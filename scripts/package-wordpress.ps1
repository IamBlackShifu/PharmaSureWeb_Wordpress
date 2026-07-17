[CmdletBinding()]
param(
	[string] $OutputDirectory = (Join-Path $PSScriptRoot '..\dist')
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$contentRoot = Join-Path $projectRoot 'wp-content'
$outputPath = [System.IO.Path]::GetFullPath($OutputDirectory)

if (-not $outputPath.StartsWith($projectRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'The package output directory must be inside the project.'
}

New-Item -ItemType Directory -Path $outputPath -Force | Out-Null

$packages = @(
	@{ Type = 'plugins'; Name = 'pharmasure-core' },
	@{ Type = 'plugins'; Name = 'pharmasure-tenancy' },
	@{ Type = 'plugins'; Name = 'pharmasure-licensing' },
	@{ Type = 'plugins'; Name = 'pharmasure-inventory' },
	@{ Type = 'plugins'; Name = 'pharmasure-clinical' },
	@{ Type = 'themes'; Name = 'pharmasure-portal' }
)

foreach ($package in $packages) {
	$source = Join-Path (Join-Path $contentRoot $package.Type) $package.Name
	if (-not (Test-Path -LiteralPath $source -PathType Container)) {
		throw "Missing package directory: $source"
	}

	$archive = Join-Path $outputPath ($package.Name + '.zip')
	if (Test-Path -LiteralPath $archive) {
		Remove-Item -LiteralPath $archive -Force
	}

	Compress-Archive -LiteralPath $source -DestinationPath $archive -CompressionLevel Optimal
	Write-Output $archive
}
