"""Deployment failure tests; no Docker, network, credentials or real farm required."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

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
        if args[0] == "scripts/snapshot":
            (self.root / "backups" / args[-1]).mkdir(parents=True)
        return ""


class DeploymentTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "farm"
        self.state = Path(self.tmp.name) / "state"
        self.root.mkdir()
        self.state.mkdir()
        self.environment = "MW_ENV=production\nMW_IMAGE=old-php\nWEB_IMAGE=old-web\nCOUNTER_IMAGE=old-counter\nMW_DOMAIN=cloudwiki.us\nFARM_SECRETS_DIRECTORY=/private/secrets\n"
        (self.root / ".env.production").write_text(self.environment)

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
        self.assertFalse(any(call[0] in ("docker", "scripts/snapshot") for call in runner.calls))

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
        self.assertFalse(any(call[0] in ("docker", "scripts/snapshot") for call in runner.calls))

    def test_failed_health_check_cannot_be_recorded_as_success(self):
        runner = FakeRunner(self.root)
        def failed_health(*_):
            raise RuntimeError("Unhealthy origin")
        with self.assertRaises(RuntimeError):
            self.deploy(runner, failed_health)
        self.assertFalse((self.state / "last-success.json").exists())
        self.assertIn("stop", runner.calls[-1])

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
