<?php
/**
 * Core settings and shared helper functions for the PI Work Portal backend.
 * Only edit district.php; you can leave this file alone.
 *
 * TRANSLATION STATUS (template project): this file's comments are in
 * English. The role-related identifiers (function fallbacks, colour
 * keys) now match district.php's English role keys — that rename
 * happened ahead of the original schedule while the file count was
 * still small (see docs/translation-plan.md). Everything else in this
 * file (other function/variable names, data folder names, e.g.
 * heeft_rol(), read_json(), DATA_DIR . '/locaties') is still using the
 * original Dutch identifiers, since every not-yet-translated file still
 * calls these exact names — renaming those requires a coordinated pass
 * across all remaining files.
 */

// All district-specific settings (name, domain, colours, invite code,
// Drive links, SMTP for email notifications, etc.) live in district.php.
// That is the ONLY file a District IT servant needs to edit per district
// to adopt this template for another district.
require __DIR__ . '/district.php';

// Folder where all data is stored (users, books, translations, notes,
// attachments). Should sit OUTSIDE the publicly reachable part of your
// hosting if possible; if not, data/.htaccess protects it.
define('DATA_DIR', __DIR__ . '/data');

// Some free hosting providers (e.g. InfinityFree) don't allow PHP to
// write to the server's default session folder — you'd get a
// "Permission denied" error on login. To avoid that we simply store
// sessions in our own (already protected) data folder.
$sessionDir = __DIR__ . '/data/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0775, true);
}
session_save_path($sessionDir);

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30, // stay logged in for 30 days
    'path' => '/',
    'samesite' => 'Lax',
]);
session_start();

// Fixed, built-in demo account (username "dummy", password "dummy") — no
// real account in users.json, purely a session marker. Allowed to VIEW
// everything, but cannot perform any write action. That is enforced
// centrally here so individual files don't each need their own check:
// every POST request (= every create/update/delete/upload across the
// whole application) is blocked, except logging in/out itself (which
// goes through auth.php and must always keep working).
define('DEMO_ACCOUNT_ID', 'demo-dummy-account');
function is_demo_gebruiker() {
    return ($_SESSION['user_id'] ?? '') === DEMO_ACCOUNT_ID;
}
if (is_demo_gebruiker() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'auth.php') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['error' => 'This is the demo account — viewing only. Changing, creating, submitting, or registering is not possible with it.']);
    exit;
}

// NOTE: the JSON content-type header is NOT set here, because config.php
// is also included by plain HTML pages (index.php, locations.php) that
// rely on the session. Every *API* file (auth.php, storage.php,
// api_locations.php) sets it itself, right after including config.php.

function data_path($rel) {
    $safe = str_replace(['..', "\0"], '', $rel);
    return DATA_DIR . '/' . ltrim($safe, '/');
}

function ensure_dir($path) {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function read_json($path, $fallback) {
    if (!file_exists($path)) return $fallback;
    $fh = fopen($path, 'r');
    if (!$fh) return $fallback;
    flock($fh, LOCK_SH);
    $content = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode($content, true);
    return $data === null ? $fallback : $data;
}

function write_json($path, $value) {
    ensure_dir(dirname($path));
    $fh = fopen($path, 'c');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function json_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_login() {
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'not logged in'], 401);
    }
}

function current_user_slug() {
    // Safe, unique folder name for this user's personal storage.
    return preg_replace('/[^a-z0-9]/', '', strtolower($_SESSION['user_id'] ?? 'anon'));
}

function current_user_role() {
    // Backwards compatible: returns the FIRST role (for simple label
    // display). Always use current_user_roles()/require_role() for
    // access control, since someone can now hold multiple roles at once.
    $rollen = current_user_roles();
    return $rollen[0] ?? 'liaison';
}

function current_user_roles() {
    return $_SESSION['user_roles'] ?? ['liaison'];
}

function heeft_rol($toegestaneRollen) {
    return count(array_intersect(current_user_roles(), $toegestaneRollen)) > 0;
}

// Default colours per role and per agenda item type — only IT can change
// these via Member Management. These are the fallback values if nothing
// has been saved yet; kleuren_pad() stores the real, customised colours.
// Keys match the ROLES keys in district.php.
const STANDAARD_KLEUREN = [
    'admin' => '#6b46c1',
    'chair' => '#00594F',
    'treasurer' => '#b5533f',
    'secretary' => '#2f6f9e',
    'coordinator_distribution' => '#c9903f',
    'coordinator_outreach' => '#2f8f4e',
    'coordinator_literature' => '#7a5c3e',
    'media_coordinator' => '#9c4f6e',
    'social_media_coordinator' => '#4f7a9c',
    'liaison' => '#6b7573',
    'voorlichting_event' => '#f0c477',
    'district_vergadering' => '#3b6fa0',
    'area_vergadering' => '#a0523b',
];

function kleuren_pad() {
    return DATA_DIR . '/kleuren.json';
}

function haal_kleuren_op() {
    return array_merge(STANDAARD_KLEUREN, read_json(kleuren_pad(), []));
}

// Converts a user record to the "roles" array format, even if it still
// has the old, single "role" field (from before multiple simultaneous
// roles were possible) — so existing data never needs manual migration.
function normaliseer_gebruiker_rollen($u) {
    if (!isset($u['roles']) || !is_array($u['roles'])) {
        $u['roles'] = [$u['role'] ?? 'liaison'];
    }
    return $u;
}

function require_role($allowedRoles) {
    require_login();
    if (!heeft_rol($allowedRoles)) {
        respond(['error' => 'You are not permitted to perform this action.'], 403);
    }
}

function uuid() {
    return bin2hex(random_bytes(8)) . dechex(time());
}

/**
 * Cryptographically secure, unguessable token — used specifically for
 * things like password-reset links, where uuid() (partly time-based) is
 * not strong enough.
 */
function veilige_token() {
    return bin2hex(random_bytes(32));
}

/**
 * Minimal SMTP client (no external library needed — handy since shared
 * hosting like InfinityFree usually has no Composer/exec). Supports
 * STARTTLS (port 587, e.g. Gmail) and direct SSL (port 465).
 * Returns true/false; never throws an error out to the user.
 */
function smtp_send($to, $subject, $body) {
    if (!defined('SMTP_HOST') || SMTP_HOST === '') return false;

    $host = (int)SMTP_PORT === 465 ? 'ssl://' . SMTP_HOST : SMTP_HOST;
    $smtp = @stream_socket_client($host . ':' . SMTP_PORT, $errno, $errstr, 10);
    if (!$smtp) return false;
    stream_set_timeout($smtp, 10);

    $read = function () use ($smtp) {
        $data = '';
        while (($line = fgets($smtp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($text) use ($smtp, $read) {
        fwrite($smtp, $text . "\r\n");
        return $read();
    };

    $domein = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
    $read(); // server greeting (220)
    $cmd("EHLO $domein");

    if ((int)SMTP_PORT !== 465) {
        $cmd("STARTTLS");
        if (!@stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($smtp);
            return false;
        }
        $cmd("EHLO $domein");
    }

    $cmd("AUTH LOGIN");
    $cmd(base64_encode(SMTP_USER));
    $authResp = $cmd(base64_encode(SMTP_PASS));
    if (strpos($authResp, '235') !== 0) { fclose($smtp); return false; } // login failed

    $cmd("MAIL FROM:<" . SMTP_FROM . ">");
    $cmd("RCPT TO:<$to>");
    $cmd("DATA");

    $headers = "From: " . DISTRICT_NAME . " <" . SMTP_FROM . ">\r\n"
        . "To: <$to>\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
    fwrite($smtp, $headers . str_replace("\n.", "\n..", $body) . "\r\n.\r\n");
    $read();
    $cmd("QUIT");
    fclose($smtp);
    return true;
}

/**
 * Sends a notification to one or more users: always as an in-portal
 * message (data/berichten/{user}.json), and optionally also by email if
 * district.php has an SMTP configuration set up. If the email fails
 * (e.g. because the host doesn't allow it, or Gmail rejects the login),
 * the in-portal message still stands — that should never block the rest
 * of the action.
 */
function notify($userIds, $titel, $tekst, $link = null) {
    foreach ((array)$userIds as $userId) {
        if (!$userId) continue;
        $path = DATA_DIR . '/berichten/' . preg_replace('/[^a-z0-9@._\-]/i', '_', $userId) . '.json';
        $berichten = read_json($path, []);
        array_unshift($berichten, [
            'id' => uuid(),
            'titel' => $titel,
            'tekst' => $tekst,
            'link' => $link,
            'gelezen' => false,
            'createdAt' => time() * 1000,
        ]);
        $berichten = array_slice($berichten, 0, 200);
        write_json($path, $berichten);
    }

    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
        $users = read_json(DATA_DIR . '/users.json', []);
        foreach ($users as $u) {
            if (in_array($u['id'], (array)$userIds, true) && !empty($u['email'])) {
                @smtp_send($u['email'], $titel, $tekst . ($link ? "\n\n" . APP_URL . $link : ''));
            }
        }
    }
}

/**
 * Sends a notification to everyone holding a given role (e.g. all Print
 * Coordinators), plus always to all admins. If no one currently holds
 * that role yet (member management still needs to assign it), this
 * falls back to the fixed email address in district.php
 * (COORDINATOR_EMAILS) so a notification is never simply lost.
 */
function notify_role($role, $titel, $tekst, $link = null) {
    $users = read_json(DATA_DIR . '/users.json', []);
    $ontvangers = array_column(array_filter($users, fn($u) => ($u['role'] ?? 'liaison') === $role || ($u['role'] ?? '') === 'admin'), 'id');
    if (!empty($ontvangers)) {
        notify($ontvangers, $titel, $tekst, $link);
        return;
    }
    if (defined('COORDINATOR_EMAILS') && !empty(COORDINATOR_EMAILS[$role])) {
        @smtp_send(COORDINATOR_EMAILS[$role], $titel, $tekst);
    }
}

/**
 * Sends a notification to ALL registered PI members (e.g. the call-out
 * "who wants to co-present this outreach talk?"). $exclUserId optionally
 * excludes the person who initiated it.
 */
function notify_alle_leden($titel, $tekst, $link = null, $exclUserId = null) {
    $users = read_json(DATA_DIR . '/users.json', []);
    $ids = array_column(array_filter($users, fn($u) => $u['id'] !== $exclUserId), 'id');
    if (!empty($ids)) notify($ids, $titel, $tekst, $link);
}

ensure_dir(DATA_DIR);
ensure_dir(DATA_DIR . '/storage/shared');
ensure_dir(DATA_DIR . '/storage/users');
ensure_dir(DATA_DIR . '/locaties');
ensure_dir(DATA_DIR . '/campagnes');
ensure_dir(DATA_DIR . '/campagne_targets');
ensure_dir(DATA_DIR . '/campagne_ideeen');
ensure_dir(DATA_DIR . '/campagne_ideeen/materialen');
ensure_dir(DATA_DIR . '/berichten');
ensure_dir(DATA_DIR . '/drukwerk');
ensure_dir(DATA_DIR . '/drukwerk/aanvragen');
ensure_dir(DATA_DIR . '/drukwerk/bestellingen');
ensure_dir(DATA_DIR . '/wachtwoord_resets');
ensure_dir(DATA_DIR . '/voorlichting/aanvragen');
ensure_dir(DATA_DIR . '/agenda');
ensure_dir(DATA_DIR . '/todos');
ensure_dir(DATA_DIR . '/financien/transacties');
ensure_dir(DATA_DIR . '/financien/aanvragen');
ensure_dir(DATA_DIR . '/financien/bijlagen');
ensure_dir(DATA_DIR . '/financien/relaties');
ensure_dir(DATA_DIR . '/financien/posten');
ensure_dir(DATA_DIR . '/financien/reroutes');
ensure_dir(DATA_DIR . '/financien/derving');
ensure_dir(DATA_DIR . '/drive_database/items');
ensure_dir(DATA_DIR . '/drive_database/thumbnails');
ensure_dir(DATA_DIR . '/profielfotos');
