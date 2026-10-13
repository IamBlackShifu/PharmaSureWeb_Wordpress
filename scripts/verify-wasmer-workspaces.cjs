/* Credentials are passed only through the child environment and never persisted or printed. */
const {execFileSync}=require('node:child_process'),path=require('node:path');
try {
 const secrets=JSON.parse(execFileSync('C:/Users/DELL/.wasmer/bin/wasmer.exe',['app','secret','export','--app','samkan60/pharmasure','--format','json','--non-interactive'],{encoding:'utf8',stdio:['ignore','pipe','ignore']}));
 const env={...process.env,...Object.fromEntries(secrets.map(s=>[s.name,s.value])),WORDPRESS_MULTISITE:'true',WORDPRESS_ALLOW_MULTISITE:'true',WORDPRESS_SUBDOMAIN_INSTALL:'false',WORDPRESS_DOMAIN_CURRENT_SITE:'pharmasure.wasmer.app'};
 const result=execFileSync('C:/xampp/php/php.exe',[path.join(__dirname,'verify-wasmer-workspaces.php'),...(process.argv.includes('--platform')?['--platform']:[])],{env,encoding:'utf8',stdio:['ignore','pipe','pipe'],timeout:90000});
 const parsed=JSON.parse(result);
 if(process.argv.includes('--save')){
  const fs=require('node:fs');const output=path.join(__dirname,process.argv.includes('--platform')?'../artifacts/platform-admin-verification.json':'../artifacts/greenwood-license-verification.json');
  fs.writeFileSync(output,JSON.stringify({...parsed,verified_at_utc:new Date().toISOString()},null,2));
 }
 console.log(result);
} catch(error){
 // PHP produces only sanitized diagnostic JSON; never print command/environment or stderr.
 if(error.stdout?.toString().trim().startsWith('{'))console.error(error.stdout.toString());
 else console.error('Read-only workspace verification could not finish; credentials suppressed.');
 process.exitCode=1;
}
