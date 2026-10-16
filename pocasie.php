<?php
header('Content-Type: application/json; charset=utf-8');

// kľúč z .env
$env = parse_ini_file(__DIR__ . '/.env');
$kluc = $env['OWM_KEY'] ?? null;

if (!$kluc) {
    http_response_code(500);
    echo json_encode(['chyba' => 'Chýba API kľúč v .env']);
    exit;
}

// mesto z URL (?mesto=Nitra), predvolene Nitra
$mesto = trim($_GET['mesto'] ?? 'Nitra');

$url = "https://api.openweathermap.org/data/2.5/weather?q="
     . urlencode($mesto)
     . "&units=metric&lang=sk&appid=" . $kluc;

// ignore_errors: aby sme dostali odpoveď aj pri 404/401
$ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
$odpoved = file_get_contents($url, false, $ctx);
$data = json_decode($odpoved, true);

// OpenWeatherMap vracia pri chybe cod != 200
if (!$data || (int)($data['cod'] ?? 0) !== 200) {
    http_response_code(404);
    echo json_encode(['chyba' => 'Mesto sa nenašlo alebo je problém s kľúčom']);
    exit;
}

echo json_encode([
    'mesto'   => $data['name'],
    'teplota' => round($data['main']['temp'], 1),
    'pocitova' => round($data['main']['feels_like'], 1),
    'vlhkost' => $data['main']['humidity'],
    'popis'   => $data['weather'][0]['description']
]);