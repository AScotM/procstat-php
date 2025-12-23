#!/usr/bin/env php
<?php

declare(strict_types=1);

class ProcStat {
    private float $uptime;
    private float $hertz;
    private int $limit;
    private string $sort;
    private bool $watch;
    private int $interval;
    private bool $verbose;
    private bool $zombie;
    private bool $threads;

    private const MAX_CMD_LENGTH = 80;
    private const DEFAULT_HERTZ = 100;
    private const MIN_UPTIME = 0.1;
    private const DEFAULT_LIMIT = 20;
    private const DEFAULT_INTERVAL = 2;
    private const MAX_PID_SCAN = 32768;
    private const CPU_EPSILON = 0.0001;

    public function __construct() {
        if (PHP_OS_FAMILY !== 'Linux') {
            fwrite(STDERR, "Error: This tool only works on Linux systems.\n");
            exit(1);
        }
        
        $this->verbose = $this->getArg('--verbose', false) !== false;
        $this->zombie = $this->getArg('--zombie', false) !== false;
        $this->threads = $this->getArg('--threads', false) !== false;
        
        $this->validateProcFilesystem();
        $this->uptime = $this->getUptime();
        $this->hertz = $this->detectHertz();
        $this->limit = max(1, (int)$this->getArg('--limit', self::DEFAULT_LIMIT));
        $this->sort = $this->getArg('--sort', 'cpu');
        
        $watchArg = $this->getArg('--watch', false);
        $this->watch = $watchArg !== false;
        $this->interval = $this->watch ? 
            max(1, (int)($watchArg === true ? self::DEFAULT_INTERVAL : $watchArg)) : 
            self::DEFAULT_INTERVAL;
        
        $this->validateOptions();
    }

    private function getArg(string $name, mixed $default): mixed {
        $options = getopt('', ["$name::"]);
        
        if (!isset($options[$name])) {
            return $default;
        }
        
        if ($default === false) {
            return $options[$name] !== false;
        }
        
        return $options[$name] === false ? true : $options[$name];
    }

    private function validateOptions(): void {
        $validSorts = ['cpu', 'mem', 'pid', 'command', 'time'];
        if (!in_array($this->sort, $validSorts)) {
            fwrite(STDERR, "Warning: Invalid sort option '{$this->sort}'. Using 'cpu'.\n");
            $this->sort = 'cpu';
        }
        
        if ($this->limit > 1000) {
            fwrite(STDERR, "Warning: Limit too high. Capping at 1000.\n");
            $this->limit = 1000;
        }
        
        if ($this->interval > 3600) {
            fwrite(STDERR, "Warning: Interval too high. Capping at 3600 seconds.\n");
            $this->interval = 3600;
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
        $realpath = realpath($path);
        if (!$realpath) {
            return false;
        }
        
        if (strpos($realpath, '/proc/') !== 0) {
            return false;
        }
        
        $allowedPatterns = [
            '#^/proc/\d+$#',
            '#^/proc/\d+/stat$#',
            '#^/proc/\d+/status$#',
            '#^/proc/\d+/cmdline$#',
            '#^/proc/\d+/task/\d+/stat$#',
            '#^/proc/\d+/task/\d+/status$#',
        ];
        
        foreach ($allowedPatterns as $pattern) {
            if (preg_match($pattern, $realpath)) {
                return true;
            }
        }
        
        return false;
    }

    private function getUptime(): float {
        $content = file_get_contents('/proc/uptime');
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
        $output = shell_exec('getconf CLK_TCK 2>/dev/null');
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
        
        return (float)$hz;
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
        echo "  --sort=TYPE  Sort by: cpu, mem, pid, command, time (default: cpu)\n";
        echo "  --watch[=N]  Refresh every N seconds, continuous mode (default: " . self::DEFAULT_INTERVAL . ")\n";
        echo "  --verbose    Show debug information\n";
        echo "  --zombie     Include zombie processes\n";
        echo "  --threads    Show thread information\n";
        echo "  --help       Show this help message\n\n";
        echo "Examples:\n";
        echo "  Show top 10 CPU processes:    " . basename($_SERVER['argv'][0]) . " --limit=10\n";
        echo "  Show top memory processes:    " . basename($_SERVER['argv'][0]) . " --sort=mem\n";
        echo "  Monitor continuously:         " . basename($_SERVER['argv'][0]) . " --watch=2\n";
        echo "  Monitor with threads:         " . basename($_SERVER['argv'][0]) . " --threads --watch\n";
    }

    private function runWatchMode(): void {
        $iteration = 0;
        $startTime = time();
        
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            declare(ticks=1);
        }
        
        echo "Process Monitor - Refresh every {$this->interval}s (Ctrl+C to stop)\n";
        
        while (true) {
            if ($iteration++ > 0) {
                echo "\033[2J\033[;H";
            }
            
            $this->displayHeader($iteration);
            $this->runOnce();
            
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
    
    private function handleSignal(int $signal): void {
        $signalName = match($signal) {
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            default => "signal {$signal}"
        };
        echo "\nReceived {$signalName}, shutting down...\n";
        exit(0);
    }
    
    private function displayHeader(int $iteration): void {
        echo "Process Monitor - Iteration #{$iteration} - " . date('Y-m-d H:i:s') . "\n";
        echo "Sorting by: " . strtoupper($this->sort) . " | Showing top: {$this->limit} | Refresh: {$this->interval}s\n";
        
        $modes = [];
        if ($this->zombie) $modes[] = 'Zombies';
        if ($this->threads) $modes[] = 'Threads';
        if ($modes) {
            echo "Modes: " . implode(', ', $modes) . "\n";
        }
        
        echo str_repeat('=', 80) . "\n\n";
    }

    private function runOnce(): void {
        $startTime = microtime(true);
        $stats = [];
        $processCount = 0;
        $errorCount = 0;
        $zombieCount = 0;
        
        $pidDirs = glob('/proc/[0-9]*', GLOB_ONLYDIR | GLOB_NOSORT);
        
        if (count($pidDirs) > self::MAX_PID_SCAN) {
            throw new RuntimeException("Too many processes to scan, possible attack");
        }
        
        foreach ($pidDirs as $pidDir) {
            $pid = (int)basename($pidDir);
            $processCount++;
            
            $process = $this->readProcess($pid);
            if ($process) {
                $stats[] = $process;
                
                if ($this->threads) {
                    $threadStats = $this->readThreads($pid);
                    $stats = array_merge($stats, $threadStats);
                }
            } else {
                $errorCount++;
            }
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($this->verbose && !$this->watch) {
            fwrite(STDERR, "Debug: Scanned {$processCount} processes, {$errorCount} errors, {$zombieCount} zombies, took {$executionTime}ms\n");
        }
        
        $this->render($stats);
        
        if (!$this->watch) {
            echo "\nTotal processes: " . count($stats) . " (scanned {$processCount})";
            if ($zombieCount > 0 && !$this->zombie) {
                echo ", {$zombieCount} zombies hidden";
            }
            echo "\n";
        }
    }

    private function readProcess(int $pid): ?array {
        try {
            $statPath = "/proc/{$pid}/stat";
            if (!$this->validateProcPath($statPath) || !is_readable($statPath)) {
                return null;
            }
            
            $statContent = file_get_contents($statPath);
            if (!$statContent) {
                return null;
            }
            
            if (!$this->zombie) {
                if (strpos($statContent, ') Z ') !== false) {
                    return null;
                }
            }
            
            $lastParen = strrpos($statContent, ')');
            if ($lastParen === false) {
                return null;
            }
            
            $namePart = substr($statContent, 0, $lastParen + 1);
            $dataPart = substr($statContent, $lastParen + 2);
            
            $processName = trim($namePart, '()');
            $fields = explode(' ', $dataPart);
            
            if (count($fields) < 22) {
                return null;
            }
            
            [$utime, $stime, $cutime, $cstime, $starttime] = [
                (float)($fields[11] ?? 0),
                (float)($fields[12] ?? 0),  
                (float)($fields[13] ?? 0),
                (float)($fields[14] ?? 0),
                (float)($fields[19] ?? 0)
            ];
            
            $totalTime = $utime + $stime + $cutime + $cstime;
            $secondsElapsed = $this->uptime - ($starttime / $this->hertz);
            
            if ($secondsElapsed <= self::MIN_UPTIME) {
                $cpuUsage = 0.0;
            } else {
                $cpuUsage = round(100 * (($totalTime / $this->hertz) / $secondsElapsed), 1);
                $cpuUsage = min($cpuUsage, 100.0);
            }

            $memoryUsage = $this->getMemoryUsage($pid);
            $command = $this->getProcessCommand($pid, $processName);
            $state = $this->getProcessState($statContent);

            return [
                'PID' => $pid,
                'CPU%' => $cpuUsage,
                'MEM(MB)' => $memoryUsage,
                'CMD' => $command,
                'TIME' => $totalTime / $this->hertz,
                'STATE' => $state,
                'TYPE' => 'process'
            ];
        } catch (Exception $e) {
            if ($this->verbose) {
                fwrite(STDERR, "Debug: Error reading PID {$pid}: " . $e->getMessage() . "\n");
            }
            return null;
        }
    }

    private function readThreads(int $pid): array {
        $threads = [];
        $taskDir = "/proc/{$pid}/task";
        
        if (!$this->validateProcPath($taskDir) || !is_dir($taskDir)) {
            return $threads;
        }
        
        $taskDirs = glob("{$taskDir}/[0-9]*", GLOB_ONLYDIR | GLOB_NOSORT);
        
        foreach ($taskDirs as $taskDirPath) {
            $tid = (int)basename($taskDirPath);
            if ($tid === $pid) {
                continue;
            }
            
            $thread = $this->readThread($pid, $tid);
            if ($thread) {
                $threads[] = $thread;
            }
        }
        
        return $threads;
    }

    private function readThread(int $pid, int $tid): ?array {
        $statPath = "/proc/{$pid}/task/{$tid}/stat";
        if (!$this->validateProcPath($statPath) || !is_readable($statPath)) {
            return null;
        }
        
        $statContent = file_get_contents($statPath);
        if (!$statContent) {
            return null;
        }
        
        $lastParen = strrpos($statContent, ')');
        if ($lastParen === false) {
            return null;
        }
        
        $namePart = substr($statContent, 0, $lastParen + 1);
        $dataPart = substr($statContent, $lastParen + 2);
        
        $processName = trim($namePart, '()');
        $fields = explode(' ', $dataPart);
        
        if (count($fields) < 22) {
            return null;
        }
        
        [$utime, $stime] = [
            (float)($fields[11] ?? 0),
            (float)($fields[12] ?? 0)
        ];
        
        $totalTime = $utime + $stime;
        $cpuUsage = round(100 * (($totalTime / $this->hertz) / $this->uptime), 1);
        $cpuUsage = min($cpuUsage, 100.0);
        
        $memoryUsage = $this->getMemoryUsage($tid);
        $state = $this->getProcessState($statContent);

        return [
            'PID' => $tid,
            'CPU%' => $cpuUsage,
            'MEM(MB)' => $memoryUsage,
            'CMD' => '└─ ' . $this->sanitizeOutput($processName),
            'TIME' => $totalTime / $this->hertz,
            'STATE' => $state,
            'TYPE' => 'thread'
        ];
    }

    private function getProcessState(string $statContent): string {
        $matches = [];
        if (preg_match('/\)\s+([A-Z])\s+/', $statContent, $matches)) {
            return $matches[1];
        }
        return '?';
    }

    private function getMemoryUsage(int $pid): float {
        $statusPath = "/proc/{$pid}/status";
        if (!$this->validateProcPath($statusPath) || !is_readable($statusPath)) {
            return 0.0;
        }
        
        $content = file_get_contents($statusPath);
        if (!$content) {
            return 0.0;
        }
        
        $rss = 0;
        $vms = 0;
        
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'VmRSS:')) {
                $rss = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            } elseif (str_starts_with($line, 'VmSize:')) {
                $vms = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            }
        }
        
        if ($rss > 0) {
            return round($rss / 1024, 1);
        }
        
        return round($vms / 1024, 1);
    }

    private function getProcessCommand(int $pid, string $defaultName): string {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (!$this->validateProcPath($cmdlinePath) || !is_readable($cmdlinePath)) {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $cmdline = file_get_contents($cmdlinePath);
        if (!$cmdline) {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $command = trim(str_replace("\0", ' ', $cmdline));
        if ($command === '') {
            return '[' . $this->sanitizeOutput($defaultName) . ']';
        }
        
        $command = $this->sanitizeOutput($command);
        
        return mb_strimwidth($command, 0, self::MAX_CMD_LENGTH, '…', 'UTF-8');
    }
    
    private function sanitizeOutput(string $text): string {
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
            'time' => 'TIME',
            default => 'CPU%',
        };
        
        usort($data, function($a, $b) use ($sortKey) {
            $result = $b[$sortKey] <=> $a[$sortKey];
            return $result !== 0 ? $result : $a['PID'] <=> $b['PID'];
        });
        
        if ($this->threads) {
            usort($data, function($a, $b) {
                if ($a['TYPE'] !== $b['TYPE']) {
                    return ($a['TYPE'] === 'process') ? -1 : 1;
                }
                if ($a['PID'] !== $b['PID']) {
                    return $a['PID'] <=> $b['PID'];
                }
                return $a['CMD'] <=> $b['CMD'];
            });
        }
        
        printf("%-6s %-6s %-10s %-6s %s\n", 'PID', 'CPU%', 'MEM(MB)', 'STATE', 'COMMAND');
        echo str_repeat('-', 80) . "\n";
        
        $displayData = array_slice($data, 0, $this->limit);
        $previousPid = null;
        
        foreach ($displayData as $process) {
            $pidDisplay = $process['PID'];
            if ($this->threads && $process['TYPE'] === 'thread') {
                $pidDisplay = "  {$pidDisplay}";
            }
            
            printf("%-6s %-6.1f %-10.1f %-6s %s\n", 
                $pidDisplay, 
                $process['CPU%'], 
                $process['MEM(MB)'], 
                $process['STATE'],
                $process['CMD']
            );
        }
        
        $totalMemory = array_sum(array_column($displayData, 'MEM(MB)'));
        $totalCpu = array_sum(array_column($displayData, 'CPU%'));
        
        if (!$this->watch) {
            echo str_repeat('-', 80) . "\n";
            printf("Top %d processes: %.1f%% CPU, %.1f MB MEM\n", 
                count($displayData), $totalCpu, $totalMemory);
        }
    }
}

try {
    $procStat = new ProcStat();
    $procStat->run();
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Try running with --help for usage information.\n");
    exit(1);
}
