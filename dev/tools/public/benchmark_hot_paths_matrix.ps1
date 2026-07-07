[CmdletBinding()]
param(
	[string]$Scenario = "all",
	[int]$Iterations = 300,
	[int]$Warmup = 50,
	[string[]]$Profiles = @("baseline", "opcache", "opcache-jit"),
	[string]$Php,
	[string]$OpcacheExtension,
	[switch]$Help
)

$ErrorActionPreference = "Stop"

function Show-Usage {
	@'
Usage:
  ./dev/tools/public/benchmark_hot_paths_matrix.ps1 [-Scenario <name>] [-Iterations <n>] [-Warmup <n>] [-Profiles baseline,opcache,opcache-jit] [-Php <path-or-command>] [-OpcacheExtension <path>]

Options:
  -Scenario          Benchmark scenario name, or all. Default: all.
  -Iterations        Measurement iterations per scenario. Default: 300.
  -Warmup            Warmup iterations per scenario. Default: 50.
  -Profiles          Any of baseline, opcache, opcache-jit.
  -Php               PHP executable path or command name. Defaults to DATAPHYRE_PHP, then php on PATH.
  -OpcacheExtension  zend_extension path for opcache/JIT profiles when auto-detection is not enough.
  -Help              Show this help text.
'@ | Write-Host
}

if ($Help) {
	Show-Usage
	exit 0
}

function Resolve-PhpPath([string]$RequestedPhp) {
	if ([string]::IsNullOrWhiteSpace($RequestedPhp) -and -not [string]::IsNullOrWhiteSpace($env:DATAPHYRE_PHP)) {
		$RequestedPhp = $env:DATAPHYRE_PHP
	}
	if ([string]::IsNullOrWhiteSpace($RequestedPhp)) {
		$phpCommand = Get-Command php -ErrorAction SilentlyContinue
		if ($null -ne $phpCommand) {
			return $phpCommand.Source
		}
		return $null
	}
	if (Test-Path $RequestedPhp -PathType Leaf) {
		return (Resolve-Path $RequestedPhp).Path
	}
	$requestedCommand = Get-Command $RequestedPhp -ErrorAction SilentlyContinue
	if ($null -ne $requestedCommand) {
		return $requestedCommand.Source
	}
	return $null
}

function Resolve-OpcachePath([string]$RequestedOpcacheExtension, [string]$ResolvedPhp) {
	if (-not [string]::IsNullOrWhiteSpace($RequestedOpcacheExtension)) {
		if (Test-Path $RequestedOpcacheExtension -PathType Leaf) {
			return (Resolve-Path $RequestedOpcacheExtension).Path
		}
		throw "Opcache extension not found: $RequestedOpcacheExtension"
	}
	$phpRoot = Split-Path -Parent $ResolvedPhp
	$candidates = @(
		(Join-Path $phpRoot "ext\php_opcache.dll"),
		(Join-Path $phpRoot "ext\opcache.so"),
		(Join-Path $phpRoot "php_opcache.dll"),
		(Join-Path $phpRoot "opcache.so")
	)
	foreach ($candidate in $candidates) {
		if (Test-Path $candidate -PathType Leaf) {
			return (Resolve-Path $candidate).Path
		}
	}
	return $null
}

$phpSource = Resolve-PhpPath $Php
if ([string]::IsNullOrWhiteSpace($phpSource)) {
	throw "PHP executable was not found. Put php on PATH, pass -Php <path>, or set DATAPHYRE_PHP."
}
$Php = $phpSource
$opcachePath = Resolve-OpcachePath $OpcacheExtension $Php
$scriptPath = Join-Path $PSScriptRoot "benchmark_hot_paths.php"

function Get-ProfileArgs([string]$Profile) {
	switch ($Profile) {
		"baseline" {
			return @()
		}
		"opcache" {
			return @(
				"-d", "zend_extension=$opcachePath",
				"-d", "opcache.enable_cli=1",
				"-d", "opcache.jit_buffer_size=0",
				"-d", "opcache.jit=disable"
			)
		}
		"opcache-jit" {
			return @(
				"-d", "zend_extension=$opcachePath",
				"-d", "opcache.enable_cli=1",
				"-d", "opcache.jit_buffer_size=64M",
				"-d", "opcache.jit=tracing"
			)
		}
		default {
			throw "Unknown profile '$Profile'. Use baseline, opcache, or opcache-jit."
		}
	}
}

foreach($profile in $Profiles){
	if($profile -ne "baseline" -and [string]::IsNullOrWhiteSpace($opcachePath)){
		throw "Opcache extension was not found near PHP. Pass -OpcacheExtension <path> for profile '$profile'."
	}
}

$runs = @()
foreach($profile in $Profiles){
	$args = @(Get-ProfileArgs $profile) + @($scriptPath, $Scenario, [string]$Iterations, [string]$Warmup)
	$started = Get-Date
	$output = & $Php @args
	if($LASTEXITCODE -ne 0){
		throw "Benchmark profile '$profile' failed with exit code $LASTEXITCODE."
	}
	$payload = $output | ConvertFrom-Json
	$runs += [pscustomobject]@{
		profile = $profile
		started_at = $started.ToString("o")
		ini_args = @(Get-ProfileArgs $profile)
		results = $payload.results
	}
}

[pscustomobject]@{
	php = (& $Php -r "echo PHP_VERSION;")
	scenario = $Scenario
	iterations_arg = $Iterations
	warmup_arg = $Warmup
	note = "benchmark_hot_paths.php times callbacks after file/class loading; opcache-only mainly affects code generation inside this process, while opcache-jit can affect warmed callback hot loops."
	runs = $runs
} | ConvertTo-Json -Depth 20
