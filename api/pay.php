<?php
// HamaraVideo payments API (Razorpay)
require __DIR__ . '/boot.php';

$PACKS = [
    'single' => ['amount' => 4900,  'credits' => 1,  'label' => '1 video'],
    'pack5'  => ['amount' => 19900, 'credits' => 5,  'label' => '5 videos'],
    'pack15' => ['amount' => 49900, 'credits' => 15, 'label' => '15 videos'],
];

function rzp_configured() {
    return defined('RAZORPAY_KEY_ID') && RAZORPAY_KEY_ID !== '' && defined('RAZORPAY_KEY_SECRET') && RAZORPAY_KEY_SECRET !== '';
}

$action = $_GET['action'] ?? '';

if ($action === 'packs') {
    global $PACKS;
    out(['packs' => $PACKS, 'key_id' => rzp_configured() ? RAZORPAY_KEY_ID : null]);
}

if ($action === 'create_order') {
    if (!rzp_configured()) out(['error' => 'Payments not configured yet'], 503);
    $user = require_user();
    $in = json_in();
    $packId = $in['pack'] ?? '';
    global $PACKS;
    if (!isset($PACKS[$packId])) out(['error' => 'Invalid pack'], 400);
    $pack = $PACKS[$packId];

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'amount' => $pack['amount'],
            'currency' => 'INR',
            'receipt' => 'hv_' . $user['id'] . '_' . time(),
            'notes' => ['user_id' => (string)$user['id'], 'pack' => $packId],
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $order = json_decode($res, true);
    if ($code >= 300 || !isset($order['id'])) out(['error' => 'Could not create order', 'detail' => $order], 502);

    $ins = db()->prepare('INSERT INTO payments (user_id, pack, amount, credits, razorpay_order_id, status) VALUES (?,?,?,?,?,"CREATED")');
    $ins->execute([$user['id'], $packId, $pack['amount'], $pack['credits'], $order['id']]);

    out([
        'order_id' => $order['id'],
        'amount' => $pack['amount'],
        'currency' => 'INR',
        'key_id' => RAZORPAY_KEY_ID,
        'name' => $user['name'],
        'mobile' => $user['mobile'],
    ]);
}

if ($action === 'verify') {
    if (!rzp_configured()) out(['error' => 'Payments not configured yet'], 503);
    $user = require_user();
    $in = json_in();
    $orderId = $in['razorpay_order_id'] ?? '';
    $paymentId = $in['razorpay_payment_id'] ?? '';
    $signature = $in['razorpay_signature'] ?? '';
    if ($orderId === '' || $paymentId === '' || $signature === '') out(['error' => 'Missing payment fields'], 400);

    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
    if (!hash_equals($expected, $signature)) out(['error' => 'Payment verification failed'], 400);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM payments WHERE razorpay_order_id = ? AND user_id = ? FOR UPDATE');
        $st->execute([$orderId, $user['id']]);
        $pay = $st->fetch();
        if (!$pay) { $pdo->rollBack(); out(['error' => 'Order not found'], 404); }
        if ($pay['status'] === 'PAID') { $pdo->rollBack(); out(['ok' => true, 'already' => true]); }

        $pdo->prepare('UPDATE payments SET status = "PAID", razorpay_payment_id = ? WHERE id = ?')
            ->execute([$paymentId, $pay['id']]);
        $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
            ->execute([$pay['credits'], $user['id']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        out(['error' => 'Could not record payment'], 500);
    }

    $st = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    out(['ok' => true, 'credits' => (int)$st->fetchColumn()]);
}

out(['error' => 'Unknown action'], 404);
