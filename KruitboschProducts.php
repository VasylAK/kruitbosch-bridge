<?php
/**
 * Moduł pobierania i filtrowania produktów z API Kruitbosch.
 */
class KruitboschProducts
{
    private array $apiConfig;
    private array $filterConfig;
    private KruitboschAuth $auth;
    private string $logFile;

    public function __construct(array $fullConfig, KruitboschAuth $auth)
    {
        $this->apiConfig = $fullConfig['kruitbosch_api'];
        $this->filterConfig = $fullConfig['filters'] ?? [];
        $this->auth = $auth;
        $this->logFile = __DIR__ . '/logs/error.log';
    }

    /**
     * Pobiera pełną listę produktów z API z obsługą dużych plików (24MB+) i odświeżania tokena.
     */
    public function fetchProducts(bool $isRetry = false): array
    {
        ini_set('memory_limit', '512M');

        $rawToken = $this->auth->getValidToken($isRetry);
        $token = trim($rawToken, "\" \t\n\r\0\x0B");
        
        $endpoint = rtrim($this->apiConfig['base_url'], '/') . '/products';
        $queryParams = http_build_query([
            'ExcludeBlocked'    => 'true',
            'ConsumerProducts'  => '1',
            'IncludeAttributes' => 'true'
        ]);
        $url = $endpoint . '?' . $queryParams;

        $sslVerify = $this->apiConfig['ssl_verify'] ?? true;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Session-Token: ' . $token,
                'X-ss-id: ' . $token,
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_TIMEOUT        => 900,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $requestHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logError("cURL Error podczas pobierania produktów: " . $error);
            throw new Exception("Błąd połączenia cURL: " . $error);
        }

        if ($httpCode === 401 && !$isRetry) {
            $this->logError("Token sesyjny wygasł (HTTP 401). Automatyczne ponawianie z nowym tokenem...");
            return $this->fetchProducts(true);
        }

        if ($httpCode !== 200) {
            $debugMsg = "API HTTP {$httpCode}\n";
            $debugMsg .= "--- WYSŁANE NAGŁÓWKI ---\n{$requestHeaders}\n";
            $debugMsg .= "--- ODPOWIEDŹ SERWERA ---\n" . substr((string)$response, 0, 2000) . "\n";
            $this->logError($debugMsg);
            throw new Exception("Błąd pobierania produktów Kruitbosch (HTTP {$httpCode}). Sprawdź plik logs/error.log.");
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            $this->logError("Nieprawidłowy format danych produktów (nie udało się zdekodować JSON).");
            throw new Exception("API Kruitbosch nie zwróciło prawidłowej struktury JSON.");
        }

        // --- INTELELGENTNA EKSTRAKCJA TABLICY Z OBIEKTU WRAPPERA ---
        $productsList = [];
        
        // Jeśli odpowiedź jest czystą tablicą liczbową z produktami
        if (isset($data[0]) && is_array($data[0])) {
            $productsList = $data;
        } 
        // Jeśli Kruitbosch owija to w słownik (np. {"Total": X, "Products": [...]})
        else {
            foreach ($data as $key => $value) {
                if (is_array($value) && (isset($value[0]) || empty($value))) {
                    $productsList = $value;
                    break;
                }
            }
        }

        if (empty($productsList) && !empty($data)) {
            $keys = implode(', ', array_keys($data));
            $this->logError("Błąd struktury danych. Zwrócone klucze główne: " . $keys);
            throw new Exception("Zdekodowano JSON, ale nie znaleziono listy produktów. Główne klucze obiektu to: " . $keys);
        }

        return $this->applyFilters($productsList);
    }

    private function applyFilters(array $products): array
    {
        $allowedBrands = $this->filterConfig['allowed_brands'] ?? [];
        $allowedEans   = $this->filterConfig['allowed_eans'] ?? [];
        $limit         = (int)($this->filterConfig['limit'] ?? 0);

        $filtered = [];
        
        foreach ($products as $product) {
            $ean = $product['EANCode'] ?? $product['Ean'] ?? $product['EAN'] ?? '';
            $ean = trim((string)$ean);
            
            // 1. Priorytet EAN: Jeśli podano listę kodów kreskowych, szukamy tylko ich
            if (!empty($allowedEans)) {
                if (in_array($ean, $allowedEans, true)) {
                    $filtered[] = $product;
                }
            } 
            // 2. Standardowy filtr: Marki (uruchamiany tylko, gdy lista EAN jest pusta)
            else {
                $brand = $this->extractBrand($product);
                if (empty($allowedBrands) || $this->isBrandAllowed($brand, $allowedBrands)) {
                    $filtered[] = $product;
                }
            }

            // 3. Globalne odcięcie po osiągnięciu sztywnego limitu (np. 10 sztuk)
            if ($limit > 0 && count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    private function extractBrand(array $product): string
    {
        // API Kruitbosch chowa markę w tablicy Attributes
        if (!empty($product['Attributes']) && is_array($product['Attributes'])) {
            foreach ($product['Attributes'] as $attr) {
                if (isset($attr['Attribute']) && strcasecmp($attr['Attribute'], 'Merk') === 0) {
                    return $attr['AttributeValue'] ?? '';
                }
            }
        }
        return $product['Brand'] ?? $product['Merk'] ?? '';
    }

    private function isBrandAllowed(string $brand, array $allowedBrands): bool
    {
        if (empty(trim($brand))) return false;
        foreach ($allowedBrands as $allowed) {
            if (strcasecmp(trim($brand), trim($allowed)) === 0) {
                return true;
            }
        }
        return false;
    }

    private function logError(string $message): void
    {
        $formatted = date('[Y-m-d H:i:s] ') . "[KruitboschProducts] " . $message . "\n";
        file_put_contents($this->logFile, $formatted, FILE_APPEND);
    }
}