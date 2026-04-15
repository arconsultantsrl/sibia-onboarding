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
