<?php
// config.php

// Pinecone API Key
define('QDRANT_API_KEY', 'YOURKEY');
define('QDRANT_ENDPOINT', 'https://YOURENDPOINT.europe-west3-0.gcp.cloud.qdrant.io');

// Define base collection naming patterns
define('USER_DOCUMENTS_PREFIX', 'user_%d_documents');       // e.g., user_1_documents
define('USER_CONVERSATIONS_PREFIX', 'user_%d_conversations'); // e.g., user_1_conversations

define('JINA_API_KEY', 'jina_YOURKEY'); 

// Jina Embeddings API URL
define('JINA_API_URL', 'https://api.jina.ai/v1/embeddings'); 

// Log file paths
define('UPLOAD_CHUNKS_LOG_FILE', __DIR__ . '/upload_chunks_debug.log');
define('SEARCH_CHUNKS_LOG_FILE', __DIR__ . '/search_chunks_debug.log');
define('EMBEDDINGS_LOG_FILE', __DIR__ . '/embeddings_debug.log');
define('LIST_DOCUMENTS_LOG_FILE', __DIR__ . '/list_documents.log');
define('VECTOR_SIZE', 1024);
// CORS Configuration (Replace '*' with your actual frontend origin for enhanced security)
define('CORS_ORIGIN', '*'); 
?>