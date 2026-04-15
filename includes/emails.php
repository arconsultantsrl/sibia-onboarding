<?php
/**
 * SIBIA Onboarding — Modelli email HTML
 */

if (!defined('ABSPATH')) { exit; }

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

    // URL portale — l'utente sceglie il nuovo piano dal portale
    $portalUrl = home_url('/area-riservata/?section=fatturazione');

    $corpoHtml = '<p>Gentile ' . esc_html($user->display_name) . ',</p>'
        . '<p>il tuo abbonamento precedente è scaduto. Ora puoi attivare il nuovo piano <strong>'
        . esc_html($pianoLabel . ' / ' . $intervalloLabel) . '</strong> dal portale.</p>';

    wp_mail(
        $user->user_email,
        'Il tuo nuovo piano è pronto — SIBIA',
        sibia_email_html('Attiva il tuo nuovo piano', $corpoHtml, $portalUrl, 'Accedi al portale'),
        ['Content-Type: text/html; charset=UTF-8']
    );
}

