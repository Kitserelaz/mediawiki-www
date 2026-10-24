<?php
/** Wait for child-exit signals, retaining shutdown handlers and lease heartbeats. */
function farmWaitProcess($process, ?callable $heartbeat = null): int {
    if (!is_resource($process)) { return 1; }
    // Block before checking status: an exit between the check and wait must
    // remain pending. The child was spawned before this mask, so it is unaffected.
    if (!pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previousMask)) {
        throw new RuntimeException('Could not block child-exit signals');
    }
    try {
        $nextHeartbeat = 0;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if ($heartbeat !== null && hrtime(true) >= $nextHeartbeat) {
                $heartbeat();
                $nextHeartbeat = hrtime(true) + 500000000;
            }
            $info = [];
            if ($heartbeat === null) {
                $signal = @pcntl_sigwaitinfo([SIGCHLD], $info);
            } else {
                $remaining = max(0, $nextHeartbeat - hrtime(true));
                if ($remaining === 0) { continue; }
                $signal = @pcntl_sigtimedwait([SIGCHLD], $info,
                    intdiv($remaining, 1000000000), $remaining % 1000000000);
            }
            // Shutdown handlers remain unblocked and can interrupt either wait.
            // A timed wait also returns when the next heartbeat is due.
            // PHP returns false on timeout without updating pcntl's last error.
            // Use the monotonic deadline, not a potentially stale errno.
            $heartbeatDue = $heartbeat !== null && hrtime(true) >= $nextHeartbeat;
            if (($signal === false || $signal === -1) && !$heartbeatDue &&
                pcntl_get_last_error() !== PCNTL_EINTR) {
                throw new RuntimeException('Child-exit wait failed: ' . pcntl_strerror(pcntl_get_last_error()));
            }
        }
        // Let proc_open's own status handling reap the target and preserve its
        // exit code; do not consume it separately with pcntl_waitpid().
        $exit = proc_close($process);
    } finally {
        if (!in_array(SIGCHLD, $previousMask, true)) {
            pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD]);
        }
    }
    if ($exit >= 0) { return $exit; }
    if (($status['exitcode'] ?? -1) >= 0) { return $status['exitcode']; }
    return !empty($status['signaled']) ? 128 + $status['termsig'] : 1;
}
