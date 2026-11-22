<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

// Deze acties zijn bewust ook zonder login bruikbaar: het ophalen van het
// literatuurpakket (voor de openbare bestelpagina) en het indienen van een
// bestelling door een externe instelling (geen PI-lid, geen account).
$publiekeActies = ['pakket_list', 'aanvraag_extern_create'];
if (!in_array($action, $publiekeActies, true)) {
    require_login();
}
$coordinatorRollen = ['admin', 'coordinator_drukwerk'];

function voorraad_path() { return DATA_DIR . '/drukwerk/voorraad.json'; }
function voorraad_subcats_path() { return DATA_DIR . '/drukwerk/voorraad_subcats.json'; }
function voorraad_subcats() {
    $l = read_json(voorraad_subcats_path(), []);
    usort($l, fn($a, $b) => ($a['volgorde'] ?? 0) <=> ($b['volgorde'] ?? 0));
    return $l;
}
function voorraad_subcat_geldig($id) {
    if (!$id) return null;
    foreach (voorraad_subcats() as $c) { if ($c['id'] === $id) return $id; }
    return null;
}
function voorraad_subcat_naam_schoon($naam) { return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$naam))), 0, 60); }
function voorraad_mutaties_path() { return DATA_DIR . '/drukwerk/voorraad_mutaties.json'; }
function leveranciers_path() { return DATA_DIR . '/drukwerk/leveranciers.json'; }
function pakketten_path() { return DATA_DIR . '/drukwerk/pakketten.json'; }
function aanvraag_path($id) { return DATA_DIR . '/drukwerk/aanvragen/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function bestelling_path($id) { return DATA_DIR . '/drukwerk/bestellingen/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }

// Legt elke voorraadmutatie vast (zowel plus als min) zodat je per item kunt
// terugzien wat er wanneer is bij- of afgeboekt, en waardoor. $itemId mag
// null zijn (bijv. als een bestelling een nog onbekend product ontvangt en
// er meteen een nieuw voorraaditem bij wordt aangemaakt) — dan wordt er bij
// het tonen van de geschiedenis op naam teruggevallen.
function log_voorraad_mutatie($itemId, $itemNaam, $delta, $reden, $omschrijving) {
    if ($delta === 0) return; // niks veranderd, geen regel nodig
    $mutaties = read_json(voorraad_mutaties_path(), []);
    array_unshift($mutaties, [
        'id' => uuid(),
        'itemId' => $itemId,
        'itemNaam' => $itemNaam,
        'delta' => $delta, // positief = erbij, negatief = eraf
        'reden' => $reden, // 'aanvraag_goedgekeurd' | 'bestelling_ontvangen' | 'handmatige_aanpassing'
        'omschrijving' => $omschrijving,
        'door' => $_SESSION['user_name'] ?? current_user_slug(),
        'datum' => time() * 1000,
    ]);
    // Onbeperkt laten groeien is niet nodig — 2000 regels is ruim voldoende
    // geschiedenis en voorkomt dat dit bestand op termijn te groot wordt.
    $mutaties = array_slice($mutaties, 0, 2000);
    write_json(voorraad_mutaties_path(), $mutaties);
}

// Prijstiers die het systeem kent — vast, want de UI (kolommen) bouwt hierop.
const PRIJSTIERS = [500, 1000, 2500, 5000];

function leeg_prijzen() {
    $out = [];
    foreach (PRIJSTIERS as $t) $out[(string)$t] = ['totaal' => null, 'perStuk' => null];
    return $out;
}

// Ruimt afgewezen aanvragen op die langer dan een week geleden zijn
// afgehandeld. Geen cron nodig op deze hosting: dit draait gewoon mee elke
// keer dat de aanvragenlijst wordt opgevraagd — onopvallend en voldoende.
function ruim_oude_afwijzingen_op() {
    $grens = (time() - 7 * 24 * 60 * 60) * 1000;
    foreach (glob(DATA_DIR . '/drukwerk/aanvragen/*.json') as $file) {
        $a = read_json($file, null);
        if ($a && $a['status'] === 'afgewezen' && $a['behandeldOp'] !== null && $a['behandeldOp'] < $grens) {
            unlink($file);
        }
    }
}

switch ($action) {

    // ================= VOORRAAD =================

    case 'voorraad_list': {
        respond(['voorraad' => read_json(voorraad_path(), []), 'subcategorieen' => voorraad_subcats()]);
        break;
    }

    // ---- Subcategorieën van de voorraad (zoals bij de Drive-database): beheer door de coördinator Drukwerk en IT ----

    case 'voorraad_subcat_create': {
        require_role($coordinatorRollen);
        $naam = voorraad_subcat_naam_schoon(json_input()['naam'] ?? '');
        if ($naam === '') respond(['error' => 'Geef de subcategorie een naam.'], 400);
        $lijst = read_json(voorraad_subcats_path(), []);
        foreach ($lijst as $c) { if (mb_strtolower($c['naam']) === mb_strtolower($naam)) respond(['error' => 'Er bestaat al een subcategorie met deze naam.'], 409); }
        $volgorde = 0; foreach ($lijst as $c) $volgorde = max($volgorde, (int)($c['volgorde'] ?? 0) + 1);
        $lijst[] = ['id' => uuid(), 'naam' => $naam, 'volgorde' => $volgorde, 'createdAt' => time() * 1000];
        write_json(voorraad_subcats_path(), $lijst);
        respond(['subcategorieen' => voorraad_subcats()], 201);
        break;
    }

    case 'voorraad_subcat_update': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $naam = voorraad_subcat_naam_schoon(json_input()['naam'] ?? '');
        if ($naam === '') respond(['error' => 'Geef de subcategorie een naam.'], 400);
        $lijst = read_json(voorraad_subcats_path(), []);
        $gevonden = false;
        foreach ($lijst as $c) { if ($c['id'] !== $id && mb_strtolower($c['naam']) === mb_strtolower($naam)) respond(['error' => 'Er bestaat al een subcategorie met deze naam.'], 409); }
        foreach ($lijst as &$c) { if ($c['id'] === $id) { $c['naam'] = $naam; $gevonden = true; } }
        unset($c);
        if (!$gevonden) respond(['error' => 'Subcategorie niet gevonden.'], 404);
        write_json(voorraad_subcats_path(), $lijst);
        respond(['subcategorieen' => voorraad_subcats()]);
        break;
    }

    // Verwijderen: de items zelf blijven bestaan en komen onder "Ongesorteerd".
    case 'voorraad_subcat_delete': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        write_json(voorraad_subcats_path(), array_values(array_filter(read_json(voorraad_subcats_path(), []), fn($c) => $c['id'] !== $id)));
        $lijst = read_json(voorraad_path(), []);
        foreach ($lijst as &$i) { if (($i['subcategorieId'] ?? null) === $id) $i['subcategorieId'] = null; }
        unset($i);
        write_json(voorraad_path(), $lijst);
        respond(['subcategorieen' => voorraad_subcats()]);
        break;
    }

    case 'voorraad_subcats_herordenen': {
        require_role($coordinatorRollen);
        $volgorde = json_input()['volgorde'] ?? [];
        if (!is_array($volgorde)) respond(['error' => 'Ongeldige volgorde.'], 400);
        $lijst = read_json(voorraad_subcats_path(), []);
        foreach ($lijst as &$c) { $pos = array_search($c['id'], $volgorde, true); if ($pos !== false) $c['volgorde'] = $pos; }
        unset($c);
        write_json(voorraad_subcats_path(), $lijst);
        respond(['subcategorieen' => voorraad_subcats()]);
        break;
    }

    // Een item naar een subcategorie slepen (null = Ongesorteerd).
    case 'voorraad_item_verplaatsen': {
        require_role($coordinatorRollen);
        $in = json_input();
        $id = $in['id'] ?? '';
        $doel = voorraad_subcat_geldig($in['subcategorieId'] ?? null);
        $lijst = read_json(voorraad_path(), []);
        $gevonden = false;
        foreach ($lijst as &$i) { if ($i['id'] === $id) { $i['subcategorieId'] = $doel; $gevonden = true; } }
        unset($i);
        if (!$gevonden) respond(['error' => 'Item niet gevonden.'], 404);
        write_json(voorraad_path(), $lijst);
        respond(['voorraad' => $lijst]);
        break;
    }

    case 'voorraad_upsert': {
        require_role($coordinatorRollen);
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'Naam is verplicht.'], 400);
        $lijst = read_json(voorraad_path(), []);
        $id = $in['id'] ?? uuid();
        $bestaand = null;
        foreach ($lijst as &$item) { if ($item['id'] === $id) { $bestaand = &$item; break; } }
        unset($item);
        $voorraadVoorWijziging = $bestaand !== null ? (int)($bestaand['huidigeVoorraad'] ?? 0) : null;

        $nieuw = [
            'id' => $id,
            'naam' => trim($in['naam']),
            'eenheid' => $in['eenheid'] ?? 'stuks',
            'huidigeVoorraad' => (int)($in['huidigeVoorraad'] ?? 0),
            'minimumVoorraad' => (int)($in['minimumVoorraad'] ?? 0),
            'waardePerStuk' => $in['waardePerStuk'] !== '' && $in['waardePerStuk'] !== null ? (float)$in['waardePerStuk'] : null,
            // Gewicht is optioneel en geldt per 'gewichtPerAantal' stuks
            // (bijv. 500 gram per 100 stuks) — zo hoef je bij dunne
            // flyers niet met kleine kommagetallen per los stuk te werken.
            'gewicht' => $in['gewicht'] !== '' && $in['gewicht'] !== null ? (float)$in['gewicht'] : null,
            'gewichtPerAantal' => max(1, (int)($in['gewichtPerAantal'] ?? 1)),
            'notitie' => $in['notitie'] ?? '',
            'subcategorieId' => array_key_exists('subcategorieId', $in) ? voorraad_subcat_geldig($in['subcategorieId']) : ($bestaand['subcategorieId'] ?? null),
            // Voorbeeldbeeld blijft staan bij het bewerken van andere velden —
            // wordt alleen via 'voorraad_thumbnail_upload' aangepast.
            'voorbeeldType' => $bestaand['voorbeeldType'] ?? 'geen',
            'voorbeeldPad' => $bestaand['voorbeeldPad'] ?? null,
            'updatedAt' => time() * 1000,
        ];
        if ($bestaand !== null) { $bestaand = $nieuw; } else { $lijst[] = $nieuw; }
        write_json(voorraad_path(), $lijst);

        // Handmatige aanpassing van de voorraad (los van aanvragen/bestellingen)
        // ook loggen, zodat de geschiedenis compleet blijft. Alleen bij een
        // bestaand item waarvan het aantal daadwerkelijk verandert — het
        // aanmaken van een gloednieuw item hoeft niet als "mutatie" te tellen.
        if ($voorraadVoorWijziging !== null && $nieuw['huidigeVoorraad'] !== $voorraadVoorWijziging) {
            log_voorraad_mutatie($id, $nieuw['naam'], $nieuw['huidigeVoorraad'] - $voorraadVoorWijziging, 'handmatige_aanpassing', 'Handmatig aangepast via voorraadbeheer.');
        }
        respond(['voorraad' => $lijst]);
        break;
    }

    case 'voorraad_delete': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $lijst = array_values(array_filter(read_json(voorraad_path(), []), fn($i) => $i['id'] !== $id));
        write_json(voorraad_path(), $lijst);
        respond(['deleted' => true]);
        break;
    }

    // Mutatiegeschiedenis van één voorraaditem — toont alles wat erbij of
    // eraf is gegaan (aanvragen, ontvangen bestellingen, handmatige
    // aanpassingen), nieuwste eerst.
    case 'voorraad_geschiedenis': {
        $id = $_GET['id'] ?? '';
        $mutaties = read_json(voorraad_mutaties_path(), []);
        $gefilterd = array_values(array_filter($mutaties, fn($m) => ($m['itemId'] ?? null) === $id));
        respond(['mutaties' => $gefilterd]);
        break;
    }

    // Voorbeeldbeeld/video per voorraaditem — zelfde systeem als de
    // Drive-database, zodat je meteen ziet hoe een artikel eruitziet.
    case 'voorraad_thumbnail_upload': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $lijst = read_json(voorraad_path(), []);
        $idx = null;
        foreach ($lijst as $i => $item) { if ($item['id'] === $id) { $idx = $i; break; } }
        if ($idx === null) respond(['error' => 'Voorraaditem niet gevonden.'], 404);
        if (empty($_FILES['bestand'])) respond(['error' => 'Geen bestand ontvangen.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload mislukt.'], 500);

        $toegestaan = [
            'image/jpeg' => ['ext' => 'jpg', 'type' => 'afbeelding'],
            'image/png' => ['ext' => 'png', 'type' => 'afbeelding'],
            'image/webp' => ['ext' => 'webp', 'type' => 'afbeelding'],
            'image/gif' => ['ext' => 'gif', 'type' => 'afbeelding'],
            'video/mp4' => ['ext' => 'mp4', 'type' => 'video'],
            'video/webm' => ['ext' => 'webm', 'type' => 'video'],
            'application/pdf' => ['ext' => 'pdf', 'type' => 'pdf'],
        ];
        $mime = mime_content_type($file['tmp_name']);
        if (!isset($toegestaan[$mime])) respond(['error' => 'Alleen JPG, PNG, WEBP, GIF, MP4, WEBM of PDF toegestaan.'], 400);
        if ($file['size'] > 15 * 1024 * 1024) respond(['error' => 'Bestand mag maximaal 15 MB zijn.'], 400);

        ensure_dir(DATA_DIR . '/drukwerk/thumbnails');
        if (!empty($lijst[$idx]['voorbeeldPad'])) {
            $oud = DATA_DIR . '/drukwerk/thumbnails/' . $lijst[$idx]['voorbeeldPad'];
            if (file_exists($oud)) unlink($oud);
        }
        $naam = veilige_token() . '.' . $toegestaan[$mime]['ext'];
        if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/drukwerk/thumbnails/' . $naam)) {
            respond(['error' => 'Opslaan mislukt.'], 500);
        }
        $lijst[$idx]['voorbeeldPad'] = $naam;
        $lijst[$idx]['voorbeeldType'] = $toegestaan[$mime]['type'];
        write_json(voorraad_path(), $lijst);
        respond(['voorraad' => $lijst]);
        break;
    }

    case 'voorraad_thumbnail_stream': {
        $bestand = basename($_GET['bestand'] ?? '');
        $pad = DATA_DIR . '/drukwerk/thumbnails/' . $bestand;
        if ($bestand === '' || !file_exists($pad)) { http_response_code(404); exit; }
        $ext = strtolower(pathinfo($pad, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($pad));
        header('Cache-Control: private, max-age=3600');
        readfile($pad);
        exit;
    }

    // ================= PAKKETTEN (vaste bundels, bijv. "Standaard PI Pakket") =================

    case 'pakket_list': {
        respond(['pakketten' => read_json(pakketten_path(), [])]);
        break;
    }

    case 'pakket_upsert': {
        require_role($coordinatorRollen);
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'Naam van het pakket is verplicht.'], 400);
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $items = array_values(array_filter(array_map(function ($it) {
            return ['naam' => trim($it['naam'] ?? ''), 'aantal' => (int)($it['aantal'] ?? 0)];
        }, $items), fn($it) => $it['naam'] !== '' && $it['aantal'] > 0));
        if (empty($items)) respond(['error' => 'Een pakket heeft minstens 1 item nodig.'], 400);

        $lijst = read_json(pakketten_path(), []);
        $id = $in['id'] ?? uuid();
        $bestaand = null;
        foreach ($lijst as &$p) { if ($p['id'] === $id) { $bestaand = &$p; break; } }
        unset($p);

        $nieuw = [
            'id' => $id,
            'naam' => trim($in['naam']),
            'type' => in_array($in['type'] ?? '', ['pi_pakket', 'literatuur_pakket'], true) ? $in['type'] : 'pi_pakket',
            'items' => $items,
            'prijsHoog' => $in['prijsHoog'] !== '' && $in['prijsHoog'] !== null ? (float)$in['prijsHoog'] : null,
            'prijsMiddel' => $in['prijsMiddel'] !== '' && $in['prijsMiddel'] !== null ? (float)$in['prijsMiddel'] : null,
            'prijsLaag' => $in['prijsLaag'] !== '' && $in['prijsLaag'] !== null ? (float)$in['prijsLaag'] : null,
            'updatedAt' => time() * 1000,
        ];
        if ($bestaand !== null) { $bestaand = $nieuw; } else { $lijst[] = $nieuw; }
        write_json(pakketten_path(), $lijst);
        respond(['pakketten' => $lijst]);
        break;
    }

    case 'pakket_delete': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $lijst = array_values(array_filter(read_json(pakketten_path(), []), fn($p) => $p['id'] !== $id));
        write_json(pakketten_path(), $lijst);
        respond(['deleted' => true]);
        break;
    }

    case 'pakket_bulk_import': {
        require_role($coordinatorRollen);
        $in = json_input();
        $rijen = $in['rijen'] ?? [];
        $groepen = []; // sleutel: naam|type

        foreach ($rijen as $rij) {
            $naam = trim($rij['pakketNaam'] ?? '');
            $item = trim($rij['item'] ?? '');
            $aantal = (int)($rij['aantal'] ?? 0);
            if ($naam === '' || $item === '' || $aantal <= 0) continue;
            $type = in_array($rij['type'] ?? '', ['pi_pakket', 'literatuur_pakket'], true) ? $rij['type'] : 'pi_pakket';
            $sleutel = $naam . '|' . $type;

            if (!isset($groepen[$sleutel])) {
                $groepen[$sleutel] = [
                    'naam' => $naam,
                    'type' => $type,
                    'items' => [],
                    'prijsHoog' => isset($rij['prijsPerPakketHoog']) && $rij['prijsPerPakketHoog'] !== '' ? (float)$rij['prijsPerPakketHoog'] : null,
                    'prijsMiddel' => isset($rij['prijsPerPakketMiddel']) && $rij['prijsPerPakketMiddel'] !== '' ? (float)$rij['prijsPerPakketMiddel'] : null,
                    'prijsLaag' => isset($rij['prijsPerPakketLaag']) && $rij['prijsPerPakketLaag'] !== '' ? (float)$rij['prijsPerPakketLaag'] : null,
                ];
            }
            $groepen[$sleutel]['items'][] = ['naam' => $item, 'aantal' => $aantal];
        }

        $lijst = read_json(pakketten_path(), []);
        foreach ($groepen as $g) {
            $lijst[] = [
                'id' => uuid(),
                'naam' => $g['naam'],
                'type' => $g['type'],
                'items' => $g['items'],
                'prijsHoog' => $g['prijsHoog'],
                'prijsMiddel' => $g['prijsMiddel'],
                'prijsLaag' => $g['prijsLaag'],
                'updatedAt' => time() * 1000,
            ];
        }
        write_json(pakketten_path(), $lijst);
        respond(['aangemaakt' => count($groepen)]);
        break;
    }

    // ================= AANVRAGEN (PI-leden vullen persoonlijke voorraad aan) =================

    case 'aanvraag_list': {
        ruim_oude_afwijzingen_op();
        $out = [];
        foreach (glob(DATA_DIR . '/drukwerk/aanvragen/*.json') as $file) {
            $a = read_json($file, null);
            if ($a) $out[] = $a;
        }
        usort($out, fn($a, $b) => $b['aangemaaktOp'] <=> $a['aangemaaktOp']);
        respond(['aanvragen' => $out]);
        break;
    }

    case 'aanvraag_extern_create': {
        $in = json_input();
        $extSleutel = 'x:' . sha1(klant_ip());
        if (!rate_limit_toegestaan($extSleutel, 8, 3600)) respond(['error' => 'Er zijn te veel aanvragen vanaf dit adres verstuurd. Probeer het later opnieuw.'], 429);
        rate_limit_registreer($extSleutel);

        // Honeypot: verborgen veld dat een mens nooit invult, bots vaak wel.
        // Vullen ze 'm toch in, dan doen we alsof het gelukt is (voorkomt dat
        // een bot doorprobeert), maar slaan we niks op.
        if (trim($in['website'] ?? '') !== '') {
            respond(['ok' => true]);
        }

        $instellingNaam = trim($in['instellingNaam'] ?? '');
        $contactpersoon = trim($in['contactpersoon'] ?? '');
        $email = strtolower(trim($in['email'] ?? ''));
        if ($instellingNaam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Vul minstens de naam van de instantie en een geldig e-mailadres in.'], 400);
        }

        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $items = array_values(array_filter(array_map(function ($it) {
            return ['naam' => trim($it['naam'] ?? ''), 'aantal' => (int)($it['aantal'] ?? 0)];
        }, $items), fn($it) => $it['naam'] !== '' && $it['aantal'] > 0));

        // Server-side dezelfde regels afdwingen als in de UI (A5 Flyers,
        // Visitekaartjes en Chitcards per 12; A3 Posters max 2 per
        // aanvraag) — nooit alleen op de browser vertrouwen.
        foreach ($items as $it) {
            if (preg_match('/A5 Flyers|Visitekaartjes|Chitcards/i', $it['naam']) && $it['aantal'] % 12 !== 0) {
                respond(['error' => $it['naam'] . ' kan alleen per 12 stuks aangevraagd worden.'], 400);
            }
            if (stripos($it['naam'], 'A3 Posters') !== false && $it['aantal'] > 2) {
                respond(['error' => 'Maximaal 2 A3 Posters per aanvraag.'], 400);
            }
        }

        if (empty($items)) respond(['error' => 'Kies minstens 1 item met een aantal groter dan 0.'], 400);

        $id = uuid();
        $aanvraag = [
            'id' => $id,
            'extern' => true,
            'aanvragerId' => 'extern:' . $email,
            'aanvragerNaam' => $instellingNaam . ($contactpersoon ? (' (' . $contactpersoon . ')') : ''),
            'contactEmail' => $email,
            'contactTelefoon' => trim($in['telefoon'] ?? ''),
            'afleveradres' => trim($in['afleveradres'] ?? ''),
            'items' => $items,
            'reden' => $in['opmerking'] ?? '',
            'status' => 'open',
            'aangemaaktOp' => time() * 1000,
            'behandeldOp' => null,
            'behandeldDoor' => null,
            'opmerkingCoordinator' => '',
        ];
        write_json(aanvraag_path($id), $aanvraag);

        $itemsTekst = implode("\n", array_map(fn($it) => '- ' . $it['aantal'] . 'x ' . $it['naam'], $items));
        notify_role(
            'coordinator_drukwerk',
            'Nieuwe externe bestelling — ' . $instellingNaam,
            'Externe instantie ' . $instellingNaam . ($contactpersoon ? (' (contactpersoon: ' . $contactpersoon . ')') : '') . ' bestelt:' . "\n" . $itemsTekst .
                "\n\nE-mail: " . $email . ($aanvraag['contactTelefoon'] ? ("\nTelefoon: " . $aanvraag['contactTelefoon']) : '') .
                ($aanvraag['afleveradres'] ? ("\nAfleveradres: " . $aanvraag['afleveradres']) : '') .
                ($aanvraag['reden'] ? ("\nOpmerking: " . $aanvraag['reden']) : ''),
            'drukwerk.php?tab=aanvragen'
        );

        respond(['ok' => true], 201);
        break;
    }

    case 'aanvraag_create': {
        $in = json_input();
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $items = array_values(array_filter(array_map(function ($it) {
            return ['naam' => trim($it['naam'] ?? ''), 'aantal' => (int)($it['aantal'] ?? 0)];
        }, $items), fn($it) => $it['naam'] !== '' && $it['aantal'] > 0));

        if (empty($items)) {
            respond(['error' => 'Kies minstens 1 item met een aantal groter dan 0.'], 400);
        }

        $id = uuid();
        $aanvraag = [
            'id' => $id,
            'aanvragerId' => $_SESSION['user_id'],
            'aanvragerNaam' => $_SESSION['user_name'] ?? $_SESSION['user_id'],
            'afleveradres' => trim($in['afleveradres'] ?? ''),
            'pakket' => trim($in['pakket'] ?? ''), // ter info: welk standaardpakket als basis diende (optioneel)
            'items' => $items,
            'reden' => $in['reden'] ?? '',
            'status' => 'open',
            'aangemaaktOp' => time() * 1000,
            'behandeldOp' => null,
            'behandeldDoor' => null,
            'opmerkingCoordinator' => '',
        ];
        write_json(aanvraag_path($id), $aanvraag);

        $itemsTekst = implode("\n", array_map(fn($it) => '- ' . $it['aantal'] . 'x ' . $it['naam'], $items));
        notify_role(
            'coordinator_drukwerk',
            'Nieuwe drukwerkaanvraag' . ($aanvraag['pakket'] ? (' — ' . $aanvraag['pakket']) : ''),
            $aanvraag['aanvragerNaam'] . ' vraagt aan:' . "\n" . $itemsTekst .
                ($aanvraag['afleveradres'] ? ("\n\nAfleveradres: " . $aanvraag['afleveradres']) : '') .
                ($aanvraag['reden'] ? ("\nToelichting: " . $aanvraag['reden']) : ''),
            'drukwerk.php?tab=aanvragen'
        );

        respond(['aanvraag' => $aanvraag], 201);
        break;
    }

    case 'aanvraag_update': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);

        $magBewerken = $aanvraag['aanvragerId'] === $_SESSION['user_id'] || heeft_rol($coordinatorRollen);
        if (!$magBewerken) respond(['error' => 'Geen toestemming.'], 403);
        if ($aanvraag['status'] !== 'open') respond(['error' => 'Deze aanvraag is al behandeld en kan niet meer gewijzigd worden.'], 400);

        $in = json_input();
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $items = array_values(array_filter(array_map(function ($it) {
            return ['naam' => trim($it['naam'] ?? ''), 'aantal' => (int)($it['aantal'] ?? 0)];
        }, $items), fn($it) => $it['naam'] !== '' && $it['aantal'] > 0));
        if (empty($items)) respond(['error' => 'Kies minstens 1 item met een aantal groter dan 0.'], 400);

        $aanvraag['items'] = $items;
        $aanvraag['afleveradres'] = trim($in['afleveradres'] ?? $aanvraag['afleveradres']);
        $aanvraag['reden'] = $in['reden'] ?? $aanvraag['reden'];
        write_json(aanvraag_path($id), $aanvraag);
        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_delete': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(aanvraag_path($id), null);
        if (!$aanvraag) respond(['deleted' => true]); // al weg, prima

        $isCoordinator = heeft_rol($coordinatorRollen);
        $isEigenOpenAanvraag = $aanvraag['aanvragerId'] === $_SESSION['user_id'] && $aanvraag['status'] === 'open';
        if (!$isCoordinator && !$isEigenOpenAanvraag) respond(['error' => 'Geen toestemming.'], 403);

        unlink(aanvraag_path($id));
        respond(['deleted' => true]);
        break;
    }

    case 'aanvraag_besluit': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);
        $in = json_input();
        $besluit = $in['besluit'] ?? ''; // 'goedgekeurd' | 'afgewezen'
        if (!in_array($besluit, ['goedgekeurd', 'afgewezen'], true)) respond(['error' => 'Ongeldig besluit.'], 400);

        $aanvraag['status'] = $besluit;
        $aanvraag['behandeldOp'] = time() * 1000;
        $aanvraag['behandeldDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $aanvraag['opmerkingCoordinator'] = $in['opmerking'] ?? '';
        write_json(aanvraag_path($id), $aanvraag);

        // Voorraad automatisch aanpassen bij goedkeuring — zoekt per item op
        // exacte naam-match in de voorraadlijst; bestaat een item niet, dan
        // wordt de aanvraag gewoon goedgekeurd zonder dat item te muteren.
        if ($besluit === 'goedgekeurd') {
            $voorraad = read_json(voorraad_path(), []);
            $aanvragerNaam = $aanvraag['aanvragerNaam'] ?? 'Onbekend';
            foreach ($aanvraag['items'] as $besteld) {
                foreach ($voorraad as &$item) {
                    if (strcasecmp($item['naam'], $besteld['naam']) === 0) {
                        $voorVoorraad = $item['huidigeVoorraad'];
                        $item['huidigeVoorraad'] = max(0, $item['huidigeVoorraad'] - $besteld['aantal']);
                        $item['updatedAt'] = time() * 1000;
                        $werkelijkeAfname = $item['huidigeVoorraad'] - $voorVoorraad; // negatief getal
                        log_voorraad_mutatie($item['id'], $item['naam'], $werkelijkeAfname, 'aanvraag_goedgekeurd',
                            'Aanvraag van ' . $aanvragerNaam . ' (' . $besteld['aantal'] . 'x aangevraagd)' . ($werkelijkeAfname > -$besteld['aantal'] ? ' — voorraad kwam niet toereikend uit, tot 0 afgeboekt' : '') . '.');
                        break;
                    }
                }
                unset($item);
            }
            write_json(voorraad_path(), $voorraad);
        }

        $itemsTekst = implode("\n", array_map(fn($it) => '- ' . $it['aantal'] . 'x ' . $it['naam'], $aanvraag['items']));
        $terugkoppelingTekst = 'Uw bestelling is ' . $besluit . ':' . "\n" . $itemsTekst .
            ($aanvraag['opmerkingCoordinator'] ? ("\n\nOpmerking: " . $aanvraag['opmerkingCoordinator']) : '');

        if (!empty($aanvraag['extern'])) {
            // Externe aanvrager heeft geen account/portaalbericht — rechtstreeks mailen.
            if (!empty($aanvraag['contactEmail'])) {
                @smtp_send($aanvraag['contactEmail'], 'Uw materiaalbestelling is ' . $besluit, $terugkoppelingTekst);
            }
        } else {
            notify([$aanvraag['aanvragerId']], 'Drukwerkaanvraag ' . $besluit, $terugkoppelingTekst, 'drukwerk.php?tab=aanvragen');
        }

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    // ================= BESTELLINGEN (bij leveranciers) =================

    case 'bestelling_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/drukwerk/bestellingen/*.json') as $file) {
            $b = read_json($file, null);
            if ($b) $out[] = $b;
        }
        usort($out, fn($a, $b) => $b['besteldOp'] <=> $a['besteldOp']);
        respond(['bestellingen' => $out]);
        break;
    }

    case 'bestelling_create': {
        $in = json_input();
        if (trim($in['product'] ?? '') === '') respond(['error' => 'Product is verplicht.'], 400);
        $id = uuid();
        $bestelling = [
            'id' => $id,
            'leverancier' => $in['leverancier'] ?? '',
            'product' => trim($in['product']),
            'aantal' => (int)($in['aantal'] ?? 0),
            'totaalprijs' => $in['totaalprijs'] !== '' ? (float)($in['totaalprijs'] ?? 0) : null,
            'besteldDoor' => $_SESSION['user_name'] ?? current_user_slug(),
            'besteldOp' => time() * 1000,
            // Elke bestelling begint als aanvraag — pas na goedkeuring door
            // Coördinator Drukwerk/IT wordt hij daadwerkelijk 'besteld'.
            'status' => 'aangevraagd', // aangevraagd | afgewezen | besteld | onderweg | ontvangen
            'goedgekeurdDoor' => null,
            'goedgekeurdOp' => null,
            'notitie' => $in['notitie'] ?? '',
        ];
        write_json(bestelling_path($id), $bestelling);
        notify_role('coordinator_drukwerk', 'Nieuwe bestelling ter goedkeuring — ' . $bestelling['product'],
            $bestelling['besteldDoor'] . ' wil bestellen: ' . $bestelling['aantal'] . 'x ' . $bestelling['product'] . ($bestelling['leverancier'] ? ' bij ' . $bestelling['leverancier'] : ''),
            'drukwerk.php?tab=bestellingen');
        respond(['bestelling' => $bestelling], 201);
        break;
    }

    case 'bestelling_goedkeuren': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $bestelling = read_json(bestelling_path($id), null);
        if (!$bestelling) respond(['error' => 'Bestelling niet gevonden.'], 404);
        if ($bestelling['status'] !== 'aangevraagd') respond(['error' => 'Deze bestelling staat niet meer als aanvraag open.'], 400);
        $bestelling['status'] = 'besteld';
        $bestelling['goedgekeurdDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $bestelling['goedgekeurdOp'] = time() * 1000;
        write_json(bestelling_path($id), $bestelling);
        respond(['bestelling' => $bestelling]);
        break;
    }

    case 'bestelling_afwijzen': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $bestelling = read_json(bestelling_path($id), null);
        if (!$bestelling) respond(['error' => 'Bestelling niet gevonden.'], 404);
        if ($bestelling['status'] !== 'aangevraagd') respond(['error' => 'Deze bestelling staat niet meer als aanvraag open.'], 400);
        $bestelling['status'] = 'afgewezen';
        write_json(bestelling_path($id), $bestelling);
        respond(['bestelling' => $bestelling]);
        break;
    }

    case 'bestelling_update_status': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $bestelling = read_json(bestelling_path($id), null);
        if (!$bestelling) respond(['error' => 'Bestelling niet gevonden.'], 404);
        if ($bestelling['status'] === 'aangevraagd') respond(['error' => 'Deze bestelling moet eerst goedgekeurd worden.'], 400);
        $in = json_input();
        $status = $in['status'] ?? '';
        if (!in_array($status, ['besteld', 'onderweg', 'ontvangen'], true)) respond(['error' => 'Ongeldige status.'], 400);
        $wasNietOntvangen = $bestelling['status'] !== 'ontvangen';
        $bestelling['status'] = $status;
        write_json(bestelling_path($id), $bestelling);

        // Bij 'ontvangen' de regionale voorraad ophogen (alleen de eerste
        // keer dat 'ie op ontvangen gezet wordt, om dubbeltellen te voorkomen).
        if ($status === 'ontvangen' && $wasNietOntvangen && $bestelling['aantal'] > 0) {
            $voorraad = read_json(voorraad_path(), []);
            $gevonden = false;
            $mutatieItemId = null;
            foreach ($voorraad as &$item) {
                if (strcasecmp($item['naam'], $bestelling['product']) === 0) {
                    $item['huidigeVoorraad'] += $bestelling['aantal'];
                    $item['updatedAt'] = time() * 1000;
                    $gevonden = true;
                    $mutatieItemId = $item['id'];
                    break;
                }
            }
            unset($item);
            if (!$gevonden) {
                $mutatieItemId = uuid();
                $voorraad[] = [
                    'id' => $mutatieItemId,
                    'naam' => $bestelling['product'],
                    'eenheid' => 'stuks',
                    'huidigeVoorraad' => $bestelling['aantal'],
                    'minimumVoorraad' => 0,
                    'waardePerStuk' => $bestelling['totaalprijs'] !== null ? round($bestelling['totaalprijs'] / max(1, $bestelling['aantal']), 4) : null,
                    'notitie' => 'Automatisch aangemaakt vanuit ontvangen bestelling.',
                    'updatedAt' => time() * 1000,
                ];
            }
            write_json(voorraad_path(), $voorraad);
            log_voorraad_mutatie($mutatieItemId, $bestelling['product'], $bestelling['aantal'], 'bestelling_ontvangen',
                'Bestelling ontvangen' . ($bestelling['leverancier'] ? (' bij ' . $bestelling['leverancier']) : '') . ', besteld door ' . ($bestelling['besteldDoor'] ?? 'onbekend') . '.');
        }

        respond(['bestelling' => $bestelling]);
        break;
    }

    // ================= LEVERANCIERS & PRIJZEN =================

    case 'leverancier_list': {
        respond(['leveranciers' => read_json(leveranciers_path(), [])]);
        break;
    }

    case 'leverancier_upsert': {
        require_role($coordinatorRollen);
        $in = json_input();
        if (trim($in['leverancier'] ?? '') === '' || trim($in['product'] ?? '') === '') {
            respond(['error' => 'Leverancier en product zijn verplicht.'], 400);
        }
        $lijst = read_json(leveranciers_path(), []);
        $id = $in['id'] ?? uuid();
        $bestaand = null;
        foreach ($lijst as &$item) { if ($item['id'] === $id) { $bestaand = &$item; break; } }
        unset($item);

        $prijzen = leeg_prijzen();
        foreach (PRIJSTIERS as $t) {
            $key = (string)$t;
            if (isset($in['prijzen'][$key])) {
                $prijzen[$key]['totaal'] = $in['prijzen'][$key]['totaal'] !== '' && $in['prijzen'][$key]['totaal'] !== null ? (float)$in['prijzen'][$key]['totaal'] : null;
                $prijzen[$key]['perStuk'] = $in['prijzen'][$key]['perStuk'] !== '' && $in['prijzen'][$key]['perStuk'] !== null ? (float)$in['prijzen'][$key]['perStuk'] : null;
            }
        }

        $nieuw = [
            'id' => $id,
            'leverancier' => trim($in['leverancier']),
            'product' => trim($in['product']),
            'aantalVarianten' => max(1, (int)($in['aantalVarianten'] ?? 1)),
            'link' => veilige_url($in['link'] ?? ''),
            'notitie' => $in['notitie'] ?? '',
            'prijzen' => $prijzen,
            'updatedAt' => time() * 1000,
        ];
        if ($bestaand !== null) { $bestaand = $nieuw; } else { $lijst[] = $nieuw; }
        write_json(leveranciers_path(), $lijst);
        respond(['leveranciers' => $lijst]);
        break;
    }

    case 'leverancier_delete': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $lijst = array_values(array_filter(read_json(leveranciers_path(), []), fn($i) => $i['id'] !== $id));
        write_json(leveranciers_path(), $lijst);
        respond(['deleted' => true]);
        break;
    }

    case 'leverancier_bulk_import': {
        require_role($coordinatorRollen);
        $in = json_input();
        $rijen = $in['rijen'] ?? [];
        $lijst = read_json(leveranciers_path(), []);
        $aangemaakt = 0;

        foreach ($rijen as $rij) {
            if (trim($rij['leverancier'] ?? '') === '' || trim($rij['product'] ?? '') === '') continue;
            $prijzen = leeg_prijzen();
            foreach (PRIJSTIERS as $t) {
                $totaalKey = 'prijs_' . $t . '_totaal';
                $perStukKey = 'prijs_' . $t . '_perstuk';
                $prijzen[(string)$t]['totaal'] = isset($rij[$totaalKey]) && $rij[$totaalKey] !== '' ? (float)$rij[$totaalKey] : null;
                $prijzen[(string)$t]['perStuk'] = isset($rij[$perStukKey]) && $rij[$perStukKey] !== '' ? (float)$rij[$perStukKey] : null;
            }
            $lijst[] = [
                'id' => uuid(),
                'leverancier' => trim($rij['leverancier']),
                'product' => trim($rij['product']),
                'aantalVarianten' => max(1, (int)($rij['aantalVarianten'] ?? 1)),
                'link' => veilige_url($rij['link'] ?? ''),
                'notitie' => $rij['notitie'] ?? '',
                'prijzen' => $prijzen,
                'updatedAt' => time() * 1000,
            ];
            $aangemaakt++;
        }
        write_json(leveranciers_path(), $lijst);
        respond(['aangemaakt' => $aangemaakt]);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}
