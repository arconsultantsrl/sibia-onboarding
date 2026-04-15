<?php
/**
 * SIBIA Onboarding — Chiamate al backend ApiConnect
 */

if (!defined('ABSPATH')) { exit; }

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
