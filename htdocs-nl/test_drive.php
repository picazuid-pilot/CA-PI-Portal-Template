<?php
require __DIR__ . '/config.php';
require_it_pagina(); // alleen ingelogde IT-beheerders
require __DIR__ . '/lib_drive.php';
header('Content-Type: text/plain; charset=utf-8');

// Vangt het scenario op waarbij de hosting het script halverwege afkapt
// (uitvoeringslimiet) — dat gebeurt zonder zichtbare foutmelding als
// display_errors uitstaat, dus dit maakt het alsnog zichtbaar.
register_shutdown_function(function () {
    $fout = error_get_last();
    if ($fout && in_array($fout['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n\n⚠ Het script is halverwege afgebroken door de hosting: " . $fout['message'] . "\n";
        echo "Dit is vermoedelijk een uitvoeringslimiet (max_execution_time) van InfinityFree, niet een probleem in de configuratie zelf.\n";
    }
});

// Zorgt dat elke regel meteen verschijnt i.p.v. pas aan het eind — anders
// lijkt het alsof het script hangt terwijl het gewoon nog bezig is.
function schrijf($tekst) {
    echo $tekst;
    if (ob_get_level() > 0) ob_flush();
    flush();
}

if (!defined('DRIVE_FOLDER_ID') || DRIVE_FOLDER_ID === '') {
    schrijf("DRIVE_FOLDER_ID is leeg in district.php.\n");
    exit;
}
schrijf("Map-ID: " . DRIVE_FOLDER_ID . "\n");

if (!file_exists(drive_service_account_pad())) {
    schrijf("Sleutelbestand niet gevonden op: " . drive_service_account_pad() . "\n");
    schrijf("Upload het Service Account JSON-bestand daar naartoe (via de filemanager, in de map 'data'), hernoemd naar 'google_service_account.json'.\n");
    exit;
}
schrijf("Sleutelbestand gevonden.\n");

schrijf("Toegangstoken ophalen... (kan een paar seconden duren)\n");
$token = drive_access_token();
if (!$token) {
    schrijf("Mislukt. Details: " . (drive_laatste_fout() ?: 'onbekend') . "\n");
    schrijf("Controleer of de Drive API aanstaat in het Google Cloud-project en of het sleutelbestand geldig is.\n");
    exit;
}
schrijf("Gelukt, token ontvangen.\n");

schrijf("Test-bestand uploaden... (kort getimed, max ~8 seconden)\n");
$tmp = DATA_DIR . '/financien_test_' . uniqid() . '.txt'; // sys_get_temp_dir() is op sommige hosting niet beschrijfbaar — data/ is dat altijd wel
file_put_contents($tmp, "Dit is een testbestand vanuit het PI Werkportaal (" . DISTRICT_NAME . ").\nGemaakt op " . date('Y-m-d H:i:s'));
$resultaat = drive_upload_bestand($tmp, 'testbestand-pi-werkportaal.txt', 'text/plain');
@unlink($tmp);

if (!$resultaat) {
    schrijf("Upload mislukt. Details: " . (drive_laatste_fout() ?: 'onbekend') . "\n");
    schrijf("Controleer of de Drive-map is gedeeld met het Service Account e-mailadres (staat in het JSON-sleutelbestand bij 'client_email'), met minimaal 'Bewerker'-rechten.\n");
    schrijf("Zie je een HTTP-statuscode 403 hierboven? Dan is de map niet (goed) gedeeld. Zie je 'cURL: ...' met een verbindingsfout? Dan blokkeert de hosting mogelijk dit specifieke Google-adres.\n");
    exit;
}

schrijf("Gelukt! Bestand aangemaakt: " . $resultaat['name'] . " (ID: " . $resultaat['id'] . ")\n");
schrijf("Check de Drive-map — daar hoort nu 'testbestand-pi-werkportaal.txt' te staan.\n");
