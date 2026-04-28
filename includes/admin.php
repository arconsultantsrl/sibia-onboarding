<?php
/**
 * SIBIA Onboarding — Pannello impostazioni WordPress
 */

if (!defined('ABSPATH')) { exit; }

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
    // Se l'utente invia il campo vuoto, mantieni il valore già salvato (non sovrascrivere con stringa vuota).
    register_setting('sibia_onboarding_settings', 'sibia_onboarding_secret', [
        'sanitize_callback' => function ($nuovoValore) {
            $nuovoValore = sanitize_text_field($nuovoValore);
            if (empty($nuovoValore)) {
                return get_option('sibia_onboarding_secret', '');
            }
            return $nuovoValore;
        }
    ]);
    register_setting('sibia_onboarding_settings', 'sibia_onboarding_header');
    register_setting('sibia_onboarding_settings', 'sibia_support_email');

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
            $isSet = !empty(sibia_onboarding_get_option('sibia_onboarding_secret', ''));
            $placeholder = $isSet ? '(lascia vuoto per mantenere il valore attuale)' : 'Inserisci il secret';
            // Il valore NON viene mai incluso nell'HTML per evitare che appaia nel sorgente della pagina.
            echo "<input type=\"password\" class=\"regular-text\" name=\"sibia_onboarding_secret\" value=\"\" placeholder=\"" . esc_attr($placeholder) . "\" autocomplete=\"new-password\" />";
            if ($isSet) {
                echo "<p class=\"description\">Secret configurato. Compilare solo per modificarlo.</p>";
            }
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

    add_settings_field(
        'sibia_support_email',
        'Email supporto',
        function () {
            $value = esc_attr(sibia_onboarding_get_option('sibia_support_email', 'info@sibia.it'));
            echo "<input type=\"email\" class=\"regular-text\" name=\"sibia_support_email\" value=\"{$value}\" />";
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
    $mailErrorData = get_option('sibia_mail_last_error', null);
    $mailError     = is_array($mailErrorData) ? ($mailErrorData['msg']  ?? null) : null;
    $mailErrorTime = is_array($mailErrorData) ? ($mailErrorData['time'] ?? null) : null;

    $testEmailResult = isset($_GET['sibia_test_email']) ? sanitize_text_field($_GET['sibia_test_email']) : '';
    ?>
    <div class="wrap">
        <h1>SIBIA Onboarding</h1>

        <?php if (!empty($mailError)) : ?>
        <div class="notice notice-error is-dismissible" style="margin-left:0;">
            <p><strong>Ultimo errore invio email (wp_mail):</strong> <?php echo esc_html($mailError); ?></p>
            <?php if ($mailErrorTime) : ?>
            <p style="font-size:12px;color:#777;">Data/ora: <?php echo esc_html($mailErrorTime); ?></p>
            <?php endif; ?>
            <p style="font-size:12px;color:#777;">Verificare la configurazione di WP Mail SMTP (Impostazioni → WP Mail SMTP → Tools → Email Test).</p>
        </div>
        <?php endif; ?>

        <?php if ($testEmailResult === 'ok') : ?>
        <div class="notice notice-success is-dismissible" style="margin-left:0;">
            <p><strong>Email di test inviata.</strong> Controlla la casella <code><?php echo esc_html(get_option('admin_email')); ?></code>.</p>
        </div>
        <?php elseif ($testEmailResult === 'fail') : ?>
        <div class="notice notice-error is-dismissible" style="margin-left:0;">
            <p><strong>Invio email di test fallito.</strong> Controlla la configurazione WP Mail SMTP.</p>
        </div>
        <?php endif; ?>

        <!-- ===== Test email ===== -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:20px;">
            <input type="hidden" name="action" value="sibia_test_email" />
            <?php wp_nonce_field('sibia_test_email_nonce'); ?>
            <button type="submit" class="button button-secondary">
                Invia email di test a <?php echo esc_html(get_option('admin_email')); ?>
            </button>
        </form>

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

// Gestore del test email dal pannello admin
add_action('admin_post_sibia_test_email', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Accesso negato.');
    }
    check_admin_referer('sibia_test_email_nonce');

    $adminEmail = get_option('admin_email');
    $result = wp_mail(
        $adminEmail,
        '[SIBIA] Test configurazione email',
        sibia_email_html(
            'Test email',
            '<p>Questa è un\'email di test inviata dal pannello admin di SIBIA.</p>'
                . '<p>Se stai leggendo questo messaggio, l\'invio tramite WP Mail SMTP funziona correttamente.</p>',
            admin_url('options-general.php?page=sibia-onboarding'),
            'Torna al pannello'
        ),
        ['Content-Type: text/html; charset=UTF-8']
    );

    $base = admin_url('options-general.php?page=sibia-onboarding');
    wp_redirect(add_query_arg('sibia_test_email', $result ? 'ok' : 'fail', $base));
    exit;
});

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
