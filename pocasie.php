<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// chyby len do logu, nie do odpovede (prezradili by cesty k súborom)
ini_set('display_errors', '0');

// kľúč z .env
$env = parse_ini_file(__DIR__ . '/.env');
$kluc = $env['OWM_KEY'] ?? null;

if (!$kluc) {
    http_response_code(500);
    echo json_encode(['chyba' => 'Chýba API kľúč v .env']);
    exit;
}

// mesto z URL (?mesto=Nitra), predvolene Nitra
$mesto = $_GET['mesto'] ?? 'Nitra';

// povolíme len text do 100 znakov: písmená (aj s diakritikou), medzery a . , ' -
// is_string zachytí ?mesto[]=x, /u odmietne neplatné UTF-8
if (!is_string($mesto) || !preg_match("/^[\p{L}\p{M} .,'\-]{1,100}$/u", $mesto = trim($mesto))) {
    http_response_code(400);
    echo json_encode(['chyba' => 'Neplatný názov mesta']);
    exit;
}

// ignore_errors: aby sme dostali odpoveď aj pri 404/401
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 8]]);

function stiahni($url, $ctx) {
    $odpoved = @file_get_contents($url, false, $ctx);
    return $odpoved ? json_decode($odpoved, true) : null;
}

$zaklad = "https://api.openweathermap.org/data/2.5/";
$parametre = "?q=" . urlencode($mesto) . "&units=metric&lang=sk&appid=" . $kluc;

// aktuálne počasie
$data = stiahni($zaklad . "weather" . $parametre, $ctx);

// OpenWeatherMap vracia pri chybe cod != 200
if (!$data || (int)($data['cod'] ?? 0) !== 200) {
    http_response_code(404);
    echo json_encode(['chyba' => 'Mesto sa nenašlo alebo je problém s kľúčom']);
    exit;
}

$lat = (float)$data['coord']['lat'];
$lon = (float)$data['coord']['lon'];
$posun = $data['timezone']; // posun od UTC v sekundách

// predpoveď na 5 dní po 3 hodinách -> zoskupíme po dňoch
$predpoved = stiahni($zaklad . "forecast" . $parametre, $ctx);
$dni = [];
$dnes = gmdate('Y-m-d', time() + $posun);

foreach ($predpoved['list'] ?? [] as $p) {
    $den = gmdate('Y-m-d', $p['dt'] + $posun);
    if ($den === $dnes) continue;

    $hodina = (int)gmdate('G', $p['dt'] + $posun);
    if (!isset($dni[$den])) {
        $dni[$den] = ['datum' => $den, 'min' => INF, 'max' => -INF, 'zrazky' => 0, 'ikona' => null, 'popis' => null, 'odchylka' => 99];
    }
    $d = &$dni[$den];
    $d['min'] = min($d['min'], $p['main']['temp_min']);
    $d['max'] = max($d['max'], $p['main']['temp_max']);
    $d['zrazky'] = max($d['zrazky'], $p['pop'] ?? 0);

    // ikonu berieme z merania najbližšie k poludniu
    if (abs($hodina - 13) < $d['odchylka']) {
        $d['odchylka'] = abs($hodina - 13);
        $d['ikona'] = str_replace('n', 'd', $p['weather'][0]['icon']);
        $d['popis'] = $p['weather'][0]['description'];
    }
    unset($d);
}

$dni = array_map(fn($d) => [
    'datum'  => $d['datum'],
    'min'    => round($d['min']),
    'max'    => round($d['max']),
    'zrazky' => round($d['zrazky'] * 100),
    'ikona'  => $d['ikona'],
    'popis'  => $d['popis'],
], array_slice(array_values($dni), 0, 4));

// UV index – OpenWeatherMap ho zadarmo nedáva, berieme z Open-Meteo (bez kľúča)
$uv = stiahni("https://api.open-meteo.com/v1/forecast?latitude=$lat&longitude=$lon"
    . "&current=uv_index&daily=uv_index_max&timezone=auto&forecast_days=1", $ctx);

echo json_encode([
    'mesto'     => $data['name'],
    'krajina'   => $data['sys']['country'] ?? '',
    'teplota'   => round($data['main']['temp'], 1),
    'pocitova'  => round($data['main']['feels_like'], 1),
    'min'       => round($data['main']['temp_min']),
    'max'       => round($data['main']['temp_max']),
    'vlhkost'   => $data['main']['humidity'],
    'tlak'      => $data['main']['pressure'],
    'viditelnost' => isset($data['visibility']) ? round($data['visibility'] / 1000, 1) : null,
    'oblacnost' => $data['clouds']['all'] ?? 0,
    'vietor'    => round(($data['wind']['speed'] ?? 0) * 3.6), // m/s -> km/h
    'narazy'    => isset($data['wind']['gust']) ? round($data['wind']['gust'] * 3.6) : null,
    'smer'      => $data['wind']['deg'] ?? 0,
    'vychod'    => $data['sys']['sunrise'],
    'zapad'     => $data['sys']['sunset'],
    'posun'     => $posun,
    'cas'       => $data['dt'],
    'popis'     => $data['weather'][0]['description'],
    'typ'       => $data['weather'][0]['id'],
    'ikona'     => $data['weather'][0]['icon'],
    'uv'        => isset($uv['current']['uv_index']) ? round($uv['current']['uv_index'], 1) : null,
    'uv_max'    => isset($uv['daily']['uv_index_max'][0]) ? round($uv['daily']['uv_index_max'][0], 1) : null,
    'dni'       => $dni,
], JSON_UNESCAPED_UNICODE);
