<?php
/**
 * Process-spawning helpers used by the various PHP endpoints that fork
 * background workers (transcript-worker, finalize-worker, audio-download-worker,
 * sync scripts, auto-fix runner).
 *
 * The wrapped pattern:
 *   nohup bash -c '
 *     ulimit -t <cpu_secs>     # CPU-time cap (soft + hard). Kernel sends
 *                              #   SIGXCPU at the soft limit, SIGKILL at
 *                              #   the hard limit. CPU time, not wall —
 *                              #   a worker sleeping on I/O does not
 *                              #   accumulate it. This is the cap that
 *                              #   stops runaway loops from pegging the
 *                              #   box and tripping abuse heuristics.
 *     exec nice -n <n> php worker.php arg
 *   ' > log 2>&1 < /dev/null & echo $!
 *
 * What we DO NOT do: ulimit -v (virtual memory cap). On Linux a process's
 * virt is a terrible proxy for real RAM pressure — glibc malloc arenas,
 * pthread stacks, COW after exec(), and mmap'd shared libs all inflate it
 * without using physical memory. We learned this when finalize-worker
 * silently SIGKILL'd at the END of a successful encode because PHP virt
 * had drifted past 1.5G during PDO work that used <80MB RSS. The
 * cpu-watchdog.sh host cron handles wall-clock-stuck workers as a safety
 * net for the rare case ulimit -t isn't enough.
 *
 * Why ulimit and not systemd-run/cgroups: spawn callers run inside the PHP
 * container which doesn't have systemd or cgroup-write access. Host-side
 * cron scripts (book-pipeline-worker.sh, claude-fix-runner.sh, etc.) keep
 * using systemd-run --scope.
 */

if (!function_exists('spawnCappedWorker')) {

/**
 * Fork a long-running PHP worker with a CPU-time cap + scheduling priority.
 *
 * @param string $workerPath Absolute path to the worker .php file.
 * @param array  $args       Positional arguments passed to the worker.
 * @param string $logFile    Path to append stdout+stderr.
 * @param array  $opts       Optional overrides:
 *   - cpu_secs:  CPU-time cap (default 1800 = 30min). yt-dlp/ffmpeg jobs
 *                rarely exceed 5min CPU; 30min absorbs slow encodes without
 *                ever letting a true runaway escape.
 *   - nice:      Scheduling niceness 0-19 (default 10). Higher = lower
 *                priority. 10 yields cleanly to user-facing PHP-FPM.
 *   - mem_mb:    Accepted for backwards-compat with callers that already
 *                pass it; ignored. See file-level docblock for why we
 *                don't cap virtual memory.
 *
 * @return int   PID of the spawned worker (0 on failure).
 */
function spawnCappedWorker(string $workerPath, array $args, string $logFile, array $opts = []): int {
    $cpuSecs = (int)($opts['cpu_secs'] ?? 1800);
    $niceLvl = (int)($opts['nice']     ?? 10);

    $argsEsc = '';
    foreach ($args as $a) $argsEsc .= ' ' . escapeshellarg((string)$a);

    // exec'ing PHP means the shell process gets replaced — so $! returned to
    // the parent is the PHP worker's PID, which is what callers want for
    // posix_kill / status reporting.
    $inner = "ulimit -t $cpuSecs 2>/dev/null; "
           . "exec nice -n $niceLvl php " . escapeshellarg($workerPath) . $argsEsc;

    $cmd = 'nohup bash -c ' . escapeshellarg($inner)
         . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';

    $out = [];
    @exec($cmd, $out);
    return (int)trim($out[0] ?? '0');
}

}
