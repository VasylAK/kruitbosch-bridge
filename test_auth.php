<?php
/**
 * Skrypt testujący autoryzację z API Kruitbosch.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/KruitboschAuth.php';

$configFile = __DIR__ . '/config.json';

if (!file_exists($configFile)) {
    die("BŁĄD KRYTYCZNY: Brak pliku config.json w katalogu: " . __DIR__ . "\n");
}

$jsonContent = file_get_contents($configFile);
$config = json_decode($jsonContent, true);

// Walidacja poprawności pliku JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    die("BŁĄD PARSOWANIA CONFIG.JSON: " . json_last_error_msg() . "\nSprawdź składnię w pliku config.json.\n");
}

try {
    echo "Inicjalizacja modułu autoryzacji...\n";
    $auth = new KruitboschAuth($config);
    
    $token = $auth->getValidToken();
    
    echo "SUKCES! Pobrano token sesyjny Kruitbosch:\n";
    echo $token . "\n";
    echo "Token został zapisany w pliku token_cache.json i jest ważny przez 24h.\n";

} catch (Throwable $e) {
    echo "BŁĄD: " . $e->getMessage() . "\n";
}