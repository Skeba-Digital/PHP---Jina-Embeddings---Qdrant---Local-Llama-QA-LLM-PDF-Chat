<?php
// search_chunks.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include configuration
require_once __DIR__ . '/config.php';

// Logging function
function logMessage($message) {
    $date = date('Y-m-d H:i:s');
    file_put_contents(SEARCH_CHUNKS_LOG_FILE, "[$date] $message\n", FILE_APPEND);
}

// Handle preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    logMessage("Handled preflight OPTIONS request.");
    exit(0);
}

// Set CORS headers
header("Access-Control-Allow-Origin: " . CORS_ORIGIN);
header("Content-Type: application/json");

// ==============================
//  Helper: cURL with retry
// ==============================
function executeCurlWithRetry($ch, $maxRetries = 3, $sleepSeconds = 1) {
    $attempt = 0;
    do {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        // Consider 2xx as success
        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'response' => $response, 'httpCode' => $httpCode];
        }

        $attempt++;
        error_log("Attempt {$attempt} failed: {$error} (HTTP {$httpCode})");
        logMessage("Attempt {$attempt} failed: {$error} (HTTP {$httpCode})");
        sleep($sleepSeconds);
    } while ($attempt < $maxRetries);

    // Return detailed error information
    return [
        'success' => false,
        'error' => $error,
        'httpCode' => $httpCode,
        'response' => $response
    ];
}

// ==============================
//  1. Generate Embedding (Jina)
// ==============================
function generateEmbedding($text) {
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . JINA_API_KEY
    ];

    $payload = [
        'input' => $text,
        'model' => 'jina-embeddings-v3' // Replace with your actual model
    ];

    $ch = curl_init(JINA_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = executeCurlWithRetry($ch);
    curl_close($ch);

    if (!$result['success']) {
        error_log('Error generating embedding: ' . ($result['error'] ?? 'Unknown'));
        logMessage('Error generating embedding: ' . ($result['error'] ?? 'Unknown'));
        return null;
    }

    $decoded = json_decode($result['response'], true);
    if (isset($decoded['data'][0]['embedding'])) {
        logMessage('Embedding generated successfully.');
        return $decoded['data'][0]['embedding'];
    }

    error_log('No embeddings returned by Jina.');
    logMessage('No embeddings returned by Jina.');
    return null;
}

// ==============================
//  2. Build Qdrant Filter
// ==============================

/**
 * Builds a payload filter for Qdrant based on selected doc_ids and timeframe.
 *
 * @param array $selectedDocuments Array of doc_ids.
 * @param int|null $timeframeDays Number of days for the timeframe filter.
 * @return array|null Qdrant filter object or null if no filters.
 */
function buildQdrantFilter($selectedDocuments, $timeframeDays = null) {
    $mustFilters = [];

    // If user specified a timeframe in days, build a date range filter
    if ($timeframeDays) {
        // Calculate the earliest date in ISO8601
        $earliest = (new DateTime())->sub(new DateInterval("P{$timeframeDays}D"))->format('c');

        // Add range filter for 'uploaded_at'
        $mustFilters[] = [
            'key' => 'uploaded_at',
            'range' => ['gte' => $earliest]
        ];
    }

    // If user selected specific document IDs, add a match filter for 'doc_id'
    if (!empty($selectedDocuments)) {
        // Determine if multiple doc_ids are provided
        if (count($selectedDocuments) === 1) {
            // Single doc_id: use 'value'
            $mustFilters[] = [
                'key' => 'doc_id',
                'match' => [
                    'value' => $selectedDocuments[0]
                ]
            ];
        } else {
            // Multiple doc_ids: use 'any'
            $mustFilters[] = [
                'key' => 'doc_id',
                'match' => [
                    'any' => $selectedDocuments
                ]
            ];
        }
    }

    // Return final filter object or null if empty
    if (!empty($mustFilters)) {
        return ['must' => $mustFilters];
    } else {
        return null;
    }
}

// ==============================
//  3. Query Qdrant
// ==============================
/**
 * Queries Qdrant with embedding and filters.
 *
 * @param array $embedding The embedding vector.
 * @param string $collectionName The name of the Qdrant collection.
 * @param int $limit Number of results to retrieve.
 * @param float $scoreThreshold Minimum score threshold.
 * @param array $selectedDocuments Array of selected doc_ids.
 * @param int|null $timeframeDays Number of days for the timeframe filter.
 * @param bool $applyThreshold Whether to apply the score threshold.
 * @return array Array of search results.
 */
function queryQdrant($embedding, $collectionName, $limit = 10, $scoreThreshold = 0.4, $selectedDocuments = [], $timeframeDays = null, $applyThreshold = true) {
    // Corrected endpoint path
    $searchUrl = QDRANT_ENDPOINT . "/collections/{$collectionName}/points/search";

    $headers = [
        'Content-Type: application/json',
        'api-key: ' . QDRANT_API_KEY
    ];

    // Base query payload
    $queryPayload = [
        'vector'        => $embedding,
        'limit'         => $limit,
        'with_payload'  => true,
    ];

    // Build optional timeframe or other filters
    $filterBlock = buildQdrantFilter($selectedDocuments, $timeframeDays);
    if ($filterBlock) {
        $queryPayload['filter'] = $filterBlock;
    }

    // Encode the query payload
    $jsonPayload = json_encode($queryPayload);
    if ($jsonPayload === false) {
        error_log('JSON encoding error in query payload: ' . json_last_error_msg());
        logMessage('JSON encoding error in query payload: ' . json_last_error_msg());
        return [];
    }

    // Log the query payload
    logMessage('Qdrant Query Payload: ' . $jsonPayload);

    // Initialize cURL
    $ch = curl_init($searchUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Execute the request
    $result = executeCurlWithRetry($ch);
    curl_close($ch);

    if (!$result['success']) {
        $errorMessage = 'Error querying Qdrant: ' . ($result['error'] ?? 'Unknown error');
        $errorDetails = "HTTP Code: {$result['httpCode']}";
        if (isset($result['response'])) {
            $errorDetails .= " | Response: " . $result['response'];
        }
        error_log("{$errorMessage} | {$errorDetails}");
        logMessage("{$errorMessage} | {$errorDetails}");
        return [];
    }

    $decoded = json_decode($result['response'], true);
    $allResults = $decoded['result'] ?? [];

    // Log the response
    logMessage('Qdrant Query Response: ' . json_encode($allResults));

    if (empty($allResults)) {
        error_log('No documents found (filter may have excluded all).');
        logMessage('No documents found (filter may have excluded all).');
        return [];
    }

    if ($applyThreshold) {
        // Filter by score threshold
        $filteredResults = array_filter($allResults, function($r) use ($scoreThreshold) {
            return (isset($r['score']) && $r['score'] >= $scoreThreshold);
        });

        // Log the number of results after filtering
        logMessage("Results after applying score threshold ({$scoreThreshold}): " . count($filteredResults));

        return array_values($filteredResults);
    } else {
        // Return top results without threshold filtering
        logMessage("Returning top {$limit} results without applying score threshold.");
        return array_slice($allResults, 0, $limit);
    }
}

// ==============================
//  4. Handle Incoming Request
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    // Validate input
    if (!isset($data['user_id']) || !isset($data['data_type']) || !isset($data['query'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: user_id, data_type, query']);
        logMessage("Error: Missing required fields in the request.");
        exit;
    }

    $userId           = intval($data['user_id']);
    $dataType         = $data['data_type'];
    $query            = trim($data['query']);
    $limit            = isset($data['limit']) ? intval($data['limit']) : 10;
    $scoreThreshold   = isset($data['score_threshold']) ? floatval($data['score_threshold']) : 0.4;
    $selectedDocs     = isset($data['selected_documents']) ? $data['selected_documents'] : [];
    $timeframeDays    = isset($data['timeframe']) ? intval($data['timeframe']) : null;

    if ($query === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Query text cannot be empty.']);
        logMessage("Error: Empty query text.");
        exit;
    }

    // Determine collection name from data_type
    if ($dataType === 'documents') {
        $collectionName = sprintf(USER_DOCUMENTS_PREFIX, $userId);
    } elseif ($dataType === 'conversations') {
        $collectionName = sprintf(USER_CONVERSATIONS_PREFIX, $userId);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data_type. Must be "documents" or "conversations".']);
        logMessage("Error: Invalid data_type '{$dataType}'.");
        exit;
    }

    // 1) Generate the embedding for the query
    $embedding = generateEmbedding($query);
    if (!$embedding) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to generate query embedding.']);
        logMessage("Error: Failed to generate embedding for query.");
        exit;
    }

    // 2) Verify embedding length matches Qdrant's vector size
    // Replace 512 with your actual vector size
    $expectedVectorSize = 1024;
    if (count($embedding) !== $expectedVectorSize) {
        logMessage("Embedding size mismatch: expected {$expectedVectorSize}, got " . count($embedding));
        http_response_code(500);
        echo json_encode(['error' => 'Embedding size mismatch.']);
        exit;
    }

    // 3) Query Qdrant with score threshold
    $results = queryQdrant(
        $embedding,
        $collectionName,
        $limit,
        $scoreThreshold,
        $selectedDocs,
        $timeframeDays,
        true // applyThreshold
    );

    // 4) If no results and selected_documents are provided, perform fallback search
    if (empty($results) && !empty($selectedDocs)) {
        logMessage("No results met the score threshold. Performing fallback search without threshold.");
        $fallbackLimit = 4;
        $fallbackResults = queryQdrant(
            $embedding,
            $collectionName,
            $fallbackLimit,
            0, // scoreThreshold is irrelevant since applyThreshold is false
            $selectedDocs,
            $timeframeDays,
            false // do not apply threshold
        );

        if (!empty($fallbackResults)) {
            logMessage("Fallback search returned " . count($fallbackResults) . " results.");
            $results = $fallbackResults;
        } else {
            logMessage("Fallback search also returned no results.");
        }
    }

    // 5) Log results count
    logMessage("Search completed with " . count($results) . " results.");

    // 6) Return the results
    echo json_encode([
        'results' => $results,
        'count'   => count($results)
    ]);
    exit;
} else {
    // Handle non-POST requests
    echo json_encode(['message' => 'Use POST to search Qdrant with a query.']);
    exit;
}
?>