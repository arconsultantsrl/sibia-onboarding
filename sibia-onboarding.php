<?php
/**
 * Plugin Name: SIBIA Onboarding
 * Description: Pagina di onboarding e gestione sincronizzazioni per SIBIA.
 * Version: 2.83.7
 * GitHub Plugin URI: arconsultantsrl/sibia-onboarding
 * Primary Branch: main
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/* Costante per il percorso del file principale (usata da register_deactivation_hook nei moduli) */
define('SIBIA_PLUGIN_FILE', __FILE__);

/* ── Funzione utility globale ── */

function sibia_onboarding_get_option($key, $default = '')
{
    $value = get_option($key);
    return $value === false ? $default : $value;
}

/* ── CSS e font ── */

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'sibia-google-fonts',
        'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600&family=Raleway:wght@400;500;600&display=swap',
        [],
        null
    );
    wp_enqueue_style(
        'sibia-onboarding',
        plugin_dir_url(__FILE__) . 'assets/css/sibia-onboarding.css',
        ['sibia-google-fonts'],
        filemtime(plugin_dir_path(__FILE__) . 'assets/css/sibia-onboarding.css')
    );
});

/* ── Moduli ── */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/api-client.php';
require_once __DIR__ . '/includes/emails.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/contracts.php';
require_once __DIR__ . '/includes/shortcodes.php';
