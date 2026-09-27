<?php
ini_set('display_errors', 0);
ini_set('display_errors', '0');

$allowed_origins = array('https://exemple.tld');
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

$target_dir = "videos/";
$domain_url = '';

$max_file_size = 250 * 1024 * 1024;

$allowed_ext = array('mp4', 'mov', 'webm', 'gif', 'jpg', 'jpeg', 'png', 'webp');
$allowed_mimes = array(
    'video/mp4', 'video/quicktime', 'video/webm',
    'image/gif', 'image/jpeg', 'image/png', 'image/webp'
);

if (!file_exists($target_dir)) {
    mkdir($target_dir, 0755, true);
}

require_once __DIR__ . '/db_config.php';
mdt_require_auth($conn, 'upload');

if (isset($_POST['action']) && $_POST['action'] == 'delete' && isset($_POST['file'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array('error' => 'Methode non autorisee', 'code' => 'E-705'));
        exit;
    }
    if (!mdt_is_admin($conn, isset($GLOBALS['MDT_ACTOR']['did']) ? $GLOBALS['MDT_ACTOR']['did'] : '')) { http_response_code(403); echo json_encode(array('error'=>'Non autorise','code'=>'E-403')); exit; }
    $fileToDelete = basename($_POST['file']);
    $filePath = $target_dir . $fileToDelete;
    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            echo "deleted";
        } else {
            error_log("[MDT E-705] Impossible de supprimer: $filePath");
            http_response_code(500);
            echo json_encode(array('error' => 'Erreur suppression', 'code' => 'E-705'));
        }
    } else {
        http_response_code(404);
        echo json_encode(array('error' => 'Fichier introuvable', 'code' => 'E-704'));
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'list') {
    header('Content-Type: application/json');

    if (!is_dir($target_dir)) {
        echo json_encode(array());
        exit;
    }

    $files = scandir($target_dir);
    if ($files === false) {
        echo json_encode(array());
        exit;
    }

    $files = array_diff($files, array('.', '..'));
    $mediaList = array();

    $mtimes = array();
    foreach ($files as $f) { $mtimes[$f] = @filemtime($target_dir . $f) ?: 0; }
    usort($files, function($a, $b) use ($mtimes) {
        return $mtimes[$b] - $mtimes[$a];
    });

    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext)) {
            $clean_url = '/' . $target_dir . $file;
            $mediaList[] = array(
                'name' => $file,
                'url' => $clean_url,
                'type' => in_array($ext, array('mp4', 'mov', 'webm')) ? 'video' : 'image'
            );
        }
    }
    echo json_encode($mediaList);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['video_file'])) {
    $file = $_FILES['video_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        error_log("[MDT E-703] Upload error code: " . $file['error']);
        http_response_code(500);
        die(json_encode(array('error' => 'Erreur upload', 'code' => 'E-703')));
    }

    if ($file['size'] > $max_file_size) {
        http_response_code(400);
        die(json_encode(array('error' => 'Fichier trop volumineux (max 500 MB)', 'code' => 'E-702')));
    }

    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_ext)) {
        http_response_code(400);
        die(json_encode(array('error' => 'Format non autorise', 'code' => 'E-701')));
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($file['tmp_name']);
    if (!in_array($real_mime, $allowed_mimes)) {
        error_log("[MDT E-701] MIME rejete: $real_mime pour fichier " . $file['name']);
        http_response_code(400);
        die(json_encode(array('error' => 'Type de fichier non autorise', 'code' => 'E-701')));
    }

    $clean_name = preg_replace('/[^a-zA-Z0-9]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $new_name = bin2hex(random_bytes(8)) . "_" . $clean_name . "." . $file_ext;
    $target_file = $target_dir . $new_name;

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        echo '/' . $target_file;
    } else {
        error_log("[MDT E-703] move_uploaded_file failed: $target_file");
        http_response_code(500);
        die(json_encode(array('error' => 'Erreur serveur', 'code' => 'E-703')));
    }
}
