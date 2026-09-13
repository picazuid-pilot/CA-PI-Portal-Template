<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib_drive.php';
header('Content-Type: text/plain; charset=utf-8');

// Catches the scenario where the hosting cuts the script off partway
// through (execution limit) — that happens with no visible error message
// when display_errors is off, so this makes it visible anyway.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n\n⚠ The script was cut off partway through by the hosting: " . $error['message'] . "\n";
        echo "This is likely an execution-time limit (max_execution_time), not a problem with the configuration itself.\n";
    }
});

// Makes each line appear immediately instead of only at the end —
// otherwise it looks like the script is hanging while it's actually
// still working.
function write_line($text) {
    echo $text;
    if (ob_get_level() > 0) ob_flush();
    flush();
}

if (!defined('DRIVE_FOLDER_ID') || DRIVE_FOLDER_ID === '') {
    write_line("DRIVE_FOLDER_ID is empty in district.php.\n");
    exit;
}
write_line("Folder ID: " . DRIVE_FOLDER_ID . "\n");

if (!file_exists(drive_service_account_path())) {
    write_line("Key file not found at: " . drive_service_account_path() . "\n");
    write_line("Upload the Service Account JSON file there (via the file manager, in the 'data' folder), renamed to 'google_service_account.json'.\n");
    exit;
}
write_line("Key file found.\n");

write_line("Fetching access token... (may take a few seconds)\n");
$token = drive_access_token();
if (!$token) {
    write_line("Failed. Details: " . (drive_last_error() ?: 'unknown') . "\n");
    write_line("Check that the Drive API is enabled in the Google Cloud project and that the key file is valid.\n");
    exit;
}
write_line("Succeeded, token received.\n");

write_line("Uploading test file... (tightly timed, max ~8 seconds)\n");
$tmp = DATA_DIR . '/finance_test_' . uniqid() . '.txt'; // sys_get_temp_dir() isn't writable on some hosting — data/ always is
file_put_contents($tmp, "This is a test file from the PI Work Portal (" . DISTRICT_NAME . ").\nCreated on " . date('Y-m-d H:i:s'));
$result = drive_upload_file($tmp, 'test-file-pi-work-portal.txt', 'text/plain');
@unlink($tmp);

if (!$result) {
    write_line("Upload failed. Details: " . (drive_last_error() ?: 'unknown') . "\n");
    write_line("Check that the Drive folder is shared with the Service Account's email address (found in the JSON key file under 'client_email'), with at least 'Editor' access.\n");
    write_line("Seeing an HTTP status code 403 above? Then the folder isn't (properly) shared. Seeing 'cURL: ...' with a connection error? Then the hosting may be blocking this specific Google address.\n");
    exit;
}

write_line("Success! File created: " . $result['name'] . " (ID: " . $result['id'] . ")\n");
write_line("Check the Drive folder — 'test-file-pi-work-portal.txt' should now be there.\n");
