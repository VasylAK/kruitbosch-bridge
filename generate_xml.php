<?php
/**
 * Główny Skrypt Mostkujący (Ścieżka 2)
 * Pobiera dane Kruitbosch -> Tłumaczy (Hybryda) -> Kalkuluje Fuzzy Stock -> Generuje XML
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0); 
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/KruitboschAuth.php';
require_once __DIR__ . '/KruitboschProducts.php';
require_once __DIR__ . '/DeepLTranslator.php';

$configFile = __DIR__ . '/config.json';
$xmlFile    = __DIR__ . '/kruitbosch_feed.xml';

if (!file_exists($configFile)) {
    die("BŁĄD: Brak pliku config.json!\n");
}

$config = json_decode(file_get_contents($configFile), true);

try {
    echo "1. Inicjalizacja modułów...\n";
    $auth = new KruitboschAuth($config);
    $productsModule = new KruitboschProducts($config, $auth);
    $translator = new DeepLTranslator($config);

    echo "2. Pobieranie danych z Kruitbosch API...\n";
    $products = $productsModule->fetchProducts();
    $totalProducts = count($products);
    echo "   Pobrano {$totalProducts} produktów do przetworzenia.\n";

    echo "3. Rozpoczęto generowanie pliku XML (Strumieniowo)...\n";
    
    $xml = new XMLWriter();
    $xml->openURI($xmlFile);
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);
    $xml->startElement('products');

    $counter = 0;
    foreach ($products as $p) {
        $counter++;
        if ($counter % 500 === 0) {
            echo "   Przetworzono $counter / $totalProducts...\n";
        }

        $xml->startElement('product');

        // 1. Identyfikatory i Marka
        $xml->writeElement('ean', $p['EANCode'] ?? '');
        $xml->writeElement('sku', $p['ProductCode'] ?? '');

        $brand = 'Nieznana';
        if (!empty($p['Attributes']) && is_array($p['Attributes'])) {
            foreach ($p['Attributes'] as $attr) {
                if (isset($attr['Attribute']) && strcasecmp($attr['Attribute'], 'Merk') === 0) {
                    $brand = $attr['AttributeValue'];
                    break;
                }
            }
        }
        $xml->writeElement('brand', $brand);

        // 2. Budowa Ścieżki Kategorii (Tylko rynkowe poziomy 1-4)
        $categoryLevels = [];
        
        // Omijamy 'ProductGroupCategory', aby pozbyć się wewnętrznego "Kruitbosch-indeling"
        if (!empty($p['ProductGroupLevel1'])) $categoryLevels[] = $p['ProductGroupLevel1'];
        if (!empty($p['ProductGroupLevel2'])) $categoryLevels[] = $p['ProductGroupLevel2'];
        if (!empty($p['ProductGroupLevel3'])) $categoryLevels[] = $p['ProductGroupLevel3'];
        if (!empty($p['ProductGroupLevel4'])) $categoryLevels[] = $p['ProductGroupLevel4'];
        
        // Odczytujemy kody EAN wytypowane do wymuszonego odświeżenia (Cache Busting)
        $forceRefreshEans = $config['settings']['force_refresh_categories_eans'] ?? [];
        $forceTranslate = in_array((string)($p['EANCode'] ?? ''), $forceRefreshEans, true);

        $translatedLevels = [];
        foreach ($categoryLevels as $lvl) {
            $lvlStr = trim($lvl);
            if (!empty($lvlStr)) {
                // TWARDY KONTEKST 'NL': Kategorie Kruitbosch są zawsze holenderskie.
                // Używamy $forceTranslate, aby nadpisać zepsute wpisy w SQLite.
                $translated = $translator->translate($lvlStr, 'PL', 'NL', $forceTranslate);
                if (!empty($translated)) {
                    $translatedLevels[] = $translated;
                }
            }
        }
        
        // Zabezpieczenie przed generowaniem pustego tagu <category_path>
        if (count($translatedLevels) > 0) {
            $categoryPath = implode(' > ', $translatedLevels);
            $xml->writeElement('category_path', $categoryPath);
        } else {
            // Fallback dla produktów widmo (które nie mają Level 1-4 w API)
            $xml->writeElement('category_path', 'Inne / Niesklasyfikowane');
        }

        // 3. Tłumaczenie głównych tekstów (TWARDY KONTEKST NL)
        $shortDescNl = $p['Description'] ?? '';
        $longDescNl  = $p['DescriptionLong'] ?? '';

        $shortDescPl = $translator->translate($shortDescNl, 'PL', 'NL');
        $longDescPl  = !empty($longDescNl) ? $translator->translate($longDescNl, 'PL', 'NL') : $shortDescPl;

        // <name> jako krótki opis, <short_description> usunięte
        $xml->writeElement('name', $shortDescPl);
        
        $xml->startElement('description');
        $xml->writeCdata($longDescPl);
        $xml->endElement();

        // 4. Translacja Atrybutów (Hybryda z zabezpieczeniem skrótów)
        $xml->startElement('features');
        if (!empty($p['Attributes']) && is_array($p['Attributes'])) {
            
            // Słownik technicznych wykluczeń (chronimy przed nadinterpretacją)
            $protectedTerms = ['MIPS', 'ABS', 'LED', 'USB', 'LCD', 'E-BIKE'];

            foreach ($p['Attributes'] as $attr) {
                $attrNameOriginal = $attr['Attribute'] ?? '';
                $attrValueOriginal = $attr['AttributeValue'] ?? '';

                if (strcasecmp($attrNameOriginal, 'Merk') === 0 || empty($attrNameOriginal) || empty($attrValueOriginal)) {
                    continue;
                }

                // Nazwa Atrybutu: Autodetekcja (bo bywa po angielsku, np. 'Frame type')
                $attrNamePl = $translator->translate($attrNameOriginal, 'PL', null);
                
                // Wartość Atrybutu: Zabezpieczenie przed halucynacją
                $cleanValue = trim($attrValueOriginal);
                if (in_array(strtoupper($cleanValue), $protectedTerms)) {
                    $attrValuePl = $cleanValue; // Nie dotykamy skrótu
                } else {
                    // Wymuszamy NL (bo tu Kruitbosch wysyła "Vast", "Wit" itp.)
                    $attrValuePl = $translator->translate($cleanValue, 'PL', 'NL');
                }

                // Optymalizacja logiki Tak/Nie
                if (strcasecmp($cleanValue, 'True') === 0) $attrValuePl = 'Tak';
                if (strcasecmp($cleanValue, 'False') === 0) $attrValuePl = 'Nie';

                $xml->startElement('feature');
                $xml->writeElement('name', $attrNamePl);
                $xml->writeElement('value', $attrValuePl);
                $xml->endElement();
            }
        }
        $xml->endElement(); // Koniec <features>

        // 5. Ceny i Przeliczniki opakowań
        $priceRrp = (float)str_replace(',', '.', $p['RecommendedSalesPrice'] ?? '0');
        $priceGross = (float)str_replace(',', '.', $p['PriceGross'] ?? '0');
        $pkgQty = (int)($p['PackageQuantity'] ?? 1);
        if ($pkgQty < 1) $pkgQty = 1;
        
        $pricePurchaseUnit = $priceGross / $pkgQty;

        $xml->writeElement('price_purchase_net', round($pricePurchaseUnit, 2));
        $xml->writeElement('price_rrp_net', round($priceRrp, 2));

        // 6. Statusy Fuzzy Stock
        $availId = (int)($p['AvailabilityId'] ?? 3);
        $stockStatusText = '';
        $activeFlag = 1;

        switch ($availId) {
            case 1: $stockStatusText = 'Wysoka dostępność'; $activeFlag = 1; break;
            case 2: $stockStatusText = 'Ostatnie sztuki'; $activeFlag = 1; break;
            case 3: $stockStatusText = 'Na zamówienie (Dostawa planowana)'; $activeFlag = 1; break;
            case 4: $stockStatusText = 'Wyprzedany / Ukryty'; $activeFlag = 0; break;
            case 5: $stockStatusText = 'Na zamówienie indywidualne'; $activeFlag = 1; break;
            default: $stockStatusText = 'Na zamówienie'; $activeFlag = 1; break;
        }

        $xml->writeElement('stock_status', $stockStatusText);
        $xml->writeElement('active', (string)$activeFlag);

        // 7. Czas dostawy B2C (Data z buforem 9 dni)
        $deliveryText = 'Wysyłka w 24-48h';
        if ($availId === 3) {
            $dateAvail = $p['DateAvailable'] ?? null;
            if (!empty($dateAvail)) {
                $baseTime = strtotime($dateAvail);
                $deliveryTime = strtotime("+9 weekdays", $baseTime);
                $deliveryText = 'Planowana dostawa: ' . date('d.m.Y', $deliveryTime);
            } else {
                $deliveryText = 'Wydłużony czas realizacji - skontaktuj się z nami';
            }
        } elseif ($availId === 5) {
            $deliveryText = 'Produkt sprowadzany na indywidualne zamówienie';
        }
        $xml->writeElement('delivery_time_text', $deliveryText);

        // 8. Zdjęcia URL (do zassania przez Smart Importer)
        $xml->startElement('images');
        if (!empty($p['Image'])) {
            $xml->writeElement('image', trim($p['Image']));
        }
        if (!empty($p['AdditionalImages'])) {
            $addImgs = is_array($p['AdditionalImages']) ? $p['AdditionalImages'] : explode(',', $p['AdditionalImages']);
            foreach ($addImgs as $imgUrl) {
                if (!empty(trim($imgUrl))) {
                    $xml->writeElement('image', trim($imgUrl));
                }
            }
        }
        $xml->endElement(); 

        $xml->endElement(); 
    }

    $xml->endElement(); 
    $xml->endDocument();

    echo "4. ZAKOŃCZONO! Plik zapisany jako: " . $xmlFile . "\n";
    echo "Rozmiar pliku: " . round(filesize($xmlFile) / 1024 / 1024, 2) . " MB\n";

} catch (Throwable $e) {
    echo "\nBŁĄD KRYTYCZNY: " . $e->getMessage() . "\n";
}