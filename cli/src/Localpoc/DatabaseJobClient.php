<?php

namespace Localpoc;

use RuntimeException;

/**
 * Database job client for CLI-side operations
 *
 * Handles communication with the WordPress plugin for database export operations
 */
class DatabaseJobClient
{
    /**
     * Initialize a new database export job
     *
     * @param string $adminAjaxUrl WordPress admin-ajax.php URL
     * @param string $key Authentication key
     * @return array Job information with job_id, bytes_written, estimated_bytes, etc.
     * @throws RuntimeException On initialization failure
     */
    public static function initJob(string $adminAjaxUrl, string $key): array
    {
        $response = Http::postJson($adminAjaxUrl, [
            'action' => 'localpoc_db_job_init'
        ], $key);

        if (isset($response['error'])) {
            throw new RuntimeException('Failed to initialize database job: ' . $response['error']);
        }

        if (!isset($response['job_id'])) {
            throw new RuntimeException('Invalid response from database job initialization');
        }

        return $response;
    }

    /**
     * Process a chunk of the database export
     *
     * @param string $adminAjaxUrl WordPress admin-ajax.php URL
     * @param string $key Authentication key
     * @param string $jobId Job identifier
     * @param int|null $timeBudget Time budget in milliseconds (optional)
     * @return array Progress information including bytes_written, done flag, etc.
     * @throws RuntimeException On processing failure
     */
    public static function processChunk(
        string $adminAjaxUrl,
        string $key,
        string $jobId,
        ?int $timeBudget = null
    ): array {
        $data = [
            'action' => 'localpoc_db_job_process',
            'job_id' => $jobId
        ];
        if ($timeBudget !== null) {
            $data['time_budget'] = $timeBudget;
        }

        $response = Http::postJson($adminAjaxUrl, $data, $key);

        if (isset($response['error'])) {
            throw new RuntimeException('Failed to process database chunk: ' . $response['error']);
        }

        if (!isset($response['done'])) {
            throw new RuntimeException('Invalid response from database job processing');
        }

        return $response;
    }

    /**
     * Download the completed database SQL file
     *
     * @param string $adminAjaxUrl WordPress admin-ajax.php URL
     * @param string $key Authentication key
     * @param string $jobId Job identifier
     * @param string $destPath Local destination path for the SQL file
     * @param callable|null $progressCallback Optional callback for progress updates
     * @return void
     * @throws RuntimeException On download failure
     */
    public static function downloadDatabase(
        string $adminAjaxUrl,
        string $key,
        string $jobId,
        string $destPath,
        ?callable $progressCallback = null
    ): void {
        // Use Http::streamToFile for streaming download
        try {
            Http::streamToFile(
                $adminAjaxUrl,                           // string $url
                [                                        // array $params
                    'action' => 'localpoc_db_job_download',
                    'job_id' => $jobId
                ],
                $key,                                    // string $key
                $destPath,                               // string $destPath
                600,                                     // int $timeout (10 minutes)
                null,                                    // $multiHandle
                $progressCallback,                       // ?callable $progressCallback
                false                                    // bool $jsonBody (use form encoding)
            );
        } catch (\Localpoc\HttpException $e) {
            // Clean up partial file on failure
            if (file_exists($destPath)) {
                @unlink($destPath);
            }
            throw new RuntimeException('Failed to download database: ' . $e->getMessage());
        }

        // Verify the downloaded file
        if (!file_exists($destPath)) {
            throw new RuntimeException('Database file was not created at destination path');
        }

        $fileSize = filesize($destPath);
        if ($fileSize === 0) {
            @unlink($destPath);
            throw new RuntimeException('Downloaded database file is empty');
        }
    }

    /**
     * Finish and clean up a database export job
     *
     * @param string $adminAjaxUrl WordPress admin-ajax.php URL
     * @param string $key Authentication key
     * @param string $jobId Job identifier
     * @return void
     * @throws RuntimeException On cleanup failure
     */
    public static function finishJob(string $adminAjaxUrl, string $key, string $jobId): void
    {
        $response = Http::postJson($adminAjaxUrl, [
            'action' => 'localpoc_db_job_finish',
            'job_id' => $jobId
        ], $key);

        // The finish endpoint might not return data, just check for errors
        if (isset($response['error'])) {
            // Don't throw exception for cleanup failures, just log
            // The server-side cleanup might have already happened
            error_log('Warning: Failed to finish database job: ' . $response['error']);
        }
    }

    /**
     * Get database metadata
     *
     * @param string $adminAjaxUrl WordPress admin-ajax.php URL
     * @param string $key Authentication key
     * @return array Database metadata including total_tables, total_rows, estimated_bytes
     * @throws RuntimeException On metadata retrieval failure
     */
    public static function getDatabaseMeta(string $adminAjaxUrl, string $key): array
    {
        $response = Http::postJson($adminAjaxUrl, [
            'action' => 'localpoc_db_meta'
        ], $key);

        if (isset($response['error'])) {
            throw new RuntimeException('Failed to get database metadata: ' . $response['error']);
        }

        if (!isset($response['total_tables'])) {
            throw new RuntimeException('Invalid response from database metadata endpoint');
        }

        return $response;
    }
}