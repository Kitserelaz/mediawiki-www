<?php
/** Wait without blocking PHP's asynchronous signal handlers inside proc_close(). */
function farmWaitProcess($process, ?callable $heartbeat = null): int {
    if (!is_resource($process)) { return 1; }
    $nextHeartbeat = 0;
    do {
        if ($heartbeat !== null && microtime(true) >= $nextHeartbeat) {
            $heartbeat();
            $nextHeartbeat = microtime(true) + 0.5;
        }
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        usleep(100000);
    } while (true);
    $exit = proc_close($process);
    if ($exit >= 0) { return $exit; }
    if (($status['exitcode'] ?? -1) >= 0) { return $status['exitcode']; }
    return !empty($status['signaled']) ? 128 + $status['termsig'] : 1;
}
