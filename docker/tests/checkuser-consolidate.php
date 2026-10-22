<?php
if (getenv('MW_JOBCHRON_TEST_ISOLATED') !== '1') { throw new RuntimeException('Disposable farm only'); }
require '/opt/farm/private.php';
$db=new PDO('mysql:host=database;dbname=wikidb','mediawiki',$wgDBpassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function check(bool $ok,string $message): void { if(!$ok){throw new RuntimeException($message);} }
foreach(['cumigrateawiki','cumigratebwiki'] as $wiki){
 $db->exec("CREATE DATABASE `$wiki`");
 foreach(['cuci_user','cuci_temp_edit','cuci_wiki_map'] as $table){$db->exec("CREATE TABLE `$wiki`.`$table` LIKE wikidb.`$table`");}
}
$db->exec("INSERT INTO wikidb.cuci_wiki_map VALUES (501,'cumigratebwiki'),(502,'cumigrateawiki')");
$db->exec("INSERT INTO cumigrateawiki.cuci_wiki_map VALUES (501,'cumigrateawiki')");
$db->exec("INSERT INTO cumigrateawiki.cuci_user VALUES (991,501,'20261001000000')");
$db->exec("INSERT INTO cumigratebwiki.cuci_user VALUES (992,501,'20261002000000')");
$db->exec("INSERT INTO cumigrateawiki.cuci_temp_edit VALUES ('C0000201',501,'20261003000000')");
$db->exec("INSERT INTO wikidb.cuci_user VALUES (991,502,'20261004000000')");
$before=(int)$db->query('SELECT COUNT(*) FROM wikidb.cuci_user')->fetchColumn();
$run=static function(array $args): array {
 $p=proc_open(['php','/opt/farm/checkuser-consolidate.php',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 return [proc_close($p),$out,$err];
};
[$exit]=$run([]);check($exit===0,'Dry run failed');
check((int)$db->query('SELECT COUNT(*) FROM wikidb.cuci_user')->fetchColumn()===$before,'Dry run wrote data');
$db->exec("INSERT INTO cumigratebwiki.cuci_user VALUES (993,65000,'20261002000000')");
[$exit]=$run(['--apply','--writers-stopped']);check($exit!==0,'Unknown map ID accepted');
check((int)$db->query('SELECT COUNT(*) FROM wikidb.cuci_user')->fetchColumn()===$before,'Failed plan partially wrote data');
$db->exec('DELETE FROM cumigratebwiki.cuci_user WHERE ciu_central_id=993');
for($n=0;$n<2;$n++){
 [$exit,$out,$err]=$run(['--apply','--writers-stopped']);check($exit===0,'Apply failed: '.$out.$err);
 check(json_decode($out,true)['verified_source_rows']===3,'Source verification count wrong');
 check($db->query('SELECT ciu_timestamp FROM wikidb.cuci_user WHERE ciu_central_id=991 AND ciu_ciwm_id=502')->fetchColumn()==='20261004000000','Newer target timestamp overwritten');
 check($db->query('SELECT ciu_timestamp FROM wikidb.cuci_user WHERE ciu_central_id=992 AND ciu_ciwm_id=501')->fetchColumn()==='20261002000000','Shared-ID orphan fallback failed');
 check($db->query("SELECT cite_timestamp FROM wikidb.cuci_temp_edit WHERE cite_ip_hex='C0000201' AND cite_ciwm_id=502")->fetchColumn()==='20261003000000','IP index or map remap failed');
 check((int)$db->query('SELECT COUNT(*) FROM cumigrateawiki.cuci_user')->fetchColumn()===1,'Source data removed');
}
echo "Consolidation passed: dry run, map collisions, recoverable orphan IDs, user/IP indexes, latest timestamp, unknown-ID refusal, source retention and idempotence.\n";
