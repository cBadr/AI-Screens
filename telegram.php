<?php
/**
 * AI Screens — Telegram Notifier
 * --------------------------------------------------------
 * Receives a visitor-intel payload from the frontend, enriches
 * it with server-side data (real IP, geo lookup, headers), and
 * forwards a rich HTML message to Telegram.
 *
 * Endpoint: POST /telegram.php
 * Body (JSON): { "event": "Page Visit" | "Download Click: ...", "client": { ...optional client-side intel... } }
 */

// ===================== CONFIG =====================
const BOT_TOKEN = '8772249847:AAHaODRYrbTYb_y5T-4xDk79pOd_DKUdjq8';
const CHAT_ID   = '-1003980462985';

// Anti-spam: limit one notification per IP+event every N seconds
const RATE_LIMIT_SECONDS = 30;
const RATE_DIR = __DIR__ . '/.tg_cache';

// ===================== HEADERS =====================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ===================== INPUT =====================
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$event  = isset($body['event'])  ? substr(trim((string)$body['event']),  0, 120) : 'Page Visit';
$client = isset($body['client']) && is_array($body['client']) ? $body['client'] : [];

// ===================== SERVER-SIDE INTEL =====================
function realIP(): string {
    $candidates = [
        'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'
    ];
    foreach ($candidates as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function httpGet(string $url, int $timeout = 5): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (AIScreens/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($code >= 200 && $code < 300 && $resp) ? $resp : null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout, 'ignore_errors' => true,
        'header' => "User-Agent: Mozilla/5.0 (AIScreens/1.0)\r\nAccept: application/json\r\n"
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp ?: null;
}

function fetchGeo(string $ip): array {
    if ($ip === '0.0.0.0' || $ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) return [];

    // Provider 1: ip-api.com (no key, generous limits, server-side friendly)
    $json = httpGet("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,asname,currency,query");
    if ($json) {
        $d = json_decode($json, true);
        if (is_array($d) && ($d['status'] ?? '') === 'success') {
            return [
                'country_name'  => $d['country']     ?? null,
                'country_code'  => $d['countryCode'] ?? null,
                'region'        => $d['regionName']  ?? ($d['region'] ?? null),
                'city'          => $d['city']        ?? null,
                'postal'        => $d['zip']         ?? null,
                'latitude'      => $d['lat']         ?? null,
                'longitude'     => $d['lon']         ?? null,
                'timezone'      => $d['timezone']    ?? null,
                'org'           => $d['isp']         ?? ($d['org'] ?? null),
                'asn'           => $d['as']          ?? ($d['asname'] ?? null),
                'currency'      => $d['currency']    ?? null,
            ];
        }
    }

    // Provider 2: ipwho.is (HTTPS, free, no key)
    $json = httpGet("https://ipwho.is/{$ip}");
    if ($json) {
        $d = json_decode($json, true);
        if (is_array($d) && ($d['success'] ?? false)) {
            return [
                'country_name'  => $d['country']           ?? null,
                'country_code'  => $d['country_code']      ?? null,
                'region'        => $d['region']            ?? null,
                'city'          => $d['city']              ?? null,
                'postal'        => $d['postal']            ?? null,
                'latitude'      => $d['latitude']          ?? null,
                'longitude'     => $d['longitude']         ?? null,
                'timezone'      => $d['timezone']['id']    ?? null,
                'org'           => $d['connection']['isp'] ?? null,
                'asn'           => isset($d['connection']['asn']) ? ('AS' . $d['connection']['asn']) : null,
                'currency'      => $d['currency']['code']  ?? null,
            ];
        }
    }

    // Provider 3: ipapi.co (last resort — often rate-limited)
    $json = httpGet("https://ipapi.co/{$ip}/json/");
    if ($json) {
        $d = json_decode($json, true);
        if (is_array($d) && empty($d['error'])) return $d;
    }

    return [];
}

function rateLimited(string $ip, string $event): bool {
    if (!is_dir(RATE_DIR)) @mkdir(RATE_DIR, 0700, true);
    $key = RATE_DIR . '/' . md5($ip . '|' . $event) . '.txt';
    if (file_exists($key) && (time() - filemtime($key)) < RATE_LIMIT_SECONDS) return true;
    @file_put_contents($key, '1');
    return false;
}

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function countryFlag(string $cc): string {
    $cc = strtoupper(substr($cc, 0, 2));
    if (strlen($cc) !== 2) return '🌍';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
}

$ip = realIP();
if (rateLimited($ip, $event)) {
    echo json_encode(['ok' => true, 'skipped' => 'rate_limited']);
    exit;
}

$geo = fetchGeo($ip);
$ua  = $_SERVER['HTTP_USER_AGENT']      ?? '';
$ref = $_SERVER['HTTP_REFERER']         ?? '';
$acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
$host = $_SERVER['HTTP_HOST']           ?? '';
$method = $_SERVER['REQUEST_METHOD']    ?? '';
$proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'HTTPS' : 'HTTP';

// ===================== MESSAGE =====================
$flag = countryFlag($geo['country_code'] ?? '');
$evIcon = stripos($event, 'download') !== false ? '⬇️' : '👀';

$lines = [];
$lines[] = "{$evIcon} <b>AI Screens — " . esc($event) . "</b> {$flag}";
$lines[] = "🕐 <b>UTC:</b> " . gmdate('Y-m-d H:i:s');
$lines[] = "";

// ---- Network / Geo ----
$lines[] = "🌐 <b>IP:</b> <code>" . esc($ip) . "</code>";
if ($geo) {
    $lines[] = "🏳️ <b>Country:</b> " . esc(($geo['country_name'] ?? '?') . ' (' . ($geo['country_code'] ?? '?') . ')');
    $lines[] = "🏙️ <b>City / Region:</b> " . esc(($geo['city'] ?? '?') . ' / ' . ($geo['region'] ?? '?'));
    if (!empty($geo['postal']))   $lines[] = "📮 <b>Postal:</b> " . esc($geo['postal']);
    if (isset($geo['latitude']))  $lines[] = "📍 <b>Coords:</b> " . esc($geo['latitude'] . ', ' . $geo['longitude']) . " (<a href=\"https://maps.google.com/?q={$geo['latitude']},{$geo['longitude']}\">map</a>)";
    if (!empty($geo['org']))      $lines[] = "🛰️ <b>ISP:</b> " . esc($geo['org']);
    if (!empty($geo['asn']))      $lines[] = "🔌 <b>ASN:</b> " . esc($geo['asn']);
    if (!empty($geo['timezone'])) $lines[] = "⏰ <b>IP Timezone:</b> " . esc($geo['timezone']);
    if (!empty($geo['currency']))$lines[] = "💱 <b>Currency:</b> " . esc($geo['currency']);
}
$lines[] = "";

// ---- Request ----
$fullUrl = $proto === 'HTTPS' ? 'https' : 'http';
$fullUrl .= '://' . $host . ($_SERVER['REQUEST_URI'] ?? '');
$lines[] = "🔗 <b>Visited URL:</b> " . esc($client['url'] ?? $fullUrl);
$lines[] = "↩️ <b>Referrer:</b> " . esc($client['referrer'] ?? ($ref ?: '(direct)'));
if (!empty($client['utm']))      $lines[] = "🎯 <b>UTM:</b> <code>" . esc($client['utm']) . "</code>";
$lines[] = "📨 <b>Protocol/Method:</b> {$proto} / " . esc($method);
$lines[] = "";

// ---- Client Device ----
if ($client) {
    if (!empty($client['platform']))            $lines[] = "💻 <b>Platform:</b> " . esc($client['platform']) . (!empty($client['vendor']) ? ' · ' . esc($client['vendor']) : '');
    if (!empty($client['screen']))              $lines[] = "🖥️ <b>Screen:</b> " . esc($client['screen']);
    if (!empty($client['viewport']))            $lines[] = "📐 <b>Viewport:</b> " . esc($client['viewport']) . ' (DPR ' . esc($client['devicePixelRatio'] ?? '?') . ')';
    if (!empty($client['gpu']))                 $lines[] = "🎨 <b>GPU:</b> " . esc($client['gpu']);
    if (!empty($client['language']))            $lines[] = "🌍 <b>Language:</b> " . esc($client['language']) . (!empty($client['languages']) ? ' (' . esc($client['languages']) . ')' : '');
    if (!empty($client['timezone']))            $lines[] = "🕓 <b>Browser TZ:</b> " . esc($client['timezone']) . ' (offset ' . esc($client['timezoneOffset'] ?? '?') . ')';
    if (!empty($client['localTime']))           $lines[] = "📅 <b>Local Time:</b> " . esc($client['localTime']);
    if (isset($client['hardwareConcurrency'])) $lines[] = "⚙️ <b>CPU cores:</b> " . esc($client['hardwareConcurrency']) . " · <b>RAM:</b> " . esc($client['deviceMemory'] ?? 'n/a') . "GB";
    if (!empty($client['connection']))          $lines[] = "📡 <b>Connection:</b> " . esc($client['connection']);
    if (!empty($client['battery']))             $lines[] = "🔋 <b>Battery:</b> " . esc($client['battery']);
    if (!empty($client['storage']))             $lines[] = "💾 <b>Storage:</b> " . esc($client['storage']);
    if (isset($client['maxTouchPoints']))      $lines[] = "👆 <b>Touch points:</b> " . esc($client['maxTouchPoints']);
    if (isset($client['cookieEnabled']))       $lines[] = "🍪 <b>Cookies:</b> " . ($client['cookieEnabled'] ? 'on' : 'off') . " · <b>DNT:</b> " . esc($client['doNotTrack'] ?? 'no');
    $lines[] = "";
}

// ---- Headers ----
if ($acceptLang) $lines[] = "🗣️ <b>Accept-Language:</b> " . esc($acceptLang);
$lines[] = "🧬 <b>User Agent:</b>";
$lines[] = "<code>" . esc($client['userAgent'] ?? $ua) . "</code>";

$text = implode("\n", $lines);

// Telegram caps messages at 4096 chars
if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . "\n…(truncated)";

// ===================== SEND =====================
function sendTelegram(string $text): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
    $payload = http_build_query([
        'chat_id' => CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 6,
            'ignore_errors' => true,
        ]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return ['raw' => $resp];
}

if (BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE' || CHAT_ID === 'YOUR_CHAT_ID_HERE') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Telegram credentials not configured']);
    exit;
}

$result = sendTelegram($text);
echo json_encode(['ok' => true]);
