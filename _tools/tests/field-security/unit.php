<?php
require __DIR__ . '/bootstrap.php';
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;
$checks=0;
function check($value,$message) {global $checks;++$checks;if(!$value)throw new RuntimeException($message);}
function denied($fn,$code=403) {try {$fn();}catch(RuntimeException $e){check($e->getCode()===$code,'Unexpected error: '.$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
class WakeProbe {public static $calls=0; public function __wakeup(){++self::$calls;}}
foreach ([[],['image'=>'photo.jpg','title'=>'Example'],['nested'=>[1,true,false,null,3.2]],"binary\0text",false,true,0,42,-5,2.5,null,'O:4:"Name":0:{}'] as $value)
    check(flexicontent_security::unserialize(serialize($value))===$value,'Scalar/array compatibility');
check(flexicontent_security::unserialize(serialize(['object'=>new WakeProbe]))===false && WakeProbe::$calls===0,'No object wakeup');
$autoloads=0;spl_autoload_register(function($class)use(&$autoloads){if($class==='MissingEnum')++$autoloads;});
check(flexicontent_security::unserialize('E:15:"MissingEnum:One";')===false && $autoloads===0,'Enum must not autoload');
foreach (['a:1:{i:0;O:4:"Name":0:{}}','C:4:"Name":0:{}','a:9999999999:{}','s:3:"ab";','a:0:{}tail','a:1:{i:0;R:1;}'] as $bad)
    check(flexicontent_security::unserialize($bad)===false,'Reject malformed/object/recursive data');
$graph=[1];for($i=0;$i<20;++$i){$next=[&$graph,&$graph];unset($graph);$graph=$next;unset($next);}check(flexicontent_security::unserialize(serialize($graph))===false,'Bound work for shared-reference graphs');
$deep='leaf';for($i=0;$i<66;++$i)$deep=[$deep];check(flexicontent_security::unserialize(serialize($deep))===false,'Depth limit');
foreach(['0',0,1,'plain','{"json":true}'] as $value)check(flexicontent_db::unserialize_array($value)===$value,'Legacy scalar fallback');
check(flexicontent_db::unserialize_array('plain',true)===['plain'],'Force array compatibility');
$object=(object)['value'=>'one','text'=>'One'];check(flexicontent_db::unserialize($object)===false && $object->text==='One','Existing objects are not decoded or modified');
foreach(['javascript:alert(1)','JaVaScRiPt:alert(1)','java&#x09;script:alert(1)','data:text/html,hello','https://example.test/" onload="bad','\\evil.test','https://[bad'] as $url)check(flexicontent_security::safeUrl($url)==='','Reject browser-active or malformed URL');
foreach(['https://example.test/path?q=1&x=2','/images/photo.png','//www.youtube.com/embed/abc'] as $url)check(flexicontent_security::safeUrl($url)===$url,'Normal URL compatibility');
$token=str_repeat('a',64);$coupon=(object)['token'=>$token,'file_id'=>7,'has_expired'=>false,'has_reached_limit'=>false];
check((bool)flexicontent_security::couponMatchesFile($coupon,7),'Own coupon file');
check(!flexicontent_security::couponMatchesFile($coupon,8),'Coupon cannot access another file');
$coupon->token='old-weak-token';check(!flexicontent_security::couponMatchesFile($coupon,7),'Weak coupon invalidated');
$coupon->token=$token;$coupon->has_expired=true;check(!flexicontent_security::couponMatchesFile($coupon,7),'Expired coupon rejected');
check(flexicontent_security::isContainedPath('/root/images/item_1','/root/images'),'Contained image path');
check(!flexicontent_security::isContainedPath('/root/images-other/item_1','/root/images'),'Sibling prefix is not containment');
$old=['format_output'=>-1,'output_custom_func'=>'return $value;'];
flexicontent_security::assertTrustedConfigurationChange($old,$old+['label'=>'changed'],Factory::$user);check(true,'Ordinary configuration edits preserve existing code');
denied(fn()=>flexicontent_security::assertTrustedConfigurationChange($old,['format_output'=>-1,'output_custom_func'=>'return 9;'],Factory::$user));
denied(fn()=>flexicontent_security::assertTrustedConfigurationChange([],['auto_title'=>2,'auto_title_code'=>'return 9;'],Factory::$user));
Factory::$user->permissions=['core.admin:'];flexicontent_security::assertTrustedConfigurationChange([],$old,Factory::$user);check(true,'Super User may configure PHP');
resetFixture(); list($item,$field)=flexicontent_security::loadItemField(10,20,'email');check($item->title==='Server title','Public context resolves');
foreach([[11,20,'email'],[10,21,'email'],[10,20,'file']] as $args)denied(fn()=>flexicontent_security::loadItemField(...$args));
foreach([
 'UPDATE test_content SET state=0 WHERE id=10',
 "UPDATE test_content SET publish_up='2999-01-01 00:00:00' WHERE id=10",
 "UPDATE test_content SET publish_down='2000-01-01 00:00:00' WHERE id=10",
 'UPDATE test_flexicontent_fields SET published=0',
 'UPDATE test_flexicontent_fields SET access=2',
 'UPDATE test_flexicontent_types SET access=2',
 'UPDATE test_categories SET published=0 WHERE id=2',
 'INSERT INTO test_categories VALUES(3,0,9,2,1)',
 'DELETE FROM test_flexicontent_fields_type_relations'
] as $sql){resetFixture();Factory::$db->pdo->exec($sql);denied(fn()=>flexicontent_security::loadItemField(10,20,'email'));}
resetFixture();list($item,$field)=flexicontent_security::loadItemField(10,20,'email');$schema=flexicontent_email_security::schema($field->parameters);
$data=['name'=>'Visitor','emailfrom'=>'visitor@example.test','message'=>'Hello','choice'=>'one','topics'=>['one','two']];
$clean=flexicontent_email_security::validateData($data,$schema);check($clean['topics']==='one, two','Valid checkbox data');
foreach(['name'=>[],'emailfrom'=>'bad','message'=>'','choice'=>'forged','topics'=>[['nested']],'extra'=>'forged'] as $key=>$value){$bad=$data;$bad[$key]=$value;denied(fn()=>flexicontent_email_security::validateData($bad,$schema),400);}
$key=flexicontent_email_security::recipientKey(10,20,'owner@example.test','fixture-secret');
check(flexicontent_email_security::recipient(10,$field,[serialize(['addr'=>'owner@example.test'])],$key,'fixture-secret')==='owner@example.test','Persisted recipient');
denied(fn()=>flexicontent_email_security::recipient(11,$field,[serialize(['addr'=>'owner@example.test'])],$key,'fixture-secret'));
denied(fn()=>flexicontent_email_security::recipient(10,$field,[serialize(['addr'=>'changed@example.test'])],$key,'fixture-secret'));
check(flexicontent_email_security::attachmentTypeAllowed('file.txt','text/plain','.txt'),'Allowed attachment');
foreach([['file.php','text/plain',''],['file.png','text/plain','.png'],['file.svg','image/svg+xml','image/*'],['file.exe','application/octet-stream',''],['file.pdf','application/pdf','.png']] as $args)check(!flexicontent_email_security::attachmentTypeAllowed(...$args),'Attachment policy');
denied(fn()=>flexicontent_email_security::attachments(['attachment'=>[['name'=>'file.txt','error'=>0,'tmp_name'=>__FILE__]]],$schema),400);
$_SERVER['REQUEST_METHOD']='GET';$_POST['test_token']='valid';denied(fn()=>flexicontent_security::requirePostToken(),405);
$_SERVER['REQUEST_METHOD']='POST';$_POST['test_token']='bad';denied(fn()=>flexicontent_security::requirePostToken());
$_POST['test_token']='valid';flexicontent_security::requirePostToken();check(true,'POST CSRF accepted');
$_SERVER['REMOTE_ADDR']='unit';$scope='unit-'.bin2hex(random_bytes(8));for($i=0;$i<5;++$i)flexicontent_security::consumeMailQuota($scope);
denied(fn()=>flexicontent_security::consumeMailQuota($scope),429);
echo "Passed $checks assertions\n";
