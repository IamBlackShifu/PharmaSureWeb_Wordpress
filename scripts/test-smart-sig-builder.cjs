/* Small DOM harness tests the actual reusable builder without external packages. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor(tag) { this.tagName=tag; this.children=[]; this.attrs={}; this.events={}; this.value=''; this.checked=false; this.disabled=false; this.hidden=false; this.textContent=''; this.classList={add:()=>{}}; }
  setAttribute(k,v) {this.attrs[k]=String(v); if(k==='value')this.value=String(v);}
  removeAttribute(k) {delete this.attrs[k];}
  append(...items) {for(const item of items){this.children.push(item);if(item instanceof Element)item.parent=this;}}
  replaceChildren(...items) {this.children=[];this.append(...items);}
  addEventListener(k,fn) {(this.events[k]??=[]).push(fn);}
  dispatchEvent(e) {for(const fn of this.events[e.type]||[])fn(e);return true;}
  closest(tag) {let e=this;while(e){if(e.tagName===tag)return e;e=e.parent;}return null;}
  focus() {}
  get childElementCount() {return this.children.length;}
  get isConnected() {return true;}
}
const window = {};
const context = {window, document:{createElement:tag=>new Element(tag)},Event,setTimeout,clearTimeout,console};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../wp-content/plugins/pharmasure-core/assets/js/smart-sig.js'),'utf8'),context);
function all(e){return[e,...e.children.filter(x=>x instanceof Element).flatMap(all)];}
function control(host,label){const item=all(host).find(e=>e.tagName==='label'&&e.children[0]?.textContent===label);assert.ok(item,`control ${label}`);return item.children[1];}
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const tick=()=>sleep(0);
async function main(){
 const host=new Element('div'),drug=new Element('select'),quantity=new Element('input');drug._sigProducts=[{id:1,dosage_form:'tablet'}];
 const pending=[];const calls=[];
 const library={settings:{enabled:true,personal_shortcuts:false},definitions:[{id:1,code:'BD',aliases:['BID'],category:'frequency',value:'daily_2',status:'active',approval_status:'approved',description:'Twice daily',priority:0,version:1},{id:2,code:'OD',aliases:[],ambiguous:true,category:'frequency',status:'active',approval_status:'approved',description:'Once daily'}],templates:[],frequencies:{daily_2:['twice daily',2]},routes:{oral:['Take','by mouth']},timing:['with food'],preferences:{favourites:[],recent:[],counts:{}},permissions:{manage:false,approve:false}};
 const api=async(path,opts)=>{calls.push([path,opts]);if(path==='sig/library')return library;if(path.startsWith('sig/profile'))return{profile:{units:['tablet'],routes:['oral']}};if(path==='sig/preferences')return library.preferences;if(path==='sig/preview')return new Promise((resolve,reject)=>pending.push({resolve,reject,payload:JSON.parse(opts.body)}));throw Error(path);};
 const builder=window.PharmaSureSmartSig.mount(host,{api,drug,quantity});await tick();
 const enabled=control(host,'Use Smart SIG Builder');assert.equal(enabled.disabled,false,'approved configuration makes builder available');
 drug.value='1';drug.dispatchEvent(new Event('change'));await tick();
 const unit=control(host,'Dosage unit'),route=control(host,'Route (must match medicine)');assert.equal(route.children.length,2,'only compatible routes are available');
 enabled.checked=true;enabled.dispatchEvent(new Event('change'));
 const dose=control(host,'Dose (decimal or fraction)'),frequency=control(host,'Frequency'),duration=control(host,'Duration in days (blank if not specified)'),original=control(host,'Original prescription directions');
 dose.value='1';unit.value='tablet';route.value='oral';frequency.value='daily_2';duration.value='5';original.value='Take one tablet twice daily for five days.';dose.dispatchEvent(new Event('input'));
 assert.throws(()=>builder.value(),/Wait for valid/,'cannot save while preview is pending');await sleep(340);
 const response={structured:{dose:'1',unit:'tablet',route:'oral',frequency:'daily_2',duration_days:5,definition_versions:[]},directions:'Take 1 tablet by mouth twice daily for 5 days.',quantity:'10.000',quantity_unit:'tablet',stock_unit:'tablets',manual_reason:null,maximum_theoretical_quantity:null,validation_scope:'Pharmacist review required.'};
 assert.equal(pending.length,1,'debounced changes make one server request');pending.shift().resolve(response);await tick();
 quantity.value='10';assert.equal(builder.value().verified_directions,response.directions,'server directions are retained in save payload');
 const directions=control(host,'Patient directions for pharmacist review');directions.value='Take two tablets daily.';directions.dispatchEvent(new Event('input'));assert.throws(()=>builder.value(),/Reconcile/,'edited directions require explicit reconciliation');
 control(host,'Reconciliation reason for edited patient directions').value='Pharmacist to reconcile with original prescription.';assert.equal(builder.value().verified_directions,'Take two tablets daily.','edited directions are preserved for pharmacist review');
 quantity.value='9';assert.throws(()=>builder.value(),/manual quantity/,'quantity disagreement blocks saving without reason');control(host,'Manual stock quantity / conversion verification reason').value='Partial supply reviewed.';assert.ok(builder.value(),'documented quantity override can enter pharmacist review');
 dose.value='2';dose.dispatchEvent(new Event('input'));await sleep(340);const stale=pending.shift();assert.ok(stale);dose.value='3';dose.dispatchEvent(new Event('input'));stale.resolve(response);await tick();assert.throws(()=>builder.value(),/Wait for valid/,'stale response cannot verify newer edits');await sleep(340);pending.shift().resolve({...response,structured:{...response.structured,dose:'3'},directions:'Take 3 tablets by mouth twice daily for 5 days.',quantity:'30.000'});await tick();
 const shorthand=control(host,'Shorthand SIG (approved codes only)');shorthand.value='1 B';shorthand.dispatchEvent(new Event('input'));const list=all(host).find(e=>e.attrs.role==='listbox');assert.equal(list.children.length,1,'autocomplete offers approved matching definition');
 let prevented=false;shorthand.dispatchEvent({type:'keydown',key:'ArrowDown',preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(shorthand.attrs['aria-activedescendant'],`${list.attrs.id}-0`,'keyboard selection updates accessible active descendant');
 shorthand.dispatchEvent({type:'keydown',key:'Tab',preventDefault(){}});assert.equal(shorthand.value,'1 BD ','Tab selects configured abbreviation');assert.throws(()=>builder.value(),/Wait for valid/,'autocomplete selection never finalizes directions');
 shorthand.value='1 O';shorthand.dispatchEvent(new Event('input'));assert.equal(list.children.length,0,'ambiguous abbreviations are excluded from suggestions');
 enabled.checked=false;enabled.dispatchEvent(new Event('change'));assert.equal(builder.value(),null,'legacy manual workflow remains available');
 const setupHost=new Element('div'),setupDrug=new Element('select'),setupQuantity=new Element('input');
 const setupLibrary={...library,settings:{enabled:false,personal_shortcuts:false},definitions:[],permissions:{manage:true,approve:true}};
 const setupCalls=[];
 const setupApi=async(path,opts)=>{setupCalls.push([path,opts]);if(path==='sig/library')return setupLibrary;if(path==='sig/settings'){setupLibrary.settings.enabled=true;return setupLibrary.settings;}throw Error(path);};
 window.PharmaSureSmartSig.mount(setupHost,{api:setupApi,drug:setupDrug,quantity:setupQuantity});await tick();
 const setupToggle=control(setupHost,'Use Smart SIG Builder');assert.equal(setupToggle.closest('label').hidden,true,'inactive checkbox is replaced by actionable setup');
 const getButton=(host,text)=>all(host).find(e=>e.tagName==='button'&&e.textContent===text);
 const launch=getButton(setupHost,'Set up Smart SIG');assert.ok(launch,'pharmacist can configure directly from intake');launch.dispatchEvent(new Event('click'));
 const activate=getButton(setupHost,'Activate and start using Smart SIG');activate.dispatchEvent(new Event('click'));await tick();
 assert.equal(setupCalls.filter(([p])=>p==='sig/settings').length,0,'setup cannot activate without attestation and rationale');
 control(setupHost,'Clinical acceptance note / reference').value='Workflow reviewed against local labels.';
 control(setupHost,'I have reviewed this workflow and approve Smart SIG use at this pharmacy').checked=true;
 activate.dispatchEvent(new Event('click'));await tick();await tick();
 assert.equal(setupToggle.disabled,false,'successful inline setup unlocks builder');assert.equal(setupToggle.checked,true,'setup immediately opens builder without losing intake');
 assert.equal(setupToggle.closest('label').hidden,false,'enabled builder toggle becomes available');
 assert.equal(setupCalls.some(([p])=>p==='sig/definitions'||p==='sig/seed'),false,'structured entry does not require automatic abbreviation approval');
 const payload=JSON.parse(setupCalls.find(([p])=>p==='sig/settings')[1].body);assert.equal(payload.clinical_acceptance,true);assert.equal(payload.personal_shortcuts,false);
 const staffHost=new Element('div');const staffLibrary={...setupLibrary,settings:{enabled:false},permissions:{manage:false,approve:false}};
 window.PharmaSureSmartSig.mount(staffHost,{api:async()=>staffLibrary,drug:new Element('select'),quantity:new Element('input')});await tick();
 assert.equal(getButton(staffHost,'Set up Smart SIG'),undefined,'ordinary prescription users cannot activate pharmacy settings');
 const recheck=getButton(staffHost,'Check if setup is complete');assert.ok(recheck,'ordinary users have an actionable refresh rather than a dead checkbox');
 staffLibrary.settings.enabled=true;recheck.dispatchEvent(new Event('click'));await tick();await tick();
 assert.equal(control(staffHost,'Use Smart SIG Builder').disabled,false,'staff can use pharmacy configuration after pharmacist activation');
 const adminHost=new Element('div');const adminLibrary={...library,permissions:{manage:true,approve:true},definitions:[{id:3,code:'TDS',value:'daily_2',description:'Twice daily',category:'frequency',aliases:[],routes:[],forms:[],status:'draft',version:1}]};
 await window.PharmaSureSmartSig.management(adminHost,async()=>adminLibrary);
 const review=getButton(adminHost,'Review');assert.ok(review);review.dispatchEvent(new Event('click'));
 const meaning=control(adminHost,'Frequency meaning');assert.equal(meaning.tagName,'select','abbreviation meaning uses plain-language choices');assert.equal(meaning.value,'daily_2','review preserves existing canonical meaning');
 assert.equal(control(adminHost,'Clinically reviewed and approved').checked,false,'review never prechecks clinical approval');
 assert.equal(control(adminHost,'Lifecycle').value,'active','review prepares activation for explicit confirmation');
 console.log('PASS: builder configuration, form compatibility, debounce, reconciliation, quantity overrides, stale responses, keyboard autocomplete, ambiguity and legacy fallback');
 console.log('PASS: inline activation, explicit acceptance, role boundaries, structured entry without dictionary setup, and staff configuration refresh');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
