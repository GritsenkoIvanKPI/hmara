<?php
/**
 * Contact form endpoint — receives a submission from index.html and
 * forwards it to Telegram.
 *
 * The bot token lives in config.php (git-ignored, never sent to the
 * browser). The page only ever talks to this script.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Send a JSON response and stop. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- method ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

/* ---------- config ---------- */

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    error_log('send-form: config.php is missing');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$config = require $configPath;
$token  = trim((string)($config['bot_token'] ?? ''));
$chatId = trim((string)($config['chat_id'] ?? ''));

if ($token === '' || $chatId === '' || str_starts_with($token, 'PASTE')) {
    error_log('send-form: bot_token or chat_id not filled in');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

/* ---------- input ---------- */

$raw   = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

/** Trim, collapse whitespace and cap a field's length. */
function field(array $src, string $key, int $max): string
{
    $value = (string)($src[$key] ?? '');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
    $value = trim($value);
    return mb_substr($value, 0, $max);
}

$name    = field($input, 'name', 120);
$phone   = field($input, 'phone', 40);
$email   = field($input, 'email', 160);
$type    = field($input, 'type', 80);
$message = field($input, 'message', 2000);
$gdpr    = !empty($input['gdpr']);
$trap    = field($input, 'website', 200); // honeypot: real people leave it empty

/* ---------- spam trap ---------- */

// A bot filling every field gets a success response and nothing is sent,
// so it has no signal to retry against.
if ($trap !== '') {
    respond(200, ['ok' => true]);
}

/* ---------- validation ---------- */

$errors = [];

if (mb_strlen($name) < 2) {
    $errors['name'] = 'required';
}

// Czech numbers with or without prefix, spaces and separators allowed.
$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
if (strlen($phoneDigits) < 9) {
    $errors['phone'] = 'invalid';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'invalid';
}

if (!$gdpr) {
    $errors['gdpr'] = 'required';
}

// Header-injection guard: these fields end up in an e-mail subject/body too.
if (preg_match('/[\r\n]/', $name . $phone . $email)) {
    $errors['name'] = 'invalid';
}

if ($errors) {
    respond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $errors]);
}

/* ---------- rate limit ---------- */

$limit = (int)($config['rate_limit_per_hour'] ?? 5);
if ($limit > 0) {
    $ip      = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket  = sys_get_temp_dir() . '/hmara-form-' . hash('sha256', $ip) . '.txt';
    $now     = time();
    $hits    = [];

    if (is_file($bucket)) {
        $stored = json_decode((string)file_get_contents($bucket), true);
        if (is_array($stored)) {
            // keep only the hits from the last hour
            $hits = array_values(array_filter(
                $stored,
                static fn($t) => is_int($t) && $t > $now - 3600
            ));
        }
    }

    if (count($hits) >= $limit) {
        respond(429, ['ok' => false, 'error' => 'rate_limited']);
    }

    $hits[] = $now;
    @file_put_contents($bucket, json_encode($hits), LOCK_EX);
}

/* ---------- compose ---------- */

/** Escape for Telegram's HTML parse mode. */
function tg(string $text): string
{
    return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lines = [];
$lines[] = '<b>Nová poptávka z webu</b>';
$lines[] = '';
$lines[] = '<b>Jméno:</b> ' . tg($name);
$lines[] = '<b>Telefon:</b> ' . tg($phone);

if ($email !== '') {
    $lines[] = '<b>E-mail:</b> ' . tg($email);
}
if ($type !== '') {
    $lines[] = '<b>Typ projektu:</b> ' . tg($type);
}
if ($message !== '') {
    $lines[] = '';
    $lines[] = '<b>Popis:</b>';
    $lines[] = tg($message);
}

$lines[] = '';
$lines[] = '<i>' . tg(date('j.n.Y H:i')) . '</i>';

$text = implode("\n", $lines);

/* ---------- send ---------- */

$payload = [
    'chat_id'                  => $chatId,
    'text'                     => $text,
    'parse_mode'               => 'HTML',
    'disable_web_page_preview' => true,
];

$ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    // Log the reason for the operator, but never echo it — the API error
    // text can quote the request and we do not want the token anywhere
    // near a browser response.
    error_log('send-form: telegram failed, http ' . $httpCode . ' ' . $curlErr . ' ' . substr((string)$response, 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

$result = json_decode((string)$response, true);
if (!is_array($result) || empty($result['ok'])) {
    error_log('send-form: telegram rejected the message: ' . substr((string)$response, 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

/* ---------- optional e-mail copy ---------- */

$notify = trim((string)($config['notify_email'] ?? ''));
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $body = "Jméno: $name\nTelefon: $phone\nE-mail: $email\nTyp projektu: $type\n\n$message\n";
    @mail(
        $notify,
        'Nová poptávka z webu',
        $body,
        "Content-Type: text/plain; charset=UTF-8\r\nFrom: web@" . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    );
}

respond(200, ['ok' => true]);
