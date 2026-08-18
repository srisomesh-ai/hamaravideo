<?php
// HamaraVideo photo upload for image-to-video
require __DIR__ . '/boot.php';

if (!defined('TEST_ACCESS_CODE')) define('TEST_ACCESS_CODE', '');

// allow logged-in users or owner test mode
$uid = auth_user_id();
if ($uid === null) {
    $access = $_POST['access'] ?? ($_GET['access'] ?? '');
    if (!(TEST_ACCESS_CODE !== '' && $access === TEST_ACCESS_CODE)) out(['error' => 'Login required', 'auth' => false], 401);
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    out(['error' => 'No photo received'], 400);
}

$f = $_FILES['photo'];
if ($f['size'] > 8 * 1024 * 1024) out(['error' => 'Photo too large (max 8 MB)'], 400);

$info = @getimagesize($f['tmp_name']);
$allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
if (!$info || !isset($allowed[$info[2]])) out(['error' => 'Only JPG, PNG or WEBP photos allowed'], 400);
$ext = $allowed[$info[2]];

$dir = dirname(__DIR__) . '/media';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$name = 'img_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
    out(['error' => 'Could not save photo'], 500);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/media/' . $name;

out(['image_url' => $url]);
