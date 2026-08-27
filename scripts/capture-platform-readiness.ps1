param(
    [Parameter(Mandatory=$true)][string]$TenantUsername,
    [Parameter(Mandatory=$true)][string]$TenantPassword,
    [string]$RootUrl='http://localhost:8080',
    [string]$TenantSiteUrl='http://localhost:8080/greenlife-pharmacy',
    [string]$AppUrl='http://localhost:8080/app',
    [string]$OutputDirectory=(Join-Path $PSScriptRoot '..\artifacts\production-readiness\2026-08-26')
)

$ErrorActionPreference='Stop'
$browser='C:\Users\DELL\AppData\Local\ms-playwright\chromium-1200\chrome-win64\chrome.exe'
$out=[IO.Path]::GetFullPath($OutputDirectory); New-Item -ItemType Directory -Path $out -Force | Out-Null
$token=[guid]::NewGuid().ToString('N').Substring(0,10)
$adminUser="readinessadmin$token"; $adminEmail="$adminUser@evidence.test"; $adminPassword="Ready!Aa1$token"
$adminCreated=$false; $profile=Join-Path ([IO.Path]::GetTempPath()) ('pharmasure-platform-'+[guid]::NewGuid().ToString('N'))
$process=$null; $socket=$null; $sequence=0; $port=9358

function Invoke-Cdp{param([string]$Method,[hashtable]$Params=@{});$script:sequence++;$id=$script:sequence;$bytes=[Text.Encoding]::UTF8.GetBytes((@{id=$id;method=$Method;params=$Params}|ConvertTo-Json -Compress -Depth 10));$null=$socket.SendAsync([ArraySegment[byte]]::new($bytes),[Net.WebSockets.WebSocketMessageType]::Text,$true,[Threading.CancellationToken]::None).GetAwaiter().GetResult();while($true){$stream=[IO.MemoryStream]::new();do{$buffer=New-Object byte[] 65536;$result=$socket.ReceiveAsync([ArraySegment[byte]]::new($buffer),[Threading.CancellationToken]::None).GetAwaiter().GetResult();$stream.Write($buffer,0,$result.Count)}until($result.EndOfMessage);$response=[Text.Encoding]::UTF8.GetString($stream.ToArray())|ConvertFrom-Json;if($response.id-eq$id){if($response.error){throw($response.error|ConvertTo-Json -Compress)};return $response.result}}}
function Navigate{param([string]$Url,[int]$Wait=3);Invoke-Cdp 'Page.navigate' @{url=$Url}|Out-Null;Start-Sleep -Seconds $Wait}
function Save-Shot{param([string]$Name);$image=Invoke-Cdp 'Page.captureScreenshot' @{format='png';fromSurface=$true;captureBeyondViewport=$false};$path=Join-Path $out $Name;[IO.File]::WriteAllBytes($path,[Convert]::FromBase64String($image.data));Write-Output "SCREENSHOT: $path"}
function Login{param([string]$Url,[string]$Username,[string]$Password);Navigate "$Url/wp-login.php" 2;$expression="(()=>{const u=document.querySelector('#user_login'),p=document.querySelector('#user_pass');if(!u||!p)return false;u.value=$(ConvertTo-Json $Username -Compress);p.value=$(ConvertTo-Json $Password -Compress);document.querySelector('#wp-submit').click();return true})()";$result=Invoke-Cdp 'Runtime.evaluate' @{expression=$expression;returnByValue=$true};if(-not$result.result.value){throw'Login form unavailable.'};Start-Sleep -Seconds 4}

try{
  $created=& docker compose exec -T wordpress wp user create $adminUser $adminEmail "--user_pass=$adminPassword" --role=administrator "--url=$RootUrl" --porcelain --allow-root 2>&1
  if($LASTEXITCODE-ne 0){throw"Temporary evidence administrator could not be created: $created"};$adminCreated=$true
  & docker compose exec -T wordpress wp super-admin add $adminUser "--url=$RootUrl" --allow-root | Out-Null
  if($LASTEXITCODE-ne 0){throw'Temporary evidence administrator could not be elevated.'}

  New-Item -ItemType Directory -Path $profile | Out-Null
  $process=Start-Process -FilePath $browser -ArgumentList @('--headless=new','--no-sandbox','--disable-gpu','--hide-scrollbars',"--remote-debugging-port=$port","--user-data-dir=$profile",'--window-size=1600,1000','about:blank') -WindowStyle Hidden -PassThru
  $targets=$null;for($i=0;$i-lt 30-and-not$targets;$i++){Start-Sleep -Milliseconds 250;try{$targets=Invoke-RestMethod -Uri "http://127.0.0.1:$port/json/list"}catch{}}
  $target=$targets|Where-Object{$_.type-eq'page'}|Select-Object -First 1;if(-not$target){throw'Chromium page target unavailable.'}
  $socket=[Net.WebSockets.ClientWebSocket]::new();$null=$socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult();Invoke-Cdp 'Page.enable'|Out-Null;Invoke-Cdp 'Runtime.enable'|Out-Null;Invoke-Cdp 'Network.enable'|Out-Null;Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1600;height=1000;deviceScaleFactor=1;mobile=$false}|Out-Null

  Login $RootUrl $adminUser $adminPassword
  Navigate "$RootUrl/wp-admin/network/admin.php?page=pharmasure-admin" 4
  $overview=Invoke-Cdp 'Runtime.evaluate' @{expression='({title:document.querySelector(".ps-platform-masthead h1")?.textContent,metrics:document.querySelectorAll(".ps-platform-metrics article").length,css:[...document.styleSheets].map(s=>s.href||"").some(x=>x.includes("platform-admin.css"))})';returnByValue=$true};if($overview.result.value.title-ne'Platform command centre'-or$overview.result.value.metrics-ne 4-or-not$overview.result.value.css){throw'Platform command centre did not render its branded control plane.'};Save-Shot '21-superadmin-platform-overview.png'
  Navigate "$RootUrl/wp-admin/network/admin.php?page=pharmasure-tenants" 4
  $directory=Invoke-Cdp 'Runtime.evaluate' @{expression='({title:document.querySelector(".ps-platform-masthead h1")?.textContent,tenants:document.querySelectorAll(".ps-tenant-card").length,repair:document.querySelectorAll(".ps-platform-warning").length})';returnByValue=$true};if($directory.result.value.title-ne'Pharmacy tenant directory'-or$directory.result.value.tenants-lt 2-or$directory.result.value.repair-ne 0){throw('Tenant directory failed readiness checks: '+($directory.result.value|ConvertTo-Json -Compress))};Save-Shot '22-superadmin-tenant-directory.png'
  Navigate "$RootUrl/wp-admin/network/admin.php?page=pharmasure-add-tenant" 4
  $form=Invoke-Cdp 'Runtime.evaluate' @{expression='({title:document.querySelector(".ps-platform-masthead h1")?.textContent,sections:document.querySelectorAll(".ps-provision-form>section").length,fields:document.querySelectorAll(".ps-provision-form input").length,trial:document.querySelector(".ps-platform-plan")?.textContent})';returnByValue=$true};if($form.result.value.title-ne'Provision a pharmacy'-or$form.result.value.sections-ne 3-or$form.result.value.fields-lt 10-or-not$form.result.value.trial){throw'Complete tenant provisioning form did not render.'};Save-Shot '23-superadmin-provision-pharmacy.png'

  Invoke-Cdp 'Network.clearBrowserCookies'|Out-Null
  Login $TenantSiteUrl $TenantUsername $TenantPassword
  Navigate $AppUrl 4
  $logout=Invoke-Cdp 'Runtime.evaluate' @{expression='({visible:!!document.querySelector(".ps-logout"),label:document.querySelector(".ps-logout")?.textContent.trim(),href:document.querySelector(".ps-logout")?.href,adminBar:!!document.querySelector("#wpadminbar")})';returnByValue=$true};if(-not$logout.result.value.visible-or$logout.result.value.label-notmatch'Log out'-or$logout.result.value.href-notmatch'action=logout'-or$logout.result.value.adminBar){throw'Persistent secure-session logout control did not render.'};Save-Shot '24-headless-logout-control.png'
  Invoke-Cdp 'Runtime.evaluate' @{expression='document.querySelector(".ps-logout").click()';returnByValue=$true}|Out-Null;Start-Sleep -Seconds 5
  $loggedOut=Invoke-Cdp 'Runtime.evaluate' @{expression='({url:location.href,login:!!document.querySelector("#loginform"),brand:document.querySelector(".ps-login-intro strong")?.textContent})';returnByValue=$true};if(-not$loggedOut.result.value.login-or$loggedOut.result.value.brand-ne'Secure workspace access'){throw('Logout did not terminate at the branded login: '+($loggedOut.result.value|ConvertTo-Json -Compress))};Save-Shot '25-logged-out-secure-login.png'
}finally{
  if($socket){$socket.Dispose()};if($process-and-not$process.HasExited){&taskkill.exe /PID $process.Id /T /F 2>&1|Out-Null;Start-Sleep -Milliseconds 400}
  if(Test-Path -LiteralPath $profile){Remove-Item -LiteralPath $profile -Recurse -Force -ErrorAction SilentlyContinue}
  if($adminCreated){& docker compose exec -T wordpress wp user delete $adminUser --yes "--url=$RootUrl" --allow-root 2>&1|Out-Null}
}
