param(
    [string]$RootUrl = 'http://localhost:8080',
    [string]$TenantUrl = 'http://localhost:8080/greenlife-pharmacy',
    [string]$EvidenceDirectory = (Join-Path $PSScriptRoot '..\artifacts\production-readiness\2026-08-26')
)

$ErrorActionPreference = 'Stop'
$evidence = [IO.Path]::GetFullPath($EvidenceDirectory)
New-Item -ItemType Directory -Path $evidence -Force | Out-Null
$log = Join-Path $evidence '00-automated-test-transcript.txt'
$summary = Join-Path $evidence '00-automated-test-summary.json'
$started = Get-Date
$results = [Collections.Generic.List[object]]::new()

Set-Content -LiteralPath $log -Value "PharmaSure production-readiness regression`nStarted: $($started.ToString('o'))`n"

function Invoke-Suite {
    param([string]$File, [string]$Url, [switch]$DirectPhp)
    $heading = "`n===== $File | $Url ====="
    Add-Content -LiteralPath $log -Value $heading
    Write-Output $heading
    if ($DirectPhp) {
        $output = & docker compose exec -T wordpress php "scripts/$File" 2>&1
    } else {
        $output = & docker compose exec -T wordpress wp eval-file "scripts/$File" "--url=$Url" --allow-root 2>&1
    }
    $code = $LASTEXITCODE
    $output | ForEach-Object { Write-Output $_; Add-Content -LiteralPath $log -Value $_ }
    $results.Add([pscustomobject]@{ suite = $File; context = $Url; passed = ($code -eq 0); exit_code = $code })
    if ($code -ne 0) { throw "Regression suite failed: $File (exit $code)." }
}

$rootSuites = @(
    'test-accessibility-hardening.php', 'test-admin-pages.php', 'test-application-shell.php',
    'test-architecture-guardrails.php', 'test-audit.php', 'test-branch-access.php',
    'test-demo-tenant-users.php', 'test-integrations.php', 'test-tenancy.php'
)
$tenantSuites = @(
    'test-app-launcher.php', 'test-branch-session-context.php', 'test-claims.php', 'test-clinical.php',
    'test-headless-claims.php', 'test-headless-clinical.php', 'test-headless-inventory.php',
    'test-headless-offline.php', 'test-headless-pos.php', 'test-headless-reports-accounts.php',
    'test-inventory.php', 'test-inventory-commands.php', 'test-inventory-workspace.php',
    'test-offline.php', 'test-offline-operations.php', 'test-offline-replay.php',
    'test-pos.php', 'test-print.php', 'test-reporting.php', 'test-runtime-schema.php'
)

try {
    foreach ($suite in $rootSuites) { Invoke-Suite -File $suite -Url $RootUrl }
    Invoke-Suite -File 'test-isolation.php' -Url $RootUrl -DirectPhp
    Invoke-Suite -File 'test-license.php' -Url $RootUrl -DirectPhp
    foreach ($suite in $tenantSuites) { Invoke-Suite -File $suite -Url $TenantUrl }
} finally {
    $finished = Get-Date
    $payload = [ordered]@{
        started_at = $started.ToString('o')
        finished_at = $finished.ToString('o')
        duration_seconds = [Math]::Round(($finished - $started).TotalSeconds, 2)
        total_suites = $results.Count
        passed_suites = @($results | Where-Object passed).Count
        failed_suites = @($results | Where-Object { -not $_.passed }).Count
        results = $results
    }
    $payload | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $summary
    Add-Content -LiteralPath $log -Value "`nFinished: $($finished.ToString('o'))`nSuites: $($payload.passed_suites)/$($payload.total_suites) passed"
}

Write-Output "REGRESSION_SUMMARY: $summary"
Write-Output "REGRESSION_TRANSCRIPT: $log"
