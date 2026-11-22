<?php
/**
 * Opslaglaag voor bestanden die leden zelf uploaden in de Drive-database
 * (subcategorie waar leden eigen bestanden mogen toevoegen).
 *
 * Dit is de ENIGE plek die weet WAAR zo'n bestand fysiek staat. De rest van het
 * portaal praat alleen met leden_opslag_bewaar() / _stream() / _verwijder().
 *
 *  - Nu:    'lokaal' = op de server in data/drive_database/leden/ (niet rechtstreeks
 *           via het web bereikbaar; downloaden loopt via api_drive.php achter de login).
 *  - Later: bijv. Box. Zet in district.php  define('LEDEN_UPLOAD_BACKEND', 'box');
 *           bouw een lib_box.php en voeg hieronder in elke functie een case 'box' toe.
 *           De pagina en de API hoeven daarvoor niet te veranderen.
 *
 * Limieten (optioneel aan te passen in district.php):
 *   LEDEN_UPLOAD_MAX_MB       per bestand            (standaard 10)
 *   LEDEN_UPLOAD_PER_LID_MB   totaal per lid         (standaard 50)
 *   LEDEN_UPLOAD_TOTAAL_MB    totaal alle leden      (standaard 200)
 */

function leden_opslag_backend() { return defined('LEDEN_UPLOAD_BACKEND') ? LEDEN_UPLOAD_BACKEND : 'lokaal'; }
function leden_upload_max_mb()     { return defined('LEDEN_UPLOAD_MAX_MB') ? (float)LEDEN_UPLOAD_MAX_MB : 10; }
function leden_upload_per_lid_mb() { return defined('LEDEN_UPLOAD_PER_LID_MB') ? (float)LEDEN_UPLOAD_PER_LID_MB : 50; }
function leden_upload_totaal_mb()  { return defined('LEDEN_UPLOAD_TOTAAL_MB') ? (float)LEDEN_UPLOAD_TOTAAL_MB : 200; }

function leden_opslag_dir() { return DATA_DIR . '/drive_database/leden'; }

/**
 * Toegestane bestandstypen: extensie => Content-Type bij downloaden.
 * Bewust GEEN scripts, HTML, SVG, zip of uitvoerbare bestanden.
 */
function leden_toegestane_typen() {
    return [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'txt'  => 'text/plain; charset=utf-8',
        'csv'  => 'text/csv; charset=utf-8',
    ];
}
function leden_afbeelding_extensies() { return ['jpg', 'jpeg', 'png', 'webp', 'gif']; }

/**
 * Controleert dat de INHOUD past bij de extensie (we vertrouwen de bestandsnaam niet):
 * een "flyer.pdf" die eigenlijk een script is wordt geweigerd.
 */
function leden_inhoud_klopt($pad, $ext) {
    $fh = @fopen($pad, 'rb');
    if (!$fh) return false;
    $kop = (string)fread($fh, 8192);
    fclose($fh);
    if ($kop === '') return false;
    switch ($ext) {
        case 'pdf':  return strpos(substr($kop, 0, 1024), '%PDF-') !== false;
        case 'jpg': case 'jpeg': return strncmp($kop, "\xFF\xD8\xFF", 3) === 0;
        case 'png':  return strncmp($kop, "\x89PNG\r\n\x1A\n", 8) === 0;
        case 'gif':  return strncmp($kop, 'GIF87a', 6) === 0 || strncmp($kop, 'GIF89a', 6) === 0;
        case 'webp': return strncmp($kop, 'RIFF', 4) === 0 && substr($kop, 8, 4) === 'WEBP';
        case 'doc': case 'xls': case 'ppt': return strncmp($kop, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
        case 'docx': case 'xlsx': case 'pptx': case 'odt': case 'ods': case 'odp': return strncmp($kop, "PK\x03\x04", 4) === 0;
        case 'txt': case 'csv': return strpos($kop, "\0") === false; // geen binaire inhoud
    }
    return false;
}

/** Schone bestandsnaam voor weergave/download (geen paden, geen stuurtekens, geen aanhalingstekens). */
function leden_schone_bestandsnaam($naam) {
    $naam = basename(str_replace('\\', '/', (string)$naam));
    $naam = preg_replace('/[\x00-\x1F\x7F"\'<>:*?|\/\\\\]/u', '', $naam);
    $naam = trim($naam);
    if ($naam === '' || $naam === '.' || $naam === '..') $naam = 'bestand';
    return mb_substr($naam, 0, 120);
}

/** Totaal gebruikte ruimte (MB) door lid-uploads; optioneel alleen van één lid. */
function leden_opslag_gebruikt_mb($uploaderId = null) {
    $bytes = 0;
    foreach (glob(DATA_DIR . '/drive_database/items/*.json') as $file) {
        $i = read_json($file, null);
        if (!$i || empty($i['ledenUpload']) || empty($i['bestand']['grootte'])) continue;
        if ($uploaderId !== null && ($i['uploaderId'] ?? '') !== $uploaderId) continue;
        $bytes += (int)$i['bestand']['grootte'];
    }
    return $bytes / 1024 / 1024;
}

/**
 * Bewaart een zojuist geüpload bestand. Geeft een opslag-record terug dat in het
 * item wordt bewaard, of null als het mislukt.
 */
function leden_opslag_bewaar($tmpPad, $ext) {
    switch (leden_opslag_backend()) {
        // case 'box': return box_upload_bestand($tmpPad, $ext);   // later
        case 'lokaal':
        default:
            ensure_dir(leden_opslag_dir());
            $naam = veilige_token() . '.' . $ext; // willekeurige naam + vaste, toegestane extensie
            $doel = leden_opslag_dir() . '/' . $naam;
            $ok = is_uploaded_file($tmpPad) ? move_uploaded_file($tmpPad, $doel) : @rename($tmpPad, $doel);
            return $ok ? ['opslag' => 'lokaal', 'pad' => $naam] : null;
    }
}

/** Stuurt het bestand naar de browser en beëindigt het script. */
function leden_opslag_stream($bestand, $weergaveNaam, $ext, $inline) {
    $typen = leden_toegestane_typen();
    $type = $typen[$ext] ?? 'application/octet-stream';
    switch ($bestand['opslag'] ?? 'lokaal') {
        // case 'box': box_stream_bestand($bestand); exit;   // later
        case 'lokaal':
        default:
            $pad = leden_opslag_dir() . '/' . basename((string)($bestand['pad'] ?? ''));
            if (empty($bestand['pad']) || !is_file($pad)) { http_response_code(404); exit; }
            $naam = leden_schone_bestandsnaam($weergaveNaam);
            header('Content-Type: ' . $type);
            header('Content-Length: ' . filesize($pad));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
                . '; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $naam) . '"; filename*=UTF-8\'\'' . rawurlencode($naam));
            readfile($pad);
            exit;
    }
}

function leden_opslag_verwijder($bestand) {
    switch ($bestand['opslag'] ?? 'lokaal') {
        // case 'box': return box_verwijder_bestand($bestand);   // later
        case 'lokaal':
        default:
            $pad = leden_opslag_dir() . '/' . basename((string)($bestand['pad'] ?? ''));
            if (!empty($bestand['pad']) && is_file($pad)) @unlink($pad);
    }
}
