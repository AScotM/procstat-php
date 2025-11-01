#!/usr/bin/env php
<?php

class ProcStat {
    private float $uptime;
    private float $hertz;
    private array $stats = [];
    private array $fields = [];

    function __construct() {
        if (PHP_OS !== 'Linux') {
            throw new RuntimeException('This script only works on Linux');
        }
        $this->uptime = (float)explode(' ', trim(file_get_contents('/proc/uptime')))[0];
        $this->hertz = $this->detectHertz();
        $this->fields = ['PID','CPU%','MEM(MB)','CMD'];
    }

    private function detectHertz(): float {
        $hertz = (int)shell_exec('getconf CLK_TCK');
        return $hertz > 0 ? $hertz : 100;
    }

    function run(): void {
        foreach (scandir('/proc') as $pid) {
            if (ctype_digit($pid)) {
                $p = $this->readProcess((int)$pid);
                if ($p) $this->stats[] = $p;
            }
        }
        $this->render();
    }

    private function readProcess(int $pid): ?array {
        if (!is_readable("/proc/$pid/stat")) {
            return null;
        }
        
        $s = file_get_contents("/proc/$pid/stat");
        if (!$s) return null;
        
        $a = explode(' ', $s);
        if (count($a) < 22) return null;

        $utime = (float)$a[13];
        $stime = (float)$a[14];
        $cutime = (float)$a[15];
        $cstime = (float)$a[16];
        $starttime = (float)$a[21];
        $total = $utime + $stime + $cutime + $cstime;
        $seconds = $this->uptime - ($starttime / $this->hertz);
        $cpu = $seconds > 0 ? round(100 * (($total / $this->hertz) / $seconds), 1) : 0;

        $rss = 0;
        $statusFile = "/proc/$pid/status";
        if (is_readable($statusFile)) {
            foreach (file($statusFile) as $line) {
                if (str_starts_with($line, 'VmRSS:')) {
                    $rss = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                    break;
                }
            }
        }

        $cmdlineFile = "/proc/$pid/cmdline";
        $cmd = '';
        if (is_readable($cmdlineFile)) {
            $cmdContent = file_get_contents($cmdlineFile);
            $cmd = trim(str_replace("\0", ' ', $cmdContent));
        }
        
        if ($cmd === '') {
            $cmd = '[' . trim($a[1], '()') . ']';
        }

        return [
            'PID' => $pid,
            'CPU%' => $cpu,
            'MEM(MB)' => round($rss/1024, 1),
            'CMD' => substr($cmd, 0, 80)
        ];
    }

    private function render(): void {
        $d = array_filter($this->stats);
        usort($d, fn($x,$y) => $y['CPU%'] <=> $x['CPU%']);
        printf("%5s %6s %9s %s\n", ...$this->fields);
        foreach (array_slice($d, 0, 20) as $p) {
            printf("%5d %6.1f %9.1f %s\n", $p['PID'], $p['CPU%'], $p['MEM(MB)'], $p['CMD']);
        }
    }
}

(new ProcStat)->run();
