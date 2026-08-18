<?php
/**
 * HamaraVideo - video generation API
 * Modes:
 *   - Logged-in user (Bearer token): deducts 1 credit, records job, credit auto-refund on failure.
 *   - Owner test mode (access code, no token): free generation, no DB record. For testing only.
 */
require __DIR__ . '/boot.php';

if (!defined('FAL_MODEL')) define('FAL_MODEL', 'fal-ai/ltx-2.3/text-to-video/fast');
if (!defined('VIDEO_DURATION')) define('VIDEO_DURATION', 8);
if (!defined('TEST_ACCESS_CODE')) define('TEST_ACCESS_CODE', '');

// Fal queue quirk: submit uses the full model path, but status/result use only the app root (owner/app)
function fal_app_root() {
    $parts = explode('/', FAL_MODEL);
    return $parts[0] . '/' . $parts[1];
}

function fal_call($method, $url, $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Key ' . FAL_API_KEY, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [0, ['error' => 'Connection failed: ' . $err]];
    $json = json_decode($res, true);
    if ($json === null) $json = ['raw' => $res];
    return [$code, $json];
}

function improve_prompt($userText) {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '' || strpos(ANTHROPIC_API_KEY, 'PASTE') === 0) return $userText;
    $sys = "You are the creative director for HamaraVideo, which makes short promo videos for small Indian businesses (kirana shops, saree shops, jewellery, restaurants, salons, sweets shops).

The user gives a rough idea in Telugu, Hindi or English. Convert it into ONE excellent English text-to-video prompt for an AI video model (8 second video).

Rules:
- Understand what the business actually is and show THAT business realistically: correct products on shelves, correct Indian shop setting, Indian customers, Indian street/market context.
- Structure as a mini shot sequence, e.g.: opening establishing shot of the shop, then product close-ups, then happy customers, ending on an inviting storefront shot.
- Cinematic language: camera movement (slow push-in, pan across shelves), lighting (warm golden, festive diya glow if festival), mood.
- If a festival is mentioned (Diwali, Sankranti, etc.) include authentic festival decor: diyas, marigold garlands, rangoli, lights.
- NEVER include readable text, signboards with names, phone numbers, or numbers in the scene - AI renders text badly.
- No celebrities, no brand logos.
- Max 100 words. Reply with the prompt only, nothing else.";
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 400,
            'system' => $sys,
            'messages' => [['role' => 'user', 'content' => $userText]],
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($res, true);
    if (isset($j['content'][0]['text'])) { $t = trim($j['content'][0]['text']); if ($t !== '') return $t; }
    return $userText;
}

$action = $_GET['action'] ?? '';

if ($action === 'submit') {
    $in = json_in();
    $prompt = trim($in['prompt'] ?? '');
    $aspect = in_array($in['aspect_ratio'] ?? '', ['9:16', '16:9', '1:1']) ? $in['aspect_ratio'] : '9:16';
    if (mb_strlen($prompt) < 5) out(['error' => 'Prompt too short'], 400);
    if (mb_strlen($prompt) > 1500) out(['error' => 'Prompt too long'], 400);

    // determine mode
    $uid = auth_user_id();
    $testMode = false;
    if ($uid === null) {
        $access = $in['access'] ?? ($_GET['access'] ?? '');
        if (TEST_ACCESS_CODE !== '' && $access === TEST_ACCESS_CODE) { $testMode = true; }
        else out(['error' => 'Login required', 'auth' => false], 401);
    }

    $user = null;
    if (!$testMode) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$uid]);
        $user = $st->fetch();
        if (!$user) out(['error' => 'Login required', 'auth' => false], 401);
        if ((int)$user['credits'] < 1) out(['error' => 'No credits left. Please buy a pack.', 'no_credits' => true], 402);
    }

    $finalPrompt = improve_prompt($prompt);

    list($code, $res) = fal_call('POST', 'https://queue.fal.run/' . FAL_MODEL, [
        'prompt' => $finalPrompt,
        'duration' => VIDEO_DURATION,
        'aspect_ratio' => $aspect,
    ]);
    if (!($code >= 200 && $code < 300 && isset($res['request_id']))) {
        out(['error' => 'Generation submit failed', 'detail' => $res, 'http' => $code], 502);
    }

    $jobId = null;
    if (!$testMode) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET credits = credits - 1 WHERE id = ? AND credits >= 1')->execute([$uid]);
            $ins = $pdo->prepare('INSERT INTO jobs (user_id, prompt, used_prompt, aspect, fal_request_id, status) VALUES (?,?,?,?,?,"SUBMITTED")');
            $ins->execute([$uid, $prompt, $finalPrompt, $aspect, $res['request_id']]);
            $jobId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); }
    }

    out(['request_id' => $res['request_id'], 'job_id' => $jobId, 'used_prompt' => $finalPrompt]);
}

if ($action === 'status') {
    $rid = preg_replace('/[^a-zA-Z0-9\-_]/', '', $_GET['request_id'] ?? '');
    if ($rid === '') out(['error' => 'request_id required'], 400);

    // allow either logged-in user or test access code
    $uid = auth_user_id();
    if ($uid === null) {
        $access = $_GET['access'] ?? '';
        if (!(TEST_ACCESS_CODE !== '' && $access === TEST_ACCESS_CODE)) out(['error' => 'Login required', 'auth' => false], 401);
    }

    list($code, $st) = fal_call('GET', 'https://queue.fal.run/' . fal_app_root() . '/requests/' . $rid . '/status');
    $status = $st['status'] ?? 'UNKNOWN';

    if ($status === 'COMPLETED') {
        list($c2, $result) = fal_call('GET', 'https://queue.fal.run/' . fal_app_root() . '/requests/' . $rid);
        $videoUrl = $result['video']['url'] ?? ($result['output']['video']['url'] ?? null);
        if ($uid !== null && $videoUrl) {
            $up = db()->prepare('UPDATE jobs SET status = "COMPLETED", video_url = ? WHERE fal_request_id = ? AND user_id = ?');
            $up->execute([$videoUrl, $rid, $uid]);
        }
        out(['status' => 'COMPLETED', 'video_url' => $videoUrl, 'result' => $videoUrl ? null : $result]);
    }

    if (in_array($status, ['FAILED', 'ERROR'])) {
        if ($uid !== null) {
            // refund credit once
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('SELECT * FROM jobs WHERE fal_request_id = ? AND user_id = ? FOR UPDATE');
                $q->execute([$rid, $uid]);
                $job = $q->fetch();
                if ($job && !$job['credit_refunded'] && $job['status'] !== 'FAILED') {
                    $pdo->prepare('UPDATE jobs SET status = "FAILED", credit_refunded = 1 WHERE id = ?')->execute([$job['id']]);
                    $pdo->prepare('UPDATE users SET credits = credits + 1 WHERE id = ?')->execute([$uid]);
                }
                $pdo->commit();
            } catch (Exception $e) { $pdo->rollBack(); }
        }
        out(['status' => 'FAILED']);
    }

    out(['status' => $status, 'queue_position' => $st['queue_position'] ?? null]);
}

if ($action === 'history') {
    $user = require_user();
    $st = db()->prepare('SELECT id, prompt, aspect, status, video_url, created_at FROM jobs WHERE user_id = ? ORDER BY id DESC LIMIT 50');
    $st->execute([$user['id']]);
    out(['jobs' => $st->fetchAll(), 'credits' => (int)$user['credits']]);
}

out(['error' => 'Unknown action'], 404);
