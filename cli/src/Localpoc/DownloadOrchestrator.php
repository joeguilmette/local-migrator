<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;

/**
 * Orchestrates the download workflow
 */
class DownloadOrchestrator
{
    private ProgressTracker $progressTracker;
    private ConcurrentDownloader $downloader;

    public function __construct(ProgressTracker $progressTracker, ConcurrentDownloader $downloader)
    {
        $this->progressTracker = $progressTracker;
        $this->downloader = $downloader;
    }

    /**
     * Handles the complete download workflow
     *
     * @param array $options Download options
     * @return int Exit code
     */
    public function handleDownload(array $options): int
    {
        $url = $options['url'];
        $key = $options['key'];
        $outputDir = $options['output'];
        $concurrency = (int) $options['concurrency'];

        $this->info('Starting download command');
        $this->info('Output directory: ' . $outputDir);

        FileOperations::ensureOutputDir($outputDir);

        $workspace = null;
        $zipPath = null;

        try {
            $workspace = ArchiveBuilder::createTempWorkspace($outputDir);
            $this->info('Working directory: ' . $workspace);

            $filesRoot = ArchiveBuilder::getTempWpContentDir($workspace);
            $dbPath = ArchiveBuilder::getTempDbPath($workspace);

            $adminAjaxUrl = Http::buildAdminAjaxUrl($url);
            $this->info('Using API base: ' . $adminAjaxUrl);
            $this->info('Starting manifest job...');

            $jobId = null;
            $partition = null;
            $jobTotals = ['total_files' => 0, 'total_bytes' => 0];

            // Start database export job
            $this->info('Initializing database export job...');
            $dbJobInfo = DatabaseJobClient::initJob($adminAjaxUrl, $key);
            $dbJobId = $dbJobInfo['job_id'];
            $dbTotalRows = $dbJobInfo['total_rows'] ?? 0;
            $dbBytesWritten = $dbJobInfo['bytes_written'] ?? 0;

            $this->info(sprintf('DB job %s: %d tables, ~%d rows', $dbJobId, $dbJobInfo['total_tables'] ?? 0, $dbTotalRows));

            // Process database chunks
            $this->info('Processing database chunks...');
            $dbDone = false;
            while (!$dbDone) {
                $dbProgress = DatabaseJobClient::processChunk($adminAjaxUrl, $key, $dbJobId);
                $dbBytesWritten = $dbProgress['bytes_written'] ?? 0;
                $dbDone = $dbProgress['done'] ?? false;

                $this->info(sprintf(
                    'DB: Tables %d/%d, %s',
                    $dbProgress['completed_tables'] ?? 0,
                    $dbProgress['total_tables'] ?? 0,
                    $this->formatBytes($dbBytesWritten)
                ));

                if (!$dbDone) {
                    usleep(100000); // 100ms between chunks
                }
            }

            $this->info('Database chunks complete. Downloading SQL file...');
            DatabaseJobClient::downloadDatabase(
                $adminAjaxUrl,
                $key,
                $dbJobId,
                $dbPath,
                function (int $bytes): void {
                    // Progress callback during download
                }
            );
            DatabaseJobClient::finishJob($adminAjaxUrl, $key, $dbJobId);
            $this->info('Database downloaded successfully.');

            // Collect file manifest
            $this->info('Starting file manifest job...');
            try {
                $jobInfo = ManifestCollector::initializeJob($adminAjaxUrl, $key);
                $jobId = $jobInfo['job_id'];
                $jobTotals['total_files'] = (int) ($jobInfo['total_files'] ?? 0);
                $jobTotals['total_bytes'] = (int) ($jobInfo['total_bytes'] ?? 0);
                $this->info(sprintf('Manifest job %s: %d files (~%d bytes)', $jobId, $jobTotals['total_files'], $jobTotals['total_bytes']));

                $partition = ManifestCollector::collectEntries($adminAjaxUrl, $key, $jobId);
            } finally {
                ManifestCollector::finishJob($adminAjaxUrl, $key, $jobId);
            }

            if ($partition === null) {
                throw new RuntimeException('Failed to collect manifest entries.');
            }

            $fileCount = $partition['total_files'];
            $totalSize = $partition['total_bytes'];
            $largeFiles = $partition['large'];
            $batches = $partition['batches'];

            $this->progressTracker->initCounters($fileCount, $totalSize, $dbBytesWritten);

            $this->info(sprintf(
                'Manifest ready: %d files (%d bytes) -> %d large, %d batches',
                $fileCount,
                $totalSize,
                count($largeFiles),
                count($batches)
            ));

            $this->info('Starting file downloads...');

            // Download files only (no DB in concurrent downloads)
            $results = $this->downloader->downloadFilesOnly(
                $adminAjaxUrl,
                $key,
                $batches,
                $largeFiles,
                $filesRoot,
                $concurrency,
                function (int $bytes): void {
                    $this->progressTracker->incrementFileBytes($bytes);
                }
            );

            $filesDownloaded = $results['files_succeeded'] + $results['batch_files_succeeded'];
            $failures = $results['files_failed'] + $results['batch_files_failed'];

            $this->progressTracker->render(true, true);
            $this->info('Database: OK');
            $this->info(sprintf(
                'Files: %d/%d (failed %d)',
                $filesDownloaded,
                $fileCount,
                $failures
            ));
            $resolvedOutput = realpath($outputDir) ?: $outputDir;
            $this->info('Output directory: ' . $resolvedOutput);

            if ($failures > 0) {
                ArchiveBuilder::cleanupWorkspace($workspace);
                $workspace = null;
                return 3; // EXIT_HTTP
            }

            // Build archive
            $hostname = ArchiveBuilder::parseHostname($url);
            $archivesDir = FileOperations::ensureZipDirectory($outputDir);
            $archiveName = ArchiveBuilder::generateArchiveName($hostname);
            $zipPath = $archivesDir . DIRECTORY_SEPARATOR . $archiveName;
            ArchiveBuilder::createZipArchive($workspace, $zipPath);
            $archiveSize = is_file($zipPath) ? filesize($zipPath) : 0;

            $this->info(sprintf(
                'Archive created: %s (%s)',
                $zipPath,
                $this->formatBytes($archiveSize)
            ));

            ArchiveBuilder::cleanupWorkspace($workspace);
            $workspace = null;

            return 0; // EXIT_SUCCESS
        } catch (\Throwable $e) {
            if ($zipPath && is_file($zipPath)) {
                @unlink($zipPath);
            }
            throw $e;
        } finally {
            if ($workspace !== null) {
                ArchiveBuilder::cleanupWorkspace($workspace);
            }
        }
    }

    /**
     * Outputs informational message
     *
     * @param string $message Message to output
     */
    private function info(string $message): void
    {
        $this->progressTracker->ensureNewline();
        fwrite(STDOUT, "[localpoc] {$message}\n");
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }
        if ($bytes > 0) {
            return $bytes . ' B';
        }
        return '0 B';
    }
}
