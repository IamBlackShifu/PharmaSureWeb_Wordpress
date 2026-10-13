/* Public endpoints must require authentication; live assets must match the release. */
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
(async()=>{
 const base='https://pharmasure.wasmer.app',results=[];
 for(const page of ['pharmasure-admin','pharmasure-tenants','pharmasure-licenses','pharmasure-add-tenant']){
  const response=await fetch(`${base}/wp-admin/network/admin.php?page=${page}`,{redirect:'manual'});
  assert.equal(response.status,302);assert.ok(response.headers.get('location')?.includes('/wp-login.php'));
  results.push({page,status:response.status,requires_login:true});
 }
 for(const asset of ['wp-content/plugins/pharmasure-core/assets/js/app.js','wp-content/plugins/pharmasure-core/assets/css/crisp-theme.css','wp-content/plugins/pharmasure-tenancy/assets/css/platform-admin.css']){
  const response=await fetch(`${base}/${asset}?verify=v18`);assert.equal(response.status,200);
  const hash=data=>crypto.createHash('sha256').update(data).digest('hex');
  assert.equal(hash(Buffer.from(await response.arrayBuffer())),hash(fs.readFileSync(path.join(__dirname,'..',asset))));
  results.push({asset,matches_release:true});
 }
 fs.writeFileSync(path.join(__dirname,'../artifacts/platform-public-verification.json'),JSON.stringify({verified_at_utc:new Date().toISOString(),results},null,2));
 console.log('PASS: four platform pages require sign-in; three live assets match the release.');
})().catch(error=>{console.error(error.message);process.exitCode=1;});
