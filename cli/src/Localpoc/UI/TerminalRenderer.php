<?php
namespace Localpoc\UI;

class TerminalRenderer
{
    private bool $plainMode;
    private bool $isInteractive;
    private bool $supportsAnsi;
    private int $lastLineCount = 0;
    private float $lastRender = 0;

    private array $state = [
        'site_url' => '',
        'output_dir' => '',
        'db_current' => 0,
        'db_total' => 0,
        'files_current' => 0,
        'files_total' => 0,
        'files_failed' => 0,
        'bytes_current' => 0,
        'bytes_total' => 0,
    ];

    public function __construct(bool $plainMode = false)
    {
        $this->plainMode = $plainMode;
        $this->supportsAnsi = $this->detectAnsiSupport();
        $this->isInteractive = !$plainMode && $this->supportsAnsi && $this->isTerminalInteractive();
    }

    private function detectAnsiSupport(): bool
    {
        $term = getenv('TERM');
        if ($term === false || $term === 'dumb') {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false ||
                   getenv('ConEmuANSI') === 'ON' ||
                   getenv('TERM_PROGRAM') === 'vscode' ||
                   getenv('WT_SESSION') !== false;
        }

        return true;
    }

    private function isTerminalInteractive(): bool
    {
        if (function_exists('posix_isatty')) {
            return posix_isatty(STDOUT);
        }
        return stream_isatty(STDOUT);
    }

    public function initialize(string $siteUrl, string $outputDir): void
    {
        $this->state['site_url'] = $siteUrl;
        $this->state['output_dir'] = $outputDir;

        if ($this->plainMode || !$this->isInteractive) {
            echo "[localpoc] Starting download from {$siteUrl}\n";
            echo "[localpoc] Output directory: {$outputDir}\n";
        } else {
            $this->render();
        }
    }

    public function setDatabaseTotal(int $bytes): void
    {
        $this->state['db_total'] = $bytes;
        $this->render();
    }

    public function updateDatabase(int $bytes): void
    {
        $this->state['db_current'] = $bytes;
        $this->render();
    }

    public function setFilesTotal(int $count, int $bytes): void
    {
        $this->state['files_total'] = $count;
        $this->state['bytes_total'] = $bytes;
        $this->render();
    }

    public function updateFiles(int $completed, int $failed, int $bytes): void
    {
        $this->state['files_current'] = $completed;
        $this->state['files_failed'] = $failed;
        $this->state['bytes_current'] = $bytes;
        $this->render();
    }

    public function log(string $message): void
    {
        if ($this->plainMode || !$this->isInteractive) {
            echo "[localpoc] {$message}\n";
        }
        // In rich mode, suppress logs to keep display clean
    }

    public function error(string $message): void
    {
        if ($this->isInteractive) {
            $this->clearScreen();
        }
        fwrite(STDERR, "[ERROR] {$message}\n");
    }

    public function showSummary(string $archivePath, int $archiveSize): void
    {
        if ($this->isInteractive) {
            $this->clearScreen();
        }

        $compression = $this->state['bytes_total'] > 0
            ? (1 - $archiveSize / $this->state['bytes_total']) * 100
            : 0;

        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "✓ Download Complete\n";
        echo "───────────────────────────────────────────────────────\n";
        echo sprintf("  Database : %s\n", $this->formatBytes($this->state['db_total']));
        echo sprintf("  Files    : %d files (%s)\n",
            $this->state['files_total'],
            $this->formatBytes($this->state['bytes_total'])
        );
        if ($this->state['files_failed'] > 0) {
            echo sprintf("  Failed   : %d files\n", $this->state['files_failed']);
        }
        echo "───────────────────────────────────────────────────────\n";
        echo sprintf("  Archive  : %s\n", $archivePath);
        echo sprintf("  Size     : %s (%.1f%% compression)\n",
            $this->formatBytes($archiveSize), $compression
        );
        echo "═══════════════════════════════════════════════════════\n\n";
    }

    private function render(): void
    {
        if ($this->plainMode || !$this->isInteractive) {
            // Periodic plain text updates
            $now = microtime(true);
            if ($now - $this->lastRender >= 5.0) {
                $this->printPlainProgress();
                $this->lastRender = $now;
            }
            return;
        }

        // Throttle to ~10 FPS
        $now = microtime(true);
        if ($now - $this->lastRender < 0.1) {
            return;
        }
        $this->lastRender = $now;

        $this->clearScreen();

        $output = [];
        $output[] = "╭─────────────────────────────────────────────────────╮";
        $output[] = "│ LocalPOC Site Downloader                            │";
        $output[] = "├─────────────────────────────────────────────────────┤";
        $output[] = sprintf("│ Site: %-46s│", substr($this->state['site_url'], 0, 46));
        $output[] = sprintf("│ Output: %-44s│", substr($this->state['output_dir'], 0, 44));
        $output[] = "├─────────────────────────────────────────────────────┤";

        // Database progress
        $dbPercent = $this->state['db_total'] > 0
            ? min(100, ($this->state['db_current'] / $this->state['db_total']) * 100)
            : 0;
        $output[] = sprintf("│ Database: %s / %s (%.1f%%)%s│",
            str_pad($this->formatBytes($this->state['db_current']), 10),
            str_pad($this->formatBytes($this->state['db_total']), 10),
            $dbPercent,
            str_repeat(' ', 15)
        );
        $output[] = "│ " . $this->makeProgressBar($dbPercent, 52) . " │";

        // Files progress
        $filesPercent = $this->state['files_total'] > 0
            ? min(100, ($this->state['files_current'] / $this->state['files_total']) * 100)
            : 0;
        $output[] = sprintf("│ Files: %d / %d (%.1f%%)%s│",
            $this->state['files_current'],
            $this->state['files_total'],
            $filesPercent,
            str_repeat(' ', 25)
        );
        $output[] = "│ " . $this->makeProgressBar($filesPercent, 52) . " │";

        if ($this->state['files_failed'] > 0) {
            $output[] = sprintf("│ Failed: %d files%s│",
                $this->state['files_failed'],
                str_repeat(' ', 36)
            );
        }

        $output[] = "╰─────────────────────────────────────────────────────╯";

        echo implode("\n", $output) . "\n";
        $this->lastLineCount = count($output);
    }

    private function clearScreen(): void
    {
        if ($this->isInteractive && $this->lastLineCount > 0) {
            echo "\033[{$this->lastLineCount}A";
            for ($i = 0; $i < $this->lastLineCount; $i++) {
                echo "\033[2K";
                if ($i < $this->lastLineCount - 1) {
                    echo "\033[1B";
                }
            }
            echo "\033[{$this->lastLineCount}A";
        }
    }

    private function makeProgressBar(float $percent, int $width): string
    {
        $filled = (int)($width * $percent / 100);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        if (!$this->supportsAnsi) {
            return $bar;
        }

        if ($percent >= 100) {
            return "\033[32m" . $bar . "\033[0m";
        } else {
            return "\033[36m" . substr($bar, 0, $filled) . "\033[0m" . substr($bar, $filled);
        }
    }

    private function printPlainProgress(): void
    {
        echo sprintf("[localpoc] DB: %s/%s | Files: %d/%d | Failed: %d\n",
            $this->formatBytes($this->state['db_current']),
            $this->formatBytes($this->state['db_total']),
            $this->state['files_current'],
            $this->state['files_total'],
            $this->state['files_failed']
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}