<?php
require __DIR__.'/bootstrap.php';
use Joomla\CMS\Factory;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
// Different fixture cases have independent rate buckets; only a loopback test server is used.
$_SERVER['REMOTE_ADDR']='fixture:'.($_GET['bucket'] ?? 'default');
try {
    if ($path==='/submit') {
        if (isset($_GET['member'])) {Factory::$user->guest=false;Factory::$user->id=7;}
        if (isset($_GET['disabled'])) Factory::$db->pdo->exec("UPDATE test_flexicontent_fields SET attribs='{}'");
        \plgFlexicontent_fieldsEmail::sendEmail();
        header('Content-Type: application/json');echo json_encode(Factory::$mailer);return;
    }
    if ($path==='/form') {
        list($item,$field)=flexicontent_security::loadItemField(10,20,'email');
        $values=[['addr'=>'owner@example.test','text'=>'Contact']];$prop='display';$is_ingroup=false;$usetitle=1;$default_title='Contact';$app=Factory::$app;$realview='item';$pretext=$posttext='';$multiple=false;
        include dirname(__DIR__,3).'/plugins/flexicontent_fields/email/tmpl/value_form.php';echo implode('',$field->display);return;
    }
    if($path==='/outputs') {
        $field=(object)['id'=>20,'name'=>'media','display'=>[]];$item=(object)['id'=>10];$prop='display';$is_ingroup=false;
        $display_duration=0;$display_title=$display_author=$display_description=1;$headinglevel=3;$display_edit_size_form=0;$width=640;$height=360;$autostart=0;$player_position=0;$privacy_embeed=1;$pretext=$posttext='';$multiple=true;
        $values=[['api_type'=>'','media_id'=>'','embed_url'=>'https://example.test/" onload="window.injected=1','duration'=>0],['api_type'=>'youtube','media_id'=>'abc123','duration'=>0,'title'=>'<img src=x onerror="window.injected=1">']];
        include dirname(__DIR__,3).'/plugins/flexicontent_fields/sharedmedia/tmpl/value_default.php';echo implode('',$field->display);
        (new class {public function make_absolute_url($v){return strpos($v,'/')===0?'https://example.test'.$v:$v;} public function cleanurl($v){return $v;} public function render(){
            $field=(object)['id'=>20,'name'=>'link','display'=>[],'parameters'=>new \Joomla\Registry\Registry(['use_direct_link'=>1])];$item=(object)['id'=>10];$prop='display';$is_ingroup=false;$display_hits=$playback_videos=$display_image=0;$usetitle=$usetext=$useclass=$useid=$usetarget=$useimage=1;$default_title=$default_text=$default_class=$default_id=$default_target=$default_image='';$add_rel_nofollow=1;$pretext=$posttext='';$multiple=true;
            $values=[['link'=>'javascript:window.injected=1'],['link'=>'/valid','linktext'=>'<img src=x onerror="window.injected=1">','class'=>'x" onclick="window.injected=1','id'=>'link" onmouseover="window.injected=1','target'=>'_self" onfocus="window.injected=1']];
            include dirname(__DIR__,3).'/plugins/flexicontent_fields/weblink/tmpl/value_default.php';echo implode('',$field->display);
        }})->render();return;
    }
    http_response_code(404);
} catch(Throwable $e) {http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():500);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage(),'sent'=>Factory::$mailer ? Factory::$mailer->sent : false]);}
