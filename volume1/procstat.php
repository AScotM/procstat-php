#!/usr/bin/env php
<?php

class ProcStat {
    private float $uptime;
    private float $hertz;
    private int $limit;
    private string $sort;

    function __construct() {
        if (PHP_OS_FAMILY !== 'Linux') {
            fwrite(STDERR, "Only works on Linux.\n");
            exit(1);
        }
        $this->uptime = $this->getUptime();
        $this->hertz = $this->detectHertz();
        $this->limit = $this->getArg('--limit', 20);
        $this->sort  = $this->getArg('--sort', 'cpu');
    }

    private function getArg(string $name, $default) {
        $args = getopt('', [$name . ':']);
        return $args[$name] ?? $default;
    }

    private function getUptime(): float {
        $c = @file_get_contents('/proc/uptime');
        return $c ? (float)explode(' ', trim($c))[0] : 0.1;
    }

    private function detectHertz(): float {
        $hz = (int)@shell_exec('getconf CLK_TCK 2>/dev/null');
        return $hz > 0 ? $hz : 100;
    }

    function run(): void {
        $stats = [];
        foreach (new DirectoryIterator('/proc') as $entry) {
            if ($entry->isDir() && ctype_digit($entry->getFilename())) {
                $p = $this->readProcess((int)$entry->getFilename());
                if ($p) $stats[] = $p;
            }
        }
        $this->render($stats);
    }

    private function readProcess(int $pid): ?array {
        $s = @file_get_contents("/proc/$pid/stat");
        if (!$s) return null;
        $a = explode(' ', trim($s));
        if (count($a) < 22) return null;

        [$utime, $stime, $cutime, $cstime, $starttime] = [
            (float)$a[13], (float)$a[14], (float)$a[15], (float)$a[16], (float)$a[21]
        ];
        $total = $utime + $stime + $cutime + $cstime;
        $seconds = $this->uptime - ($starttime / $this->hertz);
        $cpu = $seconds > 0 ? round(100 * (($total / $this->hertz) / $seconds), 1) : 0;

        $rss = 0;
        foreach (@file("/proc/$pid/status") ?: [] as $line) {
            if (str_starts_with($line, 'VmRSS:')) {
                $rss = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                break;
            }
        }

        $cmd = '';
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        if ($cmdline) $cmd = trim(str_replace("\0", ' ', $cmdline));
        if ($cmd === '') $cmd = '[' . trim($a[1], '()') . ']';
        $cmd = mb_strimwidth($cmd, 0, 80, '…', 'UTF-8');

        return [
            'PID' => $pid,
            'CPU%' => $cpu,
            'MEM(MB)' => round($rss / 1024, 1),
            'CMD' => $cmd
        ];
    }

    private function render(array $data): void {
        $key = match ($this->sort) {
            'mem' => 'MEM(MB)',
            'pid' => 'PID',
            default => 'CPU%',
        };
        usort($data, fn($x, $y) => $y[$key] <=> $x[$key]);
        printf("%5s %6s %9s %s\n", 'PID', 'CPU%', 'MEM(MB)', 'CMD');
        foreach (array_slice($data, 0, (int)$this->limit) as $p) {
            printf("%5d %6.1f %9.1f %s\n", $p['PID'], $p['CPU%'], $p['MEM(MB)'], $p['CMD']);
        }
    }
}

(new ProcStat)->run();
