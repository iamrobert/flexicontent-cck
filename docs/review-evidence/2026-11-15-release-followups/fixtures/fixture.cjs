'use strict';
// Trusted harness: boots an offline copy of the hemj5 site inside the sandbox container
// (MySQL 8 on loopback, PHP built-in server, FLEXIcontent master upgrade) and runs tests.
// Everything writable lives in /scratch (tmpfs); inputs are read-only.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');

const IN = '/fixture/inputs';
const H = '/fixture/harness';
const S = '/scratch';
const SITE = `${S}/site`;
const MYDATA = `${S}/mysql/data`;
const RUN = `${S}/run`;
const PORT = 8080;
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'release_fixture';
const PREFIX = 'fct_';

const t0 = Date.now();
const log = (...a) => console.log(`[fixture +${((Date.now() - t0) / 1000).toFixed(1)}s]`, ...a);
const childEnv = {
  PATH: '/usr/local/bin:/usr/bin:/bin', HOME: `${S}/home`, TMPDIR: `${S}/tmp`, LANG: 'C.UTF-8', TZ: 'UTC'
};

let phpLaunch=['/usr/bin/php'];
function sh(cmd, args, opts = {}) {
  if(cmd==='php'||cmd==='/usr/bin/php'){args=[...phpLaunch.slice(1),...args];cmd=phpLaunch[0];}

  const r = spawnSync(cmd, args, { encoding: 'utf8', maxBuffer: 64 << 20, timeout: 300000, env: childEnv, ...opts });
  if (r.error) throw r.error;
  if (r.status !== 0 && !opts.allowFail) {
    throw Error(`${path.basename(cmd)} ${args.slice(0, 4).join(' ')} -> ${r.status}\n${(r.stderr || '').slice(-4000)}\n${(r.stdout || '').slice(-3000)}`);
  }
  return opts.allowFail ? r : r.stdout;
}
const sleep = ms => new Promise(r => setTimeout(r, ms));
const sha256 = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const rand = n => crypto.randomBytes(n).toString('hex');

// ------------------------------------------------------------------ MySQL
const ROOT_SOCK = `${RUN}/mysqld.sock`;
function mysql(args, opts = {}) {
  // Relaxed sql_mode on connect, matching the Joomla mysqli driver, so setup inserts that
  // omit NOT NULL/no-default legacy columns behave as they do for the application.
  return sh('/usr/bin/mysql', ['--no-defaults', `--socket=${ROOT_SOCK}`, '-u', 'root', '--default-character-set=utf8mb4',
    `--init-command=SET SESSION sql_mode=''`, ...args], opts);
}
function decodeField(v) {
  if (v === 'NULL') return null;
  return v.replace(/\\(.)/g, (_, c) => ({ n: '\n', t: '\t', '0': '\0', '\\': '\\' })[c] ?? c);
}
function sql(query) {
  const out = mysql(['-N', '-B', DB, '-e', query]);
  return out.split('\n').filter(l => l !== '').map(l => l.split('\t').map(decodeField));
}
const q = v => (v === null || v === undefined ? 'NULL' : "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'");

async function startMysql() {
  for (const d of [`${S}/mysql`, MYDATA, RUN, `${S}/tmp`, `${S}/home`]) fs.mkdirSync(d, { recursive: true, mode: 0o700 });
  fs.rmSync(MYDATA, { recursive: true, force: true });
  const common = ['--no-defaults', `--datadir=${MYDATA}`, '--basedir=/usr', '--lc-messages-dir=/usr/share/mysql',
    '--innodb-use-native-aio=0', `--tmpdir=${S}/tmp`];
  sh('/usr/sbin/mysqld', [...common, '--initialize-insecure', `--log-error=${RUN}/mysqld-init.err`], { timeout: 180000 });
  log('mysql datadir initialised');
  const proc = spawn('/usr/sbin/mysqld', [...common,
    `--socket=${ROOT_SOCK}`, `--pid-file=${RUN}/mysqld.pid`, '--port=3306', '--bind-address=127.0.0.1',
    '--mysqlx=OFF', '--skip-log-bin', '--innodb-buffer-pool-size=256M', '--innodb-flush-log-at-trx-commit=2',
    '--performance-schema=OFF', '--secure-file-priv=NULL', `--log-error=${RUN}/mysqld.err`,
    '--max-connections=80', '--table-open-cache=1200', '--skip-name-resolve'
  ], { env: childEnv, stdio: 'ignore' });
  proc.on('exit', code => log('mysqld exited', code));
  for (let i = 0; i < 120; i++) {
    if (fs.existsSync(ROOT_SOCK) && mysql(['-e', 'SELECT 1'], { allowFail: true }).status === 0) { log('mysql ready'); return proc; }
    await sleep(500);
  }
  throw Error('mysqld did not start: ' + (fs.existsSync(`${RUN}/mysqld.err`) ? fs.readFileSync(`${RUN}/mysqld.err`, 'utf8').slice(-3000) : ''));
}

// ------------------------------------------------------------------ Site
function writeConfig(dbPassword, overrides = {}) {
  const cfg = {
    offline: false, offline_message: 'Offline', display_offline_message: 1, offline_image: '',
    sitename: 'hemj5 offline fixture', editor: 'none', captcha: '0', list_limit: 20, access: 1, frontediting: 0,
    debug: false, debug_lang: false, debug_lang_const: true,
    dbtype: 'mysqli', host: '127.0.0.1:3306', user: 'joomla', password: dbPassword, db: DB, dbprefix: PREFIX,
    dbencryption: 0, dbsslverifyservercert: false, dbsslkey: '', dbsslcert: '', dbsslca: '', dbsslcipher: '',
    // live_site is required: PHP's built-in server reports PHP_SAPI 'cli-server', which
    // Joomla's Uri::base() treats as CLI and then derives a doubled /administrator path.
    force_ssl: 0, live_site: BASE, secret: rand(16), gzip: false, error_reporting: 'maximum', helpurl: '',
    offset: 'UTC', mailonline: false, mailer: 'mail', mailfrom: 'fixture@example.invalid', fromname: 'Fixture',
    massmailoff: true, replyto: '', replytoname: '', sendmail: '/usr/sbin/sendmail',
    smtpauth: false, smtpuser: '', smtppass: '', smtphost: 'localhost', smtpsecure: 'none', smtpport: 25,
    caching: 0, cache_handler: 'file', cachetime: 15, cache_platformprefix: false,
    MetaDesc: '', MetaAuthor: true, MetaVersion: false, robots: 'noindex, nofollow',
    sef: true, sef_rewrite: false, sef_suffix: false, unicodeslugs: false, feed_limit: 10, feed_email: 'none',
    log_path: `${SITE}/administrator/logs`, tmp_path: `${SITE}/tmp`,
    lifetime: 60, session_handler: 'database', shared_session: false, session_metadata: true,
    behind_loadbalancer: false, cors: false, cors_allow_origin: '*', cors_allow_headers: 'Content-Type,X-Joomla-Token',
    cors_allow_methods: '', proxy_enable: false, proxy_host: '', proxy_port: '', proxy_user: '', proxy_pass: '',
    cookie_domain: '', cookie_path: '', asset_id: 1, ...overrides
  };
  const php = v => (typeof v === 'boolean' ? (v ? 'true' : 'false') : typeof v === 'number' ? String(v) : "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'");
  const body = Object.entries(cfg).map(([k, v]) => `\tpublic $${k} = ${php(v)};`).join('\n');
  fs.writeFileSync(`${SITE}/configuration.php`, `<?php\nclass JConfig {\n${body}\n}\n`);
}

function cli(args, opts = {}) {
  return sh('/usr/bin/php', ['-d', 'memory_limit=1024M', '-d', 'log_errors=1', '-d', `error_log=${RUN}/php-error.log`, `${SITE}/cli/joomla.php`, ...args], { cwd: SITE, timeout: 600000, ...opts });
}

async function startPhpServer() {
  const ini = ['memory_limit=512M', 'max_execution_time=300', 'upload_max_filesize=32M', 'post_max_size=40M',
    `session.save_path=${S}/tmp`, `sys_temp_dir=${S}/tmp`, `upload_tmp_dir=${S}/tmp`, 'log_errors=1',
    `error_log=${RUN}/php-error.log`, 'display_errors=0', 'error_reporting=32767', 'opcache.enable_cli=0', 'opcache.enable=0', 'opcache.validate_timestamps=1'];
  const args = [...ini.flatMap(d => ['-d', d]), '-S', `127.0.0.1:${PORT}`, '-t', SITE, H+'/router.php'];
  const proc = spawn(phpLaunch[0], [...phpLaunch.slice(1),...args], { cwd: SITE, env: { ...childEnv, PHP_CLI_SERVER_WORKERS: '6' }, stdio: ['ignore', 'ignore', 'pipe'] });
  let err = '';
  proc.stderr.on('data', d => { err = (err + d).slice(-4000); });
  proc.on('exit', code => log('php server exited', code));
  for (let i = 0; i < 120; i++) {
    try {
      const res = await fetch(`${BASE}/index.php`, { redirect: 'manual' });
      if (res.status < 500) { log('php server ready, front page status', res.status); return proc; }
      log('front page status', res.status);
    } catch { /* not up yet */ }
    await sleep(500);
  }
  throw Error('PHP server did not start: ' + err);
}

async function adminLogin(page, user) {
  await page.goto(`${BASE}/administrator/index.php`);
  await page.fill('#mod-login-username', user.username);
  await page.fill('#mod-login-password', user.password);
  await Promise.all([page.waitForNavigation({ timeout: 120000 }), page.click('#btn-login-submit')]);
  await page.waitForLoadState('load');
  if (await page.locator('#mod-login-username').count()) {
    const msg = (await page.locator('#system-message-container').innerText({ timeout: 5000 }).catch(() => '')).replace(/\s+/g, ' ');
    throw Error(`Admin login failed for ${user.username}: url=${page.url().replace(BASE, '')} msg=${msg.slice(0, 600)}`);
  }
}

async function installFromFolder(admin, dir) {
  const browser = await require('playwright').chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    page.setDefaultTimeout(300000);
    await adminLogin(page, admin);
    await page.goto(`${BASE}/administrator/index.php?option=com_installer&view=install`);
    if (!(await page.locator('#install_directory').count())) {
      const body = (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 1500);
      throw Error(`Install-from-folder form missing. url=${page.url().replace(BASE, '')} title=${await page.title()} text=${body}`);
    }
    await page.evaluate(d => { document.getElementById('install_directory').value = d; }, dir);
    await Promise.all([page.waitForNavigation({ timeout: 300000 }), page.evaluate(() => window.Joomla.submitbuttonfolder())]);
    const text = await page.locator('#system-message-container').innerText({ timeout: 10000 }).catch(() => '');
    if (/error|fail/i.test(text) && !/success/i.test(text)) throw Error('FLEXIcontent install failed: ' + text.slice(0, 2000));
    await runFcPostinstall(page);
    return text;
  } finally { await browser.close(); }
}

// FLEXIcontent's dashboard lists post-install tasks after an upgrade; each pending task is a
// link that runs an AJAX task. Click them in listed order, reload, and repeat until none remain.
const FC_TASK_IDS = ['existfields', 'existcpfields', 'existmenuitems', 'existtype', 'publishplugins', 'existcats', 'langsynced',
  'existdbindexes', 'existversions', 'existversionsdata', 'existauthors', 'cachethumb', 'itemcountingdok', 'deprecatedfiles',
  'oldfieldsdata', 'missingversion', 'initialpermission'];
async function runFcPostinstall(page) {
  let pending = [];
  for (let pass = 1; pass <= 4; pass++) {
    await page.goto(`${BASE}/administrator/index.php?option=com_flexicontent`);
    pending = await page.evaluate(ids => [...document.querySelectorAll('a.fc_button[id]')].map(a => a.id).filter(id => ids.includes(id)), FC_TASK_IDS);
    if (!pending.length) { log(`FLEXIcontent post-install tasks complete after ${pass - 1} pass(es)`); return; }
    for (const id of pending) {
      const respP = page.waitForResponse(r => r.url().includes('format=raw') && r.url().includes('task=flexicontent.'), { timeout: 300000 });
      await page.click('#' + id);
      const r = await respP;
      log(`fc task ${id}: ${r.status()} ${(await r.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120)}`);
    }
  }
  throw Error('FLEXIcontent post-install tasks still pending: ' + pending.join(','));
}

const children = [];
function stopChildren() {
  for (const c of children) { try { c.kill('SIGKILL'); } catch { /* already gone */ } }
}


function report(test, status, observations) {
  const json = JSON.stringify({test,status,observations});
  if (json.length > 60000) throw Error('Result exceeds cap');
  console.log('FIXTURE_RESULT='+Buffer.from(json).toString('base64'));
}
async function run(payload) {
  let failures=0;
  try {
    const manifest=JSON.parse(fs.readFileSync(IN+'/manifest.json','utf8'));
    for(const entry of Object.values(manifest)) {
      if(sha256(IN+'/'+entry.file)!==entry.sha256) throw Error('Input changed '+entry.file);
    }
    if(payload.options.php){
      const version=String(payload.options.php).replace('.','');
      if(!['82','84'].includes(version))throw Error('Unsupported PHP fixture toolchain');
      for(const row of JSON.parse(fs.readFileSync(IN+'/toolchain-files.json','utf8'))){
        if(sha256(IN+'/'+row.file)!==row.sha256)throw Error('Toolchain changed '+row.file);
      }
      const root=IN+'/php'+version+(payload.options.phpGd?'gd':'');
      if(payload.options.phpGd){for(const row of JSON.parse(fs.readFileSync(IN+'/php'+version+'gd-files.json','utf8')))if(sha256(IN+'/'+row.file)!==row.sha256)throw Error('GD toolchain changed '+row.file);}
      phpLaunch=[root+'/loader','--library-path',root+'/lib/x86_64-linux-gnu:'+root+'/usr/lib/x86_64-linux-gnu',root+'/usr/local/bin/php','-n','-d','extension='+root+'/extensions/mysqli.so'];
      if(payload.options.phpGd){const ext=root+'/usr/local/lib/php/extensions/no-debug-non-zts-'+(version==='82'?'20220829':'20240924')+'/';phpLaunch=[root+'/loader','--library-path',root+'/lib/x86_64-linux-gnu:'+root+'/usr/lib/x86_64-linux-gnu',root+'/usr/local/bin/php','-n','-d','extension='+ext+'mysqli.so','-d','extension='+ext+'gd.so'];}
    }
    for(const name of payload.tests) {
      log('test',name);
      try {
        const result=await require(H+'/tests/'+name+'.cjs').run({
          IN,H,S,SITE,DB,PREFIX,BASE,sh,sql,q,mysql,childEnv,children,manifest,
          startMysql,writeConfig,startPhpServer,adminLogin,installFromFolder,runFcPostinstall,cli,rand,sleep,log,sha256,
          options:payload.options||{},report
        });
        report(name,result.status,result.observations);
        if(result.status!=='pass') failures++;
      } catch(e) {
        failures++;
        const logs={};
        for(const f of ['mysqld.err','php-error.log']) if(fs.existsSync(RUN+'/'+f)) logs[f]=fs.readFileSync(RUN+'/'+f,'utf8').slice(-2500);
        report(name,'error',{message:String(e.stack||e).slice(0,5000),logs});
      }
    }
  } catch(e) { failures++;report('preflight','error',{message:String(e.stack||e).slice(0,5000)}); }
  finally { stopChildren(); }
  log('completed',payload.tests.length,'tests;',failures,'failed');
  await new Promise(resolve=>process.stdout.write('',resolve));
  process.exit(failures?2:0);
}
module.exports={run};
