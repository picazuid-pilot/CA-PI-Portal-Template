<?php
/**
 * Minimal WebDAV client for Infomaniak kDrive. Works with plain
 * username/password authentication (Basic Auth) over HTTPS — no OAuth,
 * no service accounts, no quota restrictions like Google's Service Account.
 *
 * Only works if KDRIVE_ID, KDRIVE_USERNAME, and KDRIVE_PASSWORD are all
 * filled in in district.php. If any of the three is missing,
 * kdrive_available() simply returns 'false' and the rest of the
 * application falls back to local storage (or Google Drive, if that
 * does work).
 */

function kdrive_set_error_info($function, $info) {
    $GLOBALS['__kdrive_error'] = $function . ': ' . $info;
}
function kdrive_last_error() {
    return $GLOBALS['__kdrive_error'] ?? null;
}

function kdrive_available() {
    return defined('KDRIVE_ID') && KDRIVE_ID !== ''
        && defined('KDRIVE_USERNAME') && KDRIVE_USERNAME !== ''
        && defined('KDRIVE_PASSWORD') && KDRIVE_PASSWORD !== '';
}

function kdrive_webdav_base() {
    return 'https://' . KDRIVE_ID . '.connect.kdrive.infomaniak.com';
}

// Turns a path-with-subfolders into a correctly encoded WebDAV URL
// (encode each folder segment separately, otherwise slashes in the
// filename would break the URL).
function kdrive_url_for_path($relativePath) {
    $parts = array_map('rawurlencode', explode('/', $relativePath));
    return kdrive_webdav_base() . '/' . implode('/', $parts);
}

/**
 * Uploads a file to the configured kDrive folder via WebDAV PUT. Returns
 * ['pad' => ..., 'bestandsnaam' => ...] on success, or null on failure
 * (after which the caller falls back to another storage method).
 */
function kdrive_upload_file($tmpPath, $filename, $mimeType) {
    if (!kdrive_available()) return null;

    $content = file_get_contents($tmpPath);
    if ($content === false) {
        kdrive_set_error_info('kdrive_upload_file', 'could not read the temporary file');
        return null;
    }

    $folder = defined('KDRIVE_MAP') ? trim(KDRIVE_MAP, '/') : '';
    $safeName = veilige_token() . '_' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
    $relativePath = ($folder !== '' ? $folder . '/' : '') . $safeName;

    $ch = curl_init(kdrive_url_for_path($relativePath));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $content,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $mimeType, 'Expect:'],
        CURLOPT_USERPWD => KDRIVE_USERNAME . ':' . KDRIVE_PASSWORD,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!in_array($status, [200, 201, 204], true)) {
        kdrive_set_error_info('kdrive_upload_file', 'HTTP ' . $status . ' — cURL: ' . ($curlError ?: 'none') . ' — response: ' . substr((string)$response, 0, 300));
        return null;
    }

    return ['pad' => $relativePath, 'bestandsnaam' => $filename];
}

/**
 * Streams a file straight from kDrive back to the browser — the portal
 * keeps doing its own access control (require_login), the file doesn't
 * need to be public. Ends the request.
 */
function kdrive_stream_file($relativePath) {
    if (!kdrive_available()) { http_response_code(502); exit; }

    $ch = curl_init(kdrive_url_for_path($relativePath));
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => KDRIVE_USERNAME . ':' . KDRIVE_PASSWORD,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) { http_response_code(404); exit; }
    echo $response;
    exit;
}
