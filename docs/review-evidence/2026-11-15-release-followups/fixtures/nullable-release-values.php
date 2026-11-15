<?php
/** Exercise exact source expressions with omitted values and meaningful zero/string controls. */
error_reporting(E_ALL);
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$root=$argv[1]??dirname(__DIR__); $count=0;
function statement($file,$pattern){global $root;if(!preg_match($pattern,file_get_contents($root.'/'.$file),$m))throw new RuntimeException('Source statement missing: '.$file);return $m[0];}
$stateStmt=statement('admin/controllers/base/baseadmin.php','/\$state = strlen\([\s\S]+?;/');
$controller=new class {public $input;function __construct(){$this->input=new class{function get($k,$d,$t){return 'U';}};}function run($code,$state){eval($code);return $state;}};
foreach([[null,'U'],['','U'],[0,0],['0','0'],[-2,-2]]as[$value,$expected]){if($controller->run($stateStmt,$value)!==$expected)throw new RuntimeException('State changed');$count++;}
$inputController=new class {public $input;function __construct(){$this->input=new class{public $value;function get($k,$d,$t){return $this->value;}};}function run($code,$v){$this->input->value=$v;eval($code);return [$fname_level1,$fname_level2,$fname_level3];}};
$upload=statement('admin/controllers/filemanager.php','/\$fname_level1\s*=[\s\S]+?\$fname_level3\s*=[^;]+;/');
foreach([null,'',0,'file']as$v){if($inputController->run($upload,$v)!==array_fill(0,3,(string)$v))throw new RuntimeException('Upload level changed');$count++;}
$extStmt=statement('admin/controllers/import.php','/\$fext\s*=[^;]+;/');
foreach(['42'=>'','name.PDF'=>'PDF']as$name=>$expected){$path_parts=pathinfo((string)$name);eval($extStmt);if($fext!==$expected||strtolower($fext)!==strtolower($expected)||strtoupper($fext)!==strtoupper($expected))throw new RuntimeException('Import extension changed');$count++;}
$langStmt=statement('admin/models/parentclassitem.php','/\$is_content_default_lang\s*=[^;]+;/');
foreach([[null,false],['en-GB',true],['fr-FR',false]]as[$language,$expected]){$site_default='en';$item=(object)['language'=>$language];eval($langStmt);if($is_content_default_lang!==$expected)throw new RuntimeException('Language comparison changed');$count++;}
$decode=statement('site/classes/flexicontent.fields.php','/\$field->value\[0\]\s*=\s*html_entity_decode\([^;]+;/');
foreach([[null,''],['A &amp; B','A & B'],['0','0']]as[$value,$expected]){$field=(object)['value'=>[]];$t=(object)['fields'=>(object)['text'=>(object)['value'=>$value]]];eval($decode);if($field->value[0]!==$expected)throw new RuntimeException('Text decode changed');$count++;}
$cut=statement('site/classes/helpers/html.php','/\$v\s*=\s*\$maxlength\s*\?\s*substr\([^;]+;/');
foreach([[null,10,''],['abcdef',3,'abc'],['0',1,'0']]as[$v,$maxlength,$expected]){eval($cut);if($v!==$expected)throw new RuntimeException('Length limit changed');$count++;}
$keyStmt=statement('site/classes/helpers/html.php','/\$apikey\s*=\s*trim\([^;]+;/');
$params=new class{public $v;function get($k,$d=null){return $this->v;}};
foreach([[null,''],[' key ','key'],['0','0']]as[$value,$expected]){$params->v=$value;eval($keyStmt);if($apikey!==$expected)throw new RuntimeException('Map key changed');$count++;}
restore_error_handler();echo "$count nullable release value checks passed\n";
