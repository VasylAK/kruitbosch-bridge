<?php
/**
 * Skrypt testujący działanie modułu tłumaczeń DeepL (wersja Free) oraz bazy SQLite.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/DeepLTranslator.php';

$configFile = __DIR__ . '/config.json';

if (!file_exists($configFile)) {
    die("BŁĄD: Brak pliku config.json!\n");
}

$config = json_decode(file_get_contents($configFile), true);

if (empty($config['deepl_api']['auth_key'])) {
    die("BŁĄD: Brak klucza API DeepL w pliku config.json (sekcja deepl_api -> auth_key)!\n");
}

try {
    echo "Inicjalizacja modułu tłumaczeń DeepL...\n";
    $translator = new DeepLTranslator($config);

    // Przykładowy tekst w języku holenderskim do przetłumaczenia
    $testText = "Dit is een hoogwaardige waterdichte fietstas, perfect voor dagelijks gebruik.";
    
    echo "\n--- TEST 1: Pierwsze tłumaczenie (Odpytanie API DeepL) ---\n";
    echo "Oryginał (NL): " . $testText . "\n";
    
    // Mierzymy czas wykonania pierwszego zapytania
    $startTime = microtime(true);
    $translatedText = $translator->translate($testText, 'PL', 'NL');
    $endTime = microtime(true);
    
    echo "Tłumaczenie (PL): " . $translatedText . "\n";
    echo "Czas wykonania: " . round($endTime - $startTime, 4) . " sekund\n";

    echo "\n--- TEST 2: Drugie zapytanie (Pobranie z bufora SQLite) ---\n";
    // Mierzymy czas ponownego przetłumaczenia TEGO SAMEGO tekstu
    $startTime2 = microtime(true);
    $translatedText2 = $translator->translate($testText, 'PL', 'NL');
    $endTime2 = microtime(true);
    
    echo "Tłumaczenie (PL): " . $translatedText2 . "\n";
    echo "Czas wykonania: " . round($endTime2 - $startTime2, 4) . " sekund\n";
    
    echo "\n----------------------------------------------\n";
    if (round($endTime2 - $startTime2, 4) < round($endTime - $startTime, 4)) {
        echo "SUKCES! Drugie zapytanie było znacznie szybsze. \nTekst został pobrany z bazy 'deepl_cache.db' bez zużywania darmowego limitu DeepL.\n";
    }

} catch (Throwable $e) {
    echo "BŁĄD KRYTYCZNY: " . $e->getMessage() . "\n";
}