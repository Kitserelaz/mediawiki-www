<?php
// The management service runs as root; generated metadata must match the worker.
if (posix_geteuid() === 0) { posix_setgid(33); posix_setuid(33); }
require '/var/www/mediawiki/maintenance/Maintenance.php';
class ProvisioningAcceptance extends MediaWiki\Maintenance\Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption('action','create or inspect',true,true);
        $this->addOption('target','New fixture',false,true);
        $this->addOption('variant','public/private/extra',false,true);
        $this->addOption('checkuser','Exercise real central-index writer and lookup');
    }
    private function check(bool $ok,string $message): void { if(!$ok){throw new RuntimeException($message);} }
    public function execute() {
        $isolated=getenv('MW_JOBCHRON_TEST_ISOLATED')==='1';
        $freshLocalInspection=$this->getOption('action')==='inspect' && getenv('MW_ENV')==='development'
            && preg_match('/^9creation[a-z0-9]+wiki$/D',$this->getConfig()->get('DBname'));
        $this->check($isolated || (bool)$freshLocalInspection,'Disposable farm or fresh local creation fixture only');
        $services=$this->getServiceContainer();
        if($this->getOption('action')==='create'){
            $wiki=$this->getOption('target');$variant=$this->getOption('variant');
            $this->check((bool)preg_match('/^9provision[a-z0-9]+wiki$/D',$wiki),'Invalid fixture');
            $private=$variant==='private';$lang=$private?'de':'en';
            $manager=$services->get('WikiManagerFactory')->newInstance($wiki);
            $error=$manager->create('Provisioning fixture',$lang,$private,'uncategorised',getenv('ADMIN_USER')?:'Admin',getenv('ADMIN_USER')?:'Admin','Provisioning equivalence test',[]);
            $this->check($error===null,'Creation failed: '.$error);
            if(in_array($variant,['extra','schema'],true)){
                // Seed a custom creation default without pre-installing its SQL.
                $db=$services->getConnectionProvider()->getPrimaryDatabase('virtual-managewiki');
                $old=$db->newSelectQueryBuilder()->select('s_extensions')->from('mw_settings')->where(['s_dbname'=>$wiki])->caller(__METHOD__)->fetchField();
                $extensions=json_decode($old?:'[]',true);foreach($variant==='schema'?['cargo','linter']:['comments'] as $extension){$extensions[]=$extension;}
                $db->newInsertQueryBuilder()->insertInto('mw_settings')->row(['s_dbname'=>$wiki,'s_extensions'=>json_encode(array_values(array_unique($extensions))),'s_settings'=>json_encode(['wgEnableUploads'=>false])])->onDuplicateKeyUpdate()->uniqueIndexFields('s_dbname')->set(['s_extensions'=>json_encode(array_values(array_unique($extensions))),'s_settings'=>json_encode(['wgEnableUploads'=>false])])->caller(__METHOD__)->execute();
                $db->commit(__METHOD__);
                $services->get('ManageWikiDataStoreFactory')->newInstance($wiki)->resetWikiData(true);
            }
            echo "Fixture created: $wiki\n";return;
        }
        $config=$services->getMainConfig();$wiki=$config->get('DBname');
        $global=$services->getDBLoadBalancerFactory()->getPrimaryDatabase('virtual-checkuser-global');
        $this->check($global->getDBname()==='wikidb','CheckUser global routing differs');
        global $wgDBpassword;
        $db=new PDO('mysql:host=database;dbname='.$wiki,'mediawiki',$wgDBpassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $schema=[];
        foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
            $create=$db->query('SHOW CREATE TABLE `'.str_replace('`','``',$table).'`')->fetch(PDO::FETCH_NUM)[1];
            $schema[$table]=hash('sha256',preg_replace('/ AUTO_INCREMENT=\d+/','',$create));
        }
        ksort($schema);
        $this->check(!isset($schema['cuci_user']),'Shared CheckUser table was created locally');
        $loaded=array_keys(MediaWiki\Registration\ExtensionRegistry::getInstance()->getAllThings());
        if(in_array('UrlShortener',$loaded,true)) {
            $this->check(isset($schema['urlshortcodes']),'Enabled UrlShortener schema is missing');
        }
        $keys=$db->query('SELECT ul_key FROM updatelog ORDER BY ul_key')->fetchAll(PDO::FETCH_COLUMN);
        $checkuser=null;
        if($this->hasOption('checkuser')){
            $user=$services->getUserFactory()->newFromName(getenv('ADMIN_USER')?:'Admin');
            // A fresh UI-created wiki may not have auto-attached the requester yet.
            $central=$services->getCentralIdLookup()->centralIdFromName($user->getName());
            $this->check((bool)$central,'Fixture requester has no central identity');
            $map=$services->get('CheckUserCentralIndexManager')->getWikiMapIdForDomainId($wiki);
            $job=$services->getJobFactory()->newJob('checkuserUpdateUserCentralIndexJob',['centralID'=>$central,'wikiMapID'=>$map,'timestamp'=>wfTimestampNow()]);
            $this->check($job->run(),'Index job failed');
            $services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
            $active=$services->get('CheckUserGlobalContributionsLookup')->getActiveWikis($user->getName(),$user);
            $this->check(in_array($wiki,$active,true),'Global lookup missed the new wiki');
            $checkuser='real index job and global lookup passed';
        }
        echo json_encode(['wiki'=>$wiki,'tables'=>$schema,'updatelog_keys'=>$keys,
            'extensions'=>$loaded,
            'language'=>$config->get('LanguageCode'),'uploads'=>$config->get('EnableUploads'),
            'anonymous_read'=>$config->get('GroupPermissions')['*']['read']??null,
            'checkuser_global_database'=>$global->getDBname(),'checkuser'=>$checkuser]),"\n";
    }
}
return ProvisioningAcceptance::class;
