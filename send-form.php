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

// A fatal (missing extension, unparsable config) would otherwise return an
// empty 500 that tells nobody anything. Turn it into JSON and a log line.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('send-form fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
});

/** Send a JSON response and stop. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- self-test ---------- */

// GET /send-form.php?selftest=1 reports whether this server can run the
// endpoint. It never prints the token — only whether one is present.
if (isset($_GET['selftest'])) {
    $cfgPath = __DIR__ . '/config.php';
    $cfg     = is_file($cfgPath) ? @require $cfgPath : null;

    respond(200, [
        'ok'              => true,
        'php'             => PHP_VERSION,
        'php_ok'          => version_compare(PHP_VERSION, '7.4', '>='),
        'config_present'  => is_file($cfgPath),
        'config_is_array' => is_array($cfg),
        'token_present'   => is_array($cfg) && !empty($cfg['bot_token']),
        'token_length'    => is_array($cfg) ? strlen((string)($cfg['bot_token'] ?? '')) : 0,
        'chat_id'         => is_array($cfg) ? (string)($cfg['chat_id'] ?? '') : '',
        'ext_curl'        => function_exists('curl_init'),
        'ext_mbstring'    => function_exists('mb_substr'),
        'ext_json'        => function_exists('json_encode'),
        'can_write_tmp'   => is_writable(sys_get_temp_dir()),
    ]);
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
if (!is_array($config)) {
    error_log('send-form: config.php did not return an array');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$token  = trim((string)($config['bot_token'] ?? ''));
$chatId = trim((string)($config['chat_id'] ?? ''));

if ($token === '' || $chatId === '' || strpos($token, 'PASTE') === 0) {
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
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
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

if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) < 2) {
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
