<?php
/**
 * Moduł tłumaczeń DeepL API (wersja Free) z pamięcią podręczną SQLite i autodetekcją języka.
 */
class DeepLTranslator
{
    private string $apiKey;
    private string $apiUrl;
    private PDO $pdo;
    private string $logFile;

    public function __construct(array $config)
    {
        $this->apiKey = $config['deepl_api']['auth_key'] ?? '';
        $this->apiUrl = $config['deepl_api']['url'] ?? 'https://api-free.deepl.com/v2/translate';
        $this->logFile = __DIR__ . '/logs/error.log';
        
        $this->initDatabase();
    }

    private function initDatabase(): void
    {
        $dbPath = __DIR__ . '/deepl_cache.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS translations_cache (
                hash_md5 VARCHAR(32) PRIMARY KEY,
                translated_text TEXT NOT NULL
            )
        ");
    }

    /**
     * Tłumaczy tekst. Jeśli $forceRefresh jest true, omija cache i nadpisuje go nowym wynikiem.
     */
    public function translate(string $text, string $targetLang = 'PL', ?string $sourceLang = null, bool $forceRefresh = false): string
    {
        $text = trim($text);
        if (empty($text)) return '';

        $hash = md5($text . '_' . $targetLang);

        // Jeśli NIE wymuszamy odświeżenia, szukamy w bazie SQLite
        if (!$forceRefresh) {
            try {
                $stmt = $this->pdo->prepare("SELECT translated_text FROM translations_cache WHERE hash_md5 = :hash LIMIT 1");
                $stmt->execute([':hash' => $hash]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row && !empty($row['translated_text'])) {
                    return $row['translated_text']; 
                }
            } catch (PDOException $e) {
                $this->logError("Błąd SQLite: " . $e->getMessage());
            }
        }

        if (empty($this->apiKey) || str_contains($this->apiKey, 'fx') === false) {
            return $text; 
        }

        // 2. Odpytanie DeepL API
        try {
            $postFields = [
                'text'        => $text,
                'target_lang' => $targetLang
            ];
            
            if ($sourceLang !== null) {
                $postFields['source_lang'] = $sourceLang;
            }

            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($postFields),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type: application/x-www-form-urlencoded'
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200) {
                $this->logError("HTTP {$httpCode} | cURL Error: {$error} | Odpowiedź: {$response}");
                return $text; 
            }

            $data = json_decode($response, true);
            $translatedText = $data['translations'][0]['text'] ?? '';

            if (!empty($translatedText)) {
                // 3. Zapis do SQLite z użyciem REPLACE INTO (nadpisuje istniejący hash lub tworzy nowy)
                $insert = $this->pdo->prepare("REPLACE INTO translations_cache (hash_md5, translated_text) VALUES (:hash, :text)");
                $insert->execute([':hash' => $hash, ':text' => $translatedText]);

                return $translatedText;
            }

        } catch (Throwable $e) {
            $this->logError("Krytyczny błąd DeepL: " . $e->getMessage());
        }

        return $text;
    }

    private function logError(string $message): void
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        $formatted = date('[Y-m-d H:i:s] ') . "[DeepLTranslator] " . $message . "\n";
        file_put_contents($this->logFile, $formatted, FILE_APPEND);
    }
}