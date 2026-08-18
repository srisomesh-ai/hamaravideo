<?php
/**
 * HamaraVideo - video generation API
 * Modes:
 *   - Logged-in user (Bearer token): deducts 1 credit, records job, credit auto-refund on failure.
 *   - Owner test mode (access code, no token): free generation, no DB record. For testing only.
 */
require __DIR__ . '/boot.php';

if (!defined('FAL_MODEL')) define('FAL_MODEL', 'fal-ai/ltx-2.3/text-to-video/fast');
if (!defined('FAL_MODEL_PREMIUM')) define('FAL_MODEL_PREMIUM', 'fal-ai/ltx-2.3/text-to-video');
if (!defined('FAL_MODEL_PHOTO')) define('FAL_MODEL_PHOTO', 'fal-ai/ltx-2.3/image-to-video/fast');
if (!defined('PREMIUM_CREDITS')) define('PREMIUM_CREDITS', 2);
if (!defined('VIDEO_DURATION')) define('VIDEO_DURATION', 8);
if (!defined('TEST_ACCESS_CODE')) define('TEST_ACCESS_CODE', '');

function tier_model($quality) {
    if ($quality === 'premium') return FAL_MODEL_PREMIUM;
    if ($quality === 'photo') return FAL_MODEL_PHOTO;
    return FAL_MODEL;
}
function tier_credits($quality) {
    return $quality === 'premium' ? PREMIUM_CREDITS : 1;
}
function tier_payload($quality, $prompt, $aspect, $imageUrl = null) {
    if ($quality === 'photo') {
        return [
            'prompt' => $prompt,
            'image_url' => $imageUrl,
            'duration' => VIDEO_DURATION,
        ];
    }
    // text-to-video (LTX fast / pro) - same parameter format
    return [
        'prompt' => $prompt,
        'duration' => VIDEO_DURATION,
        'aspect_ratio' => $aspect,
    ];
}

// Fal queue quirk: submit uses the full model path, but status/result use only the app root (owner/app)
function fal_app_root($model) {
    $parts = explode('/', $model);
    return $parts[0] . '/' . $parts[1];
}

function find_video_url($data) {
    // known shapes first
    $candidates = [
        $data['video']['url'] ?? null,
        $data['output']['video']['url'] ?? null,
        $data['videos'][0]['url'] ?? null,
        $data['video_url'] ?? null,
        $data['output']['video_url'] ?? null,
    ];
    foreach ($candidates as $c) if (is_string($c) && $c !== '') return $c;
    // recursive fallback: first http(s) string that looks like a video file
    $stack = [$data];
    while ($stack) {
        $cur = array_pop($stack);
        if (is_array($cur)) {
            foreach ($cur as $v) $stack[] = $v;
        } elseif (is_string($cur) && preg_match('#^https?://#', $cur) && preg_match('#\.(mp4|webm|mov)(\?|$)#i', $cur)) {
            return $cur;
        }
    }
    return null;
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

function improve_prompt($userText, $quality = 'standard') {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '' || strpos(ANTHROPIC_API_KEY, 'PASTE') === 0) return $userText;
    if ($quality === 'photo') {
        $sys = "You write motion prompts for an image-to-video AI. The user uploaded a REAL photo of their Indian shop, product or business and gives a rough idea (Telugu, Hindi or English) of the promo feel they want.

Write ONE short English motion prompt (max 60 words) describing how the photo should come alive over " . VIDEO_DURATION . " seconds:
- Keep the scene exactly as in the photo - do NOT add new people, objects or change the setting.
- Subtle realistic motion only: slow cinematic camera push-in or gentle pan, lights twinkling or glowing warmly, fabric/product gently swaying, steam rising (food), soft festive sparkle if a festival is mentioned.
- End on a steady, inviting final frame.
- No spoken dialogue, no readable text overlays, no scene changes.
Reply with the prompt only.";
    } else {
        $sys = "You are the creative director and script writer for HamaraVideo, making short promo videos for Indian businesses (shops, restaurants, salons, GPS/tech services, events).

The user gives a rough idea in Telugu, Hindi or English. FIRST understand the real intent: What is the business? What is being promoted? Who are the characters (customer, staff, owner, rider)? Where does the scene happen? What must be said?

THEN write ONE English text-to-video prompt as a tight " . VIDEO_DURATION . "-second script where EVERY second is used - no idle moments, no slow filler, no dead air. Structure it as timed beats, like:
[0-2s] ... [2-5s] ... [5-" . VIDEO_DURATION . "s] ...
Each beat must contain clear action or a clear spoken line. The final beat should end on a strong, complete note - never cut mid-action.

SIMPLICITY RULES (critical - AI video fails when overloaded):
- ONE location, ONE continuous scene. No scene changes.
- MAXIMUM 2 people visible. Never introduce characters the user didn't mention.
- PREFER NO SPOKEN DIALOGUE. AI speech is unreliable. Default to visual storytelling with ambient sound: festive music feel, shop ambience, product beauty, genuine smiles and gestures (welcoming namaste, showing a product proudly, happy nod). This style produces the best videos.
- If the user EXPLICITLY wrote a line to be spoken, keep ONE short line only (max 10 words), VERBATIM in double quotes, spoken by ONE person; the other person stays silent and only reacts.
- MAXIMUM 3 physical actions total across the whole video.
- One simple camera instruction only (e.g., static medium shot, or slow push-in). No complex camera moves.

Rules:
- Fix the user's scene logic: characters positioned sensibly (someone entering comes through the door; staff already inside greet naturally), correct tone (a welcome is warm; a question sounds like a question).
- Indian setting, Indian people, realistic details of that business type.
- Festivals: authentic decor (diyas, marigold garlands, rangoli, warm lights).
- NEVER include readable WRITTEN text on screen (signboards with names, phone numbers) - written text renders badly. Spoken dialogue is good.
- No celebrities, no visible brand logos.
- Max 120 words. Reply with the script-prompt only, nothing else.";
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 500,
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

    $quality = in_array($in['quality'] ?? '', ['premium', 'photo']) ? $in['quality'] : 'standard';
    $needCredits = tier_credits($quality);

    $imageUrl = null;
    if ($quality === 'photo') {
        $imageUrl = trim($in['image_url'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $prefix = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/media/';
        if (strpos($imageUrl, $prefix) !== 0) out(['error' => 'Please upload your photo first'], 400);
    }

    $user = null;
    if (!$testMode) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$uid]);
        $user = $st->fetch();
        if (!$user) out(['error' => 'Login required', 'auth' => false], 401);
        if ((int)$user['credits'] < $needCredits) out(['error' => 'Not enough credits (' . $needCredits . ' needed). Please buy a pack.', 'no_credits' => true], 402);
    }

    $finalPrompt = improve_prompt($prompt, $quality);
    $model = tier_model($quality);

    list($code, $res) = fal_call('POST', 'https://queue.fal.run/' . $model, tier_payload($quality, $finalPrompt, $aspect, $imageUrl));
    if (!($code >= 200 && $code < 300 && isset($res['request_id']))) {
        out(['error' => 'Generation submit failed', 'detail' => $res, 'http' => $code], 502);
    }

    $jobId = null;
    if (!$testMode) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?')->execute([$needCredits, $uid, $needCredits]);
            $ins = $pdo->prepare('INSERT INTO jobs (user_id, prompt, used_prompt, aspect, fal_request_id, status, credit_charged) VALUES (?,?,?,?,?,"SUBMITTED",?)');
            $ins->execute([$uid, $prompt, $finalPrompt, $aspect, $res['request_id'], $needCredits]);
            $jobId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); }
    }

    out(['request_id' => $res['request_id'], 'job_id' => $jobId, 'used_prompt' => $finalPrompt, 'quality' => $quality, 'credits_used' => $testMode ? 0 : $needCredits]);
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

    $quality = in_array($_GET['quality'] ?? '', ['premium', 'photo']) ? $_GET['quality'] : 'standard';
    $root = fal_app_root(tier_model($quality));

    list($code, $st) = fal_call('GET', 'https://queue.fal.run/' . $root . '/requests/' . $rid . '/status');
    $status = $st['status'] ?? 'UNKNOWN';

    if ($status === 'COMPLETED') {
        list($c2, $result) = fal_call('GET', 'https://queue.fal.run/' . $root . '/requests/' . $rid);
        $videoUrl = find_video_url($result);
        if ($uid !== null && $videoUrl) {
            $up = db()->prepare('UPDATE jobs SET status = "COMPLETED", video_url = ? WHERE fal_request_id = ? AND user_id = ?');
            $up->execute([$videoUrl, $rid, $uid]);
        }
        out(['status' => 'COMPLETED', 'video_url' => $videoUrl, 'result' => $videoUrl ? null : $result]);
    }

    if (in_array($status, ['FAILED', 'ERROR'])) {
        if ($uid !== null) {
            // refund charged credits once
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('SELECT * FROM jobs WHERE fal_request_id = ? AND user_id = ? FOR UPDATE');
                $q->execute([$rid, $uid]);
                $job = $q->fetch();
                if ($job && !$job['credit_refunded'] && $job['status'] !== 'FAILED') {
                    $pdo->prepare('UPDATE jobs SET status = "FAILED", credit_refunded = 1 WHERE id = ?')->execute([$job['id']]);
                    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([(int)$job['credit_charged'], $uid]);
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
