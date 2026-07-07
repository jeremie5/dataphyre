[CmdletBinding()]
param(
	[string]$Root,
	[string]$Php,
	[switch]$AllowMissingPhp,
	[switch]$Help
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Show-Usage {
	@'
Usage:
  ./dev/tools/public/lint_php.ps1 [-Root <repo>] [-Php <path-or-command>] [-AllowMissingPhp]

Options:
  -Root             Dataphyre source checkout root. Defaults to the repository root.
  -Php              PHP executable path or command name. Defaults to DATAPHYRE_PHP, then php on PATH.
  -AllowMissingPhp  Exit successfully with SKIP when PHP is not available.
  -Help             Show this help text.

The lint pass scans real PHP files and skips generated state, logs, cache, vendor,
and non-PHP fixtures that only contain PHP-like text.
'@ | Write-Host
}

if ($Help) {
	Show-Usage
	exit 0
}

if ([string]::IsNullOrWhiteSpace($Root)) {
	$scriptDirectory = if ([string]::IsNullOrWhiteSpace($PSScriptRoot)) {
		Split-Path -Parent $MyInvocation.MyCommand.Path
	}
	else {
		$PSScriptRoot
	}
	$Root = (Resolve-Path (Join-Path $scriptDirectory '..\..\..')).Path
}
$Root = (Resolve-Path $Root).Path
$phpSource = $null
if ([string]::IsNullOrWhiteSpace($Php) -and -not [string]::IsNullOrWhiteSpace($env:DATAPHYRE_PHP)) {
	$Php = $env:DATAPHYRE_PHP
}
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
	$message = 'PHP executable was not found. Put php on PATH, pass -Php <path>, or set DATAPHYRE_PHP.'
	if ($AllowMissingPhp) {
		Write-Host "SKIP: $message"
		exit 0
	}
	Write-Host "FAIL: $message"
	exit 1
}

$ExcludedPrefixes = @(
	'.git/',
	'cache/',
	'logs/',
	'runtime/cache/',
	'runtime/logs/',
	'vendor/'
)

function Get-RelativePath {
	param([string]$Path)
	$fullPath = (Resolve-Path $Path).Path
	$rootPath = $script:Root.TrimEnd('\', '/')
	if ($fullPath.StartsWith($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
		return $fullPath.Substring($rootPath.Length).TrimStart('\', '/') -replace '\\', '/'
	}
	return $fullPath -replace '\\', '/'
}

function Test-Excluded {
	param([string]$RelativePath)
	foreach ($prefix in $script:ExcludedPrefixes) {
		if ($RelativePath.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
			return $true
		}
	}
	return $false
}

function Test-StartsWithPhpOpenTag {
	param([string]$Path)
	$stream = [System.IO.File]::OpenRead($Path)
	try {
		if ($stream.Length -lt 5) {
			return $false
		}
		$buffer = New-Object byte[] 5
		$read = $stream.Read($buffer, 0, 5)
		if ($read -lt 5) {
			return $false
		}
		return $buffer[0] -eq 0x3c -and
			$buffer[1] -eq 0x3f -and
			$buffer[2] -eq 0x70 -and
			$buffer[3] -eq 0x68 -and
			$buffer[4] -eq 0x70
	}
	finally {
		$stream.Dispose()
	}
}

Write-Host "Linting PHP files at $Root"
Write-Host "Using PHP: $phpSource"

$linted = 0
$skipped = 0
$failed = 0

$files = Get-ChildItem -Path $Root -Recurse -File -Force | Where-Object {
	$_.Extension.Equals('.php', [System.StringComparison]::OrdinalIgnoreCase) -or
	$_.Name.EndsWith('.php-', [System.StringComparison]::OrdinalIgnoreCase)
}

foreach ($file in $files) {
	$relative = Get-RelativePath $file.FullName
	if (Test-Excluded $relative) {
		$skipped++
		continue
	}
	if (-not (Test-StartsWithPhpOpenTag $file.FullName)) {
		Write-Host "Skipping non-PHP fixture: $relative"
		$skipped++
		continue
	}
	& $phpSource -l $file.FullName
	if ($LASTEXITCODE -ne 0) {
		$failed++
		continue
	}
	$linted++
}

Write-Host ''
Write-Host "PHP linted $linted file(s); skipped $skipped file(s)."

if ($failed -gt 0) {
	Write-Host "PHP lint failed for $failed file(s)."
	exit 1
}

Write-Host 'PHP lint passed.'
