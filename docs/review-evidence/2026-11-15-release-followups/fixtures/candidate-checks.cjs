'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
module.exports.run=async c=>{
 const root=c.S+'/candidate';fs.mkdirSync(root);c.sh('tar',['-xf',c.IN+'/candidate.tar','-C',root]);
 const files=[];function visit(dir){for(const e of fs.readdirSync(dir,{withFileTypes:true})){const f=path.join(dir,e.name),relative=path.relative(root,f);if(relative==='site/librairies/json')continue;if(e.isDirectory())visit(f);else if(f.endsWith('.php'))files.push(f);}}visit(root);
 const lintErrors=[];for(const file of files){const r=c.sh('php',['-d','error_reporting=-1','-l',file],{allowFail:true});if(r.status||(r.stdout+r.stderr).match(/(?:Warning|Deprecated|Fatal error|Parse error):/))lintErrors.push({file:path.relative(root,file),exit:r.status,output:(r.stdout+r.stderr).slice(-900)});}
 const scripts=['tests/filemanager-frontend-guard.php','_tools/tests/fileeditor.php','_tools/tests/notificationmail-conditions.php','_tools/tests/notificationmail-template-loading.php','_tools/tests/notificationmail-template-loading-j6.php','_tools/tests/file-index-custom-properties.php','_tools/tests/category-association-propagation.php'];
 const results=[];for(const script of scripts){const r=c.sh('php',['-d','error_reporting=-1',script],{cwd:root,allowFail:true,timeout:60000});results.push({script,exit:r.status,output:(r.stdout+r.stderr).slice(-1500)});}
 const extra=c.sh('php',['-d','error_reporting=-1',c.H+'/nullable-release-values.php',root],{allowFail:true});results.push({script:'external nullable-values control',exit:extra.status,output:(extra.stdout+extra.stderr).slice(-1500)});
 const remote=c.sh('python3',[root+'/_tools/tests/remote-size/run.py','--php','php'],{cwd:root,allowFail:true,timeout:120000});results.push({script:'loopback remote-size',exit:remote.status,output:(remote.stdout+remote.stderr).slice(-1800)});
 return {status:!lintErrors.length&&results.every(x=>x.exit===0)?'pass':'fail',observations:{commit:c.manifest.candidate.commit,php:c.sh('php',['-v']).split('\n')[0],lintCount:files.length,lintErrors,results}};
};
