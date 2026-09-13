<?php
/**
 * Test email: only meant for District IT, to check whether the SMTP
 * details in district.php are correct. Remove this file (or otherwise
 * secure it) once the portal goes live — it isn't tied to login, so
 * don't leave it permanently open on the internet.
 *
 * Usage: go to test_email.php?to=your@email.com in the browser.
 */
require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

$to = $_GET['to'] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: test_email.php?to=your@email.com\n";
    exit;
}

if (!defined('SMTP_HOST') || SMTP_HOST === '') {
    echo "SMTP_HOST is empty in district.php — there's nothing to test.\n";
    exit;
}

echo "Connecting to " . SMTP_HOST . ':' . SMTP_PORT . " as " . SMTP_USER . " …\n";
$ok = smtp_send($to, 'Test email — PI Work Portal', "This is a test message from the PI Work Portal (" . DISTRICT_NAME . ").\n\nIf you're reading this, the SMTP configuration works.");

echo $ok
    ? "Success! Check the inbox (and spam folder) of $to.\n"
    : "Failed. Check: is the app password correct? Is 2-step verification on? Are SMTP_HOST/PORT correct?\n";
