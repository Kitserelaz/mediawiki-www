<?php
// A live process alone is insufficient: Redis failures must make this unhealthy.
$file = '/tmp/farm-jobchron-heartbeat';
$lastSuccess = is_file($file) ? (int)file_get_contents($file) : 0;
exit($lastSuccess > 0 && time() - $lastSuccess <= 120 ? 0 : 1);
