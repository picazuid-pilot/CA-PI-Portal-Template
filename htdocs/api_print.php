<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

// These actions are deliberately usable without being logged in: fetching
// the literature package (for the public order page) and submitting an
// order by an external institution (not a PI member, no account).
$publiekeActies = ['pakket_list', 'aanvraag_extern_create'];
if (!in_array($action, $publiekeActies, true)) {
    require_login();
}
$coordinatorRollen = ['admin', 'coordinator_distribution'];

function voorraad_path() { return DATA_DIR . '/drukwerk/voorraad.json'; }
function voorraad_mutaties_path() { return DATA_DIR . '/drukwerk/voorraad_mutaties.json'; }
function leveranciers_path() { return DATA_DIR . '/drukwerk/leveranciers.json'; }
function pakketten_path() { return DATA_DIR . '/drukwerk/pakketten.json'; }
function aanvraag_path($id) { return DATA_DIR . '/drukwerk/aanvragen/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }
function bestelling_path($id) { return DATA_DIR . '/drukwerk/bestellingen/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json'; }

// Records every stock mutation (both additions and deductions) so you can
// look back per item at what changed, when, and why. $itemId may be null
// (e.g. when an order receives a not-yet-known product and a new stock
// item is created for it right away) — the history view then falls back
// to matching by name.
function log_voorraad_mutatie($itemId, $itemNaam, $delta, $reden, $omschrijving) {
    if ($delta === 0) return; // nothing changed, no entry needed
    $mutaties = read_json(voorraad_mutaties_path(), []);
    array_unshift($mutaties, [
        'id' => uuid(),
        'itemId' => $itemId,
        'itemNaam' => $itemNaam,
        'delta' => $delta, // positive = added, negative = deducted
        'reden' => $reden, // 'aanvraag_goedgekeurd' | 'bestelling_ontvangen' | 'handmatige_aanpassing'
        'omschrijving' => $omschrijving,
        'door' => $_SESSION['user_name'] ?? current_user_slug(),
        'datum' => time() * 1000,
    ]);
    // No need to let this grow unbounded — 2000 entries is plenty of
    // history and keeps this file from growing too large over time.
    $mutaties = array_slice($mutaties, 0, 2000);
    write_json(voorraad_mutaties_path(), $mutaties);
}

// Price tiers the system understands — fixed, since the UI (columns) is built around these.
const PRIJSTIERS = [500, 1000, 2500, 5000];

function leeg_prijzen() {
    $out = [];
    foreach (PRIJSTIERS as $t) $out[(string)$t] = ['totaal' => null, 'perStuk' => null];
    return $out;
}

// Cleans up rejected requests that were handled more than a week ago. No
// cron needed on this hosting: this simply runs every time the request
// list is fetched — unobtrusive and good enough.
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

    // ================= STOCK =================

    case 'voorraad_list': {
        respond(['voorraad' => read_json(voorraad_path(), [])]);
        break;
    }

    case 'voorraad_upsert': {
        require_role($coordinatorRollen);
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'Name is required.'], 400);
        $lijst = read_json(voorraad_path(), []);
        $id = $in['id'] ?? uuid();
        $bestaand = null;
        foreach ($lijst as &$item) { if ($item['id'] === $id) { $bestaand = &$item; break; } }
        unset($item);
        $voorraadVoorWijziging = $bestaand !== null ? (int)($bestaand['huidigeVoorraad'] ?? 0) : null;

        $nieuw = [
            'id' => $id,
            'naam' => trim($in['naam']),
            'eenheid' => $in['eenheid'] ?? 'pieces',
            'huidigeVoorraad' => (int)($in['huidigeVoorraad'] ?? 0),
            'minimumVoorraad' => (int)($in['minimumVoorraad'] ?? 0),
            'waardePerStuk' => $in['waardePerStuk'] !== '' && $in['waardePerStuk'] !== null ? (float)$in['waardePerStuk'] : null,
            // Weight is optional and applies per 'gewichtPerAantal' units
            // (e.g. 500 grams per 100 units) — this way thin flyers don't
            // need tiny decimal weights per single unit.
            'gewicht' => $in['gewicht'] !== '' && $in['gewicht'] !== null ? (float)$in['gewicht'] : null,
            'gewichtPerAantal' => max(1, (int)($in['gewichtPerAantal'] ?? 1)),
            'notitie' => $in['notitie'] ?? '',
            // The preview image stays as-is when editing other fields —
            // only changed via 'voorraad_thumbnail_upload'.
            'voorbeeldType' => $bestaand['voorbeeldType'] ?? 'geen',
            'voorbeeldPad' => $bestaand['voorbeeldPad'] ?? null,
            'updatedAt' => time() * 1000,
        ];
        if ($bestaand !== null) { $bestaand = $nieuw; } else { $lijst[] = $nieuw; }
        write_json(voorraad_path(), $lijst);

        // Also log a manual stock adjustment (separate from requests/orders)
        // so the history stays complete. Only for an existing item whose
        // quantity actually changes — creating a brand-new item doesn't
        // need to count as a "mutation".
        if ($voorraadVoorWijziging !== null && $nieuw['huidigeVoorraad'] !== $voorraadVoorWijziging) {
            log_voorraad_mutatie($id, $nieuw['naam'], $nieuw['huidigeVoorraad'] - $voorraadVoorWijziging, 'handmatige_aanpassing', 'Manually adjusted via stock management.');
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

    // Mutation history for a single stock item — shows everything added or
    // removed (requests, received orders, manual adjustments), newest first.
    case 'voorraad_geschiedenis': {
        $id = $_GET['id'] ?? '';
        $mutaties = read_json(voorraad_mutaties_path(), []);
        $gefilterd = array_values(array_filter($mutaties, fn($m) => ($m['itemId'] ?? null) === $id));
        respond(['mutaties' => $gefilterd]);
        break;
    }

    // Preview image/video per stock item — same system as the Drive
    // Database, so you can immediately see what an item looks like.
    case 'voorraad_thumbnail_upload': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $lijst = read_json(voorraad_path(), []);
        $idx = null;
        foreach ($lijst as $i => $item) { if ($item['id'] === $id) { $idx = $i; break; } }
        if ($idx === null) respond(['error' => 'Stock item not found.'], 404);
        if (empty($_FILES['bestand'])) respond(['error' => 'No file received.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload failed.'], 500);

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
        if (!isset($toegestaan[$mime])) respond(['error' => 'Only JPG, PNG, WEBP, GIF, MP4, WEBM, or PDF are allowed.'], 400);
        if ($file['size'] > 15 * 1024 * 1024) respond(['error' => 'File must be no larger than 15 MB.'], 400);

        ensure_dir(DATA_DIR . '/drukwerk/thumbnails');
        if (!empty($lijst[$idx]['voorbeeldPad'])) {
            $oud = DATA_DIR . '/drukwerk/thumbnails/' . $lijst[$idx]['voorbeeldPad'];
            if (file_exists($oud)) unlink($oud);
        }
        $naam = veilige_token() . '.' . $toegestaan[$mime]['ext'];
        if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/drukwerk/thumbnails/' . $naam)) {
            respond(['error' => 'Saving failed.'], 500);
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

    // ================= PACKAGES (fixed bundles, e.g. "Standard PI Package") =================

    case 'pakket_list': {
        respond(['pakketten' => read_json(pakketten_path(), [])]);
        break;
    }

    case 'pakket_upsert': {
        require_role($coordinatorRollen);
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'The package name is required.'], 400);
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $items = array_values(array_filter(array_map(function ($it) {
            return ['naam' => trim($it['naam'] ?? ''), 'aantal' => (int)($it['aantal'] ?? 0)];
        }, $items), fn($it) => $it['naam'] !== '' && $it['aantal'] > 0));
        if (empty($items)) respond(['error' => 'A package needs at least 1 item.'], 400);

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
        $groepen = []; // key: name|type

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

    // ================= REQUESTS (PI members restocking their personal supply) =================

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

        // Honeypot: hidden field a human never fills in, bots often do. If
        // they fill it in anyway, we pretend it succeeded (so the bot
        // doesn't keep retrying), but don't save anything.
        if (trim($in['website'] ?? '') !== '') {
            respond(['ok' => true]);
        }

        $instellingNaam = trim($in['instellingNaam'] ?? '');
        $contactpersoon = trim($in['contactpersoon'] ?? '');
        $email = strtolower(trim($in['email'] ?? ''));
        if ($instellingNaam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Enter at least the institution name and a valid email address.'], 400);
        }

        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $items = array_values(array_filter(array_map(function ($it) {
            return ['naam' => trim($it['naam'] ?? ''), 'aantal' => (int)($it['aantal'] ?? 0)];
        }, $items), fn($it) => $it['naam'] !== '' && $it['aantal'] > 0));

        // Enforce the same rules server-side as in the UI (A5 Flyers,
        // Business cards, and Chit cards per 12; A3 Posters max 2 per
        // request) — never rely on the browser alone.
        foreach ($items as $it) {
            if (preg_match('/A5 Flyers|Business cards|Chit cards/i', $it['naam']) && $it['aantal'] % 12 !== 0) {
                respond(['error' => $it['naam'] . ' can only be requested in batches of 12.'], 400);
            }
            if (stripos($it['naam'], 'A3 Posters') !== false && $it['aantal'] > 2) {
                respond(['error' => 'Maximum of 2 A3 Posters per request.'], 400);
            }
        }

        if (empty($items)) respond(['error' => 'Choose at least 1 item with a quantity greater than 0.'], 400);

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
            'coordinator_distribution',
            'New external order — ' . $instellingNaam,
            'External institution ' . $instellingNaam . ($contactpersoon ? (' (contact: ' . $contactpersoon . ')') : '') . ' is ordering:' . "\n" . $itemsTekst .
                "\n\nEmail: " . $email . ($aanvraag['contactTelefoon'] ? ("\nPhone: " . $aanvraag['contactTelefoon']) : '') .
                ($aanvraag['afleveradres'] ? ("\nDelivery address: " . $aanvraag['afleveradres']) : '') .
                ($aanvraag['reden'] ? ("\nNote: " . $aanvraag['reden']) : ''),
            'print.php?tab=aanvragen'
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
            respond(['error' => 'Choose at least 1 item with a quantity greater than 0.'], 400);
        }

        $id = uuid();
        $aanvraag = [
            'id' => $id,
            'aanvragerId' => $_SESSION['user_id'],
            'aanvragerNaam' => $_SESSION['user_name'] ?? $_SESSION['user_id'],
            'afleveradres' => trim($in['afleveradres'] ?? ''),
            'pakket' => trim($in['pakket'] ?? ''), // for reference: which standard package this was based on (optional)
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
            'coordinator_distribution',
            'New print request' . ($aanvraag['pakket'] ? (' — ' . $aanvraag['pakket']) : ''),
            $aanvraag['aanvragerNaam'] . ' is requesting:' . "\n" . $itemsTekst .
                ($aanvraag['afleveradres'] ? ("\n\nDelivery address: " . $aanvraag['afleveradres']) : '') .
                ($aanvraag['reden'] ? ("\nNote: " . $aanvraag['reden']) : ''),
            'print.php?tab=aanvragen'
        );

        respond(['aanvraag' => $aanvraag], 201);
        break;
    }

    case 'aanvraag_update': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);

        $magBewerken = $aanvraag['aanvragerId'] === $_SESSION['user_id'] || heeft_rol($coordinatorRollen);
        if (!$magBewerken) respond(['error' => 'You are not permitted to do this.'], 403);
        if ($aanvraag['status'] !== 'open') respond(['error' => 'This request has already been processed and can no longer be changed.'], 400);

        $in = json_input();
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $items = array_values(array_filter(array_map(function ($it) {
            return ['naam' => trim($it['naam'] ?? ''), 'aantal' => (int)($it['aantal'] ?? 0)];
        }, $items), fn($it) => $it['naam'] !== '' && $it['aantal'] > 0));
        if (empty($items)) respond(['error' => 'Choose at least 1 item with a quantity greater than 0.'], 400);

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
        if (!$aanvraag) respond(['deleted' => true]); // already gone, fine

        $isCoordinator = heeft_rol($coordinatorRollen);
        $isEigenOpenAanvraag = $aanvraag['aanvragerId'] === $_SESSION['user_id'] && $aanvraag['status'] === 'open';
        if (!$isCoordinator && !$isEigenOpenAanvraag) respond(['error' => 'You are not permitted to do this.'], 403);

        unlink(aanvraag_path($id));
        respond(['deleted' => true]);
        break;
    }

    case 'aanvraag_besluit': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);
        $in = json_input();
        $besluit = $in['besluit'] ?? ''; // 'goedgekeurd' | 'afgewezen'
        if (!in_array($besluit, ['goedgekeurd', 'afgewezen'], true)) respond(['error' => 'Invalid decision.'], 400);

        $aanvraag['status'] = $besluit;
        $aanvraag['behandeldOp'] = time() * 1000;
        $aanvraag['behandeldDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $aanvraag['opmerkingCoordinator'] = $in['opmerking'] ?? '';
        write_json(aanvraag_path($id), $aanvraag);

        // Automatically adjust stock on approval — looks up each item by an
        // exact name match in the stock list; if an item doesn't exist, the
        // request is simply approved without mutating that item.
        if ($besluit === 'goedgekeurd') {
            $voorraad = read_json(voorraad_path(), []);
            $aanvragerNaam = $aanvraag['aanvragerNaam'] ?? 'Unknown';
            foreach ($aanvraag['items'] as $besteld) {
                foreach ($voorraad as &$item) {
                    if (strcasecmp($item['naam'], $besteld['naam']) === 0) {
                        $voorVoorraad = $item['huidigeVoorraad'];
                        $item['huidigeVoorraad'] = max(0, $item['huidigeVoorraad'] - $besteld['aantal']);
                        $item['updatedAt'] = time() * 1000;
                        $werkelijkeAfname = $item['huidigeVoorraad'] - $voorVoorraad; // negative number
                        log_voorraad_mutatie($item['id'], $item['naam'], $werkelijkeAfname, 'aanvraag_goedgekeurd',
                            'Request from ' . $aanvragerNaam . ' (' . $besteld['aantal'] . 'x requested)' . ($werkelijkeAfname > -$besteld['aantal'] ? ' — stock was insufficient, deducted down to 0' : '') . '.');
                        break;
                    }
                }
                unset($item);
            }
            write_json(voorraad_path(), $voorraad);
        }

        $itemsTekst = implode("\n", array_map(fn($it) => '- ' . $it['aantal'] . 'x ' . $it['naam'], $aanvraag['items']));
        $terugkoppelingTekst = 'Your order has been ' . ($besluit === 'goedgekeurd' ? 'approved' : 'rejected') . ':' . "\n" . $itemsTekst .
            ($aanvraag['opmerkingCoordinator'] ? ("\n\nNote: " . $aanvraag['opmerkingCoordinator']) : '');

        if (!empty($aanvraag['extern'])) {
            // An external requester has no account/in-portal message — email them directly.
            if (!empty($aanvraag['contactEmail'])) {
                @smtp_send($aanvraag['contactEmail'], 'Your material order has been ' . ($besluit === 'goedgekeurd' ? 'approved' : 'rejected'), $terugkoppelingTekst);
            }
        } else {
            notify([$aanvraag['aanvragerId']], 'Print request ' . ($besluit === 'goedgekeurd' ? 'approved' : 'rejected'), $terugkoppelingTekst, 'print.php?tab=aanvragen');
        }

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    // ================= ORDERS (to suppliers) =================

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
        if (trim($in['product'] ?? '') === '') respond(['error' => 'Product is required.'], 400);
        $id = uuid();
        $bestelling = [
            'id' => $id,
            'leverancier' => $in['leverancier'] ?? '',
            'product' => trim($in['product']),
            'aantal' => (int)($in['aantal'] ?? 0),
            'totaalprijs' => $in['totaalprijs'] !== '' ? (float)($in['totaalprijs'] ?? 0) : null,
            'besteldDoor' => $_SESSION['user_name'] ?? current_user_slug(),
            'besteldOp' => time() * 1000,
            // Every order starts as a request — only after approval by the
            // Print Distribution Coordinator/IT does it actually get 'ordered'.
            'status' => 'aangevraagd', // aangevraagd | afgewezen | besteld | onderweg | ontvangen
            'goedgekeurdDoor' => null,
            'goedgekeurdOp' => null,
            'notitie' => $in['notitie'] ?? '',
        ];
        write_json(bestelling_path($id), $bestelling);
        notify_role('coordinator_distribution', 'New order awaiting approval — ' . $bestelling['product'],
            $bestelling['besteldDoor'] . ' wants to order: ' . $bestelling['aantal'] . 'x ' . $bestelling['product'] . ($bestelling['leverancier'] ? ' from ' . $bestelling['leverancier'] : ''),
            'print.php?tab=bestellingen');
        respond(['bestelling' => $bestelling], 201);
        break;
    }

    case 'bestelling_goedkeuren': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $bestelling = read_json(bestelling_path($id), null);
        if (!$bestelling) respond(['error' => 'Order not found.'], 404);
        if ($bestelling['status'] !== 'aangevraagd') respond(['error' => 'This order is no longer awaiting approval.'], 400);
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
        if (!$bestelling) respond(['error' => 'Order not found.'], 404);
        if ($bestelling['status'] !== 'aangevraagd') respond(['error' => 'This order is no longer awaiting approval.'], 400);
        $bestelling['status'] = 'afgewezen';
        write_json(bestelling_path($id), $bestelling);
        respond(['bestelling' => $bestelling]);
        break;
    }

    case 'bestelling_update_status': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $bestelling = read_json(bestelling_path($id), null);
        if (!$bestelling) respond(['error' => 'Order not found.'], 404);
        if ($bestelling['status'] === 'aangevraagd') respond(['error' => 'This order must be approved first.'], 400);
        $in = json_input();
        $status = $in['status'] ?? '';
        if (!in_array($status, ['besteld', 'onderweg', 'ontvangen'], true)) respond(['error' => 'Invalid status.'], 400);
        $wasNietOntvangen = $bestelling['status'] !== 'ontvangen';
        $bestelling['status'] = $status;
        write_json(bestelling_path($id), $bestelling);

        // On 'received', increase the regional stock (only the first time
        // it's set to received, to avoid double-counting).
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
                    'eenheid' => 'pieces',
                    'huidigeVoorraad' => $bestelling['aantal'],
                    'minimumVoorraad' => 0,
                    'waardePerStuk' => $bestelling['totaalprijs'] !== null ? round($bestelling['totaalprijs'] / max(1, $bestelling['aantal']), 4) : null,
                    'notitie' => 'Automatically created from a received order.',
                    'updatedAt' => time() * 1000,
                ];
            }
            write_json(voorraad_path(), $voorraad);
            log_voorraad_mutatie($mutatieItemId, $bestelling['product'], $bestelling['aantal'], 'bestelling_ontvangen',
                'Order received' . ($bestelling['leverancier'] ? (' from ' . $bestelling['leverancier']) : '') . ', ordered by ' . ($bestelling['besteldDoor'] ?? 'unknown') . '.');
        }

        respond(['bestelling' => $bestelling]);
        break;
    }

    // ================= SUPPLIERS & PRICING =================

    case 'leverancier_list': {
        respond(['leveranciers' => read_json(leveranciers_path(), [])]);
        break;
    }

    case 'leverancier_upsert': {
        require_role($coordinatorRollen);
        $in = json_input();
        if (trim($in['leverancier'] ?? '') === '' || trim($in['product'] ?? '') === '') {
            respond(['error' => 'Supplier and product are required.'], 400);
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
            'link' => $in['link'] ?? '',
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
                'link' => $rij['link'] ?? '',
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
        respond(['error' => 'unknown action'], 400);
}
