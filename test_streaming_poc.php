#!/usr/bin/env php
<?php
/**
 * POC Test for streaming database implementation
 * This verifies that the classes are loadable and basic structure is correct
 */

require_once __DIR__ . '/cli/vendor/autoload.php';

use Localpoc\StreamingDatabaseClient;
use Localpoc\Http;

echo "Testing streaming database POC...\n\n";

// Test 1: Verify StreamingDatabaseClient class exists
if (!class_exists('Localpoc\StreamingDatabaseClient')) {
    echo "❌ StreamingDatabaseClient class not found\n";
    exit(1);
}
echo "✅ StreamingDatabaseClient class exists\n";

// Test 2: Verify required methods exist
$requiredMethods = ['initStream', 'fetchChunk', 'streamToFile'];
$missingMethods = [];

foreach ($requiredMethods as $method) {
    if (!method_exists('Localpoc\StreamingDatabaseClient', $method)) {
        $missingMethods[] = $method;
    }
}

if (empty($missingMethods)) {
    echo "✅ All required methods exist in StreamingDatabaseClient\n";
} else {
    echo "❌ Missing methods in StreamingDatabaseClient: " . implode(', ', $missingMethods) . "\n";
    exit(1);
}

// Test 3: Verify DownloadOrchestrator uses streaming
$orchestratorPath = __DIR__ . '/cli/src/Localpoc/DownloadOrchestrator.php';
$orchestratorCode = file_get_contents($orchestratorPath);

if (strpos($orchestratorCode, 'StreamingDatabaseClient') !== false) {
    echo "✅ DownloadOrchestrator imports StreamingDatabaseClient\n";
} else {
    echo "❌ DownloadOrchestrator does not import StreamingDatabaseClient\n";
    exit(1);
}

if (strpos($orchestratorCode, '$useStreaming = true') !== false) {
    echo "✅ DownloadOrchestrator has streaming flag enabled\n";
} else {
    echo "❌ DownloadOrchestrator does not have streaming flag\n";
    exit(1);
}

if (strpos($orchestratorCode, 'streamToFile') !== false) {
    echo "✅ DownloadOrchestrator calls streamToFile method\n";
} else {
    echo "❌ DownloadOrchestrator does not call streamToFile\n";
    exit(1);
}

// Test 4: Verify plugin files exist
$pluginFiles = [
    '/plugin/includes/class-database-stream-manager.php' => 'Database Stream Manager',
];

foreach ($pluginFiles as $file => $name) {
    $fullPath = __DIR__ . $file;
    if (file_exists($fullPath)) {
        echo "✅ Plugin file exists: $name\n";

        // Check if class is defined
        $content = file_get_contents($fullPath);
        if (strpos($content, 'class LocalPOC_Database_Stream_Manager') !== false) {
            echo "   ✅ Class LocalPOC_Database_Stream_Manager is defined\n";
        } else {
            echo "   ❌ Class LocalPOC_Database_Stream_Manager not found\n";
            exit(1);
        }
    } else {
        echo "❌ Plugin file missing: $name at $fullPath\n";
        exit(1);
    }
}

// Test 5: Check AJAX handlers
$ajaxPath = __DIR__ . '/plugin/includes/class-ajax-handlers.php';
$ajaxCode = file_get_contents($ajaxPath);

$streamingEndpoints = ['db_stream_init', 'db_stream_chunk'];
foreach ($streamingEndpoints as $endpoint) {
    if (strpos($ajaxCode, "function $endpoint") !== false) {
        echo "✅ AJAX handler exists: $endpoint\n";
    } else {
        echo "❌ AJAX handler missing: $endpoint\n";
        exit(1);
    }
}

// Test 6: Check plugin registration
$pluginPath = __DIR__ . '/plugin/includes/class-plugin.php';
$pluginCode = file_get_contents($pluginPath);

if (strpos($pluginCode, 'localpoc_db_stream_init') !== false &&
    strpos($pluginCode, 'localpoc_db_stream_chunk') !== false) {
    echo "✅ Streaming endpoints registered in plugin\n";
} else {
    echo "❌ Streaming endpoints not registered in plugin\n";
    exit(1);
}

echo "\n";
echo "========================================\n";
echo "✅ All POC tests passed successfully!\n";
echo "========================================\n";
echo "\n";
echo "The streaming database POC is ready for testing.\n";
echo "Next steps:\n";
echo "1. Deploy the plugin to a test WordPress site\n";
echo "2. Run: lm download --url='<site>' --key='<key>' --output='./test'\n";
echo "3. Monitor the output for streaming messages\n";
echo "4. Verify db.sql is created with correct content\n";