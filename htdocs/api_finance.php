<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib_drive.php';
require __DIR__ . '/lib_kdrive.php';
require_login();

$action = $_GET['action'] ?? '';
$financienRollen = ['admin', 'treasurer'];
// Loss/shrinkage may also be reported by whoever manages stock — they see
// damage/mis-purchases/lost shipments first, and this is an improvement
// over today, where stock could already be adjusted without any trace at all.
$dervingRollen = ['admin', 'treasurer', 'secretary', 'coordinator_distribution'];
// Verifying transactions is deliberately a Treasurer-specific task
// (separation of duties) — the Secretary may still edit transactions, but
// not tick them off as "verified".
$verificatieRollen = ['admin', 'treasurer'];

// bijlage_download sets its own Content-Type (PDF/image), so it
// deliberately skips the standard JSON header.
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

// Processes one of the three lists of a joint purchase (contributions,
// purchases, or additional costs) into accounting transactions + line
// records. $transactieType: 'inkomst' (contributions) or 'uitgave'
// (purchases/costs).
function verwerk_reroute_geldstroom($regelsIn, $transactieType, $omschrijvingPrefix, $titel, $nummer, $rerouteId, $createdBy, $datum) {
    $regelsUit = [];
    $totaal = 0;
    foreach ($regelsIn as $r) {
        $naam = trim($r['naam'] ?? '');
        $bedrag = round((float)($r['bedrag'] ?? 0), 2);
        if ($naam === '' || $bedrag <= 0) continue;

        $bijlagen = normaliseer_bijlagen($r['bijlagen'] ?? null);
        $gefactureerdDoor = trim($r['gefactureerdDoor'] ?? '');
        // "Own contribution": this district is also chipping in from its
        // own budget — not a real income from outside, so DO NOT create a
        // booking (that would artificially inflate the balance). Still
        // counts normally in the chart and totals of this joint purchase.
        $eigenInleg = !empty($r['eigenInleg']);
        // "Advance": someone has paid this out of their own pocket and
        // still needs to be reimbursed — instead of immediately booking a
        // completed expense (which would pretend it's already been paid
        // back), we create a reimbursement request for that person. Only
        // once it's actually paid out does the real booking happen.
        $voorschotAanvragerId = trim($r['voorschotAanvragerId'] ?? '');
        $bestaandeAanvraagId = trim($r['bestaandeGekoppeldeAanvraagId'] ?? '');

        $tId = null;
        $gekoppeldeAanvraagId = null;
        $aanvraagNummer = null;
        $omschrijving = $omschrijvingPrefix . ' ' . $naam . ($gefactureerdDoor !== '' ? (' (invoiced/advanced by ' . $gefactureerdDoor . ')') : '') . ' — ' . $titel . ' (' . $nummer . ')';

        if ($bestaandeAanvraagId !== '' && file_exists(fin_aanvraag_path($bestaandeAanvraagId))) {
            // When editing: this line was already linked to a
            // reimbursement request — that stays as-is, never recreate it.
            $bestaandeAanvraag = read_json(fin_aanvraag_path($bestaandeAanvraagId), null);
            $gekoppeldeAanvraagId = $bestaandeAanvraagId;
            $aanvraagNummer = $bestaandeAanvraag['nummer'] ?? null;
        } elseif ($voorschotAanvragerId !== '') {
            $declaratie = maak_declaratie_voor_voorschot($voorschotAanvragerId, $bedrag, $omschrijving, $rerouteId, $datum);
            $gekoppeldeAanvraagId = $declaratie['id'];
            $aanvraagNummer = $declaratie['nummer'];
        } elseif (!$eigenInleg) {
            $tId = uuid();
            write_json(transactie_path($tId), [
                'id' => $tId, 'type' => $transactieType, 'bedrag' => $bedrag, 'datum' => $datum,
                'omschrijving' => $omschrijving,
                'categorie' => 'joint purchase', 'bijlagen' => $bijlagen,
                'gekoppeldeAanvraagId' => null, 'gekoppeldePostId' => null, 'gekoppeldeRerouteId' => $rerouteId,
                'geverifieerd' => false, 'geverifieerdDoor' => null, 'geverifieerdOp' => null,
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

// Removes all bookings belonging to a joint purchase — used before
// rebuilding on edit, and when deleting entirely.
function verwijder_reroute_transacties($rerouteId) {
    foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $tPad) {
        $t = read_json($tPad, null);
        if ($t && ($t['gekoppeldeRerouteId'] ?? null) === $rerouteId) unlink($tPad);
    }
}
function lokaal_bijlagen_map() { return DATA_DIR . '/financien/bijlagen'; }

// Builds a CSV line correctly (commas/quotes/line breaks in fields are
// escaped properly, the way Excel/Google Sheets expects).
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
    $csv = csv_regel(['date', 'type', 'amount', 'category', 'description', 'attachments', 'created_by']);
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
    $csv = csv_regel(['date', 'type', 'requester', 'amount', 'description', 'supplier', 'status', 'attachments']);
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
    $csv = csv_regel(['number', 'kind', 'contact', 'lines', 'total_amount', 'outstanding', 'status', 'attachments']);
    foreach ($rijen as $p) {
        $regelsTekst = implode(' | ', array_map(fn($r) => $r['omschrijving'] . ' (' . number_format($r['bedrag'], 2, '.', '') . ')', $p['regels']));
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
    $redenLabel = ['miskoop' => 'Mis-purchase', 'verloren_zending' => 'Lost shipment', 'schade' => 'Damage', 'overig' => 'Other'];
    $csv = csv_regel(['date', 'item', 'quantity', 'reason', 'loss', 'note', 'attachments']);
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

// Marks every locally stored attachment ('pad') in one JSON file as
// archived (the filename stays known for record-keeping, but the file
// itself will soon be gone — only still in the downloaded backup zip).
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

// Normalises incoming attachments into a clean array of
// {bestandsnaam, ...} — multiple files per transaction/request/entry,
// whether stored locally ('pad') or on Drive ('driveFileId').
function normaliseer_bijlagen($waarde) {
    if (!is_array($waarde)) return [];
    return array_values(array_filter($waarde, fn($b) => is_array($b) && (!empty($b['pad']) || !empty($b['driveFileId']) || !empty($b['kdrivePad']))));
}

// Translates a chosen reference (type + ID) from the transaction form
// into the right linked-ID fields — a transaction can be linked to
// exactly 1 of the 3 kinds (or none).
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

// Automatically creates a reimbursement request for someone who advanced
// a cost (e.g. during a joint purchase) — instead of immediately booking
// a completed expense. Then simply follows the normal reimbursement flow
// (review -> payment details -> paid out), and only ONCE PAID does the
// real booking happen. Returns the created request.
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

    notify_role('treasurer', 'New advance to reimburse — ' . $aanvragerNaam,
        $aanvragerNaam . ' advanced ' . number_format($bedrag, 2) . ': ' . $omschrijving,
        'finance.php?tab=aanvragen');
    if ($aanvragerId) notify([$aanvragerId], 'Advance registered as a reimbursement request', 'Your advanced amount (' . number_format($bedrag, 2) . ') has automatically been submitted as reimbursement request ' . $aanvraag['nummer'] . ' and is awaiting approval.', 'finance.php?tab=aanvragen');

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

// Looks up a stock item by name (case-insensitive) and returns the row +
// index, so both Finance and Print share the same stock list.
function vind_voorraad_item(&$voorraad, $naam) {
    foreach ($voorraad as $i => $item) {
        if (strcasecmp($item['naam'], $naam) === 0) return $i;
    }
    return null;
}

// Processes a set of lines (stock links + free-text lines) for a
// resale/on-account entry: adjusts stock ($voorraad is modified by
// reference) and builds the cleaned-up lines + total amount. Used by both
// post_create and post_update, so the logic only needs to be correct in
// one place. Each stock-linked line deducts stock (based on the existing
// value-per-unit), each free-text line (e.g. shipping costs) simply counts
// towards the total on its own.
function verwerk_post_regels($regelsIn, &$voorraad) {
    $regelsUit = [];
    $totaal = 0;

    foreach ($regelsIn as $r) {
        $koppeling = $r['voorraadKoppeling'] ?? null;

        if ($koppeling) {
            $naam = trim($koppeling['itemNaam'] ?? '');
            $aantal = (int)($koppeling['aantal'] ?? 0);
            $idx = vind_voorraad_item($voorraad, $naam);
            if ($idx === null || $aantal <= 0) respond(['error' => 'Unknown stock item or invalid quantity: ' . $naam], 400);

            // Default: the current value-per-unit from the stock list. Can
            // deliberately be overridden (e.g. discount, agreed rounded
            // price) via 'aangepastePrijsPerStuk' — explicitly chosen by
            // the user, not just taken over silently.
            $waardePerStuk = $voorraad[$idx]['waardePerStuk'] ?? 0;
            if (isset($koppeling['aangepastePrijsPerStuk']) && $koppeling['aangepastePrijsPerStuk'] !== '' && $koppeling['aangepastePrijsPerStuk'] !== null) {
                $waardePerStuk = (float)$koppeling['aangepastePrijsPerStuk'];
            }

            if ($aantal > $voorraad[$idx]['huidigeVoorraad']) {
                respond(['error' => 'Not enough stock of "' . $naam . '" (' . $voorraad[$idx]['huidigeVoorraad'] . ' remaining).'], 400);
            }
            $voorraad[$idx]['huidigeVoorraad'] -= $aantal;
            $voorraad[$idx]['updatedAt'] = time() * 1000;

            $bedrag = round($aantal * $waardePerStuk, 2);
            $regelsUit[] = ['omschrijving' => $aantal . 'x ' . $naam . ' at ' . number_format($waardePerStuk, 2) . ' each', 'bedrag' => $bedrag, 'voorraadKoppeling' => ['itemNaam' => $naam, 'aantal' => $aantal, 'waardePerStuk' => $waardePerStuk]];
            $totaal += $bedrag;

        } else {
            $omschrijving = trim($r['omschrijving'] ?? '');
            $bedrag = round((float)($r['bedrag'] ?? 0), 2);
            if ($omschrijving === '' || $bedrag <= 0) continue;

            // "Handled privately": this cost (usually shipping) was
            // settled outside the club treasury — someone pays and
            // collects it themselves directly, without it going through
            // the treasurer. So do NOT book an expense, AND don't count it
            // towards the amount the district still needs to collect from
            // the debtor.
            $prive = !empty($r['prive']);
            $regelsUit[] = ['omschrijving' => $omschrijving, 'bedrag' => $bedrag, 'voorraadKoppeling' => null, 'prive' => $prive];
            if (!$prive) $totaal += $bedrag;
        }
    }

    return [$regelsUit, round($totaal, 2)];
}

// Reverses the stock mutation of a set of ALREADY-APPLIED lines — needed
// before editing an outstanding (not yet settled) entry, so we don't
// double-count.
function draai_post_regels_terug($regels, &$voorraad) {
    foreach ($regels as $r) {
        if (empty($r['voorraadKoppeling'])) continue;
        $naam = $r['voorraadKoppeling']['itemNaam'];
        $aantal = $r['voorraadKoppeling']['aantal'];
        $idx = vind_voorraad_item($voorraad, $naam);
        if ($idx === null) continue;
        $voorraad[$idx]['huidigeVoorraad'] += $aantal; // was deducted at the time of resale, so add it back
        $voorraad[$idx]['updatedAt'] = time() * 1000;
    }
}

switch ($action) {

    // ================= ATTACHMENTS (receipts / invoices) =================

    case 'bijlage_upload': {
        if (empty($_FILES['bestand'])) respond(['error' => 'No file received.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload failed.'], 400);
        if ($file['size'] > 8 * 1024 * 1024) respond(['error' => 'File must be no larger than 8MB.'], 400);

        $toegestaan = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $file['type'];
        if (!isset($toegestaan[$mime])) respond(['error' => 'Only PDF, JPG, or PNG are allowed.'], 400);

        $origineleNaam = basename($file['name']);

        // Storage method is an explicit per-district choice
        // (STORAGE_BACKEND in district.php), not a silent fallback order —
        // this way each district can choose for itself, and it's easy to
        // extend later with new methods (just an extra 'case' below + a
        // lib_xxx.php). If the chosen method fails, it ALWAYS falls back
        // to local — an upload should never fail outright.
        $backend = defined('STORAGE_BACKEND') ? STORAGE_BACKEND : 'local';
        switch ($backend) {
            case 'kdrive':
                if (kdrive_available()) {
                    $resultaat = kdrive_upload_file($file['tmp_name'], $origineleNaam, $mime);
                    if ($resultaat) respond(['bestandsnaam' => $origineleNaam, 'opslag' => 'kdrive', 'kdrivePad' => $resultaat['pad'], 'mimeType' => $mime]);
                }
                break;
            case 'drive':
                if (drive_available()) {
                    $resultaat = drive_upload_file($file['tmp_name'], $origineleNaam, $mime);
                    if ($resultaat) respond(['bestandsnaam' => $origineleNaam, 'opslag' => 'drive', 'driveFileId' => $resultaat['id'], 'mimeType' => $mime]);
                }
                break;
            // Adding a new storage method? Add a case here, build a
            // lib_xxx.php with the same 3 functions (available/upload/
            // stream), and add the matching settings in district.php. The
            // rest of the application doesn't need to know which methods exist.
        }

        $veiligeNaam = veilige_token() . '.' . $toegestaan[$mime];
        $doelPad = DATA_DIR . '/financien/bijlagen/' . $veiligeNaam;

        // Check the limit BEFORE saving locally — never let the hosting
        // fill up. Only applies to the actual local fallback, not
        // kdrive/drive (which have their own storage space).
        $limiet = defined('LOCAL_ATTACHMENTS_LIMIT_MB') ? (float)LOCAL_ATTACHMENTS_LIMIT_MB : 0;
        if ($limiet > 0) {
            $huidigeMB = lokaal_bijlagen_grootte_mb();
            $nieuwMB = $file['size'] / 1024 / 1024;
            if ($huidigeMB + $nieuwMB > $limiet) {
                respond([
                    'error' => 'Local storage limit almost reached (' . round($huidigeMB) . '/' . $limiet . ' MB). ' .
                        'Ask a Treasurer/Secretary to first download a backup and clean up old attachments (Finance → Bookkeeping → Storage management) before uploading again.',
                ], 507);
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $doelPad)) respond(['error' => 'Saving failed.'], 500);

        respond(['bestandsnaam' => $origineleNaam, 'opslag' => 'local', 'pad' => $veiligeNaam, 'mimeType' => $mime]);
        break;
    }

    case 'export_transacties_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-transactions-' . date('Y-m-d') . '.csv"');
        echo csv_transacties();
        exit;
    }

    case 'export_aanvragen_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-reimbursement-requests-' . date('Y-m-d') . '.csv"');
        echo csv_aanvragen();
        exit;
    }

    case 'export_posten_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-debtors-creditors-' . date('Y-m-d') . '.csv"');
        echo csv_posten();
        exit;
    }

    case 'export_derving_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-shrinkage-' . date('Y-m-d') . '.csv"');
        echo csv_derving();
        exit;
    }

    case 'gebruikers_lijst': {
        // Lightweight list (id + name only), for pickers like "who
        // advanced this". No sensitive fields like password hashes.
        $users = read_json(DATA_DIR . '/users.json', []);
        $lijst = array_map(fn($u) => ['id' => $u['id'], 'naam' => $u['name'] ?? $u['id']], $users);
        respond(['gebruikers' => $lijst]);
        break;
    }

    case 'opslag_status': {
        require_role($financienRollen);
        $limiet = defined('LOCAL_ATTACHMENTS_LIMIT_MB') ? (float)LOCAL_ATTACHMENTS_LIMIT_MB : 0;
        respond(['gebruiktMB' => round(lokaal_bijlagen_grootte_mb(), 1), 'limietMB' => $limiet]);
        break;
    }

    case 'bijlagen_backup_downloaden': {
        require_role($financienRollen);
        if (!class_exists('ZipArchive')) respond(['error' => 'ZIP support is missing on this server.'], 500);

        $overzicht = ['gemaaktOp' => date('Y-m-d H:i:s'), 'bestanden' => []];
        foreach (['transacties', 'aanvragen', 'posten', 'derving'] as $soort) {
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
        $zip->addFromString('overview.json', json_encode($overzicht, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString('transactions.csv', csv_transacties());
        $zip->addFromString('reimbursement-requests.csv', csv_aanvragen());
        $zip->addFromString('debtors-creditors.csv', csv_posten());
        $zip->addFromString('shrinkage.csv', csv_derving());
        foreach (glob(lokaal_bijlagen_map() . '/*') as $bestand) {
            if (is_file($bestand)) $zip->addFile($bestand, 'attachments/' . basename($bestand));
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . DISTRICT_SLUG . '-finance-attachments-backup-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tijdelijkZip));
        readfile($tijdelijkZip);
        unlink($tijdelijkZip);
        exit;
    }

    case 'bijlagen_wissen_na_backup': {
        require_role($financienRollen);

        // First marks every attachment reference as archived everywhere
        // (filename stays visible in the records, only the physical file
        // disappears), and only then deletes the files.
        foreach (['transacties', 'aanvragen', 'posten', 'derving'] as $soort) {
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

    case 'bijlage_download': {
        $kdrivePad = $_GET['kdrivePad'] ?? '';
        if ($kdrivePad !== '') {
            header('Content-Type: ' . ($_GET['mime'] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="attachment"');
            kdrive_stream_file($kdrivePad); // ends the request itself
        }

        $driveFileId = $_GET['driveFileId'] ?? '';
        if ($driveFileId !== '') {
            header('Content-Type: ' . ($_GET['mime'] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="attachment"');
            drive_stream_file($driveFileId); // ends the request itself
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

    // ================= BOOKKEEPING (transactions) =================
    // Visible to everyone (transparency about the balance), but only
    // editable by Treasurer, Secretary, or admin.

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
                $cat = $t['categorie'] ?: 'other';
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
            respond(['error' => 'Amount and description are required.'], 400);
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
        if (!$transactie) respond(['error' => 'Transaction not found.'], 404);
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
        if (!$transactie) respond(['error' => 'Transaction not found.'], 404);

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

    // ================= REIMBURSEMENT & ORDER REQUESTS =================
    // Two variants of the same process: 'declaratie' (reimbursement —
    // anyone may submit, paid back afterwards) and 'bestelling_aanvraag'
    // (order request — approval in advance to order somewhere — intended
    // for the PI Chairperson).

    case 'aanvraag_create': {
        $in = json_input();
        $type = ($in['type'] ?? '') === 'bestelling_aanvraag' ? 'bestelling_aanvraag' : 'declaratie';
        if ($type === 'bestelling_aanvraag' && !heeft_rol(['chair', 'admin'])) {
            respond(['error' => 'Only the PI Chairperson (or admin) can submit an order request.'], 403);
        }
        if (!is_numeric($in['bedrag'] ?? null) || trim($in['omschrijving'] ?? '') === '') {
            respond(['error' => 'Amount and description are required.'], 400);
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

        $titel = $type === 'declaratie' ? 'New reimbursement request' : 'New order request';
        notify_role(
            'treasurer',
            $titel . ' — ' . $aanvraag['aanvragerNaam'],
            $aanvraag['aanvragerNaam'] . ' is submitting a ' . ($type === 'declaratie' ? 'reimbursement request' : 'order request') . ' for ' . number_format($aanvraag['bedrag'], 2) .
                "\nDescription: " . $aanvraag['omschrijving'] .
                ($aanvraag['leverancier'] ? ("\nSupplier: " . $aanvraag['leverancier']) : ''),
            'finance.php?tab=aanvragen'
        );
        // The Secretary has the same authority, so notify them too.
        notify_role('secretary', $titel . ' — ' . $aanvraag['aanvragerNaam'], 'See the Finance tab in the portal.', 'finance.php?tab=aanvragen');

        respond(['aanvraag' => $aanvraag], 201);
        break;
    }

    case 'aanvraag_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/financien/aanvragen/*.json') as $file) {
            $a = read_json($file, null);
            if (!$a) continue;
            // Did this request already exist before numbering was added?
            // Give it a number retroactively, just once, so old data
            // doesn't stay without a reference.
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
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);
        $in = json_input();
        $besluit = $in['besluit'] ?? '';
        if (!in_array($besluit, ['goedgekeurd', 'afgewezen'], true)) respond(['error' => 'Invalid decision.'], 400);

        $aanvraag['status'] = $besluit;
        $aanvraag['behandeldOp'] = time() * 1000;
        $aanvraag['behandeldDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $aanvraag['opmerkingPenningmeester'] = $in['opmerking'] ?? '';
        write_json(fin_aanvraag_path($id), $aanvraag);

        $tekst = $besluit === 'goedgekeurd'
            ? 'Your ' . ($aanvraag['type'] === 'declaratie' ? 'reimbursement request' : 'order request') . ' for ' . number_format($aanvraag['bedrag'], 2) . ' has been approved. Send your payment details/bank account via the portal, and the treasurer will transfer it.'
            : 'Your ' . ($aanvraag['type'] === 'declaratie' ? 'reimbursement request' : 'order request') . ' has been rejected.' . ($aanvraag['opmerkingPenningmeester'] ? ("\nNote: " . $aanvraag['opmerkingPenningmeester']) : '');
        notify([$aanvraag['aanvragerId']], 'Financial request ' . ($besluit === 'goedgekeurd' ? 'approved' : 'rejected'), $tekst, 'finance.php?tab=aanvragen');

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_betaalgegevens_indienen': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(fin_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);
        if ($aanvraag['aanvragerId'] !== $_SESSION['user_id']) respond(['error' => 'You are not permitted to do this.'], 403);
        if ($aanvraag['status'] !== 'goedgekeurd') respond(['error' => 'This request has not been approved yet.'], 400);

        $in = json_input();
        $aanvraag['betaalgegevens'] = [
            'iban' => trim($in['iban'] ?? ''),
            'tenaamstelling' => trim($in['tenaamstelling'] ?? ''),
            'opmerking' => trim($in['opmerking'] ?? ''),
            'ingediendOp' => time() * 1000,
        ];
        write_json(fin_aanvraag_path($id), $aanvraag);

        notify_role(
            'treasurer',
            'Payment details received — ' . $aanvraag['aanvragerNaam'],
            $aanvraag['aanvragerNaam'] . ' has submitted payment details for the approved request of ' . number_format($aanvraag['bedrag'], 2) . '.',
            'finance.php?tab=aanvragen'
        );

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_uitbetaald': {
        require_role($financienRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(fin_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);
        if ($aanvraag['status'] !== 'goedgekeurd') respond(['error' => 'Only approved requests can be marked as paid.'], 400);

        $aanvraag['status'] = 'uitbetaald';
        $aanvraag['uitbetaaldOp'] = time() * 1000;
        write_json(fin_aanvraag_path($id), $aanvraag);

        // Automatically create an expense transaction, including the receipt.
        $transactieId = uuid();
        write_json(transactie_path($transactieId), [
            'id' => $transactieId,
            'type' => 'uitgave',
            'bedrag' => $aanvraag['bedrag'],
            'datum' => $aanvraag['datum'] ?? date('Y-m-d'),
            'omschrijving' => ($aanvraag['type'] === 'declaratie' ? 'Reimbursement — ' : 'Order — ') . $aanvraag['aanvragerNaam'] . ': ' . $aanvraag['omschrijving'],
            'categorie' => $aanvraag['type'] === 'declaratie' ? ($aanvraag['gekoppeldeRerouteId'] ? 'joint purchase' : 'reimbursements') : 'orders',
            'bijlagen' => $aanvraag['bijlagen'] ?? [],
            'gekoppeldeAanvraagId' => $id,
            'gekoppeldeRerouteId' => $aanvraag['gekoppeldeRerouteId'] ?? null,
            'geverifieerd' => false,
            'geverifieerdDoor' => null,
            'geverifieerdOp' => null,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ]);

        notify([$aanvraag['aanvragerId']], 'Paid', 'Your request for ' . number_format($aanvraag['bedrag'], 2) . ' has been paid.', 'finance.php?tab=aanvragen');

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_update': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(fin_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);

        $magBewerken = $aanvraag['aanvragerId'] === $_SESSION['user_id'] || heeft_rol($financienRollen);
        if (!$magBewerken) respond(['error' => 'You are not permitted to do this.'], 403);
        if ($aanvraag['status'] !== 'open') respond(['error' => 'This request has already been reviewed and can no longer be changed.'], 400);

        $in = json_input();
        if (!is_numeric($in['bedrag'] ?? null) || trim($in['omschrijving'] ?? '') === '') {
            respond(['error' => 'Amount and description are required.'], 400);
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
        // For migrating old, already-settled reimbursements/expenses
        // (e.g. from the previous Google Sheet) — comes in as 'uitbetaald'.
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
                'aanvragerNaam' => trim($rij['aanvragerNaam'] ?? 'Unknown'),
                'bedrag' => round((float)$bedrag, 2),
                'omschrijving' => trim($rij['omschrijving']),
                'leverancier' => trim($rij['leverancier'] ?? ''),
                'bijlagen' => [],
                'status' => 'uitbetaald',
                'opmerkingPenningmeester' => 'Imported from previous records.',
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
                'categorie' => 'reimbursements (import)',
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

    // ================= DEBTORS / CREDITORS =================

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
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'Name is required.'], 400);
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

    // ================= ADVANCES & ON-ACCOUNT PURCHASES =================

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
        require_login(); // open to all members — anyone can submit, only settling (post_afhandelen) stays finance-only
        $in = json_input();
        $relatieId = $in['relatieId'] ?? '';
        $relatie = read_json(relatie_path($relatieId), null);
        if (!$relatie) respond(['error' => 'Choose a valid debtor.'], 400);

        $regelsIn = is_array($in['regels'] ?? null) ? $in['regels'] : [];
        if (empty($regelsIn)) respond(['error' => 'Add at least 1 line.'], 400);

        $voorraad = read_json(drukwerk_voorraad_path(), []);
        [$regelsUit, $totaal] = verwerk_post_regels($regelsIn, $voorraad);

        if (empty($regelsUit)) respond(['error' => 'No valid lines found.'], 400);
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

        // The side WE carry directly (e.g. shipping costs, lines with no
        // stock link) we book NOW as a real expense. The stock-value lines
        // are not a cash flow, so those are NOT booked — you see them
        // reflected in the reduced stock value itself instead.
        foreach ($regelsUit as $r) {
            if ($r['voorraadKoppeling'] === null && empty($r['prive'])) {
                $tId = uuid();
                write_json(transactie_path($tId), [
                    'id' => $tId, 'type' => 'uitgave', 'bedrag' => $r['bedrag'], 'datum' => $datum,
                    'omschrijving' => $r['omschrijving'] . ' (advanced for ' . $relatie['naam'] . ', ' . $post['nummer'] . ')',
                    'categorie' => 'resales', 'bijlagen' => [], 'gekoppeldeAanvraagId' => null,
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
        if (!$post) respond(['error' => 'Entry not found.'], 404);
        // Editing is allowed by the original submitter, or by Finance —
        // same as reimbursements. Settling (approve/pay) stays finance-only.
        $magBewerken = ($post['createdBy'] ?? null) === ($_SESSION['user_name'] ?? current_user_slug()) || heeft_rol($financienRollen);
        if (!$magBewerken) respond(['error' => 'You are not permitted to do this.'], 403);
        if ($post['status'] !== 'open' || $post['afgehandeldBedrag'] > 0) {
            respond(['error' => 'This entry has already been (partially) settled and can no longer be edited.'], 400);
        }

        $in = json_input();
        $regelsIn = is_array($in['regels'] ?? null) ? $in['regels'] : [];
        if (empty($regelsIn)) respond(['error' => 'Add at least 1 line.'], 400);

        $voorraad = read_json(drukwerk_voorraad_path(), []);
        // First reverse the old lines, only then apply the new ones —
        // otherwise we'd double-count or deduct too much.
        draai_post_regels_terug($post['regels'], $voorraad);
        [$regelsUit, $totaal] = verwerk_post_regels($regelsIn, $voorraad);

        if (empty($regelsUit)) respond(['error' => 'No valid lines found.'], 400);
        write_json(drukwerk_voorraad_path(), $voorraad);

        // The old, automatically-booked "advanced" expenses for this entry
        // no longer apply once the lines change — remove them, and
        // recreate below if applicable.
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
                    'omschrijving' => $r['omschrijving'] . ' (advanced for ' . $post['relatieNaam'] . ', ' . $post['nummer'] . ')',
                    'categorie' => 'resales', 'bijlagen' => [], 'gekoppeldeAanvraagId' => null,
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
        if (!$post) respond(['error' => 'Entry not found.'], 404);
        $in = json_input();

        $regelIndexen = is_array($in['regelIndexen'] ?? null) ? array_map('intval', $in['regelIndexen']) : [];
        if (empty($regelIndexen)) respond(['error' => 'Choose at least 1 line to settle.'], 400);

        $bedrag = 0;
        $omschrijvingen = [];
        foreach ($regelIndexen as $i) {
            if (!isset($post['regels'][$i])) continue;
            $bedrag += $post['regels'][$i]['bedrag'];
            $omschrijvingen[] = $post['regels'][$i]['omschrijving'];
        }
        if ($bedrag <= 0) respond(['error' => 'No valid lines chosen.'], 400);

        $post['afgehandeldBedrag'] = round($post['afgehandeldBedrag'] + $bedrag, 2);
        $post['afhandelingen'][] = ['bedrag' => round($bedrag, 2), 'datum' => date('Y-m-d'), 'omschrijving' => implode(', ', $omschrijvingen), 'door' => $_SESSION['user_name'] ?? current_user_slug(), 'op' => time() * 1000];
        $post['status'] = $post['afgehandeldBedrag'] >= $post['totaalbedrag'] - 0.005 ? 'afgehandeld' : 'deels_afgehandeld';
        write_json(post_path($id), $post);

        // On a resale, money always comes IN (income) once the debtor pays.
        $tId = uuid();
        write_json(transactie_path($tId), [
            'id' => $tId,
            'type' => 'inkomst',
            'bedrag' => round($bedrag, 2),
            'datum' => date('Y-m-d'),
            'omschrijving' => implode(', ', $omschrijvingen) . ' — ' . $post['relatieNaam'] . ' (' . $post['nummer'] . ')',
            'categorie' => 'resales',
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
        if (!$post) respond(['deleted' => true]); // already gone, fine

        // Nothing settled yet -> cleanly reverse the stock mutation of the
        // lines, so deleting doesn't skew stock.
        if ($post['afgehandeldBedrag'] == 0) {
            $voorraad = read_json(drukwerk_voorraad_path(), []);
            draai_post_regels_terug($post['regels'], $voorraad);
            write_json(drukwerk_voorraad_path(), $voorraad);
        }

        // Linked automatic bookings (e.g. advanced shipping costs, or
        // earlier settlement transactions) shouldn't stay behind as orphans.
        foreach (glob(DATA_DIR . '/financien/transacties/*.json') as $tPad) {
            $t = read_json($tPad, null);
            if ($t && ($t['gekoppeldePostId'] ?? null) === $id) unlink($tPad);
        }

        unlink(post_path($id));
        respond(['deleted' => true]);
        break;
    }

    // ================= SHRINKAGE (mis-purchases, lost shipments, damage) =================

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
        if ($itemNaam === '' || $aantal <= 0) respond(['error' => 'Choose an item and a quantity greater than 0.'], 400);

        $voorraad = read_json(drukwerk_voorraad_path(), []);
        $idx = vind_voorraad_item($voorraad, $itemNaam);
        if ($idx === null) respond(['error' => 'Unknown stock item: ' . $itemNaam], 400);
        if ($aantal > $voorraad[$idx]['huidigeVoorraad']) {
            respond(['error' => 'Not enough stock of "' . $itemNaam . '" (' . $voorraad[$idx]['huidigeVoorraad'] . ' remaining).'], 400);
        }

        $waardePerStuk = $voorraad[$idx]['waardePerStuk'] ?? 0;
        $voorraad[$idx]['huidigeVoorraad'] -= $aantal;
        $voorraad[$idx]['updatedAt'] = time() * 1000;
        write_json(drukwerk_voorraad_path(), $voorraad);

        $redenLabel = ['miskoop' => 'Mis-purchase', 'verloren_zending' => 'Lost shipment', 'schade' => 'Damage', 'overig' => 'Other'][$reden];
        $verlies = round($aantal * $waardePerStuk, 2);
        $bijlagenD = normaliseer_bijlagen($in['bijlagen'] ?? null);

        // Automatically book an expense (category "shrinkage") so the loss
        // shows up in the balance, even though no money physically left —
        // it's still genuinely lost value.
        $tId = uuid();
        write_json(transactie_path($tId), [
            'id' => $tId, 'type' => 'uitgave', 'bedrag' => $verlies, 'datum' => date('Y-m-d'),
            'omschrijving' => $redenLabel . ': ' . $aantal . 'x ' . $itemNaam . (trim($in['toelichting'] ?? '') !== '' ? (' — ' . trim($in['toelichting'])) : ''),
            'categorie' => 'shrinkage', 'bijlagen' => $bijlagenD,
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

        notify_role('treasurer', 'Shrinkage recorded', $redenLabel . ': ' . $aantal . 'x ' . $itemNaam . ' (' . number_format($verlies, 2) . ') by ' . $derving['createdBy'] . '.', 'finance.php?tab=boekhouding');

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

    // ================= JOINT PURCHASES (multiple contributors -> multiple purchases) =================
    // "Follow the money": multiple districts/contributors chip in together
    // for a joint order, spread across multiple suppliers/purchases. Each
    // line automatically becomes its own booking in the main ledger, all
    // linked via the same GI number.

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
        if ($titel === '') respond(['error' => 'Title is required.'], 400);

        $bijdragenIn = is_array($in['bijdragen'] ?? null) ? $in['bijdragen'] : [];
        $aankopenIn = is_array($in['aankopen'] ?? null) ? $in['aankopen'] : [];
        $kostenIn = is_array($in['bijkomendeKosten'] ?? null) ? $in['bijkomendeKosten'] : [];
        $bijdragenGeldig = array_filter($bijdragenIn, fn($b) => trim($b['naam'] ?? '') !== '' && (float)($b['bedrag'] ?? 0) > 0);
        $aankopenGeldig = array_filter($aankopenIn, fn($a) => trim($a['naam'] ?? '') !== '' && (float)($a['bedrag'] ?? 0) > 0);
        if (empty($bijdragenGeldig) || empty($aankopenGeldig)) {
            respond(['error' => 'Add at least 1 contribution and at least 1 purchase.'], 400);
        }

        $nummer = genereer_reroutenummer();
        $id = uuid();
        $createdBy = $_SESSION['user_name'] ?? current_user_slug();
        $datum = trim($in['datum'] ?? '') ?: date('Y-m-d');

        [$bijdragenUit, $totaalBijdragen] = verwerk_reroute_geldstroom($bijdragenIn, 'inkomst', 'Contribution', $titel, $nummer, $id, $createdBy, $datum);
        [$aankopenUit, $totaalAankopen] = verwerk_reroute_geldstroom($aankopenIn, 'uitgave', 'Purchase', $titel, $nummer, $id, $createdBy, $datum);
        [$kostenUit, $totaalKosten] = verwerk_reroute_geldstroom($kostenIn, 'uitgave', 'Additional cost:', $titel, $nummer, $id, $createdBy, $datum);

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
        if (!$reroute) respond(['error' => 'Joint purchase not found.'], 404);

        $in = json_input();
        $titel = trim($in['titel'] ?? '');
        if ($titel === '') respond(['error' => 'Title is required.'], 400);

        $bijdragenIn = is_array($in['bijdragen'] ?? null) ? $in['bijdragen'] : [];
        $aankopenIn = is_array($in['aankopen'] ?? null) ? $in['aankopen'] : [];
        $kostenIn = is_array($in['bijkomendeKosten'] ?? null) ? $in['bijkomendeKosten'] : [];
        $bijdragenGeldig = array_filter($bijdragenIn, fn($b) => trim($b['naam'] ?? '') !== '' && (float)($b['bedrag'] ?? 0) > 0);
        $aankopenGeldig = array_filter($aankopenIn, fn($a) => trim($a['naam'] ?? '') !== '' && (float)($a['bedrag'] ?? 0) > 0);
        if (empty($bijdragenGeldig) || empty($aankopenGeldig)) {
            respond(['error' => 'Add at least 1 contribution and at least 1 purchase.'], 400);
        }

        // Remove every old linked booking, then rebuild everything fresh —
        // simpler and less error-prone than trying to match and update
        // individual lines.
        verwijder_reroute_transacties($id);
        $createdBy = $_SESSION['user_name'] ?? current_user_slug();
        $datum = trim($in['datum'] ?? '') ?: ($reroute['datum'] ?? date('Y-m-d'));

        [$bijdragenUit, $totaalBijdragen] = verwerk_reroute_geldstroom($bijdragenIn, 'inkomst', 'Contribution', $titel, $reroute['nummer'], $id, $createdBy, $datum);
        [$aankopenUit, $totaalAankopen] = verwerk_reroute_geldstroom($aankopenIn, 'uitgave', 'Purchase', $titel, $reroute['nummer'], $id, $createdBy, $datum);
        [$kostenUit, $totaalKosten] = verwerk_reroute_geldstroom($kostenIn, 'uitgave', 'Additional cost:', $titel, $reroute['nummer'], $id, $createdBy, $datum);

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

        // Every linked booking shouldn't stay behind as an orphan — this
        // whole joint purchase disappears entirely.
        verwijder_reroute_transacties($id);

        unlink(reroute_path($id));
        respond(['deleted' => true]);
        break;
    }

    default:
        respond(['error' => 'unknown action'], 400);
}

