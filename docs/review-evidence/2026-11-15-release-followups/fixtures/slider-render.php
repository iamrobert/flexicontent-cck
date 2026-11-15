<?php
namespace Joomla\CMS\Component { class ComponentHelper {static function getParams($x){return new \Params;}} }
namespace Joomla\CMS\Language { class Text {static function _($x){return $x;}} }
namespace Joomla\CMS { class Factory {static function getDocument(){return $GLOBALS['doc'];}} }
namespace {
error_reporting(E_ALL);
set_error_handler(static function($s,$m,$f,$l){if(error_reporting()&$s)throw new \ErrorException($m,0,$s,$f,$l);});
class Params {function __construct(public array $p=[]){} function get($k,$v=null){return $this->p[$k]??$v;}}
class flexicontent_html {static function loadFramework($name){}}
$doc=new class {public string $script=''; function addScriptDeclaration($js){$this->script.=$js;}};
$mode=(int)$argv[2]; $custom=(int)$argv[3];
$label=$custom ? '<b>Configured</b>' : '<img src=x onerror=window.__xss=1>';
$filter=(object)['field_type'=>'text','html'=>'','label'=>'Fixture','parameters'=>new Params([
 'display_filter_as'=>$mode,'slider_display_config'=>$custom?2:1,
 'slider_custom_range'=>"'min': 0, '50%': 1, 'max': 2",'slider_custom_labels'=>'Any ## '.$label.' ## Last'])];
$displayData=['filter'=>$filter,'isSearchView'=>false,'value'=>$mode===7?'':[],
 'results'=>[(object)['value'=>'one','text'=>$label],(object)['value'=>'two','text'=>'A & B']],
 'filter_ffid'=>'filter_99','filter_ffname'=>'filter_99'];
require $argv[1];
echo json_encode(['html'=>$filter->html,'js'=>$doc->script],JSON_THROW_ON_ERROR);
}
