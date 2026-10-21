#!/usr/bin/env python3
"""Port a vendored component to a pinned Git tree, preserving local source edits.

Requires an original upstream baseline already present in the source repository.
Refuses conflicting source edits before modifying the installed directory. Runtime
dependency updates must subsequently regenerate the relevant Composer lockfiles.
"""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import re

SKIP = {'.git', 'vendor', 'node_modules', '.phpunit.cache', '__pycache__'}
GENERATED = {'version', 'gitinfo.json', 'composer.lock', '.DS_Store', '.stylelintcache', '.eslintcache'}


def is_translation(name):
    return 'i18n' in Path(name).parts and name.endswith('.json')


def files(root):
    output = {}
    for path in root.rglob('*'):
        relative = path.relative_to(root)
        if any(part in SKIP for part in relative.parts) or path.name in GENERATED:
            continue
        if path.is_symlink():
            raise RuntimeError('Source symlink requires manual review: ' + str(relative))
        if path.is_file():
            output[relative.as_posix()] = path
    return output


def export(repo, ref, destination):
    destination.mkdir()
    process = subprocess.Popen(['git', '-C', str(repo), 'archive', ref], stdout=subprocess.PIPE)
    result = subprocess.run(['tar', '-xf', '-', '-C', str(destination)], stdin=process.stdout)
    process.stdout.close()
    if result.returncode or process.wait():
        raise RuntimeError('Unable to export upstream tree')


def merge_json(base, local, target, location=''):
    if local == base or local == target:
        return target
    if target == base:
        return local
    if isinstance(base, dict) and isinstance(local, dict) and isinstance(target, dict):
        output = {}
        for key in dict.fromkeys([*target, *local]):
            if key not in local:
                if key not in base:
                    output[key] = target[key]
                continue
            if key not in target:
                if key not in base or local[key] != base[key]:
                    output[key] = local[key]
                continue
            # Dependency constraints are intentionally upgraded with upstream.
            if location == '' and key in {'require', 'require-dev'}:
                output[key] = target[key]
                for package, constraint in local[key].items():
                    if package not in base.get(key, {}):
                        output[key][package] = constraint
            else:
                output[key] = merge_json(base.get(key), local[key], target[key], location + '/' + key)
        return output
    raise RuntimeError('Conflicting Composer customization: ' + location)


def merge_translations(base, local, target):
    output = dict(target)
    for key, value in local.items():
        if value == base.get(key):
            continue
        if key == '@metadata':
            metadata = dict(target.get(key, {}))
            metadata.update(value)
            metadata['authors'] = list(dict.fromkeys(target.get(key, {}).get('authors', []) + value.get('authors', [])))
            output[key] = metadata
        else:
            # Extra supplied parameters can safely be unused by local wording.
            # Never preserve references to removed or renamed parameters.
            if key in target and isinstance(value, str) and isinstance(target[key], str):
                if not set(re.findall(r'\$\d+', value)).issubset(set(re.findall(r'\$\d+', target[key]))):
                    raise RuntimeError('Translation placeholder contract changed: ' + key)
            output[key] = value
    for key in base.keys() - local.keys():
        output.pop(key, None)
    return output


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('component', type=Path)
    parser.add_argument('repository', type=Path)
    parser.add_argument('--baseline', required=True)
    parser.add_argument('--target', default='HEAD')
    parser.add_argument('--report', type=Path, required=True)
    parser.add_argument('--workspace', type=Path, default=Path(__file__).resolve().parent.parent)
    parser.add_argument('--keep-local-translations', action='store_true')
    args = parser.parse_args()
    allowed = [args.workspace.resolve() / 'mediawiki' / kind for kind in ['extensions', 'skins']]
    if not args.component.is_dir() or args.component.resolve().parent not in allowed:
        raise RuntimeError('Component must be a direct child of this workspace mediawiki/extensions or mediawiki/skins')
    target_ref = subprocess.check_output(['git', '-C', str(args.repository), 'rev-parse', args.target], text=True).strip()
    with tempfile.TemporaryDirectory(prefix='farm-component-upgrade-') as directory:
        directory = Path(directory)
        base, target = directory / 'baseline', directory / 'target'
        export(args.repository, args.baseline, base)
        export(args.repository, target_ref, target)
        old_files, local_files, new_files = files(base), files(args.component), files(target)
        preserved = []
        for name in sorted(set(old_files) | set(local_files)):
            original = old_files[name].read_bytes() if name in old_files else None
            local = local_files[name].read_bytes() if name in local_files else None
            if local == original:
                continue
            output = target / name
            if local is None:
                if output.exists():
                    output.unlink()
                preserved.append({'path': name, 'change': 'local-deletion'})
                continue
            if name not in new_files:
                if original is not None and not (args.keep_local_translations and is_translation(name)):
                    raise RuntimeError('Upstream removed locally modified source: ' + name)
                output.parent.mkdir(parents=True, exist_ok=True)
                output.write_bytes(local)
            elif local == new_files[name].read_bytes():
                continue
            elif original is None:
                if args.keep_local_translations and is_translation(name):
                    # Language files may be present in a translation-updated
                    # distribution even when absent from its frozen release branch.
                    merged = merge_translations({}, json.loads(local), json.loads(output.read_bytes()))
                    output.write_text(json.dumps(merged, indent=2, ensure_ascii=False) + '\n')
                else:
                    raise RuntimeError('Upstream collides with local addition: ' + name)
            elif name == 'composer.json':
                merged = merge_json(json.loads(original), json.loads(local), json.loads(output.read_bytes()))
                output.write_text(json.dumps(merged, indent='\t', ensure_ascii=False) + '\n')
            elif args.keep_local_translations and is_translation(name):
                merged = merge_translations(json.loads(original), json.loads(local), json.loads(output.read_bytes()))
                output.write_text(json.dumps(merged, indent='\t', ensure_ascii=False) + '\n')
            elif b'\0' in local or b'\0' in original:
                if output.read_bytes() != original:
                    raise RuntimeError('Both upstream and local binary asset changed: ' + name)
                output.write_bytes(local)
            else:
                merged = subprocess.run(['git', 'merge-file', '-p', str(local_files[name]), str(old_files[name]), str(output)], stdout=subprocess.PIPE)
                if merged.returncode:
                    raise RuntimeError('Conflicting local source edit: ' + name)
                output.write_bytes(merged.stdout)
            preserved.append({'path': name, 'change': 'local-source-edit'})
        # All conflicts were checked above. Keep repository/dependency directories;
        # replace source trees with their exact upstream path capitalization.
        for path in args.component.iterdir():
            if path.name in SKIP or path.name == 'composer.lock':
                continue
            if path.is_dir():
                shutil.rmtree(path)
            else:
                path.unlink()
        shutil.copytree(target, args.component, dirs_exist_ok=True)
        report = {'component': args.component.resolve().relative_to(args.workspace.resolve()).as_posix(), 'baseline': args.baseline,
                  'target': target_ref, 'preserved_changes': preserved,
                  'dependency_status': 'requires-lock-regeneration'}
        args.report.write_text(json.dumps(report, indent=2) + '\n')
        print(json.dumps(report))


if __name__ == '__main__':
    main()
