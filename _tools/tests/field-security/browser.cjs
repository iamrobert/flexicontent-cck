const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../../..');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'fc-field-tests-'));
const env = {...process.env, FLEXI_TEST_SITE: temporary};
const php = process.env.PHP_BINARY || 'php';
const phpArgs = process.env.PHP_SQLITE_EXTENSION ? ['-d', 'extension='+process.env.PHP_SQLITE_EXTENSION] : [];
const write = (relative, data) => {const p=path.join(temporary,relative);fs.mkdirSync(path.dirname(p),{recursive:true});fs.writeFileSync(p,data);};
write('components/com_flexicontent/classes/helpers/security.php', '<?php require_once '+JSON.stringify(root.replaceAll('\\','/')+'/site/classes/helpers/security.php')+';');
write('components/com_flexicontent/helpers/route.php','<?php // Routing test double is declared in bootstrap.php.');
write('administrator/components/com_flexicontent/defineconstants.php','<?php // Constants are supplied by the isolated test bootstrap.');
let server, browser;
(async()=>{
  for (const kind of ['controller','file','mediafile','helper']) {
    const result=spawnSync(php,[...phpArgs,path.join(__dirname,'entrypoints.php'),kind],{env,encoding:'utf8',windowsHide:true});
    process.stdout.write(result.stdout);process.stderr.write(result.stderr);assert.equal(result.status,0,'Fresh '+kind+' entry point');
  }
  const regression=spawnSync(php,[...phpArgs,path.join(__dirname,'regressions.php')],{env,encoding:'utf8',windowsHide:true});
  process.stdout.write(regression.stdout);process.stderr.write(regression.stderr);assert.equal(regression.status,0,'Save-path regression checks');
  const unit=spawnSync(php,[...phpArgs,path.join(__dirname,'unit.php')],{env,encoding:'utf8',windowsHide:true});
  process.stdout.write(unit.stdout);process.stderr.write(unit.stderr);assert.equal(unit.status,0,'PHP regression assertions');
  const socket=net.createServer();await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
  const url='http://127.0.0.1:'+port;let serverLog='';
  server=spawn(php,[...phpArgs,'-d','upload_max_filesize=16M','-d','post_max_size=32M','-S','127.0.0.1:'+port,path.join(__dirname,'router.php')],{env,stdio:['ignore','pipe','pipe'],windowsHide:true});
  server.stderr.on('data',b=>serverLog+=b);server.stdout.on('data',b=>serverLog+=b);
  browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
  const context=await browser.newContext();
  await context.route('**/*',route=>new URL(route.request().url()).origin===url ? route.continue() : route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>Fixture</title>'}));
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(let i=0;i<100;i++){try{await context.request.get(url+'/missing');break;}catch(e){if(i===99)throw e;await new Promise(r=>setTimeout(r,50));}}
  for (const captcha of ['0','broken']) {
    const unavailable=await page.goto(url+'/form?captcha='+captcha);assert.equal(unavailable.status(),200);assert.equal(await page.locator('form').count(),0);assert.equal(await page.locator('.fc-contact-unavailable').count(),1);
  }
  const response=await page.goto(url+'/form');assert.equal(response.status(),200);
  await page.locator('input[name$="[name]"]').fill('Visitor');
  await page.locator('input[name$="[emailfrom]"]').fill('visitor@example.test');
  await page.locator('textarea').fill('<img src=x onerror="window.injected=1">');
  await page.locator('input[type=radio]').first().check();await page.locator('input[type=checkbox][name$="[]"]').first().check();await page.locator('input[name=consent]').check();
  const form=await page.locator('form').evaluate(f=>Object.fromEntries(new FormData(f)));
  // Omit the empty File entry; multipart tests below use real uploaded files.
  for(const key of Object.keys(form))if(typeof form[key]!=='string')delete form[key];
  async function send(change={},bucket='case-'+Math.random(),query=''){
    const result=await context.request.post(url+'/submit?bucket='+bucket+query,{form:{...form,...change}});
    return {status:result.status(),data:await result.json()};
  }
  let result=await send({emailauthor:'attacker@example.test',itemtitle:'Forged title',itemauthor:'Forged author'});
  assert.equal(result.status,200,JSON.stringify(result.data));assert.deepEqual(result.data.recipients,['owner@example.test']);assert.deepEqual(result.data.sender,['site@example.test','Test sender']);assert.deepEqual(result.data.bcc,[]);assert.ok(result.data.body.includes('Server title'));assert.ok(!result.data.body.includes('Forged title'));assert.ok(!result.data.body.includes('<img'));assert.ok(result.data.body.includes('&lt;img'));
  for(const [changes,status] of [[{recipient_key:'a'.repeat(64)},403],[{itemid:'11'},403],[{fieldid:'21'},403],[{test_token:'bad'},403],[{captcha:'failed'},403],[{consent:''},400],[{formid:'../../outside'},400]]){
    const denied=await send(changes);assert.equal(denied.status,status,JSON.stringify(denied));assert.equal(denied.data.sent,false);
  }
  result=await send({},undefined,'&disabled=1');assert.equal(result.status,403);
  const get=await context.request.get(url+'/submit');assert.equal(get.status(),405);
  const emailKey=Object.keys(form).find(k=>k.endsWith('[emailfrom]'));
  result=await send({[emailKey]:'verified@example.test'},undefined,'&member=1');assert.deepEqual(result.data.bcc,['verified@example.test']);
  const formId=form.formid;
  async function upload(name,bytes,mime='text/plain'){
    const r=await context.request.post(url+'/submit?bucket=upload-'+Math.random(),{multipart:{...form,[formId+'[attachment][]']:{name,mimeType:mime,buffer:bytes}}});return {status:r.status(),data:await r.json()};
  }
  result=await upload('note.txt',Buffer.from('Hello from the fixture'));assert.equal(result.status,200,JSON.stringify(result.data));assert.equal(result.data.attachments[0].name,'note.txt');
  for(const [name,bytes,mime] of [['bad.php',Buffer.from('<?php echo 1;'),'text/plain'],['fake.png',Buffer.from('plain text'),'image/png'],['large.txt',Buffer.alloc(6*1024*1024,65),'text/plain']]){
    result=await upload(name,bytes,mime);assert.equal(result.status,400,JSON.stringify(result));assert.equal(result.data.sent,false);
  }
  for(const [count,size] of [[6,20],[3,4*1024*1024]]){
    const multipart={...form};for(let i=0;i<count;i++)multipart[formId+'[attachment]['+i+']']={name:'note'+i+'.txt',mimeType:'text/plain',buffer:Buffer.alloc(size,65)};
    const r=await context.request.post(url+'/submit?bucket=multi-'+Math.random(),{multipart});assert.equal(r.status(),400);assert.equal((await r.json()).sent,false);
  }
  for(let i=0;i<5;i++)assert.equal((await send({},'quota')).status,200);
  assert.equal((await send({},'quota')).status,429);
  await page.goto(url+'/outputs');assert.equal(await page.locator('iframe').count(),1);assert.equal(await page.locator('iframe[onload]').count(),0);assert.equal(await page.locator('a').count(),1);assert.equal(await page.locator('a[onclick],a[onmouseover],a[onfocus],img[onerror]').count(),0);assert.equal(await page.evaluate(()=>window.injected),undefined);
  assert.deepEqual(errors,[]);assert.ok(!serverLog.includes('Fatal error'),serverLog);
  console.log('Passed browser form, recipient/metadata tampering, CAPTCHA, CSRF, upload, quota, and XSS checks');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{
  if(browser)await browser.close();if(server)server.kill();
  // Keep fixture logs/data outside the site; only remove our verified temporary tree.
  const resolved=fs.realpathSync(temporary);const base=fs.realpathSync(os.tmpdir());
  if(path.dirname(resolved)===base && path.basename(resolved).startsWith('fc-field-tests-'))fs.rmSync(resolved,{recursive:true,force:true});
});
