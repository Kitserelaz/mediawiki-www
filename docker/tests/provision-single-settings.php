<?php
// Compatibility entry point for comparison tests; use production settings.
if (getenv('MW_JOBCHRON_TEST_ISOLATED') !== '1' || getenv('MW_PROVISION_SINGLE_PASS') !== '1') {
    throw new RuntimeException('Isolated provisioning comparison only');
}
require '/opt/farm/provision-settings.php';
