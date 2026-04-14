<?php
/**
 * Plugin Name: SIBIA Onboarding
 * Description: Pagina di onboarding e gestione sincronizzazioni per SIBIA.
 * Version: 2.83.3
 * GitHub Plugin URI: arconsultantsrl/sibia-onboarding
 * Primary Branch: main
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// ENDPOINT /accedi/ — redirect intelligente mai cachato
//
// Problema: la pagina /account/ viene salvata in cache come HTML statico.
// Quando un utente loggato ci arriva, il PHP non gira e il redirect non scatta.
//
// Soluzione: il link "Accedi" nel menu punta a /accedi/ invece di /account/.
// L'URL /accedi/ è un puro redirect senza body → nessun sistema di cache lo salva.
// Il PHP gira SEMPRE → verifica lo stato di login ogni volta in modo affidabile.
//
// Il filtro wp_nav_menu_objects cambia automaticamente il link nel menu al momento
// del render. Dopo un rebuild della cache, il menu avrà già il link corretto.
// =============================================================================

add_action('init', function () {
    add_rewrite_rule('^accedi/?$', 'index.php?sibia_accedi=1', 'top');
    // Flush automatico al primo avvio dopo aggiornamento (una sola volta)
    if (get_option('sibia_rewrite_ver') !== '2.76.0') {
        flush_rewrite_rules(false);
        update_option('sibia_rewrite_ver', '2.76.0');
    }
}, 5);

add_filter('query_vars', function ($vars) {
    $vars[] = 'sibia_accedi';
    return $vars;
});

// Redirect intelligente basato su stato login, mai cachato
add_action('template_redirect', function () {
    if (!intval(get_query_var('sibia_accedi'))) return;
    nocache_headers();
    if (is_user_logged_in()) {
        $portal = get_page_by_path('area-riservata');
        wp_redirect($portal ? get_permalink($portal->ID) : home_url('/area-riservata/'), 302);
    } else {
        $account = get_page_by_path('account');
        wp_redirect($account ? get_permalink($account->ID) : home_url('/account/'), 302);
    }
    exit;
}, 1);

// Cambia il link "Accedi" nel menu da /account/ a /accedi/ al momento del render.
// Quando la pagina viene ri-cachata, il menu conterrà già il link corretto.
add_filter('wp_nav_menu_objects', function ($items) {
    $account = get_page_by_path('account');
    if (!$account) return $items;
    $account_url = trailingslashit(get_permalink($account->ID));
    $accedi_url  = home_url('/accedi/');
    foreach ($items as $item) {
        if (trailingslashit($item->url) === $account_url) {
            $item->url = $accedi_url;
        }
    }
    return $items;
}, 10, 1);

// Redirect utenti già loggati direttamente al plugin invece di mostrare il form login
add_action('login_init', function () {
    if (is_user_logged_in() && !isset($_GET['action'])) {
        $onboarding_page = get_page_by_path('area-riservata');
        if ($onboarding_page) {
            wp_redirect(get_permalink($onboarding_page->ID));
            exit;
        } else {
            wp_redirect(home_url('/area-riservata/'));
            exit;
        }
    }
});


// Verifica email: un solo click dal link nell'email → account Verified, nient'altro.
// Hook: 'init' priorità 5 — si attiva su QUALSIASI URL, nessun is_page() che può fallire.
// URL verifica: home_url('/?sibia_verifica=TOKEN') — indipendente dallo slug della pagina.
// Il token è monouso: dopo il primo click viene eliminato. Qualsiasi click successivo
// (incluso scanner email che pre-carica il link) vede il token assente → errore.
// L'utente è comunque Verified nel database e può fare login normalmente.
add_action('init', function () {
    if (!isset($_GET['sibia_verifica'])) return;

    $token = sanitize_text_field(wp_unslash($_GET['sibia_verifica']));
    $data  = get_option('sibia_ev_' . $token, false);

    $pageReg = get_page_by_path('registrazione');
    $errUrl  = add_query_arg('sibia_verifica_err', '1',
        $pageReg ? get_permalink($pageReg->ID) : home_url('/registrazione/'));

    if (!$data || !is_array($data) || empty($data['uid']) || empty($data['exp'])
        || $data['exp'] < time() || !get_userdata($data['uid'])) {
        wp_redirect($errUrl);
        exit;
    }

    $user_id = (int) $data['uid'];
    delete_option('sibia_ev_' . $token);

    // Verifica email: i due meta che MemberPress 1.12.x controlla (identificati con debug 06/04/2026).
    // 'user_activation_key' in wp_usermeta = hash di attivazione da eliminare.
    // 'user_activation_status' in wp_usermeta = 0 non verificato, 1 verificato.
    // NOTA: questi sono user meta, distinti dal campo wp_users.user_activation_key.
    delete_user_meta($user_id, 'user_activation_key');
    update_user_meta($user_id, 'user_activation_status', 1);
    clean_user_cache($user_id);

    $okUrl = add_query_arg('sibia_ok', '1',
        $pageReg ? get_permalink($pageReg->ID) : home_url('/registrazione/'));
    wp_redirect($okUrl);
    exit;
}, 5);


// La pagina di registrazione contiene un nonce. Se la pagina viene cachata
// (plugin cache, CDN, Varnish) il nonce stale causa "Richiesta non valida" al primo submit.
// nocache_headers() invia i header standard WordPress no-cache prima di qualsiasi output.
// /account/ deve essere no-cache sempre: cambia in base allo stato di login e abbonamento.
// Utenti già loggati su /account/ vengono rimandati al portale: MemberPress mostrerebbe
// il form di login anche a utenti autenticati senza abbonamento attivo.
add_action('template_redirect', function () {
    if (is_page('registrazione')) {
        nocache_headers();
    }
    if (is_page('account')) {
        nocache_headers(); // sempre: il contenuto dipende dallo stato di autenticazione
        if (is_user_logged_in()) {
            $portal = get_page_by_path('area-riservata');
            if ($portal) {
                wp_redirect(get_permalink($portal->ID));
                exit;
            }
        }
    }
});

// Dopo il login, reindirizza al plugin (non all'area admin)
add_filter('login_redirect', function ($redirect_to, $requested_redirect_to, $user) {
    if ($user instanceof WP_User && !user_can($user, 'manage_options')) {
        $onboarding_page = get_page_by_path('area-riservata');
        if ($onboarding_page) {
            return get_permalink($onboarding_page->ID);
        }
    }
    return $redirect_to;
}, 10, 3);

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

add_action('wp_head', function () {
    ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600&family=Raleway:wght@400;500;600&display=swap">
    <style>
:root {
    --sibia-blue: #1f5fa6;
    --sibia-blue-dark: #174a85;
    --sibia-blue-light: #e8f1fb;
    --sibia-blue-50: #f0f6ff;
    --sibia-ink: #1c2b3a;
    --sibia-muted: #61758b;
    --sibia-border: #d6e1ee;
    --sibia-card: #ffffff;
    --sibia-bg: linear-gradient(135deg, #f5f9ff 0%, #ffffff 60%, #f0f5fb 100%);
    --sibia-gray-50: #f9fafb;
    --sibia-gray-100: #f3f4f6;
    --sibia-gray-200: #e5e7eb;
    --sibia-success: #10b981;
    --sibia-success-bg: #d1fae5;
    --sibia-error: #ef4444;
    --sibia-error-bg: #fee2e2;
    --sibia-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
    --sibia-shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
    --sibia-shadow-lg: 0 12px 32px rgba(19, 43, 79, 0.10);
    --sibia-shadow-blue: 0 4px 14px rgba(31, 95, 166, 0.22);
}
body:has(.sibia-portal) #Footer,
body:has(.sibia-portal) .widgets_wrapper,
body:has(.sibia-portal) .footer-copy {
    display: none !important;
}
/* Nasconde sezioni tema/page-builder che seguono il portal
   (Divi, Elementor, Gutenberg, BeTheme/Muffin, standard HTML5) */
.et_pb_section:has(.sibia-portal) ~ .et_pb_section,
.elementor-section:has(.sibia-portal) ~ .elementor-section,
.wp-block-group:has(.sibia-portal) ~ .wp-block-group,
.mcb-section:has(.sibia-portal) ~ .mcb-section,
section:has(.sibia-portal) ~ section:not(footer) {
    display: none !important;
}
.sibia-onboarding {
    font-family: 'DM Sans', sans-serif;
    color: var(--sibia-ink);
    background: var(--sibia-bg);
    padding: 32px 24px 48px;
    border-radius: 18px;
    box-shadow: var(--sibia-shadow-lg);
}
.sibia-hero {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 18px;
    align-items: center;
    padding: 8px 0 24px;
    border-bottom: 1px solid var(--sibia-border);
}
.sibia-hero__logo img { width: 54px; height: 54px; }
.sibia-hero h1 {
    font-family: 'Raleway', sans-serif;
    font-size: 28px;
    font-weight: 600;
    margin: 0 0 6px;
}
.sibia-hero p { margin: 0; color: var(--sibia-muted); }
.sibia-steps { display: grid; gap: 22px; margin-top: 24px; }
.sibia-step {
    background: var(--sibia-card);
    border: 1px solid var(--sibia-border);
    border-radius: 14px;
    padding: 24px 22px;
}
.sibia-step__title {
    font-family: 'Raleway', sans-serif;
    font-size: 18px;
    font-weight: 600;
    color: var(--sibia-blue-dark);
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--sibia-gray-200);
}
.sibia-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, var(--sibia-blue) 0%, #2968b0 100%);
    color: #fff;
    border: none;
    text-decoration: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    width: fit-content;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: var(--sibia-shadow-blue);
}
.sibia-btn:hover {
    background: linear-gradient(135deg, var(--sibia-blue-dark) 0%, var(--sibia-blue) 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(31, 95, 166, 0.30);
}
.sibia-btn:active { transform: translateY(0); }
.sibia-btn--ghost {
    background: #ffffff;
    color: var(--sibia-blue-dark);
    border: 1.5px solid var(--sibia-border);
    box-shadow: var(--sibia-shadow-sm);
}
.sibia-btn--ghost:hover {
    background: var(--sibia-blue-50);
    border-color: var(--sibia-blue);
    box-shadow: var(--sibia-shadow-md);
}
.sibia-btn--outline {
    background: #ffffff;
    color: var(--sibia-blue);
    border: 1.5px solid var(--sibia-blue);
    box-shadow: none;
}
.sibia-btn--outline:hover {
    background: var(--sibia-blue-light);
    box-shadow: var(--sibia-shadow-sm);
}
.sibia-btn--small { padding: 8px 16px; font-size: 13px; border-radius: 8px; }
.sibia-apikey-row { display: flex; gap: 10px; align-items: center; }
.sibia-apikey-row input { flex: 1; }
.sibia-panel {
    padding: 16px 18px;
    background: var(--sibia-blue-light);
    border-radius: 12px;
    border: 1px solid var(--sibia-border);
    font-size: 14px;
    line-height: 1.5;
}
.sibia-panel input {
    width: 100%;
    margin-top: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1.5px solid var(--sibia-border);
    background: #fff;
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
}
.sibia-panel--success { background: var(--sibia-success-bg); border-color: #a7f3d0; color: #065f46; }
.sibia-panel--error { background: var(--sibia-error-bg); border-color: #fecaca; color: #991b1b; }
.sibia-panel--warning { background: #fffbeb; border-color: #fcd34d; color: #92400e; }
/* ===== CONNECTION STATUS BAR ===== */
.sibia-conn-bar { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
.sibia-conn-badge {
    flex: 1; min-width: 180px;
    display: flex; align-items: center; gap: 8px;
    padding: 11px 14px; border-radius: 10px;
    font-size: 13px; font-weight: 600; border: 1px solid;
}
.sibia-conn-badge--ok      { background: #d1fae5; border-color: #6ee7b7; color: #065f46; }
.sibia-conn-badge--err     { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
.sibia-conn-badge--pending { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
.sibia-conn-badge__dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.sibia-conn-badge--ok      .sibia-conn-badge__dot { background: #10b981; }
.sibia-conn-badge--err     .sibia-conn-badge__dot { background: #ef4444; }
.sibia-conn-badge--pending .sibia-conn-badge__dot { background: #f59e0b; }
/* ===== PENDING CLIENT CARDS ===== */
.sibia-pending-card {
    background: #fff; border: 1px solid var(--sibia-border);
    border-left: 4px solid #f59e0b; border-radius: 12px;
    padding: 18px 20px; display: grid; gap: 14px;
    box-shadow: var(--sibia-shadow-sm);
}
.sibia-pending-card__name { font-weight: 700; font-size: 15px; color: var(--sibia-ink); margin-bottom: 2px; }
.sibia-pending-card__meta { font-size: 13px; color: var(--sibia-muted); }
.sibia-pending-card__body { display: grid; gap: 10px; }
.sibia-pending-card__select label { font-size: 13px; font-weight: 600; color: var(--sibia-ink); display: block; margin-bottom: 6px; }
.sibia-pending-card__select select,
.sibia-pending-card__select input[type=number] {
    display: block; width: 100%; padding: 9px 12px; box-sizing: border-box;
    border: 1.5px solid var(--sibia-border); border-radius: 8px;
    font-size: 14px; font-family: 'DM Sans', sans-serif; background: #fff; outline: none;
}
.sibia-pending-card__select select:focus,
.sibia-pending-card__select input[type=number]:focus { border-color: var(--sibia-blue); }
.sibia-pending-card__actions { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 4px; border-top: 1px solid var(--sibia-gray-100); }
/* ===== NAV BADGE ===== */
.sibia-nav-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
    background: #ef4444; color: #fff; font-size: 11px; font-weight: 700;
    margin-left: auto; line-height: 1; flex-shrink: 0;
}
.sibia-portal {
    font-family: 'DM Sans', sans-serif;
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 28px;
    background: var(--sibia-bg);
    padding: 28px;
    border-radius: 18px;
    box-shadow: var(--sibia-shadow-lg);
    min-height: 600px;
}
.sibia-portal__nav {
    background: #ffffff;
    border: 1px solid var(--sibia-border);
    border-radius: 16px;
    padding: 20px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: var(--sibia-shadow-sm);
}
.sibia-portal__brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: 'Raleway', sans-serif;
    font-weight: 700;
    font-size: 16px;
    color: var(--sibia-blue-dark);
    padding: 4px 8px 16px;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--sibia-gray-200);
}
.sibia-portal__brand img { width: 36px; height: 36px; }
.sibia-nav-section { display: flex; flex-direction: column; gap: 4px; }
.sibia-nav-spacer { flex: 1; min-height: 20px; }
.sibia-nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    border: 1.5px solid var(--sibia-gray-200);
    background: var(--sibia-gray-50);
    text-align: left;
    padding: 12px 16px;
    border-radius: 10px;
    cursor: pointer;
    color: var(--sibia-ink);
    font-family: 'Raleway', sans-serif;
    font-size: 16px;
    font-weight: 500;
    transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
}
.sibia-nav-item:hover {
    background: var(--sibia-blue-light);
    border-color: #b8d4f0;
    color: var(--sibia-blue-dark);
    transform: translateX(2px);
}
.sibia-nav-item.is-active {
    background: linear-gradient(135deg, var(--sibia-blue) 0%, #2968b0 100%);
    border-color: transparent;
    color: #ffffff;
    font-weight: 600;
    box-shadow: var(--sibia-shadow-blue);
}
.sibia-nav-item.is-disabled,
.sibia-nav-item:disabled { opacity: 0.45; cursor: not-allowed; pointer-events: none; }
.sibia-portal__main { display: grid; gap: 18px; align-content: start; }
.sibia-section { display: none; }
.sibia-section.is-active { display: grid; gap: 20px; align-content: start; }
.sibia-hero-block {
    background: #ffffff;
    border: 1px solid var(--sibia-border);
    border-radius: 14px;
    padding: 28px 32px;
    text-align: center;
    display: grid;
    gap: 8px;
    justify-items: center;
}
.sibia-hero-block img { width: 64px; height: 64px; }
.sibia-hero-block h2 {
    font-family: 'Raleway', sans-serif;
    font-size: 26px;
    font-weight: 600;
    margin: 0;
    color: var(--sibia-blue-dark);
}
.sibia-hero-block p { margin: 0; color: var(--sibia-muted); font-size: 14px; }
.sibia-form { display: grid; gap: 20px; }
.sibia-form-section { display: grid; gap: 14px; }
.sibia-form-section__header { padding-bottom: 10px; border-bottom: 2px solid var(--sibia-gray-200); }
.sibia-form-section__header h3 {
    margin: 0;
    font-family: 'Raleway', sans-serif;
    font-size: 17px;
    font-weight: 600;
    color: var(--sibia-blue-dark);
}
.sibia-form label,
.sibia-field { display: flex; flex-direction: column; gap: 6px; font-size: 14px; font-weight: 500; color: var(--sibia-ink); }
.sibia-form label span,
.sibia-field-label {
    font-family: 'Raleway', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--sibia-muted);
    letter-spacing: 0.01em;
}
.sibia-form input[type="text"],
.sibia-form input[type="email"],
.sibia-form input[type="password"],
.sibia-form input[type="tel"],
.sibia-form select {
    width: 100%;
    padding: 13px 16px;
    border-radius: 8px;
    border: 1.5px solid var(--sibia-border);
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    color: var(--sibia-ink);
    background: #ffffff;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
    box-sizing: border-box;
}
.sibia-form input:focus,
.sibia-form select:focus { border-color: var(--sibia-blue); box-shadow: 0 0 0 3px rgba(31, 95, 166, 0.10); }
.sibia-form input[readonly] { background: var(--sibia-gray-50); color: var(--sibia-muted); cursor: default; }
.sibia-form input::placeholder { color: #b0bec5; font-weight: 400; }
.sibia-form-grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.sibia-form-row-cap { display: grid; grid-template-columns: 120px 1fr 80px; gap: 14px; }
.sibia-form-divider { height: 1px; background: var(--sibia-gray-200); margin: 4px 0; }
.sibia-checkbox { display: flex; align-items: center; gap: 10px; font-size: 14px; }
.sibia-card-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
.sibia-card {
    border: 1.5px solid var(--sibia-border);
    border-radius: 14px;
    padding: 24px 20px;
    text-align: left;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}
.sibia-card::after {
    content: '\2192';
    position: absolute;
    top: 24px;
    right: 20px;
    font-size: 18px;
    color: var(--sibia-muted);
    transition: all 0.2s ease;
}
.sibia-card:hover {
    transform: translateY(-3px);
    border-color: var(--sibia-blue);
    box-shadow: 0 12px 28px rgba(31, 95, 166, 0.16);
}
.sibia-card:hover::after { color: var(--sibia-blue); transform: translateX(4px); }
.sibia-card:active { transform: translateY(-1px); }
.sibia-card.is-coming-soon { cursor: default; opacity: 0.5; }
.sibia-card.is-coming-soon::after { display: none; }
.sibia-card.is-coming-soon:hover { transform: none; border-color: var(--sibia-border); box-shadow: none; }
.sibia-card__title { display: block; font-weight: 700; font-size: 16px; margin-bottom: 8px; color: var(--sibia-ink); padding-right: 24px; }
.sibia-card__meta { display: block; color: var(--sibia-muted); font-size: 13px; line-height: 1.5; }
.sibia-card__soon { display: inline-block; margin-top: 10px; font-size: 11px; font-weight: 600; color: var(--sibia-muted); background: var(--sibia-gray-100); border-radius: 20px; padding: 2px 10px; letter-spacing: 0.04em; text-transform: uppercase; }
/* Pagine contratto */
.sibia-contratto-wrap { max-width: 820px; margin: 40px auto; padding: 0 24px 60px; font-family: 'DM Sans', sans-serif; }
.sibia-contratto-wrap h4 { font-family: 'Raleway', sans-serif; font-size: 15px; font-weight: 600; color: var(--sibia-blue-dark); margin: 22px 0 6px; }
.sibia-contratto-wrap p { margin: 0 0 10px; font-size: 14px; line-height: 1.7; color: var(--sibia-ink); }
.sibia-contratto-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
.sibia-contratto-table td { padding: 10px 8px; border-bottom: 1px solid var(--sibia-border); font-size: 14px; vertical-align: top; }
.sibia-contratto-table td:first-child { font-weight: 600; width: 260px; color: var(--sibia-ink); }
.sibia-contratto-table a { color: var(--sibia-blue); word-break: break-all; }
.sibia-contratto-warn { padding: 16px 18px; background: #fffbeb; border: 1.5px solid #f59e0b; border-radius: 10px; font-size: 13px; line-height: 1.7; margin-top: 24px; color: #78350f; }
.sibia-contratto-gdpr { padding: 16px 18px; background: var(--sibia-blue-50); border: 1.5px solid #b3d7ff; border-radius: 10px; font-size: 13px; line-height: 1.7; margin-top: 14px; color: var(--sibia-ink); }
.sibia-contratto-footer { text-align: center; margin-top: 32px; padding-top: 20px; border-top: 1px solid var(--sibia-border); font-size: 12px; color: var(--sibia-muted); }
.sibia-solution-list { display: grid; gap: 18px; }
.sibia-solution-detail { display: none; gap: 18px; }
.sibia-solution-detail[style*="display: none"] { display: none !important; }
.sibia-solution-detail:not([style*="display: none"]) { display: grid; }
.sibia-back-btn { justify-self: start; }
.sibia-view { display: none; }
.sibia-view.is-active { display: block; }
.sibia-status { display: grid; gap: 12px; align-items: center; }
.sibia-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.sibia-badge--test    { background: #FFF3CD; color: #856404; border: 1px solid #FFE69C; }
.sibia-badge--success { background: var(--sibia-success-bg); color: #065f46; }
.sibia-badge--warning { background: #fef3c7; color: #92400e; }
.sibia-badge--error   { background: var(--sibia-error-bg); color: #991b1b; }
.sibia-badge--neutral { background: var(--sibia-gray-100); color: var(--sibia-muted); }
.sibia-badge--promo   { background: #dbeafe; color: #1e40af; margin-left: 6px; font-size: 11px; text-transform: none; padding: 2px 8px; }
/* ===== BILLING ===== */
.sibia-btn--primary { background: var(--sibia-blue); color: #fff; border: none; }
.sibia-btn--primary:hover { background: var(--sibia-blue-dark); transform: translateY(-1px); box-shadow: var(--sibia-shadow-blue); }
.sibia-billing-cards { display: flex; flex-direction: column; gap: 20px; max-width: 700px; }
.sibia-billing-card { background: #fff; border: 1px solid var(--sibia-border); border-radius: 12px; overflow: hidden; box-shadow: var(--sibia-shadow-sm); }
.sibia-billing-card--coming-soon { opacity: 0.55; }
.sibia-billing-card__header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--sibia-border); background: var(--sibia-gray-50); }
.sibia-billing-card__title { font-size: 15px; font-weight: 600; color: var(--sibia-ink); }
.sibia-billing-card__body { padding: 14px 20px 10px; }
.sibia-billing-card__body p { margin: 0 0 10px; color: var(--sibia-muted); font-size: 14px; }
.sibia-billing-card__actions { padding: 0 20px 18px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.sibia-billing-card__actions form { display: inline; margin: 0; }
.sibia-billing-info { background: var(--sibia-blue-50); border-radius: 8px; padding: 10px 14px; margin-top: 6px; margin-bottom: 4px; }
.sibia-billing-info__row { display: flex; gap: 8px; font-size: 13px; margin-bottom: 4px; }
.sibia-billing-info__row:last-child { margin-bottom: 0; }
.sibia-billing-info__label { color: var(--sibia-muted); min-width: 130px; font-weight: 500; }
.sibia-sync-status { background: #F8F9FA; border: 1px solid #E9ECEF; border-radius: 12px; padding: 20px 24px; }
.sibia-sync-status__row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; }
.sibia-sync-status__row + .sibia-sync-status__row { border-top: 1px solid #E9ECEF; }
.sibia-sync-status__label { font-size: 14px; color: #6C757D; }
.sibia-sync-status__value { font-size: 14px; font-weight: 600; color: #212529; }
.the_content_wrapper .auth-layout {
    background: transparent;
    padding: 60px 20px;
    display: flex;
    justify-content: center;
    align-items: flex-start;
}
.the_content_wrapper .auth-layout .auth-box {
    max-width: 440px;
    width: 100%;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 8px 40px rgba(0, 7, 45, 0.10);
    padding: 48px 40px;
}
.the_content_wrapper .auth-layout + .auth-layout { display: none; }
.the_content_wrapper > .mp_wrapper.mp_login_form { display: none; }
.the_content_wrapper .auth-layout .mepr_price { display: none; }
.the_content_wrapper .auth-layout .mp-form-row { margin-bottom: 16px; text-align: left; }
.the_content_wrapper .auth-layout .mp-form-label label {
    display: block;
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: var(--sibia-muted);
    margin-bottom: 6px;
}
.the_content_wrapper .auth-layout input[type="text"],
.the_content_wrapper .auth-layout input[type="email"],
.the_content_wrapper .auth-layout input[type="password"] {
    width: 100%;
    padding: 12px 0;
    font-size: 15px;
    font-family: 'DM Sans', sans-serif;
    border: none;
    border-bottom: 2px solid var(--sibia-border);
    border-radius: 0;
    background: transparent;
    color: var(--sibia-ink);
    transition: border-color 0.2s ease;
    box-sizing: border-box;
}
.the_content_wrapper .auth-layout input[type="text"]:focus,
.the_content_wrapper .auth-layout input[type="email"]:focus,
.the_content_wrapper .auth-layout input[type="password"]:focus { outline: none; border-bottom-color: var(--sibia-blue); }
.the_content_wrapper .auth-layout input[type="submit"],
.the_content_wrapper .auth-layout .mepr-submit {
    width: 100%;
    padding: 14px 24px;
    font-size: 16px;
    font-weight: 700;
    font-family: 'DM Sans', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: var(--sibia-blue);
    color: #ffffff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: background 0.2s ease;
    margin-top: 12px;
}
.the_content_wrapper .auth-layout input[type="submit"]:hover,
.the_content_wrapper .auth-layout .mepr-submit:hover { background: var(--sibia-blue-dark); }
.the_content_wrapper .auth-layout .mepr-login-actions { margin-top: 20px; text-align: center; }
.the_content_wrapper .auth-layout .mepr-login-actions a { color: var(--sibia-blue); font-size: 14px; font-family: 'DM Sans', sans-serif; text-decoration: none; }
.the_content_wrapper .auth-layout .mepr-login-actions a:hover { color: var(--sibia-blue-dark); text-decoration: underline; }
.the_content_wrapper .auth-layout label[for="rememberme"],
.the_content_wrapper .auth-layout .mepr-checkbox-field {
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: var(--sibia-muted);
    display: flex;
    align-items: flex-start;
    gap: 8px;
    text-align: left;
}
.the_content_wrapper .auth-layout .cc-error { font-size: 12px; color: var(--sibia-error); margin-top: 4px; }
.the_content_wrapper .auth-layout .mepr-form-has-errors { font-size: 14px; color: var(--sibia-error); text-align: center; margin-top: 12px; }
.the_content_wrapper .auth-layout div.mp-hide-pw { position: relative; }
.the_content_wrapper .auth-layout button.mp-hide-pw { position: absolute; right: 4px; top: 50%; transform: translateY(-50%); background: transparent; border: none; cursor: pointer; color: var(--sibia-muted); padding: 4px; }
.the_content_wrapper .auth-layout .mp-spacer,
.the_content_wrapper .auth-layout .mepr_spacer { height: 8px; }
.the_content_wrapper .auth-layout .mepr-loading-gif { margin-top: 8px; }
.the_content_wrapper .mepr_notice {
    font-family: 'DM Sans', sans-serif;
    font-size: 18px;
    font-weight: 600;
    padding: 20px 28px;
    border-radius: 12px;
    text-align: center;
    line-height: 1.5;
}
/* === GUIDA INSTALLAZIONE === */
body:has(.sibia-guide) #Footer,
body:has(.sibia-guide) .widgets_wrapper,
body:has(.sibia-guide) .footer-copy { display: none !important; }
.sibia-guide {
    font-family: 'DM Sans', sans-serif;
    color: var(--sibia-ink);
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 24px 60px;
}
.sibia-guide__header {
    text-align: center;
    margin-bottom: 48px;
}
.sibia-guide__header img {
    width: 72px;
    height: 72px;
    margin-bottom: 20px;
}
.sibia-guide__header h1 {
    font-family: 'Raleway', sans-serif;
    font-size: 32px;
    font-weight: 600;
    color: var(--sibia-blue-dark);
    margin: 0 0 12px;
}
.sibia-guide__header p {
    font-size: 16px;
    color: var(--sibia-muted);
    margin: 0;
}
.sibia-guide__step {
    background: #ffffff;
    border: 1px solid var(--sibia-border);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 28px;
    box-shadow: var(--sibia-shadow-lg);
}
.sibia-guide__step-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, var(--sibia-blue) 0%, #2968b0 100%);
    color: #fff;
    border-radius: 50%;
    font-weight: 700;
    font-size: 20px;
    margin-bottom: 16px;
}
.sibia-guide__step h2 {
    font-family: 'Raleway', sans-serif;
    font-size: 22px;
    font-weight: 600;
    color: var(--sibia-blue-dark);
    margin: 0 0 16px;
}
.sibia-guide__step p {
    font-size: 15px;
    color: var(--sibia-ink);
    margin: 0 0 12px;
    line-height: 1.7;
}
.sibia-guide__step ol {
    padding-left: 24px;
    margin: 12px 0;
}
.sibia-guide__step ol li {
    margin-bottom: 10px;
    padding-left: 6px;
    line-height: 1.6;
}
.sibia-guide__step ol li strong {
    color: var(--sibia-blue-dark);
}
.sibia-guide__warning {
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 12px;
    padding: 20px 24px;
    margin: 20px 0;
}
.sibia-guide__warning strong {
    display: block;
    color: #92400e;
    font-size: 15px;
    margin-bottom: 8px;
}
.sibia-guide__warning p {
    color: #78350f;
    font-size: 14px;
    margin: 0;
    line-height: 1.6;
}
.sibia-guide__info {
    background: var(--sibia-blue-light);
    border: 1px solid var(--sibia-border);
    border-radius: 12px;
    padding: 20px 24px;
    margin: 20px 0;
}
.sibia-guide__info strong {
    display: block;
    color: var(--sibia-blue-dark);
    font-size: 15px;
    margin-bottom: 8px;
}
.sibia-guide__info p {
    color: var(--sibia-ink);
    font-size: 14px;
    margin: 0;
    line-height: 1.6;
}
.sibia-guide__placeholder {
    background: linear-gradient(135deg, #f0f5fb 0%, #e8f1fb 100%);
    border: 2px dashed var(--sibia-border);
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    margin: 20px 0;
}
.sibia-guide__ph-icon {
    font-size: 48px;
    margin-bottom: 12px;
}
.sibia-guide__ph-title {
    font-family: 'Raleway', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--sibia-blue-dark);
    margin-bottom: 6px;
}
.sibia-guide__ph-desc {
    font-size: 13px;
    color: var(--sibia-muted);
    line-height: 1.4;
}
.sibia-guide__menu {
    display: grid;
    gap: 12px;
    margin: 20px 0;
}
.sibia-guide__menu-item {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    background: var(--sibia-gray-50);
    border: 1.5px solid var(--sibia-gray-200);
    border-radius: 12px;
    padding: 18px 20px;
    transition: border-color 0.15s ease;
}
.sibia-guide__menu-item:hover {
    border-color: var(--sibia-blue);
}
.sibia-guide__menu-icon {
    font-size: 24px;
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--sibia-blue-light);
    border-radius: 12px;
}
.sibia-guide__menu-item strong {
    display: block;
    font-family: 'Raleway', sans-serif;
    font-size: 16px;
    color: var(--sibia-blue-dark);
    margin-bottom: 4px;
}
.sibia-guide__menu-item p {
    font-size: 14px;
    color: var(--sibia-muted);
    margin: 0;
    line-height: 1.5;
}
.sibia-guide__footer {
    text-align: center;
    margin-top: 48px;
    padding-top: 32px;
    border-top: 1px solid var(--sibia-border);
}
.sibia-guide__footer p {
    color: var(--sibia-muted);
    font-size: 14px;
    margin: 0 0 8px;
}
.sibia-guide__footer a {
    color: var(--sibia-blue);
    text-decoration: none;
}
.sibia-guide__footer a:hover {
    text-decoration: underline;
}
@media (max-width: 900px) {
    .sibia-portal { grid-template-columns: 1fr; }
    .sibia-portal__nav { flex-direction: row; flex-wrap: wrap; padding: 14px; }
    .sibia-portal__brand { width: 100%; margin-bottom: 4px; padding-bottom: 12px; }
    .sibia-nav-section { flex-direction: row; flex-wrap: wrap; gap: 6px; }
    .sibia-nav-spacer { display: none; }
    .sibia-nav-item { width: auto; padding: 9px 14px; font-size: 13px; }
    .sibia-guide { padding: 24px 16px 40px; }
    .sibia-guide__header h1 { font-size: 26px; }
    .sibia-guide__step { padding: 24px 20px; }
    .sibia-guide__step h2 { font-size: 19px; }
}
@media (max-width: 640px) {
    .sibia-onboarding { padding: 24px 16px 32px; }
    .sibia-hero { grid-template-columns: 1fr; text-align: left; }
    .sibia-portal { padding: 16px; gap: 16px; }
    .sibia-form-grid-2col { grid-template-columns: 1fr; }
    .sibia-form-row-cap { grid-template-columns: 100px 1fr; }
    .sibia-card-grid { grid-template-columns: 1fr; }
    .the_content_wrapper .auth-layout { padding: 40px 12px; }
    .the_content_wrapper .auth-layout .auth-box { padding: 32px 24px; }
    .sibia-hero-block { padding: 20px 16px; }
}
/* ===== MODAL ABBONAMENTO ===== */
.sibia-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.48);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.sibia-modal-overlay.is-open { display: flex; }
.sibia-modal {
    background: #fff;
    border-radius: 20px;
    padding: 40px 32px 32px;
    max-width: 460px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(19,43,79,0.22);
    position: relative;
    animation: sibia-modal-in 0.2s cubic-bezier(0.4,0,0.2,1);
}
@keyframes sibia-modal-in {
    from { opacity: 0; transform: translateY(12px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.sibia-modal__close {
    position: absolute;
    top: 14px;
    right: 14px;
    background: var(--sibia-gray-100);
    border: none;
    border-radius: 50%;
    width: 34px;
    height: 34px;
    font-size: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--sibia-muted);
    transition: background 0.15s;
    line-height: 1;
}
.sibia-modal__close:hover { background: var(--sibia-gray-200); color: var(--sibia-ink); }
.sibia-modal__service { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--sibia-muted); margin-bottom: 6px; }
.sibia-modal__title { font-family: 'Raleway', sans-serif; font-size: 22px; font-weight: 700; color: var(--sibia-blue-dark); margin: 0 0 4px; }
.sibia-modal__piano { font-size: 14px; color: var(--sibia-muted); margin-bottom: 18px; }
.sibia-modal__price { font-size: 48px; font-weight: 800; color: var(--sibia-blue); line-height: 1; }
.sibia-modal__period { font-size: 14px; color: var(--sibia-muted); margin-bottom: 20px; }
.sibia-modal__desc { font-size: 14px; color: var(--sibia-ink); line-height: 1.6; margin-bottom: 24px; padding: 14px 16px; background: var(--sibia-blue-50); border-radius: 10px; border: 1px solid var(--sibia-border); }
.sibia-modal__note { font-size: 12px; color: var(--sibia-muted); margin-top: 12px; text-align: center; }
.sibia-modal__actions { display: flex; gap: 12px; }
.sibia-modal__actions .sibia-btn { flex: 1; justify-content: center; }
.sibia-modal__fields { display: flex; flex-direction: column; gap: 12px; margin: 4px 0 8px; }
.sibia-modal__field-row { display: flex; gap: 10px; }
.sibia-modal__field { display: flex; flex-direction: column; gap: 5px; flex: 1; }
.sibia-modal__field label { font-size: 13px; font-weight: 600; color: var(--sibia-ink); }
.sibia-modal__field input[type=text] { border: 1px solid var(--sibia-border); border-radius: 7px; padding: 8px 11px; font-size: 14px; font-family: inherit; width: 100%; box-sizing: border-box; outline: none; }
.sibia-modal__field input[type=text]:focus { border-color: var(--sibia-blue); }
.sibia-modal__privacy { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: var(--sibia-ink); line-height: 1.5; background: var(--sibia-blue-50); border-radius: 8px; padding: 10px 12px; cursor: pointer; }
.sibia-modal__privacy input[type=checkbox] { margin-top: 2px; flex-shrink: 0; accent-color: var(--sibia-blue); width: 16px; height: 16px; cursor: pointer; }
/* ===== PAGINA PREZZI [sibia_prezzi] ===== */
.sibia-prezzi-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 880px;
    margin: 0 auto;
    padding: 48px 24px 72px;
}
.sibia-prezzi-header {
    text-align: center;
    margin-bottom: 52px;
}
.sibia-prezzi-header h2 {
    font-family: 'Raleway', sans-serif;
    font-size: 36px;
    font-weight: 700;
    color: var(--sibia-blue-dark);
    margin: 0 0 14px;
}
.sibia-prezzi-header p { font-size: 17px; color: var(--sibia-muted); margin: 0; }
.sibia-prezzi-service {
    background: #fff;
    border: 1px solid var(--sibia-border);
    border-radius: 20px;
    padding: 36px;
    margin-bottom: 36px;
    box-shadow: var(--sibia-shadow-md);
}
.sibia-prezzi-service__header {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-bottom: 28px;
    padding-bottom: 22px;
    border-bottom: 1px solid var(--sibia-border);
}
.sibia-prezzi-service__header img { width: 52px; height: 52px; border-radius: 12px; }
.sibia-prezzi-service__header h3 { font-family: 'Raleway', sans-serif; font-size: 22px; font-weight: 700; color: var(--sibia-blue-dark); margin: 0 0 6px; }
.sibia-prezzi-service__header p { font-size: 14px; color: var(--sibia-muted); margin: 0; }
.sibia-prezzi-plans { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 24px; }
.sibia-prezzi-plan {
    border: 1.5px solid var(--sibia-border);
    border-radius: 16px;
    padding: 28px 24px;
    position: relative;
}
.sibia-prezzi-plan--featured {
    border-color: var(--sibia-blue);
    background: var(--sibia-blue-50);
}
.sibia-prezzi-plan__badge {
    position: absolute;
    top: -13px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, var(--sibia-blue) 0%, #2968b0 100%);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 16px;
    border-radius: 20px;
    white-space: nowrap;
}
.sibia-prezzi-plan__name { font-family: 'Raleway', sans-serif; font-size: 18px; font-weight: 700; color: var(--sibia-ink); margin-bottom: 10px; }
.sibia-prezzi-plan__price { font-size: 52px; font-weight: 800; color: var(--sibia-blue); line-height: 1; margin-bottom: 4px; }
.sibia-prezzi-plan__period { font-size: 14px; color: var(--sibia-muted); margin-bottom: 6px; }
.sibia-prezzi-plan__equi { font-size: 13px; color: var(--sibia-blue-dark); font-weight: 700; margin-bottom: 20px; }
.sibia-prezzi-plan__features { list-style: none; padding: 0; margin: 0 0 26px; }
.sibia-prezzi-plan__features li {
    font-size: 14px;
    color: var(--sibia-ink);
    padding: 7px 0;
    border-bottom: 1px solid var(--sibia-gray-100);
    display: flex;
    align-items: center;
    gap: 10px;
}
.sibia-prezzi-plan__features li::before { content: '\2713'; color: var(--sibia-success); font-weight: 800; flex-shrink: 0; }
.sibia-prezzi-note {
    background: var(--sibia-blue-50);
    border: 1px solid var(--sibia-border);
    border-radius: 10px;
    padding: 14px 20px;
    font-size: 14px;
    color: var(--sibia-ink);
}
@media (max-width: 600px) {
    .sibia-prezzi-plans { grid-template-columns: 1fr; }
    .sibia-prezzi-service { padding: 24px 20px; }
    .sibia-prezzi-plan__price { font-size: 42px; }
}
/* ─── Collapsible step ───────────────────────────────────────────────────── */
.sibia-step--collapsible .sibia-step__title {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    user-select: none;
}
.sibia-step--collapsible .sibia-step__title::after {
    content: '';
    display: inline-block;
    width: 9px;
    height: 9px;
    border-right: 2px solid var(--sibia-blue);
    border-bottom: 2px solid var(--sibia-blue);
    transform: rotate(45deg);
    transition: transform 0.25s ease;
    flex-shrink: 0;
    margin-left: 12px;
    margin-bottom: 2px;
}
.sibia-step--collapsible.is-collapsed .sibia-step__title {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom-color: transparent;
}
.sibia-step--collapsible.is-collapsed .sibia-step__title::after {
    transform: rotate(-45deg);
    margin-bottom: 0;
}
.sibia-step--collapsible .sibia-step__body {
    overflow: hidden;
    transition: max-height 0.3s ease, opacity 0.25s ease, margin-top 0.25s ease;
    max-height: 2000px;
    opacity: 1;
    margin-top: 16px;
}
.sibia-step--collapsible.is-collapsed .sibia-step__body {
    max-height: 0;
    opacity: 0;
    pointer-events: none;
    margin-top: 0;
}
/* ─── Toggle switch ─────────────────────────────────────────────────────── */
.sibia-toggle {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    margin-top: 16px;
}
.sibia-toggle__input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.sibia-toggle__track {
    position: relative;
    width: 44px;
    height: 24px;
    border-radius: 12px;
    background: #d1d5db;
    flex-shrink: 0;
    transition: background 0.2s;
}
.sibia-toggle__track::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.25);
    transition: transform 0.2s;
}
.sibia-toggle__input:checked + .sibia-toggle__track { background: var(--sibia-blue); }
.sibia-toggle__input:checked + .sibia-toggle__track::after { transform: translateX(20px); }
.sibia-toggle__label {
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    font-weight: 500;
    color: var(--sibia-text);
    line-height: 1.4;
}
/* ── Pagina abbonamento ── */
.sibia-abbonamento { padding: 4px 0; max-width: 740px; }
.sibia-abbonamento__back { display:inline-block; font-size:13px; color:var(--sibia-primary); text-decoration:none; margin-bottom:20px; }
.sibia-abbonamento__back:hover { text-decoration:underline; }
.sibia-abbonamento__header { margin-bottom:20px; }
.sibia-abbonamento__header h2 { font-size:20px; font-weight:700; color:var(--sibia-dark); margin:0 0 10px; }
.sibia-abbonamento__status { display:inline-block; padding:5px 14px; border-radius:20px; font-size:13px; }
.sibia-abbonamento__status--demo    { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; }
.sibia-abbonamento__status--scaduto { background:#fff3e0; color:#e65100; border:1px solid #ffb74d; }
.sibia-abbonamento__status--attivo  { background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; }
.sibia-abbonamento__choose { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:10px; }
.sibia-abbonamento__choose h3 { font-size:15px; font-weight:600; color:var(--sibia-dark); margin:0; }
.sibia-abb-toggle { display:flex; gap:3px; background:#f1f5f9; border-radius:8px; padding:3px; }
.sibia-abb-toggle__btn { border:none; background:transparent; cursor:pointer; padding:5px 14px; border-radius:6px; font-size:13px; font-weight:500; color:var(--sibia-muted); transition:background .15s,color .15s; }
.sibia-abb-toggle__btn.is-active { background:white; color:var(--sibia-dark); box-shadow:0 1px 3px rgba(0,0,0,.1); }
.sibia-abb-toggle__save { font-size:11px; color:#16a34a; font-weight:600; }
.sibia-abb-cards { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:22px; }
@media(max-width:560px){ .sibia-abb-cards { grid-template-columns:1fr; } }
.sibia-abb-card { background:white; border:1.5px solid #e2e8f0; border-radius:12px; padding:24px 22px; display:flex; flex-direction:column; gap:10px; position:relative; }
.sibia-abb-card--featured { border-color:var(--sibia-primary); background:#f0f7ff; }
.sibia-abb-card__badge { position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:var(--sibia-primary); color:white; font-size:11px; font-weight:700; padding:2px 12px; border-radius:20px; white-space:nowrap; }
.sibia-abb-card__name { font-size:16px; font-weight:700; color:var(--sibia-dark); }
.sibia-abb-card--featured .sibia-abb-card__name { color:var(--sibia-primary); }
.sibia-abb-card__price { display:flex; align-items:baseline; gap:2px; }
.sibia-abb-card__amount { font-size:34px; font-weight:800; color:var(--sibia-dark); line-height:1; }
.sibia-abb-card__per { font-size:13px; color:var(--sibia-muted); }
.sibia-abb-card__note { font-size:12px; color:var(--sibia-muted); min-height:16px; }
.sibia-abb-card__features { list-style:none; padding:0; margin:4px 0 0; display:flex; flex-direction:column; gap:5px; flex:1; }
.sibia-abb-card__features li { font-size:13px; color:var(--sibia-text); }
.sibia-abb-card__cta { margin-top:6px; text-align:center; text-decoration:none; display:block; }
.sibia-abbonamento__support { font-size:13px; color:var(--sibia-muted); margin-top:4px; }
.sibia-abbonamento__support a { color:var(--sibia-primary); }
    </style>
    <?php
});

add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    ?>
    <script>
    (function () {
        var translations = {
            'First Name Required': 'Nome obbligatorio',
            'Last Name Required': 'Cognome obbligatorio',
            'Invalid Email': 'Email non valida',
            'Invalid Password': 'Password non valida',
            "Password Confirmation Doesn't Match": 'Le password non coincidono',
            'Please fix the errors above': 'Correggi gli errori indicati sopra',
            'Your subscription has been set up successfully.': 'Registrazione completata con successo. A breve vi arriverà una mail di verifica.',
            'Your email has been verified successfully.': 'Il tuo indirizzo email è stato verificato con successo.',
            'Sorry, activation key is not valid.': 'Il link di verifica non è valido.',
            'Verify your email first!': 'Verifica il tuo indirizzo email prima di accedere.',
            'Verification mail has been sent.': 'Email di verifica inviata.',
            'Checking Verification': 'Verifica in corso',
            'Email Verification': 'Verifica Email',
            "You don't have access to purchase this item.": 'Non hai accesso a questo elemento.',
            'You are already subscribed to this item.': 'Sei già registrato.'
        };

        var navMap = {
            'Home': 'Home',
            'Subscriptions': 'Abbonamenti',
            'Payments': 'Pagamenti',
            'Logout': 'Esci'
        };

        var labelMap = {
            'First Name': 'Nome',
            'Last Name': 'Cognome',
            'Email': 'Email',
            'Username': 'Nome utente',
            'Bio': 'Biografia',
            'Current Password': 'Password attuale',
            'New Password': 'Nuova password',
            'Confirm New Password': 'Conferma nuova password'
        };

        function translateAll(root) {
            if (!root) root = document;

            /* Input values */
            root.querySelectorAll('input[value="Log In"]').forEach(function (el) { el.value = 'Accedi'; });
            root.querySelectorAll('input[value="Sign Up"]').forEach(function (el) { el.value = 'Registrati'; });
            root.querySelectorAll('input[value="Save Profile"]').forEach(function (el) { el.value = 'Salva Profilo'; });
            root.querySelectorAll('input[value="Save"]').forEach(function (el) { el.value = 'Salva'; });

            /* Text blocks */
            root.querySelectorAll('.the_content_wrapper, .mepr_notice, .mp_wrapper, .user-verification-notice, .entry-content, .the_content_wrapper p, .cc-error, .mepr-form-has-errors, h2, h3, h4, p, span, div').forEach(function (el) {
                if (el.children.length === 0 || el.classList.contains('mepr_notice') || el.classList.contains('user-verification-notice')) {
                    var text = el.textContent.trim();
                    if (translations[text]) {
                        el.textContent = translations[text];
                    }
                }
            });

            /* Nav tabs */
            root.querySelectorAll('.mepr-nav-item a, .mepr_tab a').forEach(function (el) {
                var t = el.textContent.trim();
                if (navMap[t]) el.textContent = navMap[t];
            });

            /* Links */
            root.querySelectorAll('a').forEach(function (el) {
                var t = el.textContent.trim();
                if (t === 'Change Password') el.textContent = 'Cambia Password';
                if (t === 'Forgot Password') el.textContent = 'Password dimenticata';
            });

            root.querySelectorAll('a[title="Click here to reset your password"]').forEach(function (el) {
                el.title = 'Clicca qui per reimpostare la password';
            });

            root.querySelectorAll('button[aria-label="Show password"]').forEach(function (el) {
                el.setAttribute('aria-label', 'Mostra password');
            });

            /* Privacy checkbox */
            root.querySelectorAll('.mepr-checkbox-field').forEach(function (el) {
                if (el.innerHTML.indexOf('This site collects') === -1) return;
                var cb = el.querySelector('input[type="checkbox"]');
                var link = el.querySelector('a');
                if (!cb) return;
                var href = link ? link.getAttribute('href') : '#';
                el.innerHTML = '';
                var newCb = document.createElement('input');
                newCb.type = 'checkbox';
                newCb.name = cb.name;
                newCb.id = cb.id;
                el.appendChild(newCb);
                el.appendChild(document.createTextNode(
                    ' Questo sito raccoglie nomi, email e altre informazioni. Acconsento ai termini della '
                ));
                var a = document.createElement('a');
                a.href = href;
                a.target = '_blank';
                a.textContent = 'Privacy Policy';
                el.appendChild(a);
                el.appendChild(document.createTextNode('.'));
            });

            /* Labels */
            root.querySelectorAll('.mepr-account-form label, .mp-form-label label').forEach(function (el) {
                var t = el.textContent.trim().replace(':', '').replace('*', '').trim();
                if (labelMap[t]) {
                    el.childNodes.forEach(function (node) {
                        if (node.nodeType === 3 && node.textContent.trim().replace(':', '').replace('*', '').trim() === t) {
                            node.textContent = node.textContent.replace(t, labelMap[t]);
                        }
                    });
                }
            });
        }

        /* Disable submit until privacy checkbox is checked */
        function guardSubmit() {
            var cb = document.querySelector('.mepr-checkbox-field input[type="checkbox"]');
            var btn = document.querySelector('input[value="Sign Up"], input[value="Registrati"]');
            if (!cb || !btn) return;
            btn.disabled = !cb.checked;
            btn.style.opacity = cb.checked ? '1' : '0.5';
            btn.style.cursor = cb.checked ? 'pointer' : 'not-allowed';
            cb.addEventListener('change', function () {
                btn.disabled = !cb.checked;
                btn.style.opacity = cb.checked ? '1' : '0.5';
                btn.style.cursor = cb.checked ? 'pointer' : 'not-allowed';
            });
        }

        /* Run on page load */
        translateAll(document);
        guardSubmit();

        /* Watch for dynamically added elements (popups, AJAX content) */
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        translateAll(node);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    })();
    </script>
    <?php
});

add_action('admin_menu', function () {
    add_options_page(
        'SIBIA Onboarding',
        'SIBIA Onboarding',
        'manage_options',
        'sibia-onboarding',
        'sibia_onboarding_render_settings'
    );
});

add_action('admin_init', function () {
    register_setting('sibia_onboarding_settings', 'sibia_onboarding_api_base');
    register_setting('sibia_onboarding_settings', 'sibia_onboarding_secret');
    register_setting('sibia_onboarding_settings', 'sibia_onboarding_header');

    add_settings_section(
        'sibia_onboarding_main',
        'Configurazione ApiConnect',
        '__return_false',
        'sibia-onboarding'
    );

    add_settings_field(
        'sibia_onboarding_api_base',
        'Base URL ApiConnect',
        function () {
            $value = esc_attr(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'));
            echo "<input type=\"text\" class=\"regular-text\" name=\"sibia_onboarding_api_base\" value=\"{$value}\" />";
        },
        'sibia-onboarding',
        'sibia_onboarding_main'
    );

    add_settings_field(
        'sibia_onboarding_secret',
        'Onboarding secret',
        function () {
            $value = esc_attr(sibia_onboarding_get_option('sibia_onboarding_secret', ''));
            echo "<input type=\"password\" class=\"regular-text\" name=\"sibia_onboarding_secret\" value=\"{$value}\" />";
        },
        'sibia-onboarding',
        'sibia_onboarding_main'
    );

    add_settings_field(
        'sibia_onboarding_header',
        'Header onboarding',
        function () {
            $value = esc_attr(sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY'));
            echo "<input type=\"text\" class=\"regular-text\" name=\"sibia_onboarding_header\" value=\"{$value}\" />";
        },
        'sibia-onboarding',
        'sibia_onboarding_main'
    );
});

function sibia_onboarding_render_settings()
{
    $updateStatus = isset($_GET['sibia_update']) ? sanitize_text_field($_GET['sibia_update']) : '';
    $statusMessages = array(
        'success'       => array('success', 'Plugin aggiornato con successo! La pagina verrà ricaricata.'),
        'error_upload'  => array('error',   'Nessun file ricevuto o errore durante l\'upload.'),
        'error_zip'     => array('error',   'ZipArchive non disponibile su questo server.'),
        'error_open'    => array('error',   'Impossibile aprire il file ZIP.'),
        'error_invalid' => array('error',   'ZIP non valido: non contiene sibia-onboarding.php alla radice.'),
        'error_extract' => array('error',   'Estrazione fallita. Verificare i permessi della cartella plugins.'),
    );
    ?>
    <div class="wrap">
        <h1>SIBIA Onboarding</h1>

        <!-- ===== Configurazione ApiConnect ===== -->
        <form method="post" action="options.php">
            <?php
            settings_fields('sibia_onboarding_settings');
            do_settings_sections('sibia-onboarding');
            submit_button('Salva impostazioni');
            ?>
        </form>

        <hr />

        <!-- ===== Aggiornamento plugin ===== -->
        <h2>Aggiornamento plugin</h2>
        <p style="color:#555;">Carica il file <strong>sibia-onboarding.zip</strong> per aggiornare il plugin direttamente,
        senza passare per il meccanismo di WordPress che crea cartelle duplicate (-1, -2, ecc.).</p>

        <?php if (!empty($updateStatus) && isset($statusMessages[$updateStatus])) :
            [$type, $msg] = $statusMessages[$updateStatus]; ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible" style="margin-left:0;">
                <p><?php echo esc_html($msg); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="sibia_self_update" />
            <?php wp_nonce_field('sibia_self_update_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="sibia_plugin_zip">ZIP del plugin</label></th>
                    <td>
                        <input type="file" id="sibia_plugin_zip" name="sibia_plugin_zip" accept=".zip" />
                        <p class="description">Seleziona <code>sibia-onboarding.zip</code> dal tuo computer.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Aggiorna plugin'); ?>
        </form>
    </div>
    <?php
}

add_action('admin_post_sibia_self_update', 'sibia_handle_self_update');

function sibia_handle_self_update()
{
    if (!current_user_can('manage_options')) {
        wp_die('Accesso negato.');
    }

    check_admin_referer('sibia_self_update_nonce');

    $redirect = admin_url('options-general.php?page=sibia-onboarding&sibia_update=');

    // Verifica upload
    if (empty($_FILES['sibia_plugin_zip']) || $_FILES['sibia_plugin_zip']['error'] !== UPLOAD_ERR_OK) {
        wp_redirect($redirect . 'error_upload');
        exit;
    }

    // Verifica ZipArchive
    if (!class_exists('ZipArchive')) {
        wp_redirect($redirect . 'error_zip');
        exit;
    }

    $tmpFile = $_FILES['sibia_plugin_zip']['tmp_name'];
    $zip = new ZipArchive();

    if ($zip->open($tmpFile) !== true) {
        wp_redirect($redirect . 'error_open');
        exit;
    }

    // Verifica che il ZIP contenga la struttura corretta (file alla radice, nessuna cartella padre)
    if ($zip->locateName('sibia-onboarding.php') === false) {
        $zip->close();
        wp_redirect($redirect . 'error_invalid');
        exit;
    }

    // Estrai in wp-content/plugins/sibia-onboarding/ → sovrascrive i file sul posto
    $result = $zip->extractTo(WP_PLUGIN_DIR . '/sibia-onboarding/');
    $zip->close();

    if (!$result) {
        wp_redirect($redirect . 'error_extract');
        exit;
    }

    wp_redirect($redirect . 'success');
    exit;
}

function sibia_onboarding_get_option($key, $default = '')
{
    $value = get_option($key);
    return $value === false ? $default : $value;
}

add_shortcode('sibia_onboarding', function () {
    $user = wp_get_current_user();
    if (!$user || !$user->exists()) {
        return '<div class="sibia-onboarding"><p>Per accedere alla configurazione devi essere autenticato.</p></div>';
    }

    $state = sibia_onboarding_handle_post($user);
    $notice = $state['notice'];
    $apiKey = $state['apiKey'];

    ob_start();
    ?>
    <section class="sibia-onboarding">
        <header class="sibia-hero">
            <div class="sibia-hero__logo">
                <img src="https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png" alt="SIBIA" />
            </div>
            <div class="sibia-hero__text">
                <h1>Configurazione Sync Picam7 &rarr; Pipedrive</h1>
                <p>Completa l'onboarding e collega la tua installazione Sync.PicamPipedrive.</p>
            </div>
        </header>

        <?php if (!empty($notice)) : ?>
            <div class="sibia-step">
                <?php echo $notice; ?>
            </div>
        <?php endif; ?>

        <div class="sibia-steps">
            <?php echo sibia_onboarding_render_download_step(); ?>
            <?php echo sibia_onboarding_render_form(); ?>

            <div class="sibia-step">
                <div class="sibia-step__title">3. API key Sync.PicamPipedrive</div>
                <div class="sibia-step__body">
                    <div class="sibia-panel">
                        <p>Questa e' la chiave da inserire nel programma Sync.PicamPipedrive.</p>
                        <input type="text" readonly value="<?php echo esc_attr($apiKey ?: 'Non ancora generata'); ?>" />
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
});

add_shortcode('sibia_portal', function () {
    $user = wp_get_current_user();
    if (!$user || !$user->exists()) {
        return '<div class="sibia-onboarding"><p>Per accedere alla configurazione devi essere autenticato.</p></div>';
    }

    $state = sibia_onboarding_handle_post($user);
    $notice              = $state['notice'];
    $apiKey              = $state['apiKey'];
    $activeSection       = $state['activeSection'];
    $noticeType          = $state['noticeType'] ?? '';
    $activeServiceDetail = $state['activeServiceDetail'] ?? '';

    /* Gestione ritorno da OAuth Fatture in Cloud: apre la sezione giusta.
       Il messaggio di errore viene mostrato solo se FIC non è collegato (valutato dopo). */
    $sytficOauthError = isset($_GET['sytfic_error']);
    if (isset($_GET['sytfic_connected']) && $_GET['sytfic_connected'] === '1') {
        $activeSection       = 'soluzioni';
        $activeServiceDetail = 'synchroteam-fic';
    } elseif ($sytficOauthError) {
        $activeSection       = 'soluzioni';
        $activeServiceDetail = 'synchroteam-fic';
    }
    /* Fallback: se nessun POST/OAuth ha impostato la sezione, leggi da URL */
    if (empty($activeSection) && isset($_GET['section'])) {
        $allowed = array('registrazione', 'prodotti', 'soluzioni', 'docs', 'fatturazione', 'abbonamento');
        $req = sanitize_text_field(wp_unslash($_GET['section']));
        if (in_array($req, $allowed, true)) {
            $activeSection = $req;
        }
    }

    /* Carica dati utente — WP fornisce solo email, tutto il resto viene dall'API */
    $email = $user->user_email;

    /* Fonte unica di verità: API */
    $clienteData  = sibia_load_cliente_api($email);
    $isRegistered = !empty($clienteData['ragioneSociale']);

    $panelClass = $noticeType === 'error' ? 'sibia-panel sibia-panel--error' : 'sibia-panel sibia-panel--success';
    $hasRagioneSociale = $isRegistered;
    $navDisabled = $hasRagioneSociale ? '' : ' is-disabled';

    /* Carica stato configurazione Picam7-Pipedrive da API */
    $picPipStatus = sibia_get_pic_pip_status($email);
    $picPipToken = $picPipStatus['token'] ?? '';
    $picPipPipedriveKey = $picPipStatus['pipedriveApiKey'] ?? '';
    $picPipApiKey = $picPipStatus['apiKey'] ?? '';
    $picPipInTest = !empty($picPipStatus['inTest']);
    $picPipDitta = $picPipStatus['dittaPicam7'] ?? '';
    $picPipUltimoRun = $picPipStatus['ultimoRun'] ?? '';
    $picPipMappatureCount = intval($picPipStatus['mappatureCount'] ?? 0);
    $picPipP7ExtAttivo = !empty($picPipStatus['p7extAttivo']);
    /* Se il handler ha appena rigenerato/salvato, l'apiKey locale e' piu' aggiornata */
    if (!empty($state['apiKey'])) {
        $picPipApiKey = $state['apiKey'];
    }

    /* Carica stato configurazione Synchroteam-FattureInCloud da API */
    $sytFicStatus            = sibia_get_sytfic_status($email);
    $sytFicApiKey            = $sytFicStatus['apiKey'] ?? '';
    $sytFicConfigured        = !empty($sytFicStatus['configured']);
    $sytFicDomain            = $sytFicStatus['synchroteamDomain'] ?? '';
    $sytFicFicCompanyId      = $sytFicStatus['ficCompanyId'] ?? '';
    $sytFicTriggerJob        = $sytFicStatus['triggerJob'] ?? 'completed';
    $sytFicUltimoRun         = $sytFicStatus['ultimoRun'] ?? '';
    $sytFicUltimoRunClienti  = $sytFicStatus['ultimoRunClienti'] ?? '';
    $sytFicUltimoRunJob      = $sytFicStatus['ultimoRunJob'] ?? '';
    $sytFicClientiSync       = intval($sytFicStatus['clientiSincronizzati'] ?? 0);
    $sytFicInterventiSync    = intval($sytFicStatus['interventiSincronizzati'] ?? 0);
    $sytFicProductIdOre           = $sytFicStatus['productIdOre'] ?? null;
    $sytFicSyncArticoliAbilitato  = $sytFicStatus['syncArticoliAbilitato'] ?? false;
    $sytFicSyncClientiAbilitato   = $sytFicStatus['syncClientiAbilitato']  ?? false;
    /* Piano SynchToFic corrente: standard / professional — letto dalla billing status */
    $allBillingStatus  = ($isRegistered && !empty($email)) ? sibia_get_billing_status($email) : array();
    $sytFicBillingItem = null;
    foreach ($allBillingStatus as $_bsItem) {
        if (strcasecmp($_bsItem['servizio'] ?? '', 'SynchToFic') === 0) {
            $sytFicBillingItem = $_bsItem;
            break;
        }
    }
    /* Fonte di verità: dbo.Abbonamenti.abb_stato letto via API (sigia_get_billing_status).
     * La sync MemberPress → DB è stata rimossa: il DB SIBIA è l'unica fonte di verità
     * e le modifiche manuali vengono sempre rispettate. Gli aggiornamenti di stato
     * avvengono solo tramite hook MemberPress (mepr-transaction-completed, ecc.)
     * o chiamate esplicite all'API billing/membership. */
    /* Clienti pendenti unificati (motivo='multi_match', 'missing_address', 'missing_address_site', 'myid_conflict').
     * - multi_match          → cliente Synchroteam con multipli candidati FIC, risolvibile manualmente
     * - missing_address      → cliente FIC senza indirizzo: solo segnalazione, l'utente corregge su FIC
     * - missing_address_site → sito FIC (entità senza tipologia) senza indirizzo: segnalazione
     * - myid_conflict        → cliente FIC con Code che confligge con MyId di altro customer Synchroteam
     */
    $sytFicPendentiAll       = ($sytFicConfigured && !empty($email))
        ? sibia_get_sytfic_clienti_pendenti($email)
        : array();
    $sytFicClientiPendenti  = array_values(array_filter($sytFicPendentiAll, function($p) {
        return ($p['motivo'] ?? '') === 'multi_match';
    }));
    $sytFicFicClientiErrore = array_values(array_filter($sytFicPendentiAll, function($p) {
        return ($p['motivo'] ?? '') === 'missing_address';
    }));
    $sytFicFicSitiErrore    = array_values(array_filter($sytFicPendentiAll, function($p) {
        return ($p['motivo'] ?? '') === 'missing_address_site';
    }));
    $sytFicMyIdConflict     = array_values(array_filter($sytFicPendentiAll, function($p) {
        return ($p['motivo'] ?? '') === 'myid_conflict';
    }));
    /* $sytFicHasToken: token presente nel DB (per bottoni Connetti/Scollega, indipendente dal test reale) */
    $sytFicHasToken          = !empty($sytFicStatus['ficConnected']);
    /* Test reale della connessione FIC tramite ApiConnect. Restituisce array con:
     *   - connected      (bool): collegamento valido (access ok o refresh in attesa)
     *   - pendingRefresh (bool): access scaduto, refresh in corso dal Servizio
     * Mapping banner: connected=false → rosso, pendingRefresh=true → arancione, altrimenti verde.
     */
    $sytFicFicTestResult     = $sytFicHasToken ? sibia_test_sytfic_fic($email) : array('connected' => false, 'pendingRefresh' => false);
    $sytFicFicConnected      = !empty($sytFicFicTestResult['connected']);
    $sytFicFicPendingRefresh = !empty($sytFicFicTestResult['pendingRefresh']);
    /* $synchTestOk: risultato ultimo test Synchroteam (scritto da SibiaAdmin). null = non ancora testato → fallback a $sytFicConfigured */
    $synchTestOkRaw          = isset($sytFicStatus['synchTestOk']) ? $sytFicStatus['synchTestOk'] : null;
    $synchBannerOk           = $synchTestOkRaw !== null ? (bool)$synchTestOkRaw : ($sytFicConfigured && !empty($sytFicDomain));
    /* Carica articoli FIC per il dropdown (se token presente in DB) */
    $sytFicFicProductsResult = $sytFicHasToken ? sibia_get_sytfic_fic_products($email) : array('products' => array(), 'error' => null);
    $sytFicFicProducts       = $sytFicFicProductsResult['products'] ?? array();
    $sytFicFicProductsError  = $sytFicFicProductsResult['error'] ?? null;
    /* Se il handler ha appena salvato, aggiorna l'apiKey dalla risposta */
    if (!empty($state['sytfic_apiKey'])) {
        $sytFicApiKey = $state['sytfic_apiKey'];
        $sytFicConfigured = true;
    }
    /* Risultati test connessione API (disponibili solo subito dopo il salvataggio) */
    $sytFicTestSynch = $state['sytfic_test_synch'] ?? null;
    $sytFicTestFic   = $state['sytfic_test_fic']   ?? null;

    /* Carica stato prodotti da API. Se il record non esiste ancora (null), lo crea automaticamente. */
    $sytData = $isRegistered ? sibia_get_prodotto_status($email, 'synchroteam') : array();
    if ($isRegistered && is_null($sytData)) {
        sibia_save_prodotto($email, 'synchroteam', '', false);
        $sytData = array('dominio' => '', 'contrattoAccettato' => false, 'dataAccettazione' => null);
    }

    ob_start();
    ?>
    <section class="sibia-portal">
        <aside class="sibia-portal__nav">
            <div class="sibia-portal__brand">
                <img src="https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png" alt="SIBIA" />
                <span>Area Riservata</span>
            </div>
            <nav class="sibia-nav-section">
                <button class="sibia-nav-item<?php echo $activeSection === 'registrazione' ? ' is-active' : ''; ?>" data-section="registrazione">Dati Registrazione</button>
                <button class="sibia-nav-item<?php echo $activeSection === 'prodotti' ? ' is-active' : ''; ?><?php echo $navDisabled; ?>" data-section="prodotti"<?php echo $hasRagioneSociale ? '' : ' disabled'; ?>>Prodotti</button>
                <button class="sibia-nav-item<?php echo $activeSection === 'soluzioni' ? ' is-active' : ''; ?><?php echo $navDisabled; ?>" data-section="soluzioni"<?php echo $hasRagioneSociale ? '' : ' disabled'; ?>>Servizi<?php if (!empty($sytFicClientiPendenti)) : ?><span class="sibia-nav-badge"><?php echo count($sytFicClientiPendenti); ?></span><?php endif; ?></button>
                <button class="sibia-nav-item<?php echo $activeSection === 'docs' ? ' is-active' : ''; ?><?php echo $navDisabled; ?>" data-section="docs"<?php echo $hasRagioneSociale ? '' : ' disabled'; ?>>Documentazione</button>
            </nav>
            <div class="sibia-nav-spacer"></div>
            <button class="sibia-nav-item<?php echo $activeSection === 'fatturazione' ? ' is-active' : ''; ?><?php echo $navDisabled; ?>" data-section="fatturazione"<?php echo $hasRagioneSociale ? '' : ' disabled'; ?>>Fatturazione</button>
            <a href="<?php echo wp_logout_url(home_url()); ?>" class="sibia-nav-item" style="text-decoration: none; margin-top: 8px; border-color: #fecaca; background: #fee2e2; color: #991b1b;">Esci</a>
            <div style="margin-top: 10px; text-align: center; font-size: 11px; color: var(--sibia-muted);">Versione <?php
                $plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
                echo esc_html($plugin_data['Version']);
            ?></div>
        </aside>
        <main class="sibia-portal__main">

            <!-- ======================== DATI REGISTRAZIONE ======================== -->
            <section class="sibia-section<?php echo $activeSection === 'registrazione' ? ' is-active' : ''; ?>" data-section="registrazione">
                <div class="sibia-hero-block">
                    <h2>Dati Registrazione</h2>
                    <p>Dati anagrafici dell'azienda</p>
                </div>

                <?php if ($activeSection === 'registrazione' && !empty($notice)) : ?>
                    <div class="<?php echo esc_attr($panelClass); ?>"><?php echo $notice; ?></div>
                <?php endif; ?>

                <!-- Form dati anagrafici -->
                <div class="sibia-step">
                    <div class="sibia-step__title">Anagrafica</div>
                    <form class="sibia-form" method="post">
                        <?php wp_nonce_field('sibia_registrazione_submit', 'sibia_registrazione_nonce'); ?>

                        <!-- Dati Personali -->
                        <div class="sibia-form-section">
                            <div class="sibia-form-section__header"><h3>Dati Personali</h3></div>
                            <div class="sibia-form-grid-2col">
                                <label>
                                    <span>Nome</span>
                                    <input type="text" name="first_name" value="<?php echo esc_attr($clienteData['nome'] ?? ''); ?>" />
                                </label>
                                <label>
                                    <span>Cognome</span>
                                    <input type="text" name="last_name" value="<?php echo esc_attr($clienteData['cognome'] ?? ''); ?>" />
                                </label>
                            </div>
                            <div class="sibia-form-grid-2col">
                                <label>
                                    <span>Email</span>
                                    <input type="email" value="<?php echo esc_attr($email); ?>" readonly />
                                </label>
                                <label>
                                    <span>Telefono personale</span>
                                    <input type="tel" name="telefono_referente" value="<?php echo esc_attr($clienteData['telefonoReferente'] ?? ''); ?>" placeholder="+39 333 1234567" />
                                </label>
                            </div>
                        </div>

                        <!-- Dati Aziendali -->
                        <div class="sibia-form-section">
                            <div class="sibia-form-section__header"><h3>Dati Aziendali</h3></div>
                            <label>
                                <span>Ragione Sociale</span>
                                <input type="text" name="ragione_sociale" value="<?php echo esc_attr($clienteData['ragioneSociale'] ?? ''); ?>" required />
                            </label>
                            <div class="sibia-form-grid-2col">
                                <label>
                                    <span>Partita IVA</span>
                                    <input type="text" name="partita_iva" value="<?php echo esc_attr($clienteData['partitaIva'] ?? ''); ?>" placeholder="12345678901" />
                                </label>
                                <label>
                                    <span>Codice Fiscale</span>
                                    <input type="text" name="codice_fiscale" value="<?php echo esc_attr($clienteData['codiceFiscale'] ?? ''); ?>" />
                                </label>
                            </div>
                            <label>
                                <span>Telefono aziendale</span>
                                <input type="tel" name="telefono" value="<?php echo esc_attr($clienteData['telefono'] ?? ''); ?>" placeholder="+39 051 1234567" />
                            </label>
                            <div class="sibia-form-grid-2col">
                                <label>
                                    <span>Codice SDI <small style="font-weight:normal;color:#888;">(fatturazione elettronica)</small></span>
                                    <input type="text" name="codice_sdi" value="<?php echo esc_attr(''); ?>" maxlength="7" placeholder="0000000" />
                                </label>
                                <label>
                                    <span>PEC <small style="font-weight:normal;color:#888;">(fatturazione elettronica)</small></span>
                                    <input type="email" name="pec" value="<?php echo esc_attr(''); ?>" placeholder="azienda@pec.it" />
                                </label>
                            </div>
                        </div>

                        <!-- Indirizzo -->
                        <div class="sibia-form-section">
                            <div class="sibia-form-section__header"><h3>Indirizzo</h3></div>
                            <label>
                                <span>Indirizzo</span>
                                <input type="text" name="indirizzo" value="<?php echo esc_attr($clienteData['indirizzo'] ?? ''); ?>" />
                            </label>
                            <label>
                                <span>Indirizzo 2</span>
                                <input type="text" name="indirizzo2" value="<?php echo esc_attr($clienteData['indirizzo2'] ?? ''); ?>" placeholder="Appartamento, scala, interno..." />
                            </label>
                            <div class="sibia-form-row-cap">
                                <label>
                                    <span>CAP</span>
                                    <input type="text" name="cap" value="<?php echo esc_attr($clienteData['cap'] ?? ''); ?>" maxlength="5" />
                                </label>
                                <label>
                                    <span>Citt&agrave;</span>
                                    <input type="text" name="citta" value="<?php echo esc_attr($clienteData['citta'] ?? ''); ?>" />
                                </label>
                                <label>
                                    <span>Prov.</span>
                                    <input type="text" name="provincia" value="<?php echo esc_attr($clienteData['provincia'] ?? ''); ?>" maxlength="2" placeholder="BO" />
                                </label>
                            </div>
                        </div>

                        <button type="submit" name="sibia_registrazione_submit" class="sibia-btn">Salva Dati</button>
                    </form>
                </div>

                <!-- Form cambio password -->
                <div class="sibia-step">
                    <div class="sibia-step__title">Sicurezza</div>
                    <form class="sibia-form" method="post">
                        <?php wp_nonce_field('sibia_password_submit', 'sibia_password_nonce'); ?>
                        <label>
                            <span>Password attuale</span>
                            <input type="password" name="current_password" required />
                        </label>
                        <div class="sibia-form-grid-2col">
                            <label>
                                <span>Nuova password</span>
                                <input type="password" name="new_password" required />
                            </label>
                            <label>
                                <span>Conferma password</span>
                                <input type="password" name="confirm_password" required />
                            </label>
                        </div>
                        <button type="submit" name="sibia_password_submit" class="sibia-btn">Cambia Password</button>
                    </form>
                </div>
            </section>

            <!-- ======================== PRODOTTI ======================== -->
            <section class="sibia-section<?php echo $activeSection === 'prodotti' ? ' is-active' : ''; ?>" data-section="prodotti">
                <?php if (!$isRegistered) : ?>
                <div class="sibia-panel sibia-panel--error" style="margin-bottom:0;">
                    Completa prima i <strong>Dati Registrazione</strong> per accedere a questa sezione.
                </div>
                <?php else : ?>
                <div class="sibia-solution-list">
                    <div class="sibia-hero-block">
                        <h2>Prodotti</h2>
                        <p>Prodotti cloud in abbonamento</p>
                    </div>
                    <div class="sibia-card-grid">
                        <div class="sibia-card" data-solution="synchroteam">
                            <span class="sibia-card__title">Synchroteam</span>
                            <span class="sibia-card__meta">Field Service Management</span>
                        </div>
                        <div class="sibia-card is-coming-soon">
                            <span class="sibia-card__title">TourSolver</span>
                            <span class="sibia-card__meta">Ottimizzazione percorsi</span>
                        </div>
                        <div class="sibia-card is-coming-soon">
                            <span class="sibia-card__title">Delivery</span>
                            <span class="sibia-card__meta">Gestione consegne</span>
                        </div>
                        <div class="sibia-card is-coming-soon">
                            <span class="sibia-card__title">Nomadia Protect</span>
                            <span class="sibia-card__meta">Sicurezza lavoratori</span>
                        </div>
                    </div>
                </div>

                <!-- Dettaglio: Synchroteam -->
                <div class="sibia-solution-detail" data-solution="synchroteam">
                    <button type="button" class="sibia-btn sibia-btn--ghost sibia-back-btn">&larr; Torna ai prodotti</button>
                    <div class="sibia-hero-block">
                        <h2>Synchroteam</h2>
                        <p>Field Service Management</p>
                    </div>
                    <?php if ($activeSection === 'prodotti' && !empty($notice)) : ?>
                        <div class="<?php echo esc_attr($panelClass); ?>"><?php echo $notice; ?></div>
                    <?php endif; ?>
                    <div class="sibia-step">
                        <div class="sibia-step__title">Configurazione</div>
                        <form class="sibia-form" method="post">
                            <?php wp_nonce_field('sibia_syt_submit', 'sibia_syt_nonce'); ?>
                            <label>
                                <span>Dominio Synchroteam</span>
                                <input type="url" name="syt_dominio" value="<?php echo esc_attr($sytData['dominio'] ?? ''); ?>" placeholder="https://nomesociet&agrave;.synchroteam.com/" />
                            </label>
                            <div style="margin-top:16px;padding:14px;background:#f8f8f8;border-radius:6px;border:1px solid #e0e0e0;">
                                <?php $sytContratto = !empty($sytData['contrattoAccettato']); ?>
                                <label class="sibia-toggle-label" style="align-items:flex-start;gap:10px;<?php echo $sytContratto ? 'opacity:.65;cursor:default;' : ''; ?>">
                                    <input type="checkbox" name="syt_contratto" value="1"<?php echo $sytContratto ? ' checked disabled' : ''; ?> style="margin-top:3px;flex-shrink:0;" />
                                    <?php if ($sytContratto) : ?><input type="hidden" name="syt_contratto" value="1" /><?php endif; ?>
                                    <span>Ho letto e accetto le clausole del
                                        <a href="<?php echo esc_url(sibia_get_contratto_url('synchroteam')); ?>" target="_blank" style="color:#0073aa;text-decoration:underline;">Contratto di Servizi Cloud Synchroteam</a>
                                    </span>
                                </label>
                                <?php if (!empty($sytData['dataAccettazione'])) : ?>
                                    <p style="margin:8px 0 0;font-size:0.85em;color:#666;">Accettato il: <?php
                                        $dtAcc = new DateTime($sytData['dataAccettazione'], new DateTimeZone('UTC'));
                                        $dtAcc->setTimezone(new DateTimeZone('Europe/Rome'));
                                        echo esc_html($dtAcc->format('d/m/Y H:i'));
                                    ?></p>
                                <?php endif; ?>
                            </div>
                            <button type="submit" name="sibia_syt_submit" class="sibia-btn" style="margin-top:16px;">Salva</button>
                        </form>
                    </div>
                </div>
                <?php endif; // isRegistered — prodotti ?>

            </section>

            <!-- ======================== SOLUZIONI ======================== -->
            <section class="sibia-section<?php echo $activeSection === 'soluzioni' ? ' is-active' : ''; ?>" data-section="soluzioni">
                <?php if (!$isRegistered) : ?>
                <div class="sibia-panel sibia-panel--error" style="margin-bottom:0;">
                    Completa prima i <strong>Dati Registrazione</strong> per accedere a questa sezione.
                </div>
                <?php else : ?>
                <div class="sibia-solution-list">
                    <div class="sibia-hero-block">
                        <h2>Servizi</h2>
                        <p>Integrazioni e sincronizzazioni</p>
                    </div>
                    <div class="sibia-card-grid">
                        <div class="sibia-card" data-solution="picam-pipedrive">
                            <span class="sibia-card__title">Picam7 &#8596; Pipedrive</span>
                            <span class="sibia-card__meta">Sincronizzazione tra gestionale Picam7 e CRM Pipedrive</span>
                        </div>
                        <div class="sibia-card" data-solution="synchroteam-fic">
                            <span class="sibia-card__title">Synchroteam &#8596; Fatture in Cloud</span>
                            <span class="sibia-card__meta">Sincronizzazione tra Synchroteam e Fatture in Cloud</span>
                        </div>
                    </div>
                </div>

                <!-- Dettaglio: Picam7 <-> Pipedrive -->
                <div class="sibia-solution-detail" data-solution="picam-pipedrive">
                    <button type="button" class="sibia-btn sibia-btn--ghost sibia-back-btn">&larr; Torna alle soluzioni</button>
                    <div class="sibia-hero-block">
                        <h2>Picam7 &#8596; Pipedrive</h2>
                        <p>Configurazione della sincronizzazione</p>
                        <?php if ($picPipInTest) : ?>
                            <span class="sibia-badge sibia-badge--test">IN TEST</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($activeSection === 'soluzioni' && !empty($notice)) : ?>
                        <div class="<?php echo esc_attr($panelClass); ?>"><?php echo $notice; ?></div>
                    <?php endif; ?>

                    <!-- Download Installer -->
                    <div class="sibia-step">
                        <div class="sibia-step__title">Download Installer</div>
                        <div class="sibia-step__body">
                            <div class="sibia-panel">
                                <p><strong>Sync.PicamPipedrive</strong></p>
                                <p>Scarica e installa il programma sul computer/server dove risiede il database Picam7.</p>

                                <a href="https://api.cloud-ar.it/downloads/SyncPicamPipedrive_Setup.exe"
                                   class="sibia-btn sibia-btn-download"
                                   download
                                   style="display: inline-block; margin: 10px 0;">
                                    📥 Scarica Installer (v1.1.0)
                                </a>

                                <div class="sibia-file-info" style="margin-top: 8px;">
                                    <small>
                                        Dimensione: ~75 MB |
                                        SHA256: <?php echo esc_html(sibia_get_installer_hash()); ?>
                                    </small>
                                </div>

                                <a href="<?php echo esc_url(home_url('/guida-installazione-sync-picampipedrive/')); ?>"
                                   target="_blank"
                                   class="sibia-btn sibia-btn--outline"
                                   style="display: inline-flex; margin-top: 16px;">
                                    📖 Guida Installazione Completa
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="sibia-step">
                        <div class="sibia-step__title">Dati collegamento</div>
                        <form class="sibia-form" method="post">
                            <?php wp_nonce_field('sibia_picpip_submit', 'sibia_picpip_nonce'); ?>
                            <label>
                                <span>Token Sync.PicamPipedrive</span>
                                <input type="text" name="token" value="<?php echo esc_attr($picPipToken); ?>" required />
                            </label>
                            <label>
                                <span>API key Pipedrive</span>
                                <input type="text" name="pipedrive_api_key" value="<?php echo esc_attr($picPipPipedriveKey); ?>" required />
                            </label>
                            <button type="submit" name="sibia_picpip_submit" class="sibia-btn">Salva configurazione</button>
                        </form>
                    </div>
                    <div class="sibia-step">
                        <div class="sibia-step__title">API Key generata</div>
                        <div class="sibia-panel">
                            <p>Chiave da inserire nel programma Sync.PicamPipedrive.</p>
                            <div class="sibia-apikey-row">
                                <input type="text" readonly value="<?php echo esc_attr($picPipApiKey ?: 'Non ancora generata'); ?>" id="sibia-apikey-field" />
                                <?php if (!empty($picPipApiKey)) : ?>
                                <button type="button" class="sibia-btn sibia-btn--outline sibia-btn--small" onclick="navigator.clipboard.writeText(document.getElementById('sibia-apikey-field').value)">Copia</button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($picPipApiKey)) : ?>
                            <form method="post" style="margin-top: 12px;">
                                <?php wp_nonce_field('sibia_regenerate_key_submit', 'sibia_regenerate_key_nonce'); ?>
                                <button type="submit" name="sibia_regenerate_key_submit" class="sibia-btn sibia-btn--outline" onclick="return confirm('Rigenerando la chiave, quella attuale non sara piu valida. Continuare?')">Rigenera chiave API</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($picPipStatus['configured'])) : ?>
                    <div class="sibia-step">
                        <div class="sibia-step__title">Stato sincronizzazione</div>
                        <div class="sibia-sync-status">
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Ditta Picam7</span>
                                <span class="sibia-sync-status__value"><?php echo esc_html($picPipDitta ?: 'Non impostata'); ?></span>
                            </div>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Ultimo aggiornamento</span>
                                <span class="sibia-sync-status__value"><?php
                                    if (!empty($picPipUltimoRun)) {
                                        $dt = new DateTime($picPipUltimoRun);
                                        echo esc_html($dt->format('d/m/Y H:i'));
                                    } else {
                                        echo 'Mai eseguito';
                                    }
                                ?></span>
                            </div>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Dati sincronizzati</span>
                                <span class="sibia-sync-status__value"><?php echo esc_html($picPipMappatureCount); ?> record</span>
                            </div>
                        </div>
                    </div>
                    <div class="sibia-step">
                        <div class="sibia-step__title">P7Extension CRM (Trattative Commerciali)</div>
                        <div class="sibia-panel">
                            <p>Abilita la sincronizzazione del modulo <strong>Trattative Commerciali</strong> di P7Extension verso Pipedrive.</p>
                            <form class="sibia-form" method="post">
                                <?php wp_nonce_field('sibia_p7ext_submit', 'sibia_p7ext_nonce'); ?>
                                <label class="sibia-toggle-label">
                                    <input type="checkbox" name="p7ext_attivo" value="1"<?php echo $picPipP7ExtAttivo ? ' checked' : ''; ?> onchange="this.form.submit()" />
                                    <span>Abilita integrazione P7Extension</span>
                                </label>
                                <input type="hidden" name="sibia_p7ext_submit" value="1" />
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Dettaglio: Synchroteam ↔ Fatture in Cloud -->
                <div class="sibia-solution-detail" data-solution="synchroteam-fic">
                    <button type="button" class="sibia-btn sibia-btn--ghost sibia-back-btn">&larr; Torna ai servizi</button>
                    <div class="sibia-hero-block">
                        <h2>Synchroteam &#8596; Fatture in Cloud</h2>
                        <p>Configurazione della sincronizzazione</p>
                        <?php if (!empty($sytFicStatus['inTest'])) : ?>
                            <span class="sibia-badge sibia-badge--test">IN TEST</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($activeSection === 'soluzioni' && !empty($notice)) : ?>
                        <div class="<?php echo esc_attr($panelClass); ?>"><?php echo $notice; ?></div>
                    <?php endif; ?>

                    <!-- Banner stato connessioni -->
                    <div class="sibia-conn-bar">
                        <div class="sibia-conn-badge <?php echo $synchBannerOk ? 'sibia-conn-badge--ok' : 'sibia-conn-badge--err'; ?>">
                            <span class="sibia-conn-badge__dot"></span>
                            Synchroteam <?php echo $synchBannerOk ? 'collegato' : 'non collegato'; ?>
                        </div>
                        <?php
                        // 3 stati badge FIC:
                        // - pendingRefresh=true → arancione "Sincronizzazione in corso"
                        // - connected=true     → verde "collegato"
                        // - altrimenti         → rosso "non collegato"
                        if ($sytFicFicPendingRefresh) {
                            $_ficBadgeClass = 'sibia-conn-badge--pending';
                            $_ficBadgeText  = 'Fatture in Cloud — sincronizzazione in corso';
                        } elseif ($sytFicFicConnected) {
                            $_ficBadgeClass = 'sibia-conn-badge--ok';
                            $_ficBadgeText  = 'Fatture in Cloud collegato';
                        } else {
                            $_ficBadgeClass = 'sibia-conn-badge--err';
                            $_ficBadgeText  = 'Fatture in Cloud non collegato';
                        }
                        ?>
                        <div class="sibia-conn-badge <?php echo esc_attr($_ficBadgeClass); ?>">
                            <span class="sibia-conn-badge__dot"></span>
                            <?php echo esc_html($_ficBadgeText); ?>
                        </div>
                    </div>

                    <!-- Banner Demo / Scaduto -->
                    <?php
                    $_sytBillingStato = $sytFicBillingItem['stato'] ?? 'inattivo';
                    if ($_sytBillingStato === 'demo') :
                        $_fineDemo   = $sytFicBillingItem['dataFineDemo'] ?? null;
                        $_ggRimasti  = $_fineDemo
                            ? max(0, (int) ceil((strtotime($_fineDemo) - time()) / 86400))
                            : null;
                        $_recUsati   = intval($sytFicBillingItem['recordSincronizzatiDemo'] ?? 0);
                        $_recLimite  = intval($sytFicBillingItem['limiteRecordDemo'] ?? 50);
                        $_pianoAttuale = $sytFicBillingItem['piano'] ?? 'standard';
                        $_billingUrl = esc_url(add_query_arg('section', 'abbonamento', get_permalink()));
                    ?>
                    <div class="sibia-demo-banner sibia-demo-banner--active" style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#e8f5e9,#f1f8e9);border:1px solid #81c784;border-radius:8px;padding:12px 16px;margin-bottom:16px;gap:12px;">
                        <div>
                            <strong style="color:#2e7d32;">&#9989; Demo attiva</strong>
                            <?php if ($_ggRimasti !== null) : ?>
                            &nbsp;&mdash;&nbsp;
                            <span style="color:#388e3c;">
                                <?php echo $_ggRimasti > 0 ? esc_html($_ggRimasti) . ' giorn' . ($_ggRimasti === 1 ? 'o' : 'i') . ' rimast' . ($_ggRimasti === 1 ? 'o' : 'i') : '<strong>Scade oggi</strong>'; ?>
                            </span>
                            <?php endif; ?>
                            <span style="color:#555;font-size:13px;margin-left:8px;">&bull; <?php echo esc_html($_recUsati); ?> / <?php echo esc_html($_recLimite); ?> sincronizzazioni</span>
                            <span style="margin-left:8px;font-size:12px;background:#c8e6c9;color:#1b5e20;padding:2px 7px;border-radius:10px;"><?php echo $_pianoAttuale === 'professional' ? '&#9889; Professional' : 'Standard'; ?></span>
                        </div>
                        <a href="<?php echo $_billingUrl; ?>" class="sibia-btn sibia-btn--primary" style="white-space:nowrap;font-size:13px;padding:6px 14px;">Abbonati ora</a>
                    </div>
                    <?php elseif ($_sytBillingStato === 'attivo') :
                        $_pianoAtt  = $sytFicBillingItem['piano']      ?? 'standard';
                        $_intervAtt = $sytFicBillingItem['intervallo']  ?? '';
                    ?>
                    <div class="sibia-demo-banner" style="display:flex;align-items:center;justify-content:space-between;background:#f0f9ff;border:1px solid #7dd3fc;border-radius:8px;padding:12px 16px;margin-bottom:16px;gap:12px;">
                        <div>
                            <strong style="color:#0369a1;">&#10003; Abbonamento attivo</strong>
                            <span style="margin-left:10px;font-size:12px;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:10px;font-weight:600;">
                                <?php echo $_pianoAtt === 'professional' ? '&#9889; Professional' : 'Standard'; ?>
                                <?php if ($_intervAtt) : ?>&nbsp;&bull;&nbsp;<?php echo $_intervAtt === 'annuale' ? 'Annuale' : 'Mensile'; ?><?php endif; ?>
                            </span>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg('section', 'abbonamento', get_permalink())); ?>" class="sibia-btn sibia-btn--ghost" style="font-size:13px;white-space:nowrap;">Gestisci abbonamento &rarr;</a>
                    </div>
                    <?php elseif ($_sytBillingStato === 'scaduto') : ?>
                    <div class="sibia-demo-banner sibia-demo-banner--expired" style="display:flex;align-items:center;justify-content:space-between;background:#fff3e0;border:1px solid #ffb74d;border-radius:8px;padding:12px 16px;margin-bottom:16px;gap:12px;">
                        <div>
                            <strong style="color:#e65100;">&#9888; Periodo di prova scaduto</strong>
                            <span style="color:#555;font-size:13px;margin-left:8px;">La sincronizzazione &egrave; sospesa. Abbonati per riattivarla.</span>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg('section', 'abbonamento', get_permalink())); ?>" class="sibia-btn sibia-btn--primary" style="white-space:nowrap;font-size:13px;padding:6px 14px;">Scegli un piano</a>
                    </div>
                    <?php endif; ?>

                    <!-- STEP 1: Istruzioni -->
                    <div class="sibia-step sibia-step--collapsible is-collapsed">
                        <div class="sibia-step__title">Come ottenere le credenziali</div>
                        <div class="sibia-step__body">
                            <div class="sibia-panel">
                                <p><strong>Synchroteam — Dominio e API Key</strong></p>
                                <ol style="margin:8px 0 0 18px;padding:0;line-height:1.8;">
                                    <li>Accedi a <strong>Synchroteam</strong> dal tuo browser.</li>
                                    <li>Vai su <strong>Impostazioni &rarr; Profilo</strong> (in alto a destra).</li>
                                    <li>Il <strong>Dominio</strong> &egrave; il prefisso del tuo URL: se accedi a <code>mycompany.synchroteam.com</code>, il dominio &egrave; <code>mycompany</code>.</li>
                                    <li>Nella sezione <strong>API</strong> della pagina Profilo trovi la tua <strong>API Key</strong> (stringa UUID).</li>
                                </ol>
                            </div>
                            <div class="sibia-panel" style="margin-top:12px;">
                                <p><strong>Fatture in Cloud — Collegamento OAuth</strong></p>
                                <ol style="margin:8px 0 0 18px;padding:0;line-height:1.8;">
                                    <li>Dopo aver salvato le credenziali Synchroteam (Step 2), clicca su <strong>&ldquo;Connetti a Fatture in Cloud&rdquo;</strong> nel Step 3.</li>
                                    <li>Verrai reindirizzato al sito di <strong>Fatture in Cloud</strong> per autorizzare l'accesso a SIBIA.</li>
                                    <li>Dopo l'autorizzazione tornerai automaticamente su questa pagina con la connessione attiva.</li>
                                    <li>Il collegamento viene rinnovato automaticamente e non richiede ulteriori azioni.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 2: Configurazione Synchroteam + collegamento FIC -->
                    <div class="sibia-step sibia-step--collapsible is-collapsed">
                        <div class="sibia-step__title">Configurazione</div>
                        <div class="sibia-step__body">
                        <form class="sibia-form" method="post">
                            <?php wp_nonce_field('sibia_sytfic_submit', 'sibia_sytfic_nonce'); ?>
                            <label>
                                <span>Dominio Synchroteam</span>
                                <input type="text" name="sytfic_domain"
                                    value="<?php echo esc_attr($sytFicDomain); ?>"
                                    placeholder="mycompany" required />
                                <small style="color:var(--sibia-muted);">Solo il prefisso, senza &ldquo;.synchroteam.com&rdquo;</small>
                            </label>
                            <label>
                                <span>API Key Synchroteam</span>
                                <input type="password" name="sytfic_api_key"
                                    value="<?php echo esc_attr($sytFicConfigured ? '••••••••' : ''); ?>"
                                    placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required />
                                <small style="color:var(--sibia-muted);">
                                    <?php echo $sytFicConfigured ? 'Chiave gi&agrave; configurata — lascia invariata per non modificarla' : 'UUID dalle impostazioni Synchroteam &rarr; Profilo &rarr; API'; ?>
                                </small>
                            </label>
                            <label>
                                <span>Quando importare gli interventi</span>
                                <select name="sytfic_trigger">
                                    <option value="completed"<?php echo ($sytFicTriggerJob === 'completed' || empty($sytFicTriggerJob)) ? ' selected' : ''; ?>>Al completamento (Completed)</option>
                                    <option value="validated"<?php echo $sytFicTriggerJob === 'validated' ? ' selected' : ''; ?>>Dopo validazione (Validated)</option>
                                </select>
                                <small style="color:var(--sibia-muted);">Scegli quando un intervento Synchroteam viene trasferito su Fatture in Cloud</small>
                            </label>
                            <button type="submit" name="sibia_sytfic_submit" class="sibia-btn">Salva configurazione</button>
                        </form>

                        <!-- Collegamento Fatture in Cloud (abilitato dopo salvataggio config) -->
                        <?php if ($sytFicConfigured) : ?>
                        <div class="sibia-panel" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
                            <form method="post">
                                <?php wp_nonce_field('sibia_sytfic_oauth_start', 'sibia_sytfic_oauth_nonce'); ?>
                                <input type="hidden" name="sibia_sytfic_oauth_start" value="1" />
                                <button type="submit" class="sibia-btn"
                                    <?php if ($sytFicHasToken) echo 'disabled style="opacity:0.4;cursor:not-allowed;"'; ?>>
                                    Collega Fatture in Cloud
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirm('Sei sicuro di voler scollegare Fatture in Cloud?\nTutte le associazioni clienti e gli interventi mappati verranno rimossi.\nLa sincronizzazione si interromperà finché non ricolleghi l\'account.');">
                                <?php wp_nonce_field('sibia_sytfic_fic_disconnect', 'sibia_sytfic_fic_disconnect_nonce'); ?>
                                <input type="hidden" name="sibia_sytfic_fic_disconnect" value="1" />
                                <button type="submit" class="sibia-btn" style="background:#c0392b;border-color:#c0392b;<?php if (!$sytFicHasToken) echo 'opacity:0.4;cursor:not-allowed;'; ?>"
                                    <?php if (!$sytFicHasToken) echo 'disabled'; ?>>
                                    Scollega Fatture in Cloud
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirm('Sei sicuro di voler scollegare Synchroteam?\nLe credenziali Synchroteam verranno rimosse. Le associazioni clienti già create rimangono.');">
                                <?php wp_nonce_field('sibia_sytfic_synch_disconnect', 'sibia_sytfic_synch_disconnect_nonce'); ?>
                                <input type="hidden" name="sibia_sytfic_synch_disconnect" value="1" />
                                <button type="submit" class="sibia-btn" style="background:#7f8c8d;border-color:#7f8c8d;">
                                    Scollega Synchroteam
                                </button>
                            </form>
                        </div>
                        <?php else : ?>
                        <p style="color:var(--sibia-muted);margin-top:12px;"><em>Salva prima le credenziali Synchroteam per abilitare il collegamento con Fatture in Cloud.</em></p>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- STEP 3: Articolo ore lavoro (solo se FIC collegato) -->
                    <?php if ($sytFicHasToken) : ?>
                    <div class="sibia-step sibia-step--collapsible is-collapsed">
                        <div class="sibia-step__title">Articolo ore lavoro</div>
                        <div class="sibia-step__body">
                        <form class="sibia-form" method="post">
                            <?php wp_nonce_field('sibia_sytfic_submit', 'sibia_sytfic_nonce'); ?>
                            <input type="hidden" name="sytfic_domain" value="<?php echo esc_attr($sytFicDomain); ?>" />
                            <input type="hidden" name="sytfic_api_key" value="••••••••" />
                            <input type="hidden" name="sytfic_trigger" value="<?php echo esc_attr($sytFicTriggerJob); ?>" />
                            <label>
                                <span>Articolo ore lavoro <em style="font-weight:400;color:var(--sibia-muted);">(opzionale)</em></span>
                                <?php if (!empty($sytFicFicProducts)) : ?>
                                <select name="sytfic_product_id_ore">
                                    <option value="">— nessuno —</option>
                                    <?php foreach ($sytFicFicProducts as $prod) :
                                        $pid   = intval($prod['id'] ?? 0);
                                        $pname = esc_html($prod['name'] ?? '');
                                        $price = isset($prod['netPrice']) ? ' (' . number_format(floatval($prod['netPrice']), 2, ',', '.') . ' €)' : '';
                                        $sel   = ($sytFicProductIdOre === $pid) ? ' selected' : '';
                                    ?>
                                    <option value="<?php echo $pid; ?>"<?php echo $sel; ?>><?php echo $pname . $price; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color:var(--sibia-muted);">Seleziona l&rsquo;articolo del tuo catalogo FIC da usare per le ore lavoro nei rapporti di intervento</small>
                                <?php elseif ($sytFicFicProductsError) : ?>
                                <select name="sytfic_product_id_ore" disabled>
                                    <option value="">— errore caricamento articoli —</option>
                                </select>
                                <small style="color:#c0392b;"><?php echo esc_html($sytFicFicProductsError); ?></small>
                                <?php else : ?>
                                <select name="sytfic_product_id_ore">
                                    <option value="">— nessun articolo nel catalogo FIC —</option>
                                </select>
                                <?php endif; ?>
                            </label>
                            <label class="sibia-toggle">
                                <input type="checkbox" name="sytfic_sync_clienti" value="1"<?php echo $sytFicSyncClientiAbilitato ? ' checked' : ''; ?> class="sibia-toggle__input" id="sytfic_sync_clienti_chk" />
                                <span class="sibia-toggle__track"></span>
                                <span class="sibia-toggle__label">Sincronizza clienti FIC &rarr; Synchroteam</span>
                            </label>
                            <small style="color:var(--sibia-muted);margin-top:4px;display:block;">Se abilitato, i clienti di Fatture in Cloud vengono creati/aggiornati automaticamente in Synchroteam a ogni ciclo di sincronizzazione. Disabilita questa opzione se vuoi gestire manualmente i clienti in Synchroteam.</small>
                            <label class="sibia-toggle" style="margin-top:12px;">
                                <?php $_syncArticoliAbilitato = ($_sytBillingStato === 'demo' || ($sytFicBillingItem['piano'] ?? 'standard') === 'professional'); ?>
                                <input type="checkbox" name="sytfic_sync_articoli" value="1"<?php echo $sytFicSyncArticoliAbilitato ? ' checked' : ''; ?> class="sibia-toggle__input" id="sytfic_sync_articoli_chk"<?php echo $_syncArticoliAbilitato ? '' : ' disabled style="opacity:0.4;cursor:not-allowed;"'; ?> />
                                <span class="sibia-toggle__track"></span>
                                <span class="sibia-toggle__label">Sincronizza catalogo articoli FIC &rarr; Synchroteam (ogni 24h)
                                    <span style="display:inline-block;margin-left:8px;font-size:11px;background:#ede7f6;color:#4527a0;padding:2px 8px;border-radius:10px;vertical-align:middle;font-weight:600;">&#9889; Piano Professional</span>
                                </span>
                            </label>
                            <small style="color:var(--sibia-muted);margin-top:4px;display:block;">Se abilitato, i prodotti di Fatture in Cloud vengono copiati come part type in Synchroteam ogni 24 ore.<br><strong style="color:#4527a0;">Nota:</strong> questa opzione richiede il <strong>Piano Professional</strong>. Se disabilitata, il Piano Standard &egrave; sufficiente.</small>
                            <button type="submit" name="sibia_sytfic_submit" class="sibia-btn">Salva</button>
                        </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($sytFicConfigured) : ?>

                    <!-- STEP 6: Clienti Synchroteam da associare a FIC -->
                    <?php if (!empty($sytFicClientiPendenti)) : ?>
                    <div class="sibia-step">
                        <div class="sibia-step__title">Clienti da collegare a Fatture in Cloud
                            <span style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;background:#f59e0b;color:#fff;padding:2px 10px;border-radius:20px;margin-left:10px;vertical-align:middle;"><?php echo count($sytFicClientiPendenti); ?></span>
                        </div>
                        <p style="margin:0 0 16px;font-size:14px;color:var(--sibia-muted);line-height:1.6;">
                            I seguenti clienti Synchroteam hanno interventi recenti ma non sono ancora collegati a un cliente Fatture in Cloud.
                            Per ciascuno, <strong>Associa</strong> al cliente FIC corrispondente oppure <strong>Ignora</strong> per escluderlo dalla sincronizzazione.
                        </p>
                        <div style="display:grid;gap:14px;">
                        <?php foreach ($sytFicClientiPendenti as $pendente) :
                            $pendId    = intval($pendente['id']);
                            $pendNome  = esc_html($pendente['synchCustomerName'] ?? '');
                            $pendPiva  = esc_html($pendente['synchVatNumber'] ?? '');
                            $candidati = array();
                            if (!empty($pendente['candidatiFicJson'])) {
                                $candidati = json_decode($pendente['candidatiFicJson'], true) ?: array();
                            }
                            // Verifica se almeno un candidato corrisponde per P.IVA (corrispondenza certa)
                            $hasPivaMatch = false;
                            foreach ($candidati as $c) {
                                if (stripos($c['match'] ?? '', 'piva') !== false) { $hasPivaMatch = true; break; }
                            }
                        ?>
                        <?php
                            $pendMyId   = esc_html($pendente['synchMyId'] ?? '');
                            $pendStreet = esc_html($pendente['synchAddressStreet'] ?? '');
                            $pendCity   = esc_html($pendente['synchAddressCity']   ?? '');
                            $pendZip    = esc_html($pendente['synchAddressZip']    ?? '');
                            $pendAddr   = trim($pendStreet . ' ' . trim($pendZip . ' ' . $pendCity));
                        ?>
                        <div class="sibia-pending-card">
                            <div>
                                <div class="sibia-pending-card__name"><?php echo $pendNome ?: '(nome non disponibile)'; ?></div>
                                <div class="sibia-pending-card__meta" style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-top:4px;font-size:13px;">
                                    <span>Tipo: <strong>Cliente</strong></span>
                                    <?php if ($pendMyId) : ?>
                                    <span>Codice personalizzato: <strong><?php echo $pendMyId; ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ($pendPiva) : ?>
                                    <span>P.IVA: <?php echo $pendPiva; ?></span>
                                    <?php endif; ?>
                                    <?php if ($pendAddr) : ?>
                                    <span>Indirizzo: <?php echo $pendAddr; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="sibia-pending-card__body">
                                <form method="post">
                                    <?php wp_nonce_field('sibia_sytfic_risolvi_' . $pendId, 'sibia_sytfic_risolvi_nonce'); ?>
                                    <input type="hidden" name="sibia_sytfic_risolvi_submit" value="1" />
                                    <input type="hidden" name="sytfic_pendente_id" value="<?php echo $pendId; ?>" />
                                    <input type="hidden" name="sytfic_azione" value="associa" />
                                    <?php if (!$pendPiva && !$hasPivaMatch) : ?>
                                    <p style="margin:0 0 10px;font-size:13px;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:8px 12px;line-height:1.5;">
                                        &#9888; Questo cliente non ha P.IVA su Synchroteam. Se crei il cliente su Fatture in Cloud, verr&agrave; creato senza P.IVA.
                                    </p>
                                    <?php endif; ?>
                                    <?php if (!empty($candidati)) : ?>
                                    <!-- Candidati trovati → select per l'associazione -->
                                    <div class="sibia-pending-card__select">
                                        <label>Cliente FIC:</label>
                                        <select name="sytfic_fic_cliente_id" <?php echo $hasPivaMatch ? 'required' : ''; ?>>
                                            <option value="">— scegli cliente FIC —</option>
                                            <?php foreach ($candidati as $c) : ?>
                                            <option value="<?php echo intval($c['id']); ?>">
                                                <?php echo esc_html($c['name'] ?? ''); ?>
                                                <?php if (!empty($c['code'])) : ?> &mdash; Cod: <?php echo esc_html($c['code']); ?><?php endif; ?>
                                                <?php if (!empty($c['piva'])) : ?> &mdash; P.IVA: <?php echo esc_html($c['piva']); ?><?php endif; ?>
                                                <?php if (!empty($c['address'])) : ?> &mdash; <?php echo esc_html($c['address']); ?><?php endif; ?>
                                                &nbsp;[<?php echo esc_html($c['match'] ?? ''); ?>]
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php if (!$hasPivaMatch) : ?>
                                    <!-- Solo corrispondenza per nome: il cliente FIC potrebbe non essere quello giusto -->
                                    <p style="margin:0 0 10px;font-size:13px;color:var(--sibia-muted);line-height:1.5;">
                                        Nessuna corrispondenza certa per P.IVA. Puoi associare uno dei clienti trovati per nome oppure crearne uno nuovo su FIC.
                                    </p>
                                    <?php endif; ?>
                                    <div class="sibia-pending-card__actions">
                                        <button type="submit" class="sibia-btn sibia-btn--small">Associa</button>
                                        <?php if ($hasPivaMatch) : ?>
                                        <!-- P.IVA trovata: inutile creare un duplicato -->
                                        <button type="submit" class="sibia-btn sibia-btn--small" disabled style="opacity:0.45;cursor:not-allowed;" title="Corrispondenza P.IVA trovata: usa Associa">Crea su FIC</button>
                                        <?php else : ?>
                                        <!-- Solo nome: l'utente può preferire creare un nuovo cliente FIC -->
                                        <button type="submit" class="sibia-btn sibia-btn--small"
                                            onclick="this.closest('form').querySelector('[name=sytfic_azione]').value='crea_fic';this.closest('form').querySelector('[name=sytfic_fic_cliente_id]').removeAttribute('required');">
                                            Crea su FIC
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="sibia-btn sibia-btn--ghost sibia-btn--small"
                                            onclick="this.closest('form').querySelector('[name=sytfic_azione]').value='ignora';if(confirm('Confermi di voler escludere <?php echo esc_js($pendNome ?: 'questo cliente'); ?> dalla sincronizzazione?'))this.closest('form').submit();">
                                            Ignora
                                        </button>
                                    </div>
                                    <?php else : ?>
                                    <!-- Caso a: nessun candidato → Crea su FIC attivo, Associa disabilitato -->
                                    <p style="margin:0 0 10px;font-size:13px;color:var(--sibia-muted);line-height:1.5;">
                                        Nessun cliente trovato su Fatture in Cloud con P.IVA o nome simile.<br>
                                        Puoi creare il cliente direttamente su Fatture in Cloud oppure ignorarlo.
                                    </p>
                                    <div class="sibia-pending-card__actions">
                                        <button type="submit" class="sibia-btn sibia-btn--small"
                                            onclick="this.closest('form').querySelector('[name=sytfic_azione]').value='crea_fic';">
                                            Crea su FIC
                                        </button>
                                        <button type="submit" class="sibia-btn sibia-btn--small" disabled style="opacity:0.45;cursor:not-allowed;" title="Nessun candidato disponibile: usa Crea su FIC">Associa</button>
                                        <button type="button" class="sibia-btn sibia-btn--ghost sibia-btn--small"
                                            onclick="this.closest('form').querySelector('[name=sytfic_azione]').value='ignora';if(confirm('Confermi di voler escludere <?php echo esc_js($pendNome ?: 'questo cliente'); ?> dalla sincronizzazione?'))this.closest('form').submit();">
                                            Ignora
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Clienti FIC non sincronizzati su Synchroteam per dati insufficienti -->
                    <?php if (!empty($sytFicFicClientiErrore)) : ?>
                    <div class="sibia-step">
                        <div class="sibia-step__title">Clienti Fatture in Cloud non sincronizzati
                            <span style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;background:#ef4444;color:#fff;padding:2px 10px;border-radius:20px;margin-left:10px;vertical-align:middle;"><?php echo count($sytFicFicClientiErrore); ?></span>
                        </div>
                        <p style="margin:0 0 16px;font-size:14px;color:var(--sibia-muted);line-height:1.6;">
                            I seguenti clienti di <strong>Fatture in Cloud</strong> non sono stati creati su Synchroteam perché mancano di dati obbligatori (es. indirizzo).
                            Aprili su Fatture in Cloud, aggiungi i dati mancanti e alla prossima sincronizzazione verranno creati automaticamente.
                        </p>
                        <div style="display:grid;gap:10px;">
                        <?php foreach ($sytFicFicClientiErrore as $errore) :
                            $errNome   = esc_html($errore['ficClientName'] ?? '');
                            $errFicId  = esc_html((string)($errore['ficClientId'] ?? ''));
                            $errPiva   = esc_html($errore['ficVatNumber'] ?? '');
                            $errCode   = esc_html($errore['ficCode'] ?? '');
                            $errData   = '';
                            if (!empty($errore['dataUltimoTentativo'])) {
                                $dt = new DateTime($errore['dataUltimoTentativo'], new DateTimeZone('UTC'));
                                $dt->setTimezone(new DateTimeZone('Europe/Rome'));
                                $errData = $dt->format('d/m/Y H:i');
                            }
                        ?>
                        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;display:flex;align-items:center;gap:12px;">
                            <span style="font-size:18px;line-height:1;">&#9888;</span>
                            <div>
                                <div style="font-weight:600;font-size:14px;color:#111;"><?php echo $errNome ?: '(nome non disponibile)'; ?></div>
                                <div style="font-size:12px;color:var(--sibia-muted);margin-top:2px;display:flex;flex-wrap:wrap;gap:4px 14px;">
                                    <span>ID FIC: <?php echo $errFicId; ?></span>
                                    <?php if ($errCode) : ?><span>Codice: <strong><?php echo $errCode; ?></strong></span><?php endif; ?>
                                    <?php if ($errPiva) : ?><span>P.IVA: <?php echo $errPiva; ?></span><?php endif; ?>
                                    <?php if ($errData) : ?><span>Ultimo tentativo: <?php echo $errData; ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Siti FIC non sincronizzati su Synchroteam per dati insufficienti -->
                    <?php if (!empty($sytFicFicSitiErrore)) : ?>
                    <div class="sibia-step">
                        <div class="sibia-step__title">Siti Fatture in Cloud non sincronizzati
                            <span style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;background:#ef4444;color:#fff;padding:2px 10px;border-radius:20px;margin-left:10px;vertical-align:middle;"><?php echo count($sytFicFicSitiErrore); ?></span>
                        </div>
                        <p style="margin:0 0 16px;font-size:14px;color:var(--sibia-muted);line-height:1.6;">
                            I seguenti <strong>siti</strong> (anagrafiche Fatture in Cloud senza Tipologia usate come destinazioni diverse) non sono stati creati su Synchroteam perché mancano di dati obbligatori (es. indirizzo).
                            Aprili su Fatture in Cloud, aggiungi i dati mancanti e alla prossima sincronizzazione verranno creati automaticamente.
                        </p>
                        <div style="display:grid;gap:10px;">
                        <?php foreach ($sytFicFicSitiErrore as $siteErr) :
                            $siteNome  = esc_html($siteErr['ficClientName'] ?? '');
                            $siteFicId = esc_html((string)($siteErr['ficClientId'] ?? ''));
                            $sitePiva  = esc_html($siteErr['ficVatNumber'] ?? '');
                            $siteCode  = esc_html($siteErr['ficCode'] ?? '');
                            $siteData  = '';
                            if (!empty($siteErr['dataUltimoTentativo'])) {
                                $dt = new DateTime($siteErr['dataUltimoTentativo'], new DateTimeZone('UTC'));
                                $dt->setTimezone(new DateTimeZone('Europe/Rome'));
                                $siteData = $dt->format('d/m/Y H:i');
                            }
                        ?>
                        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;display:flex;align-items:center;gap:12px;">
                            <span style="font-size:18px;line-height:1;">&#9888;</span>
                            <div>
                                <div style="font-weight:600;font-size:14px;color:#111;"><?php echo $siteNome ?: '(nome non disponibile)'; ?></div>
                                <div style="font-size:12px;color:var(--sibia-muted);margin-top:2px;display:flex;flex-wrap:wrap;gap:4px 14px;">
                                    <span>ID FIC sito: <?php echo $siteFicId; ?></span>
                                    <?php if ($siteCode) : ?><span>Codice sito: <strong><?php echo $siteCode; ?></strong></span><?php endif; ?>
                                    <?php if ($sitePiva) : ?><span>P.IVA cliente: <?php echo $sitePiva; ?></span><?php endif; ?>
                                    <?php if ($siteData) : ?><span>Ultimo tentativo: <?php echo $siteData; ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($sytFicMyIdConflict)) : ?>
                    <div class="sibia-step">
                        <div class="sibia-step__title">Clienti con conflitto codice su Synchroteam
                            <span style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;background:#ef4444;color:#fff;padding:2px 10px;border-radius:20px;margin-left:10px;vertical-align:middle;"><?php echo count($sytFicMyIdConflict); ?></span>
                        </div>
                        <p style="margin:0 0 16px;font-size:14px;color:var(--sibia-muted);line-height:1.6;">
                            I seguenti clienti di Fatture in Cloud non possono essere aggiornati su Synchroteam
                            perch&eacute; il loro <strong>codice interno</strong> &egrave; gi&agrave; utilizzato da un altro cliente Synchroteam.
                            Per risolvere, apri Synchroteam e rinomina o rimuovi il codice personalizzato duplicato.
                        </p>
                        <div style="display:grid;gap:10px;">
                        <?php foreach ($sytFicMyIdConflict as $conflict) :
                            $confNome  = esc_html($conflict['ficClientName'] ?? '');
                            $confFicId = esc_html((string)($conflict['ficClientId'] ?? ''));
                            $confPiva  = esc_html($conflict['ficVatNumber'] ?? '');
                            $confCode  = esc_html($conflict['ficCode'] ?? '');
                            $confData  = '';
                            if (!empty($conflict['dataUltimoTentativo'])) {
                                $dt = new DateTime($conflict['dataUltimoTentativo'], new DateTimeZone('UTC'));
                                $dt->setTimezone(new DateTimeZone('Europe/Rome'));
                                $confData = $dt->format('d/m/Y H:i');
                            }
                        ?>
                        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;display:flex;align-items:center;gap:12px;">
                            <span style="font-size:18px;line-height:1;">&#9888;</span>
                            <div>
                                <div style="font-weight:600;font-size:14px;color:#111;"><?php echo $confNome ?: '(nome non disponibile)'; ?></div>
                                <div style="font-size:12px;color:var(--sibia-muted);margin-top:2px;display:flex;flex-wrap:wrap;gap:4px 14px;">
                                    <span>ID FIC: <?php echo $confFicId; ?></span>
                                    <?php if ($confCode) : ?><span>Codice in conflitto: <strong><?php echo $confCode; ?></strong></span><?php endif; ?>
                                    <?php if ($confPiva) : ?><span>P.IVA: <?php echo $confPiva; ?></span><?php endif; ?>
                                    <?php if ($confData) : ?><span>Ultimo tentativo: <?php echo $confData; ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- STEP 4: Stato sincronizzazione -->
                    <div class="sibia-step">
                        <div class="sibia-step__title">Stato sincronizzazione</div>
                        <div class="sibia-sync-status">
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Dominio Synchroteam</span>
                                <span class="sibia-sync-status__value"><?php echo esc_html($sytFicDomain ?: 'Non impostato'); ?></span>
                            </div>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Company ID Fatture in Cloud</span>
                                <span class="sibia-sync-status__value"><?php echo esc_html($sytFicFicCompanyId ?: 'Non impostato'); ?></span>
                            </div>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Importa interventi</span>
                                <span class="sibia-sync-status__value"><?php echo $sytFicTriggerJob === 'validated' ? 'Dopo validazione' : 'Al completamento'; ?></span>
                            </div>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Ultimo aggiornamento</span>
                                <span class="sibia-sync-status__value"><?php
                                    if (!empty($sytFicUltimoRun)) {
                                        $dt = new DateTime($sytFicUltimoRun);
                                        echo esc_html($dt->format('d/m/Y H:i'));
                                    } else {
                                        echo 'Mai eseguito';
                                    }
                                ?></span>
                            </div>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Clienti sincronizzati</span>
                                <span class="sibia-sync-status__value"><?php echo esc_html($sytFicClientiSync); ?> clienti</span>
                            </div>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">Interventi importati</span>
                                <span class="sibia-sync-status__value"><?php echo esc_html($sytFicInterventiSync); ?> interventi</span>
                            </div>
                            <?php if ($sytFicTestSynch !== null) : ?>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">API Synchroteam</span>
                                <span class="sibia-sync-status__value" style="color:<?php echo $sytFicTestSynch['ok'] ? '#1a7a4a' : '#c53030'; ?>;font-weight:600;"><?php echo $sytFicTestSynch['ok'] ? '&#10003; Connessa' : '&#10007; ' . esc_html($sytFicTestSynch['message']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($sytFicTestFic !== null) : ?>
                            <div class="sibia-sync-status__row">
                                <span class="sibia-sync-status__label">API Fatture in Cloud</span>
                                <span class="sibia-sync-status__value" style="color:<?php echo $sytFicTestFic['ok'] ? '#1a7a4a' : '#c53030'; ?>;font-weight:600;"><?php echo $sytFicTestFic['ok'] ? '&#10003; Connessa' : '&#10007; ' . esc_html($sytFicTestFic['message']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php endif; // $sytFicConfigured ?>

                </div>
                <?php endif; // isRegistered — soluzioni ?>

            </section>

            <!-- ======================== DOCUMENTAZIONE ======================== -->
            <section class="sibia-section<?php echo $activeSection === 'docs' ? ' is-active' : ''; ?>" data-section="docs">
                <?php if (!$isRegistered) : ?>
                <div class="sibia-panel sibia-panel--error" style="margin-bottom:0;">
                    Completa prima i <strong>Dati Registrazione</strong> per accedere a questa sezione.
                </div>
                <?php else : ?>
                <div class="sibia-hero-block">
                    <img src="https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png" alt="SIBIA" />
                    <h2>Documentazione</h2>
                    <p>Sezione in preparazione.</p>
                </div>
                <?php endif; ?>
            </section>

            <!-- ======================== FATTURAZIONE ======================== -->
            <section class="sibia-section<?php echo $activeSection === 'fatturazione' ? ' is-active' : ''; ?>" data-section="fatturazione">
                <?php if (!$isRegistered) : ?>
                <div class="sibia-panel sibia-panel--error" style="margin-bottom:0;">
                    Completa prima i <strong>Dati Registrazione</strong> per accedere a questa sezione.
                </div>
                <?php else :
                    $billingMsg       = isset($_GET['billing_msg'])       ? sanitize_text_field(wp_unslash($_GET['billing_msg']))       : '';
                    $billingSuccess   = !empty($_GET['billing_success']);
                    $billingCancelled = !empty($_GET['billing_cancelled']);
                    $billingMsgLabels = array(
                        'demo_ok'      => array('ok',  'Demo attivata! Hai 14 giorni e 50 sincronizzazioni gratuite.'),
                        'demo_err'     => array('err', 'Errore durante l\'attivazione della demo. Riprova più tardi.'),
                        'cancella_ok'              => array('ok',  'Abbonamento disdetto. Il servizio è stato disattivato.'),
                        'cancella_fine_periodo_ok' => array('ok',  'Abbonamento disdetto. Il servizio resterà attivo fino alla fine del periodo corrente.'),
                        'cancella_err'             => array('err', 'Errore durante la disdetta. Contatta il supporto a supporto@sibia.it.'),
                        'checkout_err' => array('err', 'Errore durante la creazione del pagamento. Riprova.'),
                        'portal_err'   => array('err', 'Errore durante l\'accesso al portale di fatturazione. Riprova.'),
                    );
                    $billingStatus = $allBillingStatus; // già fetchato all'inizializzazione
                    $nonce         = wp_create_nonce('sibia_billing');
                ?>
                <?php if ($billingSuccess) : ?>
                <div class="sibia-panel sibia-panel--success" style="margin-bottom:16px;">
                    &#10003; Abbonamento attivato con successo! Benvenuto in SIBIA.
                </div>
                <?php elseif ($billingCancelled) : ?>
                <div class="sibia-panel sibia-panel--error" style="margin-bottom:16px;">
                    Pagamento annullato. Puoi riprovare quando vuoi.
                </div>
                <?php elseif ($billingMsg !== '' && isset($billingMsgLabels[$billingMsg])) :
                    $ml = $billingMsgLabels[$billingMsg]; ?>
                <div class="sibia-panel sibia-panel--<?php echo $ml[0] === 'ok' ? 'success' : 'error'; ?>" style="margin-bottom:16px;">
                    <?php echo esc_html($ml[1]); ?>
                </div>
                <?php endif; ?>

                <?php
                $serviziInfo = array(
                    'PicToPip' => array(
                        'titolo'      => 'Picam7 &#8596; Pipedrive',
                        'descrizione' => 'Sincronizzazione automatica di clienti e attività tra Picam7 e Pipedrive.',
                    ),
                    'SynchToFic' => array(
                        'titolo'      => 'Synchroteam &#8596; Fatture in Cloud',
                        'descrizione' => 'Sincronizzazione automatica di clienti e rapporti di intervento tra Synchroteam e Fatture in Cloud.',
                    ),
                );
                /* Pre-calcola stati per la list view */
                $serviziRender = array();
                foreach ($serviziInfo as $_cod => $_inf) {
                    $_svc = null;
                    foreach ($billingStatus as $_s) {
                        if (strtolower($_s['servizio'] ?? '') === strtolower($_cod)) { $_svc = $_s; break; }
                    }
                    $_stato = $_svc['stato'] ?? 'inattivo';
                    $serviziRender[$_cod] = array(
                        'info'       => $_inf,
                        'stato'      => $_stato,
                        'statoLabel' => array('demo'=>'Demo attiva','attivo'=>'Attivo','scaduto'=>'Scaduto','inattivo'=>'Non attivo')[$_stato] ?? 'Non attivo',
                        'statoClass' => array('demo'=>'sibia-badge--warning','attivo'=>'sibia-badge--success','scaduto'=>'sibia-badge--error','inattivo'=>'sibia-badge--neutral')[$_stato] ?? 'sibia-badge--neutral',
                        'svcData'    => $_svc,
                    );
                }
                ?>

                <!-- ── LIST VIEW (uguale a Servizi) ── -->
                <div class="sibia-solution-list">
                    <div class="sibia-hero-block">
                        <h2>Fatturazione</h2>
                        <p>Gestisci i tuoi abbonamenti ai servizi SIBIA.</p>
                    </div>
                    <div class="sibia-card-grid">
                        <?php foreach ($serviziRender as $_cod => $_r) : ?>
                        <div class="sibia-card<?php echo $_cod === 'SynchToFic' ? '' : ''; ?>"
                            <?php if ($_cod === 'SynchToFic') : ?>
                            data-href="<?php echo esc_url(add_query_arg('section', 'abbonamento', get_permalink())); ?>"
                            <?php else : ?>
                            data-solution="billing-<?php echo strtolower($_cod); ?>"
                            <?php endif; ?>>
                            <span class="sibia-card__title"><?php echo $_r['info']['titolo']; ?></span>
                            <span class="sibia-card__meta"><?php echo esc_html($_r['info']['descrizione']); ?></span>
                            <span class="sibia-badge <?php echo $_r['statoClass']; ?>" style="margin-top:10px;align-self:flex-start;"><?php echo esc_html($_r['statoLabel']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ── DETAIL VIEW per ogni servizio ── -->
                <?php foreach ($serviziRender as $codice => $r) :
                    $info           = $r['info'];
                    $stato          = $r['stato'];
                    $statoLabel     = $r['statoLabel'];
                    $statoClass     = $r['statoClass'];
                    $svcData        = $r['svcData'];
                    $dataFineDemo   = $svcData['dataFineDemo']            ?? null;
                    $dataScadenza   = $svcData['dataScadenza']            ?? null;
                    $recUsati       = intval($svcData['recordSincronizzatiDemo'] ?? 0);
                    $recLimite      = intval($svcData['limiteRecordDemo'] ?? 100);
                    $intervallo     = $svcData['intervallo']              ?? '';
                    $pianoCorrente  = $svcData['piano']                   ?? 'standard';
                    $meprConfig     = sibia_get_mepr_config();
                    $meprPiani      = $meprConfig[$codice] ?? null;
                    $modalPrezzoMensile = '49'; $modalPrezzoAnnuale = '490';
                    $modalUrlMensile = ''; $modalUrlAnnuale = '';
                    if ($meprPiani) {
                        $modalUrlMensile = esc_url(get_permalink($meprPiani['mensile']));
                        $modalUrlAnnuale = esc_url(get_permalink($meprPiani['annuale']));
                        if (class_exists('MeprProduct')) {
                            $pm = new MeprProduct(intval($meprPiani['mensile']));
                            if ($pm->ID) $modalPrezzoMensile = number_format(floatval($pm->price), 0, ',', '.');
                            $pa = new MeprProduct(intval($meprPiani['annuale']));
                            if ($pa->ID) $modalPrezzoAnnuale = number_format(floatval($pa->price), 0, ',', '.');
                        }
                    }
                    $modalNome = html_entity_decode(strip_tags($info['titolo']));
                    $modalDesc = $info['descrizione'];
                    // SynchToFic: piani Standard e Professional separati
                    $sytFicPianiMepr = ($codice === 'SynchToFic') ? sibia_get_sytfic_piani_mepr() : null;
                    $sytFicArticoliON = ($codice === 'SynchToFic') ? $sytFicSyncArticoliAbilitato : false;
                ?>
                <div class="sibia-solution-detail" data-solution="billing-<?php echo strtolower($codice); ?>">
                    <button type="button" class="sibia-btn sibia-btn--ghost sibia-back-btn">&larr; Torna a Fatturazione</button>
                    <div class="sibia-billing-card">
                        <div class="sibia-billing-card__header">
                            <div class="sibia-billing-card__title"><?php echo $info['titolo']; ?></div>
                            <span class="sibia-badge <?php echo $statoClass; ?>"><?php echo esc_html($statoLabel); ?></span>
                        </div>
                        <div class="sibia-billing-card__body">
                            <p><?php echo esc_html($info['descrizione']); ?></p>
                            <?php if ($stato === 'demo') : ?>
                            <div class="sibia-billing-info">
                                <?php if ($dataFineDemo) : ?>
                                <div class="sibia-billing-info__row">
                                    <span class="sibia-billing-info__label">Scade il:</span>
                                    <span><?php echo esc_html(date_i18n('d/m/Y', strtotime($dataFineDemo))); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="sibia-billing-info__row">
                                    <span class="sibia-billing-info__label">Sincronizzazioni:</span>
                                    <span><?php echo $recUsati; ?> / <?php echo $recLimite; ?> usate</span>
                                </div>
                                <?php if ($codice === 'SynchToFic') : ?>
                                <div class="sibia-billing-info__row">
                                    <span class="sibia-billing-info__label">Piano demo:</span>
                                    <span><?php echo $pianoCorrente === 'professional' ? '&#9889; Professional' : 'Standard'; ?></span>
                                </div>
                                <div class="sibia-billing-info__row">
                                    <span class="sibia-billing-info__label">Articoli:</span>
                                    <span><?php echo $sytFicArticoliON ? '&#10003; abilitati' : '&#10007; disabilitati'; ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php elseif ($stato === 'attivo') : ?>
                            <div class="sibia-billing-info">
                                <?php if ($dataScadenza) : ?>
                                <div class="sibia-billing-info__row">
                                    <span class="sibia-billing-info__label">Rinnovo il:</span>
                                    <span><?php echo esc_html(date_i18n('d/m/Y', strtotime($dataScadenza))); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($intervallo) : ?>
                                <div class="sibia-billing-info__row">
                                    <span class="sibia-billing-info__label">Cadenza:</span>
                                    <span><?php echo esc_html(ucfirst($intervallo)); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="sibia-billing-info__row">
                                    <span class="sibia-billing-info__label">Piano:</span>
                                    <span><?php echo $pianoCorrente === 'professional' ? '&#9889; Professional' : 'Standard'; ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="sibia-billing-card__actions">
                            <?php if ($stato === 'inattivo' || $stato === 'scaduto') : ?>
                                <form method="post">
                                    <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr($nonce); ?>">
                                    <input type="hidden" name="sibia_billing_action"   value="demo">
                                    <input type="hidden" name="sibia_billing_servizio" value="<?php echo esc_attr($codice); ?>">
                                    <button type="submit" class="sibia-btn sibia-btn--outline">Attiva Demo Gratuita</button>
                                </form>
                            <?php endif; ?>
                            <?php
                            // Piano attualmente attivo da DB (null se non attivo)
                            $pCorrente = ($stato === 'attivo') ? $pianoCorrente : null;
                            $iCorrente = ($stato === 'attivo') ? $intervallo    : null;
                            // Piano schedulato per cambio a fine ciclo
                            $pianoSch  = ($codice === 'SynchToFic') ? sibia_get_piano_schedulato($user->ID, $codice) : null;
                            ?>
                                <?php if ($codice === 'SynchToFic' && $sytFicPianiMepr) : ?>
                                    <?php
                                    $stdPiani = $sytFicPianiMepr['standard'];
                                    $proPiani = $sytFicPianiMepr['professional'];
                                    $stdPrezzoM = '49'; $stdPrezzoA = '490';
                                    $proPrezzoM = '79'; $proPrezzoA = '790';
                                    if (class_exists('MeprProduct')) {
                                        if ($stdPiani['mensile']) { $pm = new MeprProduct($stdPiani['mensile']); if ($pm->ID) $stdPrezzoM = number_format(floatval($pm->price), 0, ',', '.'); }
                                        if ($stdPiani['annuale']) { $pa = new MeprProduct($stdPiani['annuale']); if ($pa->ID) $stdPrezzoA = number_format(floatval($pa->price), 0, ',', '.'); }
                                        if ($proPiani['mensile']) { $pm = new MeprProduct($proPiani['mensile']); if ($pm->ID) $proPrezzoM = number_format(floatval($pm->price), 0, ',', '.'); }
                                        if ($proPiani['annuale']) { $pa = new MeprProduct($proPiani['annuale']); if ($pa->ID) $proPrezzoA = number_format(floatval($pa->price), 0, ',', '.'); }
                                    }
                                    $pianoRaccomandato = $sytFicArticoliON ? 'professional' : 'standard';
                                    $isCurrStdM = ($pCorrente === 'standard'     && $iCorrente === 'mensile');
                                    $isCurrStdA = ($pCorrente === 'standard'     && $iCorrente === 'annuale');
                                    $isCurrProM = ($pCorrente === 'professional' && $iCorrente === 'mensile');
                                    $isCurrProA = ($pCorrente === 'professional' && $iCorrente === 'annuale');
                                    ?>
                                    <!-- Banner piano raccomandato -->
                                    <div style="width:100%;padding:8px 12px;border-radius:6px;margin-bottom:4px;font-size:13px;<?php echo $pianoRaccomandato === 'professional' ? 'background:#ede7f6;color:#4527a0;border:1px solid #b39ddb;' : 'background:#e3f2fd;color:#0d47a1;border:1px solid #90caf9;'; ?>">
                                        <?php if ($pianoRaccomandato === 'professional') : ?>
                                            &#9889; In base alla tua configurazione (articoli attivi) ti suggeriamo il <strong>Piano Professional</strong>.
                                        <?php else : ?>
                                            &#10003; In base alla tua configurazione ti suggeriamo il <strong>Piano Standard</strong>.
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($pianoSch) : ?>
                                    <div style="width:100%;padding:8px 12px;border-radius:6px;margin-bottom:4px;font-size:13px;background:#fff8e1;color:#7a5c00;border:1px solid #ffe082;">
                                        &#8987; Cambio a piano <strong><?php echo esc_html(ucfirst($pianoSch['piano']) . ' / ' . ucfirst($pianoSch['intervallo'])); ?></strong> schedulato alla scadenza del piano corrente.
                                    </div>
                                    <?php endif; ?>
                                    <!-- Piani Standard -->
                                    <div style="width:100%;padding:10px;border:1px solid #e0e0e0;border-radius:8px;margin-bottom:8px;">
                                        <div style="font-weight:600;margin-bottom:8px;color:#333;">Standard <span style="font-size:12px;font-weight:400;color:#777;">— senza sincronizzazione articoli</span></div>
                                        <?php if ($isCurrStdM) : ?>
                                        <button type="button" class="sibia-btn sibia-btn--primary" disabled>Standard &mdash; Mensile &euro;<?php echo esc_html($stdPrezzoM); ?>/mese +IVA <span class="sibia-badge sibia-badge--success" style="margin-left:6px;font-size:11px;">Piano attuale</span></button>
                                        <?php else : ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="sibia_billing_nonce"            value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="sibia_billing_action"           value="cambio_piano">
                                            <input type="hidden" name="sibia_billing_servizio"         value="<?php echo esc_attr($codice); ?>">
                                            <input type="hidden" name="sibia_billing_nuovo_piano"      value="standard">
                                            <input type="hidden" name="sibia_billing_nuovo_intervallo" value="mensile">
                                            <?php if ($pCorrente === 'professional') : ?>
                                            <button type="button" class="sibia-btn sibia-btn--primary"
                                                onclick="if(confirm('Passando al Piano Standard perderai al termine del periodo corrente:\n- Sincronizzazione articoli\n- Intervallo di sincronizzazione ridotto\n\nContinuare?')) { this.form.submit(); }">
                                                Standard &mdash; Mensile &euro;<?php echo esc_html($stdPrezzoM); ?>/mese +IVA
                                            </button>
                                            <?php else : ?>
                                            <button type="submit" class="sibia-btn sibia-btn--primary">Standard &mdash; Mensile &euro;<?php echo esc_html($stdPrezzoM); ?>/mese +IVA</button>
                                            <?php endif; ?>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($isCurrStdA) : ?>
                                        <button type="button" class="sibia-btn sibia-btn--primary" disabled>Standard &mdash; Annuale &euro;<?php echo esc_html($stdPrezzoA); ?>/anno +IVA <span class="sibia-badge sibia-badge--promo">Risparmia</span> <span class="sibia-badge sibia-badge--success" style="margin-left:6px;font-size:11px;">Piano attuale</span></button>
                                        <?php else : ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="sibia_billing_nonce"            value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="sibia_billing_action"           value="cambio_piano">
                                            <input type="hidden" name="sibia_billing_servizio"         value="<?php echo esc_attr($codice); ?>">
                                            <input type="hidden" name="sibia_billing_nuovo_piano"      value="standard">
                                            <input type="hidden" name="sibia_billing_nuovo_intervallo" value="annuale">
                                            <?php if ($pCorrente === 'professional') : ?>
                                            <button type="button" class="sibia-btn sibia-btn--primary"
                                                onclick="if(confirm('Passando al Piano Standard perderai al termine del periodo corrente:\n- Sincronizzazione articoli\n- Intervallo di sincronizzazione ridotto\n\nContinuare?')) { this.form.submit(); }">
                                                Standard &mdash; Annuale &euro;<?php echo esc_html($stdPrezzoA); ?>/anno +IVA <span class="sibia-badge sibia-badge--promo">Risparmia</span>
                                            </button>
                                            <?php else : ?>
                                            <button type="submit" class="sibia-btn sibia-btn--primary">Standard &mdash; Annuale &euro;<?php echo esc_html($stdPrezzoA); ?>/anno +IVA <span class="sibia-badge sibia-badge--promo">Risparmia</span></button>
                                            <?php endif; ?>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Piani Professional -->
                                    <div style="width:100%;padding:10px;border:1px solid #b39ddb;border-radius:8px;background:#faf5ff;">
                                        <div style="font-weight:600;margin-bottom:8px;color:#4527a0;">&#9889; Professional <span style="font-size:12px;font-weight:400;color:#7c3aed;">— include sincronizzazione articoli</span></div>
                                        <?php if ($isCurrProM) : ?>
                                        <button type="button" class="sibia-btn sibia-btn--primary" disabled>Professional &mdash; Mensile &euro;<?php echo esc_html($proPrezzoM); ?>/mese +IVA <span class="sibia-badge sibia-badge--success" style="margin-left:6px;font-size:11px;">Piano attuale</span></button>
                                        <?php else : ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="sibia_billing_nonce"            value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="sibia_billing_action"           value="cambio_piano">
                                            <input type="hidden" name="sibia_billing_servizio"         value="<?php echo esc_attr($codice); ?>">
                                            <input type="hidden" name="sibia_billing_nuovo_piano"      value="professional">
                                            <input type="hidden" name="sibia_billing_nuovo_intervallo" value="mensile">
                                            <button type="submit" class="sibia-btn sibia-btn--primary">Professional &mdash; Mensile &euro;<?php echo esc_html($proPrezzoM); ?>/mese +IVA</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($isCurrProA) : ?>
                                        <button type="button" class="sibia-btn sibia-btn--primary" disabled>Professional &mdash; Annuale &euro;<?php echo esc_html($proPrezzoA); ?>/anno +IVA <span class="sibia-badge sibia-badge--promo">Risparmia</span> <span class="sibia-badge sibia-badge--success" style="margin-left:6px;font-size:11px;">Piano attuale</span></button>
                                        <?php else : ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="sibia_billing_nonce"            value="<?php echo esc_attr($nonce); ?>">
                                            <input type="hidden" name="sibia_billing_action"           value="cambio_piano">
                                            <input type="hidden" name="sibia_billing_servizio"         value="<?php echo esc_attr($codice); ?>">
                                            <input type="hidden" name="sibia_billing_nuovo_piano"      value="professional">
                                            <input type="hidden" name="sibia_billing_nuovo_intervallo" value="annuale">
                                            <button type="submit" class="sibia-btn sibia-btn--primary">Professional &mdash; Annuale &euro;<?php echo esc_html($proPrezzoA); ?>/anno +IVA <span class="sibia-badge sibia-badge--promo">Risparmia</span></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                <?php else : // Altri servizi: layout 2-pulsanti classico
                                    $disableMensile = ($stato === 'attivo');
                                    $disableAnnuale = ($stato === 'attivo');
                                ?>
                                    <?php if ($meprPiani) : ?>
                                    <button type="button" class="sibia-btn sibia-btn--primary js-sibia-modal"<?php if ($disableMensile) echo ' disabled'; ?>
                                        data-nome="<?php echo esc_attr($modalNome); ?>"
                                        data-piano="Mensile"
                                        data-prezzo="€<?php echo esc_attr($modalPrezzoMensile); ?>"
                                        data-periodo="/ mese"
                                        data-desc="<?php echo esc_attr($modalDesc); ?>"
                                        data-mepr-url="<?php echo $modalUrlMensile; ?>">
                                        Abbonati &mdash; Mensile
                                    </button>
                                    <button type="button" class="sibia-btn sibia-btn--primary js-sibia-modal"<?php if ($disableAnnuale) echo ' disabled'; ?>
                                        data-nome="<?php echo esc_attr($modalNome); ?>"
                                        data-piano="Annuale"
                                        data-prezzo="€<?php echo esc_attr($modalPrezzoAnnuale); ?>"
                                        data-periodo="/ anno"
                                        data-desc="<?php echo esc_attr($modalDesc); ?>"
                                        data-mepr-url="<?php echo $modalUrlAnnuale; ?>">
                                        Abbonati &mdash; Annuale <span class="sibia-badge sibia-badge--promo">Risparmia</span>
                                    </button>
                                    <?php else : ?>
                                    <p style="color:var(--sibia-muted);font-size:13px;">I prezzi di abbonamento saranno disponibili a breve.</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php if ($stato === 'attivo') : ?>
                                <form method="post" id="form-cancella-<?php echo esc_attr($codice); ?>" style="display:none;">
                                    <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr($nonce); ?>">
                                    <input type="hidden" name="sibia_billing_action"   value="cancella">
                                    <input type="hidden" name="sibia_billing_servizio" value="<?php echo esc_attr($codice); ?>">
                                </form>
                                <form method="post" id="form-cancella-fp-<?php echo esc_attr($codice); ?>" style="display:none;">
                                    <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr($nonce); ?>">
                                    <input type="hidden" name="sibia_billing_action"   value="cancella_fine_periodo">
                                    <input type="hidden" name="sibia_billing_servizio" value="<?php echo esc_attr($codice); ?>">
                                </form>
                                <div id="disdici-wrap-<?php echo esc_attr($codice); ?>">
                                    <button type="button" class="sibia-btn sibia-btn--outline"
                                        style="border-color:var(--sibia-error);color:var(--sibia-error);"
                                        onclick="document.getElementById('disdici-wrap-<?php echo esc_attr($codice); ?>').querySelector('.sibia-disdici-choice').style.display='block';this.style.display='none';">
                                        Disdici abbonamento
                                    </button>
                                    <div class="sibia-disdici-choice" style="display:none;border:1px solid var(--sibia-error);border-radius:8px;padding:14px;background:#fff8f8;">
                                        <p style="margin:0 0 10px;font-size:14px;font-weight:600;color:var(--sibia-error);">Come vuoi disdire?</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                            <button type="button" class="sibia-btn" style="background:var(--sibia-error);border-color:var(--sibia-error);font-size:13px;"
                                                onclick="if(confirm('Il servizio si ferma oggi. Nessun rimborso. Confermi?')) { document.getElementById('form-cancella-<?php echo esc_attr($codice); ?>').submit(); }">
                                                Immediatamente
                                            </button>
                                            <button type="button" class="sibia-btn sibia-btn--outline" style="border-color:var(--sibia-error);color:var(--sibia-error);font-size:13px;"
                                                onclick="if(confirm('Il servizio resterà attivo fino alla fine del periodo pagato, poi si disattiverà. Nessun rimborso. Confermi?')) { document.getElementById('form-cancella-fp-<?php echo esc_attr($codice); ?>').submit(); }">
                                                A fine periodo
                                            </button>
                                            <button type="button" class="sibia-btn sibia-btn--ghost" style="font-size:13px;"
                                                onclick="this.closest('.sibia-disdici-choice').style.display='none';document.querySelector('#disdici-wrap-<?php echo esc_attr($codice); ?> .sibia-btn.sibia-btn--outline').style.display='';">
                                                Annulla
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; // isRegistered ?>
            </section>

            <!-- ── SEZIONE: ABBONAMENTO ── -->
            <section class="sibia-section<?php echo $activeSection === 'abbonamento' ? ' is-active' : ''; ?>" data-section="abbonamento">
            <?php if ($isRegistered) :
                /* Dati piano SynchToFic */
                $_abbStato        = $sytFicBillingItem['stato']     ?? 'inattivo';
                $_abbPianoAttuale  = $sytFicBillingItem['piano']     ?? 'standard';
                /* Leggi prezzi da MemberPress */
                $_abbPianiMepr = sibia_get_sytfic_piani_mepr();
                $_abbPrezzo = array(
                    'standard'     => array('mensile' => '49',  'annuale' => '490'),
                    'professional' => array('mensile' => '79',  'annuale' => '790'),
                );
                $_abbUrl = array(
                    'standard'     => array('mensile' => '', 'annuale' => ''),
                    'professional' => array('mensile' => '', 'annuale' => ''),
                );
                if (class_exists('MeprProduct')) {
                    foreach ($_abbPianiMepr as $_abbPianoKey => $_abbIds) {
                        foreach (array('mensile', 'annuale') as $_abbInt) {
                            $__p = new MeprProduct(intval($_abbIds[$_abbInt]));
                            if ($__p->ID) {
                                $_abbPrezzo[$_abbPianoKey][$_abbInt] = number_format(floatval($__p->price), 0, ',', '.');
                                $_abbUrl[$_abbPianoKey][$_abbInt]    = esc_url(get_permalink($__p->ID));
                            }
                        }
                    }
                }
                $statoLabel    = array('demo'=>'Demo attiva','attivo'=>'Attivo','scaduto'=>'Scaduto','inattivo'=>'Non attivo')[$_abbStato] ?? 'Non attivo';
                $statoClass    = array('demo'=>'--demo','attivo'=>'--attivo','scaduto'=>'--scaduto','inattivo'=>'')[$_abbStato] ?? '';
                $_abbIntervallo = $sytFicBillingItem['intervallo'] ?? 'mensile';
                $_isAttivo      = ($_abbStato === 'attivo');
            ?>
                <div class="sibia-abbonamento">
                    <a href="<?php echo esc_url(add_query_arg('section', 'fatturazione', get_permalink())); ?>" class="sibia-abbonamento__back">&larr; Torna alla configurazione</a>

                    <div class="sibia-abbonamento__header">
                        <h2>Synchroteam &#8596; Fatture in Cloud</h2>
                        <?php if ($statoClass) : ?>
                        <span class="sibia-abbonamento__status sibia-abbonamento__status<?php echo $statoClass; ?>"><?php echo esc_html($statoLabel); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($_isAttivo) : ?>
                    <!-- Banner abbonamento attivo -->
                    <div style="background:linear-gradient(135deg,#e8f5e9,#f1f8e9);border:1px solid #81c784;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <div>
                            <div style="font-weight:700;color:#1b5e20;font-size:15px;">&#10003; Abbonamento attivo</div>
                            <div style="color:#388e3c;font-size:13px;margin-top:4px;">
                                Piano <strong><?php echo $_abbPianoAttuale === 'professional' ? '&#9889; Professional' : 'Standard'; ?></strong>
                                &nbsp;&bull;&nbsp; <?php echo $_abbIntervallo === 'annuale' ? 'Annuale' : 'Mensile'; ?>
                            </div>
                        </div>
                        <form method="post" id="form-cancella-sytfic-imm" style="display:none;">
                            <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr(wp_create_nonce('sibia_billing')); ?>">
                            <input type="hidden" name="sibia_billing_action"   value="cancella">
                            <input type="hidden" name="sibia_billing_servizio" value="SynchToFic">
                        </form>
                        <form method="post" id="form-cancella-sytfic-fp" style="display:none;">
                            <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr(wp_create_nonce('sibia_billing')); ?>">
                            <input type="hidden" name="sibia_billing_action"   value="cancella_fine_periodo">
                            <input type="hidden" name="sibia_billing_servizio" value="SynchToFic">
                        </form>
                        <div id="disdici-wrap-sytfic">
                            <button type="button" class="sibia-btn sibia-btn--ghost" style="font-size:13px;white-space:nowrap;border-color:var(--sibia-error);color:var(--sibia-error);"
                                onclick="document.getElementById('disdici-wrap-sytfic').querySelector('.sibia-disdici-choice').style.display='block';this.style.display='none';">
                                Disdici abbonamento
                            </button>
                            <div class="sibia-disdici-choice" style="display:none;border:1px solid var(--sibia-error);border-radius:8px;padding:14px;background:#fff8f8;margin-top:8px;">
                                <p style="margin:0 0 10px;font-size:14px;font-weight:600;color:var(--sibia-error);">Come vuoi disdire?</p>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="sibia-btn" style="background:var(--sibia-error);border-color:var(--sibia-error);font-size:13px;"
                                        onclick="if(confirm('Il servizio si ferma oggi. Nessun rimborso. Confermi?')) { document.getElementById('form-cancella-sytfic-imm').submit(); }">
                                        Immediatamente
                                    </button>
                                    <button type="button" class="sibia-btn sibia-btn--outline" style="border-color:var(--sibia-error);color:var(--sibia-error);font-size:13px;"
                                        onclick="if(confirm('Il servizio resterà attivo fino alla fine del periodo pagato, poi si disattiverà. Nessun rimborso. Confermi?')) { document.getElementById('form-cancella-sytfic-fp').submit(); }">
                                        A fine periodo
                                    </button>
                                    <button type="button" class="sibia-btn sibia-btn--ghost" style="font-size:13px;"
                                        onclick="this.closest('.sibia-disdici-choice').style.display='none';document.querySelector('#disdici-wrap-sytfic > .sibia-btn').style.display='';">
                                        Annulla
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="sibia-abbonamento__choose">
                        <h3><?php echo $_isAttivo ? 'Cambia piano' : 'Scegli il piano'; ?></h3>
                        <div class="sibia-abb-toggle" id="sibia-abb-toggle">
                            <button type="button" class="sibia-abb-toggle__btn is-active" data-interval="mensile">Mensile</button>
                            <button type="button" class="sibia-abb-toggle__btn" data-interval="annuale">Annuale</button>
                        </div>
                    </div>

                    <div class="sibia-abb-cards">
                        <!-- Card Standard -->
                        <?php $_stdAttivo = ($_isAttivo && $_abbPianoAttuale === 'standard'); ?>
                        <div class="sibia-abb-card<?php echo $_stdAttivo ? ' sibia-abb-card--current' : ''; ?>"
                            data-price-mensile="<?php echo esc_attr($_abbPrezzo['standard']['mensile']); ?>"
                            data-price-annuale="<?php echo esc_attr($_abbPrezzo['standard']['annuale']); ?>"
                            data-url-mensile="<?php echo esc_attr($_stdAttivo ? '' : $_abbUrl['standard']['mensile']); ?>"
                            data-url-annuale="<?php echo esc_attr($_stdAttivo ? '' : $_abbUrl['standard']['annuale']); ?>">
                            <div class="sibia-abb-card__name">Standard</div>
                            <div class="sibia-abb-card__price">
                                <span class="sibia-abb-card__amount" data-role="amount"><?php echo esc_html($_abbPrezzo['standard']['mensile']); ?></span>
                                <span class="sibia-abb-card__currency">€</span>
                                <span class="sibia-abb-card__period" data-role="period">/mese</span><span style="font-size:11px;color:var(--sibia-muted);"> +IVA</span>
                            </div>
                            <ul class="sibia-abb-card__features">
                                <li>&#10003; Sincronizzazione clienti</li>
                                <li>&#10003; Sincronizzazione rapporti</li>
                                <li>&#10003; Supporto via email</li>
                            </ul>
                            <?php if ($_stdAttivo) : ?>
                            <span class="sibia-btn sibia-btn--outline sibia-abb-card__cta" style="pointer-events:none;background:#e8f5e9;border-color:#81c784;color:#2e7d32;text-align:center;">&#10003; Piano attuale</span>
                            <?php else : ?>
                            <a href="<?php echo esc_attr($_abbUrl['standard']['mensile']); ?>" class="sibia-btn sibia-btn--outline sibia-abb-card__cta" data-role="cta"
                                <?php echo empty($_abbUrl['standard']['mensile']) ? 'style="pointer-events:none;opacity:.5;"' : ''; ?>>
                                <?php echo $_isAttivo ? 'Passa a Standard &rarr;' : 'Continua al pagamento sicuro &rarr;'; ?>
                            </a>
                            <?php endif; ?>
                        </div>

                        <!-- Card Professional -->
                        <?php $_proAttivo = ($_isAttivo && $_abbPianoAttuale === 'professional'); ?>
                        <div class="sibia-abb-card sibia-abb-card--featured<?php echo $_proAttivo ? ' sibia-abb-card--current' : ''; ?>"
                            data-price-mensile="<?php echo esc_attr($_abbPrezzo['professional']['mensile']); ?>"
                            data-price-annuale="<?php echo esc_attr($_abbPrezzo['professional']['annuale']); ?>"
                            data-url-mensile="<?php echo esc_attr($_proAttivo ? '' : $_abbUrl['professional']['mensile']); ?>"
                            data-url-annuale="<?php echo esc_attr($_proAttivo ? '' : $_abbUrl['professional']['annuale']); ?>">
                            <div class="sibia-abb-card__badge"><?php echo $_proAttivo ? '&#10003; Attivo' : 'Consigliato'; ?></div>
                            <div class="sibia-abb-card__name">Professional</div>
                            <div class="sibia-abb-card__price">
                                <span class="sibia-abb-card__amount" data-role="amount"><?php echo esc_html($_abbPrezzo['professional']['mensile']); ?></span>
                                <span class="sibia-abb-card__currency">€</span>
                                <span class="sibia-abb-card__period" data-role="period">/mese</span><span style="font-size:11px;color:var(--sibia-muted);"> +IVA</span>
                            </div>
                            <ul class="sibia-abb-card__features">
                                <li>&#10003; Tutto di Standard</li>
                                <li>&#9889; Sincronizzazione articoli</li>
                                <li>&#9889; Priorità nel supporto</li>
                            </ul>
                            <?php if ($_proAttivo) : ?>
                            <span class="sibia-btn sibia-btn--primary sibia-abb-card__cta" style="pointer-events:none;opacity:.85;text-align:center;">&#10003; Piano attuale</span>
                            <?php else : ?>
                            <a href="<?php echo esc_attr($_abbUrl['professional']['mensile']); ?>" class="sibia-btn sibia-btn--primary sibia-abb-card__cta" data-role="cta"
                                <?php echo empty($_abbUrl['professional']['mensile']) ? 'style="pointer-events:none;opacity:.5;"' : ''; ?>>
                                <?php echo $_isAttivo ? 'Passa a Professional &rarr;' : 'Continua al pagamento sicuro &rarr;'; ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$_isAttivo) : ?>
                    <p class="sibia-abbonamento__support" style="text-align:center;margin-top:16px;">
                        &#10003; Disdici quando vuoi &nbsp;&bull;&nbsp; &#128274; Pagamento sicuro via Stripe &nbsp;&bull;&nbsp; Nessun vincolo
                    </p>
                    <?php endif; ?>
                    <p class="sibia-abbonamento__support" style="text-align:center;margin-top:6px;">
                        Hai domande? <a href="mailto:supporto@sibia.it">supporto@sibia.it</a>
                    </p>
                </div>
            <?php endif; ?>
            </section>

        </main>
    </section>

    <!-- ======================== MODAL ABBONAMENTO ======================== -->
    <div id="sibia-modal-overlay" class="sibia-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="sibia-modal-title">
        <div class="sibia-modal">
            <button class="sibia-modal__close" type="button" aria-label="Chiudi">&times;</button>
            <div id="sibia-modal-service" class="sibia-modal__service"></div>
            <div id="sibia-modal-title" class="sibia-modal__title"></div>
            <div id="sibia-modal-piano" class="sibia-modal__piano"></div>
            <div id="sibia-modal-prezzo" class="sibia-modal__price"></div>
            <div id="sibia-modal-periodo" class="sibia-modal__period"></div>
            <div id="sibia-modal-desc" class="sibia-modal__desc"></div>
            <div class="sibia-modal__fields">
                <div class="sibia-modal__field-row">
                    <div class="sibia-modal__field">
                        <label for="sibia-modal-fn">Nome</label>
                        <input type="text" id="sibia-modal-fn" placeholder="Nome">
                    </div>
                    <div class="sibia-modal__field">
                        <label for="sibia-modal-ln">Cognome</label>
                        <input type="text" id="sibia-modal-ln" placeholder="Cognome">
                    </div>
                </div>
                <label class="sibia-modal__privacy">
                    <input type="checkbox" id="sibia-modal-pp" checked>
                    <span>Questo sito raccoglie nomi, email e altre informazioni. Acconsento ai termini della Privacy Policy.</span>
                </label>
            </div>
            <div class="sibia-modal__actions">
                <button type="button" id="sibia-modal-cta" class="sibia-btn sibia-btn--primary">Procedi a Stripe &rarr;</button>
                <button type="button" class="sibia-btn sibia-btn--ghost sibia-modal__cancel">Annulla</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        /* --- Navigazione sezioni --- */
        var navItems = document.querySelectorAll('.sibia-portal .sibia-nav-item');
        var sections = document.querySelectorAll('.sibia-portal .sibia-section');

        navItems.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-section');
                navItems.forEach(function (b) {
                    b.classList.toggle('is-active', b.getAttribute('data-section') === target);
                });
                sections.forEach(function (s) {
                    s.classList.toggle('is-active', s.getAttribute('data-section') === target);
                });
                /* Ogni volta che si entra in una sezione, mostra sempre la lista */
                var targetSection = document.querySelector('.sibia-section[data-section="' + target + '"]');
                if (targetSection) {
                    var list = targetSection.querySelector('.sibia-solution-list');
                    if (list) list.style.display = '';
                    targetSection.querySelectorAll('.sibia-solution-detail').forEach(function (d) {
                        d.style.display = 'none';
                    });
                }
            });
        });

        /* --- Drill-down soluzioni/prodotti --- */
        /* Ogni section gestisce la propria lista e i propri dettagli */
        document.querySelectorAll('.sibia-section').forEach(function (section) {
            var list    = section.querySelector('.sibia-solution-list');
            var details = section.querySelectorAll('.sibia-solution-detail');
            var cards   = section.querySelectorAll('.sibia-card[data-solution]:not(.is-coming-soon)');
            var backs   = section.querySelectorAll('.sibia-back-btn');

            function resetView() {
                if (list) list.style.display = '';
                details.forEach(function (d) { d.style.display = 'none'; });
            }

            cards.forEach(function (card) {
                card.addEventListener('click', function () {
                    var sol = card.getAttribute('data-solution');
                    if (list) list.style.display = 'none';
                    details.forEach(function (d) {
                        d.style.display = d.getAttribute('data-solution') === sol ? '' : 'none';
                    });
                });
            });

            backs.forEach(function (btn) {
                btn.addEventListener('click', resetView);
            });
        });

        /* Se la pagina torna dopo un submit o OAuth, mostra il dettaglio corretto */
        <?php if ($activeSection === 'soluzioni' && !empty($activeServiceDetail)) : ?>
        (function () {
            var sec  = document.querySelector('.sibia-section[data-section="soluzioni"]');
            if (!sec) return;
            var list = sec.querySelector('.sibia-solution-list');
            if (list) list.style.display = 'none';
            sec.querySelectorAll('.sibia-solution-detail').forEach(function (d) {
                d.style.display = d.getAttribute('data-solution') === '<?php echo esc_js($activeServiceDetail); ?>' ? '' : 'none';
            });
        })();
        <?php endif; ?>
        <?php if ($activeSection === 'prodotti' && !empty($notice)) : ?>
        (function () {
            var sec  = document.querySelector('.sibia-section[data-section="prodotti"]');
            if (!sec) return;
            var list = sec.querySelector('.sibia-solution-list');
            if (list) list.style.display = 'none';
            sec.querySelectorAll('.sibia-solution-detail').forEach(function (d) {
                d.style.display = d.getAttribute('data-solution') === 'synchroteam' ? '' : 'none';
            });
        })();
        <?php endif; ?>

        /* --- Hide footer on portal page (fallback JS) --- */
        var ft = document.getElementById('Footer');
        if (ft) ft.style.display = 'none';

        /* --- Modal abbonamento --- */
        var modalOverlay = document.getElementById('sibia-modal-overlay');
        if (modalOverlay) {
            /* Dati utente WP per pre-compilare nome/cognome */
            var sibiaCuFn = <?php echo json_encode(wp_get_current_user()->first_name ?: ''); ?>;
            var sibiaCuLn = <?php echo json_encode(wp_get_current_user()->last_name  ?: ''); ?>;
            var sibiaMeprUrl = '';

            /* Apri modal */
            document.querySelectorAll('.js-sibia-modal').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.getElementById('sibia-modal-service').textContent = btn.dataset.nome || '';
                    document.getElementById('sibia-modal-title').textContent   = 'Piano ' + (btn.dataset.piano || '');
                    document.getElementById('sibia-modal-piano').textContent   = btn.dataset.piano || '';
                    document.getElementById('sibia-modal-prezzo').textContent  = btn.dataset.prezzo || '';
                    document.getElementById('sibia-modal-periodo').textContent = btn.dataset.periodo || '';
                    document.getElementById('sibia-modal-desc').textContent    = btn.dataset.desc || '';
                    sibiaMeprUrl = btn.dataset.meprUrl || '';
                    /* Pre-compila nome/cognome dall'account WP */
                    var fnEl = document.getElementById('sibia-modal-fn');
                    var lnEl = document.getElementById('sibia-modal-ln');
                    if (fnEl && !fnEl.value) fnEl.value = sibiaCuFn;
                    if (lnEl && !lnEl.value) lnEl.value = sibiaCuLn;
                    /* Reset privacy highlight */
                    var ppLabel = document.querySelector('.sibia-modal__privacy');
                    if (ppLabel) ppLabel.style.background = '';
                    modalOverlay.classList.add('is-open');
                    document.body.style.overflow = 'hidden';
                });
            });

            /* Pulsante Procedi a Stripe */
            var ctaBtn = document.getElementById('sibia-modal-cta');
            if (ctaBtn) {
                ctaBtn.addEventListener('click', function () {
                    var fnEl = document.getElementById('sibia-modal-fn');
                    var lnEl = document.getElementById('sibia-modal-ln');
                    var ppEl = document.getElementById('sibia-modal-pp');
                    /* Privacy obbligatoria */
                    if (ppEl && !ppEl.checked) {
                        var ppLabel = document.querySelector('.sibia-modal__privacy');
                        if (ppLabel) ppLabel.style.background = '#fff0f0';
                        ppEl.focus();
                        return;
                    }
                    /* Salva in sessionStorage per ob_start → auto-submit silenzioso */
                    try {
                        sessionStorage.setItem('sibia_fn', fnEl ? fnEl.value.trim() : '');
                        sessionStorage.setItem('sibia_ln', lnEl ? lnEl.value.trim() : '');
                        sessionStorage.setItem('sibia_pp', '1');
                    } catch(e) {}
                    ctaBtn.disabled    = true;
                    ctaBtn.textContent = 'Reindirizzamento\u2026';
                    if (sibiaMeprUrl) window.location.href = sibiaMeprUrl;
                });
            }

            /* Chiudi cliccando fuori o sui pulsanti chiudi/annulla */
            modalOverlay.addEventListener('click', function (e) {
                if (e.target === modalOverlay) { modalOverlay.classList.remove('is-open'); document.body.style.overflow = ''; }
            });
            modalOverlay.querySelectorAll('.sibia-modal__close, .sibia-modal__cancel').forEach(function (el) {
                el.addEventListener('click', function () { modalOverlay.classList.remove('is-open'); document.body.style.overflow = ''; });
            });
            /* Chiudi con ESC */
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modalOverlay.classList.contains('is-open')) {
                    modalOverlay.classList.remove('is-open'); document.body.style.overflow = '';
                }
            });
        }
    })();

    /* ---- Nascondi sezioni del tema che appaiono DOPO il portal ----
       ATTENZIONE: il modal #sibia-modal-overlay è un SIBLING diretto di
       .sibia-portal (fuori dall'elemento). NON nascondere MAI i sibling
       immediati del portal — nascondere solo dal livello "sezione" in su.
    ------------------------------------------------------------------ */
    (function () {

        /* Restituisce true se l'elemento non va nascosto */
        function isSafe(el) {
            if (!el || !el.tagName) return true;
            var tag = el.tagName.toUpperCase();
            if (['FOOTER','HEADER','NAV','SCRIPT','STYLE','NOSCRIPT'].indexOf(tag) !== -1) return true;
            var id  = (el.id  || '').toLowerCase();
            var cls = (el.className || '').toLowerCase();
            if (['wpadminbar','footer','masthead','header','colophon',
                 'site-footer','nav','footer-widgets'].indexOf(id) !== -1) return true;
            if (cls.indexOf('footer') !== -1 || cls.indexOf('header') !== -1) return true;
            /* NON nascondere mai elementi del plugin stesso */
            if (cls.indexOf('sibia-') !== -1 || id.indexOf('sibia-') !== -1) return true;
            return false;
        }

        /* Restituisce true se l'elemento è un contenitore "sezione"
           (et_pb_section Divi, elementor-section, <section>, ecc.) */
        function isSectionLevel(el) {
            if (!el || !el.tagName) return false;
            var tag = el.tagName.toUpperCase();
            if (tag === 'SECTION' || tag === 'ARTICLE') return true;
            var cls = (el.className || '').toLowerCase();
            return (cls.indexOf('et_pb_section')    !== -1 ||
                    cls.indexOf('elementor-section') !== -1 ||
                    cls.indexOf('wp-block-group')    !== -1 ||
                    cls.indexOf('page-section')      !== -1 ||
                    cls.indexOf('mcb-section')       !== -1);
        }

        function hideAfterPortal() {
            var portal = document.querySelector('.sibia-portal');
            if (!portal) return;

            /* ── Metodo A: trova il genitore a livello "sezione" del portal ──
               Cerchiamo il primo antenato che sia et_pb_section / elementor-section
               / <section> / etc.  Da lì nascondiamo tutti i fratelli successivi.
               In questo modo NON tocchiamo MAI il sibling diretto del portal
               (che include #sibia-modal-overlay e il tag <script> del plugin). */
            var el = portal.parentElement;
            var sectionAncestor = null;
            while (el && el !== document.body && el !== document.documentElement) {
                if (isSectionLevel(el)) {
                    sectionAncestor = el;
                    break; /* Il primo (più vicino) livello sezione */
                }
                el = el.parentElement;
            }

            /* Se non trovato, usa il figlio diretto di <body> */
            if (!sectionAncestor) {
                sectionAncestor = el; /* el si è fermato al figlio di body */
            }

            if (sectionAncestor && sectionAncestor !== document.body) {
                var sib = sectionAncestor.nextElementSibling;
                while (sib) {
                    /* Non nascondere mai un elemento che contiene il portal */
                    if (!isSafe(sib) && !(sib.querySelector && sib.querySelector('.sibia-portal'))) {
                        sib.style.setProperty('display', 'none', 'important');
                    }
                    sib = sib.nextElementSibling;
                }
            }

            /* ── Metodo B: TreeWalker — cerca testo "CONTATTACI" nel DOM ──
               Risale al livello sezione e nasconde quell'elemento.
               Skip: testo dentro .sibia-portal, dentro link <a>, o in un elemento safe. */
            try {
                var walker = document.createTreeWalker(
                    document.body, NodeFilter.SHOW_TEXT, null, false
                );
                var textNodes = [];
                var n;
                while ((n = walker.nextNode())) {
                    if (n.textContent.toUpperCase().indexOf('CONTATTACI') !== -1) {
                        textNodes.push(n.parentElement);
                    }
                }
                textNodes.forEach(function (node) {
                    if (!node || !node.parentElement) return;

                    /* Skip se è dentro .sibia-portal */
                    var p = node;
                    while (p && p !== document.body) {
                        if (p.classList && p.classList.contains('sibia-portal')) return;
                        p = p.parentElement;
                    }

                    /* Skip se è dentro un link (menu nav) */
                    var inLink = false;
                    var a = node;
                    while (a && a !== document.body) {
                        if (a.tagName === 'A') { inLink = true; break; }
                        a = a.parentElement;
                    }
                    if (inLink) return;

                    /* Risali al livello sezione più vicino */
                    var target = node;
                    while (target.parentElement &&
                           target.parentElement !== document.body &&
                           !isSectionLevel(target)) {
                        target = target.parentElement;
                    }

                    /* SICUREZZA 1: nascondi SOLO se abbiamo trovato un elemento
                       a livello sezione (et_pb_section, <section>, ecc.).
                       Se non trovato (arrivati a figlio-di-body), NON nascondere —
                       potrebbe contenere anche il portal. */
                    if (!isSectionLevel(target)) return;

                    /* SICUREZZA 2: non nascondere mai un elemento che
                       contiene .sibia-portal (evita di nascondere il portal stesso) */
                    if (target.querySelector && target.querySelector('.sibia-portal')) return;

                    if (!isSafe(target)) {
                        target.style.setProperty('display', 'none', 'important');
                    }
                });
            } catch (e) { /* silenzioso */ }
        }

        document.addEventListener('DOMContentLoaded', hideAfterPortal);
        window.addEventListener('load', hideAfterPortal);
        setTimeout(hideAfterPortal, 800);
        setTimeout(hideAfterPortal, 2000);
    })();

    /* --- Toggle mensile/annuale nella pagina abbonamento --- */
    (function () {
        var toggle = document.getElementById('sibia-abb-toggle');
        if (!toggle) return;
        var btns  = toggle.querySelectorAll('.sibia-abb-toggle__btn');
        var cards = document.querySelectorAll('.sibia-abb-card');
        var interval = 'mensile';

        function updateCards() {
            cards.forEach(function (card) {
                var amount = card.querySelector('[data-role="amount"]');
                var period = card.querySelector('[data-role="period"]');
                var cta    = card.querySelector('[data-role="cta"]');
                if (amount) amount.textContent = card.getAttribute('data-price-' + interval);
                if (period) period.textContent = interval === 'mensile' ? '/mese' : '/anno';
                if (cta) {
                    var url = card.getAttribute('data-url-' + interval);
                    cta.setAttribute('href', url || '#');
                    cta.style.pointerEvents = url ? '' : 'none';
                    cta.style.opacity       = url ? '' : '0.5';
                }
            });
        }

        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                interval = btn.getAttribute('data-interval');
                btns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                updateCards();
            });
        });
    })();

    /* --- Navigazione data-href (es. card SynchToFic → abbonamento) --- */
    document.querySelectorAll('.sibia-card[data-href]').forEach(function (card) {
        card.style.cursor = 'pointer';
        card.addEventListener('click', function () {
            var url = card.getAttribute('data-href');
            if (url) window.location.href = url;
        });
    });

    /* Sezioni collassabili */
    document.querySelectorAll('.sibia-step--collapsible .sibia-step__title').forEach(function (title) {
        title.style.cursor = 'pointer';
        title.addEventListener('click', function () {
            this.closest('.sibia-step--collapsible').classList.toggle('is-collapsed');
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

function sibia_onboarding_call_api($email, $ragioneSociale, $token, $pipedriveApiKey)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array(
            'success' => false,
            'message' => 'Onboarding secret non configurato in Impostazioni.',
            'apiKey' => null
        );
    }

    $payload = array(
        'token' => $token,
        'ragioneSociale' => $ragioneSociale,
        'email' => $email,
        'pipedriveApiKey' => $pipedriveApiKey
    );

    $response = wp_remote_post($baseUrl . '/onboarding/pic-pip', array(
        'headers' => array(
            $header => $secret,
            'Content-Type' => 'application/json'
        ),
        'body' => wp_json_encode($payload),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return array(
            'success' => false,
            'message' => $response->get_error_message(),
            'apiKey' => null
        );
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['success'])) {
        $message = isset($data['error']['message']) ? $data['error']['message'] : 'Risposta non valida.';
        return array(
            'success' => false,
            'message' => $message,
            'apiKey' => null
        );
    }

    return array(
        'success' => true,
        'message' => 'OK',
        'apiKey' => $data['data']['apiKey'] ?? null
    );
}

function sibia_sync_cliente_api($email, $data)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'message' => 'Onboarding secret non configurato.');
    }

    $payload = array(
        'email'              => $email,
        'nome'               => $data['first_name'] ?? '',
        'cognome'            => $data['last_name'] ?? '',
        'ragioneSociale'     => $data['ragione_sociale'] ?? '',
        'indirizzo'          => $data['indirizzo'] ?? '',
        'indirizzo2'         => $data['indirizzo2'] ?? '',
        'cap'                => $data['cap'] ?? '',
        'citta'              => $data['citta'] ?? '',
        'provincia'          => $data['provincia'] ?? '',
        'partitaIva'         => $data['partita_iva'] ?? '',
        'codiceFiscale'      => $data['codice_fiscale'] ?? '',
        'telefono'           => $data['telefono'] ?? '',
        'telefonoReferente'  => $data['telefono_referente'] ?? '',
    );

    $response = wp_remote_request($baseUrl . '/onboarding/sync-cliente', array(
        'method'  => 'PUT',
        'headers' => array(
            $header => $secret,
            'Content-Type' => 'application/json'
        ),
        'body'    => wp_json_encode($payload),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        $message = isset($result['error']['message']) ? $result['error']['message'] : 'Risposta API non valida (HTTP ' . $httpCode . ').';
        return array('success' => false, 'message' => $message);
    }

    return array('success' => true, 'message' => 'OK');
}

function sibia_load_cliente_api($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array();
    }

    $url = add_query_arg(array('email' => $email), $baseUrl . '/onboarding/cliente');

    $response = wp_remote_get($url, array(
        'headers' => array($header => $secret),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return array();
    }

    $body   = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (!is_array($result) || empty($result['success']) || empty($result['data'])) {
        return array();
    }

    $d = $result['data'];
    return array(
        'nome'               => $d['nome']               ?? '',
        'cognome'            => $d['cognome']             ?? '',
        'ragioneSociale'     => $d['ragioneSociale']      ?? '',
        'partitaIva'         => $d['partitaIva']          ?? '',
        'codiceFiscale'      => $d['codiceFiscale']       ?? '',
        'indirizzo'          => $d['indirizzo']           ?? '',
        'indirizzo2'         => $d['indirizzo2']          ?? '',
        'cap'                => $d['cap']                 ?? '',
        'citta'              => $d['citta']               ?? '',
        'provincia'          => $d['provincia']           ?? '',
        'telefono'           => $d['telefono']            ?? '',
        'telefonoReferente'  => $d['telefonoReferente']   ?? '',
    );
}

function sibia_get_pic_pip_status($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'token' => '', 'pipedriveApiKey' => '', 'apiKey' => '', 'configured' => false);
    }

    $url = $baseUrl . '/onboarding/pic-pip-status?email=' . urlencode($email);
    $response = wp_remote_get($url, array(
        'headers' => array($header => $secret),
        'timeout' => 15
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'token' => '', 'pipedriveApiKey' => '', 'apiKey' => '', 'configured' => false);
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        return array('success' => false, 'token' => '', 'pipedriveApiKey' => '', 'apiKey' => '', 'configured' => false);
    }

    $data = $result['data'] ?? array();
    return array(
        'success'         => true,
        'token'           => $data['token'] ?? '',
        'pipedriveApiKey' => $data['pipedriveApiKey'] ?? '',
        'apiKey'          => $data['apiKey'] ?? '',
        'configured'      => !empty($data['configured']),
        'inTest'          => !empty($data['inTest']),
        'dittaPicam7'     => $data['dittaPicam7'] ?? '',
        'ultimoRun'       => $data['ultimoRun'] ?? '',
        'mappatureCount'  => intval($data['mappatureCount'] ?? 0),
        'p7extAttivo'     => !empty($data['p7ExtAttivo']),
    );
}

function sibia_save_pic_pip_config($email, $token, $pipedriveApiKey)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'message' => 'Onboarding secret non configurato.');
    }

    $payload = array(
        'email'          => $email,
        'token'          => $token,
        'pipedriveApiKey' => $pipedriveApiKey,
    );

    $response = wp_remote_request($baseUrl . '/onboarding/pic-pip-config', array(
        'method'  => 'PUT',
        'headers' => array(
            $header => $secret,
            'Content-Type' => 'application/json'
        ),
        'body'    => wp_json_encode($payload),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        $message = isset($result['error']['message']) ? $result['error']['message'] : 'Risposta API non valida (HTTP ' . $httpCode . ').';
        return array('success' => false, 'message' => $message);
    }

    $data = $result['data'] ?? array();
    return array(
        'success' => true,
        'message' => 'OK',
        'apiKey'  => $data['apiKey'] ?? '',
    );
}

function sibia_get_sytfic_status($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'apiKey' => '', 'configured' => false);
    }

    $url      = $baseUrl . '/onboarding/sytfic-status?email=' . urlencode($email);
    $response = wp_remote_get($url, array(
        'headers' => array($header => $secret),
        'timeout' => 15
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'apiKey' => '', 'configured' => false);
    }

    $body   = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        return array('success' => false, 'apiKey' => '', 'configured' => false);
    }

    $data = $result['data'] ?? array();
    return array(
        'success'                => true,
        'apiKey'                 => $data['apiKey'] ?? '',
        'configured'             => !empty($data['configured']),
        'inTest'                 => !empty($data['inTest']),
        'ficConnected'           => !empty($data['ficConnected']),
        'synchroteamDomain'      => $data['synchroteamDomain'] ?? '',
        'ficCompanyId'           => $data['ficCompanyId'] ?? '',
        'triggerJob'             => $data['triggerJob'] ?? 'completed',
        'productIdOre'           => isset($data['productIdOre']) ? intval($data['productIdOre']) : null,
        'syncArticoliAbilitato'  => isset($data['syncArticoliAbilitato']) ? (bool)$data['syncArticoliAbilitato'] : false,
        'syncClientiAbilitato'   => isset($data['syncClientiAbilitato'])  ? (bool)$data['syncClientiAbilitato']  : false,
        'ultimoRun'              => $data['ultimoRun'] ?? '',
        'ultimoRunClienti'       => $data['ultimoRunClienti'] ?? '',
        'ultimoRunJob'           => $data['ultimoRunJob'] ?? '',
        'clientiSincronizzati'    => intval($data['clientiSincronizzati'] ?? 0),
        'interventiSincronizzati' => intval($data['interventiSincronizzati'] ?? 0),
    );
}

function sibia_save_sytfic_config($email, $domain, $synchApiKey, $trigger, $productIdOre, $syncArticoliAbilitato = false, $syncClientiAbilitato = false)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'message' => 'Onboarding secret non configurato.');
    }

    /* Salta le chiavi placeholder (campo non modificato dall'utente) */
    $synchApiKeyVal = (strpos($synchApiKey, '•') !== false) ? null : $synchApiKey;

    $payload = array(
        'email'                  => $email,
        'synchroteamDomain'      => $domain,
        'triggerJob'             => $trigger,
        'ficPrezzoOra'           => null,
        'ficProductIdOre'        => $productIdOre,
        'syncArticoliAbilitato'  => (bool)$syncArticoliAbilitato,
        'syncClientiAbilitato'   => (bool)$syncClientiAbilitato,
    );
    if ($synchApiKeyVal !== null) {
        $payload['synchroteamApiKey'] = $synchApiKeyVal;
    }

    $response = wp_remote_request($baseUrl . '/onboarding/sytfic-config', array(
        'method'  => 'PUT',
        'headers' => array(
            $header        => $secret,
            'Content-Type' => 'application/json'
        ),
        'body'    => wp_json_encode($payload),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body     = wp_remote_retrieve_body($response);
    $result   = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        $message = isset($result['error']['message']) ? $result['error']['message'] : 'Risposta API non valida (HTTP ' . $httpCode . ').';
        $details = $result['error']['details'] ?? '';
        return array('success' => false, 'message' => $message, 'details' => $details);
    }

    $data = $result['data'] ?? array();
    return array(
        'success' => true,
        'message' => 'OK',
        'apiKey'  => $data['apiKey'] ?? '',
    );
}

/**
 * Recupera il catalogo prodotti FIC del cliente da ApiConnect.
 * Restituisce array di prodotti: [{id, name, netPrice}] oppure array vuoto se FIC non connesso.
 */
/**
 * Restituisce array associativo:
 *   'products' => array di articoli [{id, name, netPrice}]
 *   'error'    => null oppure stringa con messaggio di errore diagnostico
 */
function sibia_get_sytfic_fic_products($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) return array('products' => array(), 'error' => 'Onboarding secret non configurato.');

    $url      = $baseUrl . '/onboarding/sytfic-fic-products?email=' . urlencode($email);
    $response = wp_remote_get($url, array(
        'headers' => array($header => $secret),
        'timeout' => 20
    ));

    if (is_wp_error($response))
        return array('products' => array(), 'error' => 'Errore connessione API: ' . $response->get_error_message());

    $code   = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if ($code !== 200 || !is_array($result) || empty($result['success'])) {
        $detail = isset($result['error']['message']) ? $result['error']['message'] : "HTTP $code";
        return array('products' => array(), 'error' => "Errore recupero articoli FIC: $detail");
    }

    $products = $result['data'] ?? array();
    return array('products' => $products, 'error' => null);
}

/**
 * Testa la connessione reale a Fatture in Cloud chiamando ApiConnect /sytfic-fic-test.
 *
 * Restituisce un array con due flag:
 *   - 'connected'      (bool): il cliente ha un collegamento valido (access token OK
 *                              o refresh token disponibile per il prossimo rinnovo)
 *   - 'pendingRefresh' (bool): l'access token è scaduto/assente ma il refresh token
 *                              è presente. Il Windows Service rinnoverà al prossimo
 *                              ciclo di sync. Il plugin mostra banner arancione.
 *
 * Mapping stati UI:
 *   - connected=true,  pendingRefresh=false → VERDE   "Fatture in Cloud collegato"
 *   - connected=true,  pendingRefresh=true  → ARANCIO "Sincronizzazione in corso"
 *   - connected=false                       → ROSSO   "Fatture in Cloud non collegato"
 */
function sibia_test_sytfic_fic($email)
{
    $failed = array('connected' => false, 'pendingRefresh' => false);

    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) return $failed;

    $url      = $baseUrl . '/onboarding/sytfic-fic-test?email=' . urlencode($email);
    $response = wp_remote_get($url, array(
        'headers' => array($header => $secret),
        'timeout' => 15
    ));

    if (is_wp_error($response)) return $failed;

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if ($code !== 200 || empty($result['success']) || !isset($result['data'])) {
        return $failed;
    }

    $data = $result['data'];
    return array(
        'connected'      => !empty($data['connected']),
        'pendingRefresh' => !empty($data['pendingRefresh']),
    );
}

/**
 * Ottiene l'URL di autorizzazione OAuth per Fatture in Cloud.
 * Chiama l'API SIBIA che genera uno state temporaneo e restituisce l'URL.
 * Restituisce array con 'success' (bool), 'oauthUrl' (string) e 'message' (string on error).
 */
function sibia_get_sytfic_oauth_url($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'message' => 'Onboarding secret non configurato.');
    }

    $url = $baseUrl . '/onboarding/sytfic-oauth-url?email=' . urlencode($email);
    $response = wp_remote_get($url, array(
        'headers' => array($header => $secret),
        'timeout' => 15
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $body   = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        $message = isset($result['error']['message']) ? $result['error']['message'] : 'Risposta API non valida.';
        return array('success' => false, 'message' => $message);
    }

    $data = $result['data'] ?? array();
    return array(
        'success'  => true,
        'oauthUrl' => $data['oauthUrl'] ?? '',
    );
}

/**
 * Scollega Synchroteam: azzera dominio e API key nel DB tramite API.
 * Restituisce array con 'success' (bool) e 'message' (string on error).
 */
function sibia_disconnect_sytfic_synch($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    $url = $baseUrl . '/onboarding/sytfic-synch-disconnect?email=' . urlencode($email);
    $response = wp_remote_request($url, array(
        'method'  => 'DELETE',
        'headers' => array($header => $secret),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 && !empty($data['success'])) {
        return array('success' => true);
    }

    $errMsg = $data['error']['message'] ?? 'Errore durante la disconnessione.';
    return array('success' => false, 'message' => $errMsg);
}

/**
 * Scollega Fatture in Cloud: azzera i token OAuth nel DB tramite API.
 * Restituisce array con 'success' (bool) e 'message' (string on error).
 */
function sibia_disconnect_sytfic_fic($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    $url = $baseUrl . '/onboarding/sytfic-fic-disconnect?email=' . urlencode($email);
    $response = wp_remote_request($url, array(
        'method'  => 'DELETE',
        'headers' => array($header => $secret),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 && !empty($data['success'])) {
        return array('success' => true);
    }

    $errMsg = $data['error']['message'] ?? 'Errore durante la disconnessione.';
    return array('success' => false, 'message' => $errMsg);
}

/**
 * Testa la connessione alle API Synchroteam.
 * Auth: HTTP Basic con base64(domain:apiKey).
 * Restituisce array con 'ok' (bool) e 'message' (string).
 */
function sibia_test_synchroteam($domain, $apiKey)
{
    if (empty($domain) || empty($apiKey)) {
        return array('ok' => false, 'message' => 'Credenziali non fornite.');
    }

    $credentials = base64_encode($domain . ':' . $apiKey);
    $url = 'https://ws.synchroteam.com/Api/v3/customer/list?page=1&pageSize=1';

    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Basic ' . $credentials,
            'Accept'        => 'application/json',
        ),
        'timeout' => 10,
    ));

    if (is_wp_error($response)) {
        return array('ok' => false, 'message' => 'Connessione non riuscita: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        return array('ok' => true, 'message' => 'Connessa');
    } elseif ($code === 401 || $code === 403) {
        return array('ok' => false, 'message' => 'Credenziali non valide (HTTP ' . $code . ').');
    } else {
        return array('ok' => false, 'message' => 'Risposta inattesa: HTTP ' . $code . '.');
    }
}

/**
 * Testa la connessione alle API Fatture in Cloud.
 * Auth: Bearer token (OAuth o token manuale).
 * Restituisce array con 'ok' (bool) e 'message' (string).
 */
function sibia_test_fic($token)
{
    if (empty($token)) {
        return array('ok' => false, 'message' => 'Token non fornito.');
    }

    // Rimuovi eventuale prefisso "Bearer " se già presente nel campo
    $cleanToken = preg_replace('/^Bearer\s+/i', '', trim($token));
    $url = 'https://api-v2.fattureincloud.it/user/me';

    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $cleanToken,
            'Accept'        => 'application/json',
        ),
        'timeout' => 10,
    ));

    if (is_wp_error($response)) {
        return array('ok' => false, 'message' => 'Connessione non riuscita: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        return array('ok' => true, 'message' => 'Connessa');
    } elseif ($code === 401) {
        return array('ok' => false, 'message' => 'Token non valido o scaduto (HTTP 401).');
    } elseif ($code === 403) {
        return array('ok' => false, 'message' => 'Accesso negato (HTTP 403). Verifica permessi token.');
    } else {
        return array('ok' => false, 'message' => 'Risposta inattesa: HTTP ' . $code . '.');
    }
}

function sibia_regenerate_api_key($email)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'message' => 'Onboarding secret non configurato.');
    }

    $payload = array('email' => $email);

    $response = wp_remote_post($baseUrl . '/onboarding/regenerate-api-key', array(
        'headers' => array(
            $header => $secret,
            'Content-Type' => 'application/json'
        ),
        'body'    => wp_json_encode($payload),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        $message = isset($result['error']['message']) ? $result['error']['message'] : 'Risposta API non valida (HTTP ' . $httpCode . ').';
        return array('success' => false, 'message' => $message);
    }

    $data = $result['data'] ?? array();
    return array(
        'success' => true,
        'message' => 'OK',
        'apiKey'  => $data['apiKey'] ?? '',
    );
}

function sibia_set_p7ext_attivo($email, $p7extAttivo)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'message' => 'Onboarding secret non configurato.');
    }

    $payload = array(
        'email'       => $email,
        'p7ExtAttivo' => (bool)$p7extAttivo,
    );

    $response = wp_remote_request($baseUrl . '/onboarding/pic-pip-p7ext', array(
        'method'  => 'PUT',
        'headers' => array(
            $header          => $secret,
            'Content-Type'   => 'application/json'
        ),
        'body'    => wp_json_encode($payload),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    if (!is_array($result) || empty($result['success'])) {
        $message = isset($result['error']['message']) ? $result['error']['message'] : 'Risposta API non valida (HTTP ' . $httpCode . ').';
        return array('success' => false, 'message' => $message);
    }

    return array('success' => true, 'message' => 'OK');
}

function sibia_onboarding_handle_post($user)
{
    $notice = '';
    $noticeType = '';
    $activeSection = '';
    $activeServiceDetail = '';
    $email  = $user->user_email;
    $apiKey = get_user_meta($user->ID, 'sibia_sync_api_key', true);

    /* ---- Salvataggio dati registrazione ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_registrazione_submit'])) {
        $activeSection = 'registrazione';
        if (!isset($_POST['sibia_registrazione_nonce']) ||
            !wp_verify_nonce($_POST['sibia_registrazione_nonce'], 'sibia_registrazione_submit')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            /* Tutti i dati vanno solo sull'API, nulla su WP */
            $syncData = array(
                'first_name'         => sanitize_text_field($_POST['first_name'] ?? ''),
                'last_name'          => sanitize_text_field($_POST['last_name'] ?? ''),
                'ragione_sociale'    => sanitize_text_field($_POST['ragione_sociale'] ?? ''),
                'partita_iva'        => sanitize_text_field($_POST['partita_iva'] ?? ''),
                'codice_fiscale'     => sanitize_text_field($_POST['codice_fiscale'] ?? ''),
                'indirizzo'          => sanitize_text_field($_POST['indirizzo'] ?? ''),
                'indirizzo2'         => sanitize_text_field($_POST['indirizzo2'] ?? ''),
                'cap'                => sanitize_text_field($_POST['cap'] ?? ''),
                'citta'              => sanitize_text_field($_POST['citta'] ?? ''),
                'provincia'          => sanitize_text_field($_POST['provincia'] ?? ''),
                'telefono'           => sanitize_text_field($_POST['telefono'] ?? ''),
                'telefono_referente' => sanitize_text_field($_POST['telefono_referente'] ?? ''),
            );

            /* Sincronizza con API */
            $syncResult = sibia_sync_cliente_api($user->user_email, $syncData);
            if ($syncResult['success']) {
                $notice = 'Dati salvati e sincronizzati con successo.';
                $noticeType = 'success';
            } else {
                $notice = 'Dati salvati localmente. Sincronizzazione API: ' . esc_html($syncResult['message']);
                $noticeType = 'error';
            }
        }
    }

    /* ---- Cambio password ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_password_submit'])) {
        $activeSection = 'registrazione';
        if (!isset($_POST['sibia_password_nonce']) ||
            !wp_verify_nonce($_POST['sibia_password_nonce'], 'sibia_password_submit')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!wp_check_password($current, $user->user_pass, $user->ID)) {
                $notice = 'La password attuale non è corretta.';
                $noticeType = 'error';
            } elseif (strlen($new) < 8) {
                $notice = 'La nuova password deve avere almeno 8 caratteri.';
                $noticeType = 'error';
            } elseif ($new !== $confirm) {
                $notice = 'Le password non coincidono.';
                $noticeType = 'error';
            } else {
                wp_set_password($new, $user->ID);
                wp_set_auth_cookie($user->ID);
                wp_set_current_user($user->ID);
                $notice = 'Password modificata con successo.';
                $noticeType = 'success';
            }
        }
    }

    /* ---- Salvataggio configurazione Picam7-Pipedrive ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_picpip_submit'])) {
        $activeSection = 'soluzioni';
        $activeServiceDetail = 'picam-pipedrive';
        if (!isset($_POST['sibia_picpip_nonce']) ||
            !wp_verify_nonce($_POST['sibia_picpip_nonce'], 'sibia_picpip_submit')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $token = sanitize_text_field($_POST['token'] ?? '');
            $pipedriveKey = sanitize_text_field($_POST['pipedrive_api_key'] ?? '');

            if (empty($token) || empty($pipedriveKey)) {
                $notice = 'Token e API key Pipedrive sono obbligatori.';
                $noticeType = 'error';
            } else {
                $result = sibia_save_pic_pip_config($user->user_email, $token, $pipedriveKey);
                if ($result['success']) {
                    update_user_meta($user->ID, 'sibia_sync_api_key', $result['apiKey']);
                    update_user_meta($user->ID, 'sibia_onboarding_token', $token);
                    $notice = 'Configurazione salvata con successo.';
                    $noticeType = 'success';
                } else {
                    $notice = 'Errore: ' . esc_html($result['message']);
                    $noticeType = 'error';
                }
            }
        }
    }

    /* ---- Rigenerazione API key ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_regenerate_key_submit'])) {
        $activeSection = 'soluzioni';
        $activeServiceDetail = 'picam-pipedrive';
        if (!isset($_POST['sibia_regenerate_key_nonce']) ||
            !wp_verify_nonce($_POST['sibia_regenerate_key_nonce'], 'sibia_regenerate_key_submit')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $result = sibia_regenerate_api_key($user->user_email);
            if ($result['success']) {
                update_user_meta($user->ID, 'sibia_sync_api_key', $result['apiKey']);
                $notice = 'Chiave API rigenerata con successo. Aggiorna la chiave nel programma Sync.PicamPipedrive.';
                $noticeType = 'success';
            } else {
                $notice = 'Errore: ' . esc_html($result['message']);
                $noticeType = 'error';
            }
        }
    }

    /* ---- Toggle P7Extension CRM ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_p7ext_submit'])) {
        $activeSection = 'soluzioni';
        $activeServiceDetail = 'picam-pipedrive';
        if (!isset($_POST['sibia_p7ext_nonce']) ||
            !wp_verify_nonce($_POST['sibia_p7ext_nonce'], 'sibia_p7ext_submit')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $p7extAttivo = !empty($_POST['p7ext_attivo']);
            $result = sibia_set_p7ext_attivo($user->user_email, $p7extAttivo);
            if ($result['success']) {
                $notice = $p7extAttivo
                    ? 'Integrazione P7Extension abilitata.'
                    : 'Integrazione P7Extension disabilitata.';
                $noticeType = 'success';
            } else {
                $notice = 'Errore: ' . esc_html($result['message']);
                $noticeType = 'error';
            }
        }
    }

    /* ---- Salvataggio configurazione Synchroteam-FattureInCloud ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_sytfic_submit'])) {
        $activeSection = 'soluzioni';
        $activeServiceDetail = 'synchroteam-fic';
        if (!isset($_POST['sibia_sytfic_nonce']) ||
            !wp_verify_nonce($_POST['sibia_sytfic_nonce'], 'sibia_sytfic_submit')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $sytDomain          = sanitize_text_field($_POST['sytfic_domain'] ?? '');
            $sytApiKey          = sanitize_text_field($_POST['sytfic_api_key'] ?? '');
            $trigger            = sanitize_text_field($_POST['sytfic_trigger'] ?? 'completed');
            $productIdOre       = sanitize_text_field($_POST['sytfic_product_id_ore'] ?? '');
            $syncClienti        = isset($_POST['sytfic_sync_clienti'])  && $_POST['sytfic_sync_clienti']  === '1';
            $syncArticoli       = isset($_POST['sytfic_sync_articoli']) && $_POST['sytfic_sync_articoli'] === '1';

            if (empty($sytDomain) || empty($sytApiKey)) {
                $notice = 'Dominio e API Key Synchroteam sono obbligatori.';
                $noticeType = 'error';
            } else {
                $productIdOreVal = $productIdOre !== '' ? intval($productIdOre) : null;
                // Valida le credenziali Synchroteam prima di salvare (solo se la chiave è nuova, non mascherata)
                $sytApiKeyNuova = strpos($sytApiKey, '•') === false;
                if ($sytApiKeyNuova) {
                    $testPre = sibia_test_synchroteam($sytDomain, $sytApiKey);
                    if (!$testPre['ok']) {
                        $notice = 'Credenziali Synchroteam non valide: ' . esc_html($testPre['message']);
                        $noticeType = 'error';
                    }
                }
                if ($noticeType !== 'error') {
                $result = sibia_save_sytfic_config(
                    $user->user_email,
                    $sytDomain,
                    $sytApiKey,
                    $trigger,
                    $productIdOreVal,
                    $syncArticoli,
                    $syncClienti
                );
                if ($result['success']) {
                    $sytfic_ret_apiKey = $result['apiKey'];
                    $notice = 'Configurazione Synchroteam salvata con successo.'
                        . ($sytFicHasToken ? '' : ' Ora collega Fatture in Cloud usando il pulsante qui sotto.');
                    $noticeType = 'success';
                    // Risultato test già disponibile se la chiave era nuova; altrimenti null (non testata)
                    $sytfic_ret_test_synch = $sytApiKeyNuova ? $testPre : null;
                    // Auto-attivazione demo alla prima configurazione (se non già demo/attivo)
                    $_statoAttuale = $sytFicBillingItem['stato'] ?? 'inattivo';
                    if ($_statoAttuale === 'inattivo') {
                        $_pianoDemo = $syncArticoli ? 'professional' : 'standard';
                        sibia_billing_attiva_demo($user->user_email, 'SynchToFic', $_pianoDemo);
                        // Aggiorna il banner ricaricando il billing status
                        $allBillingStatus  = sibia_get_billing_status($user->user_email);
                        $sytFicBillingItem = null;
                        foreach ($allBillingStatus as $_bsItem) {
                            if (strcasecmp($_bsItem['servizio'] ?? '', 'SynchToFic') === 0) {
                                $sytFicBillingItem = $_bsItem;
                                break;
                            }
                        }
                    }
                } else {
                    $details = !empty($result['details']) ? ' — ' . $result['details'] : '';
                    $notice = 'Errore: ' . esc_html($result['message']) . esc_html($details);
                    $noticeType = 'error';
                }
                } // end if ($noticeType !== 'error')
            }
        }
    }

    /* ---- Avvio OAuth Fatture in Cloud ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_sytfic_oauth_start'])) {
        $activeSection = 'soluzioni';
        $activeServiceDetail = 'synchroteam-fic';
        if (!isset($_POST['sibia_sytfic_oauth_nonce']) ||
            !wp_verify_nonce($_POST['sibia_sytfic_oauth_nonce'], 'sibia_sytfic_oauth_start')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $oauthResult = sibia_get_sytfic_oauth_url($email);
            if ($oauthResult['success'] && !empty($oauthResult['oauthUrl'])) {
                wp_redirect($oauthResult['oauthUrl']);
                exit;
            } else {
                $notice = 'Errore avvio autenticazione FIC: ' . esc_html($oauthResult['message'] ?? 'Riprova.');
                $noticeType = 'error';
            }
        }
    }

    /* ---- Scollega Fatture in Cloud ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_sytfic_fic_disconnect'])) {
        $activeSection = 'soluzioni';
        $activeServiceDetail = 'synchroteam-fic';
        if (!isset($_POST['sibia_sytfic_fic_disconnect_nonce']) ||
            !wp_verify_nonce($_POST['sibia_sytfic_fic_disconnect_nonce'], 'sibia_sytfic_fic_disconnect')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $result = sibia_disconnect_sytfic_fic($user->user_email);
            if (!$result['success']) {
                $notice = 'Errore durante la disconnessione: ' . esc_html($result['message'] ?? 'Riprova.');
                $noticeType = 'error';
            }
        }
    }

    /* ---- Scollega Synchroteam ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_sytfic_synch_disconnect'])) {
        $activeSection = 'soluzioni';
        $activeServiceDetail = 'synchroteam-fic';
        if (!isset($_POST['sibia_sytfic_synch_disconnect_nonce']) ||
            !wp_verify_nonce($_POST['sibia_sytfic_synch_disconnect_nonce'], 'sibia_sytfic_synch_disconnect')) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $result = sibia_disconnect_sytfic_synch($user->user_email);
            if (!$result['success']) {
                $notice = 'Errore durante la disconnessione Synchroteam: ' . esc_html($result['message'] ?? 'Riprova.');
                $noticeType = 'error';
            }
        }
    }

    /* ---- Risoluzione cliente Synchroteam pendente ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_sytfic_risolvi_submit'])) {
        $activeSection       = 'soluzioni';
        $activeServiceDetail = 'synchroteam-fic';
        $pendId = intval($_POST['sytfic_pendente_id'] ?? 0);

        if ($pendId <= 0 ||
            !isset($_POST['sibia_sytfic_risolvi_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sibia_sytfic_risolvi_nonce'])), 'sibia_sytfic_risolvi_' . $pendId)) {
            $notice = 'Richiesta non valida. Riprova.';
            $noticeType = 'error';
        } else {
            $azione     = sanitize_text_field($_POST['sytfic_azione'] ?? '');
            $ficIdRaw   = sanitize_text_field($_POST['sytfic_fic_cliente_id'] ?? '');
            $ficId      = $ficIdRaw !== '' ? intval($ficIdRaw) : null;

            if ($azione === 'crea_fic') {
                $result = sibia_crea_cliente_fic($user->user_email, $pendId);
                if ($result['success']) {
                    $notice = 'Cliente creato su Fatture in Cloud e collegato. La sincronizzazione verrà completata nel prossimo ciclo.';
                    $noticeType = 'success';
                } else {
                    $notice = 'Si è verificato un problema durante la creazione del cliente. Il supporto SIBIA interverrà a breve.';
                    $noticeType = 'error';
                }
            } elseif ($azione === 'associa' && (!$ficId || $ficId <= 0)) {
                $notice = 'Seleziona il cliente FIC da associare.';
                $noticeType = 'error';
            } else {
                $result = sibia_risolvi_cliente_pendente($user->user_email, $pendId, $azione, $ficId);
                if ($result['success']) {
                    $notice = $azione === 'associa'
                        ? 'Cliente associato con successo. La sincronizzazione verrà completata nel prossimo ciclo.'
                        : 'Cliente escluso dalla sincronizzazione.';
                    $noticeType = 'success';
                } else {
                    $notice = 'Errore: ' . esc_html($result['message'] ?? 'Riprova.');
                    $noticeType = 'error';
                }
            }
        }
    }

    /* ---- Salvataggio prodotto generico (helper interno) ---- */
    function sibia_handle_prodotto_submit($user, $email, $prodotto, $nonce_action, $nonce_field, $dominio_field, $contratto_field) {
        if (!isset($_POST[$nonce_field]) || !wp_verify_nonce($_POST[$nonce_field], $nonce_action)) {
            return ['notice' => 'Richiesta non valida. Riprova.', 'type' => 'error'];
        }

         $dominio   = esc_url_raw(sanitize_text_field($_POST[$dominio_field] ?? ''));
        $contratto = !empty($_POST[$contratto_field]);
        $result    = sibia_save_prodotto($email, $prodotto, $dominio, $contratto);
        if ($result['success']) {
            return ['notice' => 'Configurazione salvata.', 'type' => 'success'];
        }
        return ['notice' => 'Errore salvataggio: ' . esc_html($result['message']), 'type' => 'error'];
    }

    /* ---- Salvataggio configurazione Synchroteam ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_syt_submit'])) {
        $activeSection = 'prodotti';
        $r = sibia_handle_prodotto_submit($user, $email, 'synchroteam', 'sibia_syt_submit', 'sibia_syt_nonce', 'syt_dominio', 'syt_contratto');
        $notice = $r['notice']; $noticeType = $r['type'];
    }

    /* ---- Salvataggio configurazione TourSolver ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_toursolver_submit'])) {
        $activeSection = 'prodotti';
        $r = sibia_handle_prodotto_submit($user, $email, 'toursolver', 'sibia_toursolver_submit', 'sibia_toursolver_nonce', 'toursolver_dominio', 'toursolver_contratto');
        $notice = $r['notice']; $noticeType = $r['type'];
    }

    /* ---- Salvataggio configurazione Delivery ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_delivery_submit'])) {
        $activeSection = 'prodotti';
        $r = sibia_handle_prodotto_submit($user, $email, 'delivery', 'sibia_delivery_submit', 'sibia_delivery_nonce', 'delivery_dominio', 'delivery_contratto');
        $notice = $r['notice']; $noticeType = $r['type'];
    }

    /* ---- Salvataggio configurazione Nomadia Protect ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_nomadia_submit'])) {
        $activeSection = 'prodotti';
        $r = sibia_handle_prodotto_submit($user, $email, 'nomadia-protect', 'sibia_nomadia_submit', 'sibia_nomadia_nonce', 'nomadia_dominio', 'nomadia_contratto');
        $notice = $r['notice']; $noticeType = $r['type'];
    }

    return array(
        'notice'              => $notice,
        'noticeType'          => $noticeType,
        'apiKey'              => $apiKey,
        'activeSection'       => $activeSection,
        'activeServiceDetail' => $activeServiceDetail,
        'sytfic_apiKey'       => $sytfic_ret_apiKey     ?? '',
        'sytfic_test_synch'   => $sytfic_ret_test_synch ?? null,
        'sytfic_test_fic'     => $sytfic_ret_test_fic   ?? null,
    );
}

function sibia_onboarding_render_download_step()
{
    $installerHash = sibia_get_installer_hash();
    ob_start();
    ?>
    <div class="sibia-step">
        <div class="sibia-step__title">1. Scarica e Installa</div>
        <div class="sibia-step__body">
            <div class="sibia-panel">
                <p><strong>Download Sync.PicamPipedrive</strong></p>
                <p>Scarica e installa il programma sul computer/server dove risiede il database Picam7.</p>

                <a href="https://api.cloud-ar.it/downloads/SyncPicamPipedrive_Setup.exe"
                   class="sibia-btn sibia-btn-download"
                   download>
                    📥 Scarica Installer (v1.1.0)
                </a>

                <div class="sibia-file-info">
                    <small>
                        Dimensione: ~75 MB |
                        SHA256: <?php echo esc_html($installerHash); ?>
                    </small>
                </div>

                <hr style="margin: 20px 0;">

                <p><strong>Istruzioni Installazione:</strong></p>
                <ol class="sibia-instructions">
                    <li>Esegui il file scaricato <strong>come Amministratore</strong> (tasto destro → Esegui come amministratore)</li>
                    <li>L'installazione è automatica: verrà installato il servizio Windows e la tray app</li>
                    <li>Al termine, cerca l'icona SIBIA nella system tray (barra in basso a destra)</li>
                    <li>Clicca sull'icona → Seleziona <strong>"Genera token"</strong></li>
                    <li>Il token verrà copiato automaticamente negli appunti</li>
                    <li>Torna qui e completa lo <strong>Step 2</strong> inserendo il token</li>
                </ol>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function sibia_get_installer_hash()
{
    $installerPath = 'C:\inetpub\Downloads\SyncPicamPipedrive_Setup.exe';
    if (file_exists($installerPath)) {
        $fullHash = hash_file('sha256', $installerPath);
        if ($fullHash) {
            return substr($fullHash, 0, 16) . '...' . substr($fullHash, -16);
        }
    }
    return 'File non disponibile';
}

function sibia_onboarding_render_form()
{
    ob_start();
    ?>
    <div class="sibia-step">
        <div class="sibia-step__title">2. Dati cliente</div>
        <div class="sibia-step__body">
            <form class="sibia-form" method="post">
                <?php wp_nonce_field('sibia_onboarding_submit', 'sibia_onboarding_nonce'); ?>
                <label>
                    <span>Ragione sociale</span>
                    <input type="text" name="ragione_sociale" required />
                </label>
                <label>
                    <span>Token Sync.PicamPipedrive</span>
                    <input type="text" name="token" required />
                </label>
                <label>
                    <span>API key Pipedrive</span>
                    <input type="text" name="pipedrive_api_key" required />
                </label>
                <button type="submit" name="sibia_onboarding_submit" class="sibia-btn">Completa onboarding</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ========================================================================
   GUIDA INSTALLAZIONE - Auto-creazione pagina e shortcode
   ======================================================================== */

/* ========================================================================
   CONTRATTI - URL per pagina contratto per soluzione
   ======================================================================== */

function sibia_get_contratto_url($solution)
{
    $map = array(
        'synchroteam'    => home_url('/contratto-synchroteam/'),
        'toursolver'     => home_url('/contratto-toursolver/'),
        'delivery'       => home_url('/contratto-delivery/'),
        'nomadia-protect' => home_url('/contratto-nomadia-protect/'),
    );
    return isset($map[$solution]) ? $map[$solution] : home_url('/');
}

function sibia_get_prodotto_status($email, $prodotto)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('dominio' => '', 'contrattoAccettato' => false, 'dataAccettazione' => null);
    }

    $url = add_query_arg(array('email' => $email, 'prodotto' => $prodotto),
                         $baseUrl . '/onboarding/clienti-prodotti');

    $response = wp_remote_get($url, array(
        'headers' => array($header => $secret),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return array('dominio' => '', 'contrattoAccettato' => false, 'dataAccettazione' => null);
    }

    $body   = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (!is_array($result) || empty($result['success'])) {
        return array('dominio' => '', 'contrattoAccettato' => false, 'dataAccettazione' => null);
    }

    // success=true ma data=null → record non ancora esistente in DB
    if (empty($result['data'])) {
        return null;
    }

    $data = $result['data'];
    return array(
        'dominio'            => $data['dominio'] ?? '',
        'contrattoAccettato' => !empty($data['contrattoAccettato']),
        'dataAccettazione'   => $data['dataAccettazione'] ?? null,
    );
}

function sibia_save_prodotto($email, $prodotto, $dominio, $contrattoAccettato)
{
    $baseUrl = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret  = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header  = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');

    if (empty($secret)) {
        return array('success' => false, 'message' => 'Onboarding secret non configurato.');
    }

    $payload = array(
        'email'               => $email,
        'prodotto'            => $prodotto,
        'dominio'             => $dominio,
        'contrattoAccettato'  => (bool)$contrattoAccettato,
    );

    $response = wp_remote_request($baseUrl . '/onboarding/clienti-prodotti', array(
        'method'  => 'PUT',
        'headers' => array(
            $header        => $secret,
            'Content-Type' => 'application/json',
        ),
        'body'    => wp_json_encode($payload),
        'timeout' => 20,
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $body     = wp_remote_retrieve_body($response);
    $result   = json_decode($body, true);

    if (!is_array($result) || empty($result['success'])) {
        $message = isset($result['error']['message']) ? $result['error']['message'] : 'Risposta API non valida (HTTP ' . $httpCode . ').';
        return array('success' => false, 'message' => $message);
    }

    return array('success' => true);
}

/* ========================================================================
   CONTRATTO SYNCHROTEAM - Auto-creazione pagina
   ======================================================================== */

add_action('init', function () {
    if (get_transient('sibia_contratti_created_v2')) {
        return;
    }

    $pages = array(
        array('slug' => 'contratto-synchroteam',    'title' => 'Contratto di Servizi Cloud Synchroteam',    'shortcode' => '[sibia_contratto_synchroteam]'),
        array('slug' => 'contratto-toursolver',     'title' => 'Contratto di Servizi Cloud TourSolver',     'shortcode' => '[sibia_contratto_toursolver]'),
        array('slug' => 'contratto-delivery',       'title' => 'Contratto di Servizi Cloud Delivery',       'shortcode' => '[sibia_contratto_delivery]'),
        array('slug' => 'contratto-nomadia-protect','title' => 'Contratto di Servizi Cloud Nomadia Protect','shortcode' => '[sibia_contratto_nomadia_protect]'),
    );

    foreach ($pages as $p) {
        if (!get_page_by_path($p['slug'])) {
            wp_insert_post(array(
                'post_title'   => $p['title'],
                'post_name'    => $p['slug'],
                'post_content' => $p['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ));
        }
    }

    set_transient('sibia_contratti_created_v2', true, DAY_IN_SECONDS);
});

/* ============================================================
   HELPER CONTRATTI — genera HTML con stile plugin
   ============================================================ */
function sibia_render_contratto($prodotto, $linkTermini, $linkDoc, $versione) {
    ob_start();
    $p = esc_html($prodotto);
    ?>
    <div class="sibia-contratto-wrap">

        <div class="sibia-hero-block" style="margin-bottom:24px;">
            <img src="https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png" alt="SIBIA" />
            <h2>Contratto di Servizi Cloud</h2>
            <p><?php echo $p; ?> &mdash; A.R. Consultant S.r.l.</p>
        </div>

        <div class="sibia-card" style="cursor:default;margin-bottom:8px;">
            <p style="margin:0;">Tra <strong>A.R. Consultant S.r.l.</strong> a socio unico, sede via Giambologna n. 2 &ndash; 40138 &ndash; Bologna (BO) &ndash; P.IVA e C.Fisc. 03660611207, in persona del suo legale rappresentante pro tempore, di seguito &ldquo;AR Consultant&rdquo; e il <strong>Cliente</strong>.</p>
        </div>

        <div class="sibia-step" style="margin-bottom:8px;">
            <div class="sibia-step__title">Allegato B &ndash; Termini del Produttore e documentazione</div>
            <table class="sibia-contratto-table">
                <tr><td>Termini e Condizioni <?php echo $p; ?></td><td><a href="<?php echo esc_url($linkTermini); ?>" target="_blank"><?php echo esc_html($linkTermini); ?></a></td></tr>
                <tr><td>Documentazione e Help Center</td><td><a href="<?php echo esc_url($linkDoc); ?>" target="_blank"><?php echo esc_html($linkDoc); ?></a></td></tr>
            </table>
            <p>Il Cliente dichiara di aver preso visione dei documenti sopra indicati e di impegnarsi a rispettarli, anche rendendoli vincolanti nei confronti degli eventuali Utilizzatori Finali.</p>
        </div>

        <div class="sibia-step">
            <div class="sibia-step__title">Clausole contrattuali</div>

            <h4>1) Oggetto del Contratto e natura del servizio</h4>
            <p>1.1 AR Consultant mette a disposizione del Cliente, in modalit&agrave; Software-as-a-Service (SaaS), l&rsquo;accesso e l&rsquo;utilizzo della piattaforma software denominata <?php echo $p; ?> (il &ldquo;Servizio&rdquo;), secondo quanto descritto nell&rsquo;Allegato A e nell&rsquo;allegato B (Specifiche/Documentazione).</p>
            <p>1.2 Il Cliente prende atto che il Servizio e la relativa infrastruttura cloud sono forniti ed erogati direttamente dal soggetto titolare della piattaforma (di seguito &ldquo;Produttore&rdquo;), attraverso sistemi non nella disponibilit&agrave; di AR Consultant. La modalit&agrave; di erogazione e di utilizzo del servizio &egrave; regolata dai Termini e Condizioni di Utilizzo del Produttore (Allegato B), che il Cliente dichiara di aver preso visione e si impegna a rispettare.</p>

            <h4>2) Attivazione, credenziali, uso consentito e manleva</h4>
            <p>2.1 Le credenziali sono personali. Il Cliente &egrave; responsabile di ogni uso del Servizio tramite account autorizzati e si impegna a (i) mantenere riservate le credenziali, (ii) adottare misure di sicurezza adeguate (es. MFA ove disponibile), (iii) notificare tempestivamente accessi non autorizzati.</p>
            <p>2.2 &Egrave; vietato il reverse engineering, i test di penetrazione non autorizzati, l&rsquo;uso oltre i limiti di licenza (utenti, sedi, moduli), l&rsquo;upload di contenuti illeciti ed ogni uso contrario a legge.</p>
            <p>2.3 Il Cliente terr&agrave; AR Consultant manlevata e indenne da qualsiasi danno, costo, onere o spesa, o pretese di terzi che lo stesso dovesse subire quale conseguenza del mancato rispetto e/o inadempimento da parte del Cliente degli obblighi di cui al presente Contratto e/o dei Termini e Condizioni del Produttore, nonch&eacute; per contenuti, dati, istruzioni o utilizzi illeciti del servizio.</p>

            <h4>3) Requisiti e cooperazione del Cliente</h4>
            <p>3.1 Il Cliente &egrave; responsabile dei propri sistemi, connettivit&agrave;, dispositivi, browser, configurazioni e integrazioni, endpoint, credenziali e contenuti caricati.</p>
            <p>3.2 Il Cliente si impegna a cooperare nella diagnosi di incidenti e malfunzionamenti fornendo informazioni ragionevolmente richieste (log disponibili lato Cliente, data/ora eventi, utenti coinvolti, ecc.).</p>

            <h4>4) Supporto e gestione ticket</h4>
            <p>4.1 AR Consultant fornisce supporto secondo i livelli indicati nell&rsquo;Allegato A.</p>
            <p>4.2 L&rsquo;assistenza tecnica relativa alla piattaforma <?php echo $p; ?> potr&agrave; essere offerta dal Produttore nei confronti del Cliente ove previsto nei Termini e Condizioni di Utilizzo del Produttore e il rapporto si instauer&agrave; unicamente fra tali soggetti, senza che AR Consultant ne sia vincolata od assuma obblighi.</p>
            <p>4.3 Qualora e nei limiti in cui AR Consultant presti assistenza di primo livello (presa in carico e inoltro al Produttore), tale attivit&agrave; &egrave; disciplinata dall&rsquo;Allegato A. Resta inteso che AR Consultant non assume obblighi di risultato e non garantisce tempi di risoluzione (patch/fix), che dipendono dall&rsquo;analisi tecnica e dalle tempistiche del Produttore; nessun SLA di disponibilit&agrave; o performance &egrave; prestata da AR Consultant.</p>
            <p>4.4 AR Consultant potr&agrave; avvalersi del Produttore per diagnosi e correzioni; il Cliente accetta che alcune attivit&agrave; possano richiedere tempi dipendenti dal Produttore e/o da subfornitori tecnologici.</p>

            <h4>5) Manutenzione, aggiornamenti e modifiche del Servizio</h4>
            <p>5.1 Il Servizio pu&ograve; essere aggiornato per ragioni tecniche, di sicurezza, di evoluzione funzionale o conformit&agrave;.</p>
            <p>5.2 Il Cliente prende atto che il Produttore pu&ograve; modificare, sospendere o interrompere funzionalit&agrave; e/o modalit&agrave; di erogazione del servizio; AR Consultant non risponde di tali eventi n&eacute; delle relative conseguenze.</p>

            <h4>6) Garanzie</h4>
            <p>6.1 La garanzia non copre difetti o disservizi derivanti da: (i) configurazioni o ambiente del Cliente non conformi, (ii) integrazioni o software di terzi, (iii) uso improprio o oltre i limiti contrattuali, (iv) mancata applicazione di mitigazioni/istruzioni operative comunicate da AR Consultant, (v) eventi di sicurezza imputabili al Cliente e modifiche non autorizzate, (vi) mancato rispetto da parte del Cliente delle indicazioni e prescrizioni di cui alle condizioni del Produttore.</p>
            <p>6.2 Salvo quanto espressamente previsto, il Cliente riconosce che un servizio software complesso pu&ograve; presentare anomalie e che non &egrave; garantita l&rsquo;assenza totale di errori o interruzioni. AR Consultant non presta garanzia alcuna rispetto all&rsquo;erogazione del servizio da parte del Produttore n&eacute; circa la compatibilit&agrave; degli apparati, degli applicativi e dei software utilizzati dal Cliente.</p>

            <h4>7) Dati e GDPR</h4>
            <p>7.1 Il Cliente prende atto che il trattamento dei dati personali nell&rsquo;ambito del Servizio &egrave; svolto dal Produttore quale responsabile sulla base di un accordo dedicato Cliente-Produttore di cui alle condizioni in Allegato B; AR Consultant non accede ai dati applicativi e si limita a gestire aspetti commerciali/amministrativi.</p>
            <p>7.2 AR Consultant resta titolare autonoma per i dati di contatto e fatturazione connessi al rapporto contrattuale (preventivi, ordini, fatture, gestione crediti).</p>

            <h4>8) Corrispettivi, fatturazione</h4>
            <p>8.1 In corrispettivo dei servizi di cui all&rsquo;Allegato A, il Cliente corrisponder&agrave; ad AR Consultant i corrispettivi ivi indicati, con le modalit&agrave; previste. I corrispettivi pattuiti saranno dovuti anche in caso di mancata utilizzazione del servizio e non sono previsti rimborsi o crediti per periodi parziali, salvo diverso accordo scritto tra le Parti.</p>
            <p>8.2 In caso di ritardi nei pagamenti rispetto alle scadenze concordate sar&agrave; dovuto, senza necessit&agrave; di messa in mora, un interesse pari al tasso previsto dal D.Lgs. 231/2002 dalla scadenza al saldo.</p>
            <p>8.3 Qualora il Cliente, per propria scelta o per errore, sottoscriva e/o paghi il servizio direttamente al Produttore, tali importi restano estranei al presente Contratto e non danno diritto a sospendere, ritardare o compensare i corrispettivi dovuti ad AR Consultant.</p>
            <p>8.4 Il Cliente prende atto che il Produttore pubblica sul proprio sito un prezzo standard del servizio che pu&ograve; subire variazioni. AR Consultant potr&agrave; adeguare i corrispettivi alla scadenza del periodo contrattuale, previa comunicazione scritta al Cliente con il preavviso indicato nell&rsquo;Allegato A.</p>

            <h4>9) Limitazione di responsabilit&agrave;</h4>
            <p>9.1 Restano ferme le responsabilit&agrave; che non possono essere escluse o limitate per legge, incluse quelle per dolo o colpa grave.</p>
            <p>9.2 Pertanto, fatti salvi i casi di dolo e colpa grave nei limiti inderogabili di legge, AR Consultant non potr&agrave; essere ritenuta responsabile di alcun danno derivante al Cliente in conseguenza dell&rsquo;acquisto del Servizio e dell&rsquo;uso dello stesso, inclusi danni diretti e/o indiretti e consequenziali (a mero titolo esemplificativo: perdita di profitto, perdita di ricavi, perdita di chance, fermo attivit&agrave;, danno reputazionale, perdita di avviamento, perdita o corruzione di dati).</p>
            <p>9.3 In ogni caso, la responsabilit&agrave; complessiva di AR Consultant, per qualsiasi titolo, non potr&agrave; eccedere l&rsquo;importo complessivo corrisposto dal Cliente ad AR Consultant nei sei (06) mesi precedenti l&rsquo;evento che ha dato origine alla richiesta di risarcimento.</p>

            <h4>10) Sospensione per sicurezza e/o mancato pagamento</h4>
            <p>10.1 Il servizio potr&agrave; essere sospeso, revocato o limitato nel caso in cui: a) il Cliente si renda inadempiente o violi anche una soltanto delle disposizioni del presente Contratto e/o dei Termini del Produttore; b) il Cliente sia in ritardo con il pagamento dei corrispettivi dovuti; c) si verifichino violazioni di sicurezza, uso anomalo o circostanze che impongano interventi di emergenza; d) vi sia una violazione di legge o di regolamento; e) si verifichino casi di forza maggiore. In tali casi, il servizio sar&agrave; ripristinato alla cessazione dell&rsquo;evento, non appena possibile.</p>

            <h4>11) Durata, recesso</h4>
            <p>11.1 Il presente Contratto ha durata pari a quanto indicato nell&rsquo;Allegato A e si rinnova automaticamente per eguale periodo salvo disdetta del Cliente nei termini ivi indicati. AR Consultant potr&agrave; in qualsiasi momento recedere dal Contratto mediante comunicazione scritta da inviarsi con preavviso di almeno trenta (30) giorni mediante raccomandata A/R o a mezzo PEC.</p>
            <p>11.2 Alla cessazione del Contratto l&rsquo;accesso viene disattivato e il Cliente dovr&agrave; attivarsi tempestivamente per esportare i propri dati; AR Consultant non potr&agrave; essere ritenuta responsabile di eventuali perdite di dati conseguenti a mancata esportazione tempestiva.</p>

            <h4>12) Risoluzione</h4>
            <p>12.1 AR Consultant potr&agrave; risolvere il Contratto, ai sensi e per gli effetti di cui all&rsquo;art. 1456 c.c., anche per uno solo dei seguenti inadempimenti ritenuti gravi ed irreparabili: a) inadempimento totale o parziale agli obblighi di pagamento; b) attivit&agrave; illecita, fraudolenta e/o non conforme alle disposizioni nazionali vigenti tramite il servizio; c) violazione degli obblighi di riservatezza; d) cessione del Contratto senza consenso; e) iscrizione nell&rsquo;elenco dei protesti, insolvenza, o ammissione a procedura concorsuale; f) condizione di sospensione che perduri per oltre 30 giorni.</p>
            <p>12.2 L&rsquo;inadempimento, da parte del Cliente, anche di uno solo degli obblighi di pagamento previsti dal presente Contratto, ne comporter&agrave; la risoluzione di diritto, fatto salvo il risarcimento del danno.</p>

            <h4>13) Riservatezza</h4>
            <p>13.1 Le Parti garantiscono reciprocamente che il proprio personale tratter&agrave; come riservata ogni informazione di cui venissero a conoscenza durante od in relazione ad ogni attivit&agrave; inerente l&rsquo;esecuzione del Contratto, per tutta la sua durata, e si impegnano a non divulgarla in alcun modo. Gli obblighi di riservatezza restano validi anche dopo la cessazione del presente Contratto.</p>

            <h4>14) Forza maggiore</h4>
            <p>14.1 Nessuna Parte sar&agrave; responsabile per ritardi o inadempimenti dovuti a forza maggiore (es. eventi naturali, interruzioni massive della rete, attacchi generalizzati, provvedimenti autorit&agrave;), per la durata dell&rsquo;evento.</p>

            <h4>15) Legge applicabile e foro</h4>
            <p>15.1 Il Contratto &egrave; regolato dalla legge italiana.</p>
            <p>15.2 Le Parti di comune accordo, ai sensi dell&rsquo;art. 29 c.p.c., attribuiscono esclusiva competenza territoriale al Foro di Bologna per qualsiasi controversia dovesse insorgere in relazione al presente accordo.</p>

            <h4>16) Propriet&agrave; intellettuale e industriale</h4>
            <p>16.1 Il Cliente prende atto ed accetta che il presente Contratto non costituisce vendita o cessione di qualsiasi titolo di diritto di propriet&agrave; relativo al software, alla documentazione e ogni altro programma connesso alla piattaforma <?php echo $p; ?> o ulteriore diritto non esplicitamente trasferito.</p>
            <p>16.2 Il Cliente prende atto ed accetta che non ha facolt&agrave; di copiare, decompilare, eseguire operazioni di reverse engineering, disassemblare, tentare di ottenere il codice sorgente, modificare o tradurre il software, salvo i limiti inderogabili di legge.</p>

            <h4>17) Altri accordi</h4>
            <p>17.1 Tutte le condizioni, garanzie e dichiarazioni relative al rendimento, la qualit&agrave; o l&rsquo;idoneit&agrave; all&rsquo;uso del programma non previste dal presente accordo sono escluse.</p>
            <p>17.2 Il presente Contratto annulla e sostituisce ogni altra precedente intesa eventualmente intervenuta fra AR Consultant e il Cliente in ordine allo stesso oggetto e costituisce la manifestazione integrale degli accordi conclusi fra le Parti. Qualsiasi modificazione al presente Contratto dovr&agrave; risultare da atto scritto, firmato dalle Parti.</p>
        </div>

        <div class="sibia-contratto-warn">
            <strong>Ai sensi e per gli effetti degli artt. 1341 e 1342 c.c.</strong>, si approvano espressamente le condizioni e pattuizioni contenute nelle seguenti clausole: 2 (Attivazione, credenziali, uso consentito e manleva); 4 (Supporto e gestione ticket); 5 (Manutenzione, aggiornamenti e modifiche del Servizio); 6 (Garanzie); 8 (Corrispettivi, fatturazione); 9 (Limitazione di responsabilit&agrave;); 10 (Sospensione per sicurezza e/o mancato pagamento); 11 (Durata, recesso); 12 (Risoluzione); 13 (Riservatezza); 14 (Forza maggiore); 15 (Foro competente); 16 (Propriet&agrave; intellettuale e industriale).
        </div>

        <div class="sibia-contratto-gdpr">
            <strong>Trattamento dei dati personali.</strong> In ottemperanza al Regolamento (UE) 2016/679 (GDPR), il Cliente dichiara di aver preso visione dell&rsquo;informativa sul trattamento dei dati personali resa da AR Consultant ai sensi dell&rsquo;art. 13 GDPR. AR Consultant tratta i dati di contatto e fatturazione del Cliente in qualit&agrave; di titolare autonomo per finalit&agrave; connesse all&rsquo;esecuzione del presente Contratto. Il Cliente prende atto che i dati e i contenuti inseriti nella piattaforma <?php echo $p; ?> sono trattati dal Produttore secondo i Termini e Condizioni e la documentazione richiamati nell&rsquo;Allegato B.
        </div>

        <div class="sibia-contratto-footer">
            V. <?php echo esc_html($versione); ?> &mdash; A.R. Consultant S.r.l. &mdash; Via Giambologna n. 2, 40138 Bologna (BO) &mdash; P.IVA 03660611207
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('sibia_contratto_synchroteam', function () {
    return sibia_render_contratto(
        'Synchroteam',
        'https://www.synchroteam.com/terms-and-conditions.php',
        'https://support.synchroteam.com/hc/en-us',
        '3.0'
    );
});

add_shortcode('sibia_contratto_toursolver', function () {
    return sibia_render_contratto(
        'TourSolver',
        'https://www.nomadia-group.com/legal/',
        'https://help.nomadia.com/',
        '1.0'
    );
});

add_shortcode('sibia_contratto_delivery', function () {
    return sibia_render_contratto(
        'Delivery',
        'https://www.nomadia-group.com/legal/',
        'https://help.nomadia.com/',
        '1.0'
    );
});

add_shortcode('sibia_contratto_nomadia_protect', function () {
    return sibia_render_contratto(
        'Nomadia Protect',
        'https://www.nomadia-group.com/legal/',
        'https://help.nomadia.com/',
        '1.0'
    );
});

add_action('init', function () {
    if (get_transient('sibia_guide_page_created')) {
        return;
    }
    $page = get_page_by_path('guida-installazione-sync-picampipedrive');
    if (!$page) {
        wp_insert_post(array(
            'post_title'   => 'Guida Installazione Sync.PicamPipedrive',
            'post_name'    => 'guida-installazione-sync-picampipedrive',
            'post_content' => '[sibia_guida_installazione]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ));
    }
    set_transient('sibia_guide_page_created', true, DAY_IN_SECONDS);
});

add_shortcode('sibia_guida_installazione', function () {
    ob_start();
    ?>
    <div class="sibia-guide">
        <div class="sibia-guide__header">
            <img src="https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png" alt="SIBIA" />
            <h1>Guida Installazione Sync.PicamPipedrive</h1>
            <p>Segui questa guida passo-passo per installare e configurare il programma di sincronizzazione tra Picam7 e Pipedrive</p>
        </div>

        <!-- ===== STEP 1: Download ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">1</div>
            <h2>Scarica l'installer</h2>
            <p>Accedi al <strong>Portale SIBIA</strong> (Area Riservata) e vai nella sezione <strong>Soluzioni &rarr; Picam7 &#8596; Pipedrive</strong>.</p>
            <p>Clicca sul bottone <strong>&ldquo;Scarica Installer&rdquo;</strong>. Il file pesa circa <strong>75 MB</strong> e contiene tutto il necessario (incluso .NET Runtime).</p>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#128229;</div>
                <div class="sibia-guide__ph-title">Screenshot: Bottone &ldquo;Scarica Installer&rdquo; nel portale SIBIA</div>
                <div class="sibia-guide__ph-desc">Portale &rarr; Soluzioni &rarr; Picam7 &#8596; Pipedrive &rarr; Download Installer</div>
            </div>
        </div>

        <!-- ===== STEP 2: SmartScreen ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">2</div>
            <h2>Avviso Windows SmartScreen</h2>
            <p>Quando avvii il file scaricato, Windows potrebbe mostrare un avviso di sicurezza SmartScreen. Questo &egrave; <strong>normale e sicuro</strong>: il software non ha una firma digitale (che richiede un certificato costoso), ma &egrave; completamente affidabile.</p>
            <div class="sibia-guide__warning">
                <strong>&#9888;&#65039; Cosa fare se appare l'avviso</strong>
                <p>1. Clicca su <strong>&ldquo;Ulteriori informazioni&rdquo;</strong> (o &ldquo;More info&rdquo;)<br />
                2. Poi clicca su <strong>&ldquo;Esegui comunque&rdquo;</strong> (o &ldquo;Run anyway&rdquo;)</p>
            </div>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#128737;&#65039;</div>
                <div class="sibia-guide__ph-title">Screenshot: Avviso Windows SmartScreen</div>
                <div class="sibia-guide__ph-desc">Finestra blu con &ldquo;Ulteriori informazioni&rdquo; e poi &ldquo;Esegui comunque&rdquo;</div>
            </div>
        </div>

        <!-- ===== STEP 3: Esegui come Amministratore ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">3</div>
            <h2>Esegui come Amministratore</h2>
            <p>Per installare correttamente il servizio Windows, &egrave; necessario eseguire l'installer con privilegi di amministratore.</p>
            <ol>
                <li>Trova il file scaricato: <strong>SyncPicamPipedrive_Setup.exe</strong></li>
                <li>Fai <strong>clic destro</strong> sul file</li>
                <li>Seleziona <strong>&ldquo;Esegui come amministratore&rdquo;</strong></li>
                <li>Se richiesto, conferma nella finestra UAC (Controllo Account Utente)</li>
            </ol>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#128736;&#65039;</div>
                <div class="sibia-guide__ph-title">Screenshot: Menu contestuale &ldquo;Esegui come amministratore&rdquo;</div>
                <div class="sibia-guide__ph-desc">Tasto destro sul file &rarr; Esegui come amministratore (evidenziato)</div>
            </div>
        </div>

        <!-- ===== STEP 4: Installazione ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">4</div>
            <h2>Installazione automatica</h2>
            <p>L'installer proceder&agrave; automaticamente. Verranno installati due componenti:</p>
            <ol>
                <li><strong>Servizio Windows</strong> (Sync.PicamPipedrive.Service) &mdash; esegue la sincronizzazione in background, si avvia automaticamente con Windows</li>
                <li><strong>Tray Application</strong> (icona nella barra di sistema) &mdash; per gestire il servizio, generare il token e configurare le credenziali</li>
            </ol>
            <p>L'installazione dura circa <strong>10-20 secondi</strong>. Al termine vedrai una conferma di completamento.</p>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#9889;</div>
                <div class="sibia-guide__ph-title">Screenshot: Barra di progresso dell'installer</div>
                <div class="sibia-guide__ph-desc">Finestra Inno Setup con barra verde di avanzamento installazione</div>
            </div>
        </div>

        <!-- ===== STEP 5: System Tray ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">5</div>
            <h2>Trova l'icona SIBIA nella System Tray</h2>
            <p>Al termine dell'installazione, cerca l'icona <strong>SIBIA</strong> nella barra di sistema (system tray) in basso a destra, accanto all'orologio di Windows.</p>
            <div class="sibia-guide__warning">
                <strong>&#128269; Icona non visibile?</strong>
                <p>Clicca sulla <strong>freccia &ldquo;^&rdquo;</strong> nella barra delle applicazioni per espandere le icone nascoste. L'icona SIBIA potrebbe trovarsi l&igrave;.</p>
            </div>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#128421;&#65039;</div>
                <div class="sibia-guide__ph-title">Screenshot: Icona SIBIA nella System Tray</div>
                <div class="sibia-guide__ph-desc">Barra delle applicazioni Windows &rarr; System tray con icona SIBIA evidenziata (freccia ^ per icone nascoste)</div>
            </div>
        </div>

        <!-- ===== STEP 6: Menu Tray ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">6</div>
            <h2>Menu Tray &mdash; Funzionalit&agrave;</h2>
            <p>Fai <strong>clic destro</strong> sull'icona SIBIA nella system tray. Apparir&agrave; un menu con le seguenti voci:</p>
            <div class="sibia-guide__menu">
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#128196;</div>
                    <div>
                        <strong>Apri Log</strong>
                        <p>Apre il file di log del servizio (<code>service-error.log</code>) in Notepad. Utile per diagnosticare eventuali errori di sincronizzazione.</p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#128465;</div>
                    <div>
                        <strong>Pulisci Log</strong>
                        <p>Svuota il file di log. Utile per ripartire da zero dopo aver risolto un problema.</p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#9881;&#65039;</div>
                    <div>
                        <strong>Configura Servizio</strong> <em>(in grassetto nel menu)</em>
                        <p>Apre la finestra di <strong>configurazione</strong> dove inserire la API Key SIBIA (ricevuta dal portale), selezionare la ditta Picam7, e salvare le impostazioni. Dettagli nello step 8.</p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#9654;</div>
                    <div>
                        <strong>Avvia Servizio</strong>
                        <p>Avvia il servizio Windows di sincronizzazione. Questa voce &egrave; <strong>disabilitata</strong> se il servizio &egrave; gi&agrave; in esecuzione.</p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#9209;</div>
                    <div>
                        <strong>Ferma Servizio</strong>
                        <p>Ferma il servizio Windows di sincronizzazione. Questa voce &egrave; <strong>disabilitata</strong> se il servizio &egrave; gi&agrave; fermo.</p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#128683;</div>
                    <div>
                        <strong>Disinstalla Servizio</strong>
                        <p>Rimuove completamente il servizio Windows dal sistema (con richiesta di conferma). Attenzione: questa operazione &egrave; irreversibile.</p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#128202;</div>
                    <div>
                        <strong>Stato</strong>
                        <p>Mostra lo <strong>stato della sincronizzazione</strong>: stato del servizio (in esecuzione/fermo), configurazione, data/ora dell'ultimo aggiornamento, e conteggio di organizzazioni e contatti sincronizzati.</p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#128273;</div>
                    <div>
                        <strong>Genera token</strong>
                        <p>Copia il <strong>token di installazione</strong> negli appunti (clipboard). Il token identifica univocamente questa installazione nel sistema SIBIA. Ha il formato: <code>MACHINE-XXXX-XXXX-XXXX-XXXX</code></p>
                    </div>
                </div>
                <div class="sibia-guide__menu-item">
                    <div class="sibia-guide__menu-icon">&#10060;</div>
                    <div>
                        <strong>Esci</strong>
                        <p>Chiude l'applicazione tray (l'icona scompare). Il <strong>servizio Windows continua a funzionare</strong> in background &mdash; la sincronizzazione non si interrompe.</p>
                    </div>
                </div>
            </div>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#128433;&#65039;</div>
                <div class="sibia-guide__ph-title">Screenshot: Menu contestuale tray (tasto destro)</div>
                <div class="sibia-guide__ph-desc">Menu completo con 9 voci: Apri Log, Pulisci Log, Configura Servizio (grassetto), Avvia/Ferma/Disinstalla Servizio, Stato, Genera token, Esci</div>
            </div>
        </div>

        <!-- ===== STEP 7: Copia Token ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">7</div>
            <h2>Copia il Token</h2>
            <p>Fai clic destro sull'icona SIBIA e seleziona <strong>&ldquo;Genera token&rdquo;</strong> dal menu.</p>
            <p>Il token verr&agrave; <strong>copiato automaticamente negli appunti</strong> (come quando fai Ctrl+C). Vedrai una notifica di conferma.</p>
            <div class="sibia-guide__info">
                <strong>&#128161; A cosa serve il token?</strong>
                <p>Il token identifica la tua installazione e collega il programma Sync.PicamPipedrive al tuo account sul portale SIBIA. Dovrai incollarlo nel portale (Step 9).</p>
            </div>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#128203;</div>
                <div class="sibia-guide__ph-title">Screenshot: Notifica &ldquo;Token copiato negli appunti&rdquo;</div>
                <div class="sibia-guide__ph-desc">Notifica Windows che conferma la copia del token</div>
            </div>
        </div>

        <!-- ===== STEP 8: Configura API Key ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">8</div>
            <h2>Configura la API Key SIBIA</h2>
            <p>Fai clic destro sull'icona SIBIA e seleziona <strong>&ldquo;Configura Servizio&rdquo;</strong> (la voce in grassetto nel menu). Si aprir&agrave; la finestra di configurazione con tre sezioni:</p>
            <ol>
                <li><strong>API Key SIBIA</strong> &mdash; Incolla qui la chiave API che riceverai dal portale SIBIA (formato: <code>SIBIA-xxxxxxxx</code>). Questa chiave autentica il programma con il server SIBIA.</li>
                <li><strong>Ditta Picam7</strong> &mdash; Seleziona dal menu a tendina la ditta da sincronizzare. Le ditte vengono lette automaticamente dal database Picam7 installato sul computer.</li>
                <li><strong>Ragione Sociale</strong> &mdash; Campo di sola lettura che mostra la ragione sociale registrata durante l'onboarding sul portale.</li>
            </ol>
            <p>Dopo aver compilato i campi, clicca su <strong>&ldquo;Salva&rdquo;</strong>. Il programma verificher&agrave; la connessione e salver&agrave; la configurazione.</p>
            <div class="sibia-guide__info">
                <strong>&#128274; Sicurezza</strong>
                <p>La API Key viene salvata in modo crittografato (DPAPI Windows) sul computer locale. Solo gli amministratori del sistema possono accedere al file di configurazione.</p>
            </div>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#9881;&#65039;</div>
                <div class="sibia-guide__ph-title">Screenshot: Finestra &ldquo;Configura Servizio&rdquo;</div>
                <div class="sibia-guide__ph-desc">Finestra con campi: API Key SIBIA, Ditta Picam7 (dropdown), Ragione Sociale (read-only), e bottone Salva</div>
            </div>
        </div>

        <!-- ===== STEP 9: Portale ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">9</div>
            <h2>Completa la configurazione sul portale</h2>
            <p>Torna al <strong>Portale SIBIA</strong> (Area Riservata) e vai in <strong>Soluzioni &rarr; Picam7 &#8596; Pipedrive</strong>:</p>
            <ol>
                <li>Nel campo <strong>&ldquo;Token Sync.PicamPipedrive&rdquo;</strong>, incolla il token copiato nello Step 7 (Ctrl+V)</li>
                <li>Inserisci la tua <strong>API key Pipedrive</strong> (la trovi in Pipedrive &rarr; Impostazioni &rarr; Accesso API)</li>
                <li>Clicca su <strong>&ldquo;Salva configurazione&rdquo;</strong></li>
            </ol>
            <p>Il portale generer&agrave; una <strong>API Key SIBIA</strong> che dovrai inserire nel programma (Step 8, campo &ldquo;API Key SIBIA&rdquo;).</p>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#127760;</div>
                <div class="sibia-guide__ph-title">Screenshot: Form &ldquo;Dati collegamento&rdquo; nel portale</div>
                <div class="sibia-guide__ph-desc">Portale SIBIA &rarr; Soluzioni &rarr; Picam7 &#8596; Pipedrive &rarr; Campi Token e API key Pipedrive</div>
            </div>
        </div>

        <!-- ===== STEP 10: Verifica ===== -->
        <div class="sibia-guide__step">
            <div class="sibia-guide__step-num">10</div>
            <h2>Verifica il funzionamento</h2>
            <p>Dopo aver completato la configurazione su entrambi i lati (programma + portale), puoi verificare che tutto funzioni:</p>
            <ol>
                <li><strong>Sul programma</strong>: fai clic destro sull'icona SIBIA &rarr; <strong>&ldquo;Stato&rdquo;</strong> per vedere se la configurazione &egrave; OK e i dati dell'ultimo aggiornamento</li>
                <li><strong>Sul portale</strong>: nella sezione Picam7 &#8596; Pipedrive, controlla il riquadro <strong>&ldquo;Stato sincronizzazione&rdquo;</strong> con ditta, ultimo run e numero record sincronizzati</li>
            </ol>
            <p>La sincronizzazione avviene <strong>automaticamente ogni ora</strong>. Non &egrave; necessario alcun intervento manuale dopo la configurazione iniziale.</p>
            <div class="sibia-guide__placeholder">
                <div class="sibia-guide__ph-icon">&#9989;</div>
                <div class="sibia-guide__ph-title">Screenshot: Stato sincronizzazione nel portale</div>
                <div class="sibia-guide__ph-desc">Riquadro con: Ditta Picam7, Ultimo aggiornamento, Dati sincronizzati (N record)</div>
            </div>
        </div>

        <div class="sibia-guide__footer">
            <p><strong>Hai bisogno di aiuto?</strong></p>
            <p>Contatta il supporto SIBIA: <a href="mailto:supporto@sibia.it">supporto@sibia.it</a></p>
            <p style="margin-top: 16px; font-size: 13px;">&copy; 2026 SIBIA &mdash; Tutti i diritti riservati</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

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
 */
function sibia_notifica_rinnovo_fallito($user, $servizio)
{
    $siteUrl   = get_bloginfo('url');
    $adminMail = get_option('admin_email');
    $data      = date_i18n('d/m/Y H:i');
    $headers   = ['Content-Type: text/html; charset=UTF-8'];

    // Email admin
    $adminCorpo = '<p>Il rinnovo automatico per il seguente utente è fallito:</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:12px 0;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Utente</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Servizio</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($servizio) . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Data</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($data) . '</td></tr>'
        . '</table>'
        . '<p>La sincronizzazione è stata sospesa fino al rinnovo del pagamento.</p>';
    wp_mail(
        $adminMail,
        '[SIBIA] Rinnovo fallito — ' . $user->user_email,
        sibia_email_html('Rinnovo fallito — ' . $servizio, $adminCorpo),
        $headers
    );

    // Email utente
    $utenteCorpo = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
        . '<p>non siamo riusciti a rinnovare il tuo abbonamento al servizio <strong>' . esc_html($servizio) . '</strong>.</p>'
        . '<p>La sincronizzazione è stata temporaneamente sospesa.</p>'
        . '<p>Per riattivare il servizio accedi al portale e aggiorna il metodo di pagamento.</p>';
    wp_mail(
        $user->user_email,
        'Il tuo abbonamento richiede attenzione — SIBIA',
        sibia_email_html('Abbonamento — azione richiesta', $utenteCorpo, $siteUrl, 'Accedi al portale'),
        $headers
    );
}

/**
 * Email abbonamento attivato: inviata a utente e admin quando viene creata una nuova subscription.
 */
function sibia_email_abbonamento_attivato($user, $servizio, $piano, $intervallo)
{
    $siteUrl         = get_bloginfo('url');
    $adminMail       = get_option('admin_email');
    $pianoLabel      = $piano === 'professional' ? 'Professional' : 'Standard';
    $intervalloLabel = $intervallo === 'annuale' ? 'Annuale' : 'Mensile';
    $headers         = ['Content-Type: text/html; charset=UTF-8'];

    // Email utente
    $corpoUtente = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
        . '<p>il tuo abbonamento al servizio <strong>' . esc_html($servizio) . '</strong> è attivo.</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:12px 0;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Piano</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</td></tr>'
        . '</table>'
        . '<p>Accedi al portale per configurare il servizio.</p>';
    wp_mail(
        $user->user_email,
        'Abbonamento attivato — SIBIA',
        sibia_email_html('Abbonamento attivato', $corpoUtente, $siteUrl, 'Accedi al portale'),
        $headers
    );

    // Email admin
    $corpoAdmin = '<p>Nuovo abbonamento attivato:</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:12px 0;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Utente</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Servizio</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($servizio) . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Piano</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</td></tr>'
        . '</table>';
    wp_mail(
        $adminMail,
        '[SIBIA] Nuovo abbonamento — ' . $user->user_email,
        sibia_email_html('Nuovo abbonamento — ' . $servizio, $corpoAdmin),
        $headers
    );
}

/**
 * Email ricevuta rinnovo: inviata a utente e admin ad ogni rinnovo periodico riuscito.
 */
function sibia_email_ricevuta_rinnovo($user, $servizio, $piano, $intervallo)
{
    $siteUrl         = get_bloginfo('url');
    $adminMail       = get_option('admin_email');
    $pianoLabel      = $piano === 'professional' ? 'Professional' : 'Standard';
    $intervalloLabel = $intervallo === 'annuale' ? 'Annuale' : 'Mensile';
    $data            = date_i18n('d/m/Y H:i');
    $headers         = ['Content-Type: text/html; charset=UTF-8'];

    // Email utente
    $corpoUtente = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
        . '<p>il rinnovo del tuo abbonamento al servizio <strong>' . esc_html($servizio) . '</strong> è andato a buon fine.</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:12px 0;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Piano</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Data</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($data) . '</td></tr>'
        . '</table>'
        . '<p>Il servizio continua senza interruzioni.</p>';
    wp_mail(
        $user->user_email,
        'Rinnovo abbonamento confermato — SIBIA',
        sibia_email_html('Rinnovo confermato', $corpoUtente, $siteUrl, 'Accedi al portale'),
        $headers
    );

    // Email admin
    $corpoAdmin = '<p>Rinnovo abbonamento ricevuto:</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:12px 0;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Utente</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Servizio</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($servizio) . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Piano</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Data</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($data) . '</td></tr>'
        . '</table>';
    wp_mail(
        $adminMail,
        '[SIBIA] Rinnovo ricevuto — ' . $user->user_email,
        sibia_email_html('Rinnovo abbonamento — ' . $servizio, $corpoAdmin),
        $headers
    );
}

/**
 * Email abbonamento disdetto: inviata a utente e admin quando un abbonamento viene cancellato volontariamente.
 */
function sibia_email_abbonamento_disdetto($user, $servizio)
{
    $adminMail = get_option('admin_email');
    $data      = date_i18n('d/m/Y H:i');
    $headers   = ['Content-Type: text/html; charset=UTF-8'];

    // Email utente
    $corpoUtente = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
        . '<p>abbiamo ricevuto la tua richiesta di disdetta per il servizio <strong>' . esc_html($servizio) . '</strong>.</p>'
        . '<p>Il tuo abbonamento è stato cancellato e la sincronizzazione è stata sospesa.</p>'
        . '<p>Se si tratta di un errore o desideri riattivare il servizio, puoi farlo in qualsiasi momento dal portale.</p>';
    wp_mail(
        $user->user_email,
        'Abbonamento disdetto — SIBIA',
        sibia_email_html('Abbonamento disdetto', $corpoUtente),
        $headers
    );

    // Email admin
    $corpoAdmin = '<p>Un utente ha disdetto l\'abbonamento:</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:12px 0;">'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Utente</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Servizio</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($servizio) . '</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600;background:#f9f9f9;">Data</td>'
        . '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html($data) . '</td></tr>'
        . '</table>';
    wp_mail(
        $adminMail,
        '[SIBIA] Abbonamento disdetto — ' . $user->user_email,
        sibia_email_html('Abbonamento disdetto — ' . $servizio, $corpoAdmin),
        $headers
    );
}

/**
 * Email di conferma cambio piano all'utente.
 */
function sibia_email_conferma_cambio_piano($user, $piano, $intervallo, $tierUp)
{
    $siteUrl        = get_bloginfo('url');
    $pianoLabel     = $piano === 'professional' ? 'Professional' : 'Standard';
    $intervalloLabel = $intervallo === 'annuale' ? 'Annuale' : 'Mensile';

    if ($tierUp) {
        $corpoHtml = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
            . '<p>il tuo cambio al piano <strong>' . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</strong> è stato confermato.</p>'
            . '<p><strong>Le nuove funzionalità sono attive da subito</strong> (sincronizzazione articoli, intervallo di sincronizzazione ridotto).</p>'
            . '<p>Il nuovo piano entrerà in vigore a livello di fatturazione al prossimo rinnovo naturale del tuo abbonamento corrente.</p>';
    } else {
        $corpoHtml = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
            . '<p>il tuo passaggio al piano <strong>' . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</strong> è stato registrato.</p>'
            . '<p>Il cambio diventerà effettivo alla <strong>scadenza del tuo abbonamento corrente</strong>. Riceverai una email con le istruzioni per completare il passaggio.</p>'
            . '<p>Fino alla scadenza continuerai a usufruire del tuo piano attuale.</p>';
    }

    wp_mail(
        $user->user_email,
        'Cambio piano confermato — SIBIA',
        sibia_email_html('Cambio piano', $corpoHtml, $siteUrl, 'Accedi al portale'),
        ['Content-Type: text/html; charset=UTF-8']
    );
}

/**
 * Email inviata alla scadenza del piano corrente: link al checkout del nuovo piano schedulato.
 */
function sibia_email_transizione_piano($user, $nuovoPiano, $nuovoIntervallo, $servizio)
{
    $siteUrl         = get_bloginfo('url');
    $pianoLabel      = $nuovoPiano === 'professional' ? 'Professional' : 'Standard';
    $intervalloLabel = $nuovoIntervallo === 'annuale' ? 'Annuale' : 'Mensile';

    // Ricava URL checkout del nuovo piano
    $checkoutUrl = $siteUrl;
    if ($servizio === 'SynchToFic') {
        $piani = sibia_get_sytfic_piani_mepr();
        $pid   = $piani[$nuovoPiano][$nuovoIntervallo] ?? 0;
        if ($pid) $checkoutUrl = esc_url(get_permalink($pid));
    }

    $corpoHtml = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
        . '<p>il tuo abbonamento precedente è scaduto. Ora puoi attivare il nuovo piano <strong>'
        . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</strong>.</p>'
        . '<p>Clicca sul bottone qui sotto per completare l\'attivazione:</p>';

    wp_mail(
        $user->user_email,
        'Il tuo nuovo piano è pronto — SIBIA',
        sibia_email_html('Attiva il tuo nuovo piano', $corpoHtml, $checkoutUrl,
            'Attiva ' . $pianoLabel . ' ' . $intervalloLabel),
        ['Content-Type: text/html; charset=UTF-8']
    );
}

/**
 * Verifica se l'utente ha almeno una subscription MemberPress attiva per il servizio.
 * $excludeSubId: ID della subscription da escludere dal controllo (quella appena cancellata).
 */
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

register_deactivation_hook(__FILE__, function () {
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

/* ============================================================
   REGISTRAZIONE — Email di benvenuto / verifica brandizzata
   ============================================================ */

// Trasforma l'email WP di verifica in email branded SIBIA
add_filter('wp_new_user_notification_email', function ($email_data, $user, $blogname) {
    // Estrai il link di attivazione/set-password dal messaggio originale
    $activationLink = '';
    if (preg_match('/(https?:\/\/\S+wp-login\.php\S*)/i', $email_data['message'], $m)) {
        $activationLink = $m[1];
    }

    $nome  = !empty($user->first_name) ? $user->first_name : $user->display_name;

    $corpo = '
        <p>Ciao <strong>' . esc_html($nome) . '</strong>,</p>
        <p>Benvenuto su <strong>SIBIA</strong>! Il tuo account è stato creato con successo.</p>
        <p>Per completare la registrazione e impostare la tua password, clicca sul pulsante qui sotto:</p>
    ';

    $email_data['subject'] = 'Benvenuto su SIBIA — conferma il tuo indirizzo email';
    $email_data['message'] = sibia_email_html(
        'Benvenuto su SIBIA',
        $corpo,
        $activationLink ?: home_url(),
        'Imposta la tua password'
    );
    $email_data['headers'] = ['Content-Type: text/html; charset=UTF-8'];

    return $email_data;
}, 10, 3);

// Dopo la registrazione, redirect alla pagina di login con param checkemail
add_filter('registration_redirect', function ($redirect_to) {
    return add_query_arg('checkemail', 'registered', wp_login_url());
});

// Messaggio di conferma registrazione in italiano
add_filter('login_message', function ($message) {
    if (isset($_GET['checkemail']) && $_GET['checkemail'] === 'registered') {
        return '<p class="message register">' .
            'La tua registrazione è avvenuta con successo. Controlla la tua email per completarla.' .
            '</p>';
    }
    if (isset($_GET['sibia_reg']) && $_GET['sibia_reg'] === 'ok') {
        return '<p class="message register">' .
            'Account creato con successo! Puoi accedere ora.' .
            '</p>';
    }
    return $message;
});


/* ========================================================================
   SHORTCODE [sibia_registrazione] — Form di registrazione SIBIA
   ======================================================================== */

add_shortcode('sibia_registrazione', function () {
    // ── Email verificata con successo (redirect da template_redirect dopo GET token) ──
    if (isset($_GET['sibia_ok']) && $_GET['sibia_ok'] === '1') {
        ob_start();
        ?>
        <div class="sibia-onboarding" style="max-width:480px;margin:0 auto;">
            <div class="sibia-step" style="text-align:center;padding:40px 24px;">
                <div style="font-size:48px;margin-bottom:16px;">✅</div>
                <p style="font-size:20px;font-weight:700;color:var(--sibia-ink,#1c2b3a);margin:0 0 12px;">Email verificata!</p>
                <p style="color:var(--sibia-muted,#61758b);margin:0 0 28px;">Il tuo account è attivo. Puoi ora accedere al portale.</p>
                <a href="<?php echo esc_url(wp_login_url(home_url('/account/'))); ?>"
                   style="display:inline-block;background:var(--sibia-accent,#2563eb);color:#fff;
                          text-decoration:none;padding:14px 36px;border-radius:8px;
                          font-size:16px;font-weight:700;">
                    Accedi a SIBIA
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Token non valido o già usato ──
    if (isset($_GET['sibia_verifica_err'])) {
        ob_start();
        ?>
        <div class="sibia-onboarding" style="max-width:480px;margin:0 auto;">
            <div class="sibia-step" style="text-align:center;padding:40px 24px;">
                <div style="font-size:48px;margin-bottom:16px;">✅</div>
                <p style="font-size:18px;font-weight:700;color:var(--sibia-ink,#1c2b3a);margin:0 0 12px;">Link già utilizzato o scaduto</p>
                <p style="color:var(--sibia-muted,#61758b);margin:0 0 24px;">Se hai già confermato il tuo indirizzo, puoi accedere normalmente.<br>Se non hai ancora un account attivo, registrati nuovamente.</p>
                <a href="<?php echo esc_url(wp_login_url(home_url('/account/'))); ?>"
                   style="display:inline-block;background:var(--sibia-accent,#2563eb);color:#fff;
                          text-decoration:none;padding:14px 36px;border-radius:8px;
                          font-size:16px;font-weight:700;margin-bottom:12px;">
                    Accedi a SIBIA
                </a>
                <br>
                <a href="<?php echo esc_url(get_permalink()); ?>"
                   style="color:var(--sibia-muted,#61758b);font-size:14px;">
                    Vai alla registrazione
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Se già loggato, rimanda al portale
    if (is_user_logged_in()) {
        $page = get_page_by_path('area-riservata');
        $url  = $page ? get_permalink($page->ID) : home_url('/');
        wp_redirect($url);
        exit;
    }

    $errors  = [];
    $email   = '';
    $success = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sibia_reg_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sibia_reg_nonce'])), 'sibia_registrazione')) {
            $errors[] = 'Richiesta non valida. Riprova.';
        } else {
            $email     = sanitize_email(wp_unslash($_POST['sibia_reg_email'] ?? ''));
            $password  = wp_unslash($_POST['sibia_reg_password'] ?? '');
            $password2 = wp_unslash($_POST['sibia_reg_password2'] ?? '');
            $privacy   = !empty($_POST['sibia_reg_privacy']);

            if (empty($email) || !is_email($email)) {
                $errors[] = 'Inserisci un indirizzo email valido.';
            }
            if (empty($password) || strlen($password) < 8) {
                $errors[] = 'La password deve essere di almeno 8 caratteri.';
            }
            if ($password !== $password2) {
                $errors[] = 'Le password non coincidono.';
            }
            if (!$privacy) {
                $errors[] = 'Devi accettare la Privacy Policy per procedere.';
            }

            if (empty($errors)) {
                if (email_exists($email)) {
                    $errors[] = 'Questo indirizzo email è già registrato. <a href="' . esc_url(wp_login_url()) . '">Accedi</a>';
                } else {
                    // Sopprime la mail di verifica MemberPress (oggetto noto) durante la nostra registrazione.
                    // Il filtro viene rimosso subito dopo wp_create_user() per non interferire con altro.
                    $sibia_suppress_fn = function ($args) {
                        if (isset($args['subject']) &&
                            stripos($args['subject'], 'Verifica il tuo indirizzo email') !== false) {
                            $args['to'] = 'noreply@void.invalid';
                        }
                        return $args;
                    };
                    add_filter('wp_mail', $sibia_suppress_fn, 1);
                    $user_id = wp_create_user($email, $password, $email);
                    remove_filter('wp_mail', $sibia_suppress_fn, 1);

                    if (is_wp_error($user_id)) {
                        $errors[] = 'Errore durante la registrazione: ' . esc_html($user_id->get_error_message());
                    } else {
                        wp_update_user(['ID' => $user_id, 'display_name' => strstr($email, '@', true)]);

                        // L'utente rimane Unverified (user_activation_key impostato da MemberPress).
                        // Diventa Verified solo quando clicca il bottone nell'email di verifica.

                        // Genera token di verifica (valido 48 ore) — wp_options per persistenza
                        // anche con cache esterna (Redis/Memcached).
                        $token = wp_generate_password(40, false);
                        update_option('sibia_ev_' . $token,
                            ['uid' => $user_id, 'exp' => time() + 48 * HOUR_IN_SECONDS], false);

                        // URL di verifica: usa home_url — il token viene processato
                        // dall'hook 'init' su qualsiasi URL, senza dipendere dallo slug.
                        $verificaUrl = home_url('/?sibia_verifica=' . $token);

                        // Email di verifica: un solo click → account Verified
                        $corpo = '
                            <p>Benvenuto su <strong>SIBIA</strong>!</p>
                            <p>Clicca il bottone qui sotto per confermare il tuo indirizzo email e attivare l\'account.</p>
                            <p style="font-size:13px;color:#888888;">Il link è valido per 48 ore.</p>
                        ';
                        $htmlBody = sibia_email_html(
                            'Conferma il tuo indirizzo email',
                            $corpo,
                            $verificaUrl,
                            'Conferma il tuo indirizzo'
                        );
                        wp_mail(
                            $email,
                            'Conferma la registrazione su SIBIA',
                            $htmlBody,
                            ['Content-Type: text/html; charset=UTF-8']
                        );

                        $success = true;
                    }
                }
            }
        }
    }

    $logo_url    = 'https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png';
    $privacy_url = 'https://sibia.it/privacy-policy/';

    ob_start();
    ?>
    <div class="sibia-onboarding" style="max-width:480px;margin:0 auto;">
        <div class="sibia-hero" style="text-align:center;margin-bottom:24px;">
            <img src="<?php echo esc_url($logo_url); ?>" alt="SIBIA" style="width:54px;height:54px;border-radius:50%;margin-bottom:12px;">
            <h1 style="font-family:'Raleway',sans-serif;font-size:26px;font-weight:700;color:var(--sibia-ink,#1c2b3a);margin:0 0 6px;">Registrati su SIBIA</h1>
            <p style="color:var(--sibia-muted,#61758b);margin:0;">Crea il tuo account gratuito</p>
        </div>

        <?php if ($success) : ?>
            <div class="sibia-step" style="text-align:center;padding:32px 24px;">
                <div style="font-size:40px;margin-bottom:16px;">✉️</div>
                <p style="font-size:18px;font-weight:700;color:var(--sibia-ink,#1c2b3a);margin:0 0 12px;">Controlla la tua email</p>
                <p style="color:var(--sibia-muted,#61758b);margin:0;">Ti abbiamo inviato un'email con il bottone <strong>Conferma il tuo indirizzo</strong>.<br>Cliccalo per attivare l'account.</p>
            </div>
        <?php else : ?>

        <?php if (!empty($errors)) : ?>
            <div class="sibia-panel sibia-panel--error" style="margin-bottom:20px;">
                <?php foreach ($errors as $e) : ?>
                    <p style="margin:0 0 4px;"><?php echo wp_kses($e, ['a' => ['href' => []]]); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="sibia-step">
            <form method="post" action="">
                <?php wp_nonce_field('sibia_registrazione', 'sibia_reg_nonce'); ?>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--sibia-ink,#1c2b3a);">Indirizzo email</label>
                    <input type="email" name="sibia_reg_email"
                           value="<?php echo esc_attr($email); ?>"
                           placeholder="nome@azienda.it"
                           required
                           style="width:100%;padding:10px 14px;border:1px solid var(--sibia-border,#d6e1ee);border-radius:8px;font-size:15px;font-family:'DM Sans',sans-serif;box-sizing:border-box;">
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--sibia-ink,#1c2b3a);">Password</label>
                    <input type="password" name="sibia_reg_password" id="sibia_reg_pw1"
                           placeholder="Minimo 8 caratteri"
                           required minlength="8"
                           style="width:100%;padding:10px 14px;border:1px solid var(--sibia-border,#d6e1ee);border-radius:8px;font-size:15px;font-family:'DM Sans',sans-serif;box-sizing:border-box;">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--sibia-ink,#1c2b3a);">Ripeti password</label>
                    <input type="password" name="sibia_reg_password2" id="sibia_reg_pw2"
                           placeholder="Ripeti la password"
                           required
                           style="width:100%;padding:10px 14px;border:1px solid var(--sibia-border,#d6e1ee);border-radius:8px;font-size:15px;font-family:'DM Sans',sans-serif;box-sizing:border-box;">
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;color:var(--sibia-muted,#61758b);">
                        <input type="checkbox" name="sibia_reg_privacy" id="sibia_reg_privacy" value="1" style="flex-shrink:0;">
                        <span>Ho letto e accetto la <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" style="color:var(--sibia-blue,#1f5fa6);">Privacy Policy</a></span>
                    </label>
                </div>

                <button type="submit" id="sibia_reg_submit" class="sibia-btn" style="width:100%;justify-content:center;opacity:0.45;cursor:not-allowed;" disabled>
                    Crea account
                </button>

                <p style="text-align:center;margin-top:16px;font-size:14px;color:var(--sibia-muted,#61758b);">
                    Hai già un account? <a href="<?php echo esc_url(site_url('wp-login.php')); ?>" style="color:var(--sibia-blue,#1f5fa6);">Accedi</a>
                </p>
            </form>
        </div>

        <?php endif; // end else (not success, not verifica_err) ?>
    </div>
    <script>
    (function(){
        var cb   = document.getElementById('sibia_reg_privacy');
        var btn  = document.getElementById('sibia_reg_submit');
        var pw1  = document.getElementById('sibia_reg_pw1');
        var pw2  = document.getElementById('sibia_reg_pw2');
        if (!cb || !btn) return;

        // Mostra/nasconde messaggio di errore inline sotto un campo.
        function setFieldError(field, msg) {
            var errId = field.id + '_err';
            var existing = document.getElementById(errId);
            if (msg) {
                if (!existing) {
                    var e = document.createElement('p');
                    e.id = errId;
                    e.style.cssText = 'color:#dc2626;font-size:13px;margin:4px 0 0;';
                    field.parentNode.appendChild(e);
                    existing = e;
                }
                existing.textContent = msg;
                existing.style.display = 'block';
            } else if (existing) {
                existing.style.display = 'none';
            }
        }

        // Abilita/disabilita il pulsante in base al checkbox privacy.
        cb.addEventListener('change', function(){
            btn.disabled = !cb.checked;
            btn.style.opacity = cb.checked ? '1' : '0.45';
            btn.style.cursor  = cb.checked ? 'pointer' : 'not-allowed';
        });

        // Validazione lunghezza password al blur del primo campo.
        if (pw1) {
            pw1.addEventListener('blur', function(){
                if (pw1.value.length > 0 && pw1.value.length < 8) {
                    setFieldError(pw1, 'La password deve essere di almeno 8 caratteri.');
                } else {
                    setFieldError(pw1, '');
                }
            });
        }

        // Validazione al submit: blocca se password < 8 o le due non coincidono.
        // btn.form è più robusto di btn.closest('form') per la compatibilità cross-browser.
        var theForm = btn.form || btn.closest('form');
        if (!theForm) return;
        theForm.addEventListener('submit', function(e){
            var ok = true;
            if (pw1 && pw1.value.length < 8) {
                setFieldError(pw1, 'La password deve essere di almeno 8 caratteri.');
                pw1.focus();
                ok = false;
            } else if (pw1) {
                setFieldError(pw1, '');
            }
            if (ok && pw2 && pw1 && pw2.value !== pw1.value) {
                setFieldError(pw2, 'Le password non coincidono.');
                pw2.focus();
                ok = false;
            } else if (pw2) {
                setFieldError(pw2, '');
            }
            if (!ok) e.preventDefault();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

// Auto-creazione pagina /registrazione/ con shortcode SIBIA
add_action('init', function () {
    if (get_transient('sibia_registrazione_page_v1')) return;
    if (!get_page_by_path('registrazione')) {
        wp_insert_post([
            'post_title'   => 'Registrazione',
            'post_name'    => 'registrazione',
            'post_content' => '[sibia_registrazione]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);
    }
    set_transient('sibia_registrazione_page_v1', true, DAY_IN_SECONDS);
});

/* ========================================================================
   SHORTCODE [sibia_prezzi] — Pagina pubblica prezzi e piani
   Uso: inserire [sibia_prezzi] in una pagina WordPress (es. /prezzi/)
   ======================================================================== */

add_shortcode('sibia_prezzi', function () {
    $config  = sibia_get_mepr_config();
    $servizi = array(
        'SynchToFic' => array(
            'titolo'      => 'Synchroteam &#8596; Fatture in Cloud',
            'sottotitolo' => 'Sincronizzazione automatica tra Synchroteam e Fatture in Cloud',
            'descrizione' => 'Collega il gestionale Synchroteam al sistema di fatturazione Fatture in Cloud. Clienti e interventi vengono sincronizzati automaticamente, eliminando la doppia immissione.',
            'features'    => array(
                'Sincronizzazione clienti Synchroteam → Fatture in Cloud',
                'Sincronizzazione automatica degli interventi',
                'Creazione fatture da interventi completati',
                'Dashboard di stato nel portale SIBIA',
                'Supporto email incluso',
            ),
        ),
        'PicToPip' => array(
            'titolo'       => 'Picam7 &#8596; Pipedrive',
            'sottotitolo'  => 'Sincronizzazione automatica tra Picam7 e Pipedrive',
            'descrizione'  => 'Collega il gestionale Picam7 al CRM Pipedrive. Clienti e attività vengono sincronizzati automaticamente ogni ora, senza alcun intervento manuale.',
            'features'     => array(
                'Sincronizzazione clienti Picam7 → Pipedrive',
                'Sincronizzazione attività e interventi',
                'Aggiornamento automatico ogni ora',
                'Dashboard di stato nel portale SIBIA',
                'Supporto email incluso',
            ),
        ),
    );

    ob_start();
    ?>
    <div class="sibia-prezzi-wrap">
        <div class="sibia-prezzi-header">
            <h2>Piani e Prezzi</h2>
            <p>Scegli il piano pi&ugrave; adatto alle tue esigenze. Prova gratis per 14 giorni, senza carta di credito.</p>
        </div>

        <?php foreach ($servizi as $codice => $info) :
            $piani = $config[$codice] ?? null;

            /* Legge i prezzi da MemberPress se disponibile, altrimenti usa valori di default */
            $prezzoMensile = 49; $prezzoAnnuale = 490;
            $urlMensile = ''; $urlAnnuale = '';
            if ($piani) {
                $urlMensile = get_permalink($piani['mensile']);
                $urlAnnuale = get_permalink($piani['annuale']);
                if (class_exists('MeprProduct')) {
                    $pm = new MeprProduct(intval($piani['mensile']));
                    if ($pm->ID) $prezzoMensile = floatval($pm->price);
                    $pa = new MeprProduct(intval($piani['annuale']));
                    if ($pa->ID) $prezzoAnnuale = floatval($pa->price);
                }
            }
            $risparmioAnno = $prezzoMensile * 12 - $prezzoAnnuale;
            $risparmioStr  = $risparmioAnno > 0 ? 'Risparmia &euro;' . number_format($risparmioAnno, 0, ',', '.') . '/anno' : '';
        ?>
        <div class="sibia-prezzi-service">
            <div class="sibia-prezzi-service__header">
                <img src="https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png" alt="SIBIA" />
                <div>
                    <h3><?php echo $info['titolo']; ?></h3>
                    <p><?php echo esc_html($info['descrizione']); ?></p>
                </div>
            </div>

            <div class="sibia-prezzi-plans">

                <!-- Piano Mensile -->
                <div class="sibia-prezzi-plan">
                    <div class="sibia-prezzi-plan__name">Mensile</div>
                    <div class="sibia-prezzi-plan__price">&euro;<?php echo number_format($prezzoMensile, 0, ',', '.'); ?></div>
                    <div class="sibia-prezzi-plan__period">/ mese, IVA esclusa</div>
                    <ul class="sibia-prezzi-plan__features">
                        <?php foreach ($info['features'] as $f) : ?>
                        <li><?php echo esc_html($f); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($urlMensile) : ?>
                    <a href="<?php echo esc_url($urlMensile); ?>" class="sibia-btn" style="width:100%;justify-content:center;text-decoration:none;box-sizing:border-box;">
                        Inizia ora
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Piano Annuale (evidenziato) -->
                <div class="sibia-prezzi-plan sibia-prezzi-plan--featured">
                    <?php if ($risparmioStr) : ?>
                    <div class="sibia-prezzi-plan__badge"><?php echo $risparmioStr; ?></div>
                    <?php endif; ?>
                    <div class="sibia-prezzi-plan__name">Annuale</div>
                    <div class="sibia-prezzi-plan__price">&euro;<?php echo number_format($prezzoAnnuale, 0, ',', '.'); ?></div>
                    <div class="sibia-prezzi-plan__period">/ anno, IVA esclusa</div>
                    <div class="sibia-prezzi-plan__equi">~&euro;<?php echo number_format($prezzoAnnuale / 12, 0, ',', '.'); ?>/mese</div>
                    <ul class="sibia-prezzi-plan__features">
                        <?php foreach ($info['features'] as $f) : ?>
                        <li><?php echo esc_html($f); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($urlAnnuale) : ?>
                    <a href="<?php echo esc_url($urlAnnuale); ?>" class="sibia-btn" style="width:100%;justify-content:center;text-decoration:none;box-sizing:border-box;">
                        Inizia ora
                    </a>
                    <?php endif; ?>
                </div>

            </div>

            <div class="sibia-prezzi-note">
                &#9989; <strong>14 giorni gratuiti</strong> &mdash; Prova il servizio senza impegno. Attiva la demo gratuita dall&rsquo;<a href="<?php echo esc_url(home_url('/area-riservata/')); ?>" style="color:var(--sibia-blue);">Area Riservata</a> dopo la registrazione, senza carta di credito.
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

// ─── Clienti Pendenti SynchroteamFIC ────────────────────────────────────────

function sibia_get_sytfic_clienti_pendenti($email) {
    $base_url = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret   = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header   = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    if (empty($base_url) || empty($secret)) return [];

    $url      = $base_url . '/onboarding/sytfic-clienti-pendenti?email=' . urlencode($email);
    $response = wp_remote_get($url, [
        'headers' => [ $header => $secret ],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return [];
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) return [];
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
}

function sibia_risolvi_cliente_pendente($email, $id, $azione, $ficId = null) {
    $base_url = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret   = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header   = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    if (empty($base_url) || empty($secret)) return false;

    $url  = $base_url . '/onboarding/sytfic-clienti-pendenti/' . intval($id) . '/risolvi';
    $body = [ 'email' => $email, 'azione' => $azione ];
    if ($ficId !== null) $body['ficClienteId'] = intval($ficId);

    $response = wp_remote_post($url, [
        'headers' => [
            $header        => $secret,
            'Content-Type' => 'application/json',
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return ['success' => false, 'message' => 'Errore di connessione al server.'];
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) return ['success' => true];
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return ['success' => false, 'message' => $body['error']['message'] ?? ('Errore ' . $code . '. Riprova.')];
}

function sibia_crea_cliente_fic($email, $pendingId) {
    $base_url = rtrim(sibia_onboarding_get_option('sibia_onboarding_api_base', 'https://api.cloud-ar.it/api/v1'), '/');
    $secret   = sibia_onboarding_get_option('sibia_onboarding_secret', '');
    $header   = sibia_onboarding_get_option('sibia_onboarding_header', 'X-ONBOARDING-KEY');
    if (empty($base_url) || empty($secret)) return ['success' => false, 'message' => 'Configurazione API non trovata.'];

    $url  = $base_url . '/onboarding/sytfic-crea-cliente-fic';
    $body = ['email' => $email, 'pendingId' => intval($pendingId)];

    $response = wp_remote_post($url, [
        'headers' => [
            $header        => $secret,
            'Content-Type' => 'application/json',
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 30,
    ]);
    if (is_wp_error($response)) return ['success' => false, 'message' => 'Errore di connessione al server.'];
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) return ['success' => true];
    return ['success' => false];
}
