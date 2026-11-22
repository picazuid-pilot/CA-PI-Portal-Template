<?php
/**
 * Minimale WebDAV-client voor Infomaniak kDrive. Werkt met gewone
 * gebruikersnaam/wachtwoord-authenticatie (Basic Auth) over HTTPS — geen
 * OAuth, geen service-accounts, geen quota-beperkingen zoals bij Google's
 * Service Account.
 *
 * Werkt alleen als KDRIVE_ID, KDRIVE_USERNAME en KDRIVE_PASSWORD allemaal
 * zijn ingevuld in district.php. Ontbreekt een van de drie, dan doet
 * kdrive_beschikbaar() gewoon 'false' en valt de rest van de applicatie
 * terug op lokale opslag (of Google Drive, als dat wél werkt).
 */

function kdrive_zet_foutinfo($functie, $info) {
    $GLOBALS['__kdrive_fout'] = $functie . ': ' . $info;
}
function kdrive_laatste_fout() {
    return $GLOBALS['__kdrive_fout'] ?? null;
}

function kdrive_beschikbaar() {
    return defined('KDRIVE_ID') && KDRIVE_ID !== ''
        && defined('KDRIVE_USERNAME') && KDRIVE_USERNAME !== ''
        && defined('KDRIVE_PASSWORD') && KDRIVE_PASSWORD !== '';
}

function kdrive_webdav_basis() {
    return 'https://' . KDRIVE_ID . '.connect.kdrive.infomaniak.com';
}

// Zet een pad-met-submappen om in een correct ge-encodeerde WebDAV-URL
// (elk mapdeel los encoderen, anders breken slashes in de URL).
function kdrive_url_voor_pad($relatiefPad) {
    $delen = array_map('rawurlencode', explode('/', $relatiefPad));
    return kdrive_webdav_basis() . '/' . implode('/', $delen);
}

/**
 * Uploadt een bestand naar de ingestelde kDrive-map via WebDAV PUT. Geeft
 * bij succes ['pad' => ..., 'bestandsnaam' => ...] terug, of null bij een
 * fout (waarna de aanroeper terugvalt op een andere opslagmethode).
 */
function kdrive_upload_bestand($tmpPad, $bestandsnaam, $mimeType) {
    if (!kdrive_beschikbaar()) return null;

    $inhoud = file_get_contents($tmpPad);
    if ($inhoud === false) {
        kdrive_zet_foutinfo('kdrive_upload_bestand', 'kon het tijdelijke bestand niet lezen');
        return null;
    }

    $map = defined('KDRIVE_MAP') ? trim(KDRIVE_MAP, '/') : '';
    $veiligeNaam = veilige_token() . '_' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', $bestandsnaam);
    $relatiefPad = ($map !== '' ? $map . '/' : '') . $veiligeNaam;

    $ch = curl_init(kdrive_url_voor_pad($relatiefPad));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $inhoud,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $mimeType, 'Expect:'],
        CURLOPT_USERPWD => KDRIVE_USERNAME . ':' . KDRIVE_PASSWORD,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $respons = curl_exec($ch);
    $curlFout = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($status, [200, 201, 204], true)) {
        kdrive_zet_foutinfo('kdrive_upload_bestand', 'HTTP ' . $status . ' — cURL: ' . ($curlFout ?: 'geen') . ' — response: ' . substr((string)$respons, 0, 300));
        return null;
    }

    return ['pad' => $relatiefPad, 'bestandsnaam' => $bestandsnaam];
}

/**
 * Streamt een bestand rechtstreeks vanaf kDrive terug naar de browser —
 * het portaal blijft zelf de toegangscontrole doen (require_login), het
 * bestand hoeft niet publiek te staan. Beëindigt het request.
 */
function kdrive_stream_bestand($relatiefPad) {
    if (!kdrive_beschikbaar()) { http_response_code(502); exit; }

    $ch = curl_init(kdrive_url_voor_pad($relatiefPad));
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => KDRIVE_USERNAME . ':' . KDRIVE_PASSWORD,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $respons = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) { http_response_code(404); exit; }
    echo $respons;
    exit;
}
