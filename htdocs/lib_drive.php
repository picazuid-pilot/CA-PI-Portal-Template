<?php
/**
 * Minimal Google Drive integration via a Service Account. No external
 * library needed (no Composer) — uses only cURL and OpenSSL, which are
 * available by default on virtually any PHP hosting.
 *
 * Only works if:
 *  1. DRIVE_FOLDER_ID is filled in in district.php, AND
 *  2. data/google_service_account.json exists (the Service Account's key
 *     file, downloaded when creating it in Google Cloud).
 *
 * If either is missing, drive_available() simply returns 'false' and the
 * rest of the application falls back to local storage — never a hard
 * error, this is purely an optional layer on top of what already works.
 */

function drive_set_error_info($function, $info) {
    $GLOBALS['__drive_error'] = $function . ': ' . $info;
}
function drive_last_error() {
    return $GLOBALS['__drive_error'] ?? null;
}

/**
 * Lightweight test request to www.googleapis.com (separate from the
 * actual upload) — to tell apart whether the whole connection to that
 * address is stuck, or just the upload itself.
 */
function drive_test_connection() {
    $token = drive_access_token();
    if (!$token) return ['ok' => false, 'error' => 'Could not obtain an access token: ' . (drive_last_error() ?: 'unknown')];

    $ch = curl_init('https://www.googleapis.com/drive/v3/about?fields=user');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // works around IPv6 connectivity issues on some hosting
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200) return ['ok' => true];
    return ['ok' => false, 'error' => 'HTTP ' . $status . ' — cURL: ' . ($curlError ?: 'none') . ' — ' . substr((string)$response, 0, 200)];
}

function drive_service_account_path() {
    return DATA_DIR . '/google_service_account.json';
}

function drive_available() {
    return defined('DRIVE_FOLDER_ID') && DRIVE_FOLDER_ID !== '' && file_exists(drive_service_account_path());
}

function drive_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Exchanges the Service Account key for a temporary access token (JWT
 * Bearer flow — the "password" for the rest of this session/request).
 * Cached in-memory for the duration of this PHP request; no file cache
 * needed, tokens are valid for 1 hour and this happens at most a few
 * times per page view.
 */
function drive_access_token() {
    static $cache = null;
    if ($cache && $cache['expiresAt'] > time() + 30) return $cache['token'];
    if (!drive_available()) return null;

    $key = json_decode(file_get_contents(drive_service_account_path()), true);
    if (!$key || empty($key['private_key']) || empty($key['client_email'])) return null;

    $now = time();
    $header = drive_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = drive_base64url(json_encode([
        'iss' => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $signatureInput = $header . '.' . $claim;
    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $key['private_key'], 'sha256WithRSAEncryption')) return null;
    $jwt = $signatureInput . '.' . drive_base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_HTTPHEADER => ['Expect:'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if (!$response) {
        drive_set_error_info('drive_access_token (connection)', $curlError ?: 'unknown cURL error');
        return null;
    }

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        drive_set_error_info('drive_access_token (Google response)', $response);
        return null;
    }

    $cache = ['token' => $data['access_token'], 'expiresAt' => $now + ($data['expires_in'] ?? 3600)];
    return $cache['token'];
}

/**
 * Uploads a file to the configured Drive folder. Returns
 * ['id' => ..., 'name' => ...] on success, or null on failure (after
 * which the caller falls back to local storage).
 */
function drive_upload_file($tmpPath, $filename, $mimeType) {
    $token = drive_access_token();
    if (!$token) return null;

    $metadata = json_encode(['name' => $filename, 'parents' => [DRIVE_FOLDER_ID]]);
    $boundary = 'piworkportal' . bin2hex(random_bytes(8));
    $content = file_get_contents($tmpPath);
    if ($content === false) return null;

    $body = "--$boundary\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n" . $metadata . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: $mimeType\r\n\r\n" . $content . "\r\n"
        . "--$boundary--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Expect:', // disables "Expect: 100-continue" — some hosting networks hang on it
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        drive_set_error_info('drive_upload_file', 'HTTP ' . $status . ' — cURL: ' . ($curlError ?: 'none') . ' — response: ' . substr((string)$response, 0, 300));
        return null;
    }

    $data = json_decode($response, true);
    return !empty($data['id']) ? $data : null;
}

/**
 * Streams a file straight from Drive back to the browser, so the portal
 * itself keeps doing access control (require_login) instead of the file
 * needing to be public on Drive. Ends the request.
 */
function drive_stream_file($fileId) {
    $token = drive_access_token();
    if (!$token) { http_response_code(502); exit; }

    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($fileId) . '?alt=media');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) { http_response_code(404); exit; }
    echo $response;
    exit;
}
