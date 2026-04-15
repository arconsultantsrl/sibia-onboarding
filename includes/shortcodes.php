<?php
/**
 * SIBIA Onboarding — Shortcode portale, onboarding, registrazione, prezzi
 */

if (!defined('ABSPATH')) { exit; }

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
                $plugin_data = get_file_data(SIBIA_PLUGIN_FILE, ['Version' => 'Version']);
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
                    // Legge prezzi da ApiConnect (con fallback ai valori predefiniti)
                    $_pianiModal    = sibia_get_prezzi_piani($codice);
                    $modalPrezzoMensile = number_format($_pianiModal['standard']['mensile']['euroMeseDisplay'] ?? 25, 0, ',', '.');
                    $modalPrezzoAnnuale = number_format($_pianiModal['standard']['annuale']['euroTotale']      ?? 252, 0, ',', '.');
                    $modalNome      = html_entity_decode(strip_tags($info['titolo']));
                    $modalDesc      = $info['descrizione'];
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
                                <?php if ($codice === 'SynchToFic') : ?>
                                    <?php
                                    $stdPrezzoM = number_format($_pianiModal['standard']['mensile']['euroMeseDisplay']     ?? 25,  0, ',', '.');
                                    $stdPrezzoA = number_format($_pianiModal['standard']['annuale']['euroTotale']          ?? 252, 0, ',', '.');
                                    $proPrezzoM = number_format($_pianiModal['professional']['mensile']['euroMeseDisplay'] ?? 45,  0, ',', '.');
                                    $proPrezzoA = number_format($_pianiModal['professional']['annuale']['euroTotale']      ?? 420, 0, ',', '.');
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
                                <?php else : // Altri servizi: layout 2-pulsanti checkout
                                    $disableMensile = ($stato === 'attivo');
                                    $disableAnnuale = ($stato === 'attivo');
                                ?>
                                    <?php if (!$disableMensile) : ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr($nonce); ?>">
                                        <input type="hidden" name="sibia_billing_action"   value="checkout">
                                        <input type="hidden" name="sibia_billing_servizio" value="<?php echo esc_attr($codice); ?>">
                                        <input type="hidden" name="sibia_billing_piano"    value="standard">
                                        <input type="hidden" name="sibia_billing_intervallo" value="mensile">
                                        <button type="submit" class="sibia-btn sibia-btn--primary">Abbonati &mdash; Mensile</button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr($nonce); ?>">
                                        <input type="hidden" name="sibia_billing_action"   value="checkout">
                                        <input type="hidden" name="sibia_billing_servizio" value="<?php echo esc_attr($codice); ?>">
                                        <input type="hidden" name="sibia_billing_piano"    value="standard">
                                        <input type="hidden" name="sibia_billing_intervallo" value="annuale">
                                        <button type="submit" class="sibia-btn sibia-btn--primary">Abbonati &mdash; Annuale <span class="sibia-badge sibia-badge--promo">Risparmia</span></button>
                                    </form>
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
                /* Leggi prezzi da ApiConnect (con fallback ai valori predefiniti) */
                $_prezziPiani = sibia_get_prezzi_piani('SynchToFic');
                $_abbPrezzo = array(
                    'standard'     => array(
                        'mensile' => number_format($_prezziPiani['standard']['mensile']['euroMeseDisplay'] ?? 25, 0, ',', '.'),
                        'annuale' => number_format($_prezziPiani['standard']['annuale']['euroTotale']      ?? 252, 0, ',', '.'),
                    ),
                    'professional' => array(
                        'mensile' => number_format($_prezziPiani['professional']['mensile']['euroMeseDisplay'] ?? 45, 0, ',', '.'),
                        'annuale' => number_format($_prezziPiani['professional']['annuale']['euroTotale']      ?? 420, 0, ',', '.'),
                    ),
                );
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
                            data-url-mensile="<?php echo $_stdAttivo ? '' : '#'; ?>"
                            data-url-annuale="<?php echo $_stdAttivo ? '' : '#'; ?>">
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
                            <?php elseif ($_isAttivo) : ?>
                            <form method="post">
                                <input type="hidden" name="sibia_billing_nonce"            value="<?php echo esc_attr(wp_create_nonce('sibia_billing')); ?>">
                                <input type="hidden" name="sibia_billing_action"           value="cambio_piano">
                                <input type="hidden" name="sibia_billing_servizio"         value="SynchToFic">
                                <input type="hidden" name="sibia_billing_nuovo_piano"      value="standard">
                                <input type="hidden" name="sibia_billing_nuovo_intervallo" id="cambio-std-intervallo" value="mensile">
                                <button type="submit" class="sibia-btn sibia-btn--outline sibia-abb-card__cta" data-role="cta"
                                    onclick="document.getElementById('cambio-std-intervallo').value=document.querySelector('.sibia-abb-toggle__btn.is-active').getAttribute('data-interval');">
                                    Passa a Standard &rarr;
                                </button>
                            </form>
                            <?php else : ?>
                            <form method="post">
                                <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr(wp_create_nonce('sibia_billing')); ?>">
                                <input type="hidden" name="sibia_billing_action"   value="checkout">
                                <input type="hidden" name="sibia_billing_servizio" value="SynchToFic">
                                <input type="hidden" name="sibia_billing_piano"    value="standard">
                                <input type="hidden" name="sibia_billing_intervallo" id="checkout-std-intervallo" value="mensile">
                                <button type="submit" class="sibia-btn sibia-btn--outline sibia-abb-card__cta" data-role="cta"
                                    onclick="document.getElementById('checkout-std-intervallo').value=document.querySelector('.sibia-abb-toggle__btn.is-active').getAttribute('data-interval');">
                                    Continua al pagamento sicuro &rarr;
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>

                        <!-- Card Professional -->
                        <?php $_proAttivo = ($_isAttivo && $_abbPianoAttuale === 'professional'); ?>
                        <div class="sibia-abb-card sibia-abb-card--featured<?php echo $_proAttivo ? ' sibia-abb-card--current' : ''; ?>"
                            data-price-mensile="<?php echo esc_attr($_abbPrezzo['professional']['mensile']); ?>"
                            data-price-annuale="<?php echo esc_attr($_abbPrezzo['professional']['annuale']); ?>"
                            data-url-mensile="<?php echo $_proAttivo ? '' : '#'; ?>"
                            data-url-annuale="<?php echo $_proAttivo ? '' : '#'; ?>">
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
                            <?php elseif ($_isAttivo) : ?>
                            <form method="post">
                                <input type="hidden" name="sibia_billing_nonce"            value="<?php echo esc_attr(wp_create_nonce('sibia_billing')); ?>">
                                <input type="hidden" name="sibia_billing_action"           value="cambio_piano">
                                <input type="hidden" name="sibia_billing_servizio"         value="SynchToFic">
                                <input type="hidden" name="sibia_billing_nuovo_piano"      value="professional">
                                <input type="hidden" name="sibia_billing_nuovo_intervallo" id="cambio-pro-intervallo" value="mensile">
                                <button type="submit" class="sibia-btn sibia-btn--primary sibia-abb-card__cta" data-role="cta"
                                    onclick="document.getElementById('cambio-pro-intervallo').value=document.querySelector('.sibia-abb-toggle__btn.is-active').getAttribute('data-interval');">
                                    Passa a Professional &rarr;
                                </button>
                            </form>
                            <?php else : ?>
                            <form method="post">
                                <input type="hidden" name="sibia_billing_nonce"    value="<?php echo esc_attr(wp_create_nonce('sibia_billing')); ?>">
                                <input type="hidden" name="sibia_billing_action"   value="checkout">
                                <input type="hidden" name="sibia_billing_servizio" value="SynchToFic">
                                <input type="hidden" name="sibia_billing_piano"    value="professional">
                                <input type="hidden" name="sibia_billing_intervallo" id="checkout-pro-intervallo" value="mensile">
                                <button type="submit" class="sibia-btn sibia-btn--primary sibia-abb-card__cta" data-role="cta"
                                    onclick="document.getElementById('checkout-pro-intervallo').value=document.querySelector('.sibia-abb-toggle__btn.is-active').getAttribute('data-interval');">
                                    Continua al pagamento sicuro &rarr;
                                </button>
                            </form>
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

/* ========================================================================
   SHORTCODE [sibia_prezzi] — Pagina pubblica prezzi e piani
   Uso: inserire [sibia_prezzi] in una pagina WordPress (es. /prezzi/)
   ======================================================================== */

add_shortcode('sibia_prezzi', function () {
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
            /* Legge i prezzi da ApiConnect */
            $_piani = sibia_get_prezzi_piani($codice);
            $prezzoMensile = $_piani['standard']['mensile']['euroMeseDisplay'] ?? 25;
            $prezzoAnnuale = $_piani['standard']['annuale']['euroTotale']      ?? 252;
            $urlMensile = home_url('/area-riservata/?section=fatturazione');
            $urlAnnuale = home_url('/area-riservata/?section=fatturazione');
            $risparmioAnno = ($prezzoMensile * 12) - $prezzoAnnuale;
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
