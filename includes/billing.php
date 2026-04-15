<?php
/**
 * SIBIA Onboarding — Fatturazione, MemberPress, demo, upgrade/downgrade
 */

if (!defined('ABSPATH')) { exit; }

/* ============================================================
   BILLING ACTIONS — intercetta i form billing prima del render
   Usa wp_loaded (non init) per garantire che MemberPress abbia
   già registrato le sue classi (MeprSubscription, ecc.).
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
    $servizio = sanitize_text_field(wp_unslash(isset($_POST['sibia_billing_servizio']) ? $_POST['sibia_billing_servizio'] : ''));

    $page     = get_page_by_path('area-riservata');
    $baseRet  = $page ? get_permalink($page->ID) : home_url('/');
    $returnUrl = add_query_arg('section', 'fatturazione', remove_query_arg(
        array('billing_msg', 'billing_success', 'billing_cancelled', 'section'), $baseRet
    ));

    switch ($action) {
        case 'demo':
            $ok = sibia_billing_attiva_demo($email, $servizio);
            wp_redirect(add_query_arg('billing_msg', $ok ? 'demo_ok' : 'demo_err', $returnUrl));
            exit;
        case 'cancella':
            $ok = sibia_billing_cancella($email, $servizio);
            wp_redirect(add_query_arg('billing_msg', $ok ? 'cancella_ok' : 'cancella_err', $returnUrl));
            exit;
        case 'cancella_fine_periodo':
            // Cancella solo su MemberPress/Stripe (nessun rinnovo), ma il servizio resta attivo
            // fino alla scadenza naturale del periodo già pagato. Il DB non viene impostato a 'scaduto' ora:
            // l'hook mepr-transaction-expired (o mepr-subscription-cancelled con guard) lo farà a scadenza.
            $user2 = get_user_by('email', $email);
            if ($user2) {
                update_user_meta($user2->ID, 'sibia_cancella_fine_periodo_' . $servizio, 1);
                $ok = sibia_billing_cancella_mepr_only($email, $servizio);
                delete_user_meta($user2->ID, 'sibia_cancella_fine_periodo_' . $servizio);
            } else {
                $ok = false;
            }
            wp_redirect(add_query_arg('billing_msg', $ok ? 'cancella_fine_periodo_ok' : 'cancella_err', $returnUrl));
            exit;
        case 'setta_standard':
            // Disabilita la sincronizzazione articoli e reindirizza al checkout MemberPress Standard
            $checkoutUrl = sanitize_text_field(wp_unslash($_POST['sibia_billing_checkout_url'] ?? ''));
            // Leggi config attuale per passare i valori inalterati
            $sytFicCfg = sibia_get_sytfic_status($email);
            if (!empty($sytFicCfg['configured'])) {
                sibia_save_sytfic_config(
                    $email,
                    $sytFicCfg['synchroteamDomain'] ?? '',
                    '••••••••', // chiave mascherata → non aggiornata
                    $sytFicCfg['triggerJob'] ?? 'completed',
                    $sytFicCfg['productIdOre'] ?? null,
                    false // articoli disabilitati per piano Standard
                );
            }
            if (!empty($checkoutUrl)) {
                wp_redirect($checkoutUrl);
            } else {
                wp_redirect($returnUrl);
            }
            exit;
        case 'cambio_piano':
            $nuovoPiano      = sanitize_text_field($_POST['sibia_billing_nuovo_piano']      ?? '');
            $nuovoIntervallo = sanitize_text_field($_POST['sibia_billing_nuovo_intervallo'] ?? '');

            if (!in_array($nuovoPiano,      ['standard', 'professional'], true) ||
                !in_array($nuovoIntervallo, ['mensile',  'annuale'],       true)) {
                wp_redirect($returnUrl); exit;
            }

            $corrente = sibia_mepr_get_piano_corrente($user->ID, 'SynchToFic');

            // Nessuna subscription attiva → reindirizza direttamente al checkout
            if (!$corrente) {
                $piani = sibia_get_sytfic_piani_mepr();
                $pid   = $piani[$nuovoPiano][$nuovoIntervallo] ?? 0;
                wp_redirect($pid ? get_permalink($pid) : $returnUrl); exit;
            }

            // Stesso piano → nessuna azione
            if ($corrente['piano'] === $nuovoPiano && $corrente['intervallo'] === $nuovoIntervallo) {
                wp_redirect($returnUrl); exit;
            }

            // Upgrade tier (Standard → Professional): attiva funzionalità subito
            $tierUp = ($nuovoPiano === 'professional' && $corrente['piano'] === 'standard');
            if ($tierUp) {
                $sytFicCfg = sibia_get_sytfic_status($email);
                if (!empty($sytFicCfg['configured'])) {
                    sibia_save_sytfic_config(
                        $email,
                        $sytFicCfg['synchroteamDomain'] ?? '',
                        '••••••••',
                        $sytFicCfg['triggerJob'] ?? 'completed',
                        $sytFicCfg['productIdOre'] ?? null,
                        true // articoli abilitati
                    );
                }
                sibia_billing_aggiorna_membership($email, 'SynchToFic', 'attivo', $nuovoIntervallo, $nuovoPiano);
            }

            // Cancella subscription corrente (at-period-end: l'utente mantiene accesso fino a scadenza)
            update_user_meta($user->ID, 'sibia_piano_in_cambio_SynchToFic', 1);
            try {
                $meprSub = new MeprSubscription($corrente['sub_id']);
                $meprSub->cancel();
            } catch (Throwable $e) { /* continua */ }
            delete_user_meta($user->ID, 'sibia_piano_in_cambio_SynchToFic');

            // Salva piano schedulato (email inviata alla scadenza)
            sibia_set_piano_schedulato($user->ID, 'SynchToFic', $nuovoPiano, $nuovoIntervallo);

            // Email conferma all'utente
            sibia_email_conferma_cambio_piano($user, $nuovoPiano, $nuovoIntervallo, $tierUp);

            wp_redirect(add_query_arg('billing_msg', 'cambio_piano_ok', $returnUrl)); exit;
    }
});

/* ============================================================
   CHECKOUT REDIRECT — intercetta la pagina MemberPress lato PHP.
   Quando l'utente loggato apre la pagina di checkout per i prodotti
   Picam7–Pipedrive e la pagina NON usa Stripe Elements (carta embedded),
   iniettiamo un overlay spinner e auto-submittiamo il form nascosto.
   In modalità Stripe Checkout (redirect a stripe.com) questo bypassa
   la pagina intermedia di MemberPress e manda l'utente direttamente a Stripe.
   Se invece viene usato Stripe Elements, la pagina viene servita normalmente.
   ============================================================ */
add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') return;

    $uri     = $_SERVER['REQUEST_URI'];
    $matched = false;

    /* Lookup dinamico degli slug reali dagli ID prodotto MemberPress.
       692/693 = PicToPip mensile/annuale
       NOTA: SynchToFic (700/701/883/884) usa il checkout MemberPress standard
       senza auto-submit — il buffer è attivo solo per PicToPip. */
    foreach ([692, 693] as $pid) {
        $post = get_post($pid);
        if ($post && !empty($post->post_name)) {
            if (strpos($uri, '/' . $post->post_name) !== false) {
                $matched = true;
                break;
            }
        }
    }

    /* Fallback: slug hardcoded (backup nel caso get_post restituisca dati inattesi) */
    if (!$matched) {
        $slugsFallback = [
            'picam7---pipedrive-piano-mensile',
            'picam7---pipedrive-piano-annuale',
        ];
        foreach ($slugsFallback as $slug) {
            if (strpos($uri, $slug) !== false) {
                $matched = true;
                break;
            }
        }
    }

    if (!$matched) return;

    ob_start('sibia_mepr_checkout_buffer');
}, 5); /* priority 5: esegui prima di MemberPress (default 10) */

function sibia_mepr_checkout_buffer($buffer)
{
    /* ── Rilevamento Stripe Elements ──────────────────────────────────────
       Se la pagina monta un campo carta embedded (Stripe Elements / Payment
       Element), NON possiamo auto-submittare: manca il payment_method token.
       In quel caso restituiamo l'HTML intatto → l'utente vede la pagina
       MemberPress normale e inserisce la carta manualmente.
       ─────────────────────────────────────────────────────────────────── */
    $hasStripeElements = (
        stripos($buffer, 'mepr-stripe-card-element') !== false ||
        stripos($buffer, 'mepr-stripe-cvc-element')  !== false ||
        stripos($buffer, 'mepr-stripe-exp-element')  !== false ||
        stripos($buffer, 'id="card-element"')        !== false ||
        stripos($buffer, "id='card-element'")        !== false ||
        /* Stripe Payment Element o Card Element mancante del prefisso mepr */
        (stripos($buffer, 'js.stripe.com') !== false &&
         (stripos($buffer, 'card-element') !== false ||
          stripos($buffer, 'payment-element') !== false))
    );
    if ($hasStripeElements) {
        return $buffer; /* Pagina con carta embedded → mostra normale */
    }

    /* ── Trova il tag <form> di MemberPress ──────────────────────────────
       Usiamo solo il tag di apertura (più robusto di catturare l'intero
       blocco form, che poteva fallire con form annidati o strutture inattese).
       ─────────────────────────────────────────────────────────────────── */
    if (!preg_match('/<form[^>]+class=["\'][^"\']*mepr-signup-form[^"\']*["\'][^>]*>/i', $buffer, $m)) {
        /* Form MemberPress non trovato → lasciamo passare la pagina normale */
        return $buffer;
    }

    $form_tag = $m[0];

    /* Estrai l'action dal tag del form */
    $action = '';
    if (preg_match('/\baction=["\']([^"\']+)["\']/', $form_tag, $am)) {
        $action = html_entity_decode($am[1]);
    }

    /* Estrai tutti gli input hidden dalla pagina intera
       (più affidabile che cercarli solo dentro il blocco form) */
    $hidden_inputs = '';
    if (preg_match_all('/<input[^>]+type=["\']hidden["\'][^>]*(?:\/>|>)/i', $buffer, $inputs)) {
        foreach ($inputs[0] as $inp) {
            /* Includi solo input con un attributo name valorizzato */
            if (preg_match('/\bname=["\'][^"\']+["\']/', $inp)) {
                $hidden_inputs .= $inp . "\n";
            }
        }
    }

    /* Aggiungi anche il valore del bottone submit come hidden
       (MemberPress a volte lo usa per verificare il submit) */
    if (preg_match_all('/<input[^>]+type=["\']submit["\'][^>]*(?:\/>|>)/i', $buffer, $subs)) {
        foreach ($subs[0] as $sub) {
            $hidden_inputs .= preg_replace('/type=["\']submit["\']/', 'type="hidden"', $sub) . "\n";
        }
    }

    /* ── Dati utente WP per pre-compilare nome/cognome ───────────────── */
    $cu         = wp_get_current_user();
    $first_name = esc_attr($cu->first_name ?: '');
    $last_name  = esc_attr($cu->last_name  ?: '');

    /* ── Auto-detect nomi dei campi nome/cognome nel form MemberPress ── */
    $fn_name = 'user_first_name';   /* default MemberPress */
    $ln_name = 'user_last_name';
    if (preg_match('/<input[^>]+name=["\']([^"\']*(?:first[_-]?name|nome)[^"\']*)["\'][^>]*>/i', $buffer, $mfn)) {
        $fn_name = $mfn[1];
    }
    if (preg_match('/<input[^>]+name=["\']([^"\']*(?:last[_-]?name|cognome)[^"\']*)["\'][^>]*>/i', $buffer, $mln)) {
        $ln_name = $mln[1];
    }

    /* ── Auto-detect campo privacy policy ───────────────────────────── */
    $privacy_name = 'mepr_privacy_policy';   /* default MemberPress */
    if (preg_match('/<input[^>]+type=["\']checkbox["\'][^>]+name=["\']([^"\']*priv[^"\']*)["\'][^>]*>/i', $buffer, $mpv)) {
        $privacy_name = $mpv[1];
    }

    /* ── Testo label privacy policy (estratto dalla pagina MemberPress) */
    $privacy_text = 'Questo sito raccoglie nomi, email e altre informazioni. Acconsento ai termini della Privacy Policy.';
    if (preg_match('/<label[^>]*for=["\'][^"\']*priv[^"\']*["\'][^>]*>([\s\S]*?)<\/label>/i', $buffer, $mpt)) {
        $pt = trim(strip_tags($mpt[1]));
        if ($pt !== '') $privacy_text = $pt;
    }

    /* Pagina MemberPress nascosta: auto-submit immediato verso Stripe.
       Nessuna UI intermedia — l'utente ha già confermato dati nel modal del portale. */
    $overlay_html = '<style>
html,body{visibility:hidden!important;}
#sibia-redir{
    position:fixed;inset:0;z-index:2147483647;
    display:flex;align-items:center;justify-content:center;
    background:#fff;visibility:visible;
    font-family:"DM Sans",sans-serif;
}
#sibia-redir p{color:#1f5fa6;font-size:16px;font-weight:600;margin:0;}
</style>
<div id="sibia-redir"><p>Reindirizzamento al pagamento&hellip;</p></div>
<form id="sibia-co-form" method="post" action="' . esc_attr($action) . '" style="display:none">
' . $hidden_inputs . '
  <input type="hidden" id="sibia-co-h-fn"   name="' . esc_attr($fn_name)      . '" value="' . $first_name . '">
  <input type="hidden" id="sibia-co-h-ln"   name="' . esc_attr($ln_name)      . '" value="' . $last_name  . '">
  <input type="hidden" name="' . esc_attr($privacy_name) . '" value="on">
  <input type="hidden" name="mepr_terms" value="on">
</form>
<script>
(function(){
    /* Protezione anti-loop: se questa pagina è già stata inviata nella stessa sessione,
       non inviare di nuovo — mostra errore invece di creare subscription duplicate. */
    var _loopKey = \'sibia_checkout_sent\';
    var _alreadySent = false;
    try { _alreadySent = sessionStorage.getItem(_loopKey) === \'1\'; } catch(e) {}

    if (_alreadySent) {
        try { sessionStorage.removeItem(_loopKey); } catch(e) {}
        var redir = document.getElementById(\'sibia-redir\');
        if (redir) redir.innerHTML = \'<div style="text-align:center"><p style="color:#c0392b">Errore durante il reindirizzamento a Stripe.<br>Torna indietro e riprova.</p><a href="javascript:history.back()" style="color:#1f5fa6;font-size:14px;">&#8592; Torna indietro</a></div>\';
        return;
    }

    /* Se il modal ha fornito nome/cognome via sessionStorage, aggiorna gli hidden */
    try {
        var fn = sessionStorage.getItem("sibia_fn");
        var ln = sessionStorage.getItem("sibia_ln");
        sessionStorage.removeItem("sibia_fn");
        sessionStorage.removeItem("sibia_ln");
        sessionStorage.removeItem("sibia_pp");
        if (fn) { var hFn = document.getElementById("sibia-co-h-fn"); if (hFn) hFn.value = fn; }
        if (ln) { var hLn = document.getElementById("sibia-co-h-ln"); if (hLn) hLn.value = ln; }
    } catch(e) {}

    document.addEventListener("DOMContentLoaded", function(){
        var form = document.getElementById("sibia-co-form");
        if (form) {
            try { sessionStorage.setItem(_loopKey, \'1\'); } catch(e) {}
            form.submit();
        }
    });
})();
</script>';

    /* Inietta subito dopo il tag <body> — usiamo strpos + substr
       invece di preg_replace per evitare problemi con $ nei nonce
       (preg_replace interpreterebbe $1 come backreference). */
    $bodyStart = stripos($buffer, '<body');
    if ($bodyStart === false) {
        return $buffer;
    }
    $bodyTagEnd = strpos($buffer, '>', $bodyStart);
    if ($bodyTagEnd === false) {
        return $buffer;
    }
    return substr($buffer, 0, $bodyTagEnd + 1)
         . $overlay_html
         . substr($buffer, $bodyTagEnd + 1);
}

/* ========================================================================
   BILLING — Funzioni API
   ======================================================================== */

/**
 * Recupera lo stato degli abbonamenti per un cliente.
 * Restituisce un array di oggetti con: servizio, stato, dataFineDemo, ecc.
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
 * Restituisce true se riuscito, false altrimenti.
 */
function sibia_billing_attiva_demo($email, $servizio, $piano = 'standard')
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $body = array('email' => $email, 'servizio' => $servizio);
    if ($piano && $piano !== 'standard') {
        $body['piano'] = $piano;
    }

    $response = wp_remote_post($baseUrl . '/billing/demo', array(
        'timeout' => 15,
        'headers' => array(
            $header        => $secret,
            'Content-Type' => 'application/json',
        ),
        'body'    => wp_json_encode($body),
    ));

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($body['success']);
}

/**
 * Cancella tutti gli abbonamenti MemberPress attivi dell'utente per il servizio indicato.
 * Trigger: mepr-subscription-cancelled → aggiorna SIBIA DB a 'scaduto' automaticamente.
 */
function sibia_billing_cancella($email, $servizio)
{
    $user = get_user_by('email', $email);
    if (!$user) return false;

    // Raccoglie tutti gli ID prodotto validi per il servizio (Standard + Professional)
    $product_ids = array();
    if ($servizio === 'SynchToFic') {
        foreach (sibia_get_sytfic_piani_mepr() as $ids) {
            foreach ($ids as $pid) { $product_ids[] = intval($pid); }
        }
    } else {
        $config = sibia_get_mepr_config();
        $piani  = $config[$servizio] ?? null;
        if (!$piani) return false;
        $product_ids = array_map('intval', array_values($piani));
    }
    if (empty($product_ids)) return false;

    // ── STEP 1: aggiorna SIBIA DB a 'scaduto' (sempre, è l'azione esplicita dell'utente)
    sibia_billing_aggiorna_membership($email, $servizio, 'scaduto', null);

    // ── STEP 2: tenta cancellazione MemberPress + Stripe (best effort, non blocca)
    try {
        if (class_exists('MeprSubscription')) {
            $subsRaw = MeprSubscription::get_all_active_by_user_id($user->ID);
            // Deduplica per id (get_all_active_by_user_id può restituire duplicati)
            $subs = array();
            foreach ((array) $subsRaw as $s) {
                if (is_object($s) && !isset($subs[intval($s->id)])) {
                    $subs[intval($s->id)] = $s;
                }
            }
            if (empty($subs)) {
                sibia_log_errore_billing(
                    'Nessuna subscription MemberPress trovata per utente',
                    'email: ' . $email . ' | servizio: ' . $servizio,
                    null, null, null, null,
                    'user_id:' . $user->ID
                );
            } else {
                $trovata = false;
                foreach ($subs as $sub) {
                    if (!is_object($sub)) continue;
                    $subProductId = intval($sub->product_id ?? 0);
                    if (!in_array($subProductId, $product_ids)) continue;
                    $trovata = true;

                    // get_all_active_by_user_id restituisce stdClass — serve istanza reale MeprSubscription
                    $meprSub = new MeprSubscription(intval($sub->id));

                    // cancel() gestisce internamente la cancellazione su Stripe tramite gateway MemberPress
                    try {
                        $meprSub->cancel();
                    } catch (Throwable $e) {
                        sibia_log_errore_billing(
                            'Cancellazione MemberPress fallita per prodotto ' . $subProductId,
                            $e->getMessage(),
                            get_class($e),
                            null, null, null,
                            'mepr_product:' . $subProductId
                        );
                    }

                    // destroy() elimina il record così l'utente può ri-iscriversi
                    try {
                        $meprSub->destroy();
                    } catch (Throwable $e) {
                        sibia_log_errore_billing(
                            'Eliminazione subscription MemberPress fallita per prodotto ' . $subProductId,
                            $e->getMessage(),
                            get_class($e),
                            null, null, null,
                            'mepr_product:' . $subProductId
                        );
                    }
                }
                if (!$trovata) {
                    sibia_log_errore_billing(
                        'Nessuna subscription MemberPress corrisponde ai product_id del servizio',
                        'email: ' . $email . ' | servizio: ' . $servizio . ' | product_ids: ' . implode(',', $product_ids),
                        null, null, null, null,
                        'user_id:' . $user->ID
                    );
                }
            }
        }
    } catch (Throwable $e) {
        sibia_log_errore_billing(
            'Eccezione in cancellazione MemberPress',
            $e->getMessage(),
            get_class($e),
            null, null, null,
            'email:' . $email . ' | servizio:' . $servizio
        );
    }

    return true; // il DB è sempre aggiornato (STEP 1 completato)
}

/**
 * Cancella la subscription su MemberPress/Stripe senza aggiornare il DB SIBIA.
 * Usato per "disdici a fine periodo": il servizio resta attivo fino alla scadenza naturale.
 * Il meta 'sibia_cancella_fine_periodo_{servizio}' deve essere settato PRIMA della chiamata
 * per bloccare l'hook mepr-subscription-cancelled che altrimenti imposterebbe 'scaduto' subito.
 */
function sibia_billing_cancella_mepr_only($email, $servizio)
{
    $user = get_user_by('email', $email);
    if (!$user) return false;

    $product_ids = array();
    if ($servizio === 'SynchToFic') {
        foreach (sibia_get_sytfic_piani_mepr() as $ids) {
            foreach ($ids as $pid) { $product_ids[] = intval($pid); }
        }
    } else {
        $config = sibia_get_mepr_config();
        $piani  = $config[$servizio] ?? null;
        if (!$piani) return false;
        $product_ids = array_map('intval', array_values($piani));
    }
    if (empty($product_ids)) return false;

    try {
        if (class_exists('MeprSubscription')) {
            $subsRaw = MeprSubscription::get_all_active_by_user_id($user->ID);
            $subs = array();
            foreach ((array) $subsRaw as $s) {
                if (is_object($s) && !isset($subs[intval($s->id)])) {
                    $subs[intval($s->id)] = $s;
                }
            }
            foreach ($subs as $sub) {
                if (!is_object($sub)) continue;
                if (!in_array(intval($sub->product_id ?? 0), $product_ids)) continue;
                $meprSub = new MeprSubscription(intval($sub->id));
                try { $meprSub->cancel(); } catch (Throwable $e) { /* log best-effort */ }
            }
        }
    } catch (Throwable $e) { /* log best-effort */ }

    return true;
}

/**
 * Scrive un errore nella tabella Synch_to_Fic_LogErrori via ApiConnect.
 * Fire-and-forget: non blocca in caso di errore.
 */
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
    if ($httpUrl !== null)       $body['httpUrl']       = $httpUrl;
    if ($httpMetodo !== null)    $body['httpMetodo']    = $httpMetodo;
    if ($httpStatus !== null)    $body['httpStatus']    = intval($httpStatus);
    if ($riferimento !== null)   $body['riferimento']   = $riferimento;

    // Fallback: scrive sempre nel PHP error log (visibile in debug.log o error_log del server)
    error_log('[SIBIA-billing] ' . $messaggio . ($dettaglio ? ' | ' . $dettaglio : '') . ($riferimento ? ' | ref=' . $riferimento : ''));

    wp_remote_post($baseUrl . '/synch-to-fic-log-errori', array(
        'timeout' => 8,
        'headers' => array($header => $secret, 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));
}


/* ========================================================================
   MEMBERPRESS — Configurazione e integrazione con SIBIA
   ======================================================================== */

/**
 * Mappa servizio SIBIA → ID membership MemberPress (mensile e annuale).
 * Aggiornare qui quando si aggiungono nuovi servizi o si cambiano gli ID.
 */
function sibia_get_mepr_config()
{
    return array(
        'PicToPip'   => array('mensile' => 692, 'annuale' => 693),
        'SynchToFic' => array('mensile' => 700, 'annuale' => 701), // Standard plans
    );
}

/**
 * Piani MemberPress per SynchToFic divisi per piano (Standard / Professional).
 */
function sibia_get_sytfic_piani_mepr()
{
    return array(
        'standard'     => array('mensile' => 700, 'annuale' => 701),
        'professional' => array('mensile' => 883, 'annuale' => 884),
    );
}

// Intervalli di sincronizzazione per piano (minuti)
const SIBIA_INTERVALLO_STANDARD     = 60;
const SIBIA_INTERVALLO_PROFESSIONAL = 15;

/**
 * User meta: piano schedulato alla scadenza del ciclo corrente.
 */
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

/**
 * Restituisce piano e intervallo corrente da MemberPress per l'utente (solo SynchToFic).
 * Ritorna array ['piano'=>'standard'|'professional', 'intervallo'=>'mensile'|'annuale', 'sub_id'=>int]
 * oppure null se non c'è subscription attiva.
 */
function sibia_mepr_get_piano_corrente($userId, $servizio)
{
    if (!class_exists('MeprSubscription')) return null;
    $piani   = sibia_get_sytfic_piani_mepr();
    $subsRaw = MeprSubscription::get_all_active_by_user_id($userId);
    foreach ((array) $subsRaw as $s) {
        if (!is_object($s)) continue;
        $pid = intval($s->product_id ?? 0);
        foreach ($piani as $nomePiano => $ids) {
            if ($pid === intval($ids['mensile']))
                return ['piano' => $nomePiano, 'intervallo' => 'mensile', 'sub_id' => intval($s->id)];
            if ($pid === intval($ids['annuale']))
                return ['piano' => $nomePiano, 'intervallo' => 'annuale', 'sub_id' => intval($s->id)];
        }
    }
    return null;
}

/**
 * Genera il template HTML per le email SIBIA brandizzate.
 */
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

/**
 * Restituisce tutti i product_id MemberPress di tutti i servizi configurati.
 */
function sibia_get_tutti_product_ids()
{
    $ids = array();
    foreach (sibia_get_sytfic_piani_mepr() as $piani) {
        foreach ($piani as $pid) { $ids[] = intval($pid); }
    }
    $config = sibia_get_mepr_config();
    unset($config['SynchToFic']); // già incluso sopra tramite sibia_get_sytfic_piani_mepr
    foreach ($config as $piani) {
        foreach ($piani as $pid) { $ids[] = intval($pid); }
    }
    return array_values(array_unique(array_filter($ids)));
}

/**
 * Dato un product_id MemberPress, restituisce array('servizio', 'intervallo') o null.
 */
function sibia_mepr_find_servizio($product_id)
{
    // Controlla prima i piani Standard/Professional di SynchToFic
    foreach (sibia_get_sytfic_piani_mepr() as $piano => $piani) {
        if ($piani['mensile'] && intval($piani['mensile']) === intval($product_id))
            return array('servizio' => 'SynchToFic', 'intervallo' => 'mensile', 'piano' => $piano);
        if ($piani['annuale'] && intval($piani['annuale']) === intval($product_id))
            return array('servizio' => 'SynchToFic', 'intervallo' => 'annuale', 'piano' => $piano);
    }
    // Gli altri servizi (PicToPip) usano sempre piano 'standard'
    $config = sibia_get_mepr_config();
    unset($config['SynchToFic']); // già gestito sopra
    foreach ($config as $servizio => $piani) {
        if (intval($piani['mensile']) === intval($product_id))
            return array('servizio' => $servizio, 'intervallo' => 'mensile', 'piano' => 'standard');
        if (intval($piani['annuale']) === intval($product_id))
            return array('servizio' => $servizio, 'intervallo' => 'annuale', 'piano' => 'standard');
    }
    return null;
}

/**
 * Sincronizza stato MemberPress → SIBIA DB.
 * Se MemberPress ha un abbonamento attivo per SynchToFic ma il DB SIBIA non lo sa,
 * aggiorna il DB e restituisce array('piano', 'intervallo').
 * Ritorna null se nessun abbonamento MemberPress attivo trovato.
 *
 * Usare per correggere discrepanze quando il hook mepr-transaction-completed
 * non è riuscito ad aggiornare il DB (es. timeout API, Stripe webhook ritardato).
 */
function sibia_sincronizza_stato_da_mepr($user_id, $email)
{
    $piani     = sibia_get_sytfic_piani_mepr();
    $prodIdMap = array();
    foreach ($piani as $pianoKey => $ids) {
        foreach ($ids as $intervallo => $pid) {
            $prodIdMap[intval($pid)] = array('piano' => $pianoKey, 'intervallo' => $intervallo);
        }
    }

    /* Metodo 1: MeprUser::active_product_subscriptions — più affidabile con Stripe Checkout */
    try {
        if (class_exists('MeprUser') && method_exists('MeprUser', 'active_product_subscriptions')) {
            $meprUser  = new MeprUser($user_id);
            $activeIds = $meprUser->active_product_subscriptions('ids');
            if (is_array($activeIds)) {
                foreach ($activeIds as $pid) {
                    $pid = intval($pid);
                    if (!isset($prodIdMap[$pid])) continue;
                    $info = $prodIdMap[$pid];
                    sibia_billing_aggiorna_membership($email, 'SynchToFic', 'attivo', $info['intervallo'], $info['piano']);
                    return $info;
                }
            }
        }
    } catch (Throwable $e) {}

    /* Metodo 2: MeprSubscription::get_all_by_user_id — fallback */
    try {
        if (class_exists('MeprSubscription') && method_exists('MeprSubscription', 'get_all_by_user_id')) {
            $activeStr = property_exists('MeprSubscription', 'active_str') ? MeprSubscription::$active_str : 'active';
            $subs      = MeprSubscription::get_all_by_user_id($user_id);
            if (is_array($subs)) {
                foreach ($subs as $sub) {
                    if (!is_object($sub)) continue;
                    if (($sub->status ?? '') !== $activeStr) continue;
                    $pid = intval($sub->product_id ?? 0);
                    if (!isset($prodIdMap[$pid])) continue;
                    $info = $prodIdMap[$pid];
                    sibia_billing_aggiorna_membership($email, 'SynchToFic', 'attivo', $info['intervallo'], $info['piano']);
                    return $info;
                }
            }
        }
    } catch (Throwable $e) {}

    // Metodo 3 rimosso: le transazioni "complete" non indicano un abbonamento attivo —
    // una transazione pagata mesi fa (poi disdetta) re-attiverebbe sempre la subscription.
    // I Metodi 1 e 2 sono sufficienti: controllano lo stato attuale della subscription.

    return null;
}

/**
 * Restituisce l'URL della pagina account MemberPress.
 * NOTA: mepr_options è salvato come oggetto PHP (non array) — accedere con -> non [].
 * Usa MeprOptions::fetch() se disponibile, altrimenti accesso diretto all'oggetto.
 */
function sibia_get_mepr_account_url()
{
    $page_id = 0;

    // Metodo 1: classe MeprOptions (disponibile quando MemberPress è attivo)
    if (class_exists('MeprOptions')) {
        $opts = MeprOptions::fetch();
        if (!empty($opts->account_page_id)) {
            $page_id = intval($opts->account_page_id);
        }
    }

    // Metodo 2: legge direttamente l'opzione (può essere oggetto o array a seconda della versione)
    if (!$page_id) {
        $raw = get_option('mepr_options');
        if (is_object($raw) && !empty($raw->account_page_id)) {
            $page_id = intval($raw->account_page_id);
        } elseif (is_array($raw) && !empty($raw['account_page_id'])) {
            $page_id = intval($raw['account_page_id']);
        }
    }

    if ($page_id) {
        $url = get_permalink($page_id);
        if ($url) return $url;
    }

    return home_url('/account/');
}

/**
 * Chiama ApiConnect POST /billing/membership per aggiornare lo stato nel DB SIBIA.
 */
function sibia_billing_aggiorna_membership($email, $servizio, $stato, $intervallo, $piano = null)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');

    $body = array(
        'email'      => $email,
        'servizio'   => $servizio,
        'stato'      => $stato,
        'intervallo' => $intervallo,
    );
    if ($piano) {
        $body['piano'] = $piano;
    }

    $response = wp_remote_post($baseUrl . '/billing/membership', array(
        'timeout' => 15,
        'headers' => array(
            $header        => $secret,
            'Content-Type' => 'application/json',
        ),
        'body'    => wp_json_encode($body),
    ));

    if (is_wp_error($response)) {
        sibia_log_errore_billing(
            'billing_aggiorna_membership: WP_Error',
            $response->get_error_message(),
            $email
        );
        return false;
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $bodyRaw  = wp_remote_retrieve_body($response);
    $decoded  = json_decode($bodyRaw, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        sibia_log_errore_billing(
            'billing_aggiorna_membership: HTTP ' . $httpCode,
            $bodyRaw,
            $email
        );
        return false;
    }

    if (empty($decoded['success'])) {
        sibia_log_errore_billing(
            'billing_aggiorna_membership: success=false',
            $bodyRaw,
            $email
        );
        return false;
    }

    return true;
}

// Sopprime le email automatiche di MemberPress: le sostituiamo con versioni italiane HTML brandizzate SIBIA.
// La modifica è in-memory (non persiste nel DB): agisce sul singleton MeprOptions per tutta la durata della request.
add_action('init', function () {
    if (!class_exists('MeprOptions')) return;
    $opts = MeprOptions::fetch();
    foreach ([
        'MeprAdminNewSubEmail',       'MeprUserWelcomeEmail',
        'MeprAdminReceiptEmail',      'MeprUserReceiptEmail',
        'MeprAdminFailedTxnEmail',    'MeprUserFailedTxnEmail',
        'MeprAdminCancelledSubEmail', 'MeprUserCancelledSubEmail',
    ] as $c) {
        $opts->emails[$c]['enabled'] = false;
    }
}, 20);

// Hook: pagamento completato e confermato da Stripe → stato = attivo
// MemberPress con Stripe Checkout usa mepr-event-transaction-completed (non mepr-transaction-completed).
// L'evento passa un oggetto MeprEvent; la transazione si legge con $event->get_data().
add_action('mepr-event-transaction-completed', function ($event) {
    $txn = $event->get_data();
    if (!is_object($txn)) return;
    $info = sibia_mepr_find_servizio($txn->product_id ?? 0);
    if (!$info) return;
    $user = get_userdata($txn->user_id ?? 0);
    if (!$user) return;
    sibia_billing_aggiorna_membership($user->user_email, $info['servizio'], 'attivo', $info['intervallo'], $info['piano'] ?? null);
    // Se piano Standard → disabilita articoli
    if (($info['piano'] ?? '') === 'standard' && $info['servizio'] === 'SynchToFic') {
        $sytCfg = sibia_get_sytfic_status($user->user_email);
        if (!empty($sytCfg['configured']) && !empty($sytCfg['syncArticoliAbilitato'])) {
            sibia_save_sytfic_config(
                $user->user_email,
                $sytCfg['synchroteamDomain'] ?? '',
                '••••••••',
                $sytCfg['triggerJob'] ?? 'completed',
                $sytCfg['productIdOre'] ?? null,
                false
            );
        }
    }
    // Email ricevuta: solo per rinnovi (non per il primo pagamento, coperto da mepr-subscription-created)
    $subId = intval($txn->subscription_id ?? 0);
    $isNewSub = false;
    if ($subId > 0 && class_exists('MeprSubscription')) {
        try {
            $sub = new MeprSubscription($subId);
            $isNewSub = !empty($sub->created_at) && (time() - strtotime($sub->created_at)) < 300;
        } catch (Throwable $e) {}
    }
    if (!$isNewSub) {
        sibia_email_ricevuta_rinnovo($user, $info['servizio'], $info['piano'] ?? 'standard', $info['intervallo']);
    }
}, 10, 1);

// Hook: nuova subscription creata → email di attivazione abbonamento
add_action('mepr-subscription-created', function ($sub) {
    $info = sibia_mepr_find_servizio($sub->product_id ?? 0);
    if (!$info) return;
    $user = get_userdata($sub->user_id ?? 0);
    if (!$user) return;
    sibia_email_abbonamento_attivato($user, $info['servizio'], $info['piano'] ?? 'standard', $info['intervallo']);
}, 10, 1);

// Hook: transazione/abbonamento scaduto → stato = scaduto
add_action('mepr-transaction-expired', function ($txn) {
    $info = sibia_mepr_find_servizio($txn->product_id ?? 0);
    if (!$info) return;
    $user = get_userdata($txn->user_id ?? 0);
    if (!$user) return;
    // Ignora se l'utente ha ancora una subscription attiva per questo servizio
    if (sibia_mepr_utente_ha_sub_attiva($user->ID, $info['servizio'])) return;
    sibia_billing_aggiorna_membership($user->user_email, $info['servizio'], 'scaduto', null);
    // Piano schedulato → invia email con link al checkout del nuovo piano
    $pianoSch = sibia_get_piano_schedulato($user->ID, $info['servizio']);
    if ($pianoSch) {
        sibia_email_transizione_piano($user, $pianoSch['piano'], $pianoSch['intervallo'], $info['servizio']);
        sibia_clear_piano_schedulato($user->ID, $info['servizio']);
    }
}, 10, 1);

// Hook: abbonamento cancellato → stato = scaduto
add_action('mepr-subscription-cancelled', function ($sub) {
    $info = sibia_mepr_find_servizio($sub->product_id ?? 0);
    if (!$info) return;
    $user = get_userdata($sub->user_id ?? 0);
    if (!$user) return;
    // Ignora durante un cambio piano (la cancellazione è intenzionale, non un vero abbandono)
    if (get_user_meta($user->ID, 'sibia_piano_in_cambio_SynchToFic', true)) return;
    // Ignora se il cliente ha chiesto disdetta a fine periodo: il DB si aggiornerà a scadenza naturale
    if (get_user_meta($user->ID, 'sibia_cancella_fine_periodo_' . $info['servizio'], true)) return;
    // Ignora se l'utente ha ancora un'altra subscription attiva per questo servizio
    // (es. webhook Stripe in ritardo dopo una ri-iscrizione)
    if (sibia_mepr_utente_ha_sub_attiva($user->ID, $info['servizio'], intval($sub->id ?? 0))) return;
    sibia_billing_aggiorna_membership($user->user_email, $info['servizio'], 'scaduto', null);
    sibia_email_abbonamento_disdetto($user, $info['servizio']);
}, 10, 1);

add_action('mepr-transaction-failed', function ($txn) {
    $info = sibia_mepr_find_servizio($txn->product_id ?? 0);
    if (!$info) return;

    $user = get_userdata($txn->user_id ?? 0);
    if (!$user) return;

    // Solo per transazioni collegate a una subscription
    $subId = intval($txn->subscription_id ?? 0);
    if ($subId <= 0) return;

    $sub = new MeprSubscription($subId);

    if ($sub->status === 'pending') {
        // SCENARIO 1: primo pagamento mai riuscito → pulisci i record orfani
        try { (new MeprTransaction(intval($txn->id)))->destroy(); } catch (Throwable $e) {}
        try { $sub->destroy(); } catch (Throwable $e) {}

    } elseif (in_array($sub->status, ['active', 'enabled'], true)) {
        // SCENARIO 3: rinnovo fallito su abbonamento attivo → blocca sync + notifica
        sibia_billing_aggiorna_membership($user->user_email, $info['servizio'], 'scaduto', null);
        sibia_notifica_rinnovo_fallito($user, $info['servizio']);
    }
    // SCENARIO 2 (carta bloccata durante il periodo, prima del rinnovo): nessuna azione.
    // Stripe non ha ancora tentato il rinnovo, la subscription resta active, non arriva qui.
}, 10, 1);

/**
 * Invia notifica email ad admin e utente quando un rinnovo periodico fallisce.

function sibia_mepr_utente_ha_sub_attiva($userId, $servizio, $excludeSubId = 0)
{
    if (!class_exists('MeprSubscription')) return false;

    $product_ids = array();
    if ($servizio === 'SynchToFic') {
        foreach (sibia_get_sytfic_piani_mepr() as $ids) {
            foreach ($ids as $pid) { $product_ids[] = intval($pid); }
        }
    } else {
        $config = sibia_get_mepr_config();
        $piani  = $config[$servizio] ?? null;
        if (!$piani) return false;
        $product_ids = array_map('intval', array_values($piani));
    }

    $subsRaw = MeprSubscription::get_all_active_by_user_id($userId);
    foreach ((array) $subsRaw as $s) {
        if (!is_object($s)) continue;
        if ($excludeSubId > 0 && intval($s->id ?? 0) === $excludeSubId) continue;
        if (in_array(intval($s->product_id ?? 0), $product_ids)) return true;
    }
    return false;
}

/* ========================================================================
   CRON GIORNALIERO — Pulizia subscription/transaction pending orfane
   Gira ogni giorno alle 03:00 ora server.
   Elimina solo record pending più vecchi di 24 ore che non hanno mai
   completato un pagamento (subscription mai diventata active/complete).
   ======================================================================== */

add_action('wp', function () {
    if (!wp_next_scheduled('sibia_pending_cleanup_cron')) {
        $next3am = strtotime('today 03:00:00');
        if ($next3am <= time()) {
            $next3am = strtotime('tomorrow 03:00:00');
        }
        wp_schedule_event($next3am, 'daily', 'sibia_pending_cleanup_cron');
    }
});

register_deactivation_hook(SIBIA_PLUGIN_FILE, function () {
    wp_clear_scheduled_hook('sibia_pending_cleanup_cron');
});

add_action('sibia_pending_cleanup_cron', function () {
    if (!class_exists('MeprSubscription') || !class_exists('MeprTransaction')) return;

    $product_ids = sibia_get_tutti_product_ids();
    if (empty($product_ids)) return;

    global $wpdb;
    $soglia       = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

    $sub_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mepr_subscriptions
             WHERE status = 'pending'
               AND product_id IN ($placeholders)
               AND created_at <= %s",
            array_merge($product_ids, [$soglia])
        )
    );

    foreach ($sub_ids as $subId) {
        // Elimina le transaction pending collegate
        $txn_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}mepr_transactions
                 WHERE subscription_id = %d AND status = 'pending'",
                intval($subId)
            )
        );
        foreach ($txn_ids as $txnId) {
            try { (new MeprTransaction(intval($txnId)))->destroy(); } catch (Throwable $e) {}
        }

        try { (new MeprSubscription(intval($subId)))->destroy(); } catch (Throwable $e) {}
    }

    if (!empty($sub_ids)) {
        error_log('[sibia] Pulizia pending: eliminate ' . count($sub_ids) . ' subscription orfane (soglia 24h).');
    }
});
