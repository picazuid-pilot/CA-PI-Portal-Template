<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();
require_once __DIR__ . '/lib_opslag.php';

$action = $_GET['action'] ?? '';
$itRollen = ['admin']; // database files are managed by IT

// Edit rights depend on the item's category: the general archive (database
// files) is IT only; Minutes & Agendas may also be managed by the chairperson
// and the secretary.
function magDriveCategorieBewerken($categorie) {
    if ($categorie === 'notulen_agenda') return heeft_rol(['admin', 'chair', 'secretary']);
    return heeft_rol(['admin']);
}

// A member may rename/delete their OWN upload in a members' folder (never someone else's file).
function drive_is_eigen_lidbestand($item) {
    return !empty($item['ledenUpload']) && !is_demo_gebruiker()
        && ($item['uploaderId'] ?? '') !== '' && ($item['uploaderId'] ?? '') === ($_SESSION['user_id'] ?? '');
}
// What the browser may see: never the internal storage path.
function drive_item_publiek($i) {
    if (!empty($i['bestand'])) $i['bestand'] = ['naam' => $i['bestand']['naam'] ?? '', 'grootte' => (int)($i['bestand']['grootte'] ?? 0)];
    $i['eigen'] = drive_is_eigen_lidbestand($i);
    unset($i['uploaderId']);
    return $i;
}

function drive_item_path($id) {
    return DATA_DIR . '/drive_database/items/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json';
}

// ---- Subcategories (self-created folders/groups within a category) ----
// Each subcategory belongs to one 'scope' (algemeen or notulen_agenda), just
// like the files themselves. A file optionally has a 'subcategorieId'; without
// one (or with a deleted subcategory) it falls under "Unsorted".
function drive_subcat_path($id) {
    return DATA_DIR . '/drive_database/subcategorieen/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json';
}
function drive_scope($waarde) {
    return $waarde === 'notulen_agenda' ? 'notulen_agenda' : 'algemeen';
}
function drive_subcats_van_scope($scope) {
    $out = [];
    foreach (glob(DATA_DIR . '/drive_database/subcategorieen/*.json') as $file) {
        $c = read_json($file, null);
        if ($c && ($c['categorie'] ?? 'algemeen') === $scope) $out[] = $c;
    }
    usort($out, fn($a, $b) => ($a['volgorde'] ?? 0) <=> ($b['volgorde'] ?? 0) ?: strcasecmp($a['naam'], $b['naam']));
    return $out;
}
function drive_subcat_naam_schoon($naam) {
    $naam = trim(preg_replace('/\s+/u', ' ', (string)$naam));
    return mb_substr($naam, 0, 60);
}
// Returns the id if the subcategory exists in this scope, otherwise null (= unsorted).
function drive_geldige_subcat($id, $scope) {
    $id = (string)$id;
    if ($id === '') return null;
    $c = read_json(drive_subcat_path($id), null);
    return ($c && ($c['categorie'] ?? 'algemeen') === $scope) ? $c['id'] : null;
}
function drive_subcat_naam_bestaat($naam, $scope, $behalveId = null) {
    foreach (drive_subcats_van_scope($scope) as $c) {
        if ($c['id'] !== $behalveId && mb_strtolower($c['naam']) === mb_strtolower($naam)) return true;
    }
    return false;
}
// Download link: empty is fine, otherwise it must be an http(s) address (no javascript: links).
function drive_schone_url($waarde) {
    $url = trim((string)$waarde);
    if ($url === '') return '';
    $schoon = veilige_url($url);
    if ($schoon === '') respond(['error' => 'The download link must be a valid http(s) address (start with https://).'], 400);
    return $schoon;
}

switch ($action) {

    case 'item_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/drive_database/items/*.json') as $file) {
            $i = read_json($file, null);
            if ($i) $out[] = drive_item_publiek($i);
        }
        usort($out, fn($a, $b) => ($a['volgorde'] ?? 0) <=> ($b['volgorde'] ?? 0) ?: strcmp($a['titel'], $b['titel']));
        respond(['items' => $out, 'limieten' => ['maxMb' => leden_upload_max_mb(), 'perLidMb' => leden_upload_per_lid_mb(), 'gebruiktMb' => round(leden_opslag_gebruikt_mb($_SESSION['user_id'] ?? ''), 1), 'extensies' => array_keys(leden_toegestane_typen())]]);
        break;
    }

    case 'item_create': {
        $in = json_input();
        $categorie = ($in['categorie'] ?? '') === 'notulen_agenda' ? 'notulen_agenda' : 'algemeen';
        if (!magDriveCategorieBewerken($categorie)) respond(['error' => 'You are not permitted to edit this category.'], 403);
        $titel = trim($in['titel'] ?? '');
        if ($titel === '') respond(['error' => 'Title is required.'], 400);

        $id = uuid();
        $item = [
            'id' => $id,
            'titel' => $titel,
            'categorie' => $categorie,
            'omschrijving' => trim($in['omschrijving'] ?? ''),
            'voorbeeldType' => 'geen',   // 'afbeelding' | 'video' | 'geen' — set on thumbnail upload
            'voorbeeldPad' => null,
            'downloadUrl' => drive_schone_url($in['downloadUrl'] ?? ''),
            'subcategorieId' => drive_geldige_subcat($in['subcategorieId'] ?? '', $categorie),
            'volgorde' => (int)($in['volgorde'] ?? 0),
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(drive_item_path($id), $item);
        respond(['item' => drive_item_publiek($item)], 201);
        break;
    }

    case 'items_herordenen': {
        $in = json_input();
        $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
        foreach ($ids as $volgorde => $id) {
            $item = read_json(drive_item_path($id), null);
            if (!$item) continue;
            if (!magDriveCategorieBewerken($item['categorie'] ?? 'algemeen')) respond(['error' => 'You are not permitted to do this.'], 403);
            $item['volgorde'] = $volgorde;
            write_json(drive_item_path($id), $item);
        }
        respond(['ok' => true]);
        break;
    }

    // ================= SUBCATEGORIES =================

    case 'subcat_list': {
        respond(['subcategorieen' => drive_subcats_van_scope(drive_scope($_GET['categorie'] ?? ''))]);
        break;
    }

    case 'subcat_create': {
        $in = json_input();
        $scope = drive_scope($in['categorie'] ?? '');
        if (!magDriveCategorieBewerken($scope)) respond(['error' => 'You are not permitted to edit this category.'], 403);
        $naam = drive_subcat_naam_schoon($in['naam'] ?? '');
        if ($naam === '') respond(['error' => 'Give the subcategory a name.'], 400);
        if (drive_subcat_naam_bestaat($naam, $scope)) respond(['error' => 'A subcategory with this name already exists.'], 409);
        $bestaand = drive_subcats_van_scope($scope);
        $volgorde = $bestaand ? max(array_map(fn($c) => (int)($c['volgorde'] ?? 0), $bestaand)) + 1 : 0;
        $id = uuid();
        $c = ['id' => $id, 'naam' => $naam, 'categorie' => $scope, 'ledenUpload' => $scope === 'algemeen' && !empty($in['ledenUpload']), 'volgorde' => $volgorde, 'createdBy' => $_SESSION['user_name'] ?? current_user_slug(), 'createdAt' => time() * 1000];
        write_json(drive_subcat_path($id), $c);
        respond(['subcategorie' => $c], 201);
        break;
    }

    case 'subcat_update': {
        $id = $_GET['id'] ?? '';
        $c = read_json(drive_subcat_path($id), null);
        if (!$c) respond(['error' => 'Subcategory not found.'], 404);
        $scope = $c['categorie'] ?? 'algemeen';
        if (!magDriveCategorieBewerken($scope)) respond(['error' => 'You are not permitted to do this.'], 403);
        $in = json_input();
        $naam = drive_subcat_naam_schoon($in['naam'] ?? '');
        if ($naam === '') respond(['error' => 'Give the subcategory a name.'], 400);
        if (drive_subcat_naam_bestaat($naam, $scope, $c['id'])) respond(['error' => 'A subcategory with this name already exists.'], 409);
        $c['naam'] = $naam;
        if (array_key_exists('ledenUpload', $in)) $c['ledenUpload'] = $scope === 'algemeen' && !empty($in['ledenUpload']);
        write_json(drive_subcat_path($id), $c);
        respond(['subcategorie' => $c]);
        break;
    }

    // Only deletes the group: the files in it are kept and end up under
    // "Unsorted".
    case 'subcat_delete': {
        $id = $_GET['id'] ?? '';
        $c = read_json(drive_subcat_path($id), null);
        if (!$c) respond(['deleted' => true]);
        if (!magDriveCategorieBewerken($c['categorie'] ?? 'algemeen')) respond(['error' => 'You are not permitted to do this.'], 403);
        foreach (glob(DATA_DIR . '/drive_database/items/*.json') as $file) {
            $i = read_json($file, null);
            if ($i && ($i['subcategorieId'] ?? null) === $c['id']) {
                $i['subcategorieId'] = null;
                write_json($file, $i);
            }
        }
        unlink(drive_subcat_path($id));
        respond(['deleted' => true]);
        break;
    }

    case 'subcats_herordenen': {
        $in = json_input();
        $scope = drive_scope($in['categorie'] ?? '');
        if (!magDriveCategorieBewerken($scope)) respond(['error' => 'You are not permitted to do this.'], 403);
        $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
        foreach ($ids as $volgorde => $id) {
            $c = read_json(drive_subcat_path($id), null);
            if (!$c || ($c['categorie'] ?? 'algemeen') !== $scope) continue;
            $c['volgorde'] = (int)$volgorde;
            write_json(drive_subcat_path($id), $c);
        }
        respond(['ok' => true]);
        break;
    }

    // Moves a file to a subcategory ('' = Unsorted) and stores the order of that
    // whole group (ids = files in the new order).
    case 'item_verplaatsen': {
        $id = $_GET['id'] ?? '';
        $item = read_json(drive_item_path($id), null);
        if (!$item) respond(['error' => 'Not found.'], 404);
        $scope = $item['categorie'] ?? 'algemeen';
        if (!magDriveCategorieBewerken($scope)) respond(['error' => 'You are not permitted to do this.'], 403);
        $in = json_input();
        $doel = drive_geldige_subcat($in['subcategorieId'] ?? '', $scope);
        $item['subcategorieId'] = $doel;
        write_json(drive_item_path($id), $item);

        $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
        foreach ($ids as $volgorde => $andereId) {
            $x = $andereId === $id ? $item : read_json(drive_item_path($andereId), null);
            if (!$x || ($x['categorie'] ?? 'algemeen') !== $scope) continue;
            if (($x['subcategorieId'] ?? null) !== $doel) continue; // only reorder files from THIS group
            $x['volgorde'] = (int)$volgorde;
            write_json(drive_item_path($x['id']), $x);
            if ($x['id'] === $id) $item = $x;
        }
        respond(['item' => drive_item_publiek($item)]);
        break;
    }

    case 'item_update': {
        $id = $_GET['id'] ?? '';
        $item = read_json(drive_item_path($id), null);
        if (!$item) respond(['error' => 'Not found.'], 404);
        $in = json_input();
        if (!magDriveCategorieBewerken($item['categorie'] ?? 'algemeen')) {
            // Own member upload: only title and description may be changed.
            if (!drive_is_eigen_lidbestand($item)) respond(['error' => 'You are not permitted to do this.'], 403);
            $in = array_intersect_key($in, ['titel' => 1, 'omschrijving' => 1]);
        }
        if (isset($in['titel'])) {
            $titel = trim($in['titel']);
            if ($titel === '') respond(['error' => 'Title is required.'], 400);
            $item['titel'] = $titel;
        }
        if (isset($in['omschrijving'])) $item['omschrijving'] = trim($in['omschrijving']);
        if (isset($in['downloadUrl'])) $item['downloadUrl'] = drive_schone_url($in['downloadUrl']);
        if (array_key_exists('subcategorieId', $in)) $item['subcategorieId'] = drive_geldige_subcat($in['subcategorieId'], $item['categorie'] ?? 'algemeen');
        if (isset($in['volgorde'])) $item['volgorde'] = (int)$in['volgorde'];
        write_json(drive_item_path($id), $item);
        respond(['item' => drive_item_publiek($item)]);
        break;
    }

    case 'item_delete': {
        $id = $_GET['id'] ?? '';
        $item = read_json(drive_item_path($id), null);
        if ($item && !magDriveCategorieBewerken($item['categorie'] ?? 'algemeen') && !drive_is_eigen_lidbestand($item)) respond(['error' => 'You are not permitted to do this.'], 403);
        if ($item && !empty($item['bestand'])) leden_opslag_verwijder($item['bestand']); // also remove the actual file
        if ($item && !empty($item['voorbeeldPad'])) {
            $pad = DATA_DIR . '/drive_database/thumbnails/' . $item['voorbeeldPad'];
            if (file_exists($pad)) unlink($pad);
        }
        if (file_exists(drive_item_path($id))) unlink(drive_item_path($id));
        respond(['deleted' => true]);
        break;
    }

    // Upload/replace a thumbnail (image or video) for an item — IT/authorised
    // roles only, since this determines what everyone sees as the preview.
    case 'thumbnail_upload': {
        $id = $_GET['id'] ?? '';
        $item = read_json(drive_item_path($id), null);
        if (!$item) respond(['error' => 'Item not found.'], 404);
        if (!magDriveCategorieBewerken($item['categorie'] ?? 'algemeen')) respond(['error' => 'You are not permitted to do this.'], 403);
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

        // Clean up the old thumbnail (if any) before writing the new one.
        if (!empty($item['voorbeeldPad'])) {
            $oud = DATA_DIR . '/drive_database/thumbnails/' . $item['voorbeeldPad'];
            if (file_exists($oud)) unlink($oud);
        }

        $naam = veilige_token() . '.' . $toegestaan[$mime]['ext'];
        if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/drive_database/thumbnails/' . $naam)) {
            respond(['error' => 'Saving failed.'], 500);
        }

        $item['voorbeeldPad'] = $naam;
        $item['voorbeeldType'] = $toegestaan[$mime]['type'];
        write_json(drive_item_path($id), $item);
        respond(['item' => $item]);
        break;
    }

    // Streams the thumbnail through the portal (stays behind login, same
    // as with financial attachments).
    case 'thumbnail_stream': {
        $bestand = basename($_GET['bestand'] ?? '');
        $pad = DATA_DIR . '/drive_database/thumbnails/' . $bestand;
        if ($bestand === '' || !file_exists($pad)) { http_response_code(404); exit; }
        $ext = strtolower(pathinfo($pad, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($pad));
        header('Cache-Control: private, max-age=3600');
        readfile($pad);
        exit;
    }

    // ================= MEMBER UPLOADS =================

    // A member uploads their own file into a subcategory that has been opened
    // for it (ledenUpload). The file is stored through lib_opslag.php (now on the
    // server with limits, possibly Box later).
    case 'lid_upload': {
        if (is_demo_gebruiker()) respond(['error' => 'Not available in the demo environment.'], 403);
        $uid = $_SESSION['user_id'] ?? '';
        // File larger than the server limit (post_max_size): PHP then leaves $_POST and $_FILES empty.
        if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) respond(['error' => 'The file is too large for this server.'], 413);
        $sub = drive_geldige_subcat($_POST['subcategorieId'] ?? '', 'algemeen');
        $subRec = $sub ? read_json(drive_subcat_path($sub), null) : null;
        if (!$subRec || empty($subRec['ledenUpload'])) respond(['error' => 'Members cannot upload files into this folder.'], 403);
        if (empty($_FILES['bestand']) || !is_array($_FILES['bestand']) || is_array($_FILES['bestand']['name'])) respond(['error' => 'No file received.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) respond(['error' => 'The file is too large.'], 413);
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) respond(['error' => 'Upload failed.'], 400);

        if (!rate_limit_toegestaan('lidupload:' . $uid, 20, 3600)) respond(['error' => 'You have uploaded many files; please try again in an hour.'], 429);

        $titel = trim($_POST['titel'] ?? '');
        $weergaveNaam = leden_schone_bestandsnaam($file['name']);
        $ext = strtolower(pathinfo($weergaveNaam, PATHINFO_EXTENSION));
        if (!isset(leden_toegestane_typen()[$ext])) respond(['error' => 'This file type is not allowed. Allowed: ' . implode(', ', array_keys(leden_toegestane_typen())) . '.'], 400);
        $grootte = (int)filesize($file['tmp_name']);
        if ($grootte <= 0) respond(['error' => 'The file is empty.'], 400);
        if ($grootte > leden_upload_max_mb() * 1024 * 1024) respond(['error' => 'File may be at most ' . leden_upload_max_mb() . ' MB.'], 413);
        if (!leden_inhoud_klopt($file['tmp_name'], $ext)) respond(['error' => 'The file content does not match its type (.' . $ext . ').'], 400);
        if (leden_opslag_gebruikt_mb($uid) * 1024 * 1024 + $grootte > leden_upload_per_lid_mb() * 1024 * 1024) respond(['error' => 'You have used up your personal storage (' . leden_upload_per_lid_mb() . ' MB). Delete one of your own files first.'], 413);
        if (leden_opslag_gebruikt_mb() * 1024 * 1024 + $grootte > leden_upload_totaal_mb() * 1024 * 1024) respond(['error' => 'The shared storage is full. Please contact IT.'], 507);

        $opslag = leden_opslag_bewaar($file['tmp_name'], $ext);
        if (!$opslag) respond(['error' => 'Save failed.'], 500);
        rate_limit_registreer('lidupload:' . $uid);

        $id = uuid();
        $item = [
            'id' => $id,
            'titel' => $titel !== '' ? mb_substr($titel, 0, 150) : pathinfo($weergaveNaam, PATHINFO_FILENAME),
            'categorie' => 'algemeen',
            'omschrijving' => mb_substr(trim($_POST['omschrijving'] ?? ''), 0, 500),
            'voorbeeldType' => 'geen',
            'voorbeeldPad' => null,
            'downloadUrl' => '',
            'subcategorieId' => $sub,
            'ledenUpload' => true,
            'uploaderId' => $uid,
            'bestand' => $opslag + ['naam' => $weergaveNaam, 'grootte' => $grootte, 'ext' => $ext],
            'volgorde' => 0,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(drive_item_path($id), $item);
        respond(['item' => drive_item_publiek($item)], 201);
        break;
    }

    // Download of a member file: login required, never via a direct path.
    case 'bestand_download': {
        if (is_demo_gebruiker()) respond(['error' => 'Not available in the demo environment.'], 403);
        $item = read_json(drive_item_path($_GET['id'] ?? ''), null);
        if (!$item || empty($item['ledenUpload']) || empty($item['bestand'])) { http_response_code(404); exit; }
        $ext = strtolower((string)($item['bestand']['ext'] ?? pathinfo($item['bestand']['naam'] ?? '', PATHINFO_EXTENSION)));
        $inline = isset($_GET['inline']) && in_array($ext, array_merge(['pdf'], leden_afbeelding_extensies()), true);
        leden_opslag_stream($item['bestand'], $item['bestand']['naam'] ?? 'bestand', $ext, $inline);
        break;
    }

    default:
        respond(['error' => 'unknown action'], 400);
}
