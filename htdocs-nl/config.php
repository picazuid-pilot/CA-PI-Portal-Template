<?php
/**
 * Basisinstellingen voor het PI Werkportaal-backend.
 * Pas ALLEEN district.php aan; dit bestand kun je met rust laten.
 */

// Alle district-specifieke instellingen (naam, domein, kleuren, uitnodigingscode,
// Drive-links, SMTP voor e-mailmeldingen, etc.) staan in district.php. Dat is
// het ENIGE bestand dat de District-IT per district hoeft aan te passen om
// deze template over te nemen voor een ander district.
require __DIR__ . '/district.php';

// Map waar alle gegevens komen te staan (gebruikers, boeken, vertalingen,
// notities, bijlagen). Moet BUITEN het publiek toegankelijke deel staan
// als dat op jouw hosting kan; zo niet, dan beschermt data/.htaccess hem.
define('DATA_DIR', __DIR__ . '/data');

// Sommige gratis hosting (o.a. InfinityFree) staat PHP niet toe om naar de
// standaard sessie-map van de server te schrijven — dan krijg je bij het
// inloggen een "Permission denied"-foutmelding. Om dat te vermijden zetten
// we de sessies gewoon in onze eigen (al beveiligde) datamap.
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

// Sommige gratis hosting (o.a. InfinityFree) staat PHP niet toe om naar de
// standaard sessie-map van de server te schrijven — dan krijg je bij het
// inloggen een "Permission denied"-foutmelding. Om dat te vermijden zetten
// we de sessies gewoon in onze eigen (al beveiligde) datamap.
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
    'lifetime' => 60 * 60 * 24 * 30, // 30 dagen ingelogd blijven
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

// STANDAARD UIT. Aanzetten: define('DEMO_ACCOUNT_ENABLED', true); in district.php.
// Vast, ingebakken demo-account (gebruikersnaam "dummy", wachtwoord
// "dummy") — geen echt account in users.json, puur een sessie-markering.
// Mag overal KIJKEN, maar geen enkele schrijf-actie uitvoeren. Dat laatste
// wordt hier centraal afgedwongen, zodat niet elk los bestand dit apart
// hoeft te controleren: elke aanroep die niet GET/HEAD is (= elke create/update/delete/
// upload in de hele applicatie) wordt geblokkeerd, behalve inloggen/
// uitloggen zelf (die lopen via auth.php en moeten altijd blijven werken).
define('DEMO_ACCOUNT_ID', 'demo-dummy-account');
function is_demo_gebruiker() {
    return ($_SESSION['user_id'] ?? '') === DEMO_ACCOUNT_ID;
}
if (is_demo_gebruiker() && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'auth.php') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['error' => 'Dit is het demo-account — alleen bekijken is mogelijk. Wijzigen, aanmaken, indienen of registreren kan hiermee niet.']);
    exit;
}

// Wijzigende acties mogen NOOIT via een gewone GET-aanvraag lopen. Een GET kan
// door een link, afbeelding of redirect op een andere site worden afgevuurd
// (CSRF) en omzeilde de demo-blokkade hierboven, die alleen naar de aanvraag-
// methode keek. Alles in api_*.php / auth.php / storage.php waarvan de
// actienaam een wijzigend werkwoord bevat, eist daarom POST (of PUT/DELETE).
// Nieuwe wijzigende actie? Geef 'm een naam met zo'n werkwoord (create, update,
// delete, upload, bijwerken, verwijderen, ...) en dit werkt vanzelf.
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
    echo json_encode(['error' => 'Deze actie vereist een POST-aanvraag.']);
    exit;
}

// LET OP: de JSON content-type header staat NIET hier, want config.php wordt
// nu ook geïnclude't door gewone HTML-pagina's (index.php, locaties.php) die
// gebruik maken van de sessie. Elk *API*-bestand (auth.php, storage.php,
// api_locaties.php) zet 'm zelf, direct na het includen van config.php.

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
        respond(['error' => 'niet ingelogd'], 401);
    }
}

function current_user_slug() {
    // Veilige, unieke mapnaam voor de persoonlijke opslag van deze gebruiker.
    return preg_replace('/[^a-z0-9]/', '', strtolower($_SESSION['user_id'] ?? 'anon'));
}

function current_user_role() {
    // Backwards compatible: geeft de EERSTE rol terug (voor simpele label-
    // weergave). Voor rechtencontrole altijd current_user_roles()/require_role()
    // gebruiken, want iemand kan tegenwoordig meerdere rollen tegelijk hebben.
    $rollen = current_user_roles();
    return $rollen[0] ?? 'lid';
}

// De rollen van de ingelogde gebruiker, elke aanvraag opnieuw uit users.json gelezen:
// een rol die IT intrekt (of een verwijderd account) werkt dus meteen, niet pas bij
// de volgende keer inloggen.
function gebruiker_basisrollen() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $uid = $_SESSION['user_id'] ?? '';
    $rollen = $_SESSION['user_roles'] ?? ['lid'];
    if ($uid !== '' && $uid !== DEMO_ACCOUNT_ID) {
        $gevonden = false;
        foreach (read_json(DATA_DIR . '/users.json', []) as $u) {
            if (($u['id'] ?? null) === $uid) { $rollen = normaliseer_gebruiker_rollen($u)['roles']; $gevonden = true; break; }
        }
        if (!$gevonden) $rollen = ['lid']; // account is verwijderd: geen enkele bijzondere rol meer
        $_SESSION['user_roles'] = $rollen;
    }
    return $cache = $rollen;
}

// Vaste IT-beheerder (rol 'admin' op het account zelf), dus NIET iemand met tijdelijke IT-rechten.
// Voor acties die blijvende gevolgen hebben voor accounts of IT-rechten.
function heeft_vaste_it() {
    return in_array('admin', gebruiker_basisrollen(), true);
}

// Tijdelijke IT-rechten (aangevraagd door een lid, goedgekeurd door IT via een link in de e-mail).
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

// Standaard-kleuren per rol en per soort agendapunt — alleen IT kan deze
// via Ledenbeheer aanpassen. Dit zijn de vangnet-waarden als er nog niets
// is opgeslagen; kleuren_pad() bewaart de echte, aangepaste kleuren.
const STANDAARD_KLEUREN = [
    'admin' => '#6b46c1',
    'voorzitter' => '#00594F',
    'penningmeester' => '#b5533f',
    'secretaris' => '#2f6f9e',
    'coordinator_drukwerk' => '#c9903f',
    'coordinator_voorlichting' => '#2f8f4e',
    'coordinator_literatuur' => '#7a5c3e',
    'media_coordinator' => '#9c4f6e',
    'social_media_coordinator' => '#4f7a9c',
    'lid' => '#6b7573',
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

// Zet een gebruikersrecord om naar het "roles"-array-formaat, ook als het
// nog het oude, enkelvoudige "role"-veld heeft (van vóór meerdere rollen
// tegelijk mogelijk werden) — zo hoeft bestaande data niet handmatig
// aangepast te worden.
function normaliseer_gebruiker_rollen($u) {
    if (!isset($u['roles']) || !is_array($u['roles'])) {
        $u['roles'] = [$u['role'] ?? 'lid'];
    }
    return $u;
}

function require_role($allowedRoles) {
    require_login();
    if (!heeft_rol($allowedRoles)) {
        respond(['error' => 'Geen toestemming voor deze actie.'], 403);
    }
}

/**
 * Alleen http(s)-links toelaten. Voorkomt dat iemand een "javascript:..."-adres
 * als downloadlink opslaat en daarmee code uitvoert bij wie erop klikt.
 * Geeft een lege string terug als het geen geldige http(s)-URL is.
 */
function veilige_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    // "www.voorbeeld.nl" zonder https:// is een gangbare invoer: vul dat zelf aan.
    if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) && preg_match('#^[a-z0-9.\-]+\.[a-z]{2,}(/.*)?$#i', $url)) $url = 'https://' . $url;
    if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) return '';
    return $url;
}

/** IP-adres van de bezoeker (achter Cloudflare staat het echte adres in een header). */
function klant_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $k) {
        $ip = $_SERVER[$k] ?? '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

/**
 * Eenvoudige aanvraag-begrenzing (zonder database): bewaart tijdstippen per
 * sleutel in data/rate_limit.json. rate_limit_toegestaan() kijkt alleen,
 * rate_limit_registreer() telt een poging mee. $venster is in seconden (max 1 uur).
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
    respond(['error' => 'Te veel pogingen. Probeer het over een kwartier opnieuw.'], 429);
}

/** Voor test-/hulpscripts: alleen ingelogde IT-beheerders, nooit het demo-account. */
function require_it_pagina() {
    if (empty($_SESSION['user_id']) || is_demo_gebruiker() || !heeft_rol(['admin'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Alleen voor ingelogde IT-beheerders (niet het demo-account). Log eerst in op het portaal.\n";
        exit;
    }
}

function uuid() {
    return bin2hex(random_bytes(8)) . dechex(time());
}

/**
 * Cryptografisch veilige, niet te raden token — specifiek voor dingen als
 * wachtwoord-reset-links, waar uuid() (deels tijd-gebaseerd) niet sterk
 * genoeg voor is.
 */
function veilige_token() {
    return bin2hex(random_bytes(32));
}

/**
 * Minimale SMTP-client (geen externe library nodig — handig want shared
 * hosting zoals InfinityFree heeft meestal geen Composer/exec). Ondersteunt
 * STARTTLS (poort 587, zoals Gmail) en directe SSL (poort 465).
 * Geeft true/false terug; gooit nooit een fout naar de gebruiker.
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
    $read(); // server-greeting (220)
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
    if (strpos($authResp, '235') !== 0) { fclose($smtp); return false; } // login mislukt

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
 * Verstuurt een melding naar 1 of meer gebruikers: altijd als bericht in het
 * portaal (data/berichten/{user}.json), en optioneel ook per e-mail als er
 * in district.php een SMTP-configuratie staat. Faalt de e-mail (bijv. omdat
 * de hosting dat niet toestaat, of Gmail de login weigert), dan blijft het
 * in-portaal bericht gewoon staan — dat mag nooit de rest van de actie
 * blokkeren.
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
 * Stuurt een melding naar iedereen met een bepaalde rol (bijv. alle PI
 * Coördinatoren Drukwerk), plus altijd naar alle admins. De rolhouders volgen
 * automatisch uit Ledenbeheer (rol toekennen = vanaf dan ontvangt die persoon
 * de meldingen). Heeft nog niemand die rol, dan valt dit terug op het optionele
 * vaste e-mailadres in district.php (COORDINATOR_EMAILS).
 */
function notify_role($role, $titel, $tekst, $link = null) {
    $users = read_json(DATA_DIR . '/users.json', []);
    $houders = []; $admins = [];
    foreach ($users as $u) {
        $rollen = normaliseer_gebruiker_rollen($u)['roles'];
        if (in_array($role, $rollen, true)) $houders[] = $u['id'];
        if (in_array('admin', $rollen, true)) $admins[] = $u['id'];
    }
    // Wie de rol heeft (via Ledenbeheer) ontvangt de melding automatisch, plus IT.
    $ontvangers = array_values(array_unique(array_merge($houders, $admins)));
    if (!empty($ontvangers)) notify($ontvangers, $titel, $tekst, $link);
    // Heeft nog niemand de rol, dan valt het terug op het (optionele) vaste adres in district.php.
    if (empty($houders) && defined('COORDINATOR_EMAILS') && !empty(COORDINATOR_EMAILS[$role])) {
        @smtp_send(COORDINATOR_EMAILS[$role], $titel, $tekst . ($link ? "\n\n" . APP_URL . $link : ''));
    }
}

/**
 * Stuurt een melding naar ALLE geregistreerde PI-leden (bijv. de oproep
 * "wie wil deze voorlichting mee geven?"). $exclUserId sluit optioneel de
 * initiatiefnemer zelf uit.
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
