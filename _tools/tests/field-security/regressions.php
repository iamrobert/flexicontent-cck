<?php
require __DIR__.'/bootstrap.php';
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;
$checks=0;
function expect($ok,$message){global $checks;++$checks;if(!$ok)throw new RuntimeException($message);}
set_error_handler(function($severity,$message,$file,$line){if(error_reporting() & $severity)throw new ErrorException($message,0,$severity,$file,$line);return false;},E_WARNING|E_NOTICE);
require dirname(__DIR__,3).'/site/classes/flexicontent.fields.php';
$item=(object)['id'=>10,'language'=>'en-GB'];$field=(object)['field_type'=>'IndexFixture','iscore'=>0,'issearch'=>1,'item_id'=>10];$values=['First value','Second value'];
FlexicontentFields::createIndexRecords($field,$values,$item,[],[],' ',null);
expect($field->search[10]==='First value | Second value','Missing search property must be initialized without warnings');
$item->id=11;$field->item_id=11;$values=['Next item'];FlexicontentFields::createIndexRecords($field,$values,$item,[],[],' ',null);
expect($field->search[10]==='First value | Second value' && $field->search[11]==='Next item','Indexing retains other item entries');
$field->search='legacy';FlexicontentFields::createIndexRecords($field,$values,$item,[],[],' ',null);expect($field->search[11]==='Next item','Legacy non-array search value is initialized');
$code="// harmless comment\r\nreturn \$value;\r\n";$old=['format_output'=>-1,'output_custom_func'=>$code];$new=['format_output'=>-1,'output_custom_func'=>str_replace("\r\n","\n",$code)];
flexicontent_security::assertTrustedConfigurationChange($old,$new,Factory::$user);expect(true,'Whitespace line-ending conversion is allowed');
foreach([['return 1;','return 2;'],["return \"a\r\nb\";","return \"a\nb\";"],["return <<<'END'\na\r\nb\nEND;","return <<<'END'\na\nb\nEND;"]] as [$before,$after]){$caught=false;try{flexicontent_security::assertTrustedConfigurationChange(['format_output'=>-1,'output_custom_func'=>$before],['format_output'=>-1,'output_custom_func'=>$after],Factory::$user);}catch(RuntimeException $e){$caught=$e->getCode()===403;}expect($caught,'Code and string-literal changes still need a Super User');}
$id='_2026_09_05_01_02_03_1abcdef0123456';$app=Factory::$app;
$app->state['com_flexicontent.edit.item.unique_tmp_itemid']=$id;
expect(!flexicontent_security::isIssuedTemporaryItemId($app,$id),'A retry value is not proof of issuance');
$app->state['com_wrapper.edit.item.active_tmp_itemids']=[$id=>time()];
expect(flexicontent_security::isIssuedTemporaryItemId($app,$id,'com_wrapper'),'Wrapper uses its own issuing namespace');
expect(!flexicontent_security::isIssuedTemporaryItemId($app,$id),'An unrelated namespace does not authorize a temporary id');
$app->state['com_wrapper.edit.item.active_tmp_itemids'][$id]=time()-86401;expect(!flexicontent_security::isIssuedTemporaryItemId($app,$id,'com_wrapper'),'Expired temporary id is rejected');
require dirname(__DIR__,3).'/plugins/flexicontent_fields/weblink/weblink.php';plgFlexicontent_fieldsWeblink::$field_types=['weblink'];$plugin=(new ReflectionClass('plgFlexicontent_fieldsWeblink'))->newInstanceWithoutConstructor();$field=(object)['field_type'=>'weblink','parameters'=>new Registry(['use_hits'=>0,'use_text'=>1])];$item=(object)['id'=>10];$file=[];
foreach(['https://example.test/my file.pdf','javascript:alert(1)','https://example.test/"bad'] as $url){$post=[['link'=>$url,'linktext'=>'Keep this label']];$before=$post;$result=$plugin->onBeforeSaveField($field,$post,$file,$item);expect($result===false && $post===$before,'Invalid URL returns validation failure with entered data retained');}
$post=[['link'=>'https://example.test/my%20file.pdf','linktext'=>'Valid encoded space']];expect($plugin->onBeforeSaveField($field,$post,$file,$item)!==false && flexicontent_security::unserialize($post[0])['link']==='https://example.test/my%20file.pdf','Percent-encoded URL saves normally');
$post=[['link'=>'']];expect($plugin->onBeforeSaveField($field,$post,$file,$item)!==false && $post===[],'Clearing a web link remains allowed');
require dirname(__DIR__,3).'/plugins/flexicontent_fields/image/image.php';plgFlexicontent_fieldsImage::$field_types=['image'];$image=(new ReflectionClass('plgFlexicontent_fieldsImage'))->newInstanceWithoutConstructor();$field=(object)['id'=>99,'label'=>'Test image','field_type'=>'image','parameters'=>new Registry(['image_source'=>1,'dir'=>'new-image-base','use_desc'=>0])];$item=(object)['id'=>101];$post=[];
$app->input=new Joomla\Input\Input(['option'=>'com_wrapper','unique_tmp_itemid'=>$id]);$before=$post;expect($image->onBeforeSaveField($field,$post,$file,$item)===false && $post===$before,'Expired image id returns through normal validation');expect(!is_dir(JPATH_SITE.'/new-image-base'),'Rejected id creates no directories');
$app->state['com_wrapper.edit.item.active_tmp_itemids'][$id]=time();expect($image->onBeforeSaveField($field,$post,$file,$item)!==false && is_dir(JPATH_SITE.'/new-image-base'),'New empty image field creates its configured base safely');
$source=JPATH_SITE.'/new-image-base/item_'.$id.'_field_99';mkdir($source);file_put_contents($source.'/marker.txt','own session');$item->id=102;$post=[];expect($image->onBeforeSaveField($field,$post,$file,$item)!==false && file_get_contents(JPATH_SITE.'/new-image-base/item_102_field_99/marker.txt')==='own session' && !is_dir($source),'Issued wrapper folder moves to the new item');

$source=JPATH_SITE.'/new-image-base/item_10_field_99';mkdir($source);file_put_contents($source.'/copy.txt','source item');$app->input=new Joomla\Input\Input(['option'=>'com_flexicontent','unique_tmp_itemid'=>'10']);$item->id=103;$post=[];
expect($image->onBeforeSaveField($field,$post,$file,$item)===false && !is_dir(JPATH_SITE.'/new-image-base/item_103_field_99'),'Copying another item requires edit permission');
Factory::$user->guest=false;Factory::$user->id=7;Factory::$user->permissions=['core.edit.own:com_content.article.10'];
expect($image->onBeforeSaveField($field,$post,$file,$item)!==false && file_get_contents(JPATH_SITE.'/new-image-base/item_103_field_99/copy.txt')==='source item' && is_file($source.'/copy.txt'),'An authorized source folder is copied without removing its files');
Factory::$user->permissions=['core.edit:com_content.article.999'];$app->input->set('unique_tmp_itemid','999');$item->id=104;
expect($image->onBeforeSaveField($field,$post,$file,$item)===false,'An absent source item cannot authorize an orphan folder');
restore_error_handler();echo "Passed $checks regression assertions for indexing, URLs, PHP settings and temporary images\n";
