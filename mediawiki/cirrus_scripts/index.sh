#!/bin/bash

# Get wiki name
read -p "Enter wiki database name: " wiki

# Confirm
echo "Wiki: $wiki"
read -p "Start indexing? (y/N): " confirm
[[ ! "$confirm" =~ ^[Yy]$ ]] && exit 0

# Index with auto-recovery
current_id=""
while true; do
    cmd="php /var/www/mediawiki/maintenance/run.php /var/www/mediawiki/extensions/CirrusSearch/maintenance/ForceSearchIndex.php --wiki=$wiki"
    [[ -n "$current_id" ]] && cmd="$cmd --fromId=$current_id"
    
    output=$($cmd 2>&1)
    echo "$output"
    
    if echo "$output" | grep -q "Redis server error: socket error on read socket"; then
        current_id=$(echo "$output" | grep "ending at [0-9]\+" | tail -1 | grep -o "[0-9]\+")
        [[ -n "$current_id" ]] && echo "Resuming from ID: $current_id" || break
    else
        break
    fi
done

echo "Done!"

