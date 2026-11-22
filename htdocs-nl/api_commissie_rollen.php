<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();
require_role(['admin', 'voorzitter']); // comité instellen: IT en voorzitter
require __DIR__ . '/commissie_rollen_standaard.php';

$action = $_GET['action'] ?? '';

function commissie_rollen_custom_path() {
    return DATA_DIR . '/commissie_rollen_aangepast.json';
}

switch ($action) {

    case 'rol_bijwerken': {
        $in = json_input();
        $sleutel = trim($in['sleutel'] ?? '');
        if ($sleutel === '') respond(['error' => 'Onbekende rol.'], 400);
        $naam = trim($in['naam'] ?? '');
        if ($naam === '') respond(['error' => 'Naam is verplicht.'], 400);
        $taken = is_array($in['taken'] ?? null) ? array_values(array_filter(array_map('trim', $in['taken']))) : [];
        if (empty($taken)) respond(['error' => 'Minstens 1 taak is verplicht.'], 400);

        // Bestaat er nog geen aangepast bestand, dan starten we vanaf de
        // huidige standaard-inhoud — zo bewerkt IT alleen de ene rol die
        // 'ie opende, en blijven alle andere rollen ongewijzigd.
        $rollen = read_json(commissie_rollen_custom_path(), null) ?: standaard_commissie_rollen();

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
        if (!$gevonden) respond(['error' => 'Onbekende rol.'], 404);

        write_json(commissie_rollen_custom_path(), $rollen);
        respond(['ok' => true, 'rollen' => $rollen]);
        break;
    }

    case 'reset': {
        $pad = commissie_rollen_custom_path();
        if (file_exists($pad)) unlink($pad);
        respond(['ok' => true]);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}
