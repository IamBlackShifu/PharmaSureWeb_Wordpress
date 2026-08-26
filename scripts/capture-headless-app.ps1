param(
    [Parameter(Mandatory = $true)][string]$Username,
    [Parameter(Mandatory = $true)][string]$Password,
    [string]$SiteUrl = 'http://localhost:8080/greenlife-pharmacy',
    [string]$AppUrl = 'http://localhost:8080/app',
    [string]$OutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-app-overview.png'),
    [string]$LightOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-app-overview-light.png'),
    [string]$PosOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-pos-workspace.png'),
    [string]$PosCartOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-pos-active-cart.png'),
    [string]$PosLightOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-pos-active-cart-light.png'),
    [string]$PosReceiptOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-pos-thermal-receipt.png'),
    [string]$ClinicalOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-clinical-workspace.png'),
    [string]$ClinicalReviewOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-clinical-review.png'),
    [string]$ClinicalLightOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-clinical-workspace-light.png'),
    [string]$ClaimsOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-claims-workspace.png'),
    [string]$ClaimsLightOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-claims-workspace-light.png'),
    [string]$InventoryOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-inventory-catalogue.png'),
    [string]$InventoryWorkflowOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-inventory-receiving-workflow.png'),
    [string]$InventoryLightOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-inventory-catalogue-light.png'),
    [string]$ExpiryOutputFile = (Join-Path $PSScriptRoot '..\artifacts\screenshots\headless-inventory-expiry.png')
)

$ErrorActionPreference = 'Stop'
$browser = 'C:\Users\DELL\AppData\Local\ms-playwright\chromium-1200\chrome-win64\chrome.exe'
$output = [IO.Path]::GetFullPath($OutputFile)
$lightOutput = [IO.Path]::GetFullPath($LightOutputFile)
$posOutput = [IO.Path]::GetFullPath($PosOutputFile)
$posCartOutput = [IO.Path]::GetFullPath($PosCartOutputFile)
$posLightOutput = [IO.Path]::GetFullPath($PosLightOutputFile)
$posReceiptOutput = [IO.Path]::GetFullPath($PosReceiptOutputFile)
$clinicalOutput = [IO.Path]::GetFullPath($ClinicalOutputFile)
$clinicalReviewOutput = [IO.Path]::GetFullPath($ClinicalReviewOutputFile)
$clinicalLightOutput = [IO.Path]::GetFullPath($ClinicalLightOutputFile)
$claimsOutput = [IO.Path]::GetFullPath($ClaimsOutputFile)
$claimsLightOutput = [IO.Path]::GetFullPath($ClaimsLightOutputFile)
$inventoryOutput = [IO.Path]::GetFullPath($InventoryOutputFile)
$inventoryWorkflowOutput = [IO.Path]::GetFullPath($InventoryWorkflowOutputFile)
$inventoryLightOutput = [IO.Path]::GetFullPath($InventoryLightOutputFile)
$expiryOutput = [IO.Path]::GetFullPath($ExpiryOutputFile)
New-Item -ItemType Directory -Path (Split-Path $output) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $lightOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $posOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $posCartOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $posLightOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $posReceiptOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $clinicalOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $clinicalReviewOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $clinicalLightOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $claimsOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $claimsLightOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $inventoryOutput) -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path $inventoryWorkflowOutput) -Force | Out-Null
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

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/pos" } | Out-Null
    Start-Sleep -Seconds 4
    $posAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({heading:document.querySelector("h1")?.textContent,products:document.querySelectorAll(".ps-pos-product").length,session:document.querySelector(".ps-pos-session")?.textContent,adminBar:!!document.querySelector("#wpadminbar")})'; returnByValue=$true }
    if ($posAudit.result.value.heading -ne 'Point of Sale' -or $posAudit.result.value.products -lt 1) { throw 'The headless POS workspace did not render its product finder.' }
    if (-not $posAudit.result.value.session) { throw 'The seeded owner till session was not available to the POS workspace.' }
    if ($posAudit.result.value.adminBar) { throw 'The WordPress admin bar leaked into the POS route.' }
    $posImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($posOutput, [Convert]::FromBase64String($posImage.data))
    Write-Output "POS_SCREENSHOT: $posOutput | products=$($posAudit.result.value.products)"

    $added = Invoke-Cdp 'Runtime.evaluate' @{ expression='(() => { const product=[...document.querySelectorAll(".ps-pos-product:not(:disabled)")][0]; if(!product)return false; product.click(); return true; })()'; returnByValue=$true }
    if (-not $added.result.value) { throw 'No non-prescription in-stock product was available for the interaction check.' }
    Start-Sleep -Seconds 2
    $cartAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({lines:document.querySelectorAll(".ps-pos-cart-line").length,total:document.querySelector(".ps-pos-totals .is-grand dd")?.textContent,checkout:!!document.querySelector(".ps-pos-checkout:not(:disabled)")})'; returnByValue=$true }
    if ($cartAudit.result.value.lines -lt 1 -or -not $cartAudit.result.value.checkout) { throw 'Adding a medicine did not produce a payable POS cart.' }
    $posCartImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($posCartOutput, [Convert]::FromBase64String($posCartImage.data))
    Write-Output "POS_CART_SCREENSHOT: $posCartOutput | lines=$($cartAudit.result.value.lines) | total=$($cartAudit.result.value.total)"

    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2
    $posLightAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='document.documentElement.dataset.theme'; returnByValue=$true }
    if ($posLightAudit.result.value -ne 'light') { throw 'The POS workspace did not switch to light theme.' }
    $posLightImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($posLightOutput, [Convert]::FromBase64String($posLightImage.data))
    Write-Output "POS_LIGHT_SCREENSHOT: $posLightOutput"
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2

    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector(".ps-pos-checkout")?.click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 4
    $saleAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({receipt:document.querySelector(".ps-pos-receipt-result strong")?.textContent,print:!![...document.querySelectorAll(".ps-pos-receipt-result button")].find(button=>button.textContent.trim()==="Print receipt")})'; returnByValue=$true }
    if (-not $saleAudit.result.value.receipt -or -not $saleAudit.result.value.print) { throw 'POS checkout did not expose the receipt handoff.' }
    $receiptNumber = $saleAudit.result.value.receipt
    $receiptOpened = Invoke-Cdp 'Runtime.evaluate' @{ expression='(() => { window.open=()=>window; const button=[...document.querySelectorAll(".ps-pos-receipt-result button")].find(candidate=>candidate.textContent.trim()==="Print receipt"); if(!button)return false; button.click(); return true; })()'; returnByValue=$true }
    if (-not $receiptOpened.result.value) { throw 'The receipt preview could not be opened.' }
    Start-Sleep -Seconds 5
    $thermalAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({url:location.href,receipt:document.querySelector(".receipt__meta b")?.textContent,items:document.querySelectorAll(".receipt__item:not(.receipt__item--head)").length,total:document.querySelector(".receipt__grand b")?.textContent,wpAdmin:location.href.includes("wp-admin")})'; returnByValue=$true }
    if ($thermalAudit.result.value.url -notmatch '/app/print/' -or $thermalAudit.result.value.wpAdmin -or $thermalAudit.result.value.items -lt 1) { throw ('The protected thermal receipt route did not render a complete sale: ' + ($thermalAudit.result.value | ConvertTo-Json -Compress)) }
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width=420; height=920; deviceScaleFactor=1; mobile=$false } | Out-Null
    $receiptImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$true }
    [IO.File]::WriteAllBytes($posReceiptOutput, [Convert]::FromBase64String($receiptImage.data))
    Write-Output "POS_RECEIPT_SCREENSHOT: $posReceiptOutput | receipt=$receiptNumber | items=$($thermalAudit.result.value.items) | total=$($thermalAudit.result.value.total)"

    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width=1600; height=1000; deviceScaleFactor=1; mobile=$false } | Out-Null
    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/pos" } | Out-Null
    Start-Sleep -Seconds 4
    $receiptJson = ConvertTo-Json $receiptNumber -Compress
    $voided = Invoke-Cdp 'Runtime.evaluate' @{ expression="(() => { const receipt=$receiptJson; const row=[...document.querySelectorAll('.ps-pos-activity-list article')].find(candidate=>candidate.textContent.includes(receipt)); const button=row&&[...row.querySelectorAll('button')].find(candidate=>candidate.textContent.trim()==='Void'); if(!button)return false; button.click(); const input=document.querySelector('.ps-pos-dialog [name=reason]'); if(!input)return false; input.value='Automated receipt rendering verification'; input.form.requestSubmit(); return true; })()"; returnByValue=$true }
    if (-not $voided.result.value) { throw 'Receipt verification sale could not be safely voided.' }
    Start-Sleep -Seconds 4

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/clinical" } | Out-Null
    Start-Sleep -Seconds 4
    $clinicalAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({heading:document.querySelector("h1")?.textContent,patients:document.querySelectorAll(".ps-clinical-patient").length,prescriptions:document.querySelectorAll(".ps-clinical-rx").length,adminBar:!!document.querySelector("#wpadminbar"),theme:document.documentElement.dataset.theme})'; returnByValue=$true }
    if ($clinicalAudit.result.value.heading -ne 'Clinical workspace' -or $clinicalAudit.result.value.patients -lt 1 -or $clinicalAudit.result.value.prescriptions -lt 1) { throw 'The headless Clinical patient and prescription workspace did not render.' }
    if ($clinicalAudit.result.value.adminBar -or $clinicalAudit.result.value.theme -ne 'dark') { throw 'Clinical shell isolation or dark theme posture is incorrect.' }
    $clinicalImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($clinicalOutput, [Convert]::FromBase64String($clinicalImage.data))
    Write-Output "CLINICAL_SCREENSHOT: $clinicalOutput | patients=$($clinicalAudit.result.value.patients) | prescriptions=$($clinicalAudit.result.value.prescriptions)"

    $reviewOpened = Invoke-Cdp 'Runtime.evaluate' @{ expression='(() => { const card=[...document.querySelectorAll(".ps-clinical-rx")].find(row=>row.textContent.includes("Pending Review")); if(!card)return false; card.querySelector(".ps-clinical-rx__select").click(); const action=[...document.querySelectorAll(".ps-clinical-inspector-actions button")].find(button=>button.textContent.trim()==="Review prescription"); if(!action)return false; action.click(); return true; })()'; returnByValue=$true }
    if ($reviewOpened.result.value) {
        Start-Sleep -Seconds 2
        $reviewAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({open:document.querySelector(".ps-clinical-dialog")?.open,title:document.querySelector(".ps-clinical-dialog h2")?.textContent,checks:document.querySelectorAll(".ps-clinical-review-checks input").length})'; returnByValue=$true }
        if (-not $reviewAudit.result.value.open -or $reviewAudit.result.value.checks -lt 3) { throw 'The pharmacist safety review dialog is incomplete.' }
        $reviewImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
        [IO.File]::WriteAllBytes($clinicalReviewOutput, [Convert]::FromBase64String($reviewImage.data))
        Write-Output "CLINICAL_REVIEW_SCREENSHOT: $clinicalReviewOutput | checks=$($reviewAudit.result.value.checks)"
        Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector(".ps-clinical-dialog")?.close()'; returnByValue=$true } | Out-Null
    } else { Write-Output 'CLINICAL_REVIEW_SCREENSHOT: skipped; no prescription is currently awaiting review.' }
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2
    $clinicalLightImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($clinicalLightOutput, [Convert]::FromBase64String($clinicalLightImage.data))
    Write-Output "CLINICAL_LIGHT_SCREENSHOT: $clinicalLightOutput"
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/claims" } | Out-Null
    Start-Sleep -Seconds 4
    $claimsAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({heading:document.querySelector("h1")?.textContent,claims:document.querySelectorAll(".ps-claim-row").length,lines:document.querySelectorAll(".ps-claims-lines>div").length,timeline:document.querySelectorAll(".ps-claims-timeline>div").length,adminBar:!!document.querySelector("#wpadminbar"),theme:document.documentElement.dataset.theme})'; returnByValue=$true }
    if ($claimsAudit.result.value.heading -ne 'Claims workspace' -or $claimsAudit.result.value.claims -lt 1 -or $claimsAudit.result.value.lines -lt 1 -or $claimsAudit.result.value.timeline -lt 1) { throw 'The Claims queue, evidence inspector or lifecycle timeline did not render.' }
    if ($claimsAudit.result.value.adminBar -or $claimsAudit.result.value.theme -ne 'dark') { throw 'Claims shell isolation or dark theme posture is incorrect.' }
    $claimsImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($claimsOutput, [Convert]::FromBase64String($claimsImage.data))
    Write-Output "CLAIMS_SCREENSHOT: $claimsOutput | claims=$($claimsAudit.result.value.claims) | lines=$($claimsAudit.result.value.lines) | events=$($claimsAudit.result.value.timeline)"
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2
    $claimsLightImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($claimsLightOutput, [Convert]::FromBase64String($claimsLightImage.data))
    Write-Output "CLAIMS_LIGHT_SCREENSHOT: $claimsLightOutput"
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-theme-toggle").click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2

    Invoke-Cdp 'Page.navigate' @{ url="$AppUrl/inventory" } | Out-Null
    Start-Sleep -Seconds 4
    $inventoryAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({heading:document.querySelector("h1")?.textContent,view:document.querySelector(".ps-inventory-tabs [aria-current=page] strong")?.textContent,rows:document.querySelectorAll(".ps-inventory-table tbody tr").length})'; returnByValue=$true }
    if ($inventoryAudit.result.value.heading -ne 'Inventory workspace' -or $inventoryAudit.result.value.view -ne 'Catalogue') { throw 'The headless Inventory catalogue did not render.' }
    $inventoryImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($inventoryOutput, [Convert]::FromBase64String($inventoryImage.data))
    Write-Output "INVENTORY_SCREENSHOT: $inventoryOutput | rows=$($inventoryAudit.result.value.rows)"

    Invoke-Cdp 'Runtime.evaluate' @{ expression='[...document.querySelectorAll(".ps-operation-button")].find(button=>button.textContent.trim()==="Receive stock")?.click()'; returnByValue=$true } | Out-Null
    Start-Sleep -Seconds 2
    $workflowAudit = Invoke-Cdp 'Runtime.evaluate' @{ expression='({open:document.querySelector("#ps-inventory-workflow")?.open,title:document.querySelector("#ps-workflow-title")?.textContent,lines:document.querySelectorAll(".ps-workflow-line").length})'; returnByValue=$true }
    if (-not $workflowAudit.result.value.open -or $workflowAudit.result.value.title -ne 'Receive stock') { throw 'The receiving workflow dialog did not render.' }
    $workflowImage = Invoke-Cdp 'Page.captureScreenshot' @{ format='png'; fromSurface=$true; captureBeyondViewport=$false }
    [IO.File]::WriteAllBytes($inventoryWorkflowOutput, [Convert]::FromBase64String($workflowImage.data))
    Write-Output "INVENTORY_WORKFLOW_SCREENSHOT: $inventoryWorkflowOutput | lines=$($workflowAudit.result.value.lines)"
    Invoke-Cdp 'Runtime.evaluate' @{ expression='document.querySelector("#ps-inventory-workflow")?.close()'; returnByValue=$true } | Out-Null

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
