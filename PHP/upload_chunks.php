<?php
// upload_chunks.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include configuration
require_once __DIR__ . '/config.php';

// Logging function
function logMessage($message) {
    $date = date('Y-m-d H:i:s');
    file_put_contents(UPLOAD_CHUNKS_LOG_FILE, "[$date] $message\n", FILE_APPEND);
}

// Function to generate UUID v4
function generateUUIDv4() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Function to create a collection
function createCollection($collectionName) {
    $ch = curl_init();
    
    $createUrl = QDRANT_ENDPOINT . "/collections/{$collectionName}";
    
    // Define the collection schema
    $schema = [
        'vectors' => [
            'size' => VECTOR_SIZE, // 1024
            'distance' => 'Cosine'
        ],
        'shard_number' => 1,
        'replication_factor' => 1
    ];
    
    curl_setopt($ch, CURLOPT_URL, $createUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); // Use PUT method
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($schema));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Set headers
    $headers = [
        'Content-Type: application/json',
        'api-key: ' . QDRANT_API_KEY
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($response === false) {
        $errorMsg = 'cURL error while creating collection: ' . $error;
        curl_close($ch);
        return ['success' => false, 'error' => $errorMsg];
    }
    
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        return ['success' => true];
    } elseif ($httpCode === 409) { // Conflict - Collection already exists
        return ['success' => true, 'message' => 'Collection already exists.'];
    } else {
        // Ensure that even if response is empty, the error message includes HTTP code
        return ['success' => false, 'error' => "Qdrant API error (HTTP $httpCode): $response"];
    }
}

// Function to check if a collection exists
function collectionExists($collectionName) {
    $ch = curl_init();
    
    $url = QDRANT_ENDPOINT . "/collections/{$collectionName}";
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    
    // Set headers
    $headers = [
        'Content-Type: application/json',
        'api-key: ' . QDRANT_API_KEY
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($response === false) {
        $errorMsg = 'cURL error while checking collection: ' . $error;
        curl_close($ch);
        logMessage("Error: $errorMsg");
        return false;
    }
    
    curl_close($ch);
    
    return $httpCode === 200;
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
    header("Access-Control-Allow-Methods: POST, PUT, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    logMessage("Handled preflight OPTIONS request.");
    exit(0);
}

// Set CORS headers for actual requests
header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
header("Content-Type: application/json");

// Get the JSON input
$input = file_get_contents('php://input');
logMessage("Received request payload: $input");

// Decode JSON input
$data = json_decode($input, true);

// Validate input
if (!isset($data['user_id']) || !isset($data['data_type']) || !isset($data['points'])) {
    http_response_code(400);
    $errorMsg = 'Invalid input. "user_id", "data_type", and "points" are required.';
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    exit;
}

$user_id = intval($data['user_id']);
$data_type = $data['data_type']; // e.g., 'documents' or 'conversations'
$points = $data['points'];

// Validate data_type
$allowed_data_types = ['documents', 'conversations'];
if (!in_array($data_type, $allowed_data_types)) {
    http_response_code(400);
    $errorMsg = 'Invalid "data_type". Allowed types are "documents" and "conversations".';
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    exit;
}

// Further validate 'points' structure
if (!is_array($points) || empty($points)) {
    http_response_code(400);
    $errorMsg = '"points" must be a non-empty array.';
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    exit;
}

// Validate each point's structure
foreach ($points as $index => $point) {
    if (!isset($point['id']) || !isset($point['vector']) || !isset($point['metadata'])) {
        http_response_code(400);
        $errorMsg = "Each point must have 'id', 'vector', and 'metadata'. Error at point index $index.";
        echo json_encode(['error' => $errorMsg]);
        logMessage("Error: $errorMsg");
        exit;
    }
    if (!is_array($point['vector']) || count($point['vector']) !== VECTOR_SIZE) { // 1024
        http_response_code(400);
        $errorMsg = "The 'vector' field must be an array of " . VECTOR_SIZE . " numeric values. Error at point ID {$point['id']}.";
        echo json_encode(['error' => $errorMsg]);
        logMessage("Error: $errorMsg");
        exit;
    }
    foreach ($point['vector'] as $vecIndex => $num) {
        if (!is_numeric($num)) {
            http_response_code(400);
            $errorMsg = "All elements in 'vector' must be numeric. Error at point ID {$point['id']}, vector index $vecIndex.";
            echo json_encode(['error' => $errorMsg]);
            logMessage("Error: $errorMsg");
            exit;
        }
    }
}

logMessage("Validated 'user_id', 'data_type', and 'points'.");

// Determine the collection name based on user_id and data_type
$collectionName = '';
if ($data_type === 'documents') {
    $collectionName = sprintf(USER_DOCUMENTS_PREFIX, $user_id);
} elseif ($data_type === 'conversations') {
    $collectionName = sprintf(USER_CONVERSATIONS_PREFIX, $user_id);
}

// Check if the collection exists; if not, create it
if (!collectionExists($collectionName)) {
    logMessage("Collection '{$collectionName}' does not exist. Attempting to create.");
    $createResult = createCollection($collectionName);
    if (!$createResult['success']) {
        $errorDetail = isset($createResult['error']) ? $createResult['error'] : 'Unknown error occurred.';
        http_response_code(500);
        echo json_encode(['error' => "Failed to create collection '{$collectionName}': $errorDetail"]);
        logMessage("Error: Failed to create collection '{$collectionName}': $errorDetail");
        exit;
    } else {
        if (isset($createResult['message'])) {
            logMessage($createResult['message']);
        } else {
            logMessage("Collection '{$collectionName}' created successfully.");
        }
    }
} else {
    logMessage("Collection '{$collectionName}' already exists.");
}

// Generate a unique doc_id for this upload
$doc_id = generateUUIDv4();
logMessage("Generated doc_id: {$doc_id}");

// Prepare the upsert payload for Qdrant
$upsertPayload = [
    'points' => []
];

foreach ($points as $point) {
    $upsertPayload['points'][] = [
        'id' => (string) $point['id'],
        'vector' => $point['vector'],
        'payload' => [
            'doc_id' => $doc_id, // Add doc_id here
            'file_name' => $point['metadata']['file_name'] ?? 'unknown',
            'uploaded_at' => $point['metadata']['uploaded_at'] ?? 'unknown',
            'chunk_number' => $point['metadata']['chunk_number'] ?? 0,
            'chunk' => $point['metadata']['chunk'] ?? '',
            'type' => $point['metadata']['type'] ?? 'document',
            'user_id' => $point['metadata']['user_id'] ?? 0
            // Add any other metadata fields as necessary
        ]
    ];
}

$jsonPayload = json_encode($upsertPayload);
if ($jsonPayload === false) {
    $errorMsg = 'JSON encoding error: ' . json_last_error_msg();
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    exit;
}

// Log the upsert payload
logMessage("Upsert Payload: $jsonPayload");

logMessage("Prepared upsert payload with " . count($upsertPayload['points']) . " points for collection '{$collectionName}'.");

// Initialize cURL
$ch = curl_init();

// Set the Qdrant upsert endpoint
$upsertUrl = QDRANT_ENDPOINT . "/collections/{$collectionName}/points?wait=true";

curl_setopt($ch, CURLOPT_URL, $upsertUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); // Ensure using PUT method
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Set headers
$headers = [
    'Content-Type: application/json',
    'api-key: ' . QDRANT_API_KEY
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$uploadError = curl_error($ch);

if ($response === false) {
    $errorMsg = 'cURL error during upsert: ' . $uploadError;
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    curl_close($ch);
    exit;
}

curl_close($ch);

// Handle the response
if ($httpCode >= 200 && $httpCode < 300) {
    logMessage("Successfully upserted points: $response");
    $uploadedPointIDs = array_map(function($point) {
        return [
            'id' => $point['id'],
            'doc_id' => $point['payload']['doc_id'] ?? 'unknown',
            'file_name' => $point['payload']['file_name'] ?? 'unknown',
            'uploaded_at' => $point['payload']['uploaded_at'] ?? 'unknown'
        ];
    }, $points);

    echo json_encode([
        'result' => 'Points upserted successfully',
        'doc_id' => $doc_id, // Return doc_id to the frontend if needed
        'uploaded_points' => $uploadedPointIDs,
        'details' => json_decode($response, true)
    ]);
} else {
    $errorMsg = "Qdrant API error (HTTP $httpCode): $response";
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    logMessage("Error: $errorMsg");
    exit;
}
?>