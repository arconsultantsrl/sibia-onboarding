<?php
/**
 * SIBIA Onboarding — Fatturazione (Stripe diretto via ApiConnect)
 *
 * Riscrittura del 15 aprile 2026:
 * - Rimosso completamente MemberPress
 * - I pagamenti passano ora per Stripe diretto tramite ApiConnect
 * - Le email di pagamento sono gestite da ApiConnect (EmailService)
 * - WordPress rimane il guscio di presentazione
 */

if (!defined('ABSPATH')) { exit; }

// Intervalli di sincronizzazione per piano (minuti)
const SIBIA_INTERVALLO_STANDARD     = 60;
const SIBIA_INTERVALLO_PROFESSIONAL = 15;


/* ============================================================
   TEMPLATE HTML EMAIL SIBIA
   Usato per le email gestite lato WordPress (es. verifica email,
   benvenuto). Le email di pagamento sono gestite da ApiConnect.
   ============================================================ */

function sibia_email_html($titolo, $corpo_html, $cta_url = null, $cta_label = null)
{
    $cta_block = '';
    if ($cta_url && $cta_label) {
        $cta_block = '
        <tr><td align="center" style="padding:28px 0 8px;">
            <a href="' . esc_url($cta_url) . '" style="background:#1a1a2e;color:#ffffff;text-decoration:none;
               padding:14px 32px;border-radius:6px;font-weight:700;font-size:15px;display:inline-block;">
                ' . esc_html($cta_label) . '
            </a>
        </td></tr>';
    }
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"></head>
    <body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
      <tr><td style="background:#1a1a2e;padding:28px 40px;border-radius:8px 8px 0 0;">
        <p style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">SIBIA</p>
        <p style="margin:4px 0 0;color:#a0a0c0;font-size:13px;">' . esc_html($titolo) . '</p>
      </td></tr>
      <tr><td style="background:#ffffff;padding:36px 40px;border-radius:0 0 8px 8px;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr><td style="color:#333333;font-size:15px;line-height:1.7;">' . $corpo_html . '</td></tr>
          ' . $cta_block . '
          <tr><td style="padding-top:32px;border-top:1px solid #eeeeee;margin-top:24px;">
            <p style="color:#999999;font-size:12px;margin:16px 0 0;">
              Questa email è stata inviata automaticamente da SIBIA. Non rispondere a questo indirizzo.
            </p>
          </td></tr>
        </table>
      </td></tr>
    </table>
    </td></tr></table></body></html>';
}


/* ============================================================
   GESTIONE AZIONI BILLING
   ============================================================ */

add_action('wp_loaded', function () {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (empty($_POST['sibia_billing_action'])) return;

    $user = wp_get_current_user();
    if (!$user || !$user->exists()) return;

    if (!isset($_POST['sibia_billing_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sibia_billing_nonce'])), 'sibia_billing'))
        return;

    $email    = $user->user_email;
    $action   = sanitize_text_field(wp_unslash($_POST['sibia_billing_action']));
    $servizio = sanitize_text_field(wp_unslash($_POST['sibia_billing_servizio'] ?? ''));

    // $returnUrl definito prima di qualsiasi redirect (incluse le validazioni whitelist)
    $page      = get_page_by_path('area-riservata');
    $baseRet   = $page ? get_permalink($page->ID) : home_url('/');
    $returnUrl = add_query_arg('section', 'fatturazione', remove_query_arg(
        array('billing_msg', 'billing_success', 'billing_cancelled', 'section'), $baseRet
    ));

    // Whitelist servizi — solo valori attesi accettati
    if (!in_array($servizio, array('SynchToFic', 'PicToPip'), true)) {
        wp_redirect($returnUrl); exit;
    }

    switch ($action) {

        case 'demo':
            $ok = sibia_billing_attiva_demo($email, $servizio);
            wp_redirect(add_query_arg('billing_msg', $ok ? 'demo_ok' : 'demo_err', $returnUrl));
            exit;

        case 'checkout':
            // Apre la pagina di pagamento Stripe tramite ApiConnect
            $piano      = sanitize_text_field(wp_unslash($_POST['sibia_billing_piano']      ?? 'standard'));
            $intervallo = sanitize_text_field(wp_unslash($_POST['sibia_billing_intervallo'] ?? 'mensile'));
            // Whitelist piano e intervallo — solo combinazioni valide accettate
            if (!in_array($piano,      array('standard', 'professional'), true) ||
                !in_array($intervallo, array('mensile', 'annuale'),       true)) {
                wp_redirect($returnUrl); exit;
            }
            $url = sibia_billing_get_checkout_url($email, $servizio, $piano, $intervallo);
            if ($url) {
                wp_redirect($url);
            } else {
                wp_redirect(add_query_arg('billing_msg', 'checkout_err', $returnUrl));
            }
            exit;

        case 'cancella':
            $ok = sibia_billing_cancella($email, $servizio, false);
            wp_redirect(add_query_arg('billing_msg', $ok ? 'cancella_ok' : 'cancella_err', $returnUrl));
            exit;

        case 'cancella_fine_periodo':
            $ok = sibia_billing_cancella($email, $servizio, true);
            wp_redirect(add_query_arg('billing_msg', $ok ? 'cancella_fine_periodo_ok' : 'cancella_err', $returnUrl));
            exit;

        case 'cambio_piano':
            $nuovoPiano      = sanitize_text_field(wp_unslash($_POST['sibia_billing_nuovo_piano']      ?? ''));
            $nuovoIntervallo = sanitize_text_field(wp_unslash($_POST['sibia_billing_nuovo_intervallo'] ?? ''));

            if (!in_array($nuovoPiano,      ['standard', 'professional'], true) ||
                !in_array($nuovoIntervallo, ['mensile',  'annuale'],       true)) {
                wp_redirect($returnUrl); exit;
            }

            // Upgrade tier (Standard → Professional): attiva funzionalità subito nel backend
            $tierUp = ($nuovoPiano === 'professional');
            if ($tierUp) {
                $sytFicCfg = sibia_get_sytfic_status($email);
                if (!empty($sytFicCfg['configured'])) {
                    sibia_save_sytfic_config(
                        $email,
                        $sytFicCfg['synchroteamDomain'] ?? '',
                        '••••••••', // chiave mascherata → non aggiornata
                        $sytFicCfg['triggerJob'] ?? 'completed',
                        $sytFicCfg['productIdOre'] ?? null,
                        true // articoli abilitati per Professional
                    );
                }
                // Aggiorna stato nel database SIBIA al nuovo piano
                sibia_billing_aggiorna_membership($email, $servizio, 'attivo', $nuovoIntervallo, $nuovoPiano);
            }

            // Salva piano schedulato (per riferimento interno)
            sibia_set_piano_schedulato($user->ID, $servizio, $nuovoPiano, $nuovoIntervallo);

            // Cancella abbonamento corrente a fine periodo, poi apri checkout del nuovo piano
            sibia_billing_cancella($email, $servizio, true);
            $url = sibia_billing_get_checkout_url($email, $servizio, $nuovoPiano, $nuovoIntervallo);
            if ($url) {
                wp_redirect($url);
            } else {
                wp_redirect(add_query_arg('billing_msg', 'cambio_piano_ok', $returnUrl));
            }
            exit;
    }
});


/* ============================================================
   FUNZIONI BILLING — Chiamate ad ApiConnect
   ============================================================ */

/**
 * Recupera lo stato degli abbonamenti per un cliente.
 */
function sibia_get_billing_status($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $url      = add_query_arg(array('email' => $email), $baseUrl . '/billing/status');
    $response = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array($header => $secret),
    ));

    if (is_wp_error($response)) return array();
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['success']) || !is_array($body['data'])) return array();
    return $body['data'];
}

/**
 * Attiva la demo gratuita per il servizio indicato.
 */
function sibia_billing_attiva_demo($email, $servizio, $piano = 'standard')
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $body = array('email' => $email, 'servizio' => $servizio, 'piano' => $piano);

    $response = wp_remote_post($baseUrl . '/billing/demo', array(
        'timeout' => 15,
        'headers' => array($header => $secret, 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));

    if (is_wp_error($response)) return false;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($data['success']);
}

/**
 * Ottiene l'URL della pagina di pagamento Stripe tramite ApiConnect.
 * Ritorna l'URL (stringa) oppure null in caso di errore.
 */
function sibia_billing_get_checkout_url($email, $servizio, $piano = 'standard', $intervallo = 'mensile')
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $body = array(
        'email'      => $email,
        'servizio'   => $servizio,
        'piano'      => $piano,
        'intervallo' => $intervallo,
    );

    $response = wp_remote_post($baseUrl . '/billing/checkout-session', array(
        'timeout' => 20,
        'headers' => array($header => $secret, 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));

    if (is_wp_error($response)) return null;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['success'])) return null;
    $url = $data['data']['url'] ?? null;
    // Valida che l'URL sia HTTPS (Stripe usa sempre HTTPS per le checkout session)
    if (!is_string($url) || strpos($url, 'https://') !== 0) return null;
    return $url;
}

/**
 * Cancella l'abbonamento Stripe tramite ApiConnect.
 * $finePeriodo=true → cancella a fine periodo (nessun rimborso, servizio attivo fino a scadenza).
 * $finePeriodo=false → cancella subito.
 */
function sibia_billing_cancella($email, $servizio, $finePeriodo = false)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $body = array(
        'email'       => $email,
        'servizio'    => $servizio,
        'finePeriodo' => (bool) $finePeriodo,
    );

    $response = wp_remote_post($baseUrl . '/billing/cancella', array(
        'timeout' => 20,
        'headers' => array($header => $secret, 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));

    if (is_wp_error($response)) return false;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($data['success']);
}

/**
 * Aggiorna lo stato dell'abbonamento nel database SIBIA tramite ApiConnect.
 * Usato per allineamenti manuali e upgrade/downgrade piano.
 */
function sibia_billing_aggiorna_membership($email, $servizio, $stato, $intervallo = null, $piano = null)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $body = array('email' => $email, 'servizio' => $servizio, 'stato' => $stato);
    if ($intervallo) $body['intervallo'] = $intervallo;
    if ($piano)      $body['piano']      = $piano;

    $response = wp_remote_post($baseUrl . '/billing/membership', array(
        'timeout' => 15,
        'headers' => array($header => $secret, 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));

    if (is_wp_error($response)) return false;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($data['success']);
}

/**
 * Recupera i prezzi dei piani per il servizio indicato da ApiConnect.
 * Ritorna array indicizzato per [piano][intervallo] con 'euroMeseDisplay' e 'euroTotale'.
 * In caso di errore ritorna i valori predefiniti.
 */
function sibia_get_prezzi_piani($servizio = 'SynchToFic')
{
    static $cache = array();
    if (isset($cache[$servizio])) return $cache[$servizio];

    $default = array(
        'standard'     => array('mensile' => array('euroMeseDisplay' => 25, 'euroTotale' => 25),
                                'annuale' => array('euroMeseDisplay' => 21, 'euroTotale' => 252)),
        'professional' => array('mensile' => array('euroMeseDisplay' => 45, 'euroTotale' => 45),
                                'annuale' => array('euroMeseDisplay' => 35, 'euroTotale' => 420)),
    );

    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $url      = add_query_arg(array('servizio' => $servizio), $baseUrl . '/billing/prezzi');
    $response = wp_remote_get($url, array(
        'timeout' => 10,
        'headers' => array($header => $secret),
    ));

    if (is_wp_error($response)) {
        $cache[$servizio] = $default;
        return $default;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['success']) || empty($body['data']['prezzi'])) {
        $cache[$servizio] = $default;
        return $default;
    }

    $result = array();
    foreach ($body['data']['prezzi'] as $item) {
        $p = strtolower($item['piano']      ?? 'standard');
        $i = strtolower($item['intervallo'] ?? 'mensile');
        $result[$p][$i] = array(
            'euroMeseDisplay' => floatval($item['euroMeseDisplay'] ?? 0),
            'euroTotale'      => floatval($item['euroTotale']      ?? 0),
        );
    }

    $cache[$servizio] = !empty($result) ? $result : $default;
    return $cache[$servizio];
}


/* ============================================================
   PIANO SCHEDULATO
   Usato per il cambio piano: salva il nuovo piano richiesto
   mentre quello attuale è ancora in vigore fino a scadenza.
   ============================================================ */

function sibia_get_piano_schedulato($userId, $servizio)
{
    $val = get_user_meta($userId, 'sibia_piano_schedulato_' . $servizio, true);
    return is_array($val) ? $val : null;
}

function sibia_set_piano_schedulato($userId, $servizio, $piano, $intervallo)
{
    update_user_meta($userId, 'sibia_piano_schedulato_' . $servizio,
        ['piano' => $piano, 'intervallo' => $intervallo]);
}

function sibia_clear_piano_schedulato($userId, $servizio)
{
    delete_user_meta($userId, 'sibia_piano_schedulato_' . $servizio);
}


/* ============================================================
   LOG ERRORI BILLING
   ============================================================ */

function sibia_log_errore_billing($messaggio, $dettaglio = null, $tipoEccezione = null, $httpUrl = null, $httpMetodo = null, $httpStatus = null, $riferimento = null)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $body = array(
        'messaggio'  => $messaggio,
        'entita'     => 'Service',
        'operazione' => 'FATAL',
        'source'     => 'WordPress/sibia-onboarding',
    );
    if ($dettaglio !== null)     $body['dettaglio']     = $dettaglio;
    if ($tipoEccezione !== null) $body['tipoEccezione'] = $tipoEccezione;
    if ($httpUrl !== null)       $body['httpUrl']        = $httpUrl;
    if ($httpMetodo !== null)    $body['httpMetodo']     = $httpMetodo;
    if ($httpStatus !== null)    $body['httpStatus']     = $httpStatus;
    if ($riferimento !== null)   $body['riferimento']    = $riferimento;

    wp_remote_post($baseUrl . '/synch-to-fic-log-errori', array(
        'timeout'   => 5,
        'blocking'  => false,
        'headers'   => array($header => $secret, 'Content-Type' => 'application/json'),
        'body'      => wp_json_encode($body),
    ));
}


/* ============================================================
   PULIZIA AL DISATTIVAZIONE PLUGIN
   ============================================================ */

register_deactivation_hook(SIBIA_PLUGIN_FILE, function () {
    // Rimuove i metadati utente temporanei
    delete_metadata('user', 0, 'sibia_email_verificata', '', true);
    delete_metadata('user', 0, 'sibia_piano_schedulato_SynchToFic', '', true);
    delete_metadata('user', 0, 'sibia_piano_schedulato_PicToPip', '', true);
});
