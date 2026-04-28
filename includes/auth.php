<?php
/**
 * SIBIA Onboarding — Autenticazione, verifica email, login
 */

if (!defined('ABSPATH')) { exit; }

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
        $login = get_page_by_path('accesso');
        wp_redirect($login ? get_permalink($login->ID) : home_url('/accesso/'), 302);
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


// Verifica email in due fasi per resistere ai bot antivirus/scanner.
//
// Problema: i sistemi di sicurezza email (Safe Links, Barracuda, Proofpoint, ecc.)
// aprono automaticamente i link in entrata via GET per verificare che non siano
// pericolosi. Con il vecchio approccio monouso, il bot consumava il token prima
// che l'utente potesse cliccare → "Link già utilizzato o scaduto".
//
// Soluzione: GET mostra una pagina di conferma intermedia (il bot la vede ma non
// interagisce). Il POST dal pulsante "Attiva" esegue la verifica reale e consuma
// il token. I bot non fanno mai POST su form, quindi il token sopravvive.
//
// Hook: 'init' priorità 5 — si attiva su QUALSIASI URL.
// URL verifica: home_url('/?sibia_verifica=TOKEN')
add_action('init', function () {
    if (!isset($_GET['sibia_verifica'])) return;

    $token = sanitize_text_field(wp_unslash($_GET['sibia_verifica']));
    $data  = get_option('sibia_ev_' . $token, false);

    $pageReg = get_page_by_path('registrazione');
    $errUrl  = add_query_arg('sibia_verifica_err', '1',
        $pageReg ? get_permalink($pageReg->ID) : home_url('/registrazione/'));

    // Validazione token (senza eliminarlo ancora)
    if (!$data || !is_array($data) || empty($data['uid']) || empty($data['exp'])
        || $data['exp'] < time() || !get_userdata($data['uid'])) {
        wp_redirect($errUrl);
        exit;
    }

    $user_id = (int) $data['uid'];

    // Attiva l'account non appena il link valido viene aperto (GET o POST).
    // Garantisce che l'email sia verificata anche se l'utente non clicca il pulsante "Attiva".
    // La sicurezza è mantenuta dal token a 40 caratteri: solo il destinatario della mail lo conosce.
    // Segna l'utente come verificato in User Verification (PickPlugins).
    // Senza questi meta, uv_user_authentication() bloccherebbe wp_signon() con WP_Error.
    update_user_meta($user_id, 'sibia_email_verificata', 1);
    update_user_meta($user_id, 'user_activation_status', 1);
    // Assicura che user_activation_key sia vuoto.
    global $wpdb;
    $wpdb->update($wpdb->users, ['user_activation_key' => ''], ['ID' => $user_id]);
    clean_user_cache($user_id);

    // POST con campo conferma → auto-login e redirect al portale (consuma il token).
    // I bot non inviano mai POST su form → il token rimane disponibile per il click umano.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['sibia_confirma'])
        && $_POST['sibia_confirma'] === '1') {
        delete_option('sibia_ev_' . $token);

        // Auto-login: bypassa il filtro 'authenticate' di User Verification (PickPlugins).
        // wp_set_auth_cookie non passa per il flusso di login → nessun blocco.
        wp_set_auth_cookie($user_id, false);
        $portalPage = get_page_by_path('area-riservata');
        $portalUrl  = $portalPage ? get_permalink($portalPage->ID) : home_url('/area-riservata/');
        wp_redirect($portalUrl);
        exit;
    }

    // GET (o POST non riconosciuto) → pagina di conferma intermedia.
    // Il token rimane intatto; il bot vede questa pagina ma non invia il form.
    $action_url = home_url('/?sibia_verifica=' . urlencode($token));
    $logo_url   = 'https://sibia.it/wp-content/uploads/2025/06/favicon-sibia.png';

    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Conferma indirizzo email &#8212; SIBIA</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#00072d;font-family:"DM Sans","Helvetica Neue",Arial,sans-serif;
     display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:#fff;border-radius:18px;box-shadow:0 8px 40px rgba(0,7,45,.4);
      max-width:440px;width:100%;padding:40px 32px;text-align:center}
.logo{width:58px;height:58px;border-radius:50%;margin:0 auto 20px;display:block}
h1{font-family:"Raleway","Helvetica Neue",Arial,sans-serif;font-size:22px;
   font-weight:700;color:#1c2b3a;margin-bottom:12px}
p{font-size:15px;color:#61758b;line-height:1.6;margin-bottom:28px}
button{background:#1f5fa6;color:#fff;border:none;border-radius:50px;
       padding:14px 32px;font-size:16px;font-weight:600;cursor:pointer;
       width:100%;transition:background .2s}
button:hover{background:#174a85}
</style>
</head>
<body>
<div class="card">
  <img src="' . esc_attr($logo_url) . '" alt="SIBIA" class="logo">
  <h1>Conferma il tuo indirizzo email</h1>
  <p>Stai per attivare il tuo account su <strong>SIBIA</strong>.<br>
     Clicca il pulsante qui sotto per completare la registrazione.</p>
  <form method="post" action="' . esc_attr($action_url) . '">
    <input type="hidden" name="sibia_confirma" value="1">
    <button type="submit">Attiva il mio account</button>
  </form>
</div>
</body>
</html>';
    exit;
}, 5);


// Le pagine di registrazione e accesso contengono nonce: no-cache obbligatorio.
// /account/ e /accesso/ devono essere no-cache: il contenuto dipende dallo stato di login.
// Utenti loggati su /account/ o /accesso/ → portale. Non loggati su /account/ → /accesso/.
add_action('template_redirect', function () {
    if (is_page('registrazione') || is_page('accesso') || is_page('password-dimenticata') || is_page('reset-password')) {
        nocache_headers();
        // LiteSpeed Cache usa questa intestazione propria invece di Cache-Control.
        // Se LiteSpeed non è attivo l'intestazione viene ignorata, non causa problemi.
        header('X-LiteSpeed-Cache-Control: no-cache');
    }
    if (is_page('account')) {
        nocache_headers();
        if (is_user_logged_in()) {
            $portal = get_page_by_path('area-riservata');
            if ($portal) {
                wp_redirect(get_permalink($portal->ID));
                exit;
            }
        } else {
            // /account/ ospita il layout BeBuilder con il vecchio form MemberPress.
            // I non-loggati vengono mandati sulla pagina SIBIA con [sibia_accedi].
            $login = get_page_by_path('accesso');
            wp_redirect($login ? get_permalink($login->ID) : home_url('/accesso/'));
            exit;
        }
    }
    if (is_page('accesso') && is_user_logged_in()) {
        $portal = get_page_by_path('area-riservata');
        wp_redirect($portal ? get_permalink($portal->ID) : home_url('/area-riservata/'));
        exit;
    }
});

// Nasconde la barra WordPress agli utenti non amministratori
add_filter('show_admin_bar', function ($show) {
    return current_user_can('manage_options') ? $show : false;
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

// Cattura i fallimenti di wp_mail e li salva permanentemente per il pannello admin.
// Usa update_option (non transient) così l'errore non scade dopo 1 ora e rimane visibile
// finché non viene sovrascritto da un nuovo evento. Utile per diagnosticare problemi con
// WP Mail SMTP o la configurazione SMTP che si manifestano in modo intermittente.
add_action('wp_mail_failed', function ($wp_error) {
    $msg = $wp_error->get_error_message();
    error_log('[SIBIA] wp_mail fallita: ' . $msg);
    update_option('sibia_mail_last_error', [
        'msg'  => $msg,
        'time' => current_time('mysql'),
    ], false);
});

// Auto-creazione pagina /accesso/ con form di login SIBIA.
// Rimpiazza la dipendenza dalla pagina /account/ (layout BeBuilder con vecchio form MemberPress).
// I non-loggati vengono mandati qui da /accedi/ e da /account/.
add_action('init', function () {
    if (get_transient('sibia_accesso_page_v1')) return;
    if (!get_page_by_path('accesso')) {
        wp_insert_post([
            'post_title'   => 'Accedi',
            'post_name'    => 'accesso',
            'post_content' => '[sibia_accedi]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);
    }
    set_transient('sibia_accesso_page_v1', true, DAY_IN_SECONDS);
});

// Auto-creazione pagine /password-dimenticata/ e /reset-password/ per il flusso reset password SIBIA.
add_action('init', function () {
    if (get_transient('sibia_pwd_pages_v1')) return;
    foreach ([
        ['slug' => 'password-dimenticata', 'title' => 'Password dimenticata', 'content' => '[sibia_password_dimenticata]'],
        ['slug' => 'reset-password',       'title' => 'Reimposta password',   'content' => '[sibia_reset_password]'],
    ] as $p) {
        if (!get_page_by_path($p['slug'])) {
            wp_insert_post([
                'post_title'   => $p['title'],
                'post_name'    => $p['slug'],
                'post_content' => $p['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ]);
        }
    }
    set_transient('sibia_pwd_pages_v1', true, DAY_IN_SECONDS);
});

// Intercetta il link "Password dimenticata?" e lo manda alla pagina SIBIA invece di wp-login.php
add_filter('lostpassword_url', function ($url, $redirect) {
    $page = get_page_by_path('password-dimenticata');
    return $page ? get_permalink($page->ID) : $url;
}, 10, 2);
