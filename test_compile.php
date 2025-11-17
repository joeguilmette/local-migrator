#!/usr/bin/env php
<?php
/**
 * Compile test - ensures all classes can be loaded and instantiated
 */

require_once __DIR__ . '/cli/vendor/autoload.php';

echo "Testing compilation and loading of streaming components...\n\n";

// Test instantiation of StreamingDatabaseClient
try {
    $http = new \Localpoc\Http();
    $client = new \Localpoc\StreamingDatabaseClient($http, 'https://example.com', 'test-key');
    echo "✅ StreamingDatabaseClient instantiated successfully\n";
} catch (Exception $e) {
    echo "❌ Failed to instantiate StreamingDatabaseClient: " . $e->getMessage() . "\n";
    exit(1);
}

// Test that DownloadOrchestrator can be instantiated
try {
    $extractor = new \Localpoc\BatchZipExtractor();
    $downloader = new \Localpoc\ConcurrentDownloader($extractor);
    $orchestrator = new \Localpoc\DownloadOrchestrator($downloader);
    echo "✅ DownloadOrchestrator instantiated successfully\n";
} catch (Exception $e) {
    echo "❌ Failed to instantiate DownloadOrchestrator: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ All compilation tests passed!\n";