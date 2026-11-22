<?php
/**
 * Minimale Google Drive-koppeling via een Service Account. Geen externe
 * library nodig (geen Composer) — gebruikt alleen cURL en OpenSSL, wat op
 * vrijwel elke PHP-hosting standaard beschikbaar is.
 *
 * Werkt alleen als:
 *  1. DRIVE_FOLDER_ID is ingevuld in district.php, EN
 *  2. data/google_service_account.json bestaat (het sleutelbestand van het
 *     Service Account, gedownload bij het aanmaken ervan in Google Cloud).
 *
 * Ontbreekt een van beide, dan doet drive_beschikbaar() gewoon 'false' en
 * valt de rest van de applicatie terug op lokale opslag — nooit een harde
 * fout, dit is puur een optionele laag bovenop wat al werkt.
 */

function drive_zet_foutinfo($functie, $info) {
    $GLOBALS['__drive_fout'] = $functie . ': ' . $info;
}
function drive_laatste_fout() {
    return $GLOBALS['__drive_fout'] ?? null;
}

/**
 * Lichte testaanvraag naar www.googleapis.com (los van de eigenlijke
 * upload) — om te onderscheiden of de hele verbinding naar dat adres
 * vastloopt, of alleen de upload zelf.
 */
function drive_test_verbinding() {
    $token = drive_access_token();
    if (!$token) return ['ok' => false, 'fout' => 'Kon geen toegangstoken ophalen: ' . (drive_laatste_fout() ?: 'onbekend')];

    $ch = curl_init('https://www.googleapis.com/drive/v3/about?fields=user');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // IPv6-verbindingsproblemen op sommige hosting omzeilen
    ]);
    $respons = curl_exec($ch);
    $curlFout = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200) return ['ok' => true];
    return ['ok' => false, 'fout' => 'HTTP ' . $status . ' — cURL: ' . ($curlFout ?: 'geen') . ' — ' . substr((string)$respons, 0, 200)];
}

function drive_service_account_pad() {
    return DATA_DIR . '/google_service_account.json';
}

function drive_beschikbaar() {
    return defined('DRIVE_FOLDER_ID') && DRIVE_FOLDER_ID !== '' && file_exists(drive_service_account_pad());
}

function drive_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Wisselt de Service Account-sleutel in voor een tijdelijk toegangstoken
 * (JWT Bearer-flow — het "wachtwoord" voor de rest van deze sessie/request).
 * Cachet 'm in-memory voor de duur van dit PHP-request; geen bestandscache
 * nodig, tokens zijn 1 uur geldig en dit gebeurt maar hooguit een paar keer
 * per pageview.
 */
function drive_access_token() {
    static $cache = null;
    if ($cache && $cache['verlooptOp'] > time() + 30) return $cache['token'];
    if (!drive_beschikbaar()) return null;

    $sleutel = json_decode(file_get_contents(drive_service_account_pad()), true);
    if (!$sleutel || empty($sleutel['private_key']) || empty($sleutel['client_email'])) return null;

    $nu = time();
    $header = drive_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = drive_base64url(json_encode([
        'iss' => $sleutel['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $nu,
        'exp' => $nu + 3600,
    ]));
    $signatureInput = $header . '.' . $claim;
    $handtekening = '';
    if (!openssl_sign($signatureInput, $handtekening, $sleutel['private_key'], 'sha256WithRSAEncryption')) return null;
    $jwt = $signatureInput . '.' . drive_base64url($handtekening);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_HTTPHEADER => ['Expect:'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
    ]);
    $respons = curl_exec($ch);
    $curlFout = curl_error($ch);
    curl_close($ch);
    if (!$respons) {
        drive_zet_foutinfo('drive_access_token (verbinding)', $curlFout ?: 'onbekende cURL-fout');
        return null;
    }

    $data = json_decode($respons, true);
    if (empty($data['access_token'])) {
        drive_zet_foutinfo('drive_access_token (Google-response)', $respons);
        return null;
    }

    $cache = ['token' => $data['access_token'], 'verlooptOp' => $nu + ($data['expires_in'] ?? 3600)];
    return $cache['token'];
}

/**
 * Uploadt een bestand naar de ingestelde Drive-map. Geeft bij succes
 * ['id' => ..., 'name' => ...] terug, of null bij een fout (waarna de
 * aanroeper terugvalt op lokale opslag).
 */
function drive_upload_bestand($tmpPad, $bestandsnaam, $mimeType) {
    $token = drive_access_token();
    if (!$token) return null;

    $metadata = json_encode(['name' => $bestandsnaam, 'parents' => [DRIVE_FOLDER_ID]]);
    $boundary = 'piwerkportaal' . bin2hex(random_bytes(8));
    $inhoud = file_get_contents($tmpPad);
    if ($inhoud === false) return null;

    $body = "--$boundary\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n" . $metadata . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: $mimeType\r\n\r\n" . $inhoud . "\r\n"
        . "--$boundary--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Expect:', // schakelt "Expect: 100-continue" uit — sommige hosting-netwerken blijven daarop hangen
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $respons = curl_exec($ch);
    $curlFout = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        drive_zet_foutinfo('drive_upload_bestand', 'HTTP ' . $status . ' — cURL: ' . ($curlFout ?: 'geen') . ' — response: ' . substr((string)$respons, 0, 300));
        return null;
    }

    $data = json_decode($respons, true);
    return !empty($data['id']) ? $data : null;
}

/**
 * Streamt een bestand rechtstreeks vanaf Drive terug naar de browser, zodat
 * het portaal zelf de toegangscontrole blijft doen (require_login) i.p.v.
 * dat het bestand publiek op Drive hoeft te staan. Beëindigt het request.
 */
function drive_stream_bestand($fileId) {
    $token = drive_access_token();
    if (!$token) { http_response_code(502); exit; }

    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?alt=media');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $respons = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) { http_response_code(404); exit; }
    echo $respons;
    exit;
}
