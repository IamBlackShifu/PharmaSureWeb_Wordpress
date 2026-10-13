param([string]$SiteUrl = 'https://pharmasure.wasmer.app')
$ErrorActionPreference = 'Stop'
$browser = 'C:\Users\DELL\AppData\Local\ms-playwright\chromium-1200\chrome-win64\chrome.exe'
$output = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../artifacts/screenshots/public-refresh'))
New-Item -ItemType Directory -Path $output -Force | Out-Null
$profile = Join-Path $output ('browser-' + [guid]::NewGuid().ToString('N'))
$port = 9359
$sequence = 0
$socket = $null
$process = $null
function Invoke-Cdp([string]$Method, [hashtable]$Params = @{}) {
    $script:sequence++
    $id = $script:sequence
    $bytes = [Text.Encoding]::UTF8.GetBytes((@{id=$id;method=$Method;params=$Params} | ConvertTo-Json -Compress -Depth 10))
    $cancel = [Threading.CancellationTokenSource]::new(30000)
    try {
        $null = $socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $cancel.Token).GetAwaiter().GetResult()
        while ($true) {
            $stream = [IO.MemoryStream]::new()
            do {
                $buffer = New-Object byte[] 65536
                $received = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $cancel.Token).GetAwaiter().GetResult()
                $stream.Write($buffer, 0, $received.Count)
            } until ($received.EndOfMessage)
            $reply = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
            $stream.Dispose()
            if ($reply.id -eq $id) {
                if ($reply.error) { throw ($reply.error | ConvertTo-Json -Compress) }
                return $reply.result
            }
        }
    } finally { $cancel.Dispose() }
}
function Navigate([string]$Url) {
    Invoke-Cdp 'Page.navigate' @{url=$Url} | Out-Null
    for ($attempt=0; $attempt -lt 50; $attempt++) {
        Start-Sleep -Milliseconds 200
        $ready = Invoke-Cdp 'Runtime.evaluate' @{expression='document.readyState';returnByValue=$true}
        if ($ready.result.value -eq 'complete') { Start-Sleep -Milliseconds 300; return }
    }
    throw 'Page did not finish loading.'
}
function Save-Shot([string]$Name) {
    $shot = Invoke-Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$false}
    [IO.File]::WriteAllBytes((Join-Path $output $Name), [Convert]::FromBase64String($shot.data))
}
try {
    $process = Start-Process -FilePath $browser -ArgumentList @('--headless=new','--no-sandbox','--disable-gpu',"--remote-debugging-port=$port","--user-data-dir=$profile",'about:blank') -WindowStyle Hidden -PassThru
    $targets=$null
    for ($attempt=0; $attempt -lt 30 -and -not $targets; $attempt++) {
        Start-Sleep -Milliseconds 200
        try { $targets = Invoke-RestMethod -Uri "http://127.0.0.1:$port/json/list" } catch { }
    }
    $target = $targets | Where-Object {$_.type -eq 'page'} | Select-Object -First 1
    if (-not $target) { throw 'No browser target.' }
    $socket = [Net.WebSockets.ClientWebSocket]::new()
    $null = $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Runtime.enable' | Out-Null
    foreach ($size in @(@{width=1440;height=1100;name='desktop'}, @{width=390;height=844;name='mobile'})) {
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$size.width;height=$size.height;deviceScaleFactor=1;mobile=($size.name -eq 'mobile')} | Out-Null
        Navigate ($SiteUrl + '/')
        $check = Invoke-Cdp 'Runtime.evaluate' @{expression='({landing:!!document.querySelector(".pharma-landing"),styled:document.querySelector(".pl-hero-grid")&&getComputedStyle(document.querySelector(".pl-hero-grid")).display==="grid",overflow:document.documentElement.scrollWidth>innerWidth,links:[...document.querySelectorAll(".pharma-landing a")].filter(a=>/Sign in|workspace/.test(a.textContent)).every(a=>new URL(a.href).pathname==="/wp-login.php"),title:document.querySelector("h1")?.textContent})';returnByValue=$true}
        if (-not $check.result.value.landing -or -not $check.result.value.styled -or $check.result.value.overflow -or -not $check.result.value.links) { throw ('Landing check failed: '+($check.result.value|ConvertTo-Json -Compress)) }
        Save-Shot ($size.name + '.png')
        Write-Output ($size.name + ': landing styled, login links correct, no horizontal overflow')
    }
    Invoke-Cdp 'Runtime.evaluate' @{expression='document.querySelector(".pl-nav .pl-button").click()';returnByValue=$true} | Out-Null
    Start-Sleep -Seconds 2
    $login=Invoke-Cdp 'Runtime.evaluate' @{expression='({form:!!document.querySelector("#loginform"),path:location.pathname,redirect:document.querySelector("input[name=redirect_to]")?.value})';returnByValue=$true}
    if (-not $login.result.value.form -or $login.result.value.path -ne '/wp-login.php' -or $login.result.value.redirect -notmatch '/app/') { throw 'Sign-in click did not reach workspace login.' }
    Write-Output 'Sign-in click: login form with workspace destination'
    Save-Shot 'sign-in.png'
    Navigate ($SiteUrl + '/greenwoood-pharmacy/wp-login.php')
    $legacy=Invoke-Cdp 'Runtime.evaluate' @{expression='!!document.querySelector("#loginform")';returnByValue=$true}
    if (-not $legacy.result.value) { throw 'Pharmacy-prefixed login still fails.' }
    Write-Output 'Pharmacy-prefixed login: form rendered'
    Navigate ($SiteUrl + '/greenwoood-pharmacy/wp-login.php?action=logout')
    $logout=Invoke-Cdp 'Runtime.evaluate' @{expression='({confirmation:!!document.querySelector("#error-page a[href*=action]"),text:document.body.textContent.includes("log out")})';returnByValue=$true}
    if (-not $logout.result.value.text) { throw 'Legacy logout URL did not reach WordPress logout confirmation.' }
    Write-Output 'Pharmacy-prefixed logout: WordPress confirmation reached'
    # Exercise the server logout redirect using only an anonymous browser.
    Invoke-Cdp 'Runtime.evaluate' @{expression='document.querySelector("#error-page a[href*=action]").click()';returnByValue=$true} | Out-Null
    Start-Sleep -Seconds 2
    $loggedOut=Invoke-Cdp 'Runtime.evaluate' @{expression='({form:!!document.querySelector("#loginform"),path:location.pathname,loggedOut:new URL(location.href).searchParams.get("logged_out")})';returnByValue=$true}
    if (-not $loggedOut.result.value.form -or $loggedOut.result.value.path -ne '/wp-login.php' -or $loggedOut.result.value.loggedOut -ne 'true') { throw 'Server logout did not redirect to the sign-in form.' }
    Write-Output 'Server logout redirect: root login form with logged-out confirmation'
} finally {
    if ($socket) { $socket.Dispose() }
    if ($process -and -not $process.HasExited) { & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null }
    if (Test-Path -LiteralPath $profile) {
        $resolvedProfile = (Resolve-Path -LiteralPath $profile).Path
        if (-not $resolvedProfile.StartsWith($output + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Browser profile cleanup is outside the artifact directory.' }
        Remove-Item -LiteralPath $resolvedProfile -Recurse -Force -ErrorAction SilentlyContinue
    }
}
