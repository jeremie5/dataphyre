[CmdletBinding()]
param(
	[string]$Root
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
	$Root = (Resolve-Path -LiteralPath (Join-Path $scriptDirectory '..\..\..')).Path
}
$Root = (Resolve-Path -LiteralPath $Root).Path
$checker = Join-Path $Root 'dev/tools/public/check_trace_dialback_usage.ps1'
if (-not (Test-Path -LiteralPath $checker -PathType Leaf)) {
	throw "Missing trace/dialback checker: $checker"
}

$powerShell = (Get-Process -Id $PID).Path
$failures = New-Object System.Collections.Generic.List[string]

function Add-Failure {
	param([string]$Message)
	$script:failures.Add($Message) | Out-Null
	Write-Host "FAIL: $Message"
}

function Write-FixtureFile {
	param(
		[string]$Path,
		[string]$Contents
	)
	$directory = Split-Path -Parent $Path
	if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
		New-Item -ItemType Directory -Path $directory -Force | Out-Null
	}
	Set-Content -LiteralPath $Path -Value $Contents -Encoding utf8NoBOM -NoNewline
}

function Invoke-GitChecked {
	param(
		[string]$Repository,
		[string[]]$Arguments
	)
	$global:LASTEXITCODE = 0
	$output = @(& git -C $Repository @Arguments 2>&1)
	$exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if ($exitCode -ne 0) {
		throw "Git command failed with exit code ${exitCode}: $($output -join "`n")"
	}
}

function Invoke-Checker {
	param(
		[string]$FixtureRoot,
		[string]$SourceSet = ''
	)
	$arguments = @('-NoLogo', '-NoProfile', '-File', $script:checker, '-Root', $FixtureRoot)
	if (-not [string]::IsNullOrWhiteSpace($SourceSet)) {
		$arguments += @('-SourceSet', $SourceSet)
	}
	$global:LASTEXITCODE = 0
	$output = @(& $script:powerShell @arguments 2>&1)
	$exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	return [pscustomobject]@{
		ExitCode = $exitCode
		Text = ($output | ForEach-Object { [string]$_ }) -join "`n"
	}
}

function Assert-CheckerFailure {
	param(
		[object]$Result,
		[string]$Needle,
		[string]$Label
	)
	if ($Result.ExitCode -eq 0) {
		Add-Failure "$Label unexpectedly passed."
		return
	}
	if (-not $Result.Text.Contains($Needle)) {
		Add-Failure "$Label did not report expected trap '$Needle'."
	}
	if (-not $Result.Text.Contains('Trace/dialback usage check failed with 1 issue(s).')) {
		Add-Failure "$Label did not complete the checker with exactly one finding."
	}
}

function Assert-CheckerSuccess {
	param(
		[object]$Result,
		[string]$Label
	)
	if ($Result.ExitCode -ne 0) {
		Add-Failure "$Label failed: $($Result.Text)"
		return
	}
	if (-not $Result.Text.Contains('Trace/dialback usage checks passed.')) {
		Add-Failure "$Label did not report a completed passing audit."
	}
}

$workspace = Join-Path ([System.IO.Path]::GetTempPath()) ('dataphyre-trace-source-set-' + [guid]::NewGuid().ToString('N'))
$gitFixture = Join-Path $workspace 'git-checkout'
$archiveFixture = Join-Path $workspace 'archive-extract'

try {
	New-Item -ItemType Directory -Path $gitFixture -Force | Out-Null
	Invoke-GitChecked $gitFixture @('init', '--quiet')
	Invoke-GitChecked $gitFixture @('config', 'user.email', 'release-self-test@dataphyre.invalid')
	Invoke-GitChecked $gitFixture @('config', 'user.name', 'Dataphyre Release Self-Test')
	Write-FixtureFile (Join-Path $gitFixture '.gitignore') "/private ignored/`n"
	Write-FixtureFile (Join-Path $gitFixture 'docs/EXTENSION_POINTS.md') ''
	Write-FixtureFile (Join-Path $gitFixture 'empty.php') ''
	Write-FixtureFile (Join-Path $gitFixture 'tracked [literal trap].php') "<?php \dataphyre\core::dialback('tracked.trap');"
	Write-FixtureFile (Join-Path $gitFixture 'vendor/tracked-vendor.php') "<?php \dataphyre\core::dialback('vendor.excluded');"
	Invoke-GitChecked $gitFixture @('add', '.gitignore', 'docs/EXTENSION_POINTS.md', 'empty.php', 'tracked [literal trap].php', 'vendor/tracked-vendor.php')

	$trackedResult = Invoke-Checker $gitFixture 'ReleaseOwned'
	Assert-CheckerFailure $trackedResult 'tracked.trap' 'ReleaseOwned tracked trap'

	Write-FixtureFile (Join-Path $gitFixture 'tracked [literal trap].php') '<?php $tracked=true;'
	if (-not $IsWindows) {
		$newlineRelativePath = "tracked`nnewline.php"
		Write-FixtureFile (Join-Path $gitFixture $newlineRelativePath) "<?php \dataphyre\core::dialback('newline.trap');"
		Invoke-GitChecked $gitFixture @('add', '--', $newlineRelativePath)
		$newlineResult = Invoke-Checker $gitFixture 'ReleaseOwned'
		Assert-CheckerFailure $newlineResult 'newline.trap' 'ReleaseOwned NUL-delimited newline-path trap'
		Write-FixtureFile (Join-Path $gitFixture $newlineRelativePath) '<?php $newline=true;'
	}
	Write-FixtureFile (Join-Path $gitFixture 'nonignored trap.php') "<?php \dataphyre\core::dialback('nonignored.trap');"
	$nonignoredResult = Invoke-Checker $gitFixture 'ReleaseOwned'
	Assert-CheckerFailure $nonignoredResult 'nonignored.trap' 'ReleaseOwned nonignored-untracked trap'

	Write-FixtureFile (Join-Path $gitFixture 'nonignored trap.php') '<?php $nonignored=true;'
	Write-FixtureFile (Join-Path $gitFixture 'private ignored/ignored.php') "<?php \dataphyre\core::dialback('ignored.private');"
	$releaseOwnedIgnoredResult = Invoke-Checker $gitFixture 'ReleaseOwned'
	Assert-CheckerSuccess $releaseOwnedIgnoredResult 'ReleaseOwned ignored/private exclusion'
	if ($releaseOwnedIgnoredResult.Text.Contains('ignored.private')) {
		Add-Failure 'ReleaseOwned inspected an ignored/private trap.'
	}
	if (-not $releaseOwnedIgnoredResult.Text.Contains('1 ignored/private source file(s) are outside this release gate')) {
		Add-Failure 'ReleaseOwned did not disclose its ignored/private source count.'
	}

	$filesystemIgnoredResult = Invoke-Checker $gitFixture 'Filesystem'
	Assert-CheckerFailure $filesystemIgnoredResult 'ignored.private' 'Filesystem ignored/private trap'

	New-Item -ItemType Directory -Path $archiveFixture -Force | Out-Null
	Write-FixtureFile (Join-Path $archiveFixture '.gitignore') "/private archive/`n"
	Write-FixtureFile (Join-Path $archiveFixture 'docs/EXTENSION_POINTS.md') ''
	Write-FixtureFile (Join-Path $archiveFixture 'empty.md') ''
	Write-FixtureFile (Join-Path $archiveFixture 'empty.php') ''
	Write-FixtureFile (Join-Path $archiveFixture 'private archive/archive.php') "<?php \dataphyre\core::dialback('archive.private');"
	$archiveResult = Invoke-Checker $archiveFixture
	Assert-CheckerFailure $archiveResult 'archive.private' 'Extracted non-Git archive recursive trap'
	if (-not $archiveResult.Text.Contains('Source set: Filesystem')) {
		Add-Failure 'Extracted non-Git archive did not use the complete recursive source gate.'
	}
}
finally {
	if (Test-Path -LiteralPath $workspace) {
		Remove-Item -LiteralPath $workspace -Recurse -Force
	}
}

if ($failures.Count -gt 0) {
	Write-Host ''
	Write-Host "Trace/dialback source-set self-test failed with $($failures.Count) issue(s)."
	exit 1
}

$global:LASTEXITCODE = 0
Write-Host 'Trace/dialback source-set self-test passed.'
