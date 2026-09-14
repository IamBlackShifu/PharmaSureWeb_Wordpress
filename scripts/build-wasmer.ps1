[CmdletBinding()]
param(
	[string] $WordPressVersion = '7.0.1',
	[string] $OutputDirectory = (Join-Path $PSScriptRoot '..\dist\wasmer')
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$deploymentRoot = Join-Path $projectRoot 'deploy\wasmer'
$outputPath = [System.IO.Path]::GetFullPath($OutputDirectory)
$allowedOutputRoot = [System.IO.Path]::GetFullPath((Join-Path $projectRoot 'dist'))

if (-not $outputPath.StartsWith($allowedOutputRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'The Wasmer package output directory must be a child of the project dist directory.'
}

$temporaryPath = Join-Path $projectRoot ('.tmp-wasmer-build-' + [guid]::NewGuid().ToString('N'))
$archivePath = Join-Path $temporaryPath 'wordpress.zip'
$wordpressUrl = "https://wordpress.org/wordpress-$WordPressVersion.zip"
$checksumsUrl = "https://api.wordpress.org/core/checksums/1.0/?version=$WordPressVersion&locale=en_US"

$packages = @(
	@{ Type = 'plugins'; Name = 'pharmasure-core' },
	@{ Type = 'plugins'; Name = 'pharmasure-tenancy' },
	@{ Type = 'plugins'; Name = 'pharmasure-licensing' },
	@{ Type = 'plugins'; Name = 'pharmasure-inventory' },
	@{ Type = 'plugins'; Name = 'pharmasure-clinical' },
	@{ Type = 'plugins'; Name = 'pharmasure-pos' },
	@{ Type = 'plugins'; Name = 'pharmasure-claims' },
	@{ Type = 'plugins'; Name = 'pharmasure-reporting' },
	@{ Type = 'plugins'; Name = 'pharmasure-integrations' },
	@{ Type = 'plugins'; Name = 'pharmasure-offline' },
	@{ Type = 'plugins'; Name = 'pharmasure-print' },
	@{ Type = 'plugins'; Name = 'pharmasure-platform-admin' },
	@{ Type = 'themes'; Name = 'pharmasure-portal' }
)

try {
	New-Item -ItemType Directory -Path $temporaryPath | Out-Null
	Invoke-WebRequest -Uri $wordpressUrl -OutFile $archivePath
	Expand-Archive -LiteralPath $archivePath -DestinationPath $temporaryPath

	$coreRoot = Join-Path $temporaryPath 'wordpress'
	if (-not (Test-Path -LiteralPath (Join-Path $coreRoot 'wp-settings.php') -PathType Leaf)) {
		throw 'The downloaded archive does not contain a valid WordPress document root.'
	}

	$checksumResponse = Invoke-RestMethod -Uri $checksumsUrl
	if (-not $checksumResponse.checksums) {
		throw "WordPress did not return checksums for version $WordPressVersion."
	}

	foreach ($property in $checksumResponse.checksums.PSObject.Properties) {
		$filePath = Join-Path $coreRoot ($property.Name.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
		if (-not (Test-Path -LiteralPath $filePath -PathType Leaf)) {
			throw "WordPress core file is missing: $($property.Name)"
		}
		$actualHash = (Get-FileHash -LiteralPath $filePath -Algorithm MD5).Hash.ToLowerInvariant()
		if ($actualHash -ne [string] $property.Value) {
			throw "WordPress core checksum failed: $($property.Name)"
		}
	}

	if (Test-Path -LiteralPath $outputPath) {
		Remove-Item -LiteralPath $outputPath -Recurse -Force
	}
	New-Item -ItemType Directory -Path $outputPath | Out-Null
	New-Item -ItemType Directory -Path (Join-Path $outputPath 'app') | Out-Null
	Copy-Item -Path (Join-Path $coreRoot '*') -Destination (Join-Path $outputPath 'app') -Recurse

	foreach ($package in $packages) {
		$source = Join-Path (Join-Path (Join-Path $projectRoot 'wp-content') $package.Type) $package.Name
		if (-not (Test-Path -LiteralPath $source -PathType Container)) {
			throw "Missing PharmaSure package directory: $source"
		}

		$destinationParent = Join-Path (Join-Path (Join-Path $outputPath 'app\wp-content') $package.Type) ''
		New-Item -ItemType Directory -Path $destinationParent -Force | Out-Null
		Copy-Item -LiteralPath $source -Destination $destinationParent -Recurse -Force
	}

	Copy-Item -LiteralPath (Join-Path $deploymentRoot 'templates\wp-config.php') -Destination (Join-Path $outputPath 'app\wp-config.php')
	Copy-Item -LiteralPath (Join-Path $deploymentRoot 'templates\health.php') -Destination (Join-Path $outputPath 'app\health.php')
	Copy-Item -LiteralPath (Join-Path $deploymentRoot 'templates\router.php') -Destination (Join-Path $outputPath 'app\router.php')
	Copy-Item -LiteralPath (Join-Path $deploymentRoot 'config') -Destination (Join-Path $outputPath 'config') -Recurse
	Copy-Item -LiteralPath (Join-Path $deploymentRoot 'app.yaml') -Destination (Join-Path $outputPath 'app.yaml')
	Copy-Item -LiteralPath (Join-Path $deploymentRoot 'wasmer.toml') -Destination (Join-Path $outputPath 'wasmer.toml')

	$release = [ordered]@{
		wordpress_version = $WordPressVersion
		git_commit = (git -C $projectRoot rev-parse HEAD).Trim()
		built_at_utc = [DateTime]::UtcNow.ToString('o')
		packages = @($packages | ForEach-Object { "$($_.Type)/$($_.Name)" })
	}
	$release | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $outputPath 'release.json') -Encoding UTF8

	Write-Output "Wasmer deployment package created at $outputPath"
}
finally {
	if (Test-Path -LiteralPath $temporaryPath) {
		$resolvedTemporaryPath = [System.IO.Path]::GetFullPath($temporaryPath)
		if ($resolvedTemporaryPath.StartsWith($projectRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase) -and
			[System.IO.Path]::GetFileName($resolvedTemporaryPath).StartsWith('.tmp-wasmer-build-', [System.StringComparison]::Ordinal)) {
			Remove-Item -LiteralPath $resolvedTemporaryPath -Recurse -Force
		}
	}
}
