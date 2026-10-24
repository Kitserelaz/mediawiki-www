<?php
// Run inside the PHP runtime: php /path/to/process-wait.php /opt/farm/process.php
require $argv[1] ?? '/opt/farm/process.php';
pcntl_async_signals(true);
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function child(string $code) {
    $process = proc_open([PHP_BINARY, '-r', $code],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes);
    check(is_resource($process), 'Child spawn failed');
    return $process;
}
function signalMask(): array {
    pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $mask);
    if (!in_array(SIGCHLD, $mask, true)) { pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD]); }
    return $mask;
}
$originalMask = signalMask();
foreach ([0, 7, 23] as $exit) {
    check(farmWaitProcess(child('exit(' . $exit . ');')) === $exit, 'Incorrect exit status');
}
// Exits before the helper starts must not require a new SIGCHLD to wake it.
$finished = child('exit(19);');
usleep(80000);
check(farmWaitProcess($finished) === 19, 'Already-exited child was not collected');
for ($i = 0; $i < 60; $i++) {
    check(farmWaitProcess(child('exit(0);')) === 0, 'Fast-exit race');
}
$other = child('usleep(20000); exit(11);');
check(farmWaitProcess(child('usleep(100000); exit(12);')) === 12, 'Unrelated SIGCHLD ended target wait');
check(farmWaitProcess($other) === 11, 'Unrelated child status was consumed');
$heartbeats = [];
check(farmWaitProcess(child('usleep(1150000);'), static function () use (&$heartbeats): void {
    $heartbeats[] = hrtime(true);
}) === 0, 'Heartbeat child failed');
check(count($heartbeats) >= 3 && count($heartbeats) <= 4, 'Incorrect heartbeat cadence');
for ($i = 1; $i < count($heartbeats); $i++) {
    check(($heartbeats[$i] - $heartbeats[$i - 1]) / 1e9 < 0.8, 'Heartbeat deadline missed');
}
// Both shutdown signals must run the existing handler and forward to the child.
foreach ([SIGTERM, SIGINT] as $shutdown) {
    $target = child('usleep(5000000);');
    $handled = false;
    pcntl_signal($shutdown, static function () use (&$handled, $target): void {
        $handled = true;
        proc_terminate($target, SIGTERM);
    });
    $sender = child('usleep(60000); posix_kill(' . getmypid() . ', ' . $shutdown . ');');
    check(farmWaitProcess($target) === 128 + SIGTERM, 'Signaled child exit status lost');
    check($handled, 'Shutdown handler did not run during signal wait');
    check(farmWaitProcess($sender) === 0, 'Signal sender failed');
    pcntl_signal($shutdown, SIG_DFL);
}
// Failure to renew a lease must restore the signal mask for caller cleanup.
$target = child('usleep(5000000);');
try {
    farmWaitProcess($target, static function (): void { throw new RuntimeException('lease failure'); });
    throw new RuntimeException('Heartbeat failure was swallowed');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'lease failure', 'Unexpected heartbeat error');
}
$maskAfterFailure = signalMask();
check($maskAfterFailure === $originalMask, 'Signal mask leaked after heartbeat failure');
proc_terminate($target, SIGTERM);
check(farmWaitProcess($target) === 128 + SIGTERM, 'Cleanup after lease failure failed');
$finalMask = signalMask();
check($finalMask === $originalMask, 'Signal mask was not restored');
pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD, SIGUSR1]);
$blockedMask = signalMask();
check(farmWaitProcess(child('exit(5);')) === 5, 'Preblocked signal test failed');
check(signalMask() === $blockedMask, 'Previously blocked signals were unblocked');
foreach ([SIGCHLD, SIGUSR1] as $signal) {
    if (!in_array($signal, $originalMask, true)) { pcntl_sigprocmask(SIG_UNBLOCK, [$signal]); }
}
echo "Exit codes, fast exits, unrelated children, heartbeats, shutdown and lease failures passed\n";
