<?php
/**
 * AI Content Generator API
 */
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nincs bejelentkezve']);
    exit;
}

// Ensure it's a POST request with JSON
$input = json_decode(file_get_contents('php://input'), true);
$topic = trim($input['topic'] ?? '');

if (empty($topic)) {
    echo json_encode(['success' => false, 'error' => 'A téma megadása kötelező']);
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

// 2. Call DeepSeek API
$prompt = "Írj egy SEO-barát, érdekes, és jól strukturált HTML blog cikket a következő témában: \"{$topic}\".\n";
$prompt .= "A HTML tartalom csak címsorokat (<h2>, <h3>), bekezdéseket (<p>), listákat (<ul>, <li>) tartalmazzon. Ne írj <h1> taget, mert az az oldal címe lesz.\n";
$prompt .= "NEM KÉREK markdown kódblokkokat (```json), CSAK egy érvényes, nyers JSON objektumot adj vissza ezzel a pontos struktúrával:\n";
$prompt .= "{\n";
$prompt .= "  \"title\": \"A cikk beszédes címe\",\n";
$prompt .= "  \"content\": \"A legenerált HTML tartalom\",\n";
$prompt .= "  \"image_keyword\": \"egyetlen, releváns angol kulcsszó egy kapcsolódó fotó generálásához\"\n";
$prompt .= "}";

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
        ['role' => 'system', 'content' => 'Egy webes tartalomgeneráló AI vagy. Szigorúan csak azt a JSON struktúrát adhatod vissza, amit kértek, semmi más extra szöveget.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.7,
    'response_format' => ['type' => 'json_object'] // Deepseek supports json_object mostly
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

if (!$result || !isset($result['title']) || !isset($result['content'])) {
    echo json_encode(['success' => false, 'error' => 'Érvénytelen JSON válasz érkezett az AI-tól', 'raw' => $aiText]);
    exit;
}

// 3. Generate Image
$filename = '';
$keyword = urlencode($result['image_keyword'] ?? 'website');
$imageUrl = "https://image.pollinations.ai/prompt/{$keyword}?width=800&height=500&nologo=true";

$imgData = @file_get_contents($imageUrl);
if ($imgData !== false) {
    $filename = 'ai_' . time() . '_' . rand(1000, 9999) . '.jpg';
    $filepath = '../uploads/' . $filename;

    // Save to disk
    if (file_put_contents($filepath, $imgData)) {
        // Add to media library
        $stmt = $db->prepare('INSERT INTO media (filename, filepath) VALUES (?, ?)');
        $stmt->execute([$filename, $filename]);

        require_once '../core/functions.php';
        log_activity($db, 'create', 'media', (int) $db->lastInsertId(), $filename);
    } else {
        $filename = ''; // Failed to save
    }
}

echo json_encode([
    'success' => true,
    'title' => $result['title'],
    'content' => $result['content'],
    'featured_image' => $filename
]);
