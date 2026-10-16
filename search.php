<?php
/**
 * T1M — серверный мета-поиск.
 * GET /search.php?q=текст  ->  JSON { query, answer, engines[] }
 *
 * Требования: PHP 7.4+ с расширениями curl, dom, mbstring (на обычном хостинге есть).
 * Положите рядом с index.html. Папка для кэша создаётся автоматически (см. CACHE_DIR).
 */

// ===================== НАСТРОЙКИ =====================
const CACHE_DIR        = __DIR__ . '/.search_cache'; // кэш ответов
const CACHE_TTL        = 600;                        // секунд (10 минут)
const RATE_LIMIT       = 20;                         // запросов в минуту с одного IP
const TIMEOUT          = 8;                          // таймаут на каждую поисковую систему, сек
const ALLOWED_ORIGIN   = '';                         // если сайт на другом домене: 'https://ваш-сайт.ru'

// ОФИЦИАЛЬНЫЕ API (необязательно, но намного надёжнее скрейпинга).
// Google Programmable Search: https://programmablesearchengine.google.com  + ключ в Google Cloud
const GOOGLE_API_KEY   = '';
const GOOGLE_CX        = '';
// Yandex Search API (Yandex Cloud): https://yandex.cloud/ru/docs/search-api/
const YANDEX_FOLDER_ID = '';
const YANDEX_API_KEY   = '';

// Если у сервера есть российский/китайский выход, Baidu/Yandex часто отдают капчу.
// Прокси (необязательно): 'http://user:pass@host:port'
const PROXY            = '';
// =====================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (ALLOWED_ORIGIN !== '') {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 300) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_query'], JSON_UNESCAPED_UNICODE);
    exit;
}

@mkdir(CACHE_DIR, 0755, true);

// ---------- простой rate limit по IP ----------
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'x';
$rlf  = CACHE_DIR . '/rl_' . md5($ip) . '.json';
$now  = time();
$hits = [];
if (is_file($rlf)) {
    $hits = json_decode((string)@file_get_contents($rlf), true) ?: [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 60));
}
if (count($hits) >= RATE_LIMIT) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
    exit;
}
$hits[] = $now;
@file_put_contents($rlf, json_encode($hits), LOCK_EX);

// ---------- кэш ----------
$cf = CACHE_DIR . '/q_' . md5(mb_strtolower($q)) . '.json';
if (is_file($cf) && filemtime($cf) > $now - CACHE_TTL) {
    readfile($cf);
    exit;
}

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$enc = rawurlencode($q);

// ---------- описание запросов ----------
$requests = [];

// Википедия — надёжный источник «готового ответа»
$requests['wiki'] = [
    'url' => 'https://ru.wikipedia.org/w/api.php?action=query&format=json&generator=search&gsrlimit=1'
           . '&gsrsearch=' . $enc . '&prop=extracts|info&exintro=1&explaintext=1&exsentences=4&inprop=url&redirects=1',
    'headers' => ['Accept: application/json'],
];

// Google: официальный API, если задан ключ; иначе скрейпинг (часто блокируется)
if (GOOGLE_API_KEY !== '' && GOOGLE_CX !== '') {
    $requests['google'] = [
        'url' => 'https://www.googleapis.com/customsearch/v1?key=' . rawurlencode(GOOGLE_API_KEY)
               . '&cx=' . rawurlencode(GOOGLE_CX) . '&q=' . $enc . '&hl=ru&num=8',
        'headers' => ['Accept: application/json'],
    ];
} else {
    $requests['google'] = ['url' => 'https://www.google.com/search?q=' . $enc . '&hl=ru&num=10&gbv=1'];
}

// Yandex: Search API (XML) если задан ключ; иначе скрейпинг
if (YANDEX_FOLDER_ID !== '' && YANDEX_API_KEY !== '') {
    $requests['yandex'] = [
        'url' => 'https://yandex.com/search/xml?folderid=' . rawurlencode(YANDEX_FOLDER_ID)
               . '&apikey=' . rawurlencode(YANDEX_API_KEY) . '&query=' . $enc . '&l10n=ru&sortby=rlv&groupby=attr%3D%22%22.mode%3Dflat.groups-on-page%3D8.docs-in-group%3D1',
    ];
} else {
    $requests['yandex'] = ['url' => 'https://yandex.ru/search/?text=' . $enc . '&lr=213'];
}

$requests['baidu'] = ['url' => 'https://www.baidu.com/s?wd=' . $enc . '&rn=10'];
$requests['bing']  = ['url' => 'https://www.bing.com/search?q=' . $enc . '&setlang=ru&count=10'];
$requests['mailru'] = ['url' => 'https://go.mail.ru/search?q=' . $enc];

// ---------- параллельная загрузка ----------
$mh = curl_multi_init();
$handles = [];
foreach ($requests as $name => $r) {
    $ch = curl_init($r['url']);
    $headers = $r['headers'] ?? [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ru-RU,ru;q=0.9,en;q=0.5',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => $UA,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',          // gzip/deflate
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    if (PROXY !== '') {
        curl_setopt($ch, CURLOPT_PROXY, PROXY);
    }
    curl_multi_add_handle($mh, $ch);
    $handles[$name] = $ch;
}
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status === CURLM_OK);

$bodies = [];
foreach ($handles as $name => $ch) {
    $bodies[$name] = [
        'body' => (string)curl_multi_getcontent($ch),
        'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'err'  => curl_error($ch),
    ];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

// ---------- утилиты парсинга ----------
function clean(string $s): string {
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function dom_from(string $html): ?DOMXPath {
    if ($html === '') return null;
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($d);
}

function is_blocked(string $body): bool {
    return (bool)preg_match('/captcha|showcaptcha|unusual traffic|enablejs|Подтвердите, что запросы|robot/i', mb_substr($body, 0, 20000))
        && !preg_match('/class="b_algo"|class="result|<h3/i', $body);
}

function text_of(?DOMNode $n): string {
    return $n ? clean($n->textContent) : '';
}

function unwrap_url(string $href): string {
    // Google: /url?q=...&sa=
    if (preg_match('#^/url\?(?:.*&)?q=([^&]+)#', $href, $m)) {
        return urldecode($m[1]);
    }
    // Bing: /ck/a?...&u=a1aHR0cHM6Ly9...
    if (preg_match('#bing\.com/ck/a\?.*[?&]u=a1([A-Za-z0-9_\-]+)#', $href, $m)) {
        $b = strtr($m[1], '-_', '+/');
        $dec = base64_decode($b . str_repeat('=', (4 - strlen($b) % 4) % 4), true);
        if ($dec && preg_match('#^https?://#', $dec)) return $dec;
    }
    return $href;
}

function host_of(string $url): string {
    return strtolower((string)parse_url($url, PHP_URL_HOST));
}

/** Универсальный разбор: блоки-результаты по XPath, заголовок+ссылка+описание. */
function parse_blocks(DOMXPath $x, string $blockXp, string $linkXp, string $snipXp, array $skipHosts): array {
    $items = [];
    $seen = [];
    foreach ($x->query($blockXp) as $block) {
        $a = $x->query($linkXp, $block)->item(0);
        if (!$a) continue;
        $href = unwrap_url(trim($a->getAttribute('href')));
        $title = text_of($a);
        if (!preg_match('#^https?://#i', $href) || mb_strlen($title) < 4) continue;
        $h = host_of($href);
        foreach ($skipHosts as $sk) {
            if ($h === $sk || str_ends_with($h, '.' . $sk)) continue 2;
        }
        if (isset($seen[$href])) continue;
        $seen[$href] = 1;
        $snip = '';
        if ($snipXp !== '') {
            $s = $x->query($snipXp, $block)->item(0);
            $snip = text_of($s);
        }
        $items[] = ['title' => mb_substr($title, 0, 160), 'url' => $href, 'snippet' => mb_substr($snip, 0, 300)];
        if (count($items) >= 6) break;
    }
    return $items;
}

/** Запасной вариант: любые внешние ссылки с осмысленным текстом. */
function parse_generic(DOMXPath $x, array $skipHosts): array {
    $items = [];
    $seen = [];
    foreach ($x->query('//a[@href]') as $a) {
        $href = unwrap_url(trim($a->getAttribute('href')));
        if (!preg_match('#^https?://#i', $href)) continue;
        $title = text_of($a);
        if (mb_strlen($title) < 15 || mb_strlen($title) > 160) continue;
        $h = host_of($href);
        foreach ($skipHosts as $sk) {
            if ($h === $sk || str_ends_with($h, '.' . $sk)) continue 2;
        }
        if (isset($seen[$href])) continue;
        $seen[$href] = 1;
        $items[] = ['title' => $title, 'url' => $href, 'snippet' => ''];
        if (count($items) >= 6) break;
    }
    return $items;
}

// ---------- разбор каждой системы ----------
$engines = [];
$answer = null;

// Википедия
$w = json_decode($bodies['wiki']['body'], true);
if (!empty($w['query']['pages'])) {
    $page = reset($w['query']['pages']);
    $extract = trim((string)($page['extract'] ?? ''));
    if ($extract !== '' && mb_strlen($extract) > 40 && !preg_match('/может означать|значения:/iu', $extract)) {
        $answer = [
            'text'   => mb_substr($extract, 0, 900),
            'source' => 'Википедия · ' . ($page['title'] ?? ''),
            'url'    => $page['fullurl'] ?? '',
        ];
    }
}

// Google
$g = ['name' => 'Google', 'status' => 'ok', 'items' => []];
if (GOOGLE_API_KEY !== '') {
    $j = json_decode($bodies['google']['body'], true);
    foreach (($j['items'] ?? []) as $it) {
        $g['items'][] = [
            'title' => (string)($it['title'] ?? ''),
            'url' => (string)($it['link'] ?? ''),
            'snippet' => clean((string)($it['snippet'] ?? '')),
        ];
    }
    if (isset($j['error'])) $g['status'] = 'error';
} else {
    $b = $bodies['google']['body'];
    if (is_blocked($b)) {
        $g['status'] = 'blocked';
    } elseif (($x = dom_from($b))) {
        // gbv=1 (базовая HTML-версия): ссылки вида /url?q=...
        $g['items'] = parse_blocks($x, '//div[.//a[starts-with(@href,"/url?q=")]][.//h3]', './/a[starts-with(@href,"/url?q=")][1]', './/div[contains(@class,"BNeawe") and not(.//h3)][last()]', ['google.com', 'googleusercontent.com']);
        if (!$g['items']) $g['items'] = parse_generic($x, ['google.com', 'googleusercontent.com', 'gstatic.com']);
    }
}
$engines[] = $g;

// Yandex
$yx = ['name' => 'Yandex', 'status' => 'ok', 'items' => []];
$b = $bodies['yandex']['body'];
if (YANDEX_API_KEY !== '') {
    if (preg_match_all('#<doc>.*?<url>(.*?)</url>.*?<title>(.*?)</title>.*?(?:<passages>(.*?)</passages>)?.*?</doc>#s', $b, $mm, PREG_SET_ORDER)) {
        foreach (array_slice($mm, 0, 6) as $m) {
            $yx['items'][] = ['title' => clean($m[2]), 'url' => html_entity_decode($m[1]), 'snippet' => clean($m[3] ?? '')];
        }
    }
} elseif (is_blocked($b) || stripos($b, 'showcaptcha') !== false || $bodies['yandex']['code'] === 429) {
    $yx['status'] = 'blocked';
} elseif (($x = dom_from($b))) {
    $yx['items'] = parse_blocks($x, '//li[contains(@class,"serp-item")]', './/a[contains(@class,"OrganicTitle-Link") or .//h2][1]', './/*[contains(@class,"OrganicText") or contains(@class,"TextContainer")][1]', ['yandex.ru', 'yandex.com', 'ya.ru']);
    if (!$yx['items']) $yx['items'] = parse_generic($x, ['yandex.ru', 'yandex.com', 'ya.ru', 'yastatic.net']);
}
$engines[] = $yx;

// Baidu
$bd = ['name' => 'Baidu', 'status' => 'ok', 'items' => []];
$b = $bodies['baidu']['body'];
if (is_blocked($b) || stripos($b, 'wappass.baidu.com') !== false) {
    $bd['status'] = 'blocked';
} elseif (($x = dom_from($b))) {
    $bd['items'] = parse_blocks($x, '//div[contains(@class,"result")][.//h3]', './/h3//a[1]', './/*[contains(@class,"c-abstract") or contains(@class,"content-right") or contains(@class,"c-span-last")][1]', []);
    if (!$bd['items']) $bd['items'] = parse_generic($x, ['baidu.com', 'bdstatic.com']);
}
$engines[] = $bd;

// Bing
$bg = ['name' => 'Bing', 'status' => 'ok', 'items' => []];
$b = $bodies['bing']['body'];
if (($x = dom_from($b))) {
    $bg['items'] = parse_blocks($x, '//li[contains(@class,"b_algo")]', './/h2//a[1]', './/*[contains(@class,"b_caption")]//p[1] | .//p[1]', []);
    if (!$bg['items']) $bg['status'] = is_blocked($b) ? 'blocked' : 'empty';
} else {
    $bg['status'] = 'error';
}
$engines[] = $bg;

// Mail.ru
$ml = ['name' => 'Mail.ru', 'status' => 'ok', 'items' => []];
$b = $bodies['mailru']['body'];
if (($x = dom_from($b))) {
    $ml['items'] = parse_blocks($x, '//li[contains(@class,"result")] | //div[contains(@class,"result")]', './/h3//a[1] | .//a[1]', './/*[contains(@class,"snippet") or contains(@class,"desc")][1]', ['mail.ru', 'go.mail.ru']);
    if (!$ml['items']) $ml['items'] = parse_generic($x, ['mail.ru', 'go.mail.ru', 'imgsmail.ru', 'vk.com']);
    if (!$ml['items']) $ml['status'] = is_blocked($b) ? 'blocked' : 'empty';
} else {
    $ml['status'] = 'error';
}
$engines[] = $ml;

foreach ($engines as &$e) {
    if (!$e['items'] && $e['status'] === 'ok') $e['status'] = 'empty';
}
unset($e);

// ---------- «готовый ответ», если Википедия ничего не дала ----------
if ($answer === null) {
    $best = null;
    foreach ($engines as $e) {
        foreach ($e['items'] as $it) {
            if (mb_strlen($it['snippet']) >= 60 && ($best === null || mb_strlen($it['snippet']) > mb_strlen($best['snippet']))) {
                $best = $it + ['engine' => $e['name']];
            }
        }
    }
    if ($best) {
        $answer = ['text' => $best['snippet'], 'source' => $best['engine'] . ' · ' . host_of($best['url']), 'url' => $best['url']];
    }
}

$out = json_encode([
    'query'   => $q,
    'answer'  => $answer,
    'engines' => $engines,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

@file_put_contents($cf, $out, LOCK_EX);
echo $out;
