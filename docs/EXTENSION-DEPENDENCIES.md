# Container extension dependencies

The manifest and Composer requirements of every supplied extension/skin are recorded in [dependency-inventory.json](dependency-inventory.json). The image preserves the supplied vendor trees and lockfiles. This inventory does not establish that all supplied extensions work together; test each selected combination before production. Selected component dependencies were regenerated with pinned tools for this upgrade; exact packages, source targets and preservation reports are recorded in the inventory and source-port reports. Required files hidden by upstream ignore rules are explicitly tracked and hash-checked through `upgrade-vendored-build-inputs.json`.

| Feature / extensions | Container support or prerequisite |
| --- | --- |
| Core, CreateWiki, ManageWiki, CentralAuth, CheckUser, OAuth | PHP 8.4.26, mysqli/PDO, intl, mbstring, GMP, OpenSSL, sodium, XML, ZIP; shared database schemas and mounted OAuth keys are initialized explicitly. |
| Scribunto | Lua 5.1 at `/usr/bin/lua5.1`; exercised by browser acceptance tests. |
| Thumbnails, NativeSvgHandler, SVG | ImageMagick, GD, librsvg, Ghostscript and fonts; uploaded PNG thumbnails are exercised by acceptance tests. |
| PDFHandler / DjVu / TIFF | Poppler, Ghostscript, DjVuLibre and TIFF tools are installed. Rendering each uncommon format still needs a representative upload test. |
| TimedMediaHandler / media conversion | FFmpeg is installed. Codec-specific workflows and transcode schedules need deployment inventory. |
| SyntaxHighlight | Python 3 and system Pygments are installed. |
| Timeline / GraphViz | Ploticus, Graphviz and FreeFont are installed. |
| CirrusSearch / Elastica | Optional internal Elasticsearch 7.10.2 profile, matching the bundled CirrusSearch README. Explicit index configuration and backfill are required. |
| LDAP extensions | PHP LDAP is installed; supply an LDAP server, TLS trust and credentials. |
| Math | Bundled modes include source, native and MathJax. Configure local rendering deliberately; the bundled README describes external Wikimedia REST rendering. Do not enable external rendering on restored development data without opting in. |
| Score | Requires a separate isolated Shellbox renderer with LilyPond, FluidSynth, a SoundFont and LAME, as described in the bundled Score README. These are not installed in the PHP image. |
| Phonos | Default engine is eSpeak; provision its speech binaries in a custom renderer image, or explicitly configure a TTS service and credentials. It is outside the baseline acceptance tests. |
| Maps / geocoding / external data / remote repositories | Require separately configured tile, geocoding, API or repository endpoints. These services are not provisioned by Compose. |
| hCaptcha, Matomo, Discord, Cloudflare | Disabled locally; production credentials and endpoints must be supplied explicitly. |
| Mail | Local SMTP is Mailpit; production needs an inventoried SMTP service. |

ManageWiki retains its existing catalogue and production choices. Availability in that catalogue does not provision optional external services. Audit settings restored from production before enabling optional extensions locally. Additional optional renderers and integrations must be tested separately; the default stack has no requirement for them.

## Bundled source fixes

The Docker acceptance audit patched two DataDump API defects in the supplied source: dump deletion now allows completed/failed records (the previous OR condition always rejected them), and generation-limit checks read the scalar COUNT instead of casting a database result object. Original files are preserved in the private backup directory. The inventory records the patched file hashes because the upstream extension version is unchanged. The runtime explicitly selects `/usr/local/bin/php` for maintenance jobs.
