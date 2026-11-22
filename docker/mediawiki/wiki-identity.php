<?php

function farmNumericIdsEnabled(): bool {
    return getenv('MW_NUMERIC_WIKI_IDS') === '1';
}

function farmWikiRegistry(bool $refresh = false): array {
    static $registry;
    if (!farmNumericIdsEnabled()) {
        return [];
    }
    if ($registry === null || $refresh) {
        $file = '/var/www/mediawiki/cw_cache/wiki-identities.json';
        $data = is_readable($file) ? file_get_contents($file) : false;
        if ($data === false) {
            throw new RuntimeException('Numeric wiki identity registry is unavailable');
        }
        $registry = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
    return $registry;
}

function farmWikiId(string $wiki): string {
    return farmWikiRegistry()['aliases'][$wiki] ?? $wiki;
}

function farmWikiAuthSegment(string $wiki): string {
    if (preg_match('/^([1-9][0-9]*)wiki$/D', $wiki, $match) && farmWikiIdentity($wiki)) {
        return $match[1];
    }
    return $wiki;
}

function farmWikiIdFromAuthSegment(string $segment): string {
    if (preg_match('/^[1-9][0-9]*$/D', $segment) && farmWikiIdentity($segment . 'wiki')) {
        return $segment . 'wiki';
    }
    return farmWikiId($segment);
}

function farmWikiIdentity(string $wiki): array {
    return farmWikiRegistry()['wikis'][farmWikiId($wiki)] ?? [];
}

function farmWikiBuiltinUrl(string $wiki): ?string {
    $host = farmWikiIdentity($wiki)['farm_host'] ?? null;
    return $host === null ? null : 'https://' . $host;
}

function farmWikiStorageKey(string $wiki): string {
    return farmWikiIdentity($wiki)['storage'] ?? $wiki;
}

function farmWikiHostId(string $host): ?string {
    $host = strtolower(rtrim(preg_replace('/:[0-9]+$/D', '', $host), '.'));
    return farmWikiRegistry()['hosts'][$host] ?? null;
}
