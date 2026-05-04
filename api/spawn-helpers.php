<?php
/**
 * Process-spawning helpers used by the various PHP endpoints that fork
 * background workers (transcript-worker, finalize-worker, audio-download-worker,
 * sync scripts, auto-fix runner).
 *
 * The wrapped pattern:
 *   nohup bash -c '
 *     ulimit -t <cpu_secs>     # CPU-time cap — process gets SIGKILL if it
 *                              #   chews this many CPU seconds. This is the
 *                              #   cap that prevents runaway loops from
 *                              #   pegging CPU and tripping Hostinger's
 *                              #   abuse heuristic.
 *     ulimit -v <virt_kb>      # Virtual memory cap — generous, since PHP
 *                              #   virt is much larger than RSS.
 *     exec nice -n <n> php worker.php arg
 *   ' > log 2>&1 < /dev/null & echo $!
 *
 * Why ulimit and not systemd-run/cgroups: spawn callers run inside the PHP
 * container which doesn't have systemd or cgroup-write access. The host-side
 * cron scripts (book-pipeline-worker.sh, claude-fix-runner.sh, etc.) keep
 * using systemd-run --scope.
 *
 * The container itself ALSO has hard mem_limit + cpus in docker-compose.prod.yml
 * so even if a worker escapes its ulimits, the box can't be melted.
 */

if (!function_exists('spawnCappedWorker')) {

/**
 * Fork a long-running PHP worker with cpu/memory caps + scheduling priority.
 *
 * @param string $workerPath Absolute path to the worker .php file.
 * @param array  $args       Positional arguments passed to the worker.
 * @param string $logFile    Path to append stdout+stderr.
 * @param array  $opts       Optional overrides:
 *   - cpu_secs:  CPU-time cap (default 1800 = 30min). yt-dlp/ffmpeg jobs
 *                rarely exceed 5min CPU; 30min absorbs slow encodes without
 *                ever letting a true runaway escape.
 *   - mem_mb:    Virtual memory cap (default 1500). RSS is typically 1/3
 *                of virt for PHP; 1.5GB virt = ~500MB RSS.
 *   - nice:      Scheduling niceness 0-19 (default 10). Higher = lower
 *                priority. 10 yields cleanly to user-facing PHP-FPM.
 *
 * @return int   PID of the spawned worker (0 on failure).
 */
function spawnCappedWorker(string $workerPath, array $args, string $logFile, array $opts = []): int {
    $cpuSecs = (int)($opts['cpu_secs'] ?? 1800);
    $memMB   = (int)($opts['mem_mb']   ?? 1500);
    $niceLvl = (int)($opts['nice']     ?? 10);
    $memKB   = $memMB * 1024;

    $argsEsc = '';
    foreach ($args as $a) $argsEsc .= ' ' . escapeshellarg((string)$a);

    // Inner shell: set ulimits, drop priority, exec PHP.
    // exec'ing PHP means the shell process gets replaced — so $! returned to
    // the parent is the PHP worker's PID, which is what callers want for
    // posix_kill / status reporting.
    $inner = "ulimit -t $cpuSecs 2>/dev/null; "
           . "ulimit -v $memKB 2>/dev/null; "
           . "exec nice -n $niceLvl php " . escapeshellarg($workerPath) . $argsEsc;

    $cmd = 'nohup bash -c ' . escapeshellarg($inner)
         . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';

    $out = [];
    @exec($cmd, $out);
    return (int)trim($out[0] ?? '0');
}

}
