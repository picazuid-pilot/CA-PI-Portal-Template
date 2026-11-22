<?php
/**
 * Storage layer for files that members upload themselves into the Drive database
 * (a subcategory where members may add their own files).
 *
 * This is the ONLY place that knows WHERE such a file physically lives. The rest of
 * the portal only talks to leden_opslag_bewaar() / _stream() / _verwijder().
 *
 *  - Now:   'lokaal' = on the server in data/drive_database/leden/ (not directly
 *           reachable over the web; downloads go through api_drive.php behind the login).
 *  - Later: e.g. Box. Set in district.php  define('LEDEN_UPLOAD_BACKEND', 'box');
 *           build a lib_box.php and add a case 'box' to each function below.
 *           The page and the API do not need to change for that.
 *
 * Limits (optionally adjustable in district.php):
 *   LEDEN_UPLOAD_MAX_MB       per file               (default 10)
 *   LEDEN_UPLOAD_PER_LID_MB   total per member       (default 50)
 *   LEDEN_UPLOAD_TOTAAL_MB    total for all members  (default 200)
 */

function leden_opslag_backend() { return defined('LEDEN_UPLOAD_BACKEND') ? LEDEN_UPLOAD_BACKEND : 'lokaal'; }
function leden_upload_max_mb()     { return defined('LEDEN_UPLOAD_MAX_MB') ? (float)LEDEN_UPLOAD_MAX_MB : 10; }
function leden_upload_per_lid_mb() { return defined('LEDEN_UPLOAD_PER_LID_MB') ? (float)LEDEN_UPLOAD_PER_LID_MB : 50; }
function leden_upload_totaal_mb()  { return defined('LEDEN_UPLOAD_TOTAAL_MB') ? (float)LEDEN_UPLOAD_TOTAAL_MB : 200; }

function leden_opslag_dir() { return DATA_DIR . '/drive_database/leden'; }

/**
 * Allowed file types: extension => Content-Type on download.
 * Deliberately NO scripts, HTML, SVG, zip or executable files.
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
 * Checks that the CONTENT matches the extension (we do not trust the file name):
 * a "flyer.pdf" that is really a script is rejected.
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
        case 'txt': case 'csv': return strpos($kop, "\0") === false; // no binary content
    }
    return false;
}

/** Clean file name for display/download (no paths, no control characters, no quotes). */
function leden_schone_bestandsnaam($naam) {
    $naam = basename(str_replace('\\', '/', (string)$naam));
    $naam = preg_replace('/[\x00-\x1F\x7F"\'<>:*?|\/\\\\]/u', '', $naam);
    $naam = trim($naam);
    if ($naam === '' || $naam === '.' || $naam === '..') $naam = 'bestand';
    return mb_substr($naam, 0, 120);
}

/** Total space used (MB) by member uploads; optionally for one member only. */
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
 * Stores a freshly uploaded file. Returns a storage record that is kept in the
 * item, or null on failure.
 */
function leden_opslag_bewaar($tmpPad, $ext) {
    switch (leden_opslag_backend()) {
        // case 'box': return box_upload_bestand($tmpPad, $ext);   // later
        case 'lokaal':
        default:
            ensure_dir(leden_opslag_dir());
            $naam = veilige_token() . '.' . $ext; // random name + fixed, allowed extension
            $doel = leden_opslag_dir() . '/' . $naam;
            $ok = is_uploaded_file($tmpPad) ? move_uploaded_file($tmpPad, $doel) : @rename($tmpPad, $doel);
            return $ok ? ['opslag' => 'lokaal', 'pad' => $naam] : null;
    }
}

/** Sends the file to the browser and ends the script. */
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
