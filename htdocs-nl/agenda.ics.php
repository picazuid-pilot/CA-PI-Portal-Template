<?php
/**
 * iCal-feed van de Gezamenlijke agenda, om te abonneren vanuit Thunderbird,
 * Google Agenda, Apple Agenda of Outlook (alleen-lezen).
 *
 * Toegang gaat met een persoonlijk token in de URL (?token=...), omdat
 * agenda-apps niet kunnen inloggen. Het token hoort bij één lid en werkt
 * alleen zolang dat lid bestaat. Aangemaakt/vernieuwd via
 * api_voorlichting.php?action=agenda_abonnement.
 */
require __DIR__ . '/config.php';

$token = (string)($_GET['token'] ?? '');
$gebruikerId = null;
if ($token !== '') {
    foreach (read_json(DATA_DIR . '/agenda_tokens.json', []) as $t) {
        if (isset($t['token']) && hash_equals($t['token'], $token)) { $gebruikerId = $t['userId'] ?? null; break; }
    }
}
$bestaat = false;
if ($gebruikerId !== null) {
    foreach (read_json(DATA_DIR . '/users.json', []) as $u) {
        if (($u['id'] ?? '') === $gebruikerId) { $bestaat = true; break; }
    }
}
if (!$bestaat) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ongeldige of ingetrokken agenda-link. Haal een nieuwe link op in het portaal.';
    exit;
}

// Sessie is hier niet nodig; vrijgeven voorkomt dat de feed op andere
// aanvragen van dit lid hoeft te wachten.
session_write_close();

function ics_tekst($s) {
    $s = str_replace('\\', '\\\\', (string)$s);
    $s = str_replace([';', ','], ['\\;', '\\,'], $s);
    return str_replace(["\r\n", "\n", "\r"], '\\n', $s);
}
// RFC 5545: regels langer dan 75 bytes worden gevouwen (vervolgregel begint met een spatie).
function ics_regel($regel) {
    $uit = '';
    while (strlen($regel) > 75) {
        $stuk = substr($regel, 0, 75);
        // Niet midden in een UTF-8-teken knippen.
        while ($stuk !== '' && (ord($regel[strlen($stuk)]) & 0xC0) === 0x80) $stuk = substr($stuk, 0, -1);
        $uit .= $stuk . "\r\n ";
        $regel = substr($regel, strlen($stuk));
    }
    return $uit . $regel . "\r\n";
}

$typeLabels = [
    'voorlichting' => 'Voorlichting', 'evenement' => 'Evenement',
    'district_vergadering' => 'District-vergadering', 'area_vergadering' => 'Area-vergadering', 'overig' => 'Overig',
];
$tz = new DateTimeZone(date_default_timezone_get());
$utc = new DateTimeZone('UTC');
$nu = gmdate('Ymd\THis\Z');
$host = $_SERVER['HTTP_HOST'] ?? 'portaal';

$uit = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//PI Werkportaal//Gezamenlijke agenda//NL\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
$uit .= ics_regel('X-WR-CALNAME:' . ics_tekst('PI Agenda — ' . DISTRICT_NAME));
$uit .= "REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\nX-PUBLISHED-TTL:PT1H\r\n";

foreach (glob(DATA_DIR . '/agenda/*.json') as $file) {
    $a = read_json($file, null);
    if (!$a || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['datum'] ?? '')) continue;

    $uit .= "BEGIN:VEVENT\r\n";
    $uit .= ics_regel('UID:' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $a['id'] ?? basename($file, '.json')) . '@' . $host);
    $uit .= 'DTSTAMP:' . $nu . "\r\n";

    if (preg_match('/^\d{1,2}:\d{2}$/', $a['tijd'] ?? '')) {
        // Met tijd: omrekenen naar UTC zodat elke agenda-app het op de juiste kloktijd toont. Duur: 1 uur.
        $start = DateTime::createFromFormat('Y-m-d H:i', $a['datum'] . ' ' . $a['tijd'], $tz);
        if ($start) {
            $eind = (clone $start)->modify('+1 hour');
            $uit .= 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n";
            $uit .= 'DTEND:' . $eind->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n";
        }
    } else {
        // Zonder tijd: hele dag.
        $dag = DateTime::createFromFormat('Y-m-d', $a['datum'], $utc);
        $uit .= 'DTSTART;VALUE=DATE:' . $dag->format('Ymd') . "\r\n";
        $uit .= 'DTEND;VALUE=DATE:' . $dag->modify('+1 day')->format('Ymd') . "\r\n";
    }

    $uit .= ics_regel('SUMMARY:' . ics_tekst($a['titel'] ?? 'Agendapunt'));
    if (($a['locatie'] ?? '') !== '') $uit .= ics_regel('LOCATION:' . ics_tekst($a['locatie']));
    if (($a['omschrijving'] ?? '') !== '') $uit .= ics_regel('DESCRIPTION:' . ics_tekst($a['omschrijving']));
    $uit .= ics_regel('CATEGORIES:' . ics_tekst($typeLabels[$a['type'] ?? 'overig'] ?? 'Overig'));
    $uit .= "END:VEVENT\r\n";
}
$uit .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="pi-agenda.ics"');
header('Cache-Control: no-cache');
echo $uit;
