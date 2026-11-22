<?php
require __DIR__ . '/config.php';
require_it_pagina(); // alleen ingelogde IT-beheerders
require __DIR__ . '/lib_kdrive.php';
header('Content-Type: text/plain; charset=utf-8');

function schrijf($tekst) {
    echo $tekst;
    if (ob_get_level() > 0) ob_flush();
    flush();
}

register_shutdown_function(function () {
    $fout = error_get_last();
    if ($fout && in_array($fout['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n\n⚠ Het script is halverwege afgebroken door de hosting: " . $fout['message'] . "\n";
    }
});

if (!kdrive_beschikbaar()) {
    schrijf("KDRIVE_ID, KDRIVE_USERNAME of KDRIVE_PASSWORD is nog leeg in district.php.\n");
    exit;
}
schrijf("kDrive-instellingen gevonden. WebDAV-adres: " . kdrive_webdav_basis() . "\n");
if (defined('KDRIVE_MAP') && KDRIVE_MAP !== '') {
    schrijf("Doelmap: " . KDRIVE_MAP . "\n");
} else {
    schrijf("Geen KDRIVE_MAP ingesteld — bestand komt in de hoofdmap van je kDrive.\n");
}

schrijf("Test-bestand uploaden...\n");
$tmp = DATA_DIR . '/kdrive_test_' . uniqid() . '.txt';
file_put_contents($tmp, "Dit is een testbestand vanuit het PI Werkportaal (" . DISTRICT_NAME . ").\nGemaakt op " . date('Y-m-d H:i:s'));
$resultaat = kdrive_upload_bestand($tmp, 'testbestand-pi-werkportaal.txt', 'text/plain');
@unlink($tmp);

if (!$resultaat) {
    schrijf("Upload mislukt. Details: " . (kdrive_laatste_fout() ?: 'onbekend') . "\n");
    schrijf("Controleer: klopt KDRIVE_ID? Klopt de gebruikersnaam? Is het wachtwoord een geldig (toepassings)wachtwoord? Bestaat de map uit KDRIVE_MAP al?\n");
    exit;
}

schrijf("Gelukt! Bestand geplaatst op: " . $resultaat['pad'] . "\n");
schrijf("Check je kDrive — daar hoort nu 'testbestand-pi-werkportaal.txt' te staan.\n");
