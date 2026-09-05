<?php
// Invoke the real wrapper and removal authorization with isolated session/ACL doubles.
namespace Joomla\CMS\Form { class FormField { public $element, $value, $name, $id; } }
namespace {
require __DIR__ . '/bootstrap.php';
use Joomla\CMS\Factory;
$checks = 0; $failures = [];
function expect($ok, $message) { global $checks, $failures; ++$checks; if (!$ok) $failures[] = $message; }
set_error_handler(function($severity, $message, $file, $line) { if (error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line); return false; }, E_WARNING | E_NOTICE);
// These dependencies do no work in the methods under test; bootstrap owns the doubles.
foreach (['components/com_flexicontent/classes/flexicontent.helper.php', 'administrator/components/com_flexicontent/models/file.php', 'administrator/components/com_flexicontent/models/filemanager.php'] as $file) {
    $path = JPATH_SITE . '/' . $file;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, '<?php // Isolated dependency; test doubles are already loaded.');
}
class FlexicontentControllerBaseAdmin { public $input; }
require dirname(__DIR__, 3) . '/admin/models/fields/fcfieldwrapper.php';
require dirname(__DIR__, 3) . '/admin/controllers/filemanager.php';
class WrapperFixture extends JFormFieldFCFieldWrapper {
    public function __construct($itemId = 0) { $this->element = ['name'=>'fields','item_id'=>$itemId]; $this->value=[]; $this->name='fields'; $this->id='jform_fields'; }
    public function renderFieldsForm($item) { return '<span>Fixture fields</span>'; }
}
class RemovalFixture extends FlexicontentControllerFilemanager {
    public function __construct() { $this->input = new \Joomla\Input\Input(['task'=>'remove']); }
    public function canRemove($id, $user) { return $this->_canRemoveFolderModeFiles($id, $user); }
}
$bad = '_2026_09_05_01_02_03_1abcdef0123456';
$registry = 'com_flexicontent.edit.item.active_tmp_itemids';
$stateKey = 'com_flexicontent.edit.item.unique_tmp_itemid';
$removal = new RemovalFixture;
$app = Factory::$app; $user = Factory::$user;
$app->state[$stateKey] = $bad;
expect(!$removal->canRemove($bad, $user), 'A poisoned retry value alone cannot authorize removal');
$app->input = new \Joomla\Input\Input(['option'=>'com_flexicontent']);
$html = (new WrapperFixture)->getInput();
$issued = $app->input->getString('unique_tmp_itemid');
expect($issued !== $bad && flexicontent_security::isIssuedTemporaryItemId($app, $issued), 'Wrapper replaces an unissued retry ID with a newly issued ID');
expect(!isset($app->state[$registry][$bad]), 'Wrapper never registers the poisoned retry ID');
expect(strpos($html, 'value="' . $issued . '"') !== false && $app->state[$stateKey] === $issued, 'Wrapper renders and remembers the new ID');
expect(!$removal->canRemove($bad, $user), 'Wrapper rendering cannot make the poisoned ID removable');
expect($removal->canRemove($issued, $user), 'A genuine wrapper-issued ID can remove its own temporary files');
(new WrapperFixture)->getInput();
expect($app->input->getString('unique_tmp_itemid') === $issued, 'Multiple wrappers and form retries reuse their issued ID');
$app->state[$stateKey] = '118';
(new WrapperFixture)->getInput();
expect($app->input->getString('unique_tmp_itemid') !== '118', 'A numeric retry value is not treated as an existing item');
$expired = '_2026_09_05_01_02_03_2abcdef0123456';
$app->state[$registry][$expired] = time() - 86401; $app->state[$stateKey] = $expired;
expect(!$removal->canRemove($expired, $user), 'Expired retry IDs cannot authorize removal');
(new WrapperFixture)->getInput();
expect($app->input->getString('unique_tmp_itemid') !== $expired && !isset($app->state[$registry][$expired]), 'Wrapper replaces and prunes expired IDs');
$app->state[$registry]['../outside'] = time(); $app->state[$stateKey] = '../outside';
expect(!$removal->canRemove('../outside', $user), 'Even a malformed registry entry cannot authorize removal');
(new WrapperFixture)->getInput();
expect(flexicontent_security::isIssuedTemporaryItemId($app, $app->input->getString('unique_tmp_itemid')), 'Malformed retry values are replaced');
$ownApp = $app; Factory::$app = new TestApp;
expect(!$removal->canRemove($issued, $user), 'An ID issued to another session cannot authorize removal');
Factory::$app = $app = $ownApp;
$app->input = new \Joomla\Input\Input(['option'=>'com_content']);
$app->state['com_content.edit.item.unique_tmp_itemid'] = $bad;
$html = (new WrapperFixture)->getInput(); $embedded = $app->input->getString('unique_tmp_itemid');
expect($embedded !== $bad && flexicontent_security::isIssuedTemporaryItemId($app, $embedded, 'com_content'), 'An embedded wrapper rejects poisoned component state');
expect($removal->canRemove($embedded, $user), 'The FLEXIContent file manager recognizes IDs legitimately issued by an embedded wrapper');
(new WrapperFixture)->getInput();
expect($app->input->getString('unique_tmp_itemid') === $embedded, 'Embedded wrapper retries keep their issued ID');
$beforeRegistry = $app->state[$registry];
$html = (new WrapperFixture(10))->getInput();
expect($app->input->getString('unique_tmp_itemid') === '10' && strpos($html, 'value="10"') !== false, 'Saved item ID still comes from server-side form configuration');
expect($app->state[$registry] === $beforeRegistry && !isset($app->state[$registry]['10']), 'Saved item IDs are never registered as temporary IDs');
expect(!$removal->canRemove('10', $user), 'A saved item ID in retry state does not bypass item edit permission');
$user->guest = false; $user->id = 7; $user->permissions = ['core.edit.own:com_content.article.10'];
expect($removal->canRemove('10', $user), 'Owners with edit-own permission can remove their saved item files');
$user->id = 8;
expect(!$removal->canRemove('10', $user), 'Edit-own permission cannot remove another owner\'s files');
$user->permissions = ['core.edit:com_content.article.10'];
expect($removal->canRemove('10', $user), 'Item editors can remove saved item files');
$user->permissions = ['core.edit:com_content.article.999'];
expect(!$removal->canRemove('999', $user), 'A nonexistent item cannot authorize an orphan folder');
expect(!$removal->canRemove('', $user) && !$removal->canRemove('0', $user), 'Empty and zero IDs cannot authorize removal');
$app->state = [];
$app->input = new \Joomla\Input\Input(['option'=>'com_flexicontent']);
// Legacy server-issued IDs remain valid within the existing 24-hour lifetime.
$app->state[$stateKey] = $bad; $app->state[$registry][$bad] = time() - 10800;
(new WrapperFixture)->getInput();
expect($app->input->getString('unique_tmp_itemid') === $bad && $removal->canRemove($bad, $user), 'A previously issued ID remains compatible after three hours');
$generated = [];
for ($i = 0; $i < 25; ++$i) {
    $app->state[$stateKey] = false;
    (new WrapperFixture)->getInput();
    $generated[] = $app->input->getString('unique_tmp_itemid');
}
expect(count(array_unique($generated)) === 25 && preg_match('/_[a-f0-9]{32}$/D', end($generated)), 'Fresh forms receive distinct random IDs');
expect(count($app->state[$registry]) === 20 && !$removal->canRemove($generated[0], $user) && $removal->canRemove(end($generated), $user), 'The registry stays bounded and retains the newest issued ID');
restore_error_handler();
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Passed $checks wrapper and removal assertions\n";
}
