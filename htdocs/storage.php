<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();

$action = $_GET['action'] ?? '';
$key = $_GET['key'] ?? '';
$shared = ($_GET['shared'] ?? 'false') === 'true';

if ($key === '' && $action !== 'list') {
    respond(['error' => 'key is missing'], 400);
}

function storage_dir($shared) {
    return $shared
        ? DATA_DIR . '/storage/shared'
        : DATA_DIR . '/storage/users/' . current_user_slug();
}

function key_to_path($key, $shared) {
    // Keys like "book:abc123" or "att:abc123:def456" become folders, so
    // that a list-prefix like "att:abc123:" actually works too.
    $safe = preg_replace('/[^a-zA-Z0-9_:\-]/', '_', $key);
    $safe = str_replace(':', '/', $safe);
    return storage_dir($shared) . '/' . $safe . '.json';
}

switch ($action) {

    case 'get': {
        $path = key_to_path($key, $shared);
        if (!file_exists($path)) respond(['value' => null]);
        $value = read_json($path, null);
        respond(['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'shared' => $shared]);
        break;
    }

    case 'set': {
        $in = json_input();
        $value = $in['value'] ?? null; // this is already a JSON string coming from the app
        $decoded = json_decode($value, true);
        $path = key_to_path($key, $shared);
        $ok = write_json($path, $decoded);
        if (!$ok) respond(['error' => 'saving failed'], 500);
        respond(['key' => $key, 'shared' => $shared]);
        break;
    }

    case 'delete': {
        $path = key_to_path($key, $shared);
        if (file_exists($path)) unlink($path);
        respond(['key' => $key, 'deleted' => true, 'shared' => $shared]);
        break;
    }

    case 'list': {
        $prefix = $_GET['prefix'] ?? '';
        $dir = storage_dir($shared);
        $keys = [];
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'json') continue;
                $rel = substr($file->getPathname(), strlen($dir) + 1);
                $rel = substr($rel, 0, -5); // strip .json
                $k = str_replace('/', ':', $rel);
                if ($prefix === '' || strpos($k, $prefix) === 0) $keys[] = $k;
            }
        }
        respond(['keys' => $keys, 'prefix' => $prefix, 'shared' => $shared]);
        break;
    }

    default:
        respond(['error' => 'unknown action'], 400);
}
