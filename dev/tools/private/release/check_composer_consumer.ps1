[CmdletBinding()]
param(
	[string]$PackagePath,
	[ValidateSet('path', 'vcs')]
	[string]$RepositoryType = 'path',
	[string]$RepositoryUrl,
	[string]$Version = '*@dev',
	[string]$Php,
	[string]$Composer,
	[switch]$KeepTemp,
	[switch]$SkipBoot,
	[switch]$RequireReleaseManifest,
	[switch]$AllowDevArtifacts
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-ExecutablePath {
	param(
		[string]$Value,
		[string]$EnvironmentName,
		[string]$CommandName,
		[string]$FailureMessage
	)
	if (-not [string]::IsNullOrWhiteSpace($Value)) {
		if (Test-Path $Value -PathType Leaf) {
			return (Resolve-Path $Value).Path
		}
		$command = Get-Command $Value -ErrorAction SilentlyContinue
		if ($null -ne $command) {
			return $command.Source
		}
	}
	$environmentValue = [Environment]::GetEnvironmentVariable($EnvironmentName)
	if (-not [string]::IsNullOrWhiteSpace($environmentValue)) {
		return Resolve-ExecutablePath -Value $environmentValue -EnvironmentName '' -CommandName $CommandName -FailureMessage $FailureMessage
	}
	$command = Get-Command $CommandName -ErrorAction SilentlyContinue
	if ($null -ne $command) {
		return $command.Source
	}
	throw $FailureMessage
}

function Resolve-ComposerPath {
	param([string]$Composer)
	if (-not [string]::IsNullOrWhiteSpace($Composer)) {
		if (Test-Path $Composer -PathType Leaf) {
			return (Resolve-Path $Composer).Path
		}
		$command = Get-Command $Composer -ErrorAction SilentlyContinue
		if ($null -ne $command) {
			return $command.Source
		}
	}
	if (-not [string]::IsNullOrWhiteSpace($env:DATAPHYRE_COMPOSER)) {
		return Resolve-ComposerPath -Composer $env:DATAPHYRE_COMPOSER
	}
	$command = Get-Command composer -ErrorAction SilentlyContinue
	if ($null -ne $command) {
		return $command.Source
	}
	$cached = Join-Path ([System.IO.Path]::GetTempPath()) 'composer-dataphyre-smoke.phar'
	if (Test-Path $cached -PathType Leaf) {
		return $cached
	}
	throw 'Composer was not found. Put composer on PATH, pass -Composer <path>, or set DATAPHYRE_COMPOSER.'
}

function Invoke-Composer {
	param(
		[string]$PhpPath,
		[string]$ComposerPath,
		[string]$WorkingDirectory,
		[string[]]$Arguments
	)
	$global:LASTEXITCODE = 0
	$previousErrorActionPreference = $ErrorActionPreference
	try {
		$ErrorActionPreference = 'Continue'
		if ($ComposerPath -match '\.phar$') {
			& $PhpPath $ComposerPath @Arguments --working-dir=$WorkingDirectory
		}
		else {
			& $ComposerPath @Arguments --working-dir=$WorkingDirectory
		}
	}
	finally {
		$ErrorActionPreference = $previousErrorActionPreference
	}
	$exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if ($exitCode -ne 0) {
		throw "Composer command failed with exit code $exitCode."
	}
}

function Assert-MissingPath {
	param(
		[string]$Root,
		[string]$RelativePath
	)
	$path = Join-Path $Root ($RelativePath -replace '/', [IO.Path]::DirectorySeparatorChar)
	if (Test-Path $path) {
		throw "Public package boundary failed; unexpected path exists: $RelativePath"
	}
}

$phpPath = Resolve-ExecutablePath -Value $Php -EnvironmentName 'DATAPHYRE_PHP' -CommandName 'php' -FailureMessage 'PHP executable was not found. Put php on PATH, pass -Php <path>, or set DATAPHYRE_PHP.'
$composerPath = Resolve-ComposerPath -Composer $Composer

$usingPreparedPackagePath = -not [string]::IsNullOrWhiteSpace($PackagePath)
if ($usingPreparedPackagePath) {
	$PackagePath = (Resolve-Path $PackagePath).Path
	$RepositoryType = 'path'
	$RepositoryUrl = $PackagePath
}
elseif ([string]::IsNullOrWhiteSpace($RepositoryUrl)) {
	throw 'Pass -PackagePath for a prepared package tree or -RepositoryUrl for a Composer repository.'
}

$consumerRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('dataphyre-composer-consumer-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $consumerRoot | Out-Null

try {
	$repository = if ($RepositoryType -eq 'path') {
		[ordered]@{
			type = 'path'
			url = $RepositoryUrl
			options = [ordered]@{ symlink = $false }
		}
	}
	else {
		[ordered]@{
			type = 'vcs'
			url = $RepositoryUrl
		}
	}
	$consumerComposer = [ordered]@{
		name = 'dataphyre/composer-consumer-smoke'
		version = '1.0.0'
		require = [ordered]@{
			'dataphyre/dataphyre' = $Version
		}
		repositories = @($repository)
		'minimum-stability' = 'dev'
		'prefer-stable' = $true
	}
	$composerJson = $consumerComposer | ConvertTo-Json -Depth 8
	[System.IO.File]::WriteAllText((Join-Path $consumerRoot 'composer.json'), $composerJson, [System.Text.UTF8Encoding]::new($false))

	Write-Host "Checking Composer consumer install in $consumerRoot"
	Write-Host "Repository: $RepositoryType $RepositoryUrl"
	Write-Host "Requirement: dataphyre/dataphyre $Version"
	Invoke-Composer -PhpPath $phpPath -ComposerPath $composerPath -WorkingDirectory $consumerRoot -Arguments @('install', '--no-interaction', '--no-progress', '--no-scripts', '--prefer-dist')

	$packageRoot = Join-Path $consumerRoot 'vendor/dataphyre/dataphyre'
	if (-not (Test-Path (Join-Path $packageRoot 'runtime/bootstrap.php') -PathType Leaf)) {
		throw 'Installed package is missing runtime/bootstrap.php.'
	}
	if (-not (Test-Path (Join-Path $packageRoot 'composer.json') -PathType Leaf)) {
		throw 'Installed package is missing composer.json.'
	}
	if (-not (Test-Path (Join-Path $packageRoot 'docs/PACKAGE.md') -PathType Leaf)) {
		throw 'Installed package is missing docs/PACKAGE.md.'
	}
	if (-not (Test-Path (Join-Path $packageRoot 'installer/init_consumer.php') -PathType Leaf)) {
		throw 'Installed package is missing installer/init_consumer.php.'
	}
	if (($RequireReleaseManifest -or $usingPreparedPackagePath) -and -not (Test-Path (Join-Path $packageRoot 'RELEASE_MANIFEST.json') -PathType Leaf)) {
		throw 'Installed package is missing RELEASE_MANIFEST.json.'
	}
	if (-not $AllowDevArtifacts) {
		foreach ($relative in @('dev', 'tools', '.github', '.codex-tmp', '.tmp', 'vendor', 'composer.lock', 'plugins/mcp', 'runtime/modules/sentinel', 'runtime/modules/profanity')) {
			Assert-MissingPath -Root $packageRoot -RelativePath $relative
		}
	}

	$lockPath = Join-Path $consumerRoot 'composer.lock'
	$lock = Get-Content -Raw $lockPath | ConvertFrom-Json
	$package = @($lock.packages | Where-Object { $_.name -eq 'dataphyre/dataphyre' })[0]
	if ($null -eq $package) {
		throw 'composer.lock does not contain dataphyre/dataphyre.'
	}

	if (-not $SkipBoot) {
		if (Test-Path (Join-Path $packageRoot 'flight_sheet.php')) {
			throw 'Installed package unexpectedly contains an install-local flight_sheet.php.'
		}
		$initOutput = & $phpPath -d display_errors=1 (Join-Path $packageRoot 'installer/init_consumer.php') "--root=$consumerRoot"
		$initExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
		if (-not $? -or $initExitCode -ne 0) {
			throw "Installed package consumer initializer failed with exit code $initExitCode."
		}
		$initPayload = ($initOutput | Out-String).Trim() | ConvertFrom-Json
		if ($initPayload.ok -ne $true) {
			throw 'Installed package consumer initializer did not return ok=true.'
		}
		$output = & $phpPath -d display_errors=1 (Join-Path $consumerRoot 'index.php')
		$exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
		if (-not $? -or $exitCode -ne 0) {
			throw "Installed package consumer-root minimal boot failed with exit code $exitCode."
		}
		$payload = ($output | Out-String).Trim() | ConvertFrom-Json
		if ($payload.ok -ne $true -or $payload.runtime -ne 'dataphyre') {
			throw 'Installed package consumer-root minimal boot did not return the expected Dataphyre payload.'
		}
		$expectedProjectRoot = ((Resolve-Path $consumerRoot).Path.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar) -replace '\\', '/'
		$actualProjectRoot = ([string]$payload.project_root) -replace '\\', '/'
		if ($actualProjectRoot -ne $expectedProjectRoot) {
			throw "Installed package consumer-root minimal boot resolved project_root '$actualProjectRoot', expected '$expectedProjectRoot'."
		}
		$expectedRuntimeRoot = ((Resolve-Path (Join-Path $packageRoot 'runtime')).Path.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar) -replace '\\', '/'
		$actualRuntimeRoot = ([string]$payload.runtime_root) -replace '\\', '/'
		if ($actualRuntimeRoot -ne $expectedRuntimeRoot) {
			throw "Installed package consumer-root minimal boot resolved runtime_root '$actualRuntimeRoot', expected '$expectedRuntimeRoot'."
		}
	}

	Write-Host "Composer consumer smoke passed for dataphyre/dataphyre $($package.version)."
}
finally {
	if (-not $KeepTemp -and (Test-Path $consumerRoot)) {
		Remove-Item -LiteralPath $consumerRoot -Recurse -Force
	}
	elseif ($KeepTemp) {
		Write-Host "Kept consumer root: $consumerRoot"
	}
}
