<?php
/**
 * Test-mailtje: alleen te gebruiken door de District-IT, om te controleren
 * of de SMTP-gegevens in district.php kloppen. Verwijder dit bestand (of
 * beveilig het extra) zodra het portaal live gaat — het is niet gekoppeld
 * aan inloggen, dus laat het niet permanent open op internet staan.
 *
 * Gebruik: ga naar test_email.php?naar=jouw@email.nl in de browser.
 */
require __DIR__ . '/config.php';
require_it_pagina(); // alleen ingelogde IT-beheerders
header('Content-Type: text/plain; charset=utf-8');

$naar = $_GET['naar'] ?? '';
if (!filter_var($naar, FILTER_VALIDATE_EMAIL)) {
    echo "Gebruik: test_email.php?naar=jouw@email.nl\n";
    exit;
}

if (!defined('SMTP_HOST') || SMTP_HOST === '') {
    echo "SMTP_HOST is leeg in district.php — er is niets om te testen.\n";
    exit;
}

echo "Verbinden met " . SMTP_HOST . ':' . SMTP_PORT . " als " . SMTP_USER . " …\n";
$ok = smtp_send($naar, 'Testmail PI Werkportaal', "Dit is een testbericht vanuit het PI Werkportaal (" . DISTRICT_NAME . ").\n\nAls je dit leest, werkt de SMTP-configuratie.");

echo $ok
    ? "Gelukt! Check de inbox (en spam-map) van $naar.\n"
    : "Mislukt. Controleer: app-wachtwoord correct? 2-stapsverificatie aan? SMTP_HOST/PORT juist?\n";
