'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
module.exports.run=async c=>{
 const observations={cases:[],note:'The corrected SQL stub is used only in disposable scratch. Application source is unchanged.'};
 for(const [label,archive]of [['master','before.tar'],['pr','candidate.tar']]){
  const root=c.S+'/category-'+label;fs.mkdirSync(root);c.sh('tar',['-xf',c.IN+'/'+archive,'-C',root]);
  const file=root+'/_tools/tests/category-association-propagation.php';
  const before=c.sh('php',[file],{allowFail:true});assert.notEqual(before.status,0);assert.match(before.stdout+before.stderr,/independent secondary flag/);
  const old="public function loadResult() { return preg_match('/asset_id FROM #__categories WHERE id = (\\d+)/', $this->query, $m) ? 100 + (int) $m[1] : 10; }";
  const correction="public function loadResult() { if (preg_match('/asset_id FROM #__categories WHERE id = (\\d+)/', $this->query, $m)) return 100 + (int) $m[1]; if (strpos($this->query, 'catid FROM #__content WHERE id = 2') !== false) return $this->target->catid; return 10; }";
  const source=fs.readFileSync(file,'utf8');assert(source.includes(old));fs.writeFileSync(file,source.replace(old,correction));
  const after=c.sh('php',[file],{allowFail:true});assert.equal(after.status,0,after.stdout+after.stderr);assert.match(after.stdout,/54/);
  observations.cases.push({source:label,originalExit:before.status,originalDiagnostic:(before.stdout+before.stderr).slice(-900),fixtureCorrectionExit:after.status,fixtureCorrectionOutput:after.stdout.trim()});
 }
 return {status:'pass',observations};
};
