#!/bin/sh
# Exercise native dependencies without needing database credentials.
set -eu
php <<'PHP'
<?php
if (mb_strlen('é') !== 1 || Normalizer::normalize("e\u{0301}") !== 'é') {
    throw new RuntimeException('Unicode runtime failed');
}
if ((new Collator('en'))->compare('a', 'b') >= 0) {
    throw new RuntimeException('ICU collation failed');
}
$image = imagecreatetruecolor(32, 32);
if (!imagepng(imagescale($image, 16, 16), '/tmp/php-runtime.png')) {
    throw new RuntimeException('GD thumbnail failed');
}
$zip = new ZipArchive();
if ($zip->open('/tmp/php-runtime.zip', ZipArchive::CREATE) !== true) {
    throw new RuntimeException('ZIP creation failed');
}
$zip->addFile('/tmp/php-runtime.png', 'thumbnail.png');
$zip->close();
new Redis();
new Memcached();
new mysqli();
if (!in_array('mysql', PDO::getAvailableDrivers(), true) || !function_exists('pcntl_signal')) {
    throw new RuntimeException('Database or worker runtime missing');
}
echo 'PHP ', PHP_VERSION, ': Unicode, GD, ZIP, database and worker modules passed.', PHP_EOL;
PHP
lua5.1 -e 'assert(_VERSION == "Lua 5.1"); assert(2 + 3 == 5); print("Lua runtime passed.")'
convert -size 32x32 xc:red /tmp/imagemagick-runtime.png
ffmpeg -hide_banner -loglevel error -i /tmp/imagemagick-runtime.png -vf scale=16:16 -frames:v 1 -threads 1 /tmp/ffmpeg-runtime.png
test -s /tmp/ffmpeg-runtime.png
zip -q /tmp/utility-runtime.zip /tmp/ffmpeg-runtime.png
unzip -tq /tmp/utility-runtime.zip
diff /tmp/ffmpeg-runtime.png /tmp/ffmpeg-runtime.png
printf '%s\n' 'ImageMagick, FFmpeg, ZIP and diff runtimes passed.'
