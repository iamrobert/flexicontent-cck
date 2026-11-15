<?php
define('_JEXEC',1); define('JPATH_BASE','/scratch/site/administrator');
$_SERVER['HTTP_HOST']='127.0.0.1:8080'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/administrator/index.php';
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
$c=Joomla\CMS\Factory::getContainer();
$c->alias('session','session.web.administrator')->alias(Joomla\Session\SessionInterface::class,'session.web.administrator');
$app=$c->get(Joomla\CMS\Application\AdministratorApplication::class); Joomla\CMS\Factory::$application=$app;
$app->createExtensionNamespaceMap();
$db=$c->get(Joomla\Database\DatabaseInterface::class);
$id=$db->setQuery("SELECT id FROM #__users WHERE username='release-admin'")->loadResult();
$app->loadIdentity($c->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($id));
$installer=new Joomla\CMS\Installer\Installer();$installer->setDatabase($db);
echo 'UNINSTALLED='.(int)$installer->uninstall('component',(int)$argv[1])."\n";
