<?php
/**
 * SIBIA Onboarding — Contratti, termini, guide installazione
 */

if (!defined('ABSPATH')) { exit; }

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
