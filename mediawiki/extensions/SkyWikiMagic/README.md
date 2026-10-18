# WikiOasisMagic

Forked from [miraheze/MirahezeMagic](https://github.com/miraheze/MirahezeMagic)

The privacy hook removes sitemap XML and gzip files for the affected wiki from
`sitemaps/<dbname>/` and the legacy `images/<dbname>/sitemaps/` directory. The
`maintenance/sitemap.php` script uses the same cleanup before generating public
sitemaps; running it for an already-private wiki only removes its sitemaps.

Run the standalone regression checks from the MediaWiki directory:

```sh
php extensions/SkyWikiMagic/tests/audit-regressions.php
```

The checks use disposable files and parser stubs without connecting to wiki databases.
