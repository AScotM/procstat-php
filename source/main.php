#!/usr/bin/env php
<?php

declare(strict_types=1);

class ProcStat {
    private float $hertz;
    private int $limit;
    private string $sort;
    private bool $watch;
    private int $interval;
    private bool $verbose;
    private bool $zombie;
    private bool $threads;
    private int $threadLimit;

    private const MAX_CMD_LENGTH = 80;
    private const DEFAULT_HERTZ = 100;
    private const MIN_UPTIME = 0.1;
    private const DEFAULT_LIMIT = 20;
    private const DEFAULT_INTERVAL = 2;
    private const MAX_PID_SCAN = 32768;
    private const MAX_THREADS_PER_PROC = 1000;
    private const VALID_SORTS = ['cpu', 'mem', 'pid', 'command', 'time'];

    private bool $shutdownRequested = false;

    public function __construct() {
        if (PHP_OS_FAMILY !== 'Linux') {
            fwrite(STDERR, "Error: This tool only works on Linux systems.\n");
            exit(1);
        }
        
        $this->parseArguments();
        $this->validateProcFilesystem();
        $this->hertz = $this->detectHertz();
        $this->validateOptions();
    }

    private function parseArguments(): void {
        $shortOpts = "h";
        $longOpts = [
            "help",
            "limit:",
            "sort:",
            "watch::",
            "verbose",
            "zombie",
            "threads",
            "thread-limit:",
        ];

        $options = getopt($shortOpts, $longOpts);

        if (isset($options['h']) || isset($options['help'])) {
            $this->showHelp();
            exit(0);
        }

        $this->limit = (int)($options['limit'] ?? self::DEFAULT_LIMIT);
        $this->sort = $options['sort'] ?? 'cpu';
        $this->verbose = isset($options['verbose']);
        $this->zombie = isset($options['zombie']);
        $this->threads = isset($options['threads']);
        $this->threadLimit = (int)($options['thread-limit'] ?? self::MAX_THREADS_PER_PROC);
        
        if (isset($options['watch'])) {
            $this->watch = true;
            $interval = $options['watch'] === false ? self::DEFAULT_INTERVAL : (int)$options['watch'];
            $this->interval = max(1, $interval);
        } else {
            $this->watch = false;
            $this->interval = self::DEFAULT_INTERVAL;
        }
    }

    private function validateOptions(): void {
        if (!in_array($this->sort, self::VALID_SORTS)) {
            fwrite(STDERR, "Warning: Invalid sort option '{$this->sort}'. Using 'cpu'.\n");
            $this->sort = 'cpu';
        }
        
        if ($this->limit < 1 || $this->limit > 1000) {
            fwrite(STDERR, "Warning: Limit must be between 1 and 1000. Using default.\n");
            $this->limit = self::DEFAULT_LIMIT;
        }
        
        if ($this->interval < 1 || $this->interval > 3600) {
            fwrite(STDERR, "Warning: Interval must be between 1 and 3600. Using default.\n");
            $this->interval = self::DEFAULT_INTERVAL;
        }
        
        if ($this->threadLimit < 1 || $this->threadLimit > 10000) {
            fwrite(STDERR, "Warning: Thread limit must be between 1 and 10000. Using default.\n");
            $this->threadLimit = self::MAX_THREADS_PER_PROC;
        }
    }

    private function validateProcFilesystem(): void {
        if (!file_exists('/proc') || !is_dir('/proc')) {
            throw new RuntimeException('/proc filesystem not available');
        }
        
        if (!file_exists('/proc/self') && !file_exists('/proc/version')) {
            throw new RuntimeException('/proc does not appear to be a valid proc filesystem');
        }
    }

    private function validateProcPath(string $path): bool {
        if (!str_starts_with($path, '/proc/')) {
            return false;
        }
        
        $canonicalPath = realpath($path);
        if (!$canonicalPath) {
            return false;
        }
        
        if (!str_starts_with($canonicalPath, '/proc/')) {
            return false;
        }
        
        $allowedPatterns = [
            '#^/proc/\d+$#',
            '#^/proc/\d+/stat$#',
            '#^/proc/\d+/status$#',
            '#^/proc/\d+/cmdline$#',
            '#^/proc/\d+/task/\d+$#',
            '#^/proc/\d+/task/\d+/stat$#',
        ];
        
        foreach ($allowedPatterns as $pattern) {
            if (preg_match($pattern, $canonicalPath)) {
                return true;
            }
        }
        
        return false;
    }

    private function getUptime(): float {
        $content = @file_get_contents('/proc/uptime');
        if ($content === false) {
            throw new RuntimeException('Cannot read /proc/uptime');
        }
        
        $parts = explode(' ', trim($content));
        if (count($parts) < 2) {
            throw new RuntimeException('Invalid format in /proc/uptime');
        }
        
        $uptime = (float)$parts[0];
        if ($uptime <= 0) {
            throw new RuntimeException('Invalid uptime value');
        }
        
        return $uptime;
    }

    private function detectHertz(): float {
        $output = @shell_exec('getconf CLK_TCK 2>/dev/null');
        if ($output !== null) {
            $hz = (int)trim($output);
            if ($hz > 0) {
                if ($this->verbose) {
                    fwrite(STDERR, "Debug: Detected HERTZ: {$hz}\n");
                }
                return (float)$hz;
            }
        }
        
        if ($this->verbose) {
            fwrite(STDERR, "Debug: Using default HERTZ value: " . self::DEFAULT_HERTZ . "\n");
        }
        return self::DEFAULT_HERTZ;
    }

    public function run(): void {
        if ($this->watch) {
            $this->runWatchMode();
        } else {
            $this->runOnce();
        }
    }

    private function showHelp(): void {
        $scriptName = basename($_SERVER['argv'][0]);
        echo <<<HELP
Process Monitor - Linux Process Statistics
Usage: {$scriptName} [OPTIONS]

Options:
  -h, --help            Show this help message
      --limit=N         Show top N processes (default: 20)
      --sort=TYPE       Sort by: cpu, mem, pid, command, time (default: cpu)
      --watch[=N]       Refresh every N seconds (default: 2)
      --verbose         Show debug information
      --zombie          Include zombie processes
      --threads         Show thread information
      --thread-limit=N  Maximum threads per process (default: 1000)

Examples:
  {$scriptName} --limit=10 --sort=mem
  {$scriptName} --watch=5 --threads
  {$scriptName} --verbose --zombie

HELP;
    }

    private function runWatchMode(): void {
        $this->setupSignalHandlers();
        
        echo "Process Monitor - Refresh every {$this->interval}s (Ctrl+C to stop)\n";
        
        $iteration = 0;
        while (!$this->shutdownRequested) {
            if ($iteration++ > 0) {
                echo "\033[2J\033[;H";
            }
            
            $uptime = $this->getUptime();
            $this->displayHeader($iteration, $uptime);
            $this->runOnceWithUptime($uptime);
            
            for ($i = 0; $i < $this->interval && !$this->shutdownRequested; $i++) {
                sleep(1);
            }
        }
        
        echo "\nShutting down...\n";
    }

    private function setupSignalHandlers(): void {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    private function handleSignal(int $signal): void {
        $this->shutdownRequested = true;
    }

    private function displayHeader(int $iteration, float $uptime): void {
        echo sprintf("Process Monitor - Iteration #%d - %s - Uptime: %.0fs\n",
            $iteration,
            date('Y-m-d H:i:s'),
            $uptime
        );
        
        echo sprintf("Sorting by: %s | Showing top: %d | Refresh: %ds",
            strtoupper($this->sort),
            $this->limit,
            $this->interval
        );
        
        $modes = [];
        if ($this->zombie) $modes[] = 'Zombies';
        if ($this->threads) $modes[] = 'Threads';
        
        if (!empty($modes)) {
            echo " | Modes: " . implode(', ', $modes);
        }
        
        echo "\n" . str_repeat('=', 80) . "\n\n";
    }

    private function runOnce(): void {
        $uptime = $this->getUptime();
        $this->runOnceWithUptime($uptime);
    }

    private function runOnceWithUptime(float $uptime): void {
        $startTime = microtime(true);
        $processes = [];
        $procCount = 0;
        $errorCount = 0;
        
        $dir = @opendir('/proc');
        if (!$dir) {
            echo "Error: Cannot access /proc directory\n";
            return;
        }
        
        while (($entry = readdir($dir)) !== false) {
            if ($entry === '.' || $entry === '..' || !ctype_digit($entry)) {
                continue;
            }
            
            $pid = (int)$entry;
            $procCount++;
            
            if ($procCount > self::MAX_PID_SCAN) {
                fwrite(STDERR, "Warning: Too many processes, stopping scan\n");
                break;
            }
            
            $process = $this->readProcess($pid, $uptime);
            if ($process !== null) {
                $processes[] = $process;
                
                if ($this->threads) {
                    $threads = $this->readThreads($pid, $uptime);
                    $processes = array_merge($processes, $threads);
                }
            } else {
                $errorCount++;
            }
        }
        
        closedir($dir);
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($this->verbose && !$this->watch) {
            fwrite(STDERR, "Debug: Scanned {$procCount} processes, {$errorCount} errors, took {$executionTime}ms\n");
        }
        
        $this->render($processes);
        
        if (!$this->watch) {
            echo "\nTotal processes displayed: " . count($processes) . "\n";
        }
    }

    private function readProcess(int $pid, float $uptime): ?array {
        $statPath = "/proc/{$pid}/stat";
        if (!$this->validateProcPath($statPath)) {
            return null;
        }
        
        $content = @file_get_contents($statPath);
        if ($content === false) {
            return null;
        }
        
        $content = trim($content);
        
        if (!$this->zombie && str_contains($content, ') Z ')) {
            return null;
        }
        
        $lastParen = strrpos($content, ')');
        if ($lastParen === false) {
            return null;
        }
        
        $name = substr($content, 0, $lastParen);
        $name = trim($name, '()');
        $data = substr($content, $lastParen + 2);
        
        $fields = preg_split('/\s+/', $data);
        if (count($fields) < 22) {
            return null;
        }
        
        $state = $fields[0] ?? '?';
        $ppid = (int)($fields[1] ?? 0);
        $utime = (float)($fields[11] ?? 0);
        $stime = (float)($fields[12] ?? 0);
        $cutime = (float)($fields[13] ?? 0);
        $cstime = (float)($fields[14] ?? 0);
        $starttime = (float)($fields[19] ?? 0);
        
        $totalTime = $utime + $stime + $cutime + $cstime;
        $secondsElapsed = $uptime - ($starttime / $this->hertz);
        
        $cpuUsage = 0.0;
        if ($secondsElapsed > self::MIN_UPTIME) {
            $cpuUsage = 100 * (($totalTime / $this->hertz) / $secondsElapsed);
            $cpuUsage = min($cpuUsage, 100.0);
        }
        
        $memory = $this->getMemoryUsage($pid);
        $command = $this->getProcessCommand($pid, $name);
        
        return [
            'pid' => $pid,
            'ppid' => $ppid,
            'cpu' => round($cpuUsage, 1),
            'memory' => round($memory, 1),
            'command' => $command,
            'state' => $state,
            'time' => round($totalTime / $this->hertz, 1),
            'type' => 'process'
        ];
    }

    private function readThreads(int $pid, float $uptime): array {
        $threads = [];
        $taskDir = "/proc/{$pid}/task";
        
        if (!$this->validateProcPath($taskDir) || !is_dir($taskDir)) {
            return $threads;
        }
        
        $dir = @opendir($taskDir);
        if (!$dir) {
            return $threads;
        }
        
        $count = 0;
        while (($entry = readdir($dir)) !== false && $count < $this->threadLimit) {
            if ($entry === '.' || $entry === '..' || !ctype_digit($entry)) {
                continue;
            }
            
            $tid = (int)$entry;
            if ($tid === $pid) {
                continue;
            }
            
            $thread = $this->readThread($pid, $tid, $uptime);
            if ($thread !== null) {
                $threads[] = $thread;
                $count++;
            }
        }
        
        closedir($dir);
        return $threads;
    }

    private function readThread(int $pid, int $tid, float $uptime): ?array {
        $statPath = "/proc/{$pid}/task/{$tid}/stat";
        if (!$this->validateProcPath($statPath)) {
            return null;
        }
        
        $content = @file_get_contents($statPath);
        if ($content === false) {
            return null;
        }
        
        $content = trim($content);
        $lastParen = strrpos($content, ')');
        if ($lastParen === false) {
            return null;
        }
        
        $name = substr($content, 0, $lastParen);
        $name = trim($name, '()');
        $data = substr($content, $lastParen + 2);
        
        $fields = preg_split('/\s+/', $data);
        if (count($fields) < 14) {
            return null;
        }
        
        $state = $fields[0] ?? '?';
        $utime = (float)($fields[11] ?? 0);
        $stime = (float)($fields[12] ?? 0);
        
        $totalTime = $utime + $stime;
        
        $cpuUsage = 0.0;
        if ($uptime > self::MIN_UPTIME) {
            $cpuUsage = 100 * (($totalTime / $this->hertz) / $uptime);
            $cpuUsage = min($cpuUsage, 100.0);
        }
        
        $memory = $this->getMemoryUsage($tid);
        
        return [
            'pid' => $tid,
            'ppid' => $pid,
            'cpu' => round($cpuUsage, 1),
            'memory' => round($memory, 1),
            'command' => '└─ ' . $this->sanitizeOutput($name),
            'state' => $state,
            'time' => round($totalTime / $this->hertz, 1),
            'type' => 'thread'
        ];
    }

    private function getMemoryUsage(int $pid): float {
        $statusPath = "/proc/{$pid}/status";
        if (!$this->validateProcPath($statusPath)) {
            return 0.0;
        }
        
        $content = @file_get_contents($statusPath);
        if ($content === false) {
            return 0.0;
        }
        
        $lines = explode("\n", $content);
        $rss = 0;
        
        foreach ($lines as $line) {
            if (str_starts_with($line, 'VmRSS:')) {
                if (preg_match('/VmRSS:\s+(\d+)\s*kB/', $line, $matches)) {
                    $rss = (int)$matches[1];
                    break;
                }
            }
        }
        
        return $rss > 0 ? $rss / 1024 : 0.0;
    }

    private function getProcessCommand(int $pid, string $defaultName): string {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (!$this->validateProcPath($cmdlinePath)) {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $content = @file_get_contents($cmdlinePath);
        if ($content === false || strlen($content) === 0) {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $cmdline = trim(str_replace("\0", ' ', $content));
        if ($cmdline === '') {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $cmdline = $this->sanitizeOutput($cmdline);
        return $this->truncateString($cmdline, self::MAX_CMD_LENGTH);
    }

    private function sanitizeOutput(string $text): string {
        $text = preg_replace('/[^\x20-\x7E\t\n\r]/u', '?', $text);
        return trim($text);
    }

    private function truncateString(string $text, int $maxLength): string {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }
        
        return mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
    }

    private function render(array $processes): void {
        if (empty($processes)) {
            echo "No processes found or insufficient permissions.\n";
            return;
        }
        
        $sortField = match($this->sort) {
            'mem' => 'memory',
            'pid' => 'pid',
            'command' => 'command',
            'time' => 'time',
            default => 'cpu',
        };
        
        usort($processes, function($a, $b) use ($sortField) {
            $result = $b[$sortField] <=> $a[$sortField];
            return $result !== 0 ? $result : $a['pid'] <=> $b['pid'];
        });
        
        $displayCount = min($this->limit, count($processes));
        $displayProcesses = array_slice($processes, 0, $displayCount);
        
        printf("%-6s %-6s %-10s %-6s %s\n", 'PID', 'CPU%', 'MEM(MB)', 'STATE', 'COMMAND');
        echo str_repeat('-', 80) . "\n";
        
        $totalCpu = 0;
        $totalMem = 0;
        
        foreach ($displayProcesses as $proc) {
            $pidDisplay = $proc['type'] === 'thread' ? "  {$proc['pid']}" : (string)$proc['pid'];
            
            printf("%-6s %-6.1f %-10.1f %-6s %s\n",
                $pidDisplay,
                $proc['cpu'],
                $proc['memory'],
                $proc['state'],
                $proc['command']
            );
            
            $totalCpu += $proc['cpu'];
            $totalMem += $proc['memory'];
        }
        
        if (!$this->watch) {
            echo str_repeat('-', 80) . "\n";
            printf("Top %d processes: %.1f%% CPU, %.1f MB MEM\n",
                $displayCount, $totalCpu, $totalMem);
        }
    }
}

try {
    $procStat = new ProcStat();
    $procStat->run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Use --help for usage information.\n");
    exit(1);
}
