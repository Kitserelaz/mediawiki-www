"""Deployment failure tests; no Docker, network, credentials or real farm required."""
import importlib.util
import contextlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("deploy_production", Path(__file__).parents[1] / "deploy-production.py")
deployment = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deployment)


def request():
    return {"commit": "a" * 40,
            "images": {service: deployment.REGISTRY + service + "@sha256:" + "b" * 64 for service in deployment.SERVICES},
            "registry_user": "release-bot", "registry_token": "test-only-credential"}


class FakeRunner:
    def __init__(self, root, fail=None, latest="a" * 40):
        self.root, self.fail, self.latest = root, fail, latest
        self.calls = []
        self.configuration = {'services': {}, 'secrets': {}}

    def run(self, args, **kwargs):
        self.calls.append(list(args))
        if self.fail and self.fail(args):
            raise subprocess.CalledProcessError(1, args)
        if args[:3] == ["git", "status", "--porcelain"]:
            return ""
        if args[:3] == ["git", "config", "--get"]:
            return deployment.REPOSITORY
        if args[:2] == ["git", "rev-parse"]:
            return self.latest if args[2] == "FETCH_HEAD" else "c" * 40
        if args[:3] == ["docker", "image", "inspect"]:
            return 'a' * 40
        if args[:2] == ['docker', 'compose'] and args[-2:] == ['config', '--environment']:
            lines = []
            for line in (self.root / '.env.production').read_text().splitlines():
                key, separator, value = line.partition('=')
                if separator:
                    if value.startswith('"'):
                        value = json.loads(value)
                    lines.append(key + '=' + value)
            return '\n'.join(lines)
        if args[:2] == ['docker', 'compose'] and args[-2:] == ['--format', 'json']:
            return json.dumps(self.configuration)
        if args[0] == "scripts/snapshot":
            (self.root / "backups" / args[-1]).mkdir(parents=True)
        if args[:2] == ['git', 'checkout']:
            (self.root / 'scripts').mkdir(exist_ok=True)
            (self.root / 'scripts/compose').touch()
        return ""


class DeploymentTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "farm"
        self.state = Path(self.tmp.name) / "state"
        self.root.mkdir()
        self.state.mkdir()
        (self.root / 'compose.yaml').write_text('services: {}\n')
        self.environment = "MW_ENV=production\nMW_IMAGE=old-php\nWEB_IMAGE=old-web\nCOUNTER_IMAGE=old-counter\nMW_DOMAIN=cloudwiki.us\nFARM_SECRETS_DIRECTORY=/private/secrets\n"
        (self.root / ".env.production").write_text(self.environment)
        self.accept()

    def accept(self, runner=None):
        with contextlib.redirect_stdout(io.StringIO()):
            deployment.accept_local_config(self.root, self.state, runner or FakeRunner(self.root))

    def deploy(self, runner, health=lambda *_: None):
        deployment.deploy(request(), self.root, self.state, runner, health)

    def test_only_expected_images_and_command_are_accepted(self):
        self.assertEqual(deployment.validate_request("deploy", request())["commit"], "a" * 40)
        for command in ["sh", "deploy; reboot", "deploy extra"]:
            with self.assertRaises(ValueError):
                deployment.validate_request(command, request())
        for bad in ["ghcr.io/attacker/php@sha256:" + "b" * 64, deployment.REGISTRY + "php:latest"]:
            payload = request()
            payload["images"]["php"] = bad
            with self.assertRaises(ValueError):
                deployment.validate_request("deploy", payload)

    def test_duplicate_image_settings_cannot_override_the_release(self):
        output = deployment.image_environment(self.environment + 'MW_IMAGE=another-old-image\n', request()['images'])
        self.assertEqual([line for line in output.splitlines() if line.startswith('MW_IMAGE=')],
                         ['MW_IMAGE=' + request()['images']['php']] * 2)

    def test_superseded_commit_does_not_pull_or_stop_services(self):
        runner = FakeRunner(self.root, latest="d" * 40)
        self.deploy(runner)
        self.assertFalse(any(call[:2] == ['docker', 'pull'] or call[0] == 'scripts/snapshot' for call in runner.calls))

    def test_pull_failure_preserves_running_release(self):
        runner = FakeRunner(self.root, fail=lambda args: args[:2] == ["docker", "pull"])
        with self.assertRaises(subprocess.CalledProcessError):
            self.deploy(runner)
        self.assertFalse(any(call[0] == "scripts/snapshot" for call in runner.calls))
        self.assertEqual((self.root / ".env.production").read_text(), self.environment)
        self.assertFalse(list(self.state.glob("registry-*")))

    def test_snapshot_failure_never_switches_code_or_images(self):
        runner = FakeRunner(self.root, fail=lambda args: args[0] == "scripts/snapshot")
        with self.assertRaises(subprocess.CalledProcessError):
            self.deploy(runner)
        self.assertFalse(any(call[:2] == ["git", "checkout"] for call in runner.calls))
        self.assertEqual((self.root / ".env.production").read_text(), self.environment)
        self.assertIn("stop", runner.calls[-1])

    def test_wrong_image_revision_is_rejected_before_write_freeze(self):
        runner = FakeRunner(self.root)
        original_run = runner.run
        def wrong_revision(args, **kwargs):
            value = original_run(args, **kwargs)
            return 'd' * 40 if args[:3] == ['docker', 'image', 'inspect'] else value
        runner.run = wrong_revision
        with self.assertRaises(RuntimeError):
            self.deploy(runner)
        self.assertFalse(any(call[0] == 'scripts/snapshot' for call in runner.calls))

    def test_migration_failure_stops_writers_without_unsafe_code_rollback(self):
        runner = FakeRunner(self.root, fail=lambda args: args[0] == "scripts/farm")
        with self.assertRaises(subprocess.CalledProcessError):
            self.deploy(runner)
        self.assertEqual([call for call in runner.calls if call[:2] == ["git", "checkout"]],
                         [["git", "checkout", "--detach", "a" * 40]])
        self.assertIn("stop", runner.calls[-1])
        self.assertFalse((self.state / "last-success.json").exists())
        backup = next((self.root / "backups").iterdir())
        self.assertEqual((backup / "production.env").read_text(), self.environment)

    def test_success_preserves_secrets_and_records_recovery_information(self):
        runner = FakeRunner(self.root)
        self.deploy(runner)
        contents = (self.root / ".env.production").read_text()
        self.assertIn("FARM_SECRETS_DIRECTORY=/private/secrets\n", contents)
        self.assertIn("MW_DOMAIN=cloudwiki.us\n", contents)
        self.assertIn("MW_IMAGE=" + request()["images"]["php"], contents)
        self.assertIn("JOBCHRON_IMAGE=" + request()["images"]["php"], contents)
        self.assertEqual((self.root / ".env.production").stat().st_mode & 0o777, 0o600)
        record = json.loads((self.state / "last-success.json").read_text())
        self.assertEqual(record["previous_commit"], "c" * 40)
        self.assertTrue((self.root / "backups" / record["snapshot"] / "production.env").exists())
        self.assertNotIn("registry_token", record)
        self.assertFalse(any("fresh" in " ".join(call) for call in runner.calls))

    def test_repeated_successful_release_only_checks_health(self):
        self.deploy(FakeRunner(self.root))
        runner = FakeRunner(self.root)
        checks = []
        self.deploy(runner, lambda *_: checks.append(True))
        self.assertEqual(checks, [True])
        self.assertFalse(any(call[:2] == ['docker', 'pull'] or call[0] == 'scripts/snapshot' for call in runner.calls))

    def test_bind_release_is_prepared_before_snapshot_and_saved_in_environment(self):
        env = self.root / '.env.production'
        env.write_text(self.environment + 'MW_CODE_MODE=bind\n')
        self.accept()
        runner = FakeRunner(self.root)
        release = self.state / 'releases' / ('a' * 40)
        def prepare(*_):
            self.assertFalse(any(call[0] == 'scripts/snapshot' for call in runner.calls))
            return release
        with patch.object(deployment, 'prepare_release', side_effect=prepare):
            self.deploy(runner)
        self.assertIn('MW_SOURCE_DIRECTORY=' + json.dumps(str(release)), env.read_text())
        record = json.loads((self.state / 'last-success.json').read_text())
        self.assertEqual(record['code_mode'], 'bind')
        self.assertEqual(record['source_directory'], str(release))
        self.assertTrue(any(call[:2] == ['python3', 'scripts/compose'] for call in runner.calls))

    def test_export_failure_does_not_stop_the_running_farm(self):
        (self.root / '.env.production').write_text(self.environment + 'MW_CODE_MODE=bind\n')
        self.accept()
        runner = FakeRunner(self.root)
        with patch.object(deployment, 'prepare_release', side_effect=RuntimeError('Export failed')):
            with self.assertRaises(RuntimeError):
                self.deploy(runner)
        self.assertFalse(any(call[0] == 'scripts/snapshot' or 'stop' in call for call in runner.calls))

    def test_changing_mode_on_same_commit_is_not_skipped(self):
        self.deploy(FakeRunner(self.root))
        (self.root / '.env.production').write_text(self.environment + 'MW_CODE_MODE=bind\n')
        self.accept()
        runner = FakeRunner(self.root)
        with patch.object(deployment, 'prepare_release', return_value=self.state / 'releases' / ('a' * 40)):
            self.deploy(runner)
        self.assertTrue(any(call[0] == 'scripts/snapshot' for call in runner.calls))

    def test_failed_health_check_cannot_be_recorded_as_success(self):
        runner = FakeRunner(self.root)
        def failed_health(*_):
            raise RuntimeError("Unhealthy origin")
        with self.assertRaises(RuntimeError):
            self.deploy(runner, failed_health)
        self.assertFalse((self.state / "last-success.json").exists())
        self.assertIn("stop", runner.calls[-1])

    def test_missing_baseline_requires_local_acknowledgement(self):
        (self.state / deployment.CONFIG_BASELINE).unlink()
        runner = FakeRunner(self.root)
        with self.assertRaisesRegex(RuntimeError, 'No accepted local configuration baseline'):
            self.deploy(runner)
        self.assertEqual(runner.calls, [])

    def test_edited_dotenv_blocks_before_fetch_without_exposing_values(self):
        env = self.root / '.env.production'
        changed = self.environment + 'MW_SMTP_PASSWORD=private-test-value\n'
        env.write_text(changed)
        runner = FakeRunner(self.root)
        with self.assertRaises(RuntimeError) as caught:
            self.deploy(runner)
        self.assertIn('.env.production', str(caught.exception))
        self.assertNotIn('private-test-value', str(caught.exception))
        self.assertEqual(runner.calls, [])
        self.assertEqual(env.read_text(), changed)

    def test_config_edit_during_pull_leaves_services_running_and_preserves_edit(self):
        runner = FakeRunner(self.root)
        original = runner.run
        env = self.root / '.env.production'
        def edit_during_pull(args, **kwargs):
            result = original(args, **kwargs)
            if args[:2] == ['docker', 'pull']:
                env.write_text(self.environment + 'MW_EMAIL_ENABLED=1\n')
            return result
        runner.run = edit_during_pull
        with self.assertRaisesRegex(RuntimeError, 'Local configuration changed'):
            self.deploy(runner)
        self.assertFalse(any(call[0] == 'scripts/snapshot' or 'stop' in call for call in runner.calls))
        self.assertIn('MW_EMAIL_ENABLED=1', env.read_text())

    def test_config_edit_during_snapshot_is_not_overwritten(self):
        runner = FakeRunner(self.root)
        original = runner.run
        env = self.root / '.env.production'
        def edit_during_snapshot(args, **kwargs):
            result = original(args, **kwargs)
            if args[0] == 'scripts/snapshot':
                env.write_text(self.environment + 'MW_EMAIL_ENABLED=1\n')
            return result
        runner.run = edit_during_snapshot
        with self.assertRaisesRegex(RuntimeError, 'Local configuration changed'):
            self.deploy(runner)
        self.assertFalse(any(call[:2] == ['git', 'checkout'] for call in runner.calls))
        self.assertIn('MW_EMAIL_ENABLED=1', env.read_text())

    def test_secret_edits_and_new_secret_files_are_detected(self):
        secrets = self.root / 'private-secrets'
        secrets.mkdir()
        farm = secrets / 'farm.json'
        farm.write_text('original-secret')
        runner = FakeRunner(self.root)
        runner.configuration['secrets']['farm'] = {'file': str(farm)}
        self.accept(runner)
        farm.write_text('changed-secret')
        with self.assertRaises(RuntimeError) as caught:
            self.deploy(runner)
        self.assertIn('farm.json', str(caught.exception))
        self.assertNotIn('changed-secret', str(caught.exception))
        farm.write_text('original-secret')
        (secrets / 'new-runtime.json').write_text('new-secret')
        with self.assertRaisesRegex(RuntimeError, 'new-runtime.json'):
            self.deploy(runner)

    def test_successful_deployment_records_its_own_env_changes_as_expected(self):
        self.deploy(FakeRunner(self.root))
        expected = json.loads((self.state / deployment.CONFIG_BASELINE).read_text())
        self.assertEqual((self.state / deployment.CONFIG_BASELINE).stat().st_mode & 0o777, 0o600)
        deployment.check_local_config(self.root, self.state, FakeRunner(self.root))
        self.assertNotIn('test-only-credential', json.dumps(expected))

    def test_active_bind_release_php_settings_are_protected(self):
        source = self.state / 'releases' / ('c' * 40)
        (source / 'mediawiki').mkdir(parents=True)
        (source / 'docker/mediawiki').mkdir(parents=True)
        (source / 'release.json').write_text(json.dumps({'commit': 'c' * 40}))
        (source / 'docker/mediawiki/private.php').write_text('<?php // loader')
        config = source / 'mediawiki/LocalSettings.php'
        config.write_text('<?php // original')
        (self.root / '.env.production').write_text(self.environment + 'MW_CODE_MODE=bind\nMW_SOURCE_DIRECTORY=' + json.dumps(str(source)) + '\n')
        self.accept()
        config.write_text('<?php // locally edited')
        runner = FakeRunner(self.root)
        with self.assertRaisesRegex(RuntimeError, 'LocalSettings.php'):
            self.deploy(runner)
        self.assertFalse(any(call[0] == 'scripts/snapshot' for call in runner.calls))

    def test_mounted_config_file_changes_are_detected(self):
        config = self.root / 'wiki.caddy'
        config.write_text('original')
        runner = FakeRunner(self.root)
        runner.configuration['services']['web'] = {'volumes': [{'type': 'bind', 'source': str(config)}]}
        self.accept(runner)
        config.write_text('locally edited')
        with self.assertRaisesRegex(RuntimeError, 'wiki.caddy'):
            self.deploy(runner)

    def test_ignored_compose_override_and_service_env_file_are_protected(self):
        override = self.root / 'compose.local.yaml'
        override.write_text('services: {}\n')
        service_env = self.root / 'service.env'
        service_env.write_text('VALUE=original\n')
        (self.root / '.env.production').write_text(self.environment + 'COMPOSE_FILE=compose.yaml:compose.local.yaml\n')
        runner = FakeRunner(self.root)
        runner.configuration['services']['php'] = {'env_file': [{'path': str(service_env), 'required': True}]}
        self.accept(runner)
        override.write_text('invalid local override')
        with self.assertRaisesRegex(RuntimeError, 'compose.local.yaml'):
            self.deploy(runner)
        override.write_text('services: {}\n')
        service_env.write_text('VALUE=changed\n')
        with self.assertRaisesRegex(RuntimeError, 'service.env'):
            self.deploy(runner)

    def test_changes_during_migration_are_not_automatically_accepted(self):
        baseline = (self.state / deployment.CONFIG_BASELINE).read_text()
        def edit_config(*_):
            env = self.root / '.env.production'
            env.write_text(env.read_text() + 'MW_EMAIL_ENABLED=1\n')
        runner = FakeRunner(self.root)
        with self.assertRaisesRegex(RuntimeError, 'Local configuration changed'):
            self.deploy(runner, edit_config)
        self.assertEqual((self.state / deployment.CONFIG_BASELINE).read_text(), baseline)
        self.assertIn('stop', runner.calls[-1])

    def test_human_can_acknowledge_an_intentional_env_edit(self):
        (self.root / '.env.production').write_text(self.environment + 'MW_EMAIL_ENABLED=1\n')
        self.accept()
        self.deploy(FakeRunner(self.root))
        self.assertTrue((self.state / 'last-success.json').exists())

    def test_forced_ssh_command_rejects_acceptance_and_shell_commands(self):
        wrapper = Path(__file__).parents[1] / 'deploy-ssh'
        import os
        for command in ['--accept-config', '--check-config', 'deploy --accept-config', 'sh', '']:
            result = subprocess.run(['sh', str(wrapper)], env=dict(os.environ, SSH_ORIGINAL_COMMAND=command),
                                    input='{}', text=True, capture_output=True)
            self.assertEqual(result.returncode, 1)
            self.assertIn('Only the deploy command is permitted', result.stderr)

    def live_environment(self):
        (self.root / 'docker/mediawiki').mkdir(parents=True)
        (self.root / 'docker/mediawiki/private.php').write_text('<?php // loader')
        (self.root / '.env.production').write_text(self.environment + 'MW_CODE_MODE=bind\nMW_BIND_STRATEGY=live\nMW_SOURCE_DIRECTORY=' + json.dumps(str(self.root)) + '\n')
        self.accept()

    def test_live_deployment_keeps_checkout_and_pauses_editor_through_health(self):
        self.live_environment()
        runner = FakeRunner(self.root)
        def health(*_):
            self.assertTrue(any(call[-2:] == ['stop', 'code'] for call in runner.calls))
            self.assertFalse(any(call[-1:] == ['code'] and 'up' in call for call in runner.calls))
        with patch.object(deployment, 'prepare_release') as export:
            self.deploy(runner, health)
        export.assert_not_called()
        record = json.loads((self.state / 'last-success.json').read_text())
        self.assertEqual(record['source_directory'], str(self.root))
        self.assertEqual(record['bind_strategy'], 'live')
        self.assertTrue(any('code=0' in call for call in runner.calls))
        self.assertTrue(any(call[-1:] == ['code'] and 'up' in call for call in runner.calls))
        self.assertTrue(any('cloudwiki-live-permissions.py' in ' '.join(call) for call in runner.calls))

    def test_new_untracked_editor_file_blocks_before_fetch(self):
        self.live_environment()
        runner = FakeRunner(self.root)
        original = runner.run
        def dirty(args, **kwargs):
            result = original(args, **kwargs)
            return '?? mediawiki/new.php' if '--untracked-files=all' in args else result
        runner.run = dirty
        with self.assertRaisesRegex(RuntimeError, 'untracked live edits'):
            self.deploy(runner)
        self.assertFalse(any(call[:2] == ['git', 'fetch'] or 'stop' in call for call in runner.calls))

    def test_edit_while_editor_is_stopping_aborts_and_restarts_only_editor(self):
        self.live_environment()
        runner = FakeRunner(self.root)
        original = runner.run
        stopped = False
        def edit(args, **kwargs):
            nonlocal stopped
            result = original(args, **kwargs)
            if args[-2:] == ['stop', 'code']:
                stopped = True
            return ' M mediawiki/index.php' if stopped and '--untracked-files=all' in args else result
        runner.run = edit
        with self.assertRaisesRegex(RuntimeError, 'untracked live edits'):
            self.deploy(runner)
        self.assertFalse(any(call[0] == 'scripts/snapshot' or call[:2] == ['git', 'checkout'] for call in runner.calls))
        self.assertEqual(runner.calls[-1][-4:], ['-d', '--no-deps', '--no-build', 'code'])

    def test_live_mode_rejects_a_different_checkout(self):
        other = self.root / 'other'
        (other / 'docker/mediawiki').mkdir(parents=True)
        (other / 'docker/mediawiki/private.php').write_text('<?php // loader')
        (self.root / '.env.production').write_text(self.environment + 'MW_CODE_MODE=bind\nMW_BIND_STRATEGY=live\nMW_SOURCE_DIRECTORY=' + json.dumps(str(other)) + '\n')
        self.accept()
        runner = FakeRunner(self.root)
        with self.assertRaisesRegex(RuntimeError, 'application checkout'):
            self.deploy(runner)
        self.assertFalse(any(call[:2] == ['docker', 'pull'] for call in runner.calls))

    def test_retention_preserves_current_images_and_operator_backups(self):
        history = self.state / 'history'
        history.mkdir()
        for index in range(4):
            name = 'deploy-' + str(index) + '-' + 'a' * 12
            backup = self.root / 'backups' / name
            backup.mkdir(parents=True)
            record = {'snapshot': name, 'images': {'php': 'image-' + str(index)}}
            (history / (name + '.json')).write_text(json.dumps(record))
        operator_backup = self.root / 'backups' / 'manual-backup'
        operator_backup.mkdir()
        runner = FakeRunner(self.root)
        deployment.trim_history(self.root, self.state, runner)
        self.assertTrue(operator_backup.exists())
        self.assertFalse((self.root / 'backups' / ('deploy-0-' + 'a' * 12)).exists())
        self.assertTrue((self.root / 'backups' / ('deploy-1-' + 'a' * 12)).exists())
        self.assertEqual(runner.calls, [['docker', 'image', 'rm', 'image-0'], ['docker', 'image', 'rm', 'image-1']])


if __name__ == "__main__":
    unittest.main()
