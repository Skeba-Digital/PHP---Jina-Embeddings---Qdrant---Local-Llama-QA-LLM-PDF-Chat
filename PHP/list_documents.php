<?php
// list_documents.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include configuration
require_once __DIR__ . '/config.php';

// Logging function
function logMessage($message) {
    $date = date('Y-m-d H:i:s');
    file_put_contents(LIST_DOCUMENTS_LOG_FILE, "[$date] $message\n", FILE_APPEND);
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    logMessage("Handled preflight OPTIONS request.");
    exit(0);
}

// Set CORS headers for actual requests
header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
header("Content-Type: application/json");

// ------------------------------------------------------------------
// 1) Retrieve user_id & data_type from query parameters
// ------------------------------------------------------------------
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 1; // default to 1
$data_type = isset($_GET['data_type']) ? $_GET['data_type'] : 'documents';

// Validate data_type
$allowed_data_types = ['documents', 'conversations'];
if (!in_array($data_type, $allowed_data_types)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid "data_type". Allowed types are "documents" and "conversations".']);
    logMessage("Error: Invalid data_type '{$data_type}'.");
    exit;
}

// Determine the Qdrant collection
$collectionName = ($data_type === 'documents')
    ? sprintf(USER_DOCUMENTS_PREFIX, $user_id)
    : sprintf(USER_CONVERSATIONS_PREFIX, $user_id);

// Prepare the scroll payload
$scrollPayload = [
    'limit' => 200,
    'with_payload' => ['doc_id', 'file_name', 'uploaded_at']
];

// Call Qdrant's Scroll Endpoint
$ch = curl_init();
$scrollUrl = QDRANT_ENDPOINT . "/collections/{$collectionName}/points/scroll";
curl_setopt($ch, CURLOPT_URL, $scrollUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($scrollPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$headers = [
    'Content-Type: application/json',
    'api-key: ' . QDRANT_API_KEY
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($response === false) {
    $errorMsg = 'cURL error: ' . $curlError;
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    curl_close($ch);
    exit;
}

curl_close($ch);

// Check for HTTP 404 (Collection likely doesn't exist)
if ($httpCode === 404) {
    $message = "Collection '{$collectionName}' not found. It may not have been created yet.";
    http_response_code(404);
    echo json_encode(['error' => $message]);
    logMessage("Error: $message");
    exit;
}

// Decode JSON response
$responseData = json_decode($response, true);
if ($responseData === null) {
    $errorMsg = 'JSON decoding error: ' . json_last_error_msg();
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    exit;
}

// Validate response structure
if (!isset($responseData['result']) || !isset($responseData['result']['points'])) {
    $errorMsg = 'Unexpected response structure from Qdrant.';
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    exit;
}

// Check for empty points
$points = $responseData['result']['points'];
if (empty($points)) {
    $message = "No documents found in collection '{$collectionName}'.";
    echo json_encode(['documents' => [], 'message' => $message]);
    logMessage($message);
    exit;
}

// Process points
$groupedDocs = [];
foreach ($points as $point) {
    $payload = $point['payload'] ?? [];
    $docId = $payload['doc_id'] ?? null;
    $fileName = $payload['file_name'] ?? null;
    $uploadedAt = $payload['uploaded_at'] ?? null;

    if (!$docId || !$fileName) continue;

    if (!isset($groupedDocs[$docId])) {
        $groupedDocs[$docId] = ['doc_id' => $docId, 'file_name' => $fileName, 'uploaded_at' => $uploadedAt];
    } elseif ($uploadedAt && (!$groupedDocs[$docId]['uploaded_at'] || new DateTime($uploadedAt) < new DateTime($groupedDocs[$docId]['uploaded_at']))) {
        $groupedDocs[$docId]['uploaded_at'] = $uploadedAt;
    }
}

// Sort documents by upload date
$documents = array_values($groupedDocs);
usort($documents, function ($a, $b) {
    return new DateTime($a['uploaded_at'] ?? '1970-01-01') <=> new DateTime($b['uploaded_at'] ?? '1970-01-01');
});

// Return documents
echo json_encode(['documents' => $documents]);
logMessage("Successfully fetched " . count($documents) . " documents from '{$collectionName}'.");
?>