<?php
/**
 * Soro Blog Sitemap Generator - Fixed for JS Embed Response
 */

$baseUrl = 'https://www.abc.net/blog.php';
$soroApiUrl = 'https://app.trysoro.com/api/embed/<your url code>';
$outputFile = 'blog.xml';
//$outputFile must be writeable on the server

function debug($msg) {
    echo "[DEBUG] " . date('H:i:s') . " - {$msg}\n";
    flush();
}

// Fetch and parse Soro JS embed response
function fetchSoroArticles($apiUrl) {
    debug("Fetching from: {$apiUrl}");
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SoroSitemap/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: */*']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    debug("HTTP Status: {$httpCode}");
    
    if (!$response || $httpCode !== 200) {
        debug("Failed to fetch response");
        return false;
    }
    
    // The API returns JS: var SORO_ARTICLES = [{...}];
    // Extract the JSON array using regex
    if (preg_match('/var\s+SORO_ARTICLES\s*=\s*(\[[\s\S]*?\]);?\s*(?:var|function|\}|$)/i', $response, $matches)) {
        $jsonString = $matches[1];
        debug("Extracted SORO_ARTICLES JSON (" . strlen($jsonString) . " chars)");
        
        $articles = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debug("JSON parse error: " . json_last_error_msg());
            debug("First 200 chars: " . substr($jsonString, 0, 200));
            return false;
        }
        
        debug("Parsed " . count($articles) . " articles");
        return $articles;
    }
    
    debug("Could not find SORO_ARTICLES in response");
    debug("Response preview: " . substr($response, 0, 300));
    return false;
}

function xmlEscape($string) {
    return htmlspecialchars($string ?? '', ENT_XML1, 'UTF-8');
}

function formatSitemapDate($isoDate) {
    try {
        return (new DateTime($isoDate))->format('c');
    } catch (Exception $e) {
        return date('c');
    }
}

function generateSitemap($articles, $baseUrl) {
    debug("Building sitemap XML");
    
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    $xml .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    
    // Main blog page
    $xml .= '  <url>' . "\n";
    $xml .= '    <loc>' . xmlEscape($baseUrl) . '</loc>' . "\n";
    $xml .= '    <lastmod>' . date('c') . '</lastmod>' . "\n";
    $xml .= '    <changefreq>daily</changefreq>' . "\n";
    $xml .= '    <priority>0.8</priority>' . "\n";
    $xml .= '  </url>' . "\n";
    
    // Articles
    foreach ($articles as $article) {
        if (empty($article['slug'])) continue;
        
        $articleUrl = $baseUrl . '?post=' . urlencode($article['slug']);
        debug("  + {$article['title']}");
        
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . xmlEscape($articleUrl) . '</loc>' . "\n";
        
        if (!empty($article['isoDate'])) {
            $xml .= '    <lastmod>' . xmlEscape(formatSitemapDate($article['isoDate'])) . '</lastmod>' . "\n";
        }
        $xml .= '    <changefreq>monthly</changefreq>' . "\n";
        $xml .= '    <priority>0.6</priority>' . "\n";
        
        if (!empty($article['image'])) {
            $xml .= '    <image:image>' . "\n";
            $xml .= '      <image:loc>' . xmlEscape($article['image']) . '</image:loc>' . "\n";
            $xml .= '      <image:title>' . xmlEscape($article['title']) . '</image:title>' . "\n";
            if (!empty($article['excerpt'])) {
                $xml .= '      <image:caption>' . xmlEscape($article['excerpt']) . '</image:caption>' . "\n";
            }
            $xml .= '    </image:image>' . "\n";
        }
        $xml .= '  </url>' . "\n";
    }
    
    $xml .= '</urlset>' . "\n";
    return $xml;
}

// === EXECUTION ===
debug("=== Soro Sitemap Generator ===");

try {
    $articles = fetchSoroArticles($soroApiUrl);
    
    if (!$articles || !is_array($articles)) {
        throw new Exception("No articles extracted from API response");
    }
    
    $sitemapXml = generateSitemap($articles, $baseUrl);
    
    $bytes = file_put_contents($outputFile, $sitemapXml);
    if ($bytes === false) {
        throw new Exception("Failed to write {$outputFile}");
    }
    
    echo "\n✅ Success: {$outputFile} ({$bytes} bytes, " . count($articles) . " articles)\n";
    
} catch (Exception $e) {
    fwrite(STDERR, "\n❌ Error: " . $e->getMessage() . "\n");
    exit(1);
}
?>