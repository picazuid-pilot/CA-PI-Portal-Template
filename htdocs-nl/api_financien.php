<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib_drive.php';
require __DIR__ . '/lib_kdrive.php';
require_login();

$action = $_GET['action'] ?? '';
$financienRollen = ['admin', 'penningmeester'];
// Derving mag ook door wie de voorraad beheert gemeld worden — die ziet
// schade/miskopen/verloren zendingen als eerste, en dit is een verbetering
// t.o.v. nu, waar voorraad ook al zonder enig spoor bijgesteld kan worden.
$dervingRollen = ['admin', 'penningmeester', 'secretaris', 'coordinator_drukwerk'];
// Verifiëren van transacties is bewust een Penningmeester-specifieke taak
// (scheiding van taken) — Secretaris mag transacties nog wel bewerken, maar
// niet als "gecontroleerd" afvinken.
$verificatieRollen = ['admin', 'penningmeester'];

// bijlage_download zet zijn eigen Content-Type (PDF/afbeelding), dus die
// slaat de standaard JSON-header bewust over.
if ($action !== 'bijlage_download') {
    header('Content-Type: application/json; charset=utf-8');
}

function transactie_path($id) { return DATA_DIR . '/financien/transacties/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function fin_aanvraag_path($id) { return DATA_DIR . '/financien/aanvragen/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function relatie_path($id) { return DATA_DIR . '/financien/relaties/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function post_path($id) { return DATA_DIR . '/financien/posten/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function reroute_path($id) { return DATA_DIR . '/financien/reroutes/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function derving_path($id) { return DATA_DIR . '/financien/derving/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function drukwerk_voorraad_path() { return DATA_DIR . '/drukwerk/voorraad.json'; }

function genereer_reroutenummer() {
    $jaar = date('Y');
    $aantal = 0;
    foreach (glob(DATA_DIR . '/financien/reroutes/*.json') as $file) {
        $r = read_json($file, null);
        if ($r && strpos($r['nummer'], 'GI-' . $jaar) === 0) $aantal++;
    }
    return 'GI-' . $jaar . '-' . str_pad($aantal + 1, 3, '0', STR_PAD_LEFT);
}

// Verwerkt één van de drie lijsten van een gezamenlijke inkoop (bijdragen,
// aankopen, of bijkomende kosten) tot boekhoud-transacties + regel-records.
// $transactieType: 'inkomst' (bijdragen) of 'uitgave' (aankopen/kosten).
function verwerk_reroute_geldstroom($regelsIn, $transactieType, $omschrijvingPrefix, $titel, $nummer, $rerouteId, $createdBy, $standaardDatum, $behoudMeta = []) {
    $regelsUit = [];
    $totaal = 0;
    foreach ($regelsIn as $r) {
        $naam = trim($r['naam'] ?? '');
        $bedrag = round((float)($r['bedrag'] ?? 0), 2);
        if ($naam === '' || $bedrag <= 0) continue;

        $bijlagen = normaliseer_bijlagen($r['bijlagen'] ?? null);
        $gefactureerdDoor = trim($r['gefactureerdDoor'] ?? '');
        // "Eigen inleg": dit district legt zelf ook bij uit het eigen budget
        // — geen echte inkomst van buitenaf, dus GEEN boeking aanmaken (dat
        // zou de balans kunstmatig opblazen). Telt wel gewoon mee in de
        // grafiek en de totalen van deze gezamenlijke inkoop.
        $eigenInleg = !empty($r['eigenInleg']);
        // "Voorschot": iemand heeft dit uit eigen zak voorgeschoten en moet
        // nog vergoed worden — i.p.v. meteen een voltooide uitgave te boeken
        // (die zou doen alsof het al is terugbetaald), maken we een declaratie
        // aan voor die persoon. Pas bij daadwerkelijk uitbetalen ontstaat de
        // echte boeking.
        $voorschotAanvragerId = trim($r['voorschotAanvragerId'] ?? '');
        $bestaandeAanvraagId = trim($r['bestaandeGekoppeldeAanvraagId'] ?? '');

        // Datum voor déze regel bepalen: eigen datumveld op de regel zelf
        // (indien ingevuld) > de datum die deze regel al had in de
        // boekhouding (als naam+bedrag ongewijzigd zijn t.o.v. de vorige
        // keer opslaan — dan behandelen we 'm als "dezelfde" regel) >
        // anders de algemene datum van de gezamenlijke inkoop als laatste redmiddel.
        // Zo overschrijft bewerken van de gezamenlijke inkoop nooit een
        // datum die je al los in de Boekhouding had aangepast — die blijft leidend.
        $signature = $naam . '|' . $bedrag;
        $eigenDatum = trim($r['datum'] ?? '');
        if ($eigenDatum !== '') {
            $datum = $eigenDatum;
        } elseif (isset($behoudMeta[$signature]['datum'])) {
            $datum = $behoudMeta[$signature]['datum'];
        } else {
            $datum = $standaardDatum;
        }

        $tId = null;
        $gekoppeldeAanvraagId = null;
        $aanvraagNummer = null;
        $omschrijving = $omschrijvingPrefix . ' ' . $naam . ($gefactureerdDoor !== '' ? (' (gefactureerd/voorgeschoten door ' . $gefactureerdDoor . ')') : '') . ' — ' . $titel . ' (' . $nummer . ')';

        if ($bestaandeAanvraagId !== '' && file_exists(fin_aanvraag_path($bestaandeAanvraagId))) {
            // Bij bewerken: deze regel was al gekoppeld aan een declaratie —
            // die blijft gewoon bestaan, nooit opnieuw aanmaken.
            $bestaandeAanvraag = read_json(fin_aanvraag_path($bestaandeAanvraagId), null);
            $gekoppeldeAanvraagId = $bestaandeAanvraagId;
            $aanvraagNummer = $bestaandeAanvraag['nummer'] ?? null;
        } elseif ($voorschotAanvragerId !== '') {
            $declaratie = maak_declaratie_voor_voorschot($voorschotAanvragerId, $bedrag, $omschrijving, $rerouteId, $datum);
            $gekoppeldeAanvraagId = $declaratie['id'];
            $aanvraagNummer = $declaratie['nummer'];
        } elseif (!$eigenInleg) {
            $tId = uuid();
            $behoud = $behoudMeta[$signature] ?? null;
            write_json(transactie_path($tId), [
                'id' => $tId, 'type' => $transactieType, 'bedrag' => $bedrag, 'datum' => $datum,
                'omschrijving' => $omschrijving,
                'categorie' => 'gezamenlijke inkoop', 'bijlagen' => $bijlagen,
                'gekoppeldeAanvraagId' => null, 'gekoppeldePostId' => null, 'gekoppeldeRerouteId' => $rerouteId,
                // Als deze regel ongewijzigd is (zelfde naam+bedrag als
                // voorheen), nemen we ook de verificatiestatus mee over —
                // anders zou elk keer opslaan een al-geverifieerde
                // transactie stiekem weer "niet geverifieerd" maken.
                'geverifieerd' => $behoud['geverifieerd'] ?? false,
                'geverifieerdDoor' => $behoud['geverifieerdDoor'] ?? null,
                'geverifieerdOp' => $behoud['geverifieerdOp'] ?? null,
                'createdBy' => $createdBy, 'createdAt' => time() * 1000,
            ]);
        }

        $regel = ['naam' => $naam, 'bedrag' => $bedrag, 'bijlagen' => $bijlagen, 'gekoppeldeTransactieId' => $tId];
        if ($gefactureerdDoor !== '') $regel['gefactureerdDoor'] = $gefactureerdDoor;
        if ($eigenInleg) $regel['eigenInleg'] = true;
        if ($gekoppeldeAanvraagId) { $regel['gekoppeldeAanvraagId'] = $gekoppeldeAanvraagId; $regel['aanvraagNummer'] = $aanvraagNummer; }
        $regelsUit[] = $regel;
        $totaal += $bedrag;
    }
    return [$regelsUit, round($totaal, 2)];
}

// Bouwt op basis van de HUIDIGE (nog niet overschreven) regels van een
// gezamenlijke inkoop een opzoektabel "naam|bedrag" -> datum/verificatie
// van de bijbehorende, al bestaande boekingen. Wordt vlak vóór het
// herbouwen van de boekingen aangeroepen, zodat ongewijzigde regels hun
// eigen (mogelijk handmatig aangepaste) datum en verificatiestatus behouden.
function verzamel_reroute_behoud_meta($reroute) {
    $meta = [];
    $alleRegels = array_merge($reroute['bijdragen'] ?? [], $reroute['aankopen'] ?? [], $reroute['bijkomendeKosten'] ?? []);
    foreach ($alleRegels as $r) {
        if (empty($r['gekoppeldeTransactieId'])) continue;
        $t = read_json(transactie_path($r['gekoppeldeTransactieId']), null);
        if (!$t) continue;
        $signature = trim($r['naam'] ?? '') . '|' . round((float)($r['bedrag'] ?? 0), 2);
        $meta[$signature] = ['datum' => $t['datum'] ?? null, 'geverifieerd' => $t['geverifieerd'] ?? false, 'geverifieerdDoor' => $t['geverifieerdDoor'] ?? null, 'geverifieerdOp' => $t['geverifieerdOp'] ?? null];
    }
    return $meta;
}

// Verwijdert alle boekingen die bij een gezamenlijke inkoop horen — gebruikt
// vóór het opnieuw opbouwen bij bewerken, en bij helemaal verwijderen.
function verwijder_reroute_transacties($rerouteId) {
    foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $tPad) {
        $t = read_json($tPad, null);
        if ($t && ($t['gekoppeldeRerouteId'] ?? null) === $rerouteId) unlink($tPad);
    }
}
function lokaal_bijlagen_map() { return DATA_DIR . '/financien/bijlagen'; }

// Zet een CSV-regel correct in elkaar (komma's/aanhalingstekens/regeleinden
// in velden worden keurig ge-escaped, zoals Excel/Google Sheets verwacht).
function csv_regel($velden) {
    return implode(',', array_map(function ($v) {
        $v = (string)$v;
        if (preg_match('/[",\n]/', $v)) $v = '"' . str_replace('"', '""', $v) . '"';
        return $v;
    }, $velden)) . "\n";
}

function bijlagenNamenTekst($bijlagen) {
    if (empty($bijlagen) || !is_array($bijlagen)) return '';
    return implode(' | ', array_map(fn($b) => $b['bestandsnaam'] ?? ($b['pad'] ?? ''), $bijlagen));
}

function csv_transacties() {
    $rijen = [];
    foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $f) {
        $t = read_json($f, null);
        if ($t) $rijen[] = $t;
    }
    usort($rijen, fn($a, $b) => strcmp($a['datum'], $b['datum']));
    $csv = csv_regel(['datum', 'type', 'bedrag', 'categorie', 'omschrijving', 'bijlagen', 'aangemaakt_door']);
    foreach ($rijen as $t) {
        $csv .= csv_regel([$t['datum'], $t['type'], number_format($t['bedrag'], 2, '.', ''), $t['categorie'] ?? '', $t['omschrijving'], bijlagenNamenTekst($t['bijlagen'] ?? []), $t['createdBy'] ?? '']);
    }
    return $csv;
}

function csv_aanvragen() {
    $rijen = [];
    foreach (glob(DATA_DIR . '/financien/aanvragen/*.json') as $f) {
        $a = read_json($f, null);
        if ($a) $rijen[] = $a;
    }
    usort($rijen, fn($a, $b) => $b['aangemaaktOp'] <=> $a['aangemaaktOp']);
    $csv = csv_regel(['datum', 'type', 'aanvrager', 'bedrag', 'omschrijving', 'leverancier', 'status', 'bijlagen']);
    foreach ($rijen as $a) {
        $csv .= csv_regel([date('Y-m-d', (int)($a['aangemaaktOp'] / 1000)), $a['type'], $a['aanvragerNaam'], number_format($a['bedrag'], 2, '.', ''), $a['omschrijving'], $a['leverancier'] ?? '', $a['status'], bijlagenNamenTekst($a['bijlagen'] ?? [])]);
    }
    return $csv;
}

function csv_posten() {
    $rijen = [];
    foreach (glob(DATA_DIR . '/financien/posten/*.json') as $f) {
        $p = read_json($f, null);
        if ($p) $rijen[] = $p;
    }
    usort($rijen, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
    $csv = csv_regel(['nummer', 'soort', 'relatie', 'regels', 'totaalbedrag', 'openstaand', 'status', 'bijlagen']);
    foreach ($rijen as $p) {
        $regelsTekst = implode(' | ', array_map(fn($r) => $r['omschrijving'] . ' (' . number_format($r['bedrag'], 2, ',', '.') . ')', $p['regels']));
        $csv .= csv_regel([$p['nummer'], $p['soort'], $p['relatieNaam'], $regelsTekst, number_format($p['totaalbedrag'], 2, '.', ''), number_format($p['totaalbedrag'] - $p['afgehandeldBedrag'], 2, '.', ''), $p['status'], bijlagenNamenTekst($p['bijlagen'] ?? [])]);
    }
    return $csv;
}

function csv_derving() {
    $rijen = [];
    foreach (glob(DATA_DIR . '/financien/derving/*.json') as $f) {
        $d = read_json($f, null);
        if ($d) $rijen[] = $d;
    }
    usort($rijen, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
    $redenLabel = ['miskoop' => 'Miskoop', 'verloren_zending' => 'Verloren zending', 'schade' => 'Schade', 'overig' => 'Overig'];
    $csv = csv_regel(['datum', 'item', 'aantal', 'reden', 'verlies', 'toelichting', 'bijlagen']);
    foreach ($rijen as $d) {
        $csv .= csv_regel([date('Y-m-d', (int)($d['createdAt'] / 1000)), $d['itemNaam'], $d['aantal'], $redenLabel[$d['reden']] ?? $d['reden'], number_format($d['verlies'], 2, '.', ''), $d['toelichting'] ?? '', bijlagenNamenTekst($d['bijlagen'] ?? [])]);
    }
    return $csv;
}

function lokaal_bijlagen_grootte_mb() {
    $totaal = 0;
    foreach (glob(lokaal_bijlagen_map() . '/*') as $bestand) {
        if (is_file($bestand)) $totaal += filesize($bestand);
    }
    return $totaal / 1024 / 1024;
}

// Markeert alle lokaal opgeslagen bijlagen ('pad') in één JSON-bestand als
// gearchiveerd (bestandsnaam blijft bekend voor de administratie, maar het
// bestand zelf is straks weg — alleen nog in de gedownloade back-up-zip).
function archiveer_lokale_bijlagen_in_bestand($pad) {
    $data = read_json($pad, null);
    if (!$data || !isset($data['bijlagen']) || !is_array($data['bijlagen'])) return;
    $gewijzigd = false;
    foreach ($data['bijlagen'] as &$b) {
        if (is_array($b) && !empty($b['pad'])) {
            $b['gearchiveerd'] = true;
            unset($b['pad']);
            $gewijzigd = true;
        }
    }
    unset($b);
    if ($gewijzigd) write_json($pad, $data);
}

// Normaliseert binnenkomende bijlagen naar een schone array van
// {bestandsnaam, ...} — meerdere bestanden per transactie/aanvraag/post,
// ongeacht of ze lokaal ('pad') of op Drive ('driveFileId') staan.
function normaliseer_bijlagen($waarde) {
    if (!is_array($waarde)) return [];
    return array_values(array_filter($waarde, fn($b) => is_array($b) && (!empty($b['pad']) || !empty($b['driveFileId']) || !empty($b['kdrivePad']))));
}

// Vertaalt een gekozen referentie (type + ID) uit het transactie-formulier
// naar de juiste gekoppelde-ID-velden — een transactie kan met precies 1
// van de 3 soorten gekoppeld worden (of geen).
function verwerk_transactie_referentie($in) {
    $type = $in['referentieType'] ?? '';
    $refId = trim($in['referentieId'] ?? '');
    $out = ['gekoppeldeAanvraagId' => null, 'gekoppeldePostId' => null, 'gekoppeldeRerouteId' => null];
    if ($refId === '') return $out;
    if ($type === 'aanvraag' && file_exists(fin_aanvraag_path($refId))) $out['gekoppeldeAanvraagId'] = $refId;
    elseif ($type === 'post' && file_exists(post_path($refId))) $out['gekoppeldePostId'] = $refId;
    elseif ($type === 'reroute' && file_exists(reroute_path($refId))) $out['gekoppeldeRerouteId'] = $refId;
    return $out;
}

// Maakt automatisch een declaratie aan voor iemand die een kost heeft
// voorgeschoten (bijv. bij een gezamenlijke inkoop) — in plaats van meteen
// een voltooide uitgave te boeken. Volgt daarna gewoon de normale
// declaratie-flow (beoordelen -> betaalgegevens -> uitbetalen), en PAS bij
// uitbetalen ontstaat de echte boeking. Geeft de aangemaakte aanvraag terug.
function maak_declaratie_voor_voorschot($aanvragerId, $bedrag, $omschrijving, $rerouteId, $datum) {
    $users = read_json(DATA_DIR . '/users.json', []);
    $aanvragerNaam = $aanvragerId;
    foreach ($users as $u) {
        if ($u['id'] === $aanvragerId) { $aanvragerNaam = $u['name'] ?? $aanvragerId; break; }
    }

    $id = uuid();
    $aanvraag = [
        'id' => $id,
        'nummer' => genereer_aanvraagnummer('declaratie'),
        'type' => 'declaratie',
        'aanvragerId' => $aanvragerId,
        'aanvragerNaam' => $aanvragerNaam,
        'bedrag' => round((float)$bedrag, 2),
        'datum' => $datum,
        'omschrijving' => $omschrijving,
        'leverancier' => '',
        'bijlagen' => [],
        'status' => 'open',
        'opmerkingPenningmeester' => '',
        'betaalgegevens' => null,
        'gekoppeldeRerouteId' => $rerouteId,
        'aangemaaktOp' => time() * 1000,
        'behandeldOp' => null,
        'behandeldDoor' => null,
        'uitbetaaldOp' => null,
    ];
    write_json(fin_aanvraag_path($id), $aanvraag);

    notify_role('penningmeester', 'Nieuw voorschot om te vergoeden — ' . $aanvragerNaam,
        $aanvragerNaam . ' heeft € ' . number_format($bedrag, 2, ',', '.') . ' voorgeschoten: ' . $omschrijving,
        'financien.php?tab=aanvragen');
    if ($aanvragerId) notify([$aanvragerId], 'Voorschot geregistreerd als declaratie', 'Je voorgeschoten bedrag (€ ' . number_format($bedrag, 2, ',', '.') . ') is automatisch als declaratie ' . $aanvraag['nummer'] . ' ingediend en wacht op goedkeuring.', 'financien.php?tab=aanvragen');

    return $aanvraag;
}

function genereer_aanvraagnummer($type) {
    $prefix = $type === 'declaratie' ? 'DC' : 'BA';
    $jaar = date('Y');
    $aantal = 0;
    foreach (glob(DATA_DIR . '/financien/aanvragen/*.json') as $file) {
        $a = read_json($file, null);
        if ($a && ($a['nummer'] ?? '') !== '' && strpos($a['nummer'], $prefix . '-' . $jaar) === 0) $aantal++;
    }
    return $prefix . '-' . $jaar . '-' . str_pad($aantal + 1, 3, '0', STR_PAD_LEFT);
}

function genereer_postnummer() {
    $jaar = date('Y');
    $aantal = 0;
    foreach (glob(DATA_DIR . '/financien/posten/*.json') as $file) {
        $p = read_json($file, null);
        if ($p && strpos($p['nummer'], 'DV-' . $jaar) === 0) $aantal++;
    }
    return 'DV-' . $jaar . '-' . str_pad($aantal + 1, 3, '0', STR_PAD_LEFT);
}

// Zoekt een voorraad-item op naam (case-insensitive) en geeft de rij + index
// terug, zodat zowel Financiën als Drukwerk dezelfde voorraadlijst delen.
function vind_voorraad_item(&$voorraad, $naam) {
    foreach ($voorraad as $i => $item) {
        if (strcasecmp($item['naam'], $naam) === 0) return $i;
    }
    return null;
}

// Verwerkt een set regels (voorraad-koppelingen + vrije regels) voor een
// doorverkoop/inkoop-post: past de voorraad aan ($voorraad wordt by reference
// gewijzigd) en bouwt de opgeschoonde regels + totaalbedrag op. Wordt door
// zowel post_create als post_update gebruikt, zodat de logica maar op één
// plek hoeft te kloppen.
// Verwerkt de regels van een doorverkoop-post: elke voorraad-regel schrijft
// de voorraad af (op basis van de al bestaande waarde-per-stuk), elke vrije
// regel (bijv. verzendkosten) telt gewoon los mee in het totaalbedrag.
function verwerk_post_regels($regelsIn, &$voorraad) {
    $regelsUit = [];
    $totaal = 0;

    foreach ($regelsIn as $r) {
        $koppeling = $r['voorraadKoppeling'] ?? null;

        if ($koppeling) {
            $naam = trim($koppeling['itemNaam'] ?? '');
            $aantal = (int)($koppeling['aantal'] ?? 0);
            $idx = vind_voorraad_item($voorraad, $naam);
            if ($idx === null || $aantal <= 0) respond(['error' => 'Onbekend voorraaditem of ongeldig aantal: ' . $naam], 400);

            // Standaard: de huidige waarde-per-stuk uit de voorraadlijst.
            // Kan bewust overschreven worden (bijv. korting, afgeronde
            // prijsafspraak) via 'aangepastePrijsPerStuk' — expliciet gekozen
            // door de gebruiker, niet zomaar overgenomen.
            $waardePerStuk = $voorraad[$idx]['waardePerStuk'] ?? 0;
            if (isset($koppeling['aangepastePrijsPerStuk']) && $koppeling['aangepastePrijsPerStuk'] !== '' && $koppeling['aangepastePrijsPerStuk'] !== null) {
                $waardePerStuk = (float)$koppeling['aangepastePrijsPerStuk'];
            }

            if ($aantal > $voorraad[$idx]['huidigeVoorraad']) {
                respond(['error' => 'Niet genoeg voorraad van "' . $naam . '" (nog ' . $voorraad[$idx]['huidigeVoorraad'] . ' over).'], 400);
            }
            $voorraad[$idx]['huidigeVoorraad'] -= $aantal;
            $voorraad[$idx]['updatedAt'] = time() * 1000;

            $bedrag = round($aantal * $waardePerStuk, 2);
            $regelsUit[] = ['omschrijving' => $aantal . 'x ' . $naam . ' à € ' . number_format($waardePerStuk, 2, ',', '.'), 'bedrag' => $bedrag, 'voorraadKoppeling' => ['itemNaam' => $naam, 'aantal' => $aantal, 'waardePerStuk' => $waardePerStuk]];
            $totaal += $bedrag;

        } else {
            $omschrijving = trim($r['omschrijving'] ?? '');
            $bedrag = round((float)($r['bedrag'] ?? 0), 2);
            if ($omschrijving === '' || $bedrag <= 0) continue;

            // "Privé afgehandeld": deze kost (meestal verzendkosten) is
            // buiten de clubkas om geregeld — iemand betaalt en int het zelf
            // rechtstreeks, zonder dat het via de penningmeester loopt. Dus
            // GEEN uitgave boeken, EN niet meetellen in het bedrag dat het
            // district nog moet innen bij de debiteur.
            $prive = !empty($r['prive']);
            $regelsUit[] = ['omschrijving' => $omschrijving, 'bedrag' => $bedrag, 'voorraadKoppeling' => null, 'prive' => $prive];
            if (!$prive) $totaal += $bedrag;
        }
    }

    return [$regelsUit, round($totaal, 2)];
}

// Draait de voorraad-mutatie van een set REEDS TOEGEPASTE regels terug —
// nodig voordat een openstaande (nog niet afgehandelde) post bewerkt wordt,
// zodat we niet dubbel optellen/aftrekken.
function draai_post_regels_terug($regels, &$voorraad) {
    foreach ($regels as $r) {
        if (empty($r['voorraadKoppeling'])) continue;
        $naam = $r['voorraadKoppeling']['itemNaam'];
        $aantal = $r['voorraadKoppeling']['aantal'];
        $idx = vind_voorraad_item($voorraad, $naam);
        if ($idx === null) continue;
        $voorraad[$idx]['huidigeVoorraad'] += $aantal; // was afgeschreven bij de doorverkoop, dus terug erbij
        $voorraad[$idx]['updatedAt'] = time() * 1000;
    }
}

// Verzamelt álle bijlagen uit alle vier de entiteitstypes (transacties,
// aanvragen, posten, derving) tot één platte lijst, elk met genoeg context
// (bij welk record hoort 'ie, welke datum) om overzichtelijk te tonen en om
// later weer bij het juiste record terug te vinden voor hernoemen.
// Gearchiveerde bijlagen (alleen nog in de backup-zip, niet meer lokaal/
// Drive/kDrive) worden overgeslagen — die zijn hier toch niet te bekijken.
function verzamel_alle_bijlagen() {
    $bronnen = [
        'transactie' => ['map' => 'transacties', 'label' => fn($e) => $e['omschrijving'] ?? '', 'datum' => fn($e) => $e['datum'] ?? null],
        'aanvraag' => ['map' => 'aanvragen', 'label' => fn($e) => trim(($e['nummer'] ?? '') . ' — ' . ($e['aanvragerNaam'] ?? '')), 'datum' => fn($e) => $e['datum'] ?? null],
        'post' => ['map' => 'posten', 'label' => fn($e) => trim(($e['nummer'] ?? '') . ' — ' . ($e['relatieNaam'] ?? '')), 'datum' => fn($e) => $e['datum'] ?? null],
        'derving' => ['map' => 'derving', 'label' => fn($e) => 'Derving — ' . ($e['itemNaam'] ?? ''), 'datum' => fn($e) => isset($e['createdAt']) ? date('Y-m-d', (int)($e['createdAt'] / 1000)) : null],
        'los' => ['map' => 'losse_bijlagen', 'label' => fn($e) => $e['notitie'] ?: 'Nog niet gekoppeld', 'datum' => fn($e) => isset($e['createdAt']) ? date('Y-m-d', (int)($e['createdAt'] / 1000)) : null],
    ];
    // Eerst alle losse koppelingen verzamelen...
    $ruw = [];
    foreach ($bronnen as $type => $info) {
        foreach (glob(DATA_DIR . '/financien/' . $info['map'] . '/*.json') as $file) {
            $entiteit = read_json($file, null);
            if (!$entiteit || empty($entiteit['bijlagen']) || !is_array($entiteit['bijlagen'])) continue;
            foreach ($entiteit['bijlagen'] as $idx => $b) {
                if (!empty($b['gearchiveerd'])) continue;
                if (empty($b['pad']) && empty($b['driveFileId']) && empty($b['kdrivePad'])) continue;
                $ruw[] = [
                    'parentType' => $type,
                    'parentId' => $entiteit['id'],
                    'index' => $idx,
                    'bestandsnaam' => $b['bestandsnaam'] ?? '(naamloos)',
                    'pad' => $b['pad'] ?? null,
                    'driveFileId' => $b['driveFileId'] ?? null,
                    'kdrivePad' => $b['kdrivePad'] ?? null,
                    'mimeType' => $b['mimeType'] ?? null,
                    'contextLabel' => $info['label']($entiteit),
                    'contextType' => $type,
                    'datum' => $info['datum']($entiteit),
                ];
            }
        }
    }

    // ...en daarna groeperen per fysiek bestand (pad/driveFileId/kdrivePad),
    // zodat hetzelfde document — ook als het aan meerdere transacties/
    // overzichten gekoppeld is — maar 1x in het overzicht verschijnt, met
    // een lijst van al zijn koppelingen erbij.
    $groepen = [];
    foreach ($ruw as $r) {
        $sleutel = $r['pad'] ?? ($r['driveFileId'] ?? $r['kdrivePad']);
        if (!isset($groepen[$sleutel])) {
            $groepen[$sleutel] = [
                'bestandsnaam' => $r['bestandsnaam'],
                'pad' => $r['pad'], 'driveFileId' => $r['driveFileId'], 'kdrivePad' => $r['kdrivePad'], 'mimeType' => $r['mimeType'],
                'datum' => $r['datum'],
                'links' => [],
            ];
        }
        $groepen[$sleutel]['links'][] = [
            'parentType' => $r['parentType'], 'parentId' => $r['parentId'], 'index' => $r['index'],
            'contextLabel' => $r['contextLabel'], 'datum' => $r['datum'],
        ];
        if (($r['datum'] ?? '') > ($groepen[$sleutel]['datum'] ?? '')) $groepen[$sleutel]['datum'] = $r['datum'];
    }

    $out = array_values($groepen);
    usort($out, fn($a, $b) => strcmp($b['datum'] ?? '', $a['datum'] ?? ''));
    return $out;
}

// Zoekt het juiste JSON-bestand op basis van type+id (transactie/aanvraag/
// post/derving) en geeft zowel het pad als de ingelezen data terug, zodat
// bijlage_hernoemen/verwijderen/samenvoegen deze logica niet elk apart
// hoeven te herhalen.
function laad_bijlage_entiteit($parentType, $parentId) {
    $mapPerType = ['transactie' => 'financien/transacties', 'aanvraag' => 'financien/aanvragen', 'post' => 'financien/posten', 'derving' => 'financien/derving', 'los' => 'financien/losse_bijlagen'];
    if (!isset($mapPerType[$parentType])) return null;
    $pad = DATA_DIR . '/' . $mapPerType[$parentType] . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $parentId) . '.json';
    $data = read_json($pad, null);
    if (!$data) return null;
    return ['pad' => $pad, 'data' => $data];
}

switch ($action) {

    // ================= BIJLAGEN (bonnetjes / facturen) =================

    case 'bijlage_upload': {
        if (empty($_FILES['bestand'])) respond(['error' => 'Geen bestand ontvangen.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload mislukt.'], 400);
        if ($file['size'] > 8 * 1024 * 1024) respond(['error' => 'Bestand mag maximaal 8MB zijn.'], 400);

        $toegestaan = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $file['type'];
        if (!isset($toegestaan[$mime])) respond(['error' => 'Alleen PDF, JPG of PNG toegestaan.'], 400);

        $origineleNaam = basename($file['name']);

        // Opslagmethode is een expliciete keuze per district (STORAGE_BACKEND
        // in district.php), niet een stille volgorde — zo kan elk district
        // zelf kiezen, en is dit later makkelijk uit te breiden met nieuwe
        // methoden (gewoon een extra 'case' hieronder + een lib_xxx.php).
        // Lukt de gekozen methode niet, dan valt het ALTIJD terug op lokaal
        // — een upload mag nooit volledig mislukken.
        $backend = defined('STORAGE_BACKEND') ? STORAGE_BACKEND : 'lokaal';
        switch ($backend) {
            case 'kdrive':
                if (kdrive_beschikbaar()) {
                    $resultaat = kdrive_upload_bestand($file['tmp_name'], $origineleNaam, $mime);
                    if ($resultaat) respond(['bestandsnaam' => $origineleNaam, 'opslag' => 'kdrive', 'kdrivePad' => $resultaat['pad'], 'mimeType' => $mime]);
                }
                break;
            case 'drive':
                if (drive_beschikbaar()) {
                    $resultaat = drive_upload_bestand($file['tmp_name'], $origineleNaam, $mime);
                    if ($resultaat) respond(['bestandsnaam' => $origineleNaam, 'opslag' => 'drive', 'driveFileId' => $resultaat['id'], 'mimeType' => $mime]);
                }
                break;
            // Nieuwe opslagmethode toevoegen? Zet hier een case bij, bouw
            // een lib_xxx.php met dezelfde 3 functies (beschikbaar/upload/
            // stream), en voeg de bijbehorende instellingen toe in
            // district.php. De rest van de applicatie hoeft niet te weten
            // welke methoden er allemaal bestaan.
        }

        $veiligeNaam = veilige_token() . '.' . $toegestaan[$mime];
        $doelPad = DATA_DIR . '/financien/bijlagen/' . $veiligeNaam;

        // Limiet controleren VOORDAT we lokaal opslaan — nooit de hosting
        // laten volslibben. Geldt alleen voor de daadwerkelijke lokale val,
        // niet voor kdrive/drive (die hebben hun eigen opslagruimte).
        $limiet = defined('LOKAAL_BIJLAGEN_LIMIET_MB') ? (float)LOKAAL_BIJLAGEN_LIMIET_MB : 0;
        if ($limiet > 0) {
            $huidigeMB = lokaal_bijlagen_grootte_mb();
            $nieuwMB = $file['size'] / 1024 / 1024;
            if ($huidigeMB + $nieuwMB > $limiet) {
                respond([
                    'error' => 'Lokale opslaglimiet bijna bereikt (' . round($huidigeMB) . '/' . $limiet . ' MB). ' .
                        'Vraag een Penningmeester/Secretaris om eerst een back-up te downloaden en oude bijlagen op te ruimen (Financiën → Boekhouding → Opslagbeheer) voordat er weer geüpload kan worden.',
                ], 507);
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $doelPad)) respond(['error' => 'Opslaan mislukt.'], 500);

        respond(['bestandsnaam' => $origineleNaam, 'opslag' => 'lokaal', 'pad' => $veiligeNaam, 'mimeType' => $mime]);
        break;
    }

    // Maakt een "los" document aan — een bijlage die (nog) niet aan een
    // specifieke transactie/declaratie/doorverkoop/derving hangt, maar wel
    // al in het systeem staat en straks via de bijlage-kiezer overal
    // gekoppeld kan worden. Zo kun je facturen vast uploaden en later pas
    // koppelen. Het bestand zelf is al eerder geüpload via 'bijlage_upload'
    // (dezelfde flow als overal) — deze actie registreert alleen de
    // metadata als een op zichzelf staand document.
    case 'bijlage_los_aanmaken': {
        require_role($financienRollen);
        $in = json_input();
        $bestandsnaam = trim($in['bestandsnaam'] ?? '');
        if ($bestandsnaam === '') respond(['error' => 'Ongeldige bijlage.'], 400);
        $id = uuid();
        $doc = [
            'id' => $id,
            'notitie' => trim($in['notitie'] ?? ''),
            'bijlagen' => [[
                'bestandsnaam' => $bestandsnaam,
                'pad' => $in['pad'] ?? null,
                'driveFileId' => $in['driveFileId'] ?? null,
                'kdrivePad' => $in['kdrivePad'] ?? null,
                'mimeType' => $in['mimeType'] ?? null,
            ]],
            'createdAt' => time() * 1000,
        ];
        write_json(DATA_DIR . '/financien/losse_bijlagen/' . $id . '.json', $doc);
        respond(['ok' => true], 201);
        break;
    }

    case 'export_transacties_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-transacties-' . date('Y-m-d') . '.csv"');
        echo csv_transacties();
        exit;
    }

    case 'export_aanvragen_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-declaraties-aanvragen-' . date('Y-m-d') . '.csv"');
        echo csv_aanvragen();
        exit;
    }

    case 'export_posten_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-debiteuren-crediteuren-' . date('Y-m-d') . '.csv"');
        echo csv_posten();
        exit;
    }

    case 'export_derving_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-derving-' . date('Y-m-d') . '.csv"');
        echo csv_derving();
        exit;
    }

    case 'gebruikers_lijst': {
        // Lichte lijst (alleen id + naam), voor keuzelijsten zoals "wie heeft
        // dit voorgeschoten". Geen gevoelige velden zoals wachtwoord-hashes.
        $users = read_json(DATA_DIR . '/users.json', []);
        $lijst = array_map(fn($u) => ['id' => $u['id'], 'naam' => $u['name'] ?? $u['id']], $users);
        respond(['gebruikers' => $lijst]);
        break;
    }

    case 'opslag_status': {
        require_role($financienRollen);
        $limiet = defined('LOKAAL_BIJLAGEN_LIMIET_MB') ? (float)LOKAAL_BIJLAGEN_LIMIET_MB : 0;
        respond(['gebruiktMB' => round(lokaal_bijlagen_grootte_mb(), 1), 'limietMB' => $limiet]);
        break;
    }

    case 'bijlagen_backup_downloaden': {
        require_role($financienRollen);
        if (!class_exists('ZipArchive')) respond(['error' => 'ZIP-ondersteuning ontbreekt op deze server.'], 500);

        $overzicht = ['gemaaktOp' => date('Y-m-d H:i:s'), 'bestanden' => []];
        foreach (['transacties', 'aanvragen', 'posten', 'derving', 'losse_bijlagen'] as $soort) {
            foreach (glob(DATA_DIR . '/financien/' . $soort . '/*.json') as $bestandPad) {
                $data = read_json($bestandPad, null);
                if (!$data || empty($data['bijlagen']) || !is_array($data['bijlagen'])) continue;
                foreach ($data['bijlagen'] as $b) {
                    if (!empty($b['pad'])) {
                        $overzicht['bestanden'][] = [
                            'bestandOpSchijf' => $b['pad'],
                            'oorspronkelijkeNaam' => $b['bestandsnaam'] ?? $b['pad'],
                            'hoortBij' => $soort . '/' . basename($bestandPad, '.json'),
                        ];
                    }
                }
            }
        }

        $tijdelijkZip = tempnam(DATA_DIR, 'backup');
        $zip = new ZipArchive();
        $zip->open($tijdelijkZip, ZipArchive::OVERWRITE);
        $zip->addFromString('overzicht.json', json_encode($overzicht, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString('transacties.csv', csv_transacties());
        $zip->addFromString('declaraties-aanvragen.csv', csv_aanvragen());
        $zip->addFromString('debiteuren-crediteuren.csv', csv_posten());
        $zip->addFromString('derving.csv', csv_derving());
        foreach (glob(lokaal_bijlagen_map() . '/*') as $bestand) {
            if (is_file($bestand)) $zip->addFile($bestand, 'bijlagen/' . basename($bestand));
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-financien-bijlagen-backup-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tijdelijkZip));
        readfile($tijdelijkZip);
        unlink($tijdelijkZip);
        exit;
    }

    case 'bijlagen_wissen_na_backup': {
        require_role($financienRollen);

        // Markeert eerst overal de bijlage-verwijzingen als gearchiveerd
        // (bestandsnaam blijft zichtbaar in de administratie, alleen het
        // fysieke bestand verdwijnt), en verwijdert dan pas de bestanden.
        foreach (['transacties', 'aanvragen', 'posten', 'derving', 'losse_bijlagen'] as $soort) {
            foreach (glob(DATA_DIR . '/financien/' . $soort . '/*.json') as $bestandPad) {
                archiveer_lokale_bijlagen_in_bestand($bestandPad);
            }
        }

        $verwijderd = 0;
        foreach (glob(lokaal_bijlagen_map() . '/*') as $bestand) {
            if (is_file($bestand) && unlink($bestand)) $verwijderd++;
        }

        respond(['verwijderd' => $verwijderd]);
        break;
    }

    // Zet een eerder gedownloade back-up-zip weer terug: pakt de bestanden
    // uit 'bijlagen/' terug op hun plek, en koppelt ze — via overzicht.json
    // — weer aan precies dezelfde transactie/declaratie/etc. als voorheen.
    // Werkt omdat "Wis lokale bijlagen" nooit de administratie zelf
    // aanraakt, alleen de fysieke bestanden — alle koppelingen en
    // verificatiestatus staan dus nog gewoon klaar om hersteld te worden.
    case 'bijlagen_herstellen_vanuit_backup': {
        require_role($financienRollen);
        if (empty($_FILES['backup'])) respond(['error' => 'Geen back-up bestand ontvangen.'], 400);
        $file = $_FILES['backup'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload mislukt.'], 400);
        if (!class_exists('ZipArchive')) respond(['error' => 'ZIP-ondersteuning ontbreekt op deze server.'], 500);

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) respond(['error' => 'Kon het zip-bestand niet openen — is het wel een geldige back-up?'], 400);

        $overzichtJson = $zip->getFromName('overzicht.json');
        if ($overzichtJson === false) { $zip->close(); respond(['error' => 'overzicht.json ontbreekt in deze zip — dit lijkt geen geldige back-up van dit portaal.'], 400); }
        $overzicht = json_decode($overzichtJson, true);
        if (!$overzicht || empty($overzicht['bestanden'])) { $zip->close(); respond(['error' => 'Geen bijlagen gevonden in deze back-up.'], 400); }

        $hersteld = 0;
        $nietGevonden = [];
        foreach ($overzicht['bestanden'] as $item) {
            $bestandOpSchijf = basename($item['bestandOpSchijf'] ?? '');
            $hoortBij = $item['hoortBij'] ?? ''; // vorm: "soort/entiteitId"
            $oorspronkelijkeNaam = $item['oorspronkelijkeNaam'] ?? $bestandOpSchijf;
            if ($bestandOpSchijf === '' || strpos($hoortBij, '/') === false) continue;
            [$soort, $entiteitId] = explode('/', $hoortBij, 2);

            $inhoud = $zip->getFromName('bijlagen/' . $bestandOpSchijf);
            if ($inhoud === false) { $nietGevonden[] = $oorspronkelijkeNaam . ' (bestand niet in deze zip gevonden)'; continue; }

            $entiteit = laad_bijlage_entiteit($soort, $entiteitId);
            if (!$entiteit) { $nietGevonden[] = $oorspronkelijkeNaam . ' (record bestaat niet meer)'; continue; }

            // Zoek de bijbehorende, nog gearchiveerde bijlage-slot terug op
            // naam — er kunnen meerdere bijlagen bij 1 record horen.
            $gevonden = false;
            foreach ($entiteit['data']['bijlagen'] as &$b) {
                if (!empty($b['gearchiveerd']) && ($b['bestandsnaam'] ?? '') === $oorspronkelijkeNaam) {
                    unset($b['gearchiveerd']);
                    $b['pad'] = $bestandOpSchijf;
                    $gevonden = true;
                    break;
                }
            }
            unset($b);
            if (!$gevonden) { $nietGevonden[] = $oorspronkelijkeNaam . ' (koppeling niet meer gevonden — mogelijk al eerder hersteld, of de bijlage is intussen verwijderd/vervangen)'; continue; }

            ensure_dir(lokaal_bijlagen_map());
            file_put_contents(lokaal_bijlagen_map() . '/' . $bestandOpSchijf, $inhoud);
            write_json($entiteit['pad'], $entiteit['data']);
            $hersteld++;
        }
        $zip->close();

        respond(['hersteld' => $hersteld, 'nietGevonden' => $nietGevonden]);
        break;
    }

    case 'bijlagen_overzicht': {
        require_role($financienRollen);
        respond(['bijlagen' => verzamel_alle_bijlagen()]);
        break;
    }

    case 'bijlage_vervangen': {
        require_role($financienRollen);
        $in = json_input();
        $links = is_array($in['links'] ?? null) ? $in['links'] : [];
        $nieuw = [
            'bestandsnaam' => trim($in['bestandsnaam'] ?? ''),
            'pad' => $in['pad'] ?? null,
            'driveFileId' => $in['driveFileId'] ?? null,
            'kdrivePad' => $in['kdrivePad'] ?? null,
            'mimeType' => $in['mimeType'] ?? null,
        ];
        if ($nieuw['bestandsnaam'] === '' || empty($links)) respond(['error' => 'Ongeldig verzoek.'], 400);

        // Werkt élke koppeling naar dit bestand bij — dus als dit document
        // aan meerdere transacties/overzichten hangt, verandert het overal
        // tegelijk mee.
        $oudPad = null;
        foreach ($links as $link) {
            $entiteit = laad_bijlage_entiteit($link['parentType'] ?? '', $link['parentId'] ?? '');
            $index = (int)($link['index'] ?? -1);
            if (!$entiteit || !isset($entiteit['data']['bijlagen'][$index])) continue;
            if ($oudPad === null) $oudPad = $entiteit['data']['bijlagen'][$index]['pad'] ?? null;
            $entiteit['data']['bijlagen'][$index] = $nieuw;
            write_json($entiteit['pad'], $entiteit['data']);
        }
        if ($oudPad && $oudPad !== $nieuw['pad']) {
            $volledigPad = DATA_DIR . '/financien/bijlagen/' . $oudPad;
            if (file_exists($volledigPad)) unlink($volledigPad);
        }

        respond(['ok' => true]);
        break;
    }

    case 'bijlage_hernoemen': {
        require_role($financienRollen);
        $in = json_input();
        $links = is_array($in['links'] ?? null) ? $in['links'] : [];
        $nieuweNaam = trim($in['bestandsnaam'] ?? '');
        if ($nieuweNaam === '' || empty($links)) respond(['error' => 'Ongeldig verzoek.'], 400);

        // Werkt de naam bij op élke koppeling naar dit bestand — het is
        // immers hetzelfde document, dus overal dezelfde nieuwe naam.
        foreach ($links as $link) {
            $entiteit = laad_bijlage_entiteit($link['parentType'] ?? '', $link['parentId'] ?? '');
            $index = (int)($link['index'] ?? -1);
            if (!$entiteit || !isset($entiteit['data']['bijlagen'][$index])) continue;
            $entiteit['data']['bijlagen'][$index]['bestandsnaam'] = $nieuweNaam;
            write_json($entiteit['pad'], $entiteit['data']);
        }
        respond(['ok' => true]);
        break;
    }

    case 'bijlage_verwijderen': {
        require_role($financienRollen);
        $in = json_input();
        $links = is_array($in['links'] ?? null) ? $in['links'] : [];
        if (empty($links)) respond(['error' => 'Ongeldig verzoek.'], 400);

        // Verwijdert álle koppelingen naar dit bestand (niet slechts 1) —
        // dat is precies wat "dit document verwijderen" moet betekenen.
        // Per entiteit aflopend op index verwijderen, zodat de overige
        // indexen binnen diezelfde entiteit niet per ongeluk doorschuiven.
        $perEntiteit = [];
        foreach ($links as $link) {
            $sleutel = ($link['parentType'] ?? '') . ':' . ($link['parentId'] ?? '');
            $perEntiteit[$sleutel][] = (int)($link['index'] ?? -1);
        }
        $padOmTeVerwijderen = null;
        foreach ($perEntiteit as $sleutel => $indexen) {
            [$parentType, $parentId] = explode(':', $sleutel, 2);
            $entiteit = laad_bijlage_entiteit($parentType, $parentId);
            if (!$entiteit) continue;
            rsort($indexen);
            foreach ($indexen as $idx) {
                if (!isset($entiteit['data']['bijlagen'][$idx])) continue;
                if ($padOmTeVerwijderen === null) $padOmTeVerwijderen = $entiteit['data']['bijlagen'][$idx]['pad'] ?? null;
                array_splice($entiteit['data']['bijlagen'], $idx, 1);
            }
            write_json($entiteit['pad'], $entiteit['data']);
        }
        if ($padOmTeVerwijderen) {
            $volledigPad = DATA_DIR . '/financien/bijlagen/' . $padOmTeVerwijderen;
            if (file_exists($volledigPad)) unlink($volledigPad);
        }

        respond(['ok' => true]);
        break;
    }

    case 'bijlagen_samenvoegen': {
        require_role($financienRollen);
        $in = json_input();
        $bronLinks = is_array($in['bronLinks'] ?? null) ? $in['bronLinks'] : [];   // document dat BEHOUDEN blijft
        $doelLinks = is_array($in['doelLinks'] ?? null) ? $in['doelLinks'] : [];   // document dat verdwijnt; al zijn koppelingen wijzen straks naar de bron
        if (empty($bronLinks) || empty($doelLinks)) respond(['error' => 'Ongeldig verzoek.'], 400);

        $eersteBron = $bronLinks[0];
        $bronEntiteit = laad_bijlage_entiteit($eersteBron['parentType'] ?? '', $eersteBron['parentId'] ?? '');
        $bronIndex = (int)($eersteBron['index'] ?? -1);
        if (!$bronEntiteit || !isset($bronEntiteit['data']['bijlagen'][$bronIndex])) respond(['error' => 'Bronbijlage niet gevonden.'], 404);
        $bronBijlage = $bronEntiteit['data']['bijlagen'][$bronIndex];

        // Élke koppeling van het doel-document laten wijzen naar exact
        // hetzelfde bestand als de bron — daarna groepeert
        // verzamel_alle_bijlagen() ze vanzelf tot 1 rij, met alle
        // koppelingen (van bron én doel) samen.
        $oudDoelPad = null;
        foreach ($doelLinks as $link) {
            $entiteit = laad_bijlage_entiteit($link['parentType'] ?? '', $link['parentId'] ?? '');
            $index = (int)($link['index'] ?? -1);
            if (!$entiteit || !isset($entiteit['data']['bijlagen'][$index])) continue;
            if ($oudDoelPad === null) $oudDoelPad = $entiteit['data']['bijlagen'][$index]['pad'] ?? null;
            $entiteit['data']['bijlagen'][$index] = $bronBijlage;
            write_json($entiteit['pad'], $entiteit['data']);
        }

        // Het oude, nu overbodige fysieke bestand van de doel-kant opruimen.
        if ($oudDoelPad && $oudDoelPad !== ($bronBijlage['pad'] ?? null)) {
            $volledigPad = DATA_DIR . '/financien/bijlagen/' . $oudDoelPad;
            if (file_exists($volledigPad)) unlink($volledigPad);
        }

        respond(['ok' => true]);
        break;
    }

    case 'bijlage_download': {
        $kdrivePad = $_GET['kdrivePad'] ?? '';
        if ($kdrivePad !== '') {
            header('Content-Type: ' . ($_GET['mime'] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="bijlage"');
            kdrive_stream_bestand($kdrivePad); // beëindigt het request zelf
        }

        $driveFileId = $_GET['driveFileId'] ?? '';
        if ($driveFileId !== '') {
            header('Content-Type: ' . ($_GET['mime'] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="bijlage"');
            drive_stream_bestand($driveFileId); // beëindigt het request zelf
        }

        $pad = basename($_GET['pad'] ?? '');
        $volledigPad = DATA_DIR . '/financien/bijlagen/' . $pad;
        if ($pad === '' || !file_exists($volledigPad)) {
            http_response_code(404);
            exit;
        }
        $mime = function_exists('mime_content_type') ? mime_content_type($volledigPad) : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $pad . '"');
        readfile($volledigPad);
        exit;
    }

    // ================= BOEKHOUDING (transacties) =================
    // Zichtbaar voor iedereen (transparantie over de balans), maar alleen
    // aan te passen door Penningmeester, Secretaris of admin.

    case 'transactie_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $file) {
            $t = read_json($file, null);
            if ($t) $out[] = $t;
        }
        usort($out, fn($a, $b) => strcmp($b['datum'], $a['datum']));
        respond(['transacties' => $out]);
        break;
    }

    case 'balans': {
        $inkomsten = 0; $uitgaven = 0; $perCategorie = [];
        foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $file) {
            $t = read_json($file, null);
            if (!$t) continue;
            if ($t['type'] === 'inkomst') {
                $inkomsten += $t['bedrag'];
            } else {
                $uitgaven += $t['bedrag'];
                $cat = $t['categorie'] ?: 'overig';
                $perCategorie[$cat] = ($perCategorie[$cat] ?? 0) + $t['bedrag'];
            }
        }
        $instellingen = read_json(DATA_DIR . '/financien/instellingen.json', ['startsaldo' => 0]);
        $startsaldo = (float)($instellingen['startsaldo'] ?? 0);
        respond(['inkomsten' => $inkomsten, 'uitgaven' => $uitgaven, 'startsaldo' => $startsaldo, 'saldo' => $startsaldo + $inkomsten - $uitgaven, 'perCategorie' => $perCategorie]);
        break;
    }

    case 'startsaldo_bijwerken': {
        require_role($financienRollen);
        $in = json_input();
        write_json(DATA_DIR . '/financien/instellingen.json', ['startsaldo' => (float)($in['startsaldo'] ?? 0)]);
        respond(['ok' => true]);
        break;
    }

    case 'transactie_create': {
        require_role($financienRollen);
        $in = json_input();
        if (!is_numeric($in['bedrag'] ?? null) || trim($in['omschrijving'] ?? '') === '') {
            respond(['error' => 'Bedrag en omschrijving zijn verplicht.'], 400);
        }
        $id = uuid();
        $referentie = verwerk_transactie_referentie($in);
        $transactie = [
            'id' => $id,
            'type' => ($in['type'] ?? '') === 'inkomst' ? 'inkomst' : 'uitgave',
            'bedrag' => round((float)$in['bedrag'], 2),
            'datum' => $in['datum'] ?: date('Y-m-d'),
            'omschrijving' => trim($in['omschrijving']),
            'categorie' => trim($in['categorie'] ?? ''),
            'bijlagen' => normaliseer_bijlagen($in['bijlagen'] ?? null),
            'gekoppeldeAanvraagId' => $referentie['gekoppeldeAanvraagId'],
            'gekoppeldePostId' => $referentie['gekoppeldePostId'],
            'gekoppeldeRerouteId' => $referentie['gekoppeldeRerouteId'],
            'geverifieerd' => false,
            'geverifieerdDoor' => null,
            'geverifieerdOp' => null,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(transactie_path($id), $transactie);
        respond(['transactie' => $transactie], 201);
        break;
    }

    case 'transactie_update': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $transactie = read_json(transactie_path($id), null);
        if (!$transactie) respond(['error' => 'Transactie niet gevonden.'], 404);
        $in = json_input();
        foreach (['type', 'datum', 'omschrijving', 'categorie'] as $veld) {
            if (isset($in[$veld])) $transactie[$veld] = is_string($in[$veld]) ? trim($in[$veld]) : $in[$veld];
        }
        if (isset($in['bedrag']) && is_numeric($in['bedrag'])) $transactie['bedrag'] = round((float)$in['bedrag'], 2);
        if (array_key_exists('bijlagen', $in)) $transactie['bijlagen'] = normaliseer_bijlagen($in['bijlagen']);
        if (array_key_exists('referentieType', $in) || array_key_exists('referentieId', $in)) {
            $referentie = verwerk_transactie_referentie($in);
            $transactie['gekoppeldeAanvraagId'] = $referentie['gekoppeldeAanvraagId'];
            $transactie['gekoppeldePostId'] = $referentie['gekoppeldePostId'];
            $transactie['gekoppeldeRerouteId'] = $referentie['gekoppeldeRerouteId'];
        }
        write_json(transactie_path($id), $transactie);
        respond(['transactie' => $transactie]);
        break;
    }

    case 'transactie_delete': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $path = transactie_path($id);
        if (file_exists($path)) unlink($path);
        respond(['deleted' => true]);
        break;
    }

    case 'transactie_verifieren': {
        require_role($verificatieRollen);
        $id = $_GET['id'] ?? '';
        $transactie = read_json(transactie_path($id), null);
        if (!$transactie) respond(['error' => 'Transactie niet gevonden.'], 404);

        $in = json_input();
        $geverifieerd = !empty($in['geverifieerd']);
        $transactie['geverifieerd'] = $geverifieerd;
        $transactie['geverifieerdDoor'] = $geverifieerd ? ($_SESSION['user_name'] ?? current_user_slug()) : null;
        $transactie['geverifieerdOp'] = $geverifieerd ? time() * 1000 : null;
        write_json(transactie_path($id), $transactie);

        respond(['transactie' => $transactie]);
        break;
    }

    case 'transactie_bulk_import': {
        require_role($financienRollen);
        $in = json_input();
        $aangemaakt = 0;
        foreach ($in['rijen'] ?? [] as $rij) {
            $bedrag = str_replace(',', '.', trim($rij['bedrag'] ?? ''));
            if (!is_numeric($bedrag) || trim($rij['omschrijving'] ?? '') === '') continue;
            $id = uuid();
            write_json(transactie_path($id), [
                'id' => $id,
                'type' => ($rij['type'] ?? '') === 'inkomst' ? 'inkomst' : 'uitgave',
                'bedrag' => round((float)$bedrag, 2),
                'datum' => trim($rij['datum'] ?? '') ?: date('Y-m-d'),
                'omschrijving' => trim($rij['omschrijving']),
                'categorie' => trim($rij['categorie'] ?? ''),
                'bijlagen' => [],
                'gekoppeldeAanvraagId' => null,
                'gekoppeldeRerouteId' => null,
                'geverifieerd' => false,
                'geverifieerdDoor' => null,
                'geverifieerdOp' => null,
                'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
                'createdAt' => time() * 1000,
            ]);
            $aangemaakt++;
        }
        respond(['aangemaakt' => $aangemaakt]);
        break;
    }

    // ================= DECLARATIES & BESTELLING-AANVRAGEN =================
    // Twee varianten van hetzelfde proces: 'declaratie' (iedereen mag
    // indienen, achteraf terugbetaald) en 'bestelling_aanvraag' (vooraf
    // goedkeuring om ergens te bestellen — bedoeld voor de PI Voorzitter).

    case 'aanvraag_create': {
        $in = json_input();
        $type = ($in['type'] ?? '') === 'bestelling_aanvraag' ? 'bestelling_aanvraag' : 'declaratie';
        if ($type === 'bestelling_aanvraag' && !heeft_rol(['voorzitter', 'admin'])) {
            respond(['error' => 'Alleen de PI Voorzitter (of admin) kan een bestelling-aanvraag indienen.'], 403);
        }
        if (!is_numeric($in['bedrag'] ?? null) || trim($in['omschrijving'] ?? '') === '') {
            respond(['error' => 'Bedrag en omschrijving zijn verplicht.'], 400);
        }

        $id = uuid();
        $aanvraag = [
            'id' => $id,
            'nummer' => genereer_aanvraagnummer($type),
            'type' => $type,
            'aanvragerId' => $_SESSION['user_id'],
            'aanvragerNaam' => $_SESSION['user_name'] ?? $_SESSION['user_id'],
            'bedrag' => round((float)$in['bedrag'], 2),
            'datum' => trim($in['datum'] ?? '') ?: date('Y-m-d'),
            'omschrijving' => trim($in['omschrijving']),
            'leverancier' => trim($in['leverancier'] ?? ''),
            'bijlagen' => normaliseer_bijlagen($in['bijlagen'] ?? null),
            'status' => 'open',
            'opmerkingPenningmeester' => '',
            'betaalgegevens' => null,
            'gekoppeldeRerouteId' => null,
            'aangemaaktOp' => time() * 1000,
            'behandeldOp' => null,
            'behandeldDoor' => null,
            'uitbetaaldOp' => null,
        ];
        write_json(fin_aanvraag_path($id), $aanvraag);

        $titel = $type === 'declaratie' ? 'Nieuwe declaratie' : 'Nieuwe bestelling-aanvraag';
        notify_role(
            'penningmeester',
            $titel . ' — ' . $aanvraag['aanvragerNaam'],
            $aanvraag['aanvragerNaam'] . ' dient een ' . ($type === 'declaratie' ? 'declaratie' : 'bestelling-aanvraag') . ' in van € ' . number_format($aanvraag['bedrag'], 2, ',', '.') .
                "\nOmschrijving: " . $aanvraag['omschrijving'] .
                ($aanvraag['leverancier'] ? ("\nLeverancier: " . $aanvraag['leverancier']) : ''),
            'financien.php?tab=aanvragen'
        );
        // Secretaris heeft dezelfde bevoegdheid, dus ook op de hoogte stellen.
        notify_role('secretaris', $titel . ' — ' . $aanvraag['aanvragerNaam'], 'Zie financiën-tab in het portaal.', 'financien.php?tab=aanvragen');

        respond(['aanvraag' => $aanvraag], 201);
        break;
    }

    case 'aanvraag_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/financien/aanvragen/*.json') as $file) {
            $a = read_json($file, null);
            if (!$a) continue;
            // Bestond deze aanvraag al vóór de nummering werd toegevoegd? Geef
            // 'm dan alsnog eenmalig een nummer, zodat oude data niet zonder
            // referentie blijft zitten.
            if (empty($a['nummer'])) {
                $a['nummer'] = genereer_aanvraagnummer($a['type'] ?? 'declaratie');
                write_json($file, $a);
            }
            $out[] = $a;
        }
        usort($out, fn($a, $b) => $b['aangemaaktOp'] <=> $a['aangemaaktOp']);
        respond(['aanvragen' => $out]);
        break;
    }

    case 'aanvraag_besluit': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(fin_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);
        $in = json_input();
        $besluit = $in['besluit'] ?? '';
        if (!in_array($besluit, ['goedgekeurd', 'afgewezen'], true)) respond(['error' => 'Ongeldig besluit.'], 400);

        $aanvraag['status'] = $besluit;
        $aanvraag['behandeldOp'] = time() * 1000;
        $aanvraag['behandeldDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $aanvraag['opmerkingPenningmeester'] = $in['opmerking'] ?? '';
        write_json(fin_aanvraag_path($id), $aanvraag);

        $tekst = $besluit === 'goedgekeurd'
            ? 'Je ' . ($aanvraag['type'] === 'declaratie' ? 'declaratie' : 'bestelling-aanvraag') . ' van € ' . number_format($aanvraag['bedrag'], 2, ',', '.') . ' is goedgekeurd. Stuur je betaalverzoek of bankgegevens door via het portaal, dan maakt de penningmeester het over.'
            : 'Je ' . ($aanvraag['type'] === 'declaratie' ? 'declaratie' : 'bestelling-aanvraag') . ' is afgewezen.' . ($aanvraag['opmerkingPenningmeester'] ? ("\nOpmerking: " . $aanvraag['opmerkingPenningmeester']) : '');
        notify([$aanvraag['aanvragerId']], 'Financiële aanvraag ' . $besluit, $tekst, 'financien.php?tab=aanvragen');

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_betaalgegevens_indienen': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(fin_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);
        if ($aanvraag['aanvragerId'] !== $_SESSION['user_id']) respond(['error' => 'Geen toestemming.'], 403);
        if ($aanvraag['status'] !== 'goedgekeurd') respond(['error' => 'Deze aanvraag is nog niet goedgekeurd.'], 400);

        $in = json_input();
        $aanvraag['betaalgegevens'] = [
            'iban' => trim($in['iban'] ?? ''),
            'tenaamstelling' => trim($in['tenaamstelling'] ?? ''),
            'opmerking' => trim($in['opmerking'] ?? ''),
            'ingediendOp' => time() * 1000,
        ];
        write_json(fin_aanvraag_path($id), $aanvraag);

        notify_role(
            'penningmeester',
            'Betaalgegevens ontvangen — ' . $aanvraag['aanvragerNaam'],
            $aanvraag['aanvragerNaam'] . ' heeft betaalgegevens doorgegeven voor de goedgekeurde aanvraag van € ' . number_format($aanvraag['bedrag'], 2, ',', '.') . '.',
            'financien.php?tab=aanvragen'
        );

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_uitbetaald': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(fin_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);
        if ($aanvraag['status'] !== 'goedgekeurd') respond(['error' => 'Alleen goedgekeurde aanvragen kunnen als uitbetaald gemarkeerd worden.'], 400);

        $aanvraag['status'] = 'uitbetaald';
        $aanvraag['uitbetaaldOp'] = time() * 1000;
        write_json(fin_aanvraag_path($id), $aanvraag);

        // Automatisch een uitgave-transactie aanmaken, inclusief het bonnetje.
        $transactieId = uuid();
        write_json(transactie_path($transactieId), [
            'id' => $transactieId,
            'type' => 'uitgave',
            'bedrag' => $aanvraag['bedrag'],
            'datum' => $aanvraag['datum'] ?? date('Y-m-d'),
            'omschrijving' => ($aanvraag['type'] === 'declaratie' ? 'Declaratie — ' : 'Bestelling — ') . $aanvraag['aanvragerNaam'] . ': ' . $aanvraag['omschrijving'],
            'categorie' => $aanvraag['type'] === 'declaratie' ? ($aanvraag['gekoppeldeRerouteId'] ? 'gezamenlijke inkoop' : 'declaraties') : 'bestellingen',
            'bijlagen' => $aanvraag['bijlagen'] ?? [],
            'gekoppeldeAanvraagId' => $id,
            'gekoppeldeRerouteId' => $aanvraag['gekoppeldeRerouteId'] ?? null,
            'geverifieerd' => false,
            'geverifieerdDoor' => null,
            'geverifieerdOp' => null,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ]);

        notify([$aanvraag['aanvragerId']], 'Uitbetaald', 'Je aanvraag van € ' . number_format($aanvraag['bedrag'], 2, ',', '.') . ' is uitbetaald.', 'financien.php?tab=aanvragen');

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_update': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(fin_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);

        $magBewerken = $aanvraag['aanvragerId'] === $_SESSION['user_id'] || heeft_rol($financienRollen);
        if (!$magBewerken) respond(['error' => 'Geen toestemming.'], 403);
        if ($aanvraag['status'] !== 'open') respond(['error' => 'Deze aanvraag is al beoordeeld en kan niet meer aangepast worden.'], 400);

        $in = json_input();
        if (!is_numeric($in['bedrag'] ?? null) || trim($in['omschrijving'] ?? '') === '') {
            respond(['error' => 'Bedrag en omschrijving zijn verplicht.'], 400);
        }

        $aanvraag['bedrag'] = round((float)$in['bedrag'], 2);
        $aanvraag['datum'] = trim($in['datum'] ?? '') ?: ($aanvraag['datum'] ?? date('Y-m-d'));
        $aanvraag['omschrijving'] = trim($in['omschrijving']);
        $aanvraag['leverancier'] = trim($in['leverancier'] ?? $aanvraag['leverancier']);
        $aanvraag['bijlagen'] = normaliseer_bijlagen($in['bijlagen'] ?? $aanvraag['bijlagen']);
        write_json(fin_aanvraag_path($id), $aanvraag);

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_delete': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $path = fin_aanvraag_path($id);
        if (file_exists($path)) unlink($path);
        respond(['deleted' => true]);
        break;
    }

    case 'aanvraag_bulk_import': {
        // Voor het overzetten van oude, al afgehandelde declaraties/uitgaven
        // (bijv. vanuit de vorige Google Sheet) — komt binnen als 'uitbetaald'.
        require_role($financienRollen);
        $in = json_input();
        $aangemaakt = 0;
        foreach ($in['rijen'] ?? [] as $rij) {
            $bedrag = str_replace(',', '.', trim($rij['bedrag'] ?? ''));
            if (!is_numeric($bedrag) || trim($rij['omschrijving'] ?? '') === '') continue;
            $id = uuid();
            $bulkType = ($rij['type'] ?? '') === 'bestelling_aanvraag' ? 'bestelling_aanvraag' : 'declaratie';
            write_json(fin_aanvraag_path($id), [
                'id' => $id,
                'nummer' => genereer_aanvraagnummer($bulkType),
                'type' => $bulkType,
                'aanvragerId' => null,
                'aanvragerNaam' => trim($rij['aanvragerNaam'] ?? 'Onbekend'),
                'bedrag' => round((float)$bedrag, 2),
                'omschrijving' => trim($rij['omschrijving']),
                'leverancier' => trim($rij['leverancier'] ?? ''),
                'bijlagen' => [],
                'status' => 'uitbetaald',
                'opmerkingPenningmeester' => 'Geïmporteerd vanuit oude administratie.',
                'betaalgegevens' => null,
                'gekoppeldeRerouteId' => null,
                'aangemaaktOp' => time() * 1000,
                'behandeldOp' => time() * 1000,
                'behandeldDoor' => $_SESSION['user_name'] ?? current_user_slug(),
                'uitbetaaldOp' => time() * 1000,
            ]);

            $transactieId = uuid();
            write_json(transactie_path($transactieId), [
                'id' => $transactieId,
                'type' => 'uitgave',
                'bedrag' => round((float)$bedrag, 2),
                'datum' => trim($rij['datum'] ?? '') ?: date('Y-m-d'),
                'omschrijving' => trim($rij['omschrijving']),
                'categorie' => 'declaraties (import)',
                'bijlagen' => [],
                'gekoppeldeAanvraagId' => $id,
                'gekoppeldeRerouteId' => null,
                'geverifieerd' => false,
                'geverifieerdDoor' => null,
                'geverifieerdOp' => null,
                'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
                'createdAt' => time() * 1000,
            ]);
            $aangemaakt++;
        }
        respond(['aangemaakt' => $aangemaakt]);
        break;
    }

    // ================= DEBITEUREN / CREDITEUREN =================

    case 'relatie_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/financien/relaties/*.json') as $file) {
            $r = read_json($file, null);
            if ($r) $out[] = $r;
        }
        usort($out, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        respond(['relaties' => $out]);
        break;
    }

    case 'relatie_upsert': {
        require_role($financienRollen);
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'Naam is verplicht.'], 400);
        $id = $in['id'] ?? uuid();
        $relatie = [
            'id' => $id,
            'naam' => trim($in['naam']),
            'type' => in_array($in['type'] ?? '', ['debiteur', 'crediteur', 'beide'], true) ? $in['type'] : 'beide',
            'contactpersoon' => trim($in['contactpersoon'] ?? ''),
            'email' => trim($in['email'] ?? ''),
            'telefoon' => trim($in['telefoon'] ?? ''),
            'notitie' => trim($in['notitie'] ?? ''),
            'updatedAt' => time() * 1000,
        ];
        write_json(relatie_path($id), $relatie);
        respond(['relatie' => $relatie]);
        break;
    }

    case 'relatie_delete': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $path = relatie_path($id);
        if (file_exists($path)) unlink($path);
        respond(['deleted' => true]);
        break;
    }

    // ================= VOORSCHOTTEN & INKOOP OP REKENING =================

    case 'post_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/financien/posten/*.json') as $file) {
            $p = read_json($file, null);
            if ($p) $out[] = $p;
        }
        usort($out, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        respond(['posten' => $out]);
        break;
    }

    case 'post_create': {
        require_login(); // open voor alle leden — indienen mag door iedereen, alleen afhandelen (post_afhandelen) blijft financiën-only
        $in = json_input();
        $relatieId = $in['relatieId'] ?? '';
        $relatie = read_json(relatie_path($relatieId), null);
        if (!$relatie) respond(['error' => 'Kies een geldige debiteur.'], 400);

        $regelsIn = is_array($in['regels'] ?? null) ? $in['regels'] : [];
        if (empty($regelsIn)) respond(['error' => 'Voeg minstens 1 regel toe.'], 400);

        $voorraad = read_json(drukwerk_voorraad_path(), []);
        [$regelsUit, $totaal] = verwerk_post_regels($regelsIn, $voorraad);

        if (empty($regelsUit)) respond(['error' => 'Geen geldige regels gevonden.'], 400);
        write_json(drukwerk_voorraad_path(), $voorraad);

        $id = uuid();
        $bijlagen = normaliseer_bijlagen($in['bijlagen'] ?? null);
        $datum = trim($in['datum'] ?? '') ?: date('Y-m-d');
        $post = [
            'id' => $id,
            'nummer' => genereer_postnummer(),
            'soort' => 'doorverkoop',
            'relatieId' => $relatie['id'],
            'relatieNaam' => $relatie['naam'],
            'datum' => $datum,
            'factuurnummer' => trim($in['factuurnummer'] ?? ''),
            'bijlagen' => $bijlagen,
            'regels' => $regelsUit,
            'totaalbedrag' => $totaal,
            'status' => 'open',
            'afgehandeldBedrag' => 0,
            'afhandelingen' => [],
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(post_path($id), $post);

        // De kant die WIJ direct dragen (bijv. verzendkosten, regels zonder
        // voorraadkoppeling) boeken we NU als echte uitgave. De
        // voorraadwaarde-regels zijn geen kasstroom, dus die NIET boeken —
        // die zie je terug in de gedaalde voorraadwaarde zelf.
        foreach ($regelsUit as $r) {
            if ($r['voorraadKoppeling'] === null && empty($r['prive'])) {
                $tId = uuid();
                write_json(transactie_path($tId), [
                    'id' => $tId, 'type' => 'uitgave', 'bedrag' => $r['bedrag'], 'datum' => $datum,
                    'omschrijving' => $r['omschrijving'] . ' (voorgeschoten voor ' . $relatie['naam'] . ', ' . $post['nummer'] . ')',
                    'categorie' => 'doorverkopen', 'bijlagen' => [], 'gekoppeldeAanvraagId' => null,
                    'gekoppeldeRerouteId' => null,
                    'geverifieerd' => false,
                    'geverifieerdDoor' => null,
                    'geverifieerdOp' => null,
                    'gekoppeldePostId' => $id, 'createdBy' => $post['createdBy'], 'createdAt' => time() * 1000,
                ]);
            }
        }

        respond(['post' => $post], 201);
        break;
    }

    case 'post_update': {
        $id = $_GET['id'] ?? '';
        $post = read_json(post_path($id), null);
        if (!$post) respond(['error' => 'Post niet gevonden.'], 404);
        // Bewerken mag door de maker zelf, of door Financiën — net als bij
        // declaraties. Afhandelen (goedkeuren/betalen) blijft financiën-only.
        $magBewerken = ($post['createdBy'] ?? null) === ($_SESSION['user_name'] ?? current_user_slug()) || heeft_rol($financienRollen);
        if (!$magBewerken) respond(['error' => 'Geen toestemming.'], 403);
        if ($post['status'] !== 'open' || $post['afgehandeldBedrag'] > 0) {
            respond(['error' => 'Deze post is al (deels) afgehandeld en kan niet meer bewerkt worden.'], 400);
        }

        $in = json_input();
        $regelsIn = is_array($in['regels'] ?? null) ? $in['regels'] : [];
        if (empty($regelsIn)) respond(['error' => 'Voeg minstens 1 regel toe.'], 400);

        $voorraad = read_json(drukwerk_voorraad_path(), []);
        // Eerst de oude regels terugdraaien, dan pas de nieuwe toepassen —
        // anders tellen we dubbel of onttrekken we te veel.
        draai_post_regels_terug($post['regels'], $voorraad);
        [$regelsUit, $totaal] = verwerk_post_regels($regelsIn, $voorraad);

        if (empty($regelsUit)) respond(['error' => 'Geen geldige regels gevonden.'], 400);
        write_json(drukwerk_voorraad_path(), $voorraad);

        // De oude, automatisch geboekte "voorgeschoten"-uitgaven bij deze
        // post horen niet meer te kloppen zodra de regels wijzigen —
        // verwijderen, en hieronder desgewenst opnieuw aanmaken.
        foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $tPad) {
            $t = read_json($tPad, null);
            if ($t && ($t['gekoppeldePostId'] ?? null) === $id) unlink($tPad);
        }

        $post['factuurnummer'] = trim($in['factuurnummer'] ?? $post['factuurnummer']);
        $post['bijlagen'] = normaliseer_bijlagen($in['bijlagen'] ?? $post['bijlagen']);
        $post['datum'] = trim($in['datum'] ?? '') ?: ($post['datum'] ?? date('Y-m-d'));
        $post['regels'] = $regelsUit;
        $post['totaalbedrag'] = $totaal;
        write_json(post_path($id), $post);

        foreach ($regelsUit as $r) {
            if ($r['voorraadKoppeling'] === null && empty($r['prive'])) {
                $tId = uuid();
                write_json(transactie_path($tId), [
                    'id' => $tId, 'type' => 'uitgave', 'bedrag' => $r['bedrag'], 'datum' => $post['datum'],
                    'omschrijving' => $r['omschrijving'] . ' (voorgeschoten voor ' . $post['relatieNaam'] . ', ' . $post['nummer'] . ')',
                    'categorie' => 'doorverkopen', 'bijlagen' => [], 'gekoppeldeAanvraagId' => null,
                    'gekoppeldeRerouteId' => null,
                    'geverifieerd' => false,
                    'geverifieerdDoor' => null,
                    'geverifieerdOp' => null,
                    'gekoppeldePostId' => $id, 'createdBy' => $_SESSION['user_name'] ?? current_user_slug(), 'createdAt' => time() * 1000,
                ]);
            }
        }

        respond(['post' => $post]);
        break;
    }

    case 'post_afhandelen': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $post = read_json(post_path($id), null);
        if (!$post) respond(['error' => 'Post niet gevonden.'], 404);
        $in = json_input();

        $regelIndexen = is_array($in['regelIndexen'] ?? null) ? array_map('intval', $in['regelIndexen']) : [];
        if (empty($regelIndexen)) respond(['error' => 'Kies minstens 1 regel om af te handelen.'], 400);

        $bedrag = 0;
        $omschrijvingen = [];
        foreach ($regelIndexen as $i) {
            if (!isset($post['regels'][$i])) continue;
            $bedrag += $post['regels'][$i]['bedrag'];
            $omschrijvingen[] = $post['regels'][$i]['omschrijving'];
        }
        if ($bedrag <= 0) respond(['error' => 'Geen geldige regels gekozen.'], 400);

        $post['afgehandeldBedrag'] = round($post['afgehandeldBedrag'] + $bedrag, 2);
        $post['afhandelingen'][] = ['bedrag' => round($bedrag, 2), 'datum' => date('Y-m-d'), 'omschrijving' => implode(', ', $omschrijvingen), 'door' => $_SESSION['user_name'] ?? current_user_slug(), 'op' => time() * 1000];
        $post['status'] = $post['afgehandeldBedrag'] >= $post['totaalbedrag'] - 0.005 ? 'afgehandeld' : 'deels_afgehandeld';
        write_json(post_path($id), $post);

        // Bij een doorverkoop komt geld altijd BINNEN (inkomst) zodra de
        // debiteur betaalt.
        $tId = uuid();
        write_json(transactie_path($tId), [
            'id' => $tId,
            'type' => 'inkomst',
            'bedrag' => round($bedrag, 2),
            'datum' => date('Y-m-d'),
            'omschrijving' => implode(', ', $omschrijvingen) . ' — ' . $post['relatieNaam'] . ' (' . $post['nummer'] . ')',
            'categorie' => 'doorverkopen',
            'bijlagen' => [],
            'gekoppeldeAanvraagId' => null,
            'gekoppeldeRerouteId' => null,
            'geverifieerd' => false,
            'geverifieerdDoor' => null,
            'geverifieerdOp' => null,
            'gekoppeldePostId' => $id,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ]);

        respond(['post' => $post]);
        break;
    }

    case 'post_delete': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $post = read_json(post_path($id), null);
        if (!$post) respond(['deleted' => true]); // al weg, prima

        // Nog niets afgehandeld -> de voorraadmutatie van de regels netjes
        // terugdraaien, zodat verwijderen de voorraad niet scheeftrekt.
        if ($post['afgehandeldBedrag'] == 0) {
            $voorraad = read_json(drukwerk_voorraad_path(), []);
            draai_post_regels_terug($post['regels'], $voorraad);
            write_json(drukwerk_voorraad_path(), $voorraad);
        }

        // Gekoppelde automatische boekingen (bijv. voorgeschoten verzendkosten,
        // of eerdere afhandel-transacties) horen niet als wees te blijven staan.
        foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $tPad) {
            $t = read_json($tPad, null);
            if ($t && ($t['gekoppeldePostId'] ?? null) === $id) unlink($tPad);
        }

        unlink(post_path($id));
        respond(['deleted' => true]);
        break;
    }

    // ================= DERVING (miskopen, verloren zendingen, schade) =================

    case 'derving_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/financien/derving/*.json') as $file) {
            $d = read_json($file, null);
            if ($d) $out[] = $d;
        }
        usort($out, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        respond(['derving' => $out]);
        break;
    }

    case 'derving_create': {
        require_role($dervingRollen);
        $in = json_input();
        $itemNaam = trim($in['itemNaam'] ?? '');
        $aantal = (int)($in['aantal'] ?? 0);
        $reden = in_array($in['reden'] ?? '', ['miskoop', 'verloren_zending', 'schade', 'overig'], true) ? $in['reden'] : 'overig';
        if ($itemNaam === '' || $aantal <= 0) respond(['error' => 'Kies een item en een aantal groter dan 0.'], 400);

        $voorraad = read_json(drukwerk_voorraad_path(), []);
        $idx = vind_voorraad_item($voorraad, $itemNaam);
        if ($idx === null) respond(['error' => 'Onbekend voorraaditem: ' . $itemNaam], 400);
        if ($aantal > $voorraad[$idx]['huidigeVoorraad']) {
            respond(['error' => 'Niet genoeg voorraad van "' . $itemNaam . '" (nog ' . $voorraad[$idx]['huidigeVoorraad'] . ' over).'], 400);
        }

        $waardePerStuk = $voorraad[$idx]['waardePerStuk'] ?? 0;
        $voorraad[$idx]['huidigeVoorraad'] -= $aantal;
        $voorraad[$idx]['updatedAt'] = time() * 1000;
        write_json(drukwerk_voorraad_path(), $voorraad);

        $redenLabel = ['miskoop' => 'Miskoop', 'verloren_zending' => 'Verloren zending', 'schade' => 'Schade', 'overig' => 'Overig'][$reden];
        $verlies = round($aantal * $waardePerStuk, 2);
        $bijlagenD = normaliseer_bijlagen($in['bijlagen'] ?? null);

        // Automatisch een uitgave boeken (categorie "derving") zodat het
        // verlies zichtbaar wordt in de balans, ook al is er geen geld
        // fysiek de deur uit gegaan — het is wel echt kwijtgeraakte waarde.
        $tId = uuid();
        write_json(transactie_path($tId), [
            'id' => $tId, 'type' => 'uitgave', 'bedrag' => $verlies, 'datum' => date('Y-m-d'),
            'omschrijving' => $redenLabel . ': ' . $aantal . 'x ' . $itemNaam . (trim($in['toelichting'] ?? '') !== '' ? (' — ' . trim($in['toelichting'])) : ''),
            'categorie' => 'derving', 'bijlagen' => $bijlagenD,
            'gekoppeldeAanvraagId' => null, 'gekoppeldePostId' => null, 'gekoppeldeRerouteId' => null,
            'geverifieerd' => false,
            'geverifieerdDoor' => null,
            'geverifieerdOp' => null,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(), 'createdAt' => time() * 1000,
        ]);

        $id = uuid();
        $derving = [
            'id' => $id,
            'itemNaam' => $itemNaam,
            'aantal' => $aantal,
            'waardePerStuk' => $waardePerStuk,
            'verlies' => $verlies,
            'reden' => $reden,
            'toelichting' => trim($in['toelichting'] ?? ''),
            'bijlagen' => $bijlagenD,
            'gekoppeldeTransactieId' => $tId,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(derving_path($id), $derving);

        notify_role('penningmeester', 'Derving geregistreerd', $redenLabel . ': ' . $aantal . 'x ' . $itemNaam . ' (€ ' . number_format($verlies, 2, ',', '.') . ') door ' . $derving['createdBy'] . '.', 'financien.php?tab=boekhouding');

        respond(['derving' => $derving], 201);
        break;
    }

    case 'derving_delete': {
        require_role($dervingRollen);
        $id = $_GET['id'] ?? '';
        $path = derving_path($id);
        if (file_exists($path)) unlink($path);
        respond(['deleted' => true]);
        break;
    }

    // ================= GEZAMENLIJKE INKOOP (meerdere bijdragers -> meerdere aankopen) =================
    // "Follow the money": meerdere districten/investeerders leggen samen in
    // voor een gezamenlijke bestelling, verdeeld over meerdere leveranciers/
    // aankopen. Elke regel wordt automatisch een eigen boeking in de
    // hoofdboekhouding, allemaal gekoppeld via hetzelfde GI-nummer.

    case 'reroute_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/financien/reroutes/*.json') as $file) {
            $r = read_json($file, null);
            if ($r) $out[] = $r;
        }
        usort($out, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        respond(['reroutes' => $out]);
        break;
    }

    case 'reroute_create': {
        require_role($financienRollen);
        $in = json_input();
        $titel = trim($in['titel'] ?? '');
        if ($titel === '') respond(['error' => 'Titel is verplicht.'], 400);

        $bijdragenIn = is_array($in['bijdragen'] ?? null) ? $in['bijdragen'] : [];
        $aankopenIn = is_array($in['aankopen'] ?? null) ? $in['aankopen'] : [];
        $kostenIn = is_array($in['bijkomendeKosten'] ?? null) ? $in['bijkomendeKosten'] : [];
        $bijdragenGeldig = array_filter($bijdragenIn, fn($b) => trim($b['naam'] ?? '') !== '' && (float)($b['bedrag'] ?? 0) > 0);
        $aankopenGeldig = array_filter($aankopenIn, fn($a) => trim($a['naam'] ?? '') !== '' && (float)($a['bedrag'] ?? 0) > 0);
        if (empty($bijdragenGeldig) || empty($aankopenGeldig)) {
            respond(['error' => 'Voeg minstens 1 bijdrage én minstens 1 aankoop toe.'], 400);
        }

        $nummer = genereer_reroutenummer();
        $id = uuid();
        $createdBy = $_SESSION['user_name'] ?? current_user_slug();
        $datum = trim($in['datum'] ?? '') ?: date('Y-m-d');

        [$bijdragenUit, $totaalBijdragen] = verwerk_reroute_geldstroom($bijdragenIn, 'inkomst', 'Bijdrage', $titel, $nummer, $id, $createdBy, $datum);
        [$aankopenUit, $totaalAankopen] = verwerk_reroute_geldstroom($aankopenIn, 'uitgave', 'Aankoop', $titel, $nummer, $id, $createdBy, $datum);
        [$kostenUit, $totaalKosten] = verwerk_reroute_geldstroom($kostenIn, 'uitgave', 'Bijkomende kosten:', $titel, $nummer, $id, $createdBy, $datum);

        $reroute = [
            'id' => $id,
            'nummer' => $nummer,
            'datum' => $datum,
            'titel' => $titel,
            'bijdragen' => $bijdragenUit,
            'aankopen' => $aankopenUit,
            'bijkomendeKosten' => $kostenUit,
            'totaalBijdragen' => $totaalBijdragen,
            'totaalAankopen' => $totaalAankopen,
            'totaalBijkomendeKosten' => $totaalKosten,
            'createdBy' => $createdBy,
            'createdAt' => time() * 1000,
        ];
        write_json(reroute_path($id), $reroute);

        respond(['reroute' => $reroute], 201);
        break;
    }

    case 'reroute_update': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $reroute = read_json(reroute_path($id), null);
        if (!$reroute) respond(['error' => 'Gezamenlijke inkoop niet gevonden.'], 404);

        $in = json_input();
        $titel = trim($in['titel'] ?? '');
        if ($titel === '') respond(['error' => 'Titel is verplicht.'], 400);

        $bijdragenIn = is_array($in['bijdragen'] ?? null) ? $in['bijdragen'] : [];
        $aankopenIn = is_array($in['aankopen'] ?? null) ? $in['aankopen'] : [];
        $kostenIn = is_array($in['bijkomendeKosten'] ?? null) ? $in['bijkomendeKosten'] : [];
        $bijdragenGeldig = array_filter($bijdragenIn, fn($b) => trim($b['naam'] ?? '') !== '' && (float)($b['bedrag'] ?? 0) > 0);
        $aankopenGeldig = array_filter($aankopenIn, fn($a) => trim($a['naam'] ?? '') !== '' && (float)($a['bedrag'] ?? 0) > 0);
        if (empty($bijdragenGeldig) || empty($aankopenGeldig)) {
            respond(['error' => 'Voeg minstens 1 bijdrage én minstens 1 aankoop toe.'], 400);
        }

        // Vóórdat we de oude boekingen weggooien: hun huidige datum en
        // verificatiestatus bewaren per (naam+bedrag), zodat een regel die
        // ongewijzigd blijft zijn eigen boekhouding-datum behoudt i.p.v.
        // overschreven te worden door de algemene datum van deze
        // gezamenlijke inkoop.
        $behoudMeta = verzamel_reroute_behoud_meta($reroute);

        // Alle oude gekoppelde boekingen weg, daarna alles vers opnieuw
        // opbouwen — simpeler en minder foutgevoelig dan proberen individuele
        // regels te matchen en bij te werken.
        verwijder_reroute_transacties($id);
        $createdBy = $_SESSION['user_name'] ?? current_user_slug();
        $datum = trim($in['datum'] ?? '') ?: ($reroute['datum'] ?? date('Y-m-d'));

        [$bijdragenUit, $totaalBijdragen] = verwerk_reroute_geldstroom($bijdragenIn, 'inkomst', 'Bijdrage', $titel, $reroute['nummer'], $id, $createdBy, $datum, $behoudMeta);
        [$aankopenUit, $totaalAankopen] = verwerk_reroute_geldstroom($aankopenIn, 'uitgave', 'Aankoop', $titel, $reroute['nummer'], $id, $createdBy, $datum, $behoudMeta);
        [$kostenUit, $totaalKosten] = verwerk_reroute_geldstroom($kostenIn, 'uitgave', 'Bijkomende kosten:', $titel, $reroute['nummer'], $id, $createdBy, $datum, $behoudMeta);

        $reroute['titel'] = $titel;
        $reroute['datum'] = $datum;
        $reroute['bijdragen'] = $bijdragenUit;
        $reroute['aankopen'] = $aankopenUit;
        $reroute['bijkomendeKosten'] = $kostenUit;
        $reroute['totaalBijdragen'] = $totaalBijdragen;
        $reroute['totaalAankopen'] = $totaalAankopen;
        $reroute['totaalBijkomendeKosten'] = $totaalKosten;
        write_json(reroute_path($id), $reroute);

        respond(['reroute' => $reroute]);
        break;
    }

    case 'reroute_delete': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        if (!file_exists(reroute_path($id))) respond(['deleted' => true]);

        // Alle gekoppelde boekingen horen niet als wees te blijven staan —
        // deze hele gezamenlijke inkoop verdwijnt dan in zijn geheel.
        verwijder_reroute_transacties($id);

        unlink(reroute_path($id));
        respond(['deleted' => true]);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}
