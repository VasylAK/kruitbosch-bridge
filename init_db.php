<?php
/**
 * Inicjalizacja lokalnej bazy SQLite na potrzeby cache'owania tłumaczeń DeepL.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$baseDir = __DIR__;
$dbFile = $baseDir . '/deepl_cache.db';
$logDir = $baseDir . '/logs';
$logFile = $logDir . '/error.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
        CREATE TABLE IF NOT EXISTS translations_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hash_md5 VARCHAR(32) UNIQUE NOT NULL,
            translated_text TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_hash ON translations_cache(hash_md5);
    ";
    
    $pdo->exec($sql);
    echo "SUKCES: Baza danych SQLite została pomyślnie utworzona: " . $dbFile . "\n";

} catch (PDOException $e) {
    $errorMsg = date('[Y-m-d H:i:s] ') . "Błąd bazy danych (DB Init): " . $e->getMessage() . "\n";
    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    echo "BŁĄD KRYTYCZNY: Nie udało się utworzyć bazy danych. Szczegóły w logs/error.log\n";
}