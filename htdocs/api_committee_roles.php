<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();
require_role(['admin']); // this whole module is IT-only
require __DIR__ . '/committee_roles_defaults.php';

$action = $_GET['action'] ?? '';

function committee_roles_custom_path() {
    return DATA_DIR . '/committee_roles_custom.json';
}

switch ($action) {

    case 'role_update': {
        $in = json_input();
        $sleutel = trim($in['sleutel'] ?? '');
        if ($sleutel === '') respond(['error' => 'Unknown role.'], 400);
        $naam = trim($in['naam'] ?? '');
        if ($naam === '') respond(['error' => 'Name is required.'], 400);
        $taken = is_array($in['taken'] ?? null) ? array_values(array_filter(array_map('trim', $in['taken']))) : [];
        if (empty($taken)) respond(['error' => 'At least 1 duty is required.'], 400);

        // If no custom file exists yet, start from the current defaults —
        // this way IT only edits the one role they opened, and every
        // other role stays exactly as it was.
        $rollen = read_json(committee_roles_custom_path(), null) ?: standard_committee_roles();

        $gevonden = false;
        foreach ($rollen as &$r) {
            if ($r['sleutel'] === $sleutel) {
                $r['naam'] = $naam;
                $r['sobriety'] = trim($in['sobriety'] ?? '');
                $r['ervaring'] = trim($in['ervaring'] ?? '');
                $r['termijn'] = trim($in['termijn'] ?? '');
                $r['taken'] = $taken;
                $gevonden = true;
                break;
            }
        }
        unset($r);
        if (!$gevonden) respond(['error' => 'Unknown role.'], 404);

        write_json(committee_roles_custom_path(), $rollen);
        respond(['ok' => true, 'roles' => $rollen]);
        break;
    }

    case 'reset': {
        $pad = committee_roles_custom_path();
        if (file_exists($pad)) unlink($pad);
        respond(['ok' => true]);
        break;
    }

    default:
        respond(['error' => 'unknown action'], 400);
}
