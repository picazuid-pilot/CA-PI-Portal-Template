<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib_kdrive.php';
header('Content-Type: text/plain; charset=utf-8');

function write_line($text) {
    echo $text;
    if (ob_get_level() > 0) ob_flush();
    flush();
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n\n⚠ The script was cut off partway through by the hosting: " . $error['message'] . "\n";
    }
});

if (!kdrive_available()) {
    write_line("KDRIVE_ID, KDRIVE_USERNAME, or KDRIVE_PASSWORD is still empty in district.php.\n");
    exit;
}
write_line("kDrive settings found. WebDAV address: " . kdrive_webdav_base() . "\n");
if (defined('KDRIVE_MAP') && KDRIVE_MAP !== '') {
    write_line("Target folder: " . KDRIVE_MAP . "\n");
} else {
    write_line("No KDRIVE_MAP set — the file will go into the root folder of your kDrive.\n");
}

write_line("Uploading test file...\n");
$tmp = DATA_DIR . '/kdrive_test_' . uniqid() . '.txt';
file_put_contents($tmp, "This is a test file from the PI Work Portal (" . DISTRICT_NAME . ").\nCreated on " . date('Y-m-d H:i:s'));
$result = kdrive_upload_file($tmp, 'test-file-pi-work-portal.txt', 'text/plain');
@unlink($tmp);

if (!$result) {
    write_line("Upload failed. Details: " . (kdrive_last_error() ?: 'unknown') . "\n");
    write_line("Check: is KDRIVE_ID correct? Is the username correct? Is the password a valid (app) password? Does the folder from KDRIVE_MAP already exist?\n");
    exit;
}

write_line("Success! File placed at: " . $result['pad'] . "\n");
write_line("Check your kDrive — 'test-file-pi-work-portal.txt' should now be there.\n");
