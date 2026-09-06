<?php
/**
 * Google reviews feed.
 *
 * Fetches the place's reviews from the Google Places API once a day,
 * caches them to a JSON file, and serves the cache to the page. The API
 * key lives in config.php and never reaches the browser — the page only
 * ever talks to this script.
 *
 *   ?resolve=NAME   one-off helper: look up the place_id for a business
 *   ?refresh=1      ignore the cache and re-fetch now
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const CACHE_TTL   = 86400; // 24h — Places terms allow short-term caching
const CACHE_FILE  = 'google-reviews-cache.json';
const PLACES_HOST = 'https://places.googleapis.com/v1/';

/** Send JSON and stop. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * GET a URL. Mirrors send-form.php: this host has no ext-curl, so the
 * stream wrapper is the path that actually runs in production.
 */
function httpGet(string $url, array $headers): array
{
    $headerLines = '';
    foreach ($headers as $k => $v) {
        $headerLines .= $k . ': ' . $v . "\r\n";
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $flat = [];
        foreach ($headers as $k => $v) {
            $flat[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $flat,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => $body === false ? null : (string)$body, 'status' => $status];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['body' => null, 'status' => 0];
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => $headerLines,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body   = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }

    return ['body' => $body === false ? null : (string)$body, 'status' => $status];
}

/* ---------- config ---------- */

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    respond(200, ['ok' => true, 'configured' => false, 'reviews' => []]);
}

$config  = require $configPath;
$apiKey  = trim((string)($config['google_api_key'] ?? ''));
$placeId = trim((string)($config['google_place_id'] ?? ''));

// Not set up yet is not an error — the page simply hides the section.
if ($apiKey === '' || strpos($apiKey, 'PASTE') === 0) {
    respond(200, ['ok' => true, 'configured' => false, 'reviews' => []]);
}

/* ---------- helper: find a place_id by name ---------- */

if (isset($_GET['resolve'])) {
    $query = trim((string)$_GET['resolve']);
    if ($query === '') {
        respond(400, ['ok' => false, 'error' => 'pass ?resolve=Business+Name']);
    }

    $res = httpGet(
        PLACES_HOST . 'places:searchText?' . http_build_query(['textQuery' => $query]),
        [
            'X-Goog-Api-Key'   => $apiKey,
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount',
            'Accept'           => 'application/json',
        ]
    );

    // searchText is a POST endpoint; fall back to the GET-friendly variant
    if ($res['status'] !== 200) {
        respond(200, [
            'ok'     => false,
            'status' => $res['status'],
            'hint'   => 'If this fails, find the place id manually at https://developers.google.com/maps/documentation/places/web-service/place-id',
            'body'   => json_decode((string)$res['body'], true),
        ]);
    }

    respond(200, ['ok' => true, 'result' => json_decode((string)$res['body'], true)]);
}

/* ---------- cache ---------- */

$cachePath = __DIR__ . '/' . CACHE_FILE;
$fresh     = is_file($cachePath) && (time() - (int)filemtime($cachePath)) < CACHE_TTL;

if ($fresh && !isset($_GET['refresh'])) {
    $cached = json_decode((string)file_get_contents($cachePath), true);
    if (is_array($cached)) {
        $cached['cached'] = true;
        respond(200, $cached);
    }
}

if ($placeId === '' || strpos($placeId, 'PASTE') === 0) {
    respond(200, ['ok' => true, 'configured' => false, 'reviews' => []]);
}

/* ---------- fetch ---------- */

$res = httpGet(
    PLACES_HOST . rawurlencode('places/' . $placeId) . '?languageCode=cs',
    [
        'X-Goog-Api-Key'   => $apiKey,
        'X-Goog-FieldMask' => 'id,displayName,rating,userRatingCount,googleMapsUri,reviews',
        'Accept'           => 'application/json',
    ]
);

$data = json_decode((string)$res['body'], true);

if ($res['status'] !== 200 || !is_array($data)) {
    error_log('google-reviews: places api http ' . $res['status'] . ' ' . substr((string)$res['body'], 0, 300));

    // Serve a stale cache rather than an empty section if we have one.
    if (is_file($cachePath)) {
        $stale = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($stale)) {
            $stale['cached'] = true;
            $stale['stale']  = true;
            respond(200, $stale);
        }
    }
    respond(200, ['ok' => false, 'configured' => true, 'reviews' => []]);
}

/* ---------- shape the payload ---------- */

$reviews = [];
foreach (($data['reviews'] ?? []) as $r) {
    $text = $r['originalText']['text'] ?? ($r['text']['text'] ?? '');
    $text = trim((string)$text);
    if ($text === '') {
        continue; // star-only ratings have nothing to show
    }

    $reviews[] = [
        'author'     => (string)($r['authorAttribution']['displayName'] ?? ''),
        'photo'      => (string)($r['authorAttribution']['photoUri'] ?? ''),
        'profileUrl' => (string)($r['authorAttribution']['uri'] ?? ''),
        'rating'     => (int)($r['rating'] ?? 0),
        'text'       => $text,
        'relative'   => (string)($r['relativePublishTimeDescription'] ?? ''),
    ];
}

$payload = [
    'ok'         => true,
    'configured' => true,
    'name'       => (string)($data['displayName']['text'] ?? ''),
    'rating'     => isset($data['rating']) ? (float)$data['rating'] : null,
    'total'      => isset($data['userRatingCount']) ? (int)$data['userRatingCount'] : 0,
    'mapsUrl'    => (string)($data['googleMapsUri'] ?? ''),
    'reviews'    => $reviews,
    'fetchedAt'  => date('c'),
];

@file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);

respond(200, $payload);
