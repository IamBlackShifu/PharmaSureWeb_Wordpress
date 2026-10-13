/* Read-only live tenant/licence diagnosis. Credentials remain in process memory. */
const {execFileSync}=require('node:child_process');
(async()=>{
 const db=JSON.parse(execFileSync('C:/Users/DELL/.wasmer/bin/wasmer.exe',['app','database','list','samkan60/pharmasure','--format','json'],{encoding:'utf8',stdio:['ignore','pipe','ignore']}))[0];
 const cookies=new Map();
 async function request(url,options={}){
  for(let i=0;i<10;i++){
   const response=await fetch(url,{...options,redirect:'manual',headers:{...options.headers,Cookie:[...cookies].map(([k,v])=>`${k}=${v}`).join('; ')},signal:AbortSignal.timeout(30000)});
   for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';')[0],index=pair.indexOf('=');cookies.set(pair.slice(0,index),pair.slice(index+1));}
   if(response.status>=300&&response.status<400){url=new URL(response.headers.get('location'),url).href;options={};continue;}
   return {response,html:await response.text(),url};
  }throw Error('Too many redirects');
 }
 const page=await request(db.db_explorer_url);
 const queryUrl=new URL(page.url);queryUrl.searchParams.delete('magiclogin');queryUrl.searchParams.set('sql','SELECT 1 AS probe');
 const sqlPage=await request(queryUrl.href);
 const tokenTag=[...sqlPage.html.matchAll(/<input\b[^>]*>/g)].map(x=>x[0]).find(x=>/name=["']token["']/.test(x));
 const token=tokenTag?.match(/value=["']([^"']*)/)[1];if(!token)throw Error('SQL CSRF token unavailable');
 async function query(sql){
  if(!/^\s*(SELECT|SHOW)\b/i.test(sql))throw Error('Read-only SQL required');
  const result=await request(queryUrl.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({query:sql,token,limit:'100',error_stops:'1'})});
  const tables=[...result.html.matchAll(/<table\b[\s\S]*?<\/table>/g)].map(x=>x[0].replace(/<[^>]*>/g,' ').replace(/\s+/g,' '));
  console.log(JSON.stringify({sql,tables}));return result.html;
 }
 await query('SELECT blog_id,domain,path,archived,deleted FROM wp_blogs');
 await query('SHOW TABLES LIKE "%ps_licences"');
 await query('SELECT id,trading_name,slug,status FROM wp_ps_tenants');
 await query('SELECT id,tenant_id,plan_id,status,activated_at,expires_at,grace_period_days FROM wp_ps_licences');
 await query('SELECT id,tenant_id,plan_id,status,activated_at,expires_at,grace_period_days FROM wp_3_ps_licences');
})().catch(()=>{console.error('Live database diagnosis failed (credentials suppressed).');process.exitCode=1;});
