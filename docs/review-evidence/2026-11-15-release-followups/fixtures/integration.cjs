'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
module.exports.run=async c=>{
  const major=Number(c.options.joomla||5), upgrade=!!c.options.upgrade;
  const source=c.S+'/package';fs.mkdirSync(source,{recursive:true});
  c.sh('tar',['-xf',c.IN+'/'+(c.options.candidate?'candidate.tar':'master.tar'),'-C',source]);
  const dbpass='Fixture-DB-2026',admin={username:'release-admin',password:'Fixture-Admin-2026-Offline'};
  c.children.push(await c.startMysql());
  c.mysql(['-e',`CREATE DATABASE ${c.DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
    CREATE USER 'joomla'@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY '${dbpass}';
    GRANT ALL ON ${c.DB}.* TO 'joomla'@'127.0.0.1';`]);
  fs.mkdirSync(c.SITE,{recursive:true});
  c.sh('tar',['-xf',c.IN+`/joomla${major}.tar`,'-C',c.SITE,'--no-same-owner','--no-same-permissions']);
  const install=c.sh('php',['-d','memory_limit=768M',c.SITE+'/installation/joomla.php','install',
    '--site-name=FLEXIcontent Release Fixture','--admin-user=Release Administrator',`--admin-username=${admin.username}`,
    `--admin-password=${admin.password}`,'--admin-email=release@example.invalid','--db-type=mysqli','--db-host=127.0.0.1',
    '--db-user=joomla',`--db-pass=${dbpass}`,`--db-name=${c.DB}`,`--db-prefix=${c.PREFIX}`,'-n'],{cwd:c.SITE,timeout:180000});
  assert(install.includes('Joomla has been installed'),install.slice(-1200));
  c.writeConfig(dbpass);
  c.sql(`UPDATE ${c.PREFIX}extensions SET enabled=0 WHERE type='plugin' AND
    ((folder='behaviour' AND element IN ('compat','compat6')) OR (folder='system' AND element IN ('guidedtours','stats','schedulerunner')));`);
  c.log('Clean Joomla installed; compatibility plugins disabled');
  c.children.push(await c.startPhpServer());
  let displayEnv;
  if(c.options.virtualDisplay){
    const proc=require('node:child_process').spawn('/usr/bin/Xvfb',[':99','-screen','0','1440x1000x24','-nolock','-nolisten','unix','-listen','tcp','-ac'],{env:c.childEnv,stdio:'ignore'});
    c.children.push(proc);await c.sleep(1000);displayEnv={...process.env,DISPLAY:'127.0.0.1:99'};
  }
  const browser=await require('playwright')[c.options.browser||'chromium'].launch({headless:!c.options.virtualDisplay,...(displayEnv?{env:displayEnv}:{})});
  const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:c.options.reducedMotion?'reduce':'no-preference'});
  const chartResponses=c.options.charts?JSON.parse(fs.readFileSync(c.IN+'/chart-responses.json','utf8')):[];
  await context.route('**/*',async r=>{
    if(c.options.mediaAssets&&r.request().url()==='https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js')return r.fulfill({status:200,contentType:'application/javascript',body:fs.readFileSync(c.H+'/addons/wavesurfer.min.js')});
    const asset=chartResponses.find(x=>x.url===r.request().url());
    if(asset)return r.fulfill({status:200,contentType:'application/javascript',body:fs.readFileSync(c.IN+'/'+asset.file)});
    return new URL(r.request().url()).origin===c.BASE?r.continue():r.abort('blockedbyclient');
  });
  const page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(60000);
  const errors=[],http=[];
  let phase=upgrade?'v51':'candidate';
  function track(p){
    p.on('pageerror',e=>errors.push({phase,url:p.url().replace(c.BASE,''),name:e.name,message:e.message,stack:e.stack?.slice(0,1100)}));
    p.on('response',r=>{if(r.url().startsWith(c.BASE)&&r.status()>=400)http.push({phase,url:r.url().replace(c.BASE,''),status:r.status()})});
  }
  track(page);context.on('page',track);
  const observations={joomlaMajor:major,php:c.sh('php',['-v']).split('\n')[0],freshJoomla:true,compatibilityPlugins:[],checks:[]};
  async function check(name,fn){try{const detail=await fn();observations.checks.push({name,status:'pass',detail});c.log('PASS',name);}catch(e){observations.checks.push({name,status:'fail',message:String(e.message).slice(0,1600)});c.log('FAIL',name,String(e.message).slice(0,180));}}
  async function packageZip(dir,name){const zip=c.S+'/'+name+'.zip';c.sh('python3',['-c',
    'import zipfile,pathlib,sys;r=pathlib.Path(sys.argv[1]);z=zipfile.ZipFile(sys.argv[2],"w",zipfile.ZIP_DEFLATED,strict_timestamps=False);[(z.write(p,p.relative_to(r).as_posix())) for p in r.rglob("*") if p.is_file()];z.close()',dir,zip]);return zip;}
  async function uploadPackage(zip){
    await page.goto(c.BASE+'/administrator/index.php?option=com_installer&view=install');
    const input=page.locator('input[type=file][name=install_package]');
    assert.equal(await input.count(),1,'Extension package uploader exists');
    const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('com_installer'),{timeout:180000});
    await input.setInputFiles(zip);
    const res=await response;const body=await res.text().catch(()=>'');
    await page.waitForLoadState('networkidle');
    await page.waitForFunction(()=>/success/i.test(document.querySelector('#system-message-container')?.innerText||''),null,{timeout:30000});
    const msg=await page.locator('#system-message-container').innerText();
    assert(/success/i.test(msg),msg.slice(0,2000)||body.slice(-1000));
    return {status:res.status(),message:msg.replace(/\s+/g,' ').slice(0,500)};
  }
  try{
    await c.adminLogin(page,admin);
    if(c.options.cacheTransitions)await require(c.H+'/cache-transitions.cjs').runCore({...c,page,context,browser,check,observations,admin});
    if(c.options.transitionControls){for(const stage of ['minimal','core'])await require(c.H+'/transitions.cjs').run({...c,page,context,check,observations,admin},stage);}
    if(c.options.coreControl){
      observations.cleanCoreBrowser={browser:browser.version(),browserDirectories:fs.readdirSync('/ms-playwright'),errors:[]};
      for(let i=0;i<20;i++){
        await page.goto(c.BASE+'/administrator/index.php?option=com_content&view='+(i%2?'articles':'categories'));
        await page.waitForLoadState('networkidle');
      }
      for(let i=0;i<5;i++){
        await page.goto(c.BASE+'/administrator/index.php?option=com_content&task=article.add');
        await page.fill('#jform_title','Clean core form control '+i);
        await page.locator('#jform_catid').evaluate(e=>{e.value='2';e.dispatchEvent(new Event('change',{bubbles:true}));});
        await Promise.all([page.waitForNavigation({timeout:60000}),page.evaluate(()=>Joomla.submitbutton('article.save'))]);
        await page.waitForLoadState('networkidle');
      }
      observations.cleanCoreBrowser.errors=errors.splice(0);http.length=0;
    }
    if(upgrade){
      const old=c.S+'/v51';fs.mkdirSync(old);c.sh('tar',['-xf',c.IN+(c.options.aclUpgrade?'/before.tar':'/v51.tar'),'-C',old]);
      await check(c.options.aclUpgrade?'Install pre-fix integration package':'Install actual v5.1 package',async()=>uploadPackage(await packageZip(old,'v51')));
      await c.runFcPostinstall(page);
      await page.goto(c.BASE+'/administrator/index.php?option=com_config&view=component&component=com_flexicontent');
      await Promise.all([page.waitForNavigation({timeout:60000}),page.locator('#toolbar-apply button').click()]);
      await page.goto(c.BASE+'/administrator/index.php?option=com_flexicontent&task=items.add&typeid=1');
      await page.fill('#jform_title','Pre-upgrade representative 中文');
      const category=c.sql(`SELECT id FROM ${c.PREFIX}categories WHERE extension='com_content' AND published=1 ORDER BY id LIMIT 1`)[0][0];
      await page.locator('#jform_catid').evaluate((e,v)=>{e.value=v;e.dispatchEvent(new Event('change',{bubbles:true}));},category);
      await page.fill('#jform_text','<p>Preserve "quotes" &amp; <em>formatting</em> 中文.</p>');
      await Promise.all([page.waitForNavigation({timeout:60000}),page.evaluate(()=>Joomla.submitbutton('items.save'))]);
      if(c.options.seedUpgradeFields)await require(c.H+'/broad-fields.cjs').run({...c,page,context,observations,admin,allowBaselineDiagnostics:true,check:(name,fn)=>check('v5.1 seed: '+name,fn)});
      observations.preUpgradeFieldValues=c.sql(`SELECT field_id,item_id,valueorder,suborder,value FROM ${c.PREFIX}flexicontent_fields_item_relations ORDER BY field_id,item_id,valueorder,suborder`);
      observations.preUpgradeContent=c.sql(`SELECT c.id,c.title,c.introtext,c.\`fulltext\`,c.catid,c.state,c.access,e.type_id FROM ${c.PREFIX}content c JOIN ${c.PREFIX}flexicontent_items_ext e ON e.item_id=c.id ORDER BY c.id`);
      assert(observations.preUpgradeContent.length>0,'Representative v5.1 item stored');
      c.sql(`INSERT INTO ${c.PREFIX}flexicontent_files (filename,filename_original,altname,url,secure,ext,uploaded)
        VALUES ('fixture.pdf','Fixture \\"quote\\" 中文.pdf','Fixture & metadata',0,1,'pdf','2026-01-02 03:04:05');`);
      observations.preUpgradeFiles=c.sql(`SELECT id,filename,filename_original,altname,uploaded FROM ${c.PREFIX}flexicontent_files ORDER BY id`);
      const backup=c.S+'/before-upgrade.sql';
      const fd=fs.openSync(backup,'w');try{c.sh('mysqldump',['--no-defaults','--socket='+c.S+'/run/mysqld.sock','-uroot','--skip-lock-tables',c.DB],{stdio:['ignore',fd,'pipe']});}finally{fs.closeSync(fd);}
      observations.preUpgradeBackup={bytes:fs.statSync(backup).size,sha256:c.sha256(backup),note:'Disposable fixture backup remained inside bounded scratch'};
    }
    if(upgrade&&fs.existsSync(c.S+'/run/php-error.log')){
      const previous=fs.readFileSync(c.SITE+'/administrator/components/com_flexicontent/defineconstants.php','utf8');
      const next=fs.readFileSync(source+'/admin/defineconstants.php','utf8');
      observations.previousConstantsSha256=c.sha256(c.SITE+'/administrator/components/com_flexicontent/defineconstants.php');
      observations.oldStrictConstantBootstrap=previous.includes('E_ALL & ~E_STRICT')&&!next.includes('E_ALL & ~E_STRICT');
      observations.v51Diagnostics=fs.readFileSync(c.S+'/run/php-error.log','utf8').slice(-2000);
      fs.writeFileSync(c.S+'/run/php-error.log','');
    }
    phase='candidate';
    if(upgrade&&c.options.updateViaFeed)await require(c.H+'/update-feed.cjs').run({...c,page,context,check,observations,admin});
    else await check(upgrade?'Upgrade via actual candidate ZIP':'Fresh FLEXIcontent via actual ZIP',async()=>uploadPackage(c.options.artifactZip?c.IN+'/'+c.manifest.candidateZip.file:await packageZip(source,'release')));
    await check('Post-install initialization',async()=>{await c.runFcPostinstall(page);return 'No pending first-use tasks';});
    await check('First-use configuration Save',async()=>{
      await page.goto(c.BASE+'/administrator/index.php?option=com_config&view=component&component=com_flexicontent');
      await Promise.all([page.waitForNavigation({timeout:60000}),page.locator('#toolbar-apply button').click()]);
      await page.waitForLoadState('networkidle');
      const msg=await page.locator('#system-message-container').innerText();assert.match(msg,/saved/i);
      return msg.replace(/\s+/g,' ').slice(0,300);
    });
    observations.compatibilityPlugins=c.sql(`SELECT element,enabled FROM ${c.PREFIX}extensions WHERE folder='behaviour' ORDER BY element`);
    observations.joomla=c.sh('php',[c.SITE+'/cli/joomla.php','--version'],{cwd:c.SITE}).trim();
    if(c.manifest.candidate.version)await check('Installed version metadata matches the submitted PR source',async()=>{
      const cache=JSON.parse(c.sql(`SELECT manifest_cache FROM ${c.PREFIX}extensions WHERE element='com_flexicontent' AND type='component'`)[0][0]);assert.equal(cache.version,c.manifest.candidate.version);
      const out=c.sh('php',['-d','log_errors=1','-d','error_log='+c.S+'/run/php-error.log',c.H+'/joomla-ops.php',JSON.stringify({op:'version',user:Number(c.sql(`SELECT id FROM ${c.PREFIX}users WHERE username='release-admin'`)[0][0])})]);const runtime=JSON.parse(out.trim().split('\n').pop());assert.deepEqual(runtime,c.manifest.candidate.runtime);return {manifest:cache.version,runtime};
    });
    observations.fileSchema=c.sql(`SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='${c.DB}' AND TABLE_NAME='${c.PREFIX}flexicontent_files' ORDER BY ORDINAL_POSITION`);
    await check('Mail templates initialized',async()=>{const n=Number(c.sql(`SELECT COUNT(*) FROM ${c.PREFIX}mail_templates WHERE template_id LIKE 'com_flexicontent.%'`)[0][0]);assert.equal(n,5);return n;});
    if(upgrade)await check('File rows preserved',async()=>{const after=c.sql(`SELECT id,filename,filename_original,altname,uploaded FROM ${c.PREFIX}flexicontent_files ORDER BY id`);assert.deepEqual(after,observations.preUpgradeFiles);return after.length;});
    if(upgrade)await check('Representative content preserved through upgrade',async()=>{
      const after=c.sql(`SELECT c.id,c.title,c.introtext,c.\`fulltext\`,c.catid,c.state,c.access,e.type_id FROM ${c.PREFIX}content c JOIN ${c.PREFIX}flexicontent_items_ext e ON e.item_id=c.id ORDER BY c.id`);
      assert.deepEqual(after,observations.preUpgradeContent);return after.length;
    });
    if(upgrade)await check('Custom field values preserved through actual extension upgrade',async()=>{
      const after=c.sql(`SELECT field_id,item_id,valueorder,suborder,value FROM ${c.PREFIX}flexicontent_fields_item_relations ORDER BY field_id,item_id,valueorder,suborder`);
      assert.deepEqual(after,observations.preUpgradeFieldValues);
      if(c.options.seedUpgradeFields){const p=await context.newPage();try{const r=await p.goto(c.BASE+`/index.php?option=com_flexicontent&view=item&id=${observations.broadFieldItem}`);const body=await p.locator('body').innerText();assert.equal(r.status(),200);assert(body.includes('Beta')&&body.includes('Mail title 1')&&body.includes('2026-11-16'));}finally{await p.close();}}
      return after.length;
    });
    if(c.options.acl){
      if(c.options.aclUpgrade)await check('Upgrade explains Basic index rebuild',async()=>{const installation=observations.checks.find(x=>x.name==='Upgrade via actual candidate ZIP');assert.match(installation.detail.message,/Rebuild the Basic search index/);assert(!installation.detail.message.includes('FLEXI_BASIC_AUTOCOMPLETE_REBUILD'));return installation.detail.message;});
      await require(c.H+'/acl.cjs').run({...c,page,context,browser,check,observations,admin});
      return {status:observations.checks.every(r=>r.status==='pass')?'pass':'fail',observations};
    }
    if(c.options.remediation){
      await require(c.H+'/remediation.cjs').run({...c,page,context,browser,check,observations,admin});
      return {status:observations.checks.every(r=>r.status==='pass')?'pass':'fail',observations};
    }
    for(const view of (c.options.focus?['','stats']:['', 'items','categories','types','fields','tags','templates','users','filemanager','import','search','stats'])){
      await check('Administrator '+(view||'dashboard'),async()=>{
        const response=await page.goto(c.BASE+'/administrator/index.php?option=com_flexicontent'+(view?'&view='+view:''));
        await page.waitForLoadState('networkidle');const body=await page.locator('body').innerText();
        assert.equal(response.status(),200,body.slice(-1400));assert(!/Fatal error|Warning:|Deprecated:|Call to undefined|Class .+ not found/i.test(body),body.slice(-2000));
        if(view)assert.equal(new URL(page.url()).searchParams.get('view'),view,body.slice(-2200));
        if(view==='stats'){
          observations.statsScripts=await page.locator('script:not([src])').allTextContents().then(x=>x.filter(t=>/data|chart|plot/i.test(t)).map(x=>({bytes:x.length,sha256:require('node:crypto').createHash('sha256').update(x).digest('hex')})));
          const options=await page.evaluate(()=>Joomla.getOptions('flexicontent.stats'));
          const external=[options.loader,...options.scripts].filter(u=>new URL(u,c.BASE).origin!==c.BASE);
          if(external.length&&!c.options.charts)observations.chartRendering={status:'blocked',reason:'Required chart libraries are loaded from an external CDN; the offline fixture forbids that network access.',external};
          else {
            await page.waitForFunction(()=>['main','pie','pie2'].every(id=>document.querySelector('#'+id+' canvas')),null,{timeout:15000});
            observations.chartRendering={status:'pass',mode:external.length?'Pinned CDN-response replay, no external sandbox network':'Local application chart assets',
              assets:chartResponses,canvases:await page.evaluate(()=>['main','pie','pie2'].map(id=>({id,canvases:[...document.querySelectorAll('#'+id+' canvas')].map(c=>{const pixels=c.getContext('2d').getImageData(0,0,c.width,c.height).data;let drawn=0;for(let i=3;i<pixels.length;i+=4)if(pixels[i])drawn++;return {width:c.width,height:c.height,nonTransparent:drawn};})})))};
            assert(observations.chartRendering.canvases.every(row=>row.canvases.some(x=>x.nonTransparent>0)),'All three charts draw non-transparent pixels');
          }
        }
        return {status:response.status(),title:await page.title(),heading:await page.locator('h1,h2,.page-title').allTextContents(),bodyLength:body.length,url:page.url().replace(c.BASE,'')};
      });
    }
    await page.goto(c.BASE+'/administrator/index.php?option=com_flexicontent');
    observations.dashboard={body:(await page.locator('body').innerText()).slice(-4500),buttons:await page.locator('a.fc_button,button').evaluateAll(xs=>xs.map(x=>({id:x.id,href:x.getAttribute('href'),onclick:x.getAttribute('onclick'),text:x.textContent.trim().slice(0,150)})).filter(x=>x.id||x.onclick).slice(-50))};
    observations.form=await (async()=>{
      await page.goto(c.BASE+'/administrator/index.php?option=com_flexicontent&task=items.add&typeid=1');await page.waitForLoadState('networkidle');
      assert.equal(await page.locator('#jform_title').count(),1);
      return {url:page.url().replace(c.BASE,''),body:(await page.locator('body').innerText()).slice(-1800),inputs:await page.locator('input[name],select[name],textarea[name]').evaluateAll(xs=>xs.map(x=>({tag:x.tagName,id:x.id,name:x.name,type:x.type})).filter(x=>x.type!=='hidden').slice(0,35))};
    })();
    observations.checks.push({name:'New item form has no PHP diagnostics',status:/Deprecated:|Warning:|Fatal error/i.test(observations.form.body)?'fail':'pass'});
    if(c.options.transitionControls)await require(c.H+'/transitions.cjs').run({...c,page,context,check,observations,admin},'flexicontent');
    if(c.options.cacheModuleOverlay)await require(c.H+'/cache-transitions.cjs').runModuleOverlay({...c,page,context,browser,check,observations,admin});
    if(c.options.cacheOverlayBefore)await require(c.H+'/cache-transitions.cjs').runBeforeAfter({...c,page,context,browser,check,observations,admin});
    if(c.options.cacheTransitions)await require(c.H+'/cache-transitions.cjs').run({...c,page,context,browser,check,observations,admin});
    if(c.options.pageCacheMitigation)await require(c.H+'/cache-transitions.cjs').runMitigation({...c,page,context,browser,check,observations,admin});
    if(c.options.pageRevocation)await require(c.H+'/page-cache.cjs').run({...c,page,context,browser,check,observations,admin});
    if(c.options.thumbnails)await require(c.H+'/thumbnail-integration.cjs').run({...c,page,context,browser,check,observations,admin});
    if(c.options.complexFields)await require(c.H+'/complex-fields.cjs').run({...c,page,context,browser,check,observations,admin});
    if(c.options.categoryBatch)await require(c.H+'/category-batch.cjs').run({...c,page,context,browser,check,observations,admin});
    if(c.options.osmap)await require(c.H+'/osmap.cjs').run({...c,page,context,browser,check,observations,admin});
    if(c.options.fields)await require(c.H+'/fields.cjs').run({...c,page,context,check,observations,admin});
    if(c.options.broadFields)await require(c.H+'/broad-fields.cjs').run({...c,page,context,check,observations,admin});
    if(c.options.workflows)await require(c.H+'/workflows.cjs').run({...c,page,context,check,observations,admin});
    if(c.options.notifications)await require(c.H+'/notifications.cjs').run({...c,page,context,check,observations,admin});
    if(c.options.finder)await require(c.H+'/finder.cjs').run({...c,page,context,check,observations,admin});
    if(c.options.roles)await require(c.H+'/roles.cjs').run({...c,page,context,browser,check,observations,admin});
    if(c.options.gates)await require(c.H+'/release-gates.cjs').run({...c,page,context,check,observations,admin});
    if(c.options.migrate)await require(c.H+'/migration.cjs').run({...c,page,context,check,observations,admin});
    observations.v51BrowserDiagnostics={errors:errors.filter(x=>x.phase==='v51'),httpFailures:http.filter(x=>x.phase==='v51')};
    observations.errors=errors.filter(x=>x.phase==='candidate');observations.httpFailures=http.filter(x=>x.phase==='candidate');
    observations.phpErrors=fs.existsSync(c.S+'/run/php-error.log')?fs.readFileSync(c.S+'/run/php-error.log','utf8').slice(-9000):'';
    if(upgrade&&observations.oldStrictConstantBootstrap){
      const previous=[],current=[];
      for(const line of observations.phpErrors.split('\n'))(/PHP Deprecated:\s+Constant E_STRICT is deprecated in \/scratch\/site\/administrator\/components\/com_flexicontent\/defineconstants\.php on line \d+/.test(line)?previous:current).push(line);
      observations.previousVersionBootstrapDiagnostics=previous;observations.phpErrors=current.join('\n').trim();
    }
    observations.checks.push({name:'No PHP warnings, notices or deprecations',status:/PHP (?:Warning|Deprecated|Fatal error|Parse error|Notice):/.test(observations.phpErrors)?'fail':'pass',detail:observations.phpErrors||undefined});
  }finally{await browser.close();}
  if(c.options.compact){
    if(observations.complexFrontend)delete observations.complexFrontend.body;
    if(observations.categoryPages)for(const row of Object.values(observations.categoryPages))delete row.body;
    if(observations.categoryFilter)for(const row of Object.values(observations.categoryFilter))if(row&&typeof row==='object')delete row.body;
    for(const key of ['form','dashboard','complexProbe','imageCloneComponents','broadFieldControls','fileSchema','finderSave','finderSearch'])delete observations[key];
    if(observations.cacheIsolation)observations.cacheIsolation.cached=observations.cacheIsolation.cached.slice(0,12);
    for(const row of observations.checks){if(row.status==='pass'&&row.detail!==undefined&&(typeof row.detail==='object'||String(row.detail).length>120))row.detail={verified:true};}
    if(observations.migration){delete observations.migration.body;delete observations.migration.firstBackend;}
    if(observations.previousVersionBootstrapDiagnostics){const rows=observations.previousVersionBootstrapDiagnostics;observations.previousVersionBootstrapDiagnostics={count:rows.length,sample:rows.slice(0,2)};}
    const keep=new Set(['joomlaMajor','php','freshJoomla','joomla','compatibilityPlugins','checks','errors','httpFailures','phpErrors','migration','thumbnail','fullPageCache','chartRendering','oldStrictConstantBootstrap','previousVersionBootstrapDiagnostics']);
    const digests={};
    for(const key of Object.keys(observations))if(!keep.has(key)){
      const value=JSON.stringify(observations[key])||'null';
      digests[key]={bytes:Buffer.byteLength(value),sha256:require('node:crypto').createHash('sha256').update(value).digest('hex')};
      delete observations[key];
    }
    observations.additionalEvidenceDigests=digests;
    assert(Buffer.byteLength(JSON.stringify(observations))<45000,'Structured evidence exceeds bounded base64 receipt size');
  }
  return {status:observations.checks.every(r=>r.status==='pass')&&!observations.errors.length&&!observations.httpFailures.length?'pass':'fail',observations};
};
