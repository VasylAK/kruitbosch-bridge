<?php
/**
 * Klasa obsługująca autoryzację sesyjną w API Kruitbosch.
 */
class KruitboschAuth
{
    private array $config;
    private string $tokenFile;
    private string $logFile;

    public function __construct(array $config)
    {
        if (!isset($config['kruitbosch_api'])) {
            throw new InvalidArgumentException("Brak sekcji 'kruitbosch_api' w pliku konfiguracyjnym.");
        }
        $this->config = $config['kruitbosch_api'];
        $this->tokenFile = __DIR__ . '/token_cache.json';
        $this->logFile = __DIR__ . '/logs/error.log';
    }

    /**
     * Czyszczenie zapamiętanego tokenu sesyjnego.
     */
    public function clearTokenCache(): void
    {
        if (file_exists($this->tokenFile)) {
            @unlink($this->tokenFile);
        }
    }

    /**
     * Pobiera aktywny token sesyjny (z pamięci podręcznej lub generuje nowy).
     */
    public function getValidToken(bool $forceRefresh = false): string
    {
        // Jeśli wymuszamy odświeżenie, czyścimy lokalny plik
        if ($forceRefresh) {
            $this->clearTokenCache();
        }

        // 1. Sprawdzenie cache tokenu
        if (file_exists($this->tokenFile)) {
            $cache = json_decode(file_get_contents($this->tokenFile), true);
            if (isset($cache['token'], $cache['expires_at']) && $cache['expires_at'] > (time() + 300)) {
                return $cache['token'];
            }
        }

        // 2. Pobranie nowego tokenu z API
        return $this->authenticate();
    }

    /**
     * Wysyła zapytanie POST do /auth i zapisuje token.
     */
    private function authenticate(): string
    {
        $url = rtrim($this->config['auth_url'], '/');
        
        $payload = json_encode([
            'Provider' => $this->config['provider'],
            'Username' => $this->config['username'],
            'Password' => $this->config['password']
        ]);

        $sslVerify = $this->config['ssl_verify'] ?? true;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logError("cURL Error podczas autoryzacji: " . $error);
            throw new Exception("Błąd połączenia cURL: " . $error);
        }

        if ($httpCode !== 200) {
            $this->logError("API HTTP {$httpCode}: " . $response);
            throw new Exception("Błąd autoryzacji Kruitbosch (HTTP Status {$httpCode}). Odpowiedź: {$response}");
        }

        $data = json_decode($response, true);
        $token = null;

        if (is_string($data) && !empty(trim($data))) {
            $token = trim($data);
        } elseif (is_array($data)) {
            $token = $data['SessionToken'] 
                  ?? $data['SessionId'] 
                  ?? $data['Token'] 
                  ?? $data['token'] 
                  ?? $data['sessionToken'] 
                  ?? $data['id'] 
                  ?? null;
        }

        if (!$token && is_string($response) && !empty(trim($response))) {
            $cleanResponse = trim($response, "\" \t\n\r\0\x0B");
            if (strlen($cleanResponse) > 10) {
                $token = $cleanResponse;
            }
        }

        if (!$token) {
            $this->logError("Nie rozpoznano formatu tokenu. Odpowiedź serwera: " . $response);
            throw new Exception("Brak tokenu. Surowa odpowiedź z API Kruitbosch: " . $response);
        }

        $cacheData = [
            'token'      => $token,
            'expires_at' => time() + (24 * 3600)
        ];
        
        file_put_contents($this->tokenFile, json_encode($cacheData, JSON_PRETTY_PRINT));

        return $token;
    }

    private function logError(string $message): void
    {
        $formatted = date('[Y-m-d H:i:s] ') . "[KruitboschAuth] " . $message . "\n";
        file_put_contents($this->logFile, $formatted, FILE_APPEND);
    }
}