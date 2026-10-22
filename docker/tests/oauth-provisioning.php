<?php
// OAuth schema/routing regression helper for disposable provisioning farms only.
if (posix_geteuid() === 0) { posix_setgid(33); posix_setuid(33); }
require '/var/www/mediawiki/maintenance/Maintenance.php';

use MediaWiki\Extension\OAuth\Backend\Consumer;
use MediaWiki\Extension\OAuth\Backend\ConsumerAcceptance;
use MediaWiki\Extension\OAuth\Backend\Utils;
use MediaWiki\Utils\MWRestrictions;

class OAuthProvisioningCheck extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('exercise', 'Round-trip OAuth 1/2 consumers and acceptances');
    }
    private function check(bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    }
    public function execute() {
        $this->check(getenv('MW_JOBCHRON_TEST_ISOLATED') === '1', 'Disposable farm required');
        $services = $this->getServiceContainer();
        $wiki = $this->getConfig()->get('DBname');
        $oauth = Utils::getOAuthDB(DB_PRIMARY);
        $exercises = [];
        if ($this->hasOption('exercise')) {
            $this->check(!getenv('MW_BOOTSTRAP_DB') && $oauth->getDBname() === 'metawiki', 'Expected central runtime routing');
            foreach ([1, 2] as $version) {
                $key = bin2hex(random_bytes(16));
                $timestamp = '20261021010203';
                $consumer = Consumer::newFromArray([
                    'id'=>null, 'consumerKey'=>$key, 'name'=>'Provisioning regression '.$key,
                    'userId'=>1, 'version'=>'1', 'callbackUrl'=>'https://example.invalid/callback',
                    'callbackIsPrefix'=>false, 'description'=>'Disposable persistence test',
                    'email'=>'test@example.invalid', 'emailAuthenticated'=>null,
                    'developerAgreement'=>true, 'ownerOnly'=>false, 'oauthVersion'=>$version,
                    'wiki'=>'*', 'registration'=>$timestamp, 'secretKey'=>bin2hex(random_bytes(16)),
                    'rsaKey'=>null, 'grants'=>['basic'], 'restrictions'=>MWRestrictions::newDefault(),
                    'stage'=>Consumer::STAGE_APPROVED, 'stageTimestamp'=>$timestamp, 'deleted'=>false,
                    'oauth2IsConfidential'=>true, 'oauth2GrantTypes'=>['authorization_code','refresh_token'],
                ]);
                $this->check($consumer->save($oauth), 'Consumer insert failed');
                $loaded = Consumer::newFromKey($oauth, $key);
                $this->check($loaded && $loaded->get('registration') === $timestamp &&
                    $loaded->get('stageTimestamp') === $timestamp && $loaded->get('emailAuthenticated') === null,
                    'Consumer timestamp/null readback failed');
                $loaded->setField('emailAuthenticated', $timestamp);
                $this->check($loaded->save($oauth), 'Consumer update failed');
                $this->check(Consumer::newFromKey($oauth, $key)->get('emailAuthenticated') === $timestamp,
                    'Authenticated email timestamp readback failed');
                $token = bin2hex(random_bytes(16));
                $acceptance = ConsumerAcceptance::newFromArray([
                    'id'=>null, 'wiki'=>$wiki, 'userId'=>1, 'consumerId'=>$loaded->getId(),
                    'accessToken'=>$token, 'accessSecret'=>bin2hex(random_bytes(16)),
                    'grants'=>['basic'], 'accepted'=>$timestamp, 'oauth_version'=>$version,
                ]);
                $this->check($acceptance->save($oauth), 'Acceptance insert failed');
                $saved = ConsumerAcceptance::newFromToken($oauth, $token);
                $this->check($saved && $saved->getAccepted() === $timestamp, 'Acceptance timestamp readback failed');
                $this->check($saved->delete($oauth) && $loaded->delete($oauth), 'Fixture cleanup failed');
                $exercises[] = ['oauth_version'=>$version, 'consumer_insert_read_update'=>true,
                    'nullable_email_timestamp'=>true, 'acceptance_insert_read'=>true];
            }
            $services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
        }
        global $wgDBpassword;
        $databases = [];
        foreach (array_unique([$wiki, 'metawiki']) as $name) {
            $db = new PDO('mysql:host=database;dbname='.$name, 'mediawiki', $wgDBpassword,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $tables = [];
            foreach (['oauth_registered_consumer','oauth_accepted_consumer','oauth2_access_tokens'] as $table) {
                $create = $db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
                $tables[$table] = ['schema_sha256'=>hash('sha256', preg_replace('/ AUTO_INCREMENT=\d+/', '', $create)),
                    'rows'=>(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()];
            }
            $columns = $db->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=".$db->quote($name)." AND COLUMN_NAME IN
                ('oarc_email_authenticated','oarc_registration','oarc_stage_timestamp','oaac_accepted')
                ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
            $markers = $db->query("SELECT ul_key FROM updatelog WHERE ul_key LIKE 'oauth%timestamp.sql' ORDER BY ul_key")->fetchAll(PDO::FETCH_COLUMN);
            $databases[$name] = ['tables'=>$tables, 'timestamp_columns'=>$columns, 'timestamp_patch_markers'=>$markers];
        }
        echo json_encode(['wiki'=>$wiki, 'bootstrap'=>(bool)getenv('MW_BOOTSTRAP_DB'),
            'central_wiki'=>$this->getConfig()->get('MWOAuthCentralWiki'),
            'oauth_database'=>$oauth->getDBname(),
            'shared_virtual_domain'=>$services->getDBLoadBalancerFactory()->isSharedVirtualDomain('virtual-oauth'),
            'databases'=>$databases, 'exercises'=>$exercises]), "\n";
    }
}
return OAuthProvisioningCheck::class;
