param(
    [Parameter(Mandatory = $true)][string]$Username,
    [Parameter(Mandatory = $true)][string]$Password,
    [string]$SiteUrl = 'http://localhost:8080/greenlife-pharmacy',
    [string]$DesiredBranch = 'Borrowdale Branch',
    [string]$OutputDirectory = (Join-Path $PSScriptRoot '..\artifacts\screenshots')
)

$ErrorActionPreference = 'Stop'
$browser = 'C:\Users\DELL\AppData\Local\ms-playwright\chromium-1200\chrome-win64\chrome.exe'
if (-not (Test-Path -LiteralPath $browser)) { throw "Chromium was not found at $browser" }

$output = [IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $output -Force | Out-Null
$profile = Join-Path ([IO.Path]::GetTempPath()) ('pharmasure-capture-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $profile | Out-Null
$port = 9337
$process = $null
$socket = $null
$sequence = 0

function Invoke-Cdp {
    param([string]$Method, [hashtable]$Params = @{})
    $script:sequence++
    $id = $script:sequence
    $message = @{ id = $id; method = $Method; params = $Params } | ConvertTo-Json -Compress -Depth 12
    $bytes = [Text.Encoding]::UTF8.GetBytes($message)
    $segment = [ArraySegment[byte]]::new($bytes)
    $null = $socket.SendAsync($segment, [Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
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
    $arguments = @(
        '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
        '--force-device-scale-factor=1', "--remote-debugging-port=$port",
        "--user-data-dir=$profile", '--window-size=1600,1000', 'about:blank'
    )
    $process = Start-Process -FilePath $browser -ArgumentList $arguments -WindowStyle Hidden -PassThru
    $targets = $null
    for ($attempt = 0; $attempt -lt 30 -and -not $targets; $attempt++) {
        Start-Sleep -Milliseconds 250
        try { $targets = Invoke-RestMethod -Uri "http://127.0.0.1:$port/json/list" } catch { }
    }
    $target = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
    if (-not $target) { throw 'Chromium did not expose a debuggable page target.' }

    $socket = [Net.WebSockets.ClientWebSocket]::new()
    $null = $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Runtime.enable' | Out-Null
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 1600; height = 1000; deviceScaleFactor = 1; mobile = $false } | Out-Null
    Invoke-Cdp 'Page.navigate' @{ url = "$SiteUrl/wp-login.php" } | Out-Null
    Start-Sleep -Seconds 2

    $login = @"
(() => {
  const user = document.querySelector('#user_login');
  const pass = document.querySelector('#user_pass');
  if (!user || !pass) return 'missing-login-form';
  user.value = $(ConvertTo-Json $Username -Compress);
  pass.value = $(ConvertTo-Json $Password -Compress);
  const remember = document.querySelector('#rememberme');
  if (remember) remember.checked = true;
  document.querySelector('#wp-submit').click();
  return 'submitted';
})()
"@
    $loginResult = Invoke-Cdp 'Runtime.evaluate' @{ expression = $login; returnByValue = $true }
    if ($loginResult.result.value -ne 'submitted') { throw 'The WordPress login form could not be submitted.' }
    Start-Sleep -Seconds 4
    $location = Invoke-Cdp 'Runtime.evaluate' @{ expression = 'location.href'; returnByValue = $true }
    if ($location.result.value -like '*wp-login.php*') { throw 'The demo owner login did not complete.' }

    $captures = @(
        @{ Name = 'inventory-catalogue.png'; View = 'catalogue' },
        @{ Name = 'inventory-batches.png'; View = 'batches' },
        @{ Name = 'inventory-receipts.png'; View = 'receipts' },
        @{ Name = 'inventory-movements.png'; View = 'movements' },
        @{ Name = 'inventory-low-stock.png'; View = 'low-stock' },
        @{ Name = 'inventory-expiry.png'; View = 'expiry' },
        @{ Name = 'inventory-suppliers.png'; View = 'suppliers' }
    )
    foreach ($capture in $captures) {
        $url = "$SiteUrl/wp-admin/admin.php?page=pharmasure-inventory&view=$($capture.View)"
        Invoke-Cdp 'Page.navigate' @{ url = $url } | Out-Null
        Start-Sleep -Seconds 3
        $selectBranch = @"
(() => {
  const select = document.querySelector('#ps-active-branch');
  if (!select) return 'missing';
  const option = [...select.options].find(item => item.textContent.trim() === $(ConvertTo-Json $DesiredBranch -Compress));
  if (!option) return 'unavailable';
  if (select.value === option.value) return 'ready';
  select.value = option.value;
  select.form.submit();
  return 'submitted';
})()
"@
        $branchResult = Invoke-Cdp 'Runtime.evaluate' @{ expression = $selectBranch; returnByValue = $true }
        if ($branchResult.result.value -eq 'submitted') { Start-Sleep -Seconds 3 }
        if ($branchResult.result.value -eq 'unavailable' -or $branchResult.result.value -eq 'missing') { throw "The desired branch '$DesiredBranch' is not available." }
        Invoke-Cdp 'Runtime.evaluate' @{ expression = 'window.scrollTo(0,0); document.fonts ? document.fonts.ready.then(() => true) : true'; awaitPromise = $true; returnByValue = $true } | Out-Null
        $page = Invoke-Cdp 'Runtime.evaluate' @{ expression = '({title:document.title, heading:document.querySelector("h1")?.textContent, url:location.href})'; returnByValue = $true }
        if ($page.result.value.heading -ne 'Inventory workspace') { throw "Unexpected render for $url" }
        $image = Invoke-Cdp 'Page.captureScreenshot' @{ format = 'png'; fromSurface = $true; captureBeyondViewport = $false }
        [IO.File]::WriteAllBytes((Join-Path $output $capture.Name), [Convert]::FromBase64String($image.data))
        Write-Output ((Join-Path $output $capture.Name) + ' | ' + $page.result.value.title)
    }
}
finally {
    if ($socket) { $socket.Dispose() }
    if ($process -and -not $process.HasExited) {
        & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null
        Start-Sleep -Milliseconds 500
    }
    $resolvedProfile = [IO.Path]::GetFullPath($profile)
    $resolvedTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if ($resolvedProfile.StartsWith($resolvedTemp, [StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $resolvedProfile)) {
        for ($attempt = 0; $attempt -lt 5 -and (Test-Path -LiteralPath $resolvedProfile); $attempt++) {
            Start-Sleep -Milliseconds 300
            Remove-Item -LiteralPath $resolvedProfile -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}
