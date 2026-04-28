<?php
/**
 * SIBIA — Guida installazione Sync.PicamPipedrive
 * Shortcode: [sibia_guida_picampipedrive]
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('sibia_guida_picampipedrive', function () {
    $img = plugin_dir_url(dirname(__FILE__)) . 'images/';

    $passi = array(
        array(
            'num'     => 1,
            'titolo'  => 'Scarica l\'installer',
            'corpo'   => '<p>Clicca sul bottone <strong>"📥 Scarica Installer"</strong> nel portale. Il file ha dimensione di circa 75 MB e contiene tutto il necessario per l\'installazione.</p>',
            'immagini'=> array(
                array('src' => $img . 'image1.png', 'alt' => 'Pagina download installer dal portale SIBIA', 'caption' => ''),
            ),
            'warning' => null,
        ),
        array(
            'num'     => 2,
            'titolo'  => 'Avviso Windows SmartScreen (normale)',
            'corpo'   => '<p>Quando scarichi il file, Windows potrebbe mostrare un avviso di sicurezza. Questo è <strong>normale e sicuro</strong>. Il file non ha una firma digitale (richiede un certificato costoso), ma il software è completamente sicuro.</p>',
            'immagini'=> array(),
            'warning' => array(
                'titolo' => 'Cosa fare se appare l\'avviso',
                'testo'  => 'Clicca su <strong>"Ulteriori informazioni"</strong> o <strong>"More info"</strong>, poi clicca su <strong>"Esegui comunque"</strong> o <strong>"Run anyway"</strong> per procedere.',
            ),
        ),
        array(
            'num'     => 3,
            'titolo'  => 'Esegui l\'installer come Amministratore',
            'corpo'   => '<p>Dopo aver scaricato il file <code>SyncPicamPipedrive_Setup.exe</code>, fai clic destro sul file e seleziona <strong>"Esegui come amministratore"</strong>.</p>',
            'immagini'=> array(
                array('src' => $img . 'image2.png', 'alt' => 'Clic destro - Esegui come amministratore', 'caption' => ''),
            ),
            'warning' => null,
        ),
        array(
            'num'     => 4,
            'titolo'  => 'Installazione automatica',
            'corpo'   => '<p>L\'installer procederà automaticamente. Verranno installati:</p>
<ol>
<li><strong>Servizio Windows</strong> (Sync.PicamPipedrive.Service) — esegue la sincronizzazione in background</li>
<li><strong>Tray Application</strong> (icona nella system tray) — per gestire il servizio e generare il token</li>
</ol>
<p>L\'installazione dura circa 10-20 secondi. Al termine, vedrai una conferma di completamento.</p>',
            'immagini'=> array(
                array('src' => $img . 'image3.png', 'alt' => 'Installazione in corso', 'caption' => ''),
            ),
            'warning' => null,
        ),
        array(
            'num'     => 5,
            'titolo'  => 'Trova l\'icona SIBIA nella system tray',
            'corpo'   => '<p>Al termine dell\'installazione, cerca l\'icona <strong>SIBIA</strong> nella barra in basso a destra (system tray), accanto all\'orologio di Windows.</p>',
            'immagini'=> array(
                array('src' => $img . 'image4.png', 'alt' => 'Icona SIBIA nella system tray', 'caption' => ''),
            ),
            'warning' => array(
                'titolo' => 'Icona non visibile?',
                'testo'  => 'Clicca sulla freccia <strong>"^"</strong> per espandere le icone nascoste. L\'icona SIBIA potrebbe essere nascosta lì.',
            ),
        ),
        array(
            'num'     => 6,
            'titolo'  => 'Menu tray — funzioni disponibili',
            'corpo'   => '<p>Clicca con il <strong>tasto destro</strong> sull\'icona SIBIA nella system tray per vedere tutte le funzioni disponibili:</p>
<ol>
<li><strong>Apri log Service</strong> — visualizza i log del servizio di sincronizzazione</li>
<li><strong>Apri log Tray</strong> — visualizza i log dell\'applicazione tray</li>
<li><strong>Configura Servizio</strong> (in grassetto) — imposta la chiave API per la connessione al portale</li>
<li><strong>Avvia Servizio / Ferma Servizio</strong> — gestisci manualmente il servizio di sincronizzazione</li>
<li><strong>Disinstalla Servizio</strong> — rimuove completamente il servizio (richiede reinstallazione)</li>
<li><strong>Stato</strong> — mostra statistiche sincronizzazione (ultimo run, record sincronizzati)</li>
<li><strong>Genera token</strong> — genera e copia il token negli appunti</li>
<li><strong>Informazioni</strong> — versione software e copyright</li>
<li><strong>Esci</strong> — chiude l\'applicazione tray (il servizio continua a funzionare)</li>
</ol>',
            'immagini'=> array(
                array('src' => $img . 'image5.png', 'alt' => 'Menu tray completo con tutte le voci', 'caption' => ''),
            ),
            'warning' => array(
                'titolo' => 'Prima volta che usi il programma?',
                'testo'  => 'Per iniziare, usa nell\'ordine: <strong>Genera token</strong> (Passo 7) → <strong>Configura Servizio</strong> (Passo 8) → <strong>Stato</strong> (per verificare)',
            ),
        ),
        array(
            'num'     => 7,
            'titolo'  => 'Genera il token',
            'corpo'   => '<p>Dal menu tray (tasto destro sull\'icona SIBIA), seleziona <strong>"Genera token"</strong>.</p>
<p>Il token verrà <strong>copiato automaticamente negli appunti</strong> (come quando fai Ctrl+C). Vedrai una notifica di conferma.</p>',
            'immagini'=> array(
                array('src' => $img . 'image6.png', 'alt' => 'Dialog token generato e copiato negli appunti', 'caption' => ''),
            ),
            'warning' => array(
                'titolo' => 'Conserva il token!',
                'testo'  => 'Il token è necessario per collegare il software al portale SIBIA. Incollalo subito nel portale (passo successivo) o salvalo temporaneamente in un file di testo.',
            ),
        ),
        array(
            'num'     => 8,
            'titolo'  => 'Completa la configurazione sul portale',
            'corpo'   => '<p>Torna alla pagina del portale SIBIA (area riservata) e:</p>
<ol>
<li>Vai alla sezione <strong>"Soluzioni → Picam7 ↔ Pipedrive"</strong></li>
<li>Nel campo <strong>"Token Sync.PicamPipedrive"</strong>, incolla il token copiato (Ctrl+V)</li>
<li>Inserisci la tua <strong>API key Pipedrive</strong> (la trovi nelle impostazioni di Pipedrive)</li>
<li>Clicca su <strong>"Salva configurazione"</strong></li>
</ol>
<p>Il portale genererà automaticamente la <strong>chiave API per il collegamento</strong>.</p>',
            'immagini'=> array(
                array('src' => $img . 'image8.png', 'alt' => 'Pipedrive Settings - Personal preferences - API token', 'caption' => 'Dove trovare la API key Pipedrive:'),
                array('src' => $img . 'image9.png', 'alt' => 'Form di configurazione sul portale SIBIA', 'caption' => 'Form di configurazione sul portale SIBIA:'),
            ),
            'warning' => null,
        ),
        array(
            'num'     => 9,
            'titolo'  => 'Configura il servizio sul PC',
            'corpo'   => '<p>Ora devi collegare il software installato al portale SIBIA:</p>
<ol>
<li>Torna al menu tray (tasto destro sull\'icona SIBIA)</li>
<li>Seleziona <strong>"Configura Servizio"</strong> (voce in grassetto)</li>
<li>Vedrai una finestra che mostra lo <strong>stato del servizio</strong> (IN ESECUZIONE / FERMO)</li>
<li>Incolla la <strong>chiave API</strong> generata dal portale nel campo "Inserisci la API key"</li>
<li>Clicca su <strong>"Conferma"</strong></li>
</ol>
<p>Il software verificherà automaticamente la connessione al portale.</p>',
            'immagini'=> array(
                array('src' => $img . 'image10.png', 'alt' => 'Dialog Configura Servizio con API key generata dal portale', 'caption' => ''),
            ),
            'warning' => array(
                'titolo' => 'Servizio fermo?',
                'testo'  => 'Se il servizio risulta "FERMO", puoi avviarlo manualmente dal menu tray → <strong>"Avvia Servizio"</strong>. Normalmente si avvia automaticamente all\'installazione.',
            ),
        ),
        array(
            'num'     => 10,
            'titolo'  => 'Verifica che tutto funzioni',
            'corpo'   => '<p>Dopo aver completato la configurazione, puoi verificare che tutto funzioni correttamente in due modi:</p>
<p><strong>Dal portale SIBIA:</strong></p>
<ol>
<li>Controlla lo <strong>Stato sincronizzazione</strong> nella stessa pagina del portale</li>
<li>Dovresti vedere: ultimo run, numero organizzazioni/destinazioni/contatti sincronizzati</li>
<li>La sincronizzazione avviene <strong>automaticamente ogni ora</strong></li>
</ol>
<p><strong>Dal software sul PC:</strong></p>
<ol>
<li>Apri il menu tray (tasto destro sull\'icona SIBIA)</li>
<li>Seleziona <strong>"Stato"</strong></li>
<li>Vedrai: stato servizio, ultimo aggiornamento, statistiche sincronizzazione</li>
</ol>',
            'immagini'=> array(
                array('src' => $img . 'image7.png', 'alt' => 'Dashboard stato sincronizzazione con statistiche', 'caption' => ''),
            ),
            'warning' => array(
                'titolo' => 'Problemi o errori?',
                'testo'  => 'Se vedi errori, controlla i log dal menu tray → <strong>"Apri log Service"</strong>. Per assistenza, contatta il supporto SIBIA allegando i file di log.',
            ),
        ),
    );

    ob_start();
    ?>
    <div class="sibia-guida-wrap">

        <div class="sibia-guida-header">
            <h1>Guida Installazione Sync.PicamPipedrive</h1>
            <p>Segui questi passi per installare e configurare il programma di sincronizzazione. Clicca su ciascun passo per aprirlo.</p>
        </div>

        <div class="sibia-guida-toolbar">
            <button class="sibia-btn sibia-btn--ghost sibia-btn--small js-guida-apri-tutto">Apri tutto</button>
            <button class="sibia-btn sibia-btn--ghost sibia-btn--small js-guida-chiudi-tutto">Chiudi tutto</button>
        </div>

        <div class="sibia-guida-accordion" id="guida-picampipedrive">
        <?php foreach ($passi as $passo) :
            $id_body = 'guida-step-body-' . $passo['num'];
        ?>
            <div class="sibia-guida-step">
                <button class="sibia-guida-step__trigger"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr($id_body); ?>">
                    <span class="sibia-guida-step__num"><?php echo intval($passo['num']); ?></span>
                    <span class="sibia-guida-step__label"><?php echo esc_html($passo['titolo']); ?></span>
                    <svg class="sibia-guida-step__chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                    </svg>
                </button>
                <div class="sibia-guida-step__body" id="<?php echo esc_attr($id_body); ?>" hidden>

                    <?php echo wp_kses_post($passo['corpo']); ?>

                    <?php if (!empty($passo['warning'])) : ?>
                    <div class="sibia-guida-warning">
                        <span aria-hidden="true" style="font-size:20px;flex-shrink:0;">⚠️</span>
                        <div>
                            <strong><?php echo esc_html($passo['warning']['titolo']); ?></strong>
                            <p><?php echo wp_kses_post($passo['warning']['testo']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($passo['immagini'] as $img_item) : ?>
                    <div class="sibia-guida-screenshot">
                        <?php if (!empty($img_item['caption'])) : ?>
                            <p class="sibia-guida-screenshot__caption"><?php echo esc_html($img_item['caption']); ?></p>
                        <?php endif; ?>
                        <img src="<?php echo esc_url($img_item['src']); ?>"
                             alt="<?php echo esc_attr($img_item['alt']); ?>"
                             loading="lazy">
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="sibia-guida-footer">
            <p><strong>Hai bisogno di aiuto?</strong></p>
            <p>Contatta il supporto SIBIA: <a href="mailto:supporto@sibia.it">supporto@sibia.it</a></p>
        </div>

    </div>
    <script>
    (function () {
        var accordion = document.getElementById('guida-picampipedrive');
        if (!accordion) return;

        function apriStep(trigger) {
            var bodyId = trigger.getAttribute('aria-controls');
            var body = document.getElementById(bodyId);
            var step = trigger.closest('.sibia-guida-step');
            if (!body || !step) return;
            trigger.setAttribute('aria-expanded', 'true');
            body.removeAttribute('hidden');
            step.classList.add('is-open');
        }

        function chiudiStep(trigger) {
            var bodyId = trigger.getAttribute('aria-controls');
            var body = document.getElementById(bodyId);
            var step = trigger.closest('.sibia-guida-step');
            if (!body || !step) return;
            trigger.setAttribute('aria-expanded', 'false');
            body.setAttribute('hidden', '');
            step.classList.remove('is-open');
        }

        accordion.querySelectorAll('.sibia-guida-step__trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                if (trigger.getAttribute('aria-expanded') === 'true') {
                    chiudiStep(trigger);
                } else {
                    apriStep(trigger);
                }
            });
        });

        var btnApri = document.querySelector('.js-guida-apri-tutto');
        if (btnApri) {
            btnApri.addEventListener('click', function () {
                accordion.querySelectorAll('.sibia-guida-step__trigger').forEach(apriStep);
            });
        }

        var btnChiudi = document.querySelector('.js-guida-chiudi-tutto');
        if (btnChiudi) {
            btnChiudi.addEventListener('click', function () {
                accordion.querySelectorAll('.sibia-guida-step__trigger').forEach(chiudiStep);
            });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
});
