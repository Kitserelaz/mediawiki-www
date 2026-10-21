# OOUIFemiwikiTheme

Femiwiki Theme is a theme for [OOUI]

## Build

```sh
git clone --recurse-submodules https://github.com/femiwiki/OOUIFemiwikiTheme
# If you already cloned:
#   git submodule update --recursive --remote
yarn
./build.sh
```

## Bump oojs-ui

```sh
git checkout main
git pull
git checkout -b REL1_42
git submodule update --recursive --remote
cd oojs-ui
git checkout v0.49.1 --force
cd ..
git add oojs-ui
git commit -m 'MediaWiki 1.42 supports for OOUI v0.49.1' -m 'https://github.com/wikimedia/mediawiki-vendor/blob/REL1_42/composer.json'

asdf install nodejs $(cat oojs-ui/.nvmrc)
asdf local nodejs $(cat oojs-ui/.nvmrc)
yarn
./build.sh
git add dist
git commit -m Rebuild
```

[ooui]: https://www.mediawiki.org/wiki/OOUI
