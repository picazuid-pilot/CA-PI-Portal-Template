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
// Beveiliging: data/ bevat gebruikers, wachtwoord-hashes, financiële gegevens en
// sessies en mag NOOIT rechtstreeks via het web te downloaden zijn. Op Apache
// (ook InfinityFree) doet een .htaccess dat; we maken die hier zelf aan als
// hij ontbreekt, zodat het niet afhangt van een handmatige stap bij het uploaden.
function bescherm_datamap() {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht) || filesize($ht) < 10) {
        @file_put_contents($ht,
            "# Automatically created by the portal: this folder must never be reachable via the web.\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    if (!file_exists(DATA_DIR . '/index.html')) @file_put_contents(DATA_DIR . '/index.html', '');
}
bescherm_datamap();

// Some free hosting providers (e.g. InfinityFree) don't allow PHP to write
// to the server's default session folder — you'd get a "Permission denied"
// error on login. To avoid that we store sessions in our own (protected)
// data folder.
$sessionDir = __DIR__ . '/data/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0775, true);
}
session_save_path($sessionDir);

$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');   // geen sessie-ID's accepteren die de server niet zelf heeft uitgegeven
ini_set('session.use_only_cookies', '1');  // nooit een sessie-ID uit de URL
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30, // stay logged in for 30 days
    'path' => '/',
    'secure' => $__https,   // cookie alleen via https meesturen zodra de site via https wordt bezocht
    'httponly' => true,     // niet uit te lezen door JavaScript (beperkt de schade van XSS)
    'samesite' => 'Lax',
]);
session_start();

// Standaard beveiligingskoppen (voor elke pagina en API die config.php laadt).
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');          // browser mag bestandstypes niet "raden"
    header('X-Frame-Options: SAMEORIGIN');              // portaal kan niet in een frame op een andere site
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// OFF BY DEFAULT. Enable with: define('DEMO_ACCOUNT_ENABLED', true); in district.php.
// Fixed, built-in demo account (username "dummy", password "dummy") — no
// real account in users.json, purely a session marker. Allowed to VIEW
// everything, but cannot perform any write action. That is enforced
// centrally here so individual files don't each need their own check:
// every request that is not GET/HEAD (= every create/update/delete/upload across the
// whole application) is blocked, except logging in/out itself (which
// goes through auth.php and must always keep working).
define('DEMO_ACCOUNT_ID', 'demo-dummy-account');
function is_demo_gebruiker() {
    return ($_SESSION['user_id'] ?? '') === DEMO_ACCOUNT_ID;
}
if (is_demo_gebruiker() && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'auth.php') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['error' => 'This is the demo account — viewing only. Changing, creating, submitting, or registering is not possible with it.']);
    exit;
}

// State-changing actions must NEVER run through a plain GET request. A GET can
// be triggered by a link, image or redirect on another site (CSRF) and used to
// bypass the demo block above, which only looked at the request method.
// Everything in api_*.php / auth.php / storage.php whose action name contains
// a changing verb therefore requires POST (or PUT/DELETE).
// New changing action? Give it a name with such a verb (create, update, delete,
// upload, bijwerken, verwijderen, ...) and this works automatically.
define('MUTERENDE_ACTIE_PATROON', '/(create|update|delete|upsert|import|upload|bijwerken|verwijderen|aanmaken|herordenen|verplaatsen|besluit|afwijzen|goedkeuren|inplannen|aanmelden|afmelden|toggle|verifieren|afhandelen|uitbetaald|indienen|vervangen|hernoemen|samenvoegen|wissen|herstellen|versturen|intrekken|beslissen|_add|abonnement)|^(reset|set)$/');
$__script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__actie = (string)($_GET['action'] ?? '');
if ($__actie !== ''
    && (strpos($__script, 'api_') === 0 || in_array($__script, ['auth.php', 'storage.php'], true))
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
    && preg_match(MUTERENDE_ACTIE_PATROON, $__actie)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'This action requires a POST request.']);
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

// The roles of the logged-in user, re-read from users.json on every request:
// a role that IT revokes (or a deleted account) takes effect immediately, not only
// at the next login.
function gebruiker_basisrollen() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $uid = $_SESSION['user_id'] ?? '';
    $rollen = $_SESSION['user_roles'] ?? ['liaison'];
    if ($uid !== '' && $uid !== DEMO_ACCOUNT_ID) {
        $gevonden = false;
        foreach (read_json(DATA_DIR . '/users.json', []) as $u) {
            if (($u['id'] ?? null) === $uid) { $rollen = normaliseer_gebruiker_rollen($u)['roles']; $gevonden = true; break; }
        }
        if (!$gevonden) $rollen = ['liaison']; // account was deleted: no special role any more
        $_SESSION['user_roles'] = $rollen;
    }
    return $cache = $rollen;
}

// Permanent IT administrator (role 'admin' on the account itself), so NOT someone with temporary IT rights.
// For actions with lasting consequences for accounts or IT rights.
function heeft_vaste_it() {
    return in_array('admin', gebruiker_basisrollen(), true);
}

// Temporary IT rights (requested by a member, approved by IT via a link in the email).
function it_tijdelijk_pad() { return DATA_DIR . '/it_tijdelijk.json'; }
function it_tijdelijk_actief_tot($userId) {
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $nu = time() * 1000; $tot = null;
    foreach (read_json(it_tijdelijk_pad(), []) as $a) {
        if (($a['userId'] ?? '') === $userId && ($a['status'] ?? '') === 'goedgekeurd' && ($a['geldigTot'] ?? 0) > $nu) {
            $tot = max($tot ?? 0, $a['geldigTot']);
        }
    }
    return $cache[$userId] = $tot;
}

function current_user_roles() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rollen = gebruiker_basisrollen();
    $uid = $_SESSION['user_id'] ?? '';
    if ($uid !== '' && $uid !== DEMO_ACCOUNT_ID && !in_array('admin', $rollen, true) && it_tijdelijk_actief_tot($uid)) $rollen[] = 'admin';
    return $cache = $rollen;
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

/**
 * Only allow http(s) links. Prevents someone from saving a "javascript:..."
 * address as a download link and running code for whoever clicks it.
 * Returns an empty string if it is not a valid http(s) URL.
 */
function veilige_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    // "www.example.com" without https:// is common input: add it ourselves.
    if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) && preg_match('#^[a-z0-9.\-]+\.[a-z]{2,}(/.*)?$#i', $url)) $url = 'https://' . $url;
    if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) return '';
    return $url;
}

/** Visitor IP address (behind Cloudflare the real address is in a header). */
function klant_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $k) {
        $ip = $_SERVER[$k] ?? '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

/**
 * Simple request throttling (no database): keeps timestamps per key in
 * data/rate_limit.json. rate_limit_toegestaan() only checks,
 * rate_limit_registreer() counts an attempt. $venster is in seconds (max 1 hour).
 */
function rate_limit_toegestaan($sleutel, $max, $venster) {
    $nu = time();
    $data = read_json(DATA_DIR . '/rate_limit.json', []);
    $tijden = array_filter($data[$sleutel] ?? [], fn($t) => $t > $nu - $venster);
    return count($tijden) < $max;
}
function rate_limit_registreer($sleutel) {
    $pad = DATA_DIR . '/rate_limit.json';
    $nu = time();
    $data = read_json($pad, []);
    foreach ($data as $k => $tijden) {
        $data[$k] = array_values(array_filter($tijden, fn($t) => $t > $nu - 3600));
        if (!$data[$k]) unset($data[$k]);
    }
    $data[$sleutel][] = $nu;
    write_json($pad, $data);
}
function rate_limit_wis($sleutel) {
    $pad = DATA_DIR . '/rate_limit.json';
    $data = read_json($pad, []);
    if (isset($data[$sleutel])) { unset($data[$sleutel]); write_json($pad, $data); }
}
function te_veel_pogingen() {
    respond(['error' => 'Too many attempts. Please try again in fifteen minutes.'], 429);
}

/** For test/helper scripts: logged-in IT admins only, never the demo account. */
function require_it_pagina() {
    if (empty($_SESSION['user_id']) || is_demo_gebruiker() || !heeft_rol(['admin'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Only for logged-in IT admins (not the demo account). Log in to the portal first.\n";
        exit;
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
function notify($userIds, $titel, $tekst, $link = null, $mail = true) {
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

    if ($mail && defined('SMTP_HOST') && SMTP_HOST !== '') {
        $users = read_json(DATA_DIR . '/users.json', []);
        foreach ($users as $u) {
            if (in_array($u['id'], (array)$userIds, true) && !empty($u['email'])) {
                @smtp_send($u['email'], $titel, $tekst . ($link ? "\n\n" . APP_URL . $link : ''));
            }
        }
    }
}

function notify_zonder_mail($userIds, $titel, $tekst, $link = null) { notify($userIds, $titel, $tekst, $link, false); }

/**
 * Sends a notification to everyone holding a given role (e.g. all Print
 * Coordinators), plus always to all admins. Role holders follow automatically
 * from Member Management (assign a role = that person receives the
 * notifications from then on). If no one holds the role yet, this falls back
 * to the optional fixed email address in district.php (COORDINATOR_EMAILS).
 */
function notify_role($role, $titel, $tekst, $link = null) {
    $users = read_json(DATA_DIR . '/users.json', []);
    $houders = []; $admins = [];
    foreach ($users as $u) {
        $rollen = normaliseer_gebruiker_rollen($u)['roles'];
        if (in_array($role, $rollen, true)) $houders[] = $u['id'];
        if (in_array('admin', $rollen, true)) $admins[] = $u['id'];
    }
    // Whoever holds the role (via Member Management) receives the notification automatically, plus IT.
    $ontvangers = array_values(array_unique(array_merge($houders, $admins)));
    if (!empty($ontvangers)) notify($ontvangers, $titel, $tekst, $link);
    // If no one holds the role yet, fall back to the (optional) fixed address in district.php.
    if (empty($houders) && defined('COORDINATOR_EMAILS') && !empty(COORDINATOR_EMAILS[$role])) {
        @smtp_send(COORDINATOR_EMAILS[$role], $titel, $tekst . ($link ? "\n\n" . APP_URL . $link : ''));
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
ensure_dir(DATA_DIR . '/drive_database/subcategorieen');
ensure_dir(DATA_DIR . '/profielfotos');
