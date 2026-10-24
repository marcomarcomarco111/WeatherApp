<?php
// router pre vývojový server: php -S localhost:8000 router.php
// zablokuje skryté súbory (.env, .git, ...) a samotný router
$cesta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#(^|/)\.|^/router\.php$#', $cesta)) {
    http_response_code(404);
    exit;
}

return false; // ostatné súbory obslúži server normálne
