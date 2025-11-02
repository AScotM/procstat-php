#!/usr/bin/env php
<?php

class ProcStat {
    private float $uptime;
    private float $hertz;
    private int $limit;
    private string $sort;
    private bool $watch;
    private int $interval;
    private bool $verbose;

    private const MAX_CMD_LENGTH = 80;
    private const DEFAULT_HERTZ = 100;
    private const MIN_UPTIME = 0.1;
    private const DEFAULT_LIMIT = 20;
    private const DEFAULT_INTERVAL = 2;

    public function __construct() {
        if (PHP_OS_FAMILY !== 'Linux') {
            fwrite(STDERR, "Error: This tool only works on Linux systems.\n");
            exit(1);
        }
        
        // Initialize verbose first since it's used in other initializations
        $this->verbose = $this->getArg('--verbose', false) !== false;
        
        $this->validateProcFilesystem();
        $this->uptime = $this->getUptime();
        $this->hertz = $this->detectHertz();
        $this->limit = max(1, (int)$this->getArg('--limit', self::DEFAULT_LIMIT));
        $this->sort = $this->getArg('--sort', 'cpu');
        $this->watch = $this->getArg('--watch', 0) > 0;
        $this->interval = max(1, (int)$this->getArg('--watch', self::DEFAULT_INTERVAL));
        
        $this->validateOptions();
    }

    private function getArg(string $name, mixed $default): mixed {
        $longOpts = [];
        if ($default === false) {
            // Boolean flag
            $longOpts = [$name];
        } else {
            // Value option
            $longOpts = [$name . ':'];
        }
        
        $args = getopt('', $longOpts);
        
        if ($default === false) {
            return isset($args[$name]);
        }
        
        return $args[$name] ?? $default;
    }

    private function validateOptions(): void {
        $validSorts = ['cpu', 'mem', 'pid', 'command'];
        if (!in_array($this->sort, $validSorts)) {
            fwrite(STDERR, "Warning: Invalid sort option '{$this->sort}'. Using 'cpu'.\n");
            $this->sort = 'cpu';
        }
        
        if ($this->limit > 1000) {
            fwrite(STDERR, "Warning: Limit too high. Capping at 1000.\n");
            $this->limit = 1000;
        }
    }

    private function validateProcFilesystem(): void {
        if (!file_exists('/proc') || !is_dir('/proc')) {
            throw new RuntimeException('/proc filesystem not available');
        }
        
        // Basic sanity checks
        if (!file_exists('/proc/self') && !file_exists('/proc/version')) {
            throw new RuntimeException('/proc does not appear to be a valid proc filesystem');
        }
    }

    private function validateProcPath(string $path): bool {
        $realpath = realpath($path);
        if (!$realpath) {
            return false;
        }
        
        // Must be under /proc and follow safe patterns
        if (strpos($realpath, '/proc/') !== 0) {
            return false;
        }
        
        // Allow specific patterns
        $allowedPatterns = [
            '#^/proc/\d+$#',
            '#^/proc/\d+/stat$#',
            '#^/proc/\d+/status$#',
            '#^/proc/\d+/cmdline$#',
        ];
        
        foreach ($allowedPatterns as $pattern) {
            if (preg_match($pattern, $realpath)) {
                return true;
            }
        }
        
        return false;
    }

    private function getUptime(): float {
        $content = @file_get_contents('/proc/uptime');
        if ($content === false) {
            throw new RuntimeException('Cannot read /proc/uptime - check permissions');
        }
        
        $parts = explode(' ', trim($content));
        if (count($parts) < 2) {
            throw new RuntimeException('Invalid format in /proc/uptime');
        }
        
        $uptime = (float)$parts[0];
        if ($uptime <= 0) {
            throw new RuntimeException('Invalid uptime value');
        }
        
        if ($this->verbose) {
            fwrite(STDERR, "Debug: System uptime: {$uptime}s\n");
        }
        
        return $uptime;
    }

    private function detectHertz(): float {
        $output = @shell_exec('getconf CLK_TCK 2>/dev/null');
        $hz = $output ? (int)trim($output) : 0;
        
        if ($hz <= 0) {
            if ($this->verbose) {
                fwrite(STDERR, "Debug: Using default HERTZ value: " . self::DEFAULT_HERTZ . "\n");
            }
            $hz = self::DEFAULT_HERTZ;
        } else {
            if ($this->verbose) {
                fwrite(STDERR, "Debug: Detected HERTZ: {$hz}\n");
            }
        }
        
        return $hz;
    }

    public function run(): void {
        if ($this->getArg('--help', false) !== false) {
            $this->showHelp();
            return;
        }
        
        if ($this->watch) {
            $this->runWatchMode();
        } else {
            $this->runOnce();
        }
    }

    private function showHelp(): void {
        echo "Process Monitor - Linux Process Statistics\n";
        echo "Usage: " . basename($_SERVER['argv'][0]) . " [OPTIONS]\n\n";
        echo "Options:\n";
        echo "  --limit=N    Show top N processes (default: " . self::DEFAULT_LIMIT . ")\n";
        echo "  --sort=TYPE  Sort by: cpu, mem, pid, command (default: cpu)\n";
        echo "  --watch=N    Refresh every N seconds, continuous mode\n";
        echo "  --verbose    Show debug information\n";
        echo "  --help       Show this help message\n\n";
        echo "Examples:\n";
        echo "  Show top 10 CPU processes:    " . basename($_SERVER['argv'][0]) . " --limit=10\n";
        echo "  Show top memory processes:    " . basename($_SERVER['argv'][0]) . " --sort=mem\n";
        echo "  Monitor continuously:         " . basename($_SERVER['argv'][0]) . " --watch=2\n";
    }

    private function runWatchMode(): void {
        $iteration = 0;
        $startTime = time();
        
        // Setup signal handling for graceful exit
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() use ($startTime) {
                $duration = time() - $startTime;
                echo "\nMonitoring stopped after {$duration} seconds.\n";
                exit(0);
            });
        }
        
        echo "Process Monitor - Refresh every {$this->interval}s (Ctrl+C to stop)\n";
        
        while (true) {
            if ($iteration++ > 0) {
                // Clear screen and move cursor to top
                echo "\033[2J\033[;H";
            }
            
            $this->displayHeader($iteration);
            $this->runOnce();
            
            // Sleep with signal checking
            $slept = 0;
            while ($slept < $this->interval) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                sleep(1);
                $slept++;
            }
        }
    }
    
    private function displayHeader(int $iteration): void {
        echo "Process Monitor - Iteration #{$iteration} - " . date('Y-m-d H:i:s') . "\n";
        echo "Sorting by: " . strtoupper($this->sort) . " | Showing top: {$this->limit} | Refresh: {$this->interval}s\n";
        echo str_repeat('=', 80) . "\n\n";
    }

    private function runOnce(): void {
        $startTime = microtime(true);
        $stats = [];
        $processCount = 0;
        $errorCount = 0;
        
        foreach (new DirectoryIterator('/proc') as $entry) {
            if ($entry->isDot()) continue;
            
            if ($entry->isDir() && ctype_digit($entry->getFilename())) {
                $pid = (int)$entry->getFilename();
                $processCount++;
                
                if ($process = $this->readProcess($pid)) {
                    $stats[] = $process;
                } else {
                    $errorCount++;
                }
            }
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($this->verbose && !$this->watch) {
            fwrite(STDERR, "Debug: Scanned {$processCount} processes, {$errorCount} errors, took {$executionTime}ms\n");
        }
        
        $this->render($stats);
        
        if (!$this->watch) {
            echo "\nTotal processes: " . count($stats) . " (scanned {$processCount})\n";
        }
    }

    private function readProcess(int $pid): ?array {
        $statPath = "/proc/{$pid}/stat";
        if (!$this->validateProcPath($statPath) || !is_readable($statPath)) {
            return null;
        }
        
        $statContent = @file_get_contents($statPath);
        if (!$statContent) {
            return null;
        }
        
        // Parse the stat file - handle process names with spaces and parentheses
        $lastParen = strrpos($statContent, ')');
        if ($lastParen === false) {
            return null;
        }
        
        $namePart = substr($statContent, 0, $lastParen + 1);
        $dataPart = substr($statContent, $lastParen + 2); // Skip ') '
        
        // Extract process name (remove parentheses)
        $processName = trim($namePart, '()');
        
        // Parse the remaining fields
        $fields = explode(' ', $dataPart);
        if (count($fields) < 21) {
            return null;
        }
        
        // Field indices are shifted because we removed the command name part
        // Original indices from proc man page: utime=14, stime=15, cutime=16, cstime=17, starttime=22
        // Our indices are: utime=13, stime=14, cutime=15, cstime=16, starttime=21
        [$utime, $stime, $cutime, $cstime, $starttime] = [
            (float)($fields[13] ?? 0),
            (float)($fields[14] ?? 0),  
            (float)($fields[15] ?? 0),
            (float)($fields[16] ?? 0),
            (float)($fields[21] ?? 0)
        ];
        
        // Calculate CPU usage percentage
        $totalTime = $utime + $stime + $cutime + $cstime;
        $secondsElapsed = $this->uptime - ($starttime / $this->hertz);
        
        if ($secondsElapsed <= 0) {
            $cpuUsage = 0.0;
        } else {
            $cpuUsage = round(100 * (($totalTime / $this->hertz) / $secondsElapsed), 1);
            // Cap at 100% to handle edge cases
            $cpuUsage = min($cpuUsage, 100.0);
        }

        $memoryUsage = $this->getMemoryUsage($pid);
        $command = $this->getProcessCommand($pid, $processName);

        return [
            'PID' => $pid,
            'CPU%' => $cpuUsage,
            'MEM(MB)' => $memoryUsage,
            'CMD' => $command,
            'TIME' => $totalTime / $this->hertz // Total CPU time in seconds
        ];
    }

    private function getMemoryUsage(int $pid): float {
        $statusPath = "/proc/{$pid}/status";
        if (!$this->validateProcPath($statusPath) || !is_readable($statusPath)) {
            return 0.0;
        }
        
        $content = @file_get_contents($statusPath);
        if (!$content) {
            return 0.0;
        }
        
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'VmRSS:')) {
                $kb = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                return round($kb / 1024, 1);
            }
        }
        
        return 0.0;
    }

    private function getProcessCommand(int $pid, string $defaultName): string {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (!$this->validateProcPath($cmdlinePath) || !is_readable($cmdlinePath)) {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $cmdline = @file_get_contents($cmdlinePath);
        if (!$cmdline) {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $command = trim(str_replace("\0", ' ', $cmdline));
        if ($command === '') {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        // Sanitize command for safe display
        $command = $this->sanitizeOutput($command);
        
        return mb_strimwidth($command, 0, self::MAX_CMD_LENGTH, '…', 'UTF-8');
    }
    
    private function sanitizeOutput(string $text): string {
        // Convert to UTF-8 and remove control characters except tab and newline
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[^\x20-\x7E\t\n]/u', '?', $text);
        return $text;
    }

    private function render(array $data): void {
        if (empty($data)) {
            echo "No process data available. Check permissions or system status.\n";
            return;
        }
        
        $sortKey = match ($this->sort) {
            'mem' => 'MEM(MB)',
            'pid' => 'PID',
            'command' => 'CMD',
            default => 'CPU%',
        };
        
        // Sort processes
        usort($data, function($a, $b) use ($sortKey) {
            $result = $b[$sortKey] <=> $a[$sortKey];
            // Secondary sort by PID for stability
            return $result !== 0 ? $result : $a['PID'] <=> $b['PID'];
        });
        
        // Display header
        printf("%-6s %-6s %-10s %s\n", 'PID', 'CPU%', 'MEM(MB)', 'COMMAND');
        echo str_repeat('-', 80) . "\n";
        
        // Display processes
        $displayData = array_slice($data, 0, $this->limit);
        foreach ($displayData as $process) {
            printf("%-6d %-6.1f %-10.1f %s\n", 
                $process['PID'], 
                $process['CPU%'], 
                $process['MEM(MB)'], 
                $process['CMD']
            );
        }
        
        // Show summary stats
        $totalMemory = array_sum(array_column($displayData, 'MEM(MB)'));
        $totalCpu = array_sum(array_column($displayData, 'CPU%'));
        
        if (!$this->watch) {
            echo str_repeat('-', 80) . "\n";
            printf("Top %d processes: %.1f%% CPU, %.1f MB MEM\n", 
                count($displayData), $totalCpu, $totalMemory);
        }
    }
}

// Main execution with error handling
try {
    $procStat = new ProcStat();
    $procStat->run();
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Try running with --help for usage information.\n");
    exit(1);
}
