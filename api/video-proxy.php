<?php
/**
 * HamaraVideo - Fal.ai video generation proxy
 * Secrets live in api/config.local.php on the server (never in git).
 *
 * Endpoints:
 *   POST video-proxy.php?action=submit   {prompt, aspect_ratio, access}
 *   GET  video-proxy.php?action=status&request_id=...&access=...
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg = __DIR__ . '/config.local.php';
if (!file_exists($cfg)) { http_response_code(500); echo json_encode(['error' => 'Server not configured. config.local.php missing.']); exit; }
require $cfg;

if (!defined('FAL_MODEL')) define('FAL_MODEL', 'fal-ai/kling-video/v3/standard/text-to-video');
if (!defined('TEST_ACCESS_CODE')) define('TEST_ACCESS_CODE', '');

function fal_call($method, $url, $body = null) {
    $ch = curl_init($url);
    $headers = ['Authorization: Key ' . FAL_API_KEY, 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
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
    // If Claude key configured, turn loose Telugu/Hindi/English input into a cinematic English video prompt.
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '' || strpos(ANTHROPIC_API_KEY, 'PASTE') === 0) {
        return $userText; // pass through until Claude key is added
    }
    $sys = "You convert a small Indian business owner's promo idea (may be in Telugu, Hindi or English) into ONE concise English text-to-video prompt for an AI video model. Cinematic, warm, festive Indian retail feel where appropriate. Describe visuals only (scenes, camera, lighting, mood). Do NOT include on-screen text, phone numbers or shop names (text overlays are added later). Max 80 words. Reply with the prompt only.";
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 300,
            'system' => $sys,
            'messages' => [['role' => 'user', 'content' => $userText]],
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($res, true);
    if (isset($j['content'][0]['text'])) {
        $t = trim($j['content'][0]['text']);
        if ($t !== '') return $t;
    }
    return $userText;
}

$action = $_GET['action'] ?? '';

// simple gate so random visitors can't burn credits during testing
$access = $_GET['access'] ?? '';
if ($action === 'submit') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $access = $in['access'] ?? $access;
}
if (TEST_ACCESS_CODE !== '' && $access !== TEST_ACCESS_CODE) {
    http_response_code(403); echo json_encode(['error' => 'Invalid access code']); exit;
}

if ($action === 'submit') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $prompt = trim($in['prompt'] ?? '');
    $aspect = in_array($in['aspect_ratio'] ?? '', ['9:16', '16:9', '1:1']) ? $in['aspect_ratio'] : '9:16';
    if (mb_strlen($prompt) < 5) { http_response_code(400); echo json_encode(['error' => 'Prompt too short']); exit; }
    if (mb_strlen($prompt) > 1500) { http_response_code(400); echo json_encode(['error' => 'Prompt too long']); exit; }

    $finalPrompt = improve_prompt($prompt);

    list($code, $res) = fal_call('POST', 'https://queue.fal.run/' . FAL_MODEL, [
        'prompt' => $finalPrompt,
        'duration' => '5',
        'aspect_ratio' => $aspect,
    ]);
    if ($code >= 200 && $code < 300 && isset($res['request_id'])) {
        echo json_encode(['request_id' => $res['request_id'], 'used_prompt' => $finalPrompt]);
    } else {
        http_response_code(502);
        echo json_encode(['error' => 'Generation submit failed', 'detail' => $res, 'http' => $code]);
    }
    exit;
}

if ($action === 'status') {
    $rid = preg_replace('/[^a-zA-Z0-9\-_]/', '', $_GET['request_id'] ?? '');
    if ($rid === '') { http_response_code(400); echo json_encode(['error' => 'request_id required']); exit; }

    list($code, $st) = fal_call('GET', 'https://queue.fal.run/' . FAL_MODEL . '/requests/' . $rid . '/status');
    $status = $st['status'] ?? 'UNKNOWN';

    if ($status === 'COMPLETED') {
        list($c2, $result) = fal_call('GET', 'https://queue.fal.run/' . FAL_MODEL . '/requests/' . $rid);
        $videoUrl = $result['video']['url'] ?? ($result['output']['video']['url'] ?? null);
        echo json_encode(['status' => 'COMPLETED', 'video_url' => $videoUrl, 'result' => $videoUrl ? null : $result]);
    } else {
        echo json_encode(['status' => $status, 'queue_position' => $st['queue_position'] ?? null, 'detail' => ($code >= 400 ? $st : null)]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown action']);
