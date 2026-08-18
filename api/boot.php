<?php
// HamaraVideo shared bootstrap
header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg = __DIR__ . '/config.local.php';
if (!file_exists($cfg)) { http_response_code(500); echo json_encode(['error' => 'Server not configured']); exit; }
require $cfg;

if (!defined('APP_SECRET')) define('APP_SECRET', 'change-me-in-config');

function db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => 'Database connection failed']); exit;
        }
    }
    return $pdo;
}

function json_in() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ---- token auth: base64(userId|expiry|hmac) ----
function make_token($userId) {
    $exp = time() + 60 * 60 * 24 * 90; // 90 days
    $payload = $userId . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, APP_SECRET);
    return base64_encode($payload . '|' . $sig);
}

function verify_token($token) {
    $raw = base64_decode($token, true);
    if (!$raw) return null;
    $parts = explode('|', $raw);
    if (count($parts) !== 3) return null;
    list($uid, $exp, $sig) = $parts;
    if (!ctype_digit($uid) || !ctype_digit($exp)) return null;
    if ((int)$exp < time()) return null;
    $expected = hash_hmac('sha256', $uid . '|' . $exp, APP_SECRET);
    if (!hash_equals($expected, $sig)) return null;
    return (int)$uid;
}

function auth_user_id() {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (stripos($hdr, 'Bearer ') === 0) $token = trim(substr($hdr, 7));
    if ($token === '') $token = $_GET['token'] ?? '';
    if ($token === '') { $in = json_in(); $token = $in['token'] ?? ''; }
    $uid = $token !== '' ? verify_token($token) : null;
    return $uid;
}

function require_user() {
    $uid = auth_user_id();
    if ($uid === null) out(['error' => 'Login required', 'auth' => false], 401);
    $u = db()->prepare('SELECT id, mobile, name, credits FROM users WHERE id = ?');
    $u->execute([$uid]);
    $user = $u->fetch();
    if (!$user) out(['error' => 'Login required', 'auth' => false], 401);
    return $user;
}
