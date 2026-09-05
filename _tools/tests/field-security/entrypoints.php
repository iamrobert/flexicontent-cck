<?php
// A fresh process: intentionally do not load bootstrap.php or the database helper.
namespace Joomla\CMS { class Factory { public static function getConfig(){return new \Joomla\Registry\Registry(['error_reporting'=>'default']);} } }
namespace Joomla\CMS\MVC\Controller { class BaseController {} }
namespace Joomla\CMS\Session { class Session { public static function checkToken($method){return false;} } }
namespace {
 if(PHP_SAPI!=='cli')exit(1);
 define('_JEXEC',1);define('DS',DIRECTORY_SEPARATOR);define('JPATH_SITE',getenv('FLEXI_TEST_SITE'));define('JPATH_ROOT',JPATH_SITE);define('JPATH_BASE',JPATH_SITE);define('JPATH_ADMINISTRATOR',JPATH_SITE.'/administrator');
 require getenv('JOOMLA_ROOT').'/libraries/vendor/autoload.php';
 function jimport($name){}
 class FCField {}
 class JLoader {public static $map=[];public static function register($class,$file){self::$map[$class]=$file;}}
 spl_autoload_register(function($class){if(isset(JLoader::$map[$class]))require JLoader::$map[$class];});
 $root=dirname(__DIR__,3);$kind=$argv[1];
 if(class_exists('flexicontent_security',false)||class_exists('flexicontent_db',false))throw new \RuntimeException('Fixture preloaded a helper');
 $files=['controller'=>'site/controller.php','file'=>'plugins/flexicontent_fields/file/file.php','mediafile'=>'plugins/flexicontent_fields/mediafile/mediafile.php','helper'=>'site/classes/flexicontent.helper.php'];
 require $root.'/'.$files[$kind];
 if(!class_exists('flexicontent_security'))throw new \RuntimeException('Security helper is unavailable');
 if(class_exists('flexicontent_db',false))throw new \RuntimeException('Entry point depends on database-helper side effects');
 if(in_array($kind,['file','mediafile'],true)){
  $class='plgFlexicontent_fields'.$kind;$plugin=(new \ReflectionClass($class))->newInstanceWithoutConstructor();
  foreach(['GET'=>405,'POST'=>403] as $method=>$code){$_SERVER['REQUEST_METHOD']=$method;$caught=false;try{$plugin->share_file_email();}catch(\RuntimeException $e){if($e->getCode()!==$code)throw $e;$caught=true;}if(!$caught)throw new \RuntimeException('Sharing request was not rejected');}
 }
 echo 'Passed fresh entry point: '.$kind.PHP_EOL;
}
