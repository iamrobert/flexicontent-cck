<?php
define('_JEXEC', 1);
define('JPATH_BASE', '/scratch/site/administrator');
$_SERVER['HTTP_HOST']='127.0.0.1:8080';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['SCRIPT_NAME']='/administrator/index.php';
require JPATH_BASE.'/includes/defines.php';
require JPATH_BASE.'/includes/framework.php';
$container=Joomla\CMS\Factory::getContainer();
$container->alias('session','session.web.administrator')
    ->alias(Joomla\Session\SessionInterface::class,'session.web.administrator');
$app=$container->get(Joomla\CMS\Application\AdministratorApplication::class);
Joomla\CMS\Factory::$application=$app;
$app->createExtensionNamespaceMap();
$args=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$user=$container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserById((int)($args['user']??0));
$app->loadIdentity($user);
$db=$container->get(Joomla\Database\DatabaseInterface::class);
try {
    if($args['op']==='version') {
        require_once JPATH_ADMINISTRATOR.'/components/com_flexicontent/defineconstants.php';
        $result=['version'=>FLEXI_VERSION,'phpMinimum'=>FLEXI_PHP_NEEDED,'phpRecommended'=>FLEXI_PHP_RECOMMENDED];
    } elseif($args['op']==='cacheClean') {
        $cache=$container->get(Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('callback');$result=[];foreach(['com_modules','com_flexicontent_tmpl','page','_system','com_menus','com_categories','com_content','com_plugins','com_flexicontent_cats','com_flexicontent_items','com_flexicontent']as $group)$result[$group]=$cache->clean($group);
    } elseif($args['op']==='findUpdates') {
        $changed=Joomla\CMS\Updater\Updater::getInstance()->findUpdates((int)$args['extension'],0,Joomla\CMS\Updater\Updater::STABILITY_STABLE);
        $result=['changed'=>$changed];
    } elseif($args['op']==='menu') {
        $table=new Joomla\CMS\Table\Menu($db);$table->setLocation(1,'last-child');
        $table->bind(['title'=>$args['title']??'Gate catalog','alias'=>$args['alias']??'gate-catalog','menutype'=>'mainmenu','type'=>'component','link'=>'index.php?option=com_flexicontent&view=category&cid='.(int)$args['category'],'component_id'=>(int)$args['component'],'published'=>1,'access'=>1,'language'=>'*','params'=>'{}','client_id'=>0]);
        if(!$table->check()||!$table->store())throw new RuntimeException($table->getError());$table->rebuildPath($table->id);$result=['id'=>$table->id];
    } elseif($args['op']==='category') {
        $table=new Joomla\CMS\Table\Category($db);
        $table->setLocation(1,'last-child');
        $table->bind(['title'=>$args['title'],'alias'=>$args['alias'],'extension'=>'com_content',
          'description'=>'','params'=>'{}','metadata'=>'{}','language'=>'*','published'=>1,'access'=>1]);
        if(!$table->check()||!$table->store())throw new RuntimeException($table->getError());
        $table->rebuildPath($table->id);
        $result=['id'=>$table->id];
    } elseif($args['op']==='assoc') {
        require_once JPATH_ADMINISTRATOR.'/components/com_flexicontent/defineconstants.php';
        require_once JPATH_ROOT.'/components/com_flexicontent/classes/helpers/db.php';
        $result=['user'=>$user->id,'targetEditable'=>flexicontent_db::canEditAssocItem((int)$args['target'],$user),
          'flags'=>flexicontent_db::getCategoriesUntranslatable(),'updated'=>flexicontent_db::propagateCategoriesToAssocs((int)$args['source'])];
    } else throw new RuntimeException('Unknown operation');
    echo json_encode($result,JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $e){echo json_encode(['error'=>get_class($e).': '.$e->getMessage()]),"\n";exit(1);}
