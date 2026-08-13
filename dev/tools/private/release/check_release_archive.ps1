[CmdletBinding()]
param(
	[string]$Root,
	[string]$Ref = 'HEAD',
	[string]$Php,
	[int]$WarningLimit = 0,
	[switch]$NoSecretScan
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$scriptDirectory = if ([string]::IsNullOrWhiteSpace($PSScriptRoot)) {
	Split-Path -Parent $MyInvocation.MyCommand.Path
}
else {
	$PSScriptRoot
}
if ([string]::IsNullOrWhiteSpace($Root)) {
	$Root = (Resolve-Path (Join-Path $scriptDirectory '..\..\..\..')).Path
}
$Root = (Resolve-Path $Root).Path

$checkPublicExport = Join-Path $scriptDirectory 'check_public_export.ps1'
if (-not (Test-Path $checkPublicExport -PathType Leaf)) {
	throw "Missing public export checker: $checkPublicExport"
}

$archiveRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('dataphyre-release-archive-' + [guid]::NewGuid().ToString('N'))
$zipPath = Join-Path $archiveRoot 'source.zip'
$extractPath = Join-Path $archiveRoot 'extract'
New-Item -ItemType Directory -Path $archiveRoot | Out-Null

try {
	Write-Host "Checking Dataphyre git archive release surface for $Ref"
	$global:LASTEXITCODE = 0
	$resolvedRefOutput = @(& git -C $Root rev-parse --verify "${Ref}^{commit}" 2>&1)
	$resolvedRefSucceeded = $?
	$resolvedRefExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if (-not $resolvedRefSucceeded -or $resolvedRefExitCode -ne 0 -or $resolvedRefOutput.Count -ne 1 -or
		[string]$resolvedRefOutput[0] -notmatch '^[0-9a-fA-F]{40,64}$') {
		throw "Unable to resolve $Ref to one immutable commit SHA (exit code $resolvedRefExitCode)."
	}
	$resolvedRef = ([string]$resolvedRefOutput[0]).ToLowerInvariant()
	Write-Host "Resolved archive ref to immutable commit $resolvedRef"

	$executablePaths = @(
		'bin/dataphyre-test',
		'bin/dataphyre-test-docker'
	)
	$global:LASTEXITCODE = 0
	$treeOutput = @(& git -C $Root ls-tree $resolvedRef -- @executablePaths 2>&1)
	$treeSucceeded = $?
	$treeExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if (-not $treeSucceeded -or $treeExitCode -ne 0) {
		throw "git ls-tree failed with exit code $treeExitCode."
	}
	$executableModes = @{}
	foreach ($line in $treeOutput) {
		if ([string]$line -match '^([0-9]{6})\s+\S+\s+[0-9a-fA-F]+\t(.+)$') {
			$executableModes[$Matches[2] -replace '\\', '/'] = $Matches[1]
		}
	}
	foreach ($executablePath in $executablePaths) {
		if (-not $executableModes.ContainsKey($executablePath)) {
			throw "Git tree is missing required executable: $executablePath"
		}
		if ($executableModes[$executablePath] -ne '100755') {
			throw "Git tree mode for $executablePath must be 100755; found $($executableModes[$executablePath])."
		}
	}
	Write-Host 'OK: canonical test CLI Git executable modes are 100755'

	& git -C $Root archive --format=zip --output=$zipPath $resolvedRef
	$archiveExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if ($archiveExitCode -ne 0) {
		throw "git archive failed with exit code $archiveExitCode."
	}
	Expand-Archive -LiteralPath $zipPath -DestinationPath $extractPath
	$entries = @(Get-ChildItem -LiteralPath $extractPath -Force)
	if ($entries.Count -eq 1 -and $entries[0].PSIsContainer) {
		$publicRoot = $entries[0].FullName
	}
	else {
		$publicRoot = $extractPath
	}
	$args = @{
		Root = $publicRoot
		WarningLimit = $WarningLimit
	}
	if (-not [string]::IsNullOrWhiteSpace($Php)) {
		$args.Php = $Php
	}
	if ($NoSecretScan) {
		$args.NoSecretScan = $true
	}
	& $checkPublicExport @args
	$checkExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if (-not $? -or $checkExitCode -ne 0) {
		throw "Git archive release surface failed public export checks with exit code $checkExitCode."
	}
	Write-Host 'Dataphyre git archive release surface checks passed.'
}
finally {
	if (Test-Path $archiveRoot) {
		Remove-Item -LiteralPath $archiveRoot -Recurse -Force
	}
}
