<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();

$action = $_GET['action'] ?? '';
$itRollen = ['admin', 'chair'];

// Edit rights depend on the item's category: the general archive stays
// IT+Chairperson, but Minutes & Agendas may also be managed by the Secretary.
function magDriveCategorieBewerken($categorie) {
    if ($categorie === 'notulen_agenda') return heeft_rol(['admin', 'chair', 'secretary']);
    return heeft_rol(['admin', 'chair']);
}

function drive_item_path($id) {
    return DATA_DIR . '/drive_database/items/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json';
}

switch ($action) {

    case 'item_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/drive_database/items/*.json') as $file) {
            $i = read_json($file, null);
            if ($i) $out[] = $i;
        }
        usort($out, fn($a, $b) => ($a['volgorde'] ?? 0) <=> ($b['volgorde'] ?? 0) ?: strcmp($a['titel'], $b['titel']));
        respond(['items' => $out]);
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
            'downloadUrl' => trim($in['downloadUrl'] ?? ''),
            'volgorde' => (int)($in['volgorde'] ?? 0),
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(drive_item_path($id), $item);
        respond(['item' => $item], 201);
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

    case 'item_update': {
        $id = $_GET['id'] ?? '';
        $item = read_json(drive_item_path($id), null);
        if (!$item) respond(['error' => 'Not found.'], 404);
        if (!magDriveCategorieBewerken($item['categorie'] ?? 'algemeen')) respond(['error' => 'You are not permitted to do this.'], 403);
        $in = json_input();
        if (isset($in['titel'])) {
            $titel = trim($in['titel']);
            if ($titel === '') respond(['error' => 'Title is required.'], 400);
            $item['titel'] = $titel;
        }
        if (isset($in['omschrijving'])) $item['omschrijving'] = trim($in['omschrijving']);
        if (isset($in['downloadUrl'])) $item['downloadUrl'] = trim($in['downloadUrl']);
        if (isset($in['volgorde'])) $item['volgorde'] = (int)$in['volgorde'];
        write_json(drive_item_path($id), $item);
        respond(['item' => $item]);
        break;
    }

    case 'item_delete': {
        $id = $_GET['id'] ?? '';
        $item = read_json(drive_item_path($id), null);
        if ($item && !magDriveCategorieBewerken($item['categorie'] ?? 'algemeen')) respond(['error' => 'You are not permitted to do this.'], 403);
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

    default:
        respond(['error' => 'unknown action'], 400);
}
