param(
    [Parameter(Mandatory = $true)][string]$Username,
    [Parameter(Mandatory = $true)][string]$Password,
    [string]$SiteUrl = 'http://localhost:8080/greenlife-pharmacy',
    [string]$AppUrl = 'http://localhost:8080/app',
    [string]$OutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-app-overview.png'),
    [string]$LightOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-app-overview-light.png'),
    [string]$InventoryOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-inventory-catalogue.png'),
    [string]$InventoryLightOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-inventory-catalogue-light.png'),
    [string]$ExpiryOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-inventory-expiry.png')
)

$ErrorActionPreference = 'Stop'
$browser = 'C:\Users\DELL\AppData\Local\ms-playwright\chromium-1200\chrome-win64\chrome.exe'
$output = [IO.Path]::GetFullPath($OutputFile)
$lightOutput = [IO.Path]::GetFullPath($LightOutputFile)
$inventoryOutput = [IO.Path]::GetFullPath($InventoryOutputFile)
$inventoryLightOutput = [IO.Path]::GetFullPath($InventoryLightOutputFile)
$expiryOutput = [IO.Path]::GetFullPath($ExpiryOutputFile)
New-Item -ItemType Directory -Path (Split-Path $output) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $lightOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $inventoryOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $inventoryLightOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $expiryOutput) -Force | Out-Null
$profile = Join-Path ([IO.Path]::GetTempPath()) ('pharmasure-app-capture-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $profile | Out-Null
$port = 9341
$process = $null
$socket = $null
$sequence = 0

function Invoke-Cdp {
    param([string]$Method, [hashtable]$Params = @{})
    $script:sequence++
    $id = $script:sequence
    $message = @{ id = $id; method = $Method; params = $Params } | ConvertTo-Json -Compress -Depth 10
    $bytes = [Text.Encoding]::UTF8.GetBytes($message)
    $null = $socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
    while ($true) {
        $stream = [IO.MemoryStream]::new()
        do {
            $buffer = New-Object byte[] 65536
            $result = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), [Threading.CancellationToken]::None).GetAwaiter().GetResult()
            $stream.Write($buffer, 0, $result.Count)
        } until ($result.EndOfMessage)
        $response = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
        if ($response.id -eq $id) {
            if ($response.error) { throw ($response.error | ConvertTo-Json -Compress) }
            return $response.result
        }
    }
}

try {
    $arguments = @('--headless=new','--no-sandbox','--disable-gpu','--hide-scrollbars','--force-device-scale-factor=1',"--remote-debugging-port=$port","--user-data-dir=$profile",'--window-size=1600,1000','about:blank')
    $process = Start-Process -FilePath $browser -ArgumentList $arguments -WindowStyle Hidden -PassThru
    $targets = $null
    for ($attempt = 0; $attempt -lt 30 -and -not $targets; $attempt++) {
        Start-Sleep -Milliseconds 250
        try { $targets = Invoke-RestMethod -Uri "http://127.0.0.1:$port/json/list" } catch { }
    }
    $target = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
    if (-not $target) { throw 'Chromium did not expose a page target.' }
    $socket = [Net.WebSockets.ClientWebSocket]::new()
    $null = $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Runtime.enable' | Out-Null
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width=1600; height=1000; deviceScaleFactor=1; mobile=$false } | Out-Null
    Invoke-Cdp 'Page.navigate' @{ url="$SiteUrl/wp-login.php" } | Out-Null
    Start-Sleep -Seconds 2
    $login = @"
(() => {
  const user = document.querySelector('#user_login');
  const pass = document.querySelector('#user_pass');
  if (!user || !pass) return false;
  user.value = $(ConvertTo-Json $Username -Compress);
  pass.value = $(ConvertTo-Json $Password -Compress);
  document.querySelector('#wp-submit').click();
  return true;
})()
"@
    $submitted = Invoke-Cdp 'Runtime.evaluate' @{ expression=$login; returnByValue=$true }
    if (-not $submitted.result.value) { throw 'Login form unavailable.' }
    Start-Sleep -Seconds 4
    Invoke-Cdp 'Page.navigate' @{ url=$AppUrl } | Out-Null
    Start-Sleep -Seconds 4
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.fonts ? document.fonts.ready.then(() => true) : true'; awaitPromise=$true; returnByValue=$true } | Out-Null
    $initialTheme = Invoke-Cdp 'Runtime.evaluate' @{ expression='document.documentElement.dataset.theme'; returnByValue=$true }
    if ($initialTheme.result.value -ne 'dark') {
        Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
        Start-Sleep -Seconds 2
    }
    $audit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({url:location.href,heading:document.querySelector("h1")?.textContent,theme:document.documentElement.dataset.theme,adminBar:!!document.querySelector("#wpadminbar"),styles:[...document.styleSheets].map(s=>s.href).filter(Boolean),scripts:[...document.scripts].map(s=>s.src).filter(Boolean)})'; returnByValue=$true }
    if ($audit.result.value.url -notlike "$AppUrl*") { throw "Unexpected app URL: $($audit.result.value.url)" }
    if ($audit.result.value.adminBar) { throw 'The WordPress admin bar leaked into the standalone shell.' }
    if (($audit.result.value.styles -join ' ') -match 'wp-admin|dashicons|admin-bar') { throw 'A WordPress administrative stylesheet was loaded.' }
    $image = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($output, [Convert]::FromBase64String($image.data))
    Write-Output "SCREENSHOT: $output"
    Write-Output "HEADING: $($audit.result.value.heading)"
    Write-Output "STYLES: $($audit.result.value.styles -join ', ')"
    Write-Output "SCRIPTS: $($audit.result.value.scripts -join ', ')"

    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2
    $lightAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='document.documentElement.dataset.theme'; returnByValue=$true }
    if ($lightAudit.result.value -ne 'light') { throw 'The application did not switch to light theme.' }
    $lightImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($lightOutput, [Convert]::FromBase64String($lightImage.data))
    Write-Output "LIGHT_SCREENSHOT: $lightOutput"
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/inventory" } | Out-Null
    Start-Sleep -Seconds 4
    $inventoryAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({heading:document.querySelector("h1")?.textContent,view:document.querySelector(".ps-inventory-tabs [aria-current=page] strong")?.textContent,rows:document.querySelectorAll(".ps-inventory-table tbody tr").length})'; returnByValue=$true }
    if ($inventoryAudit.result.value.heading -ne 'Inventory workspace' -or $inventoryAudit.result.value.view -ne 'Catalogue') { throw 'The headless Inventory catalogue did not render.' }
    $inventoryImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($inventoryOutput, [Convert]::FromBase64String($inventoryImage.data))
    Write-Output "INVENTORY_SCREENSHOT: $inventoryOutput | rows=$($inventoryAudit.result.value.rows)"

    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2
    $inventoryLightImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($inventoryLightOutput, [Convert]::FromBase64String($inventoryLightImage.data))
    Write-Output "INVENTORY_LIGHT_SCREENSHOT: $inventoryLightOutput"
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/inventory?view=expiry" } | Out-Null
    Start-Sleep -Seconds 4
    $expiryAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({view:document.querySelector(".ps-inventory-tabs [aria-current=page] strong")?.textContent,rows:document.querySelectorAll(".ps-inventory-table tbody tr").length})'; returnByValue=$true }
    if ($expiryAudit.result.value.view -ne 'Expiry') { throw 'The headless Inventory expiry view did not render.' }
    $expiryImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($expiryOutput, [Convert]::FromBase64String($expiryImage.data))
    Write-Output "EXPIRY_SCREENSHOT: $expiryOutput | rows=$($expiryAudit.result.value.rows)"

    Invoke-Cdp 'Page.navigate' @{ url="$SiteUrl/wp-admin/" } | Out-Null
    Start-Sleep -Seconds 3
    $redirect = Invoke-Cdp 'Runtime.evaluate' @{ expression='location.href'; returnByValue=$true }
    Write-Output "STAFF_ADMIN_DESTINATION: $($redirect.result.value)"
    if ($redirect.result.value -notlike "$AppUrl*") { throw 'Pharmacy staff wp-admin access did not redirect to /app.' }
}
finally {
    if ($socket) { $socket.Dispose() }
    if ($process -and -not $process.HasExited) { & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null; Start-Sleep -Milliseconds 500 }
    $resolved = [IO.Path]::GetFullPath($profile)
    $temp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if ($resolved.StartsWith($temp,[StringComparison]::OrdinalIgnoreCase)) {
        for ($attempt=0; $attempt -lt 5 -and (Test-Path -LiteralPath $resolved); $attempt++) { Start-Sleep -Milliseconds 250; Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction SilentlyContinue }
    }
}
