#!/usr/bin/env python3
"""Clone a parentless candidate snapshot without changing the working index/history."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parent.parent


def run(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs).decode().strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    output = args.output.resolve()
    if output.exists():
        parser.error('Output must not already exist')
    source_git = Path(run(['git', 'rev-parse', '--absolute-git-dir']))
    original_index = (source_git / 'index').read_bytes()
    with tempfile.TemporaryDirectory(prefix='farm-clean-export-') as temporary:
        bare = Path(temporary) / 'candidate.git'
        run(['git', 'init', '--bare', '--initial-branch=candidate', str(bare)])
        run(['git', '--git-dir', str(bare), 'config', 'pack.window', '0'])
        (bare / 'objects/info/alternates').write_text(str(source_git / 'objects') + '\n')
        (bare / 'index').write_bytes(original_index)
        exclude = source_git / 'info/exclude'
        if exclude.exists():
            shutil.copyfile(exclude, bare / 'info/exclude')
        env = {**os.environ, 'GIT_DIR': str(bare), 'GIT_WORK_TREE': str(ROOT),
               'GIT_INDEX_FILE': str(bare / 'index'),
               'GIT_AUTHOR_NAME': 'Candidate export', 'GIT_COMMITTER_NAME': 'Candidate export',
               'GIT_AUTHOR_EMAIL': 'candidate@example.invalid',
               'GIT_COMMITTER_EMAIL': 'candidate@example.invalid'}
        run(['git', '-c', 'core.ignorecase=false', 'add', '--all'], env=env)
        subprocess.run(['python3', str(ROOT / 'scripts/verify-checkout.py')],
                       cwd=ROOT, env=env, check=True)
        tree = run(['git', 'write-tree'], env=env)
        commit = run(['git', 'commit-tree', tree], env=env,
                     input=b'Uncommitted upgrade candidate snapshot for clean-checkout validation\n')
        run(['git', 'update-ref', 'refs/heads/candidate', commit], env=env)
        # --no-local transfers reachable objects, so the clone has no alternates
        # or dependency on the private temporary repository or original checkout.
        run(['git', 'clone', '--no-local', str(bare), str(output)])
    if (source_git / 'index').read_bytes() != original_index:
        raise RuntimeError('Original index changed during export')
    subprocess.run(['python3', 'scripts/verify-checkout.py'], cwd=output, check=True)
    if run(['git', '-C', str(output), 'status', '--porcelain']):
        raise RuntimeError('Exported clone is not clean')
    print(json.dumps({'tree': tree, 'snapshot_commit': commit, 'checkout': str(output),
                      'original_index_unchanged': True, 'clean': True}, indent=2))


if __name__ == '__main__':
    main()
