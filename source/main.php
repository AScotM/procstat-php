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
    private int $maxPidScan;
    private bool $useMb;
    private float $lastScanTime;
    private bool $json;

    private const DEFAULT_HERTZ = 100.0;
    private const MIN_UPTIME = 0.1;
    private const DEFAULT_LIMIT = 20;
    private const DEFAULT_INTERVAL = 2;
    private const DEFAULT_MAX_PID_SCAN = 131072;
    private const DEFAULT_THREAD_LIMIT = 1000;
    private const DEFAULT_CMD_LENGTH = 80;
    private const VALID_SORTS = ['cpu', 'mem', 'pid', 'command', 'time'];
    private const FLOAT_EPSILON = 0.00001;
    private const MAX_STATS_AGE = 5;
    private const MAX_THREADS_PER_PROCESS = 100;
    private const CACHE_TTL = 1;
    private const BATCH_SIZE = 100;
    private const BATCH_DELAY_US = 1000;
    private const MAX_STATS_SIZE = 1000000;

    private bool $shutdownRequested = false;
    private array $previousStats = [];
    private ?float $previousUptime = null;
    private array $statCache = [];
    private array $lastCacheTime = [];
    private array $lastReadTime = [];

    public function __construct() {
        if (PHP_OS_FAMILY !== 'Linux') {
            fwrite(STDERR, "Error: This tool only works on Linux systems.\n");
            exit(1);
        }
        
        $this->parseArguments();
        $this->validateProcFilesystem();
        $this->hertz = $this->detectHertz();
        $this->validateOptions();
        $this->setupResourceLimits();
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
            "max-scan:",
            "kb",
            "mb",
            "json",
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
        $this->threadLimit = (int)($options['thread-limit'] ?? self::DEFAULT_THREAD_LIMIT);
        $this->maxPidScan = (int)($options['max-scan'] ?? self::DEFAULT_MAX_PID_SCAN);
        $this->useMb = !isset($options['kb']);
        $this->json = isset($options['json']);
        
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
        if (!in_array($this->sort, self::VALID_SORTS, true)) {
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
            $this->threadLimit = self::DEFAULT_THREAD_LIMIT;
        }
        
        if ($this->maxPidScan < 100 || $this->maxPidScan > 1000000) {
            fwrite(STDERR, "Warning: Max scan must be between 100 and 1000000. Using default.\n");
            $this->maxPidScan = self::DEFAULT_MAX_PID_SCAN;
        }
    }

    private function setupResourceLimits(): void {
        if (function_exists('setrlimit')) {
            $maxFiles = min(4096, $this->maxPidScan * 3);
            if (defined('RLIMIT_NOFILE')) {
                @setrlimit(RLIMIT_NOFILE, $maxFiles, $maxFiles);
            }
            
            $maxTime = 30;
            if (defined('RLIMIT_CPU')) {
                @setrlimit(RLIMIT_CPU, $maxTime, $maxTime);
            }
        }
    }

    private function validateProcFilesystem(): void {
        if (!file_exists('/proc') || !is_dir('/proc')) {
            throw new RuntimeException('/proc filesystem not available or not mounted');
        }
        
        if (!file_exists('/proc/self') && !file_exists('/proc/version')) {
            throw new RuntimeException('/proc does not appear to be a valid proc filesystem');
        }
        
        if (!is_readable('/proc/uptime')) {
            throw new RuntimeException('/proc/uptime is not readable. Check permissions or run with sudo');
        }
    }

    private function validateProcPath(string $path): bool {
        $resolved = realpath($path);
        if ($resolved === false) {
            return false;
        }
        
        if (!str_starts_with($resolved, '/proc/')) {
            return false;
        }
        
        $components = explode('/', trim($resolved, '/'));
        
        if (count($components) < 2) {
            return false;
        }
        
        if ($components[0] !== 'proc') {
            return false;
        }
        
        if (!ctype_digit($components[1])) {
            return false;
        }
        
        $pid = (int)$components[1];
        if ($pid <= 0 || $pid > PHP_INT_MAX) {
            return false;
        }
        
        if (count($components) === 2) {
            return is_dir($resolved);
        }
        
        $allowedFiles = ['stat', 'status', 'cmdline', 'task'];
        if (count($components) === 3) {
            return in_array($components[2], $allowedFiles, true);
        }
        
        if ($components[2] === 'task' && count($components) === 4) {
            return ctype_digit($components[3]) && is_dir($resolved);
        }
        
        if ($components[2] === 'task' && count($components) === 5) {
            return ctype_digit($components[3]) && $components[4] === 'stat';
        }
        
        return false;
    }

    private function safeFileRead(string $path): ?string {
        if (!$this->validateProcPath($path)) {
            return null;
        }
        
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        
        flock($handle, LOCK_SH);
        
        try {
            $content = @stream_get_contents($handle);
            return $content !== false ? $content : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function getUptime(): float {
        $content = @file_get_contents('/proc/uptime');
        if ($content === false) {
            throw new RuntimeException('Cannot read /proc/uptime. Check permissions or run with appropriate privileges');
        }
        
        $parts = explode(' ', trim($content));
        if (count($parts) < 2) {
            throw new RuntimeException('Invalid format in /proc/uptime');
        }
        
        $uptime = (float)$parts[0];
        if ($uptime <= self::FLOAT_EPSILON) {
            throw new RuntimeException('Invalid uptime value in /proc/uptime');
        }
        
        return $uptime;
    }

    private function detectHertz(): float {
        $content = @file_get_contents('/proc/self/stat');
        if ($content !== false) {
            $fields = explode(' ', $content);
            if (isset($fields[41])) {
                $clockTicks = (int)$fields[41];
                if ($clockTicks > 0) {
                    if ($this->verbose) {
                        fwrite(STDERR, "Debug: Detected HERTZ from /proc/self/stat: {$clockTicks}\n");
                    }
                    return (float)$clockTicks;
                }
            }
        }
        
        $output = [];
        $result = -1;
        @exec(escapeshellcmd('getconf CLK_TCK'), $output, $result);
        
        if ($result === 0 && isset($output[0])) {
            $hz = (int)$output[0];
            if ($hz > 0) {
                if ($this->verbose) {
                    fwrite(STDERR, "Debug: Detected HERTZ from getconf: {$hz}\n");
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
        if ($this->json) {
            $this->runOnce();
        } elseif ($this->watch) {
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
      --max-scan=N      Maximum PIDs to scan (default: 131072)
      --kb              Show memory in kilobytes
      --mb              Show memory in megabytes (default)
      --json            Output in JSON format

Examples:
  {$scriptName} --limit=10 --sort=mem
  {$scriptName} --watch=5 --threads
  {$scriptName} --verbose --zombie --kb
  {$scriptName} --limit=20 --sort=cpu --json
  sudo {$scriptName} --limit=20 --sort=cpu

Note: Some systems may require sudo/root privileges to read all process information.

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
            
            try {
                $uptime = $this->getUptime();
                $this->displayHeader($iteration, $uptime);
                $this->runOnceWithUptime($uptime);
            } catch (RuntimeException $e) {
                echo "Error: " . $e->getMessage() . "\n";
                $this->shutdownRequested = true;
                break;
            }
            
            $this->sleepWithInterrupt($this->interval);
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
            if (defined('SIGHUP')) {
                pcntl_signal(SIGHUP, [$this, 'handleSignal']);
            }
        }
    }

    private function handleSignal(int $signal): void {
        static $handled = false;
        if (!$handled) {
            $handled = true;
            $this->shutdownRequested = true;
        }
    }

    private function sleepWithInterrupt(int $seconds): void {
        $remaining = $seconds;
        while ($remaining > 0 && !$this->shutdownRequested) {
            $sleepTime = min($remaining, 1);
            sleep($sleepTime);
            $remaining -= $sleepTime;
        }
    }

    private function displayHeader(int $iteration, float $uptime): void {
        $memoryUnit = $this->useMb ? 'MB' : 'KB';
        
        echo sprintf("Process Monitor - Iteration #%d - %s - Uptime: %.0fs\n",
            $iteration,
            date('Y-m-d H:i:s'),
            $uptime
        );
        
        echo sprintf("Sorting by: %s | Showing top: %d | Refresh: %ds | Memory: %s",
            strtoupper($this->sort),
            $this->limit,
            $this->interval,
            $memoryUnit
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
        try {
            $uptime = $this->getUptime();
            $this->previousUptime = $uptime;
            $this->lastScanTime = microtime(true);
            $this->runOnceWithUptime($uptime);
        } catch (RuntimeException $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    private function shouldCache(string $key): bool {
        $now = microtime(true);
        if (isset($this->lastCacheTime[$key])) {
            if ($now - $this->lastCacheTime[$key] < self::CACHE_TTL) {
                return false;
            }
        }
        $this->lastCacheTime[$key] = $now;
        return true;
    }

    private function rateLimitRead(string $path, int $minInterval = 100000): bool {
        $now = microtime(true);
        if (isset($this->lastReadTime[$path])) {
            if ($now - $this->lastReadTime[$path] < $minInterval) {
                return false;
            }
        }
        $this->lastReadTime[$path] = $now;
        return true;
    }

    private function cleanupOldStats(): void {
        $now = microtime(true);
        $maxStats = min($this->maxPidScan * 2, self::MAX_STATS_SIZE);
        
        if (count($this->previousStats) > $maxStats) {
            uasort($this->previousStats, function($a, $b) {
                return $b['timestamp'] <=> $a['timestamp'];
            });
            $this->previousStats = array_slice($this->previousStats, 0, $maxStats, true);
        }
        
        foreach ($this->previousStats as $key => $stat) {
            if ($now - $stat['timestamp'] > self::MAX_STATS_AGE) {
                unset($this->previousStats[$key]);
            }
        }
    }

    private function readProcessesBatch(array $pids, float $interval): array {
        $processes = [];
        $batches = array_chunk($pids, self::BATCH_SIZE);
        $batchCount = count($batches);
        
        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $pid) {
                $process = $this->readProcess($pid, $interval);
                if ($process !== null) {
                    $processes[] = $process;
                }
            }
            
            if ($batchCount > 1 && $batchIndex < $batchCount - 1) {
                usleep(self::BATCH_DELAY_US);
            }
        }
        
        return $processes;
    }

    private function calculateCpuUsage(float $totalTime, float $previousTotalTime, float $interval): float {
        if ($interval <= self::FLOAT_EPSILON) {
            return 0.0;
        }
        
        $timeDiff = $totalTime - $previousTotalTime;
        if ($timeDiff < 0) {
            $timeDiff = 0.0;
        }
        
        $cpuUsage = 100.0 * ($timeDiff / $this->hertz) / max($interval, self::FLOAT_EPSILON);
        
        return min(max($cpuUsage, 0.0), 100.0);
    }

    private function readProcess(int $pid, float $interval): ?array {
        $cacheKey = "proc_{$pid}";
        if (isset($this->statCache[$cacheKey]) && 
            microtime(true) - $this->statCache[$cacheKey]['time'] < self::CACHE_TTL) {
            return $this->statCache[$cacheKey]['data'];
        }
        
        $statPath = "/proc/{$pid}/stat";
        $content = @file_get_contents($statPath);
        if ($content === false) {
            return null;
        }
        
        $content = trim($content);
        
        if (!$this->zombie && strpos($content, ') Z ') !== false) {
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
        
        $totalTime = $utime + $stime + $cutime + $cstime;
        
        $previousKey = "{$pid}_process";
        $previousTotalTime = $this->previousStats[$previousKey]['total_time'] ?? 0.0;
        
        $cpuUsage = $this->calculateCpuUsage($totalTime, $previousTotalTime, $interval);
        
        $this->previousStats[$previousKey] = [
            'total_time' => $totalTime,
            'timestamp' => microtime(true)
        ];
        
        $memory = $this->getMemoryUsage($pid);
        $command = $this->getProcessCommand($pid, $name);
        
        $result = [
            'pid' => $pid,
            'ppid' => $ppid,
            'cpu' => round($cpuUsage, 1),
            'memory' => round($memory, 1),
            'command' => $command,
            'state' => $state,
            'time' => round($totalTime / $this->hertz, 1),
            'type' => 'process'
        ];
        
        $this->statCache[$cacheKey] = [
            'data' => $result,
            'time' => microtime(true)
        ];
        
        return $result;
    }

    private function readThreads(int $pid, float $interval): array {
        $threads = [];
        $taskDir = "/proc/{$pid}/task";
        
        if (!is_dir($taskDir)) {
            return $threads;
        }
        
        $entries = @scandir($taskDir, SCANDIR_SORT_NONE);
        if ($entries === false) {
            return $threads;
        }
        
        $count = 0;
        $threadLimit = min($this->threadLimit, self::MAX_THREADS_PER_PROCESS);
        $processMemory = $this->getMemoryUsage($pid);
        
        foreach ($entries as $entry) {
            if ($count >= $threadLimit) {
                if ($this->verbose) {
                    fwrite(STDERR, "Debug: Reached thread limit {$threadLimit} for PID {$pid}\n");
                }
                break;
            }
            
            if ($entry === '.' || $entry === '..' || !ctype_digit($entry)) {
                continue;
            }
            
            $tid = (int)$entry;
            if ($tid === $pid) {
                continue;
            }
            
            $cacheKey = "{$pid}_{$tid}_thread";
            
            if (isset($this->statCache[$cacheKey]) && 
                microtime(true) - $this->statCache[$cacheKey]['time'] < self::CACHE_TTL) {
                $threads[] = $this->statCache[$cacheKey]['data'];
                $count++;
                continue;
            }
            
            $thread = $this->readThread($pid, $tid, $interval, $processMemory);
            if ($thread !== null) {
                $this->statCache[$cacheKey] = [
                    'data' => $thread,
                    'time' => microtime(true)
                ];
                $threads[] = $thread;
                $count++;
            }
        }
        
        return $threads;
    }

    private function readThread(int $pid, int $tid, float $interval, float $processMemory): ?array {
        $statPath = "/proc/{$pid}/task/{$tid}/stat";
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
        
        $previousKey = "{$pid}_{$tid}_thread";
        $previousTotalTime = $this->previousStats[$previousKey]['total_time'] ?? 0.0;
        
        $cpuUsage = $this->calculateCpuUsage($totalTime, $previousTotalTime, $interval);
        
        $this->previousStats[$previousKey] = [
            'total_time' => $totalTime,
            'timestamp' => microtime(true)
        ];
        
        return [
            'pid' => $tid,
            'ppid' => $pid,
            'cpu' => round($cpuUsage, 1),
            'memory' => round($processMemory, 1),
            'command' => '  └─ ' . $this->sanitizeOutput($name),
            'state' => $state,
            'time' => round($totalTime / $this->hertz, 1),
            'type' => 'thread'
        ];
    }

    private function getMemoryUsage(int $pid): float {
        $cacheKey = "mem_{$pid}";
        if (isset($this->statCache[$cacheKey]) && 
            microtime(true) - $this->statCache[$cacheKey]['time'] < self::CACHE_TTL) {
            return $this->statCache[$cacheKey]['data'];
        }
        
        $statusPath = "/proc/{$pid}/status";
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
                }
                break;
            }
        }
        
        if ($rss <= 0) {
            return 0.0;
        }
        
        $memory = $this->useMb ? $rss / 1024.0 : (float)$rss;
        
        $this->statCache[$cacheKey] = [
            'data' => $memory,
            'time' => microtime(true)
        ];
        
        return $memory;
    }

    private function getProcessCommand(int $pid, string $defaultName): string {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        $content = @file_get_contents($cmdlinePath);
        if ($content === false || strlen($content) === 0) {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $cmdline = trim(str_replace("\0", ' ', $content));
        if ($cmdline === '') {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $cmdline = $this->sanitizeOutput($cmdline);
        return $this->truncateString($cmdline, self::DEFAULT_CMD_LENGTH);
    }

    private function sanitizeOutput(string $text): string {
        $text = preg_replace('/[^\x20-\x7E\t\n\r]/u', '?', $text);
        return trim($text);
    }

    private function truncateString(string $text, int $maxLength): string {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }
        
        $truncated = mb_substr($text, 0, $maxLength - 3, 'UTF-8');
        return $truncated . '...';
    }

    private function partialSort(array &$array, string $field, int $limit): void {
        if ($limit >= count($array)) {
            usort($array, function($a, $b) use ($field) {
                $result = $b[$field] <=> $a[$field];
                return $result !== 0 ? $result : $a['pid'] <=> $b['pid'];
            });
            return;
        }
        
        $heap = new SplMaxHeap();
        $heapCount = 0;
        
        foreach ($array as $index => $item) {
            $value = $item[$field];
            $heap->insert([$value, $index]);
            $heapCount++;
            
            if ($heapCount > $limit) {
                $heap->extract();
                $heapCount--;
            }
        }
        
        $topIndices = [];
        while (!$heap->isEmpty()) {
            $top = $heap->extract();
            $topIndices[] = $top[1];
        }
        
        $topItems = [];
        foreach ($topIndices as $index) {
            $topItems[] = $array[$index];
        }
        
        usort($topItems, function($a, $b) use ($field) {
            $result = $b[$field] <=> $a[$field];
            return $result !== 0 ? $result : $a['pid'] <=> $b['pid'];
        });
        
        $array = $topItems;
    }

    private function buildProcessTree(array $processes): array {
        $tree = [];
        $byPid = [];
        
        foreach ($processes as $proc) {
            $byPid[$proc['pid']] = $proc;
        }
        
        foreach ($processes as $proc) {
            if (isset($byPid[$proc['ppid']])) {
                $tree[$proc['ppid']]['children'][] = $proc;
            } else {
                $tree[0]['children'][] = $proc;
            }
        }
        
        return $tree;
    }

    private function renderJson(array $processes): void {
        $output = [
            'timestamp' => time(),
            'uptime' => $this->previousUptime,
            'total_processes' => count($processes),
            'processes' => $processes
        ];
        
        echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    }

    private function renderTable(array $processes): void {
        if (empty($processes)) {
            echo "No processes found or insufficient permissions.\n";
            if (function_exists('posix_geteuid') && posix_geteuid() != 0) {
                echo "Try running with sudo for more complete results.\n";
            }
            return;
        }
        
        $sortField = match($this->sort) {
            'mem' => 'memory',
            'pid' => 'pid',
            'command' => 'command',
            'time' => 'time',
            default => 'cpu',
        };
        
        $displayCount = min($this->limit, count($processes));
        
        if ($displayCount < count($processes)) {
            $this->partialSort($processes, $sortField, $displayCount);
            $displayProcesses = array_slice($processes, 0, $displayCount);
        } else {
            usort($processes, function($a, $b) use ($sortField) {
                $result = $b[$sortField] <=> $a[$sortField];
                return $result !== 0 ? $result : $a['pid'] <=> $b['pid'];
            });
            $displayProcesses = $processes;
        }
        
        $memoryUnit = $this->useMb ? 'MB' : 'KB';
        $memoryLabel = "MEM({$memoryUnit})";
        
        printf("%-6s %-6s %-12s %-6s %s\n", 'PID', 'CPU%', $memoryLabel, 'STATE', 'COMMAND');
        echo str_repeat('-', 80) . "\n";
        
        $totalCpu = 0.0;
        $totalMem = 0.0;
        
        foreach ($displayProcesses as $proc) {
            $pidDisplay = $proc['type'] === 'thread' ? "  {$proc['pid']}" : (string)$proc['pid'];
            
            printf("%-6s %-6.1f %-12.1f %-6s %s\n",
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
            printf("Top %d processes: %.1f%% CPU, %.1f %s\n",
                $displayCount, $totalCpu, $totalMem, $memoryUnit);
        }
    }

    private function render(array $processes): void {
        if ($this->json) {
            $this->renderJson($processes);
        } else {
            $this->renderTable($processes);
        }
    }

    private function runOnceWithUptime(float $uptime): void {
        $startTime = microtime(true);
        
        $pids = $this->scanProcDirectory();
        if ($pids === null) {
            echo "Error: Cannot access /proc directory. Check permissions.\n";
            return;
        }
        
        $currentScanTime = microtime(true);
        $interval = $this->previousUptime !== null ? $currentScanTime - $this->lastScanTime : 1.0;
        $this->lastScanTime = $currentScanTime;
        
        $this->cleanupOldStats();
        
        $processes = $this->readProcessesBatch($pids, $interval);
        
        if ($this->threads) {
            $threadStartTime = microtime(true);
            $allThreads = [];
            
            foreach ($processes as $proc) {
                $threads = $this->readThreads($proc['pid'], $interval);
                if (!empty($threads)) {
                    array_push($allThreads, ...$threads);
                }
            }
            
            $processes = array_merge($processes, $allThreads);
            
            if ($this->verbose) {
                fwrite(STDERR, "Debug: Read " . count($allThreads) . " threads in " . 
                    round((microtime(true) - $threadStartTime) * 1000, 2) . "ms\n");
            }
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($this->verbose && !$this->watch) {
            fwrite(STDERR, "Debug: Read " . count($processes) . " entries, took {$executionTime}ms\n");
        }
        
        $this->render($processes);
        
        if (!$this->watch && !$this->json) {
            echo "\nTotal entries displayed: " . count($processes) . "\n";
        }
        
        $this->previousUptime = $uptime;
    }

    private function scanProcDirectory(): ?array {
        $entries = @scandir('/proc', SCANDIR_SORT_NONE);
        if ($entries === false) {
            return null;
        }
        
        $pids = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && ctype_digit($entry)) {
                $pids[] = (int)$entry;
            }
        }
        
        return $pids;
    }
}

try {
    $procStat = new ProcStat();
    $procStat->run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    if ($e->getCode() !== 0) {
        fwrite(STDERR, "Error code: " . $e->getCode() . "\n");
    }
    fwrite(STDERR, "Use --help for usage information.\n");
    fwrite(STDERR, "Note: Some systems may require sudo/root privileges.\n");
    exit(1);
}
