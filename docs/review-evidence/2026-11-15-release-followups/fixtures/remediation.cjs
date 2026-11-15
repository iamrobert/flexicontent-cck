'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
module.exports.run=async c=>{
 const {page,context,browser,check,observations,BASE,SITE,PREFIX:P,sql,q}=c;
 const before=c.S+'/before';fs.mkdirSync(before);c.sh('tar',['-xf',c.IN+'/before.tar','-C',before]);
 const paths={raw:'admin/controllers/search.raw.php',file:'admin/views/file/tmpl/default.php',user:'admin/views/user/view.html.php',userform:'admin/views/user/tmpl/form.php',debuguser:'admin/views/debuguser/tmpl/default.php',debuggroup:'admin/views/debuggroup/tmpl/default.php',uninstall:'script.php'};
 function installed(src){return src.startsWith('admin/')?SITE+'/administrator/components/com_flexicontent/'+src.slice(6):src==='script.php'?SITE+'/administrator/components/com_flexicontent/script.php':SITE+'/components/com_flexicontent/'+src.slice(5);}
 async function rollback(keys,fn){const saved={};for(const key of keys){const src=paths[key],dest=installed(src);saved[dest]=fs.readFileSync(dest);fs.writeFileSync(dest,fs.readFileSync(before+'/'+src));}try{return await fn();}finally{for(const[dest,data]of Object.entries(saved))if(fs.existsSync(require('node:path').dirname(dest)))fs.writeFileSync(dest,data);}}
 const root=SITE+'/administrator/index.php?option=com_flexicontent';
 const url=BASE+'/administrator/index.php?option=com_flexicontent';
 const adminId=Number(sql(`SELECT id FROM ${P}users WHERE username='release-admin'`)[0][0]);
 c.cli(['user:add','--username=remediation-manager','--name=Remediation Manager','--password=Fixture-Manager-2026','--email=manager@example.invalid','--usergroup=Manager','-n']);
 const managerId=Number(sql(`SELECT id FROM ${P}users WHERE username='remediation-manager'`)[0][0]);
 const rules=JSON.parse(sql(`SELECT rules FROM ${P}assets WHERE name='com_flexicontent'`)[0][0]);rules['core.manage']={...rules['core.manage'],'6':1};sql(`UPDATE ${P}assets SET rules=${q(JSON.stringify(rules))} WHERE name='com_flexicontent'`);
 const manager=await browser.newContext({reducedMotion:'reduce'}),mp=await manager.newPage();
 await manager.route('**/*',r=>new URL(r.request().url()).origin===BASE?r.continue():r.abort());
 await c.adminLogin(mp,{username:'remediation-manager',password:'Fixture-Manager-2026'});
 async function token(p){await p.goto(url+'&view=items');return p.locator('input[type=hidden][value="1"]').evaluateAll(xs=>xs.map(x=>x.name).find(n=>/^[a-f0-9]{32}$/.test(n)));}
 const adminToken=await token(page),managerToken=await token(mp);assert(adminToken&&managerToken,'Session tokens');
 const seed=()=>{sql(`DELETE FROM ${P}flexicontent_advsearch_index; INSERT INTO ${P}flexicontent_advsearch_index (field_id,item_id,search_index) VALUES(1,900001,'purge-sentinel')`);};
 const count=()=>Number(sql(`SELECT COUNT(*) FROM ${P}flexicontent_advsearch_index`)[0][0]);
 for(const original of [true,false])await check((original?'Negative control: ':'Fixed: ')+'raw purge authorization, verb and token',async()=>{
  const run=async()=>{const rows=[];for(const who of ['admin','manager'])for(const verb of ['GET','POST'])for(const tok of ['none','query','body']){
   if(verb==='GET'&&tok==='body')continue;seed();const ctx=who==='admin'?context:manager,t=who==='admin'?adminToken:managerToken;
   const endpoint=url+'&task=search.purge&format=raw'+(tok==='query'?'&'+t+'=1':'');
   const r=await ctx.request.fetch(endpoint,{method:verb,maxRedirects:0,...(verb==='POST'?{form:tok==='body'?{[t]:'1'}:{}}:{})});
   const n=count(),allowed=who==='admin'&&verb==='POST'&&tok==='body';
   assert.equal(n,original?0:allowed?0:1,`${who} ${verb} ${tok} status ${r.status()}`);
   if(!original&&!allowed)assert([403,405].includes(r.status()),`Denied status ${r.status()}`);
   rows.push({who,verb,tok,status:r.status(),remaining:n});}
   return rows;};return original?rollback(['raw'],run):run();
 });
 await check('Normal HTML purge denies Manager',async()=>{seed();const r=await manager.request.get(url+'&task=search.purge',{maxRedirects:0});assert.equal(r.status(),403);assert.equal(count(),1);return r.status();});
 await manager.close();
 const filename='X"><script>window.__xss=1</script>.pdf';
 sql(`INSERT INTO ${P}flexicontent_files(filename,filename_original,altname,url,secure,ext,uploaded) VALUES(${q(filename)},'fixture.pdf','Fixture',0,1,'pdf','2026-01-02 03:04:05')`);
 const fileId=Number(sql(`SELECT MAX(id) FROM ${P}flexicontent_files`)[0][0]);
 for(const original of [true,false])await check((original?'Negative control: ':'Fixed: ')+'file real path HTML encoding',async()=>{
  const run=async()=>{for(const dialog of ['', '&tmpl=component']){const r=await page.goto(url+`&task=file.edit&view=file&id=${fileId}`+dialog);assert.equal(r.status(),200);assert.equal(await page.evaluate(()=>window.__xss||0),original?1:0);if(!original)assert((await page.locator('body').textContent()).includes(filename));}return {normal:true,dialog:true};};return original?rollback(['file'],run):run();
 });
 for(const original of [true,false])await check((original?'Negative control: ':'Fixed: ')+'Joomla user edit and new forms',async()=>{
  const run=async()=>{const rows=[];for(const task of [`users.edit&id=${managerId}`,'users.add']){const r=await page.goto(url+'&task='+task),body=await page.locator('body').innerText();const broken=original&&observations.joomlaMajor===6;assert.equal(r.status(),broken?500:200,body.slice(-1000));if(broken)assert.match(body,/stdClass::get/);else {assert.equal(await page.locator('#adminForm').count(),1);assert(!(original?/Fatal error/:/Fatal error|Deprecated:|Warning:/).test(body),body.slice(-1500));}rows.push({task,status:r.status(),oldNullDateWarning:original&&/DateTime::__construct/.test(body)});}return rows;};return original?rollback(['user','userform'],run):run();
 });
 // Permission reports intentionally require Joomla debug mode.
 const config=SITE+'/configuration.php',configBefore=fs.readFileSync(config,'utf8');fs.writeFileSync(config,configBefore.replace('public $debug = false','public $debug = true'));
 try{for(const original of [true,false])for(const kind of ['user','group'])await check((original?'Negative control: ':'Fixed: ')+kind+' permission report keeps selected principal',async()=>{
  const id=kind==='user'?managerId:6;
  const run=async()=>{await page.goto(url+`&view=debug${kind}&${kind}_id=${id}`);const action=await page.locator('#adminForm').getAttribute('action');const parsed=new URL(action,BASE);assert.equal(parsed.searchParams.get(kind+'_id'),original?(kind==='user'?'0':null):String(id));
   if(!original){await Promise.all([page.waitForNavigation(),page.locator('select[name=filter_component]').selectOption('com_flexicontent')]);assert.equal(new URL(page.url()).searchParams.get(kind+'_id'),String(id));assert(!/User #0|Warning:|Fatal error/.test(await page.locator('body').innerText()));}return {action};};return original?rollback(['debug'+kind],run):run();
 });}finally{fs.writeFileSync(config,configBefore);}
 const sliderPath='site/layouts/items_list_filters/txtsearch_date_slider.php';
 const guest=await browser.newContext(),gp=await guest.newPage();await guest.route('**/*',r=>new URL(r.request().url()).origin===BASE?r.continue():r.abort());
 for(const original of [true,false])for(const mode of [7,8])for(const custom of [0,1])await check((original?'Negative control: ':'Fixed: ')+`slider ${mode} ${custom?'configured HTML':'stored text'}`,async()=>{
  // Original custom labels also expose the separate null strlen defect; test that in the strict PHP suite.
  if(original&&custom){const r=c.sh('php',[c.H+'/slider-render.php',before+'/'+sliderPath,String(mode),String(custom)],{allowFail:true});if(mode===8){assert.notEqual(r.status,0);assert.match(r.stdout+r.stderr,/strlen/);}else{assert.equal(r.status,0);assert(JSON.parse(r.stdout).js.includes('Configured'));}return {nullDeprecationReproduced:mode===8};}
  const rendered=JSON.parse(c.sh('php',[c.H+'/slider-render.php',original?before+'/'+sliderPath:installed(sliderPath),String(mode),String(custom)]));
  const html='<html><body><div>'+rendered.html+'</div><script src="/media/vendor/jquery/js/jquery.min.js"></script><script src="/components/com_flexicontent/librairies/nouislider/nouislider.min.js"></script><script>'+rendered.js+'</script></body></html>';
  fs.writeFileSync(SITE+'/remediation-slider.html',html);await gp.goto(BASE+'/remediation-slider.html');
  await gp.waitForFunction(()=>document.getElementById('filter_99_nouislider').noUiSlider);
  await gp.evaluate(m=>document.getElementById('filter_99_nouislider').noUiSlider.set(m===7?1:[1,1]),mode);
  if(original)await gp.waitForFunction(()=>window.__xss===1,null,{timeout:10000});else await gp.waitForTimeout(300);
  assert.equal(await gp.evaluate(()=>window.__xss||0),original?1:0);
  const box=gp.locator('.fcfilter_nouislider_txtbox');if(custom)assert(await box.locator('b').count()>0);else if(!original){assert.equal(await box.locator('img').count(),0);assert((await box.innerText()).includes('<img'));}
  return {mode,custom,scriptExecuted:await gp.evaluate(()=>window.__xss||0)};
 });
 await guest.close();
 await check('Translate progress reports actual translations with Maximum error reporting',async()=>{
  sql(`INSERT INTO ${P}languages(lang_code,title,title_native,sef,image,published,access,ordering) VALUES('fr-FR','French','Francais','fr','fr_fr',1,1,2)`);
  sql(`UPDATE ${P}extensions SET enabled=1,params=JSON_SET(IF(JSON_VALID(params),params,'{}'),'$.item_associations',1) WHERE type='plugin' AND folder='system' AND element='languagefilter'`);
  const ids=[];
  for(let i=0;i<2;i++){
   await page.goto(url+'&task=items.add&typeid=1');await page.fill('#jform_title','Remediation Translate '+i);await page.fill('#jform_text','<p>Translate fixture</p>');
   const cat=sql(`SELECT id FROM ${P}categories WHERE extension='com_content' AND published=1 ORDER BY id LIMIT 1`)[0][0];
   for(const [selector,value]of [['#jform_catid',cat],['#jform_language','en-GB']])await page.locator(selector).evaluate((e,v)=>{e.value=v;e.dispatchEvent(new Event('change',{bubbles:true}));},value);
   await Promise.all([page.waitForNavigation(),page.evaluate(()=>Joomla.submitbutton('items.save'))]);
   ids.push(Number(sql(`SELECT id FROM ${P}content WHERE title=${q('Remediation Translate '+i)}`)[0][0]));
  }
  await page.goto(url+`&view=items&tmpl=component&task=quicktranslateall&cid[]=${ids[0]}&cid[]=${ids[1]}`);
  const errors=[];const listener=e=>errors.push(e.message);page.on('pageerror',listener);
  try{
   const input=page.locator('input[name="languages[]"][value="fr-FR"]');const id=await input.getAttribute('id');await page.locator('label[for="'+id+'"]').click();assert(await input.isChecked());
   const steps=[];const response=r=>{if(r.request().method()==='POST'&&/fc_ajax/.test(r.request().postData()||''))steps.push(r);};page.on('response',response);
   try{await page.locator('#fc_add_translations_btn').click();await page.locator('#fc_bp_close').waitFor({state:'visible',timeout:120000});}finally{page.off('response',response);}
   assert.equal(steps.length,2);for(const r of steps){const text=await r.text();assert(text.trim().startsWith('{'),text.slice(0,400));const json=JSON.parse(text);assert.equal(json.ok,true);assert.equal(json.created,1);assert.deepEqual(json.errors,[]);}
   assert.match(await page.locator('#fc_bp_percent').innerText(),/100\.00/);assert.equal((await page.locator('#fc_bp_errors').innerText()).trim(),'');assert.match(await page.locator('#fc_bp_head').innerText(),/2/);
   const made=sql(`SELECT id,language FROM ${P}content WHERE title LIKE '%Remediation Translate %' AND language='fr-FR' AND state<>-2`);assert.equal(made.length,2);assert.deepEqual(errors,[]);
   const progress=await page.locator('#fc_bp_head').innerText();
   const log=c.S+'/run/php-error.log',offset=fs.existsSync(log)?fs.statSync(log).size:0;
   const trash=await context.request.post(url,{form:{task:'items.changestate','cid[]':made[0][0],newstate:'-2',[adminToken]:'1'}});
   assert.equal(sql(`SELECT state FROM ${P}content WHERE id=${Number(made[0][0])}`)[0][0],'-2');
   const added=fs.existsSync(log)?fs.readFileSync(log).subarray(offset).toString():'';
   assert(!/Trying to access array offset on null|strlen\(\): Passing null/.test(added),added.slice(-1000));
   return {requests:steps.length,created:made.length,progress,trashStatus:trash.status(),translatedItemTrashed:true};
  }finally{page.off('pageerror',listener);}
 });
 // Uninstall is last: this fixture and its database are disposable.
 let bundledState=[];
 await check('Joomla uninstall removes bundled extensions and keeps both applications usable',async()=>{
  const id=Number(sql(`SELECT extension_id FROM ${P}extensions WHERE type='component' AND element='com_flexicontent'`)[0][0]);
  const bundled=sql(`SELECT extension_id FROM ${P}extensions WHERE element LIKE '%flexi%' OR folder='flexicontent_fields'`).map(r=>Number(r[0]));assert(bundled.length>40);
  bundledState=sql(`SELECT type,folder,element,enabled FROM ${P}extensions WHERE extension_id IN (${bundled.join(',')})`);
  const out=c.sh('php',[c.H+'/uninstall.php',String(id)]);assert.match(out,/UNINSTALLED=1/);
  const remaining=sql(`SELECT extension_id,element FROM ${P}extensions WHERE extension_id IN (${bundled.join(',')})`);assert.deepEqual(remaining,[]);
  assert.deepEqual(sql(`SHOW TABLES LIKE '${P}flexicontent_basicsearch_index'`),[],'New autocomplete index removed with the component');
  for(const endpoint of ['/index.php','/administrator/index.php']){const r=await page.goto(BASE+endpoint);assert.equal(r.status(),200);assert(!/Failed opening required|Fatal error/.test(await page.locator('body').innerText()));}return {removed:bundled.length};
 });
 await check('Negative control: original uninstall lifecycle',async()=>{
  await c.installFromFolder(c.admin,c.S+'/package');
  // A reinstall in the same login session can skip the publish-plugins first-use task.
  // Restore the exact enabled states recorded from the fresh installation above.
  for(const[type,folder,element,enabled]of bundledState)sql(`UPDATE ${P}extensions SET enabled=${Number(enabled)} WHERE type=${q(type)} AND folder=${q(folder)} AND element=${q(element)}`);
  const id=Number(sql(`SELECT extension_id FROM ${P}extensions WHERE type='component' AND element='com_flexicontent'`)[0][0]);
  return rollback(['uninstall'],async()=>{
   const out=c.sh('php',[c.H+'/uninstall.php',String(id)],{allowFail:true});
   const remaining=sql(`SELECT element FROM ${P}extensions WHERE element LIKE '%flexi%' OR folder='flexicontent_fields'`);
   const responses=[];
   if(observations.joomlaMajor===6){assert.equal(remaining.length,50);for(const endpoint of ['/index.php','/administrator/index.php']){const r=await page.goto(BASE+endpoint),body=await page.locator('body').innerText();assert.match(body,/Failed opening required.+defineconstants\.php/s);responses.push({endpoint,status:r.status(),missingComponentError:true});}}
   else assert.deepEqual(remaining,[]);
   return {remaining:remaining.length,responses,exit:out.status,diagnostic:(out.stdout+out.stderr).slice(-800)};
  });
 });
};
