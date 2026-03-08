<?php
/**
 * AI Assistant API (SEO, Improve Text, TL;DR)
 */
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nincs bejelentkezve']);
    exit;
}

// Ensure it's a POST request with JSON
$input = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');

if (empty($action)) {
    echo json_encode(['success' => false, 'error' => 'Érvénytelen művelet (action)']);
    exit;
}

// 1. Get API Key from settings
$apiKey = '';
$settingsRow = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'ai'")->fetchColumn();
if ($settingsRow) {
    $aiSettings = json_decode($settingsRow, true);
    $apiKey = $aiSettings['deepseek_api_key'] ?? '';
}

if (empty($apiKey)) {
    echo json_encode(['success' => false, 'error' => 'DeepSeek API kulcs nincs beállítva a Beállításoknál!']);
    exit;
}

// 2. Setup prompt based on action
$prompt = '';
$systemMessage = 'Egy profi szerkesztői AI asszisztens vagy. Kizárólag érvényes, tiszta JSON formátumban válaszolj, Markdown kódblokkok (```json) és minden egyéb kommentár nélkül.';

if ($action === 'seo') {
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');

    // Alap kivonat a túl hosszú szövegeknél
    $excerpt = mb_strimwidth(strip_tags($content), 0, 1000, '...');

    $prompt = "A következő cikkhez generálj kattintásmágnes (de nem clickbait), SEO-barát Meta Címet (max 60 karakter) és Meta Leírást (max 160 karakter).\n";
    if ($title)
        $prompt .= "Jelenlegi Cím: {$title}\n";
    $prompt .= "Tartalom részlet: {$excerpt}\n\n";

    $prompt .= "A válaszod PONTOSAN egy ilyen JSON legyen:\n";
    $prompt .= "{\n  \"meta_title\": \"A kigondolt cím\",\n  \"meta_description\": \"A kigondolt leírás\"\n}";

} elseif ($action === 'improve_text') {
    $text = trim($input['text'] ?? '');
    $style = trim($input['style'] ?? 'default');

    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'Nincs küldött szöveg.']);
        exit;
    }

    $charCount = mb_strlen(strip_tags($text));
    $prompt = "Olvasd el a következő szöveget, és javítsd ki az esetleges helyesírási, nyelvhelyességi hibákat, megőrizve a HTML formázást (ha van benne)!\n";
    $prompt .= "RENDKÍVÜL FONTOS SZIGORÚ KÖVETELMÉNY: A válaszod hossza (karakterszámban) legyen szinte PONTOSAN ugyanannyi, mint az eredeti szövegé (összesen kb. {$charCount} karakter)! Ne bövítsd ki, ne adj hozzá felesleges díszítéseket, csak fogalmazd át a kért stílusban.\n";

    // Stílus hozzáadása a prompthoz
    switch ($style) {
        case 'funny':
            $prompt .= "Fogalmazd át a szöveget rendkívül vicces, humoros, szórakoztató stílusban!\n";
            break;
        case 'academic':
            $prompt .= "Fogalmazd át a szöveget tudálékos, magas röptű, akadémiai és erősen szakmai nyelvezettel!\n";
            break;
        case 'formal':
            $prompt .= "Fogalmazd át a szöveget szigorúan hivatalos, udvarias, távolságtartó üzleti stílusban!\n";
            break;
        case 'casual':
            $prompt .= "Fogalmazd át a szöveget laza, közvetlen, baráti és könnyed hétköznapi stílusban!\n";
            break;
        case 'short':
            $prompt .= "Zanzásítsd a szöveget! Legyen a lehető legrövidebb, leginkább lényegretörő, minden felesleges szó nélkül!\n";
            break;
        default:
            $prompt .= "Fogalmazd át egy kicsit professzionálisabb, folyékonyabb stílusban, de tartsd meg az eredeti hangnemet és jelentést!\n";
            break;
    }

    $prompt .= "\nSzöveg:\n{$text}\n\n";

    $prompt .= "A válaszod PONTOSAN egy ilyen JSON legyen:\n";
    $prompt .= "{\n  \"improved_text\": \"A feljavított és átfogalmazott szöveg\"\n}";

} elseif ($action === 'tldr') {
    $content = trim($input['content'] ?? '');
    if (empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Nincs tartalom.']);
        exit;
    }

    // Alap kivonat a túl hosszú szövegeknél, ha spórolni akarunk a tokennel, de itt érdemes minél többet átadni
    $excerpt = mb_strimwidth(strip_tags($content), 0, 3000, '...');

    $prompt = "Készíts egy nagyon rövid, 2-3 mondatos összefoglalót (TL;DR) a következő cikkből. Legyen figyelemfelkeltő és lényegretörő!\n\n";
    $prompt .= "Cikk szövege:\n{$excerpt}\n\n";

    $prompt .= "A válaszod PONTOSAN egy ilyen JSON legyen:\n";
    $prompt .= "{\n  \"tldr_text\": \"Az összefoglaló szövege\"\n}";

} else {
    echo json_encode(['success' => false, 'error' => 'Ismeretlen action.']);
    exit;
}

// 3. Call DeepSeek API
$ch = curl_init('https://api.deepseek.com/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => $systemMessage],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.6,
    'response_format' => ['type' => 'json_object']
]));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || $response === false) {
    echo json_encode(['success' => false, 'error' => 'DeepSeek API hiba (HTTP ' . $httpCode . '): ' . $error]);
    exit;
}

$decoded = json_decode($response, true);
$aiText = $decoded['choices'][0]['message']['content'] ?? '';

// Fix API sometimes returning markdown wrappers despite instructions
$aiText = preg_replace('/^```json\s*/i', '', $aiText);
$aiText = preg_replace('/\s*```$/i', '', $aiText);

$result = json_decode(trim($aiText), true);

if (!$result) {
    echo json_encode(['success' => false, 'error' => 'Érvénytelen JSON válasz érkezett az AI-tól', 'raw' => $aiText]);
    exit;
}

// Merge success flag into result
$result['success'] = true;
echo json_encode($result);
