#!/usr/bin/env python3
"""Export public assets for Caddy; PHP and dependency server code stay in PHP-FPM."""
import os, shutil
from pathlib import Path
source = Path('/var/www/mediawiki')
target = Path('/opt/public')
allowed = {'.js', '.mjs', '.css', '.less', '.map', '.json', '.html', '.htm', '.svg', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.ico', '.cur', '.woff', '.woff2', '.ttf', '.otf', '.eot', '.wasm', '.ogg', '.mp3', '.mp4', '.webm'}
for tree in ['resources', 'skins', 'extensions']:
    for directory, folders, files in os.walk(source/tree, followlinks=False):
        folders[:] = [name for name in folders if name not in {'.git', 'tests', 'Tests', 'node_modules'}]
        for name in files:
            file = Path(directory)/name
            if file.is_symlink() or file.suffix.lower() not in allowed: continue
            destination = target/file.relative_to(source)
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(file, destination)
