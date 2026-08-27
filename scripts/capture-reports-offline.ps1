param(
    [Parameter(Mandatory = $true)][string]$Username,
    [Parameter(Mandatory = $true)][string]$Password,
    [string]$SiteUrl = 'http://localhost:8080/greenlife-pharmacy',
    [string]$AppUrl = 'http://localhost:8080/app',
    [string]$OfflineOutput = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-offline-workspace.png'),
    [string]$ReportsOutput = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-reports-xlsx.png'),
    [string]$DownloadDirectory = (Join-Path $PSScriptRoot '..\artifacts\downloads')
)

$ErrorActionPreference = 'Stop'
$browser = 'C:\Users\DELL\AppData\Local\ms-playwright\chromium-1200\chrome-win64\chrome.exe'
$offlineOutputPath = [IO.Path]::GetFullPath($OfflineOutput)
$reportsOutputPath = [IO.Path]::GetFullPath($ReportsOutput)
$downloadPath = [IO.Path]::GetFullPath($DownloadDirectory)
New-Item -ItemType Directory -Path (Split-Path $offlineOutputPath) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $reportsOutputPath) -Force | Out-Null
New-Item -ItemType Directory -Path $downloadPath -Force | Out-Null
$profile = Join-Path ([IO.Path]::GetTempPath()) ('pharmasure-report-offline-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $profile | Out-Null
$port = 9343
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
    for ($attempt = 0; $attempt -lt 30 -and -not $targets; $attempt++) { Start-Sleep -Milliseconds 250; try { $targets = Invoke-RestMethod -Uri "http://127.0.0.1:$port/json/list" } catch { } }
    $target = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
    if (-not $target) { throw 'Chromium did not expose a page target.' }
    $socket = [Net.WebSockets.ClientWebSocket]::new()
    $null = $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Runtime.enable' | Out-Null
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width=1600; height=1000; deviceScaleFactor=1; mobile=$false } | Out-Null
    Invoke-Cdp 'Browser.setDownloadBehavior' @{ behavior='allow'; downloadPath=$downloadPath; eventsEnabled=$true } | Out-Null

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

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/reports" } | Out-Null
    Start-Sleep -Seconds 5
    $reportsAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({heading:document.querySelector("h1")?.textContent,xlsx:[...document.querySelectorAll(".ps-report-export-actions button")].some(button=>button.textContent.trim()==="XLSX"),adminBar:!!document.querySelector("#wpadminbar")})'; returnByValue=$true }
    if ($reportsAudit.result.value.heading -ne 'Reports & analysis' -or -not $reportsAudit.result.value.xlsx -or $reportsAudit.result.value.adminBar) { throw 'The headless Reports export surface did not render correctly.' }
    $reportsImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($reportsOutputPath, [Convert]::FromBase64String($reportsImage.data))
    $downloadStarted = Get-Date
    Invoke-Cdp 'Runtime.evaluate' @{ expression='[...document.querySelectorAll(".ps-report-export-actions button")].find(button=>button.textContent.trim()==="XLSX").click()'; returnByValue=$true } | Out-Null
    $xlsx = $null
    for ($attempt = 0; $attempt -lt 40 -and -not $xlsx; $attempt++) { Start-Sleep -Milliseconds 250; $xlsx = Get-ChildItem -LiteralPath $downloadPath -Filter '*.xlsx' | Where-Object { $_.LastWriteTime -ge $downloadStarted -and $_.Length -gt 2000 } | Sort-Object LastWriteTime -Descending | Select-Object -First 1 }
    if (-not $xlsx) {
        $downloadDiagnostic = Invoke-Cdp 'Runtime.evaluate' @{ expression='({errors:[...document.querySelectorAll(".ps-error")].map(item=>item.textContent),toasts:[...document.querySelectorAll(".ps-toast")].map(item=>item.textContent),url:location.href})'; returnByValue=$true }
        throw ('The XLSX browser download did not complete: ' + ($downloadDiagnostic.result.value | ConvertTo-Json -Compress))
    }
    $signature = ([IO.File]::ReadAllBytes($xlsx.FullName))[0..1]
    if ($signature[0] -ne 80 -or $signature[1] -ne 75) { throw 'The downloaded XLSX file is not an Open XML ZIP package.' }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::OpenRead($xlsx.FullName)
    try {
        $entries = @($archive.Entries | ForEach-Object FullName)
        foreach ($required in @('xl/workbook.xml','xl/styles.xml','xl/worksheets/sheet1.xml','xl/worksheets/sheet2.xml')) { if ($entries -notcontains $required) { throw "Downloaded workbook is missing $required." } }
    } finally { $archive.Dispose() }
    Write-Output "XLSX_DOWNLOAD: $($xlsx.FullName) | bytes=$($xlsx.Length) | validated=true"
    Write-Output "REPORTS_SCREENSHOT: $reportsOutputPath"

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/offline" } | Out-Null
    Start-Sleep -Seconds 5
    $offlineAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({heading:document.querySelector("h1")?.textContent,metrics:document.querySelectorAll(".ps-offline-metrics .ps-report-metric").length,tabs:document.querySelectorAll(".ps-offline-tabs button").length,adminBar:!!document.querySelector("#wpadminbar"),error:document.querySelector(".ps-error")?.textContent})'; returnByValue=$true }
    if ($offlineAudit.result.value.heading -ne 'Offline operations' -or $offlineAudit.result.value.metrics -ne 4 -or $offlineAudit.result.value.tabs -ne 3 -or $offlineAudit.result.value.adminBar -or $offlineAudit.result.value.error) { throw ('The headless Offline workspace did not render correctly: ' + ($offlineAudit.result.value | ConvertTo-Json -Compress)) }
    $offlineImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($offlineOutputPath, [Convert]::FromBase64String($offlineImage.data))
    Write-Output "OFFLINE_SCREENSHOT: $offlineOutputPath | metrics=$($offlineAudit.result.value.metrics) | tabs=$($offlineAudit.result.value.tabs)"
}
finally {
    if ($socket) { $socket.Dispose() }
    if ($process -and -not $process.HasExited) { & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null; Start-Sleep -Milliseconds 500 }
    $resolved = [IO.Path]::GetFullPath($profile); $temp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if ($resolved.StartsWith($temp,[StringComparison]::OrdinalIgnoreCase)) { for ($attempt=0; $attempt -lt 5 -and (Test-Path -LiteralPath $resolved); $attempt++) { Start-Sleep -Milliseconds 250; Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction SilentlyContinue } }
}
