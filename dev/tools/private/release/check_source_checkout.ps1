[CmdletBinding()]
param(
	[string]$Root,
	[string]$Php,
	[string]$Composer,
	[int]$WarningLimit = 200,
	[switch]$NoSecretScan,
	[switch]$SkipPreparedExport,
	[switch]$SkipComposerConsumer
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Root)) {
	$scriptDirectory = if ([string]::IsNullOrWhiteSpace($PSScriptRoot)) {
		Split-Path -Parent $MyInvocation.MyCommand.Path
	}
	else {
		$PSScriptRoot
	}
	$Root = (Resolve-Path (Join-Path $scriptDirectory '..\..\..\..')).Path
}
$Root = (Resolve-Path $Root).Path

if ([string]::IsNullOrWhiteSpace($Php) -and -not [string]::IsNullOrWhiteSpace($env:DATAPHYRE_PHP)) {
	$Php = $env:DATAPHYRE_PHP
}

$phpSource = $null
if ([string]::IsNullOrWhiteSpace($Php)) {
	$phpCommand = Get-Command php -ErrorAction SilentlyContinue
	if ($null -ne $phpCommand) {
		$phpSource = $phpCommand.Source
	}
}
elseif (Test-Path $Php -PathType Leaf) {
	$phpSource = (Resolve-Path $Php).Path
}
else {
	$phpCommand = Get-Command $Php -ErrorAction SilentlyContinue
	if ($null -ne $phpCommand) {
		$phpSource = $phpCommand.Source
	}
}

if ([string]::IsNullOrWhiteSpace($phpSource)) {
	Write-Host 'FAIL: PHP executable was not found. Put php on PATH, pass -Php <path>, or set DATAPHYRE_PHP.'
	exit 1
}

function Invoke-CheckedStep {
	param(
		[string]$Name,
		[scriptblock]$Step
	)
	$global:LASTEXITCODE = 0
	& $Step
	$exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if (-not $? -or $exitCode -ne 0) {
		if ($exitCode -eq 0) {
			$exitCode = 1
		}
		Write-Host "FAIL: $Name failed with exit code $exitCode."
		exit $exitCode
	}
}

$privateToolsDirectory = Join-Path $Root 'dev/tools/private/release'
$publicToolsDirectory = Join-Path $Root 'dev/tools/public'
$checkRelease = Join-Path $privateToolsDirectory 'check_release.ps1'
$checkPublicExport = Join-Path $privateToolsDirectory 'check_public_export.ps1'
$checkReleaseArchive = Join-Path $privateToolsDirectory 'check_release_archive.ps1'
$preparePublicExport = Join-Path $privateToolsDirectory 'prepare_public_export.ps1'
$checkComposerConsumer = Join-Path $privateToolsDirectory 'check_composer_consumer.ps1'
$lintPhp = Join-Path $publicToolsDirectory 'lint_php.ps1'
$checkTraceDialbackUsage = Join-Path $publicToolsDirectory 'check_trace_dialback_usage.ps1'
$checkTraceDialbackUsageSelfTest = Join-Path $publicToolsDirectory 'check_trace_dialback_usage_self_test.ps1'
$mcpSelfTest = Join-Path $publicToolsDirectory 'mcp_self_test.php'
$mcpLiveValidate = Join-Path $publicToolsDirectory 'mcp_live_validate.php'

Write-Host "Checking Dataphyre source checkout at $Root"
Write-Host "Using PHP: $phpSource"
Write-Host ''

Invoke-CheckedStep 'release checks' { & $checkRelease -Root $Root }

$sourceExportArgs = @{
	Root = $Root
	Php = $phpSource
	WarnOnly = $true
	WarningLimit = $WarningLimit
}
if ($NoSecretScan) {
	$sourceExportArgs.NoSecretScan = $true
}
Invoke-CheckedStep 'source public export audit' { & $checkPublicExport @sourceExportArgs }

Invoke-CheckedStep 'git archive release surface check' { & $checkReleaseArchive -Root $Root -Php $phpSource -WarningLimit $WarningLimit }

if (-not $SkipPreparedExport) {
	$export = Join-Path ([System.IO.Path]::GetTempPath()) ('dataphyre-export-' + [guid]::NewGuid().ToString('N'))
	try {
		Invoke-CheckedStep 'prepared public export' { & $preparePublicExport -Output $export -Php $phpSource }
		if (-not $SkipComposerConsumer) {
			$consumerArgs = @{
				PackagePath = $export
				Version = '*@dev'
				Php = $phpSource
			}
			if (-not [string]::IsNullOrWhiteSpace($Composer)) {
				$consumerArgs.Composer = $Composer
			}
			Invoke-CheckedStep 'Composer consumer package smoke' { & $checkComposerConsumer @consumerArgs }
		}
	}
	finally {
		if (Test-Path $export) {
			Remove-Item -LiteralPath $export -Recurse -Force
		}
	}
}

Invoke-CheckedStep 'PHP lint' { & $lintPhp -Root $Root -Php $phpSource }
Invoke-CheckedStep 'trace/dialback source-set self-test' { & $checkTraceDialbackUsageSelfTest -Root $Root }
Invoke-CheckedStep 'trace/dialback release-owned usage checks' { & $checkTraceDialbackUsage -Root $Root -SourceSet ReleaseOwned }
Invoke-CheckedStep 'MCP self-test' { & $phpSource $mcpSelfTest }
Invoke-CheckedStep 'MCP live validation' { & $phpSource $mcpLiveValidate --php $phpSource }

Write-Host ''
Write-Host 'Dataphyre source checkout checks passed.'
