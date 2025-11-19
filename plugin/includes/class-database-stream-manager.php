<?php
class LocalPOC_Database_Stream_Manager {

    // Constants
    const STREAM_VERSION = '1.0-poc';
    const DEFAULT_CHUNK_ROWS = 1000;
    const MAX_CHUNK_ROWS = 5000;
    const KEYSET_THRESHOLD_ROWS = 100000;
    const KEYSET_THRESHOLD_BYTES = 104857600; // 100MB

    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Initialize streaming session
     *
     * @param array $options ['chunk_size' => int, 'compression' => 'none'|'gzip']
     * @return array ['cursor' => string, 'sql_header' => string, 'metadata' => array]
     */
    public function init_stream($options = []) {
        $chunk_size = isset($options['chunk_size'])
            ? max(100, min((int)$options['chunk_size'], self::MAX_CHUNK_ROWS))
            : self::DEFAULT_CHUNK_ROWS;

        // Get table list exactly as current exporter does
        $tables = $this->wpdb->get_col("SHOW TABLES");

        // Build metadata using existing logic from class-database-job-manager.php
        $total_rows = 0;
        $total_bytes = 0;
        $table_info = [];

        foreach ($tables as $table) {
            $status = $this->wpdb->get_row(
                $this->wpdb->prepare("SHOW TABLE STATUS LIKE %s", $table)
            );
            $rows = (int)$status->Rows;
            $bytes = (int)$status->Data_length + (int)$status->Index_length;

            $total_rows += $rows;
            $total_bytes += $bytes;
            $table_info[$table] = [
                'rows' => $rows,
                'bytes' => $bytes,
                'use_keyset' => ($rows > self::KEYSET_THRESHOLD_ROWS || $bytes > self::KEYSET_THRESHOLD_BYTES)
            ];
        }

        // Create initial cursor
        $cursor = [
            'session_id' => 'poc_' . uniqid(),
            'table_index' => 0,
            'table_name' => $tables[0] ?? '',
            'offset' => 0,
            'last_primary_key' => null,
            'schema_sent' => false,
            'chunk_size' => $chunk_size,
            'is_complete' => empty($tables),
            'tables' => $tables,
            'table_info' => $table_info
        ];

        // Generate SQL header (copy exact format from class-database-job-manager.php)
        $sql_header = "-- LocalPOC Database Export\n";
        $sql_header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql_header .= "-- WordPress Version: " . get_bloginfo('version') . "\n\n";
        $sql_header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $sql_header .= "SET time_zone = '+00:00';\n";
        $sql_header .= "SET foreign_key_checks = 0;\n\n";
        $sql_header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $sql_header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $sql_header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $sql_header .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

        return [
            'cursor' => base64_encode(json_encode($cursor)),
            'sql_header' => base64_encode($sql_header),
            'metadata' => [
                'tables' => $tables,
                'total_tables' => count($tables),
                'total_rows' => $total_rows,
                'total_bytes' => $total_bytes,
                'chunk_size' => $chunk_size
            ]
        ];
    }

    /**
     * Stream next chunk of SQL
     *
     * @param string $cursor_encoded Base64 encoded cursor
     * @param array $options ['time_budget' => int, 'compression' => 'none'|'gzip']
     * @return array ['sql_chunk' => string, 'cursor' => string, 'is_complete' => bool, 'progress' => array, 'performance' => array]
     */
    public function stream_chunk($cursor_encoded, $options = []) {
        $start_time = microtime(true);
        $time_budget = isset($options['time_budget']) ? (int)$options['time_budget'] : 5;
        $compression = isset($options['compression']) ? $options['compression'] : 'none';

        // Decode cursor
        $cursor = json_decode(base64_decode($cursor_encoded), true);
        if (!$cursor) {
            throw new Exception('Invalid cursor');
        }

        $sql_buffer = '';
        $rows_sent = 0;
        $query_time = 0;

        // Process current table
        $table = $cursor['table_name'];
        $progress_table_name = $table;
        $progress_table_index = $cursor['table_index'];
        if ($table && $cursor['table_index'] < count($cursor['tables'])) {

            // Send CREATE TABLE on first chunk for this table
            if (!$cursor['schema_sent']) {
                $create = $this->wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
                $sql_buffer .= "\n-- Table: $table\n";
                $sql_buffer .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql_buffer .= $create[1] . ";\n\n";
                $sql_buffer .= "LOCK TABLES `$table` WRITE;\n";
                $sql_buffer .= "/*!40000 ALTER TABLE `$table` DISABLE KEYS */;\n\n";
                $cursor['schema_sent'] = true;
            }

            // Determine pagination method
            $use_keyset = false;
            $primary_key = null;

            if ($cursor['table_info'][$table]['use_keyset']) {
                // Cache the primary key lookup so we hit SHOW KEYS at most once per table
                if (!array_key_exists('primary_key', $cursor['table_info'][$table])) {
                    $keys = $this->wpdb->get_results("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A);
                    if ($keys && count($keys) == 1) {
                        $cursor['table_info'][$table]['primary_key'] = $keys[0]['Column_name'];
                    } else {
                        $cursor['table_info'][$table]['primary_key'] = null;
                    }
                }

                if (!empty($cursor['table_info'][$table]['primary_key'])) {
                    $primary_key = $cursor['table_info'][$table]['primary_key'];
                    $use_keyset = true;
                }
            }

            // Build and execute query
            $query_start = microtime(true);

            if ($use_keyset && $primary_key && $cursor['last_primary_key'] !== null) {
                // Keyset pagination
                $query = $this->wpdb->prepare(
                    "SELECT * FROM `$table` WHERE `$primary_key` > %s ORDER BY `$primary_key` LIMIT %d",
                    $cursor['last_primary_key'],
                    $cursor['chunk_size']
                );
            } else {
                // Offset pagination
                $query = $this->wpdb->prepare(
                    "SELECT * FROM `$table` LIMIT %d, %d",
                    $cursor['offset'],
                    $cursor['chunk_size']
                );
            }

            $rows = $this->wpdb->get_results($query, ARRAY_A);
            $query_time = (microtime(true) - $query_start) * 1000;

            // Format rows as INSERT statements
            if ($rows) {
                $columns = array_keys($rows[0]);
                $column_list = '`' . implode('`, `', $columns) . '`';

                foreach ($rows as $row) {
                    // Check time budget
                    if (microtime(true) - $start_time > $time_budget) {
                        break;
                    }

                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $this->wpdb->_real_escape($value) . "'";
                        }
                    }

                    $sql_buffer .= "INSERT INTO `$table` ($column_list) VALUES (" . implode(', ', $values) . ");\n";
                    $rows_sent++;

                    // Update cursor position
                    if ($use_keyset && $primary_key) {
                        $cursor['last_primary_key'] = $row[$primary_key];
                    }
                }

                // Update offset for fallback mode
                if (!$use_keyset) {
                    $cursor['offset'] += $rows_sent;
                }
            }

            // Check if table is complete
            if (count($rows) < $cursor['chunk_size']) {
                // Table done, add closing statements
                $sql_buffer .= "\n/*!40000 ALTER TABLE `$table` ENABLE KEYS */;\n";
                $sql_buffer .= "UNLOCK TABLES;\n\n";

                // Move to next table
                $cursor['table_index']++;
                if ($cursor['table_index'] < count($cursor['tables'])) {
                    $cursor['table_name'] = $cursor['tables'][$cursor['table_index']];
                    $cursor['offset'] = 0;
                    $cursor['last_primary_key'] = null;
                    $cursor['schema_sent'] = false;
                } else {
                    $cursor['is_complete'] = true;

                    // Add SQL footer
                    $sql_buffer .= "\n-- Export completed\n";
                    $sql_buffer .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
                    $sql_buffer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
                    $sql_buffer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
                }
            }
        }

        // Adaptive chunk size adjustment
        $total_time = (microtime(true) - $start_time) * 1000;
        if ($total_time < 1000 && $cursor['chunk_size'] < self::MAX_CHUNK_ROWS) {
            $cursor['chunk_size'] = min(self::MAX_CHUNK_ROWS, $cursor['chunk_size'] * 1.5);
        } elseif ($total_time > 3000 && $cursor['chunk_size'] > 100) {
            $cursor['chunk_size'] = max(100, $cursor['chunk_size'] * 0.75);
        }
        $cursor['chunk_size'] = (int)$cursor['chunk_size'];

        // Apply compression if requested
        $sql_final = $sql_buffer;
        if ($compression === 'gzip' && function_exists('gzencode')) {
            $sql_final = gzencode($sql_buffer, 6); // level 6 for balance of speed/compression
        }

        return [
            'sql_chunk' => base64_encode($sql_final),
            'cursor' => base64_encode(json_encode($cursor)),
            'is_complete' => $cursor['is_complete'],
            'progress' => [
                'current_table' => $progress_table_name,
                'current_table_index' => $progress_table_index,
                'tables_completed' => $progress_table_index,
                'rows_in_chunk' => $rows_sent,
                'bytes_in_chunk' => strlen($sql_buffer)
            ],
            'performance' => [
                'query_time_ms' => $query_time,
                'total_time_ms' => $total_time,
                'compression' => $compression,
                'chunk_size_used' => $cursor['chunk_size']
            ]
        ];
    }
}