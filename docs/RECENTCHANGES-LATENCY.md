# RecentChanges latency investigation

Measured on 2026-10-21 against
`https://meta.localhost/wiki/Special:RecentChanges`, with MediaWiki 1.46.2 and
PHP 8.4.27. Measurements use controlled headless Chromium and direct curl,
not the user's existing browser profile. Browser-cache warmth is separate from
server-cache warmth. The investigation initially left configuration unchanged;
the authorized APCu fix and follow-up measurements are recorded below.

## Observed timing

| Scenario | HTML first byte | First contentful paint | Load event |
| --- | ---: | ---: | ---: |
| Anonymous, cold browser cache | 180 ms | 1,444 ms | 1,807 ms |
| Anonymous, warm browser cache | 93 ms | 124 ms | 1,026 ms |
| Logged in, first navigation | 105 ms | 1,464 ms | 1,455 ms |
| Logged in, warm browser cache | 97 ms | 120 ms | 825 ms |

Four direct HTML requests took 36–86 ms total. TLS handshakes took about 2–8 ms.
MediaWiki's embedded backend timer reported 32 ms on the final direct request
and 78–111 ms during browser profiling. Database work is included in these
backend figures; individual SQL statements were not profiled.

The principal delays are ResourceLoader (`load.php`) responses:

- Cold-browser JavaScript: 1,463 ms; compressed transfer about 243 kB,
  decoded size about 1.31 MB.
- Cold-browser styles: 1,152 ms, blocking initial paint.
- Logged-in first-navigation styles: 1,324 ms, again blocking initial paint.
- Warm-browser JavaScript bundle containing jQuery, OOUI core, and RecentChanges
  filters: 697–895 ms; about 119 kB compressed / 624 kB decoded.
- Replaying identical asset URLs with curl still spent 707–733 ms waiting for
  JavaScript's first byte and 791–806 ms waiting for styles. Actual transfer
  after the first byte took only a few milliseconds.

These requests overlap, so their durations must not be added together.
JavaScript execution contributed much less in this sample: one 83 ms long task
on the initial cold page and none on the other three profiled navigations.
The periodic RecentChanges peek request took about 24–80 ms. No script errors
or failed requests were seen in the initial browser captures.

## Confirmed cache problem

The PHP image installs OPcache, Redis, and Memcached but does not install APCu.
`MediaWikiServices::getLocalServerObjectCache()` resolves to `EmptyBagOStuff`.
MediaWiki's local-cache factory falls back to that empty implementation without
APCu. ResourceLoader uses this local cache for JavaScript/CSS minification.
A working main Memcached cache does not automatically fill that separate role.

An isolated FastCGI comparison pointed only diagnostic requests at the existing
Memcached service for the local-cache role. Identical JavaScript response bodies
were 623,728 bytes in both cases:

| Diagnostic condition | Server response time |
| --- | ---: |
| Normal local cache, sample 1 | 683 ms |
| Cache override, first population | 794 ms |
| Cache override, populated | 31 ms |
| Normal local cache, sample 2 | 785 ms |
| Cache override, populated | 38 ms |

That is about a 95% reduction for this particular JavaScript request with a
populated cache. It supports repeated ResourceLoader work as the main cause;
it is not a promise of the same percentage reduction for the entire page.
Styles also improved with the experimental cache, but worker reuse affected
that comparison, so no precise styles-cache speedup is claimed.

Before the experiment, OPcache was not full: about 58 MB free, 99.6% hit rate,
and zero out-of-memory/hash restarts. It is distinct from APCu.

## Recommended change

Install and enable a pinned APCu version in the PHP image, giving MediaWiki a
real server-local object cache. Rebuild/restart PHP, verify the actual FPM cache
class, and repeat these navigation/asset measurements with cold and populated
caches. A deliberate Memcached fallback for the local-cache role is another
option; APCu avoids a network round trip for each local-cache access.

After that, investigate remaining asset weight and browser module storage.
ResourceLoader declines to store individual modules whose serialized code
exceeds 100 kB (`resources/src/startup/mediawiki.loader.js`); the three large
modules were repeatedly requested in this capture. HTTP caching and module
storage should be measured separately before changing limits.

Switching localhost to HTTP would remove only the small TLS handshake portion
seen here, and would not address the measured ResourceLoader delay.

Temporary container probes were removed and PHP-FPM was restarted after the
experiment to clear per-worker FastCGI overrides. Normal asset responses and
HTTPS smoke checks were verified after cleanup. Raw captures are temporary;
sanitized measurement results are in `upgrade-evidence/recentchanges-latency.json`.

## Fix deployed locally

APCu 5.1.28 is now pinned and enabled in the PHP image with 128 MB of shared
cache memory. PHP-FPM, worker, scheduler, jobchron, and the matching web image
were rebuilt/recreated. The stack remains MediaWiki 1.46.2 / PHP 8.4.27.
The old images remain under `cloudwiki-php:pre-apcu` and `cloudwiki-web:pre-apcu`.
No schemas or production deployment changed for this cache fix.

The actual FPM cache now resolves to `Wikimedia\ObjectCache\APCUBagOStuff`.
The new `docker/tests/local-cache.py` probe stores a unique key through one FPM
request and reads/deletes it through another. The temporary PHP script is
outside the document root, is removed after the check, and uses no PHP
configuration overrides. It failed against the old image and passes with APCu.
The image check also verifies APCu can store/fetch a value. CLI APCu remains
disabled by default; the image check explicitly enables it only for that process.

| Measurement | Before | With populated APCu |
| --- | ---: | ---: |
| Same JavaScript asset: first byte | 707–733 ms | 30–77 ms |
| Same stylesheet: first byte | 791–806 ms | 21–23 ms |
| Logged-in warm browser: load event | 825 ms | 198 ms |
| Fresh browser: first paint | 1,444 ms | 180 ms |
| Fresh browser: load event | 1,807 ms | 350 ms |

The fresh-browser after sample has a populated server cache. It shows the
benefit for a new browser after the server has processed those assets, not a
fully cold restart comparison. Cold APCu still needs to compile/populate cache
entries: the first post-restart profile took 2,962 ms to its load event. Subsequent
anonymous and logged-in warm loads took 184 and 198 ms in this sample. Durations
vary with background load; this is not a promise of a fixed page-load time.

All four browser tests passed (21.1 seconds): login/SSO/logout, editing/Lua/
uploads/thumbnails, private-wiki access protection, and XML/ZIP dump jobs.
HTTPS smoke, image checks, and the FPM cross-request cache probe passed.
Sanitized results and final image identities are in
`upgrade-evidence/apcu-deployment.json`. Only native ARM64 was rebuilt locally.
