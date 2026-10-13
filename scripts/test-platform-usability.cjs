/* Exercise the actual app module through an isolated DOM boundary. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
class Element {
 constructor(tag){this.tagName=tag;this.children=[];this.attrs={};this.events={};this.value='';this.textContent='';this.dataset={};this.className='';this.hidden=false;this.disabled=false;}
 setAttribute(key,value){this.attrs[key]=String(value);if(key==='value')this.value=String(value);if(key==='class')this.className=String(value);if(key==='disabled')this.disabled=true;}
 removeAttribute(key){delete this.attrs[key];}
 append(...items){for(const item of items){this.children.push(item);if(item instanceof Element)item.parent=this;}}
 replaceChildren(...items){this.children=[];this.append(...items);}
 addEventListener(key,fn){(this.events[key]??=[]).push(fn);}
 async trigger(key){for(const fn of this.events[key]||[])await fn({type:key,preventDefault(){}});}
 closest(tag){for(let item=this;item;item=item.parent)if(item.tagName===tag)return item;return null;}
 querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
 querySelectorAll(selector){return this.children.filter(x=>x instanceof Element).flatMap(x=>[x,...descendants(x)]).filter(x=>selector.split(',').some(s=>matches(x,s.trim())));}
 get options(){return this.children.filter(x=>x.tagName==='option');}
 remove(){if(this.parent)this.parent.children=this.parent.children.filter(x=>x!==this);}
 get firstElementChild(){return this.children.find(x=>x instanceof Element);}
 focus(){} showModal(){} close(){}
}
function descendants(e){return e.children.filter(x=>x instanceof Element).flatMap(x=>[x,...descendants(x)]);}
function matches(e,s){if(s.startsWith('.'))return e.className.split(' ').includes(s.slice(1));const m=s.match(/^\[([^=]+)=(?:"?)([^"\]]+)(?:"?)\]$/);if(m)return e.attrs[m[1]]===m[2];return e.tagName===s;}
const source=fs.readFileSync(path.join(__dirname,'../wp-content/plugins/pharmasure-core/assets/js/app.js'),'utf8').replace(/\}\)\(\);\s*$/,`globalThis.testApp={routes,state,routeFromLocation,renderHeader,workflowBody,lineEditor,formatDate,renderTextSize,openClinicalPatientDialog,inventoryDashboard};})();`);
function app(permissions,url='/app',extra={}){
 const document={querySelector:()=>null,createElement:tag=>new Element(tag),createTextNode:text=>String(text),documentElement:{dataset:{}},addEventListener(){},body:new Element('body'),hidden:false};
 const calls=[],window={PharmaSureConfig:{permissions,appUrl:'/app',apiUrl:'/api/',branches:[],theme:'dark',textSize:'standard',...extra},location:{pathname:url,search:'',origin:'http://local.test'},setInterval(){},setTimeout(){},addEventListener(){},scrollTo(){}};
 const context={window,document,Node:Element,URL,URLSearchParams,Intl,Date,console,fetch:async(url,options)=>{calls.push([url,options]);return{ok:true,json:async()=>({})};}};
 vm.runInNewContext(source,context);return{...context.testApp,document,window,calls};
}
(async()=>{
 let a=app({inventory:true,pos:true});assert.deepEqual(Array.from(a.routes,x=>x[0]),['pos','inventory']);assert.equal(a.state.route,'pos','cashier opens directly in POS');
 a=app({inventory:true,clinical:true},'/app/account');assert.equal(a.state.route,'clinical','unauthorized direct URL falls back to clinical');
 let header=a.renderHeader(),links=header.querySelector('nav').children;assert.deepEqual(links.map(x=>x.textContent),['Inventory','Clinical'],'header omits unauthorized modules');
 assert.equal(header.querySelectorAll('a').some(x=>x.textContent==='Platform admin'),false,'staff have no platform link');
 const platformUrl='/wp-admin/network/admin.php?page=pharmasure-admin';
 const platformHeader=app({inventory:true},'/app/inventory',{platformAdminUrl:platformUrl}).renderHeader();
 assert.equal(platformHeader.querySelectorAll('a').find(x=>x.textContent==='Platform admin').attrs.href,platformUrl,'network administrators can return to platform administration');
 a=app({overview:true,pos:true,inventory:true,manage_tenant:true});assert.equal(a.state.route,'overview','manager opens in overview');
 a=app({reports:true,inventory:true},'/app/clinical');assert.equal(a.state.route,'inventory');
 const catalogue=a.inventoryDashboard({view:'catalogue',scope:{trading_name:'Test',branch_name:'Main',currency:'USD'},summary:{products:1,units:3,low_stock:1,expiry:0},facts:{medicines:1},permissions:{},rows:[{id:2,name:'Medicine',sku:'D1',quantity_available:3,selling_price_minor:200}]});assert.equal(catalogue.querySelector('thead').querySelector('tr').children.length,5,'selling catalogue has five relevant columns');assert.equal(catalogue.querySelector('tbody').querySelector('tr').children.length,5,'catalogue rows align with headers');assert.equal(catalogue.querySelector('.ps-inventory-command-bar'),null,'read-only users do not see stock command layer');
 assert.equal(a.formatDate('2026-10-12'),'2026-10-12');assert.equal(a.formatDate(null),'—');
 a=app({inventory:true,manage_tenant:true},'/app/inventory',{markupPercent:25});
 const body=a.workflowBody('receipt',null,{suppliers:[],drugs:[{id:2,sku:'D1',name:'Medicine',cost_price_minor:199,selling_price_minor:300}],markup_percent:25});const form=new Element('form');form.append(body);
 const line=body.querySelector('.ps-workflow-line'),cost=line.querySelector('[name=line_unit_cost]'),price=line.querySelector('[name=line_selling_price]'),markup=body.querySelector('[name=markup_percent]');
 cost.value='1.99';await cost.trigger('input');assert.equal(price.value,'2.49','cost updates rounded suggested price');
 price.value='2.75';await price.trigger('input');cost.value='3';await cost.trigger('input');assert.equal(price.value,'2.75','manual price survives cost changes');
 markup.value='50';await markup.trigger('input');assert.equal(price.value,'2.75','manual price survives markup changes');
 await line.children.find(x=>x.tagName==='button'&&x.textContent==='Suggest price').trigger('click');assert.equal(price.value,'4.50','suggest restores automatic mode');
 const size=a.renderTextSize().querySelector('select');assert.equal(size.options[0].textContent,'Normal (14px)','original typography is the default choice');assert.deepEqual(size.options.map(x=>x.value),['standard','large','extra-large'],'existing saved choices remain compatible');size.value='large';await size.trigger('change');assert.equal(a.document.documentElement.dataset.textSize,'large');assert.equal(JSON.parse(a.calls[0][1].body).text_size,'large','size is persisted through API');
 const expiry=a.workflowBody('batch',{sku:'D1',batch_number:'B1',status:'expired',expiry_date:'2020-01-01'},{batches:[]});const disposition=expiry.querySelector('[name=status]');assert.deepEqual(disposition.options.filter(x=>x.value).map(x=>x.value),['disposed']);assert.equal(expiry.querySelector('[name=disposal_reference]').required,true,'disposal evidence becomes required');
 const active=a.workflowBody('batch',{sku:'D1',batch_number:'B2',status:'active',expiry_date:'2099-01-01'},{});assert.equal(active.querySelector('[name=disposal_method]').closest('label').hidden,true,'disposal details stay out of unrelated stock actions');
 a.state.clinicalData={};a.openClinicalPatientDialog();const patientForm=a.document.body.querySelector('form');const inputs=patientForm.querySelectorAll('input');assert.deepEqual(inputs.filter(x=>'required'in x.attrs).map(x=>x.attrs.name),['first_name','last_name'],'patient registration requires only names');
 console.log('PASS: role-filtered navigation, landing routes, ISO dates, price suggestion/override, saved text size, disposal controls and minimum patient fields');
})().catch(error=>{console.error(error);process.exitCode=1;});
