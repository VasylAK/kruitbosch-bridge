<?php
/**
 * Skrypt testujący pobieranie i filtrowanie katalogu produktów z Kruitbosch.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/KruitboschAuth.php';
require_once __DIR__ . '/KruitboschProducts.php';

$configFile = __DIR__ . '/config.json';

if (!file_exists($configFile)) {
    die("BŁĄD: Brak pliku config.json!\n");
}

$config = json_decode(file_get_contents($configFile), true);

try {
    echo "Inicjalizacja modułu autoryzacji i pobierania produktów...\n";
    $auth = new KruitboschAuth($config);
    $productsModule = new KruitboschProducts($config, $auth);

    echo "Pobieranie produktów z API Kruitbosch (GET /products)...\n";
    $products = $productsModule->fetchProducts();

    echo "SUKCES! Pobrano produkty. Liczba pozycji: " . count($products) . "\n\n";

    if (!empty($products)) {
        echo "--- PRZYKŁADOWY PRODUKT (1 z " . count($products) . ") ---\n";
        $sample = $products[0]; // Bierzemy pierwszy produkt z brzegu
        
        echo "EAN: " . ($sample['EANCode'] ?? 'Brak') . "\n";
        echo "SKU (ProductCode): " . ($sample['ProductCode'] ?? 'Brak') . "\n";
        
        // Ekstrakcja marki specjalnie z tablicy Attributes
        $brand = 'Brak';
        if (!empty($sample['Attributes']) && is_array($sample['Attributes'])) {
            foreach ($sample['Attributes'] as $attr) {
                if (isset($attr['Attribute']) && strcasecmp($attr['Attribute'], 'Merk') === 0) {
                    $brand = $attr['AttributeValue'];
                    break;
                }
            }
        }
        echo "Marka: " . $brand . "\n";
        
        echo "Nazwa (NL): " . ($sample['Description'] ?? 'Brak') . "\n";
        echo "Cena sugerowana (Adviesprijs): " . ($sample['RecommendedSalesPrice'] ?? 'Brak') . " EUR\n";
        echo "Cena zakupu netto (Inkoopprijs): " . ($sample['PriceGross'] ?? 'Brak') . " EUR\n";
        echo "Informacja: Stany magazynowe są obsługiwane w oddzielnym etapie.\n";
        echo "----------------------------------------------\n";
    }

} catch (Throwable $e) {
    echo "BŁĄD: " . $e->getMessage() . "\n";
}