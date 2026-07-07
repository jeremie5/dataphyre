param(
	[string]$Scenario = "all",
	[int]$Iterations = 300,
	[int]$Warmup = 50,
	[string[]]$Profiles = @("baseline", "opcache", "opcache-jit"),
	[string]$Php = "C:\Users\jeref\OneDrive\Bureau\ShopiCore\.local\shopiro\php\php.exe"
)

$ErrorActionPreference = "Stop"

$scriptPath = Join-Path $PSScriptRoot "benchmark_hot_paths.php"
$phpRoot = Split-Path -Parent $Php
$opcacheDll = Join-Path $phpRoot "ext\php_opcache.dll"

function Get-ProfileArgs([string]$Profile) {
	switch ($Profile) {
		"baseline" {
			return @()
		}
		"opcache" {
			return @(
				"-d", "zend_extension=$opcacheDll",
				"-d", "opcache.enable_cli=1",
				"-d", "opcache.jit_buffer_size=0",
				"-d", "opcache.jit=disable"
			)
		}
		"opcache-jit" {
			return @(
				"-d", "zend_extension=$opcacheDll",
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

if(!(Test-Path -LiteralPath $Php)){
	throw "PHP binary not found: $Php"
}

foreach($profile in $Profiles){
	if($profile -ne "baseline" -and !(Test-Path -LiteralPath $opcacheDll)){
		throw "Opcache extension not found: $opcacheDll"
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
