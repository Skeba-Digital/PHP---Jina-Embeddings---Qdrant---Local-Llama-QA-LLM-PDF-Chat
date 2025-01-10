<?php
// embeddings.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include configuration
require_once __DIR__ . '/config.php';

// Logging function with log levels
function logMessage($message, $level = 'INFO') {
    $date = date('Y-m-d H:i:s');
    file_put_contents(EMBEDDINGS_LOG_FILE, "[$date] [$level] $message\n", FILE_APPEND);
}

// Log the configuration values for debugging
logMessage("JINA_API_URL: " . JINA_API_URL, 'DEBUG');
logMessage("JINA_API_KEY: " . substr(JINA_API_KEY, 0, 10) . "...", 'DEBUG'); // Mask API key
logMessage("CORS_ORIGIN: " . CORS_ORIGIN, 'DEBUG');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    logMessage("Handled preflight OPTIONS request.", 'INFO');
    exit(0);
}

// Set CORS headers for actual requests
header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
header("Content-Type: application/json");

// Get the JSON input
$input = file_get_contents('php://input');
logMessage("Received request payload: " . substr($input, 0, 100) . "...", 'INFO'); // Log first 100 chars

// Decode JSON input
$data = json_decode($input, true);

// Validate input
if (!isset($data['texts']) || !is_array($data['texts']) || empty($data['texts'])) {
    http_response_code(400);
    $errorMsg = 'Invalid input. "texts" must be a non-empty array.';
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg", 'ERROR');
    exit;
}

$texts = $data['texts'];

// Prepare the payload for Jina
$jinaPayload = [
    'model' => 'jina-embeddings-v3',
    'task' => 'text-matching',
    'late_chunking' => false,
    'dimensions' => 1024, // Updated to match Qdrant's VECTOR_SIZE
    'embedding_type' => 'float',
    'input' => $texts
];

// Initialize cURL
$ch = curl_init();

// Set the Jina Embeddings API endpoint
curl_setopt($ch, CURLOPT_URL, JINA_API_URL);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jinaPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Set headers
$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . JINA_API_KEY
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Handle errors
if ($response === false) {
    $errorMsg = 'cURL error: ' . curl_error($ch);
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg", 'ERROR');
    curl_close($ch);
    exit;
}

curl_close($ch);

// Handle the response
if ($httpCode >= 200 && $httpCode < 300) {

    $responseData = json_decode($response, true);
    
    // Check if 'data' key exists
    if (!isset($responseData['data']) || !is_array($responseData['data'])) {
        $errorMsg = "Invalid response structure from Jina. 'data' key missing or not an array.";
        http_response_code(500);
        echo json_encode(['error' => $errorMsg]);
        logMessage("Error: $errorMsg", 'ERROR');
        exit;
    }
    
    // Extract embeddings from 'data' array
    $embeddings = [];
    foreach ($responseData['data'] as $index => $item) {
        if (isset($item['embedding']) && is_array($item['embedding'])) {
            if (count($item['embedding']) !== 1024) {
                $errorMsg = "Embedding size mismatch. Expected 1024 dimensions, got " . count($item['embedding']) . ".";
                http_response_code(500);
                echo json_encode(['error' => $errorMsg]);
                logMessage("Error: $errorMsg", 'ERROR');
                exit;
            }
            $embeddings[] = $item['embedding'];
        } else {
            $errorMsg = "Invalid embedding structure in 'data' item at index $index.";
            http_response_code(500);
            echo json_encode(['error' => $errorMsg]);
            logMessage("Error: $errorMsg", 'ERROR');
            exit;
        }
    }
    
    // Return the embeddings
    echo json_encode(['embeddings' => $embeddings]);
    logMessage("Successfully generated embeddings for " . count($embeddings) . " texts.", 'INFO');
} else {
    $errorMsg = "Jina API error (HTTP $httpCode): $response";
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg", 'ERROR');
    exit;
}
?>