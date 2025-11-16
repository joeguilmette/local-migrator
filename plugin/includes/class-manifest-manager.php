<?php
/**
 * File manifest generation and job management
 *
 * @package LocalPOC
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages file manifests and job lifecycle
 */
class LocalPOC_Manifest_Manager {

    /**
     * Generates a file manifest with pagination
     *
     * @param int $offset Starting offset
     * @param int $limit  Number of files to return
     * @return array Manifest data with 'files' slice and 'all_files' for job storage
     */
    public static function generate_manifest($offset, $limit) {
        $files = LocalPOC_File_Scanner::scan_file_list();

        $total = count($files);
        $total_bytes = array_sum(array_column($files, 'size'));
        $slice = array_slice($files, $offset, $limit);

        return [
            'root'        => ABSPATH,
            'offset'      => $offset,
            'limit'       => $limit,
            'total_files' => $total,
            'files'       => array_values($slice),
            'total_bytes' => $total_bytes,
            'all_files'   => $files,  // Only used for save_job, removed before response
        ];
    }

    /**
     * Saves a job with file list to chunked transient storage
     *
     * Large file lists are split into chunks to avoid exceeding WordPress limits.
     *
     * @param array $files File list
     * @return string Job ID
     */
    public static function save_job(array $files) {
        $job_id = wp_generate_password(20, false, false);
        $chunk_size = 2000; // Files per chunk
        $chunks = array_chunk($files, $chunk_size);
        $ttl = 15 * MINUTE_IN_SECONDS;

        // Save metadata
        $metadata = [
            'created_at'   => time(),
            'total_files'  => count($files),
            'total_bytes'  => array_sum(array_column($files, 'size')),
            'chunk_count'  => count($chunks),
        ];
        set_transient('localpoc_job_meta_' . $job_id, $metadata, $ttl);

        // Save file chunks
        foreach ($chunks as $index => $chunk) {
            set_transient('localpoc_job_chunk_' . $job_id . '_' . $index, $chunk, $ttl);
        }

        return $job_id;
    }

    /**
     * Retrieves a job from chunked transient storage
     *
     * Reassembles file chunks into complete job data.
     *
     * @param string $job_id Job ID
     * @return array|false Job data or false if not found
     */
    public static function get_job($job_id) {
        $metadata = get_transient('localpoc_job_meta_' . $job_id);
        if (!is_array($metadata)) {
            return false;
        }

        $chunk_count = $metadata['chunk_count'] ?? 0;
        $files = [];

        // Reassemble files from chunks
        for ($i = 0; $i < $chunk_count; $i++) {
            $chunk = get_transient('localpoc_job_chunk_' . $job_id . '_' . $i);
            if (!is_array($chunk)) {
                return false; // Missing chunk
            }
            $files = array_merge($files, $chunk);
        }

        return [
            'created_at'  => $metadata['created_at'],
            'total_files' => $metadata['total_files'],
            'total_bytes' => $metadata['total_bytes'],
            'files'       => $files,
        ];
    }

    /**
     * Deletes a job and all its chunks from transient storage
     *
     * @param string $job_id Job ID
     */
    public static function delete_job($job_id) {
        $metadata = get_transient('localpoc_job_meta_' . $job_id);
        if (is_array($metadata)) {
            $chunk_count = $metadata['chunk_count'] ?? 0;
            for ($i = 0; $i < $chunk_count; $i++) {
                delete_transient('localpoc_job_chunk_' . $job_id . '_' . $i);
            }
        }
        delete_transient('localpoc_job_meta_' . $job_id);
    }

    /**
     * Gets a paginated slice of the manifest
     *
     * If job_id is provided, uses cached job data.
     * Otherwise, scans filesystem and creates new job.
     *
     * @param string $job_id Optional job ID
     * @param int    $offset Starting offset
     * @param int    $limit  Number of files to return
     * @return array|WP_Error Manifest slice or WP_Error
     */
    public static function get_manifest_slice($job_id, $offset, $limit) {
        try {
            if ($job_id) {
                $job = self::get_job($job_id);
                if (!$job) {
                    return new WP_Error(
                        'localpoc_job_missing',
                        __('Manifest job not found or expired.', 'localpoc'),
                        ['status' => 404]
                    );
                }
                $files = $job['files'];
                $total_files = $job['total_files'] ?? count($files);
                $total_bytes = $job['total_bytes'] ?? array_sum(array_column($files, 'size'));
                $slice = array_slice($files, $offset, $limit);

                return [
                    'root'        => ABSPATH,
                    'offset'      => $offset,
                    'limit'       => $limit,
                    'total_files' => $total_files,
                    'total_bytes' => $total_bytes,
                    'files'       => array_values($slice),
                    'job_id'      => $job_id,
                ];
            }

            $manifest = self::generate_manifest($offset, $limit);
            $job_id = self::save_job($manifest['all_files']);
            unset($manifest['all_files']);
            $manifest['job_id'] = $job_id;
            return $manifest;
        } catch (UnexpectedValueException $e) {
            return new WP_Error(
                'localpoc_manifest_error',
                __('Unable to build file manifest.', 'localpoc'),
                ['status' => 500]
            );
        }
    }

    /**
     * Creates a new manifest job
     *
     * @param string $mode Mode parameter (reserved for future async support)
     * @return array|WP_Error Job info or WP_Error
     */
    public static function create_manifest_job($mode = 'default') {
        // TODO: support asynchronous scan modes via $mode parameter.
        try {
            $files = LocalPOC_File_Scanner::scan_file_list();
        } catch (UnexpectedValueException $e) {
            return new WP_Error(
                'localpoc_manifest_error',
                __('Unable to build file manifest.', 'localpoc'),
                ['status' => 500]
            );
        }

        $job_id = self::save_job($files);
        $job = self::get_job($job_id);

        if (!$job) {
            return new WP_Error(
                'localpoc_job_save_failed',
                __('Failed to save manifest job.', 'localpoc'),
                ['status' => 500]
            );
        }

        return [
            'job_id'      => $job_id,
            'total_files' => $job['total_files'] ?? count($files),
            'total_bytes' => $job['total_bytes'] ?? array_sum(array_column($files, 'size')),
            'created_at'  => $job['created_at'],
        ];
    }

    /**
     * Finishes and cleans up a manifest job
     *
     * @param string $job_id Job ID
     * @return true|WP_Error True on success, WP_Error if job_id missing
     */
    public static function finish_manifest_job($job_id) {
        if (empty($job_id)) {
            return new WP_Error(
                'localpoc_job_missing',
                __('Manifest job ID is required.', 'localpoc'),
                ['status' => 400]
            );
        }

        self::delete_job($job_id);
        return true;
    }
}
