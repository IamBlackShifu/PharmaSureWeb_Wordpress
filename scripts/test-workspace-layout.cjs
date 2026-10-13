/* Real Chromium checks using the production app/CSS and deterministic API fixtures. */
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),os=require('node:os'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),out=path.join(root,'artifacts/screenshots/workspace-typography');
const config={appUrl:'/app',apiUrl:'/api/',theme:'dark',textSize:'standard',licence:{status:'grace',grace_until:'2026-10-18 08:52:02'},permissions:Object.fromEntries(['overview','pos','inventory','clinical','claims','reports','offline','account','manage_tenant'].map(x=>[x,true])),branches:[{id:1,name:'Main Branch'}],scope:{branch_id:1},currentUser:{role:'owner',displayName:'Test owner'},brandMarkUrl:'/mark.svg'};
const overview={scope:{pharmacy:'Greenwood Pharmacy',branch:'Main Branch'},metrics:{active_medicines:4,units_available:11965,low_stock:1,expiring_90_days:1},signals:{low_stock:1,expiring_90_days:1},movements:Array.from({length:12},(_,i)=>({created_at:'2026-10-12 10:22:26',name:'Amoxycillin',sku:'109',movement_type:'receipt',quantity_delta:10000,reference_type:'stock_receipt',reference_id:i+1}))};
const server=http.createServer((req,res)=>{
 const files={'/app.js':'wp-content/plugins/pharmasure-core/assets/js/app.js','/style.css':'wp-content/plugins/pharmasure-core/assets/css/crisp-theme.css','/mark.svg':'wp-content/plugins/pharmasure-core/assets/brand/pharmasure-mark.svg'};
 if(files[req.url]){res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':req.url.endsWith('.js')?'text/javascript':'image/svg+xml');res.end(fs.readFileSync(path.join(root,files[req.url])));return;}
 if(req.url.startsWith('/api/')){res.setHeader('Content-Type','application/json');res.end(JSON.stringify(req.url.includes('overview')?overview:{items:[]}));return;}
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/style.css"></head><body><div id="pharmasure-app"></div><script>window.PharmaSureConfig=${JSON.stringify(config)}</script><script src="/app.js"></script></body></html>`);
});
(async()=>{
 fs.mkdirSync(out,{recursive:true});const profile=fs.mkdtempSync(path.join(os.tmpdir(),'pharmasure-layout-'));
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=spawn('C:/Users/DELL/AppData/Local/ms-playwright/chromium-1200/chrome-win64/chrome.exe',['--headless=new','--no-sandbox','--disable-gpu','--remote-debugging-port=9361',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket;
 try{
  let targets;for(let i=0;i<50;i++){try{targets=await(await fetch('http://127.0.0.1:9361/json/list')).json();break;}catch{await new Promise(r=>setTimeout(r,200));}}
  assert.ok(targets,'Chromium started');socket=new WebSocket(targets.find(x=>x.type==='page').webSocketDebuggerUrl);await new Promise(r=>socket.addEventListener('open',r,{once:true}));
  let id=0;const pending=new Map();socket.addEventListener('message',e=>{const msg=JSON.parse(e.data);if(pending.has(msg.id)){const [resolve,reject]=pending.get(msg.id);pending.delete(msg.id);msg.error?reject(Error(JSON.stringify(msg.error))):resolve(msg.result);}});
  const cdp=(method,params={})=>new Promise((resolve,reject)=>{pending.set(++id,[resolve,reject]);socket.send(JSON.stringify({id,method,params}));});
  const evaluate=async expression=>{const r=await cdp('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;};
  await cdp('Page.enable');await cdp('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/app/overview`});
  for(let i=0;i<50;i++){if(await evaluate('!!document.querySelector(".ps-ledger")'))break;await new Promise(r=>setTimeout(r,100));}
  const results=[];
  assert.ok(await evaluate('document.querySelector(".ps-licence-notice")?.textContent.includes("2026-10-18")'),'workspace explains grace and exact renewal deadline');
  for(const theme of ['dark','light'])for(const width of [1920,1440,1024,768,390])for(const size of ['standard','large','extra-large']){
   await cdp('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
   await evaluate(`(()=>{document.documentElement.dataset.theme=${JSON.stringify(theme)};const sizeSelect=document.querySelector('[aria-label="Text size"]');sizeSelect.value=${JSON.stringify(size)};sizeSelect.dispatchEvent(new Event('change'));})()`);
   const check=await evaluate(`(()=>{const header=document.querySelector('.ps-global-header').getBoundingClientRect(),workspace=document.querySelector('.ps-workspace').getBoundingClientRect();return {overflow:document.documentElement.scrollWidth>innerWidth,overlap:header.bottom>workspace.top+1,clipped:[...document.querySelectorAll('.ps-global-header button,.ps-global-header select,.ps-global-header a')].some(e=>e.getBoundingClientRect().bottom>header.bottom+1),base:getComputedStyle(document.documentElement).fontSize,title:getComputedStyle(document.querySelector('h1')).fontSize};})()`);
   assert.equal(check.overflow,false,`${theme} ${width} ${size}: page overflow`);assert.equal(check.overlap,false,`${width} ${size}: header overlaps workspace`);assert.equal(check.clipped,false,`${width} ${size}: header clips controls`);
   if(size==='standard'){assert.equal(check.base,'14px');assert.ok(Math.abs(parseFloat(check.title)-25)<.01,'Normal restores original 25px heading');}
   results.push({theme,width,size,...check});
   if(theme==='dark'&&[1440,390].includes(width)){const shot=await cdp('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,`${width}-${size}.png`),Buffer.from(shot.data,'base64'));}
  }
  fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));console.log(`PASS: ${results.length} real-browser layouts; original Normal typography, both themes, no page overflow or header overlap/clipping.`);
 }finally{socket?.close();browser.kill();server.close();/* Unique temporary profile is left for OS cleanup while Chromium exits. */}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
