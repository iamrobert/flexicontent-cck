<?php
// Test doubles only: no real Joomla application, database, users, or mail transport.
namespace Joomla\CMS {
    class Factory {
        public static $app, $db, $user, $mailer;
        public static function getApplication() { return self::$app; }
        public static function getDbo() { return self::$db; }
        public static function getUser() { return self::$user; }
        public static function getDocument() { return self::$app->getDocument(); }
        public static function getContainer() { return new class { public function get($id) { return $this; } public function createMailer() { return Factory::$mailer = new \TestMailer(); } }; }
    }
}
namespace Joomla\CMS\Session { class Session { public static function checkToken($method = 'post') { return ($_POST['test_token'] ?? '') === 'valid'; } } }
namespace Joomla\CMS\Captcha { class Captcha { public static function getInstance($plugin) { return new self; } public function checkAnswer($code) { return $code === 'passed'; } public function display($name, $id, $class) { return '<input name="captcha" value="passed">'; } } }
namespace Joomla\CMS\Plugin { class PluginHelper { public static function getPlugin($group,$name) { return (object)['params'=>'{"email_user_copy":1,"email_admin_copy":0}']; } } }
namespace Joomla\CMS\Language { class Text { public static function _($text, ...$args) { return $text; } public static function sprintf($key,...$args) { return $key . ' ' . implode(' ', $args); } } }
namespace Joomla\CMS\Router { class Route { public static function _($url,...$args) { return '/' . ltrim($url,'/'); } } }
namespace Joomla\CMS\Uri { class Uri { public static function root(...$args) { return 'https://example.test/'; } public static function isInternal($url) { return strpos($url,'https://example.test/') === 0; } } }
namespace Joomla\CMS\String { class PunycodeHelper { public static function emailToUTF8($v) {return $v;} public static function urlToUTF8($v) {return $v;} } }
namespace Joomla\CMS\HTML { class HTMLHelper { public static function _($name,...$args) {return $name === 'form.token' ? '<input name="test_token" value="valid" type="hidden">' : ''; } } }
namespace {
    if (!in_array(PHP_SAPI, ['cli','cli-server'], true)) { http_response_code(404); exit; }
    define('_JEXEC', 1); define('FLEXI_J40GE', true);
    define('JPATH_SITE', getenv('FLEXI_TEST_SITE')); define('JPATH_ROOT', JPATH_SITE); define('JPATH_ADMINISTRATOR', JPATH_SITE . '/administrator');
    require getenv('JOOMLA_ROOT') . '/libraries/vendor/autoload.php';
    require dirname(__DIR__, 3) . '/site/classes/helpers/db.php';
    class JLoader { public static function register(...$args) {} }
    class FCField {}
    class FlexicontentHelperRoute { public static function getItemRoute($id,$cat) { return 'index.php?option=com_flexicontent&id=' . $id; } }
    class TestUser {
        public $guest = true, $block = 0, $id = 0, $email = 'verified@example.test';
        public $permissions = [];
        public function getAuthorisedViewLevels() { return [1]; }
        public function authorise($action,$asset = null) { return in_array($action . ':' . $asset,$this->permissions,true); }
    }
    class TestMailer {
        public $recipients=[], $bcc=[], $attachments=[], $sender, $reply, $subject, $body, $sent=false;
        public function isHTML($v) {}
        public function setSender($v) {$this->sender=$v;}
        public function addReplyTo($email,$name) {$this->reply=[$email,$name];}
        public function addRecipient($v) {$this->recipients[]=$v;}
        public function addBCC($v) {$this->bcc[]=$v;}
        public function setSubject($v) {$this->subject=$v;}
        public function setBody($v) {$this->body=$v;}
        public function addAttachment($path,$name) {$this->attachments[]=['name'=>$name,'bytes'=>filesize($path)];}
        public function Send() {$this->sent=true;return true;}
    }
    class TestApp {
        public $input, $messages=[];
        public function __construct() {$this->input=new \Joomla\Input\Input();}
        public function get($key,$default=null) {return ['secret'=>'fixture-secret','captcha'=>'fixture','sitename'=>'Test site','mailfrom'=>'site@example.test','fromname'=>'Test sender','tmp_path'=>getenv('FLEXI_TEST_SITE')][$key] ?? $default;}
        public function getCfg($key,$default=null) {return $this->get($key,$default);}
        public function getLanguage() {return new class { public function load(...$args) {} };}
        public function getDocument() {return new class { public function addStyleSheet(...$args) {} };}
        public function enqueueMessage($text,$type) {$this->messages[]=[$text,$type];}
        public function isClient($type) {return $type==='site';}
    }
    class TestDb {
        public $pdo, $statement;
        public function __construct() {
            $this->pdo=new \PDO('sqlite::memory:'); $this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE test_content (id INTEGER,catid INTEGER,state INTEGER,access INTEGER,created_by INTEGER,publish_up TEXT,publish_down TEXT,title TEXT,alias TEXT); CREATE TABLE test_flexicontent_items_ext (item_id INTEGER,type_id INTEGER); CREATE TABLE test_flexicontent_types (id INTEGER,access INTEGER,published INTEGER); CREATE TABLE test_categories (id INTEGER,lft INTEGER,rgt INTEGER,access INTEGER,published INTEGER); CREATE TABLE test_flexicontent_fields (id INTEGER,field_type TEXT,access INTEGER,published INTEGER,attribs TEXT); CREATE TABLE test_flexicontent_fields_type_relations (field_id INTEGER,type_id INTEGER); CREATE TABLE test_flexicontent_fields_item_relations (item_id INTEGER,field_id INTEGER,valueorder INTEGER,value TEXT)');
            $this->pdo->exec("INSERT INTO test_content VALUES (10,2,1,1,7,NULL,NULL,'Server title','server-title'),(11,2,1,2,8,NULL,NULL,'Private','private'); INSERT INTO test_flexicontent_items_ext VALUES (10,1),(11,1); INSERT INTO test_flexicontent_types VALUES (1,1,1); INSERT INTO test_categories VALUES (1,0,9,1,1),(2,1,8,1,1)");
            $fields=[]; foreach(['name'=>'text','emailfrom'=>'email','message'=>'textarea','choice'=>'radio','topics'=>'checkbox','attachment'=>'file'] as $name=>$type)
                $fields[]=(object)['field_name'=>$name,'field_label'=>ucfirst($name),'field_type'=>$type,'field_required'=>in_array($name,['name','emailfrom','message']) ? 1 : 0,'field_value'=>$name==='attachment'?'.txt,.pdf,.png;;multiple;;5':'one;;two'];
            $params=json_encode(['viewlayout'=>'form','viewlayout_display_captcha'=>1,'viewlayout_use_modal'=>0,'viewlayout_form_fields'=>$fields]);
            $this->pdo->prepare('INSERT INTO test_flexicontent_fields VALUES (20,\'email\',1,1,?)')->execute([$params]);
            $this->pdo->exec('INSERT INTO test_flexicontent_fields_type_relations VALUES (20,1)');
            $this->pdo->prepare('INSERT INTO test_flexicontent_fields_item_relations VALUES (10,20,1,?)')->execute([serialize(['addr'=>'owner@example.test','text'=>'Contact owner'])]);
        }
        public function setQuery($sql,...$args) {$this->statement=$this->pdo->query(str_replace('#__','test_',$sql));return $this;}
        public function quote($v) {return $this->pdo->quote($v);}
        public function loadObject() {return $this->statement->fetchObject() ?: null;}
        public function loadColumn() {return $this->statement->fetchAll(\PDO::FETCH_COLUMN);}
        public function loadResult() {return $this->statement->fetchColumn();}
    }
    function resetFixture() {\Joomla\CMS\Factory::$app=new TestApp;\Joomla\CMS\Factory::$db=new TestDb;\Joomla\CMS\Factory::$user=new TestUser;\Joomla\CMS\Factory::$mailer=null;}
    resetFixture();
    require dirname(__DIR__,3) . '/plugins/flexicontent_fields/email/email.php';
}
