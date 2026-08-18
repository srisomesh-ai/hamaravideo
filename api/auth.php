<?php
// HamaraVideo auth API
require __DIR__ . '/boot.php';

$action = $_GET['action'] ?? '';

if ($action === 'register') {
    $in = json_in();
    $mobile = preg_replace('/\D/', '', $in['mobile'] ?? '');
    $name = trim($in['name'] ?? '');
    $pin = trim($in['pin'] ?? '');
    if (strlen($mobile) !== 10) out(['error' => 'Enter a valid 10-digit mobile number'], 400);
    if (mb_strlen($name) < 2) out(['error' => 'Enter your name'], 400);
    if (!preg_match('/^\d{4,6}$/', $pin)) out(['error' => 'PIN must be 4 to 6 digits'], 400);

    $st = db()->prepare('SELECT id FROM users WHERE mobile = ?');
    $st->execute([$mobile]);
    if ($st->fetch()) out(['error' => 'This mobile is already registered. Please login.'], 409);

    $freeCredits = defined('SIGNUP_FREE_CREDITS') ? SIGNUP_FREE_CREDITS : 1;
    $ins = db()->prepare('INSERT INTO users (mobile, name, pin_hash, credits) VALUES (?,?,?,?)');
    $ins->execute([$mobile, $name, password_hash($pin, PASSWORD_DEFAULT), $freeCredits]);
    $uid = (int)db()->lastInsertId();
    out(['token' => make_token($uid), 'user' => ['id' => $uid, 'mobile' => $mobile, 'name' => $name, 'credits' => $freeCredits]]);
}

if ($action === 'login') {
    $in = json_in();
    $mobile = preg_replace('/\D/', '', $in['mobile'] ?? '');
    $pin = trim($in['pin'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE mobile = ?');
    $st->execute([$mobile]);
    $u = $st->fetch();
    if (!$u || !password_verify($pin, $u['pin_hash'])) out(['error' => 'Mobile or PIN incorrect'], 401);
    out(['token' => make_token($u['id']), 'user' => ['id' => (int)$u['id'], 'mobile' => $u['mobile'], 'name' => $u['name'], 'credits' => (int)$u['credits']]]);
}

if ($action === 'me') {
    $user = require_user();
    out(['user' => ['id' => (int)$user['id'], 'mobile' => $user['mobile'], 'name' => $user['name'], 'credits' => (int)$user['credits']]]);
}

// Admin: add credits manually. auth.php?action=admin_add_credits&admin=KEY  body: {mobile, credits}
if ($action === 'admin_add_credits') {
    if (!defined('ADMIN_KEY') || ($_GET['admin'] ?? '') !== ADMIN_KEY) out(['error' => 'Forbidden'], 403);
    $in = json_in();
    $mobile = preg_replace('/\D/', '', $in['mobile'] ?? '');
    $credits = (int)($in['credits'] ?? 0);
    if ($credits === 0) out(['error' => 'credits required'], 400);
    $st = db()->prepare('UPDATE users SET credits = credits + ? WHERE mobile = ?');
    $st->execute([$credits, $mobile]);
    if ($st->rowCount() === 0) out(['error' => 'User not found'], 404);
    out(['ok' => true]);
}

out(['error' => 'Unknown action'], 404);
