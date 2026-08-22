[CmdletBinding()]
param(
	[string]$Root,
	[string]$RulesFile,
	[string]$Php,
	[int]$WarningLimit = 0,
	[switch]$WarnOnly,
	[switch]$NoSecretScan
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
if ([string]::IsNullOrWhiteSpace($RulesFile)) {
	$RulesFile = Join-Path $Root '.distignore'
}
else {
	$RulesFile = (Resolve-Path $RulesFile).Path
}

$failures = New-Object System.Collections.Generic.List[string]
$warningCount = 0
$suppressedWarningCount = 0

$RequiredFiles = @(
	'.distignore',
	'.dockerignore',
	'.editorconfig',
	'.gitattributes',
	'.gitignore',
	'bin/dataphyre-test',
	'bin/dataphyre-test-docker',
	'bin/dataphyre-mutate',
	'docker/testing/Dockerfile',
	'docker/testing/browser/package.json',
	'docs/AGENTS.md',
	'docs/AGENTIC_ENTERPRISE.md',
	'docs/ARCHITECTURE.md',
	'docs/CHANGELOG.md',
	'docs/CODE_OF_CONDUCT.md',
	'docs/CONFIGURATION.md',
	'docs/CONTRIBUTING.md',
	'docs/GETTING_STARTED.md',
	'LICENSE',
	'docs/MODULES.md',
	'docs/NOTICE.md',
	'docs/PACKAGE.md',
	'docs/README.md',
	'docs/RELEASE_MANIFEST.md',
	'docs/RELEASE_MANIFEST.schema.json',
	'docs/SECURITY.md',
	'docs/STABILITY.md',
	'docs/SUPPORT.md',
	'docs/THIRD_PARTY_NOTICES.md',
	'composer.json',
	'flight_sheet.example.php',
	'index.example.php',
	'config/README.md',
	'config/access.example.php',
	'config/cache.example.php',
	'config/mvc.example.php',
	'config/storage.example.php',
	'config/stripe.example.php',
	'config/supercookie.example.php',
	'config/tracelog.example.php',
	'examples/minimal/README.md',
	'plugins/README.md',
	'runtime/README.md',
	'runtime/bootstrap.php',
	'runtime/modules/core/Release/ApplicationReleasePreflightEvidence.php',
	'runtime/modules/core/kernel/application_release_preflight_realtime.php',
	'runtime/modules/core/kernel/application_runtime_activation_latch.php',
	'runtime/modules/core/kernel/application_runtime_cgi_environment.php',
	'runtime/modules/core/kernel/application_runtime_child_environment.php',
	'runtime/modules/core/kernel/application_runtime_database_identity.php',
	'runtime/modules/core/kernel/application_runtime_environment.php',
	'runtime/modules/core/kernel/application_runtime_one_shot.php',
	'runtime/modules/core/kernel/application_runtime_seed_evidence.php',
	'runtime/modules/core/kernel/application_runtime_one_shot_worker.php',
	'runtime/modules/core/kernel/application_runtime_php_fpm.conf',
	'runtime/modules/core/kernel/application_runtime_pre_exec.php',
	'runtime/modules/core/kernel/application_runtime_probe_state.php',
	'runtime/modules/core/kernel/application_runtime_process_broker.php',
	'runtime/modules/core/kernel/application_runtime_realtime_bootstrap.php',
	'runtime/modules/core/kernel/application_runtime_realtime_probe.php',
	'runtime/modules/core/kernel/application_runtime_realtime_server.php',
	'runtime/modules/core/kernel/application_runtime_release_probe.php',
	'runtime/modules/core/kernel/application_runtime_router.php',
	'runtime/modules/core/kernel/application_runtime_scheduler_gateway.php',
	'runtime/modules/core/kernel/application_runtime_scheduler_protocol.php',
	'runtime/modules/core/kernel/application_runtime_scheduler_state.php',
	'runtime/modules/core/kernel/application_runtime_status_probe.php',
	'runtime/modules/core/kernel/application_runtime_supervisor.php',
	'runtime/modules/core/kernel/application_runtime_web_gateway.php',
	'runtime/modules/core/kernel/realtime.php',
	'runtime/native/environment_fd/config.m4',
	'runtime/native/environment_fd/dataphyre_environment_fd.c',
	'runtime/modules/sql/Framework/RegisteredTableMaterializationCommand.php',
	'runtime/modules/sql/kernel/materialize_registered_tables.php',
	'runtime/modules/sql/kernel/managed_seeds.php',
	'runtime/modules/cache/documentation/Dataphyre_Cache.md',
	'runtime/modules/cache/Framework/SharedCacheProbeCommand.php',
	'runtime/modules/cache/kernel/cache.main.php',
	'runtime/modules/cache/kernel/shared_cache_probe.php',
	'runtime/modules/cache/version',
	'runtime/modules/testing/documentation/Dataphyre_Testing.md',
	'runtime/modules/testing/tooling/Runner.php',
	'runtime/modules/testing/tooling/bootstrap.php',
	'runtime/modules/testing/tooling/code_worker.php',
	'runtime/modules/testing/tooling/TestKit/Context.php',
	'runtime/modules/testing/version'
)

$VendoredPrefixes = @(
	'runtime/modules/stripe/src/',
	'runtime/modules/sql/third_party/',
	'vendor/',
	'.git/'
)

$ForbiddenPublicPrefixes = @(
	'.github/',
	'.codex-tmp/',
	'.tmp/',
	'cache/',
	'hosttmp/',
	'logs/',
	'runtime/cache/',
	'runtime/modules/core/unit_tests/fixtures/core-functions-unavailable-missing/cache/',
	'runtime/logs/',
	('runtime/modules/' + 'conting' + 'ency/'),
	('runtime/modules/' + 'cj' + 'dropshipping/'),
	('runtime/modules/' + 'shopiro' + '_devapi/'),
	'runtime/modules/profanity/',
	'runtime/modules/sentinel/',
	'tmp/'
)

$TextExtensions = @(
	'.css',
	'.distignore',
	'.editorconfig',
	'.gitattributes',
	'.gitignore',
	'.html',
	'.js',
	'.json',
	'.md',
	'.php',
	'.php-',
	'.ps1',
	'.txt',
	'.xml',
	'.yaml',
	'.yml'
)

$SecretPatterns = @(
	@{ Label = 'Stripe live secret key'; Pattern = 'sk_live_[A-Za-z0-9]+' },
	@{ Label = 'Stripe webhook secret'; Pattern = 'whsec_[A-Za-z0-9]+' },
	@{ Label = 'GitHub personal access token'; Pattern = 'gh[pousr]_[A-Za-z0-9_]{30,}' },
	@{ Label = 'Slack token'; Pattern = 'xox[baprs]-[A-Za-z0-9-]+' },
	@{ Label = 'Google API key'; Pattern = 'AIza[0-9A-Za-z_-]{35}' },
	@{ Label = 'private key block'; Pattern = '-----BEGIN (RSA |DSA |EC |OPENSSH )?PRIVATE KEY-----' }
)

$ForbiddenReleaseMarkers = @(
	@{ Label = 'legacy fallback runtime marker'; Pattern = '\b' + 'conting' + 'ency\b' },
	@{ Label = 'Shopiro CDN hostname'; Pattern = 'cdn\.shopiro\.ca' },
	@{ Label = 'Shopiro asset application marker'; Pattern = '\bshopiro' + 'cdn\b' },
	@{ Label = 'product-specific API profile marker'; Pattern = '\bshop' + 'iro\.(mobile|dev)' },
	@{ Label = 'private adapter module marker'; Pattern = '\bcj' + 'dropshipping\b' },
	@{ Label = 'private adapter module marker'; Pattern = '\bshopiro' + '_devapi\b' },
	@{ Label = 'product-specific example application marker'; Pattern = '\bvolum' + 'etrix\b' },
	@{ Label = 'product-specific example application marker'; Pattern = '\bnorth' + 'star\b' },
	@{ Label = 'product-specific example application marker'; Pattern = '\bexo' + 'dus\b' },
	@{ Label = 'product-specific local environment marker'; Pattern = '\bSHOP' + 'IRO_LOCAL_' },
	@{ Label = 'legacy asset class'; Pattern = '\bclass\s+' + 'cdn\b' },
	@{ Label = 'legacy embedded runtime module path'; Pattern = 'common/dataphyre/' + 'modules/' },
	@{ Label = 'embedded install absolute path'; Pattern = '/var/www/' + 'shopicore' },
	@{ Label = 'local Windows user path'; Pattern = '[A-Za-z]:\\' + 'Users\\' },
	@{ Label = 'cloud workspace path'; Pattern = '\bOne' + 'Drive\b' },
	@{ Label = 'developer home marker'; Pattern = '\bje' + 'ref\b' },
	@{ Label = 'desktop workspace marker'; Pattern = '\bBu' + 'reau\b' }
)

$ForbiddenProfanityMarkers = @(
	@{ Label = 'public profanity marker'; Pattern = '\bf' + 'uck(?:ing)?\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bsh' + 'it\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bbull' + 'sh' + 'it\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bb' + 'itch\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bass' + 'hole\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bbas' + 'tard\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bd' + 'ick\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bc' + 'ock\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bc' + 'unt\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bcr' + 'ap\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bwt' + 'f\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bfm' + 'l\b' },
	@{ Label = 'public profanity marker'; Pattern = '\bpi' + 'ss\b' }
)

function Add-Failure {
	param([string]$Message)
	$script:failures.Add($Message) | Out-Null
	Write-Host "FAIL: $Message"
}

function Add-ExportIssue {
	param([string]$Message)
	if ($script:WarnOnly) {
		$script:warningCount++
		if ($script:WarningLimit -le 0 -or $script:warningCount -le $script:WarningLimit) {
			Write-Host "WARN: $Message"
		}
		else {
			$script:suppressedWarningCount++
		}
		return
	}
	Add-Failure $Message
}

function Get-RelativePath {
	param([string]$Path)
	$fullPath = [System.IO.Path]::GetFullPath($Path)
	$rootPath = $script:Root.TrimEnd('\', '/')
	$rootWithSeparator = $rootPath + [System.IO.Path]::DirectorySeparatorChar
	if ($fullPath.Equals($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
		return '.'
	}
	if ($fullPath.StartsWith($rootWithSeparator, [System.StringComparison]::OrdinalIgnoreCase)) {
		return $fullPath.Substring($rootWithSeparator.Length) -replace '\\', '/'
	}
	return $fullPath -replace '\\', '/'
}

function Test-SourceCheckoutDevToolReference {
	param([string]$Text)
	$patterns = @(
		'(?i)(^|[\s`''"\(])(\./|php\s+)?dev[\\/]tools[\\/]',
		'(?i)(^|[\s`''"\(])common[\\/]dataphyre[\\/]dev[\\/]tools[\\/]'
	)
	foreach ($pattern in $patterns) {
		if ($Text -match $pattern) {
			return $true
		}
	}
	return $false
}

function Test-PrefixExcluded {
	param([string]$RelativePath)
	foreach ($prefix in $script:VendoredPrefixes) {
		if ($RelativePath.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
			return $true
		}
	}
	return $false
}

function Test-ForbiddenPublicPath {
	param([string]$RelativePath)
	foreach ($prefix in $script:ForbiddenPublicPrefixes) {
		if ($RelativePath.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
			return $true
		}
	}
	return $false
}

function ConvertTo-Rule {
	param([string]$Line)
	$raw = $Line.Trim()
	if ($raw -eq '' -or $raw.StartsWith('#')) {
		return $null
	}
	$negated = $raw.StartsWith('!')
	if ($negated) {
		$raw = $raw.Substring(1)
	}
	$anchored = $raw.StartsWith('/')
	$pattern = $raw.TrimStart('/') -replace '\\', '/'
	$directoryOnly = $pattern.EndsWith('/')
	if ($directoryOnly) {
		$pattern = $pattern.TrimEnd('/')
	}
	if ($pattern -eq '') {
		return $null
	}
	return [pscustomobject]@{
		Negated = $negated
		Anchored = $anchored
		Pattern = $pattern
		DirectoryOnly = $directoryOnly
	}
}

function Test-RuleMatch {
	param(
		[pscustomobject]$Rule,
		[string]$RelativePath
	)
	$relative = $RelativePath -replace '\\', '/'
	if ($Rule.DirectoryOnly) {
		if ($Rule.Anchored -or $Rule.Pattern.Contains('/')) {
			return $relative.Equals($Rule.Pattern, [System.StringComparison]::OrdinalIgnoreCase) -or
				$relative.StartsWith($Rule.Pattern + '/', [System.StringComparison]::OrdinalIgnoreCase)
		}
		foreach ($segment in ($relative -split '/')) {
			if ($segment.Equals($Rule.Pattern, [System.StringComparison]::OrdinalIgnoreCase)) {
				return $true
			}
		}
		return $false
	}
	$options = [System.Management.Automation.WildcardOptions]::IgnoreCase
	if ($Rule.Anchored -or $Rule.Pattern.Contains('/')) {
		$patternSegments = $Rule.Pattern -split '/'
		$relativeSegments = $relative -split '/'
		if ($patternSegments.Count -ne $relativeSegments.Count) {
			return $false
		}
		for ($index = 0; $index -lt $patternSegments.Count; $index++) {
			$segmentWildcard = [System.Management.Automation.WildcardPattern]::new($patternSegments[$index], $options)
			if (-not $segmentWildcard.IsMatch($relativeSegments[$index])) {
				return $false
			}
		}
		return $true
	}
	$wildcard = [System.Management.Automation.WildcardPattern]::new($Rule.Pattern, $options)
	return $wildcard.IsMatch($relative) -or $wildcard.IsMatch((Split-Path $relative -Leaf))
}

function Test-DistIgnored {
	param(
		[string]$RelativePath,
		[object[]]$Rules
	)
	$ignored = $false
	foreach ($rule in $Rules) {
		if (Test-RuleMatch $rule $RelativePath) {
			$ignored = -not $rule.Negated
		}
	}
	return $ignored
}

function Get-RepoFiles {
	Get-ChildItem -Path $script:Root -Recurse -File -Force | Where-Object {
		$relative = Get-RelativePath $_.FullName
		-not (Test-PrefixExcluded $relative)
	}
}

function Get-ContentScanFiles {
	Get-RepoFiles | Where-Object {
		$relative = Get-RelativePath $_.FullName
		-not (Test-ForbiddenPublicPath $relative)
	}
}

function Test-TextFile {
	param([System.IO.FileInfo]$File)
	if ($File.Length -gt 2MB) {
		return $false
	}
	$name = $File.Name
	if ($name -in @('.distignore', '.editorconfig', '.gitattributes', '.gitignore')) {
		return $true
	}
	foreach ($extension in $script:TextExtensions) {
		if ($name.EndsWith($extension, [System.StringComparison]::OrdinalIgnoreCase) -or
			$File.Extension.Equals($extension, [System.StringComparison]::OrdinalIgnoreCase)) {
			return $true
		}
	}
	return $false
}

Write-Host "Checking Dataphyre public export at $Root"
if ($WarnOnly) {
	Write-Host 'WarnOnly mode is enabled; forbidden export files and secret markers are reported as warnings.'
}

if (-not (Test-Path $RulesFile)) {
	Add-Failure "Rules file is missing: $RulesFile"
}

$rules = @()
if (Test-Path $RulesFile) {
	$rules = Get-Content $RulesFile | ForEach-Object { ConvertTo-Rule $_ } | Where-Object { $_ -ne $null }
	Write-Host "OK: loaded $($rules.Count) export ignore rule(s)"
}

foreach ($relative in $RequiredFiles) {
	$localRelative = $relative.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
	$candidate = Join-Path $Root $localRelative
	if (-not (Test-Path $candidate -PathType Leaf)) {
		Add-Failure "Required public file is missing: $relative"
	}
}
Write-Host 'OK: required public files checked'

if (-not $WarnOnly) {
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
		Add-Failure 'Canonical test CLI smoke requires PHP. Put php on PATH, pass -Php <path>, or set DATAPHYRE_PHP.'
	}
	else {
		$testCli = Join-Path $Root 'bin/dataphyre-test'
		if (Test-Path $testCli -PathType Leaf) {
			$global:LASTEXITCODE = 0
			$helpOutput = (& $phpSource $testCli --help 2>&1 | Out-String)
			$helpSucceeded = $?
			$helpExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
			if (-not $helpSucceeded -or $helpExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($helpOutput)) {
				Add-Failure "Canonical test CLI help smoke failed with exit code $helpExitCode"
			}

			$global:LASTEXITCODE = 0
			$inventoryOutput = (& $phpSource $testCli list --scope=framework --owner=testing --path=runner --kind=code --cases --no-test-cache --json 2>&1 | Out-String)
			$inventorySucceeded = $?
			$inventoryExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
			if (-not $inventorySucceeded -or $inventoryExitCode -ne 0) {
				Add-Failure "Canonical test CLI inventory smoke failed with exit code $inventoryExitCode"
			}
			else {
				try {
					$inventory = $inventoryOutput | ConvertFrom-Json -ErrorAction Stop
					$manifestCount = [int]$inventory.matched
					$caseCount = 0
					foreach ($testInventory in @($inventory.tests)) {
						$caseCount += [int]$testInventory.cases
					}
					if ($manifestCount -lt 1 -or $caseCount -lt 1) {
						Add-Failure 'Canonical test CLI inventory smoke returned an empty testing-runner inventory'
					}
					else {
						Write-Host "OK: canonical test CLI smoke passed ($manifestCount manifest(s), $caseCount case(s))"
					}
				}
				catch {
					Add-Failure "Canonical test CLI inventory smoke returned invalid JSON: $($_.Exception.Message)"
				}
			}
		}
	}
}

$sourceCheckout = Test-Path (Join-Path $Root 'dev') -PathType Container
if (-not $sourceCheckout) {
	$scriptDirectory = if ([string]::IsNullOrWhiteSpace($PSScriptRoot)) {
		Split-Path -Parent $MyInvocation.MyCommand.Path
	}
	else {
		$PSScriptRoot
	}
	$manifestVerifier = Join-Path $scriptDirectory 'verify_release_manifest.ps1'
	if (-not (Test-Path $manifestVerifier -PathType Leaf)) {
		Add-Failure 'Release manifest verifier is missing: dev/tools/verify_release_manifest.ps1'
	}
	else {
		$global:LASTEXITCODE = 0
		& $manifestVerifier -Root $Root
		$manifestCheckSucceeded = $?
		$manifestCheckExitCode = $LASTEXITCODE
		if (-not $manifestCheckSucceeded -or $manifestCheckExitCode -ne 0) {
			Add-Failure "Release manifest verification failed with exit code $manifestCheckExitCode"
		}
	}
	$preparedReadme = Join-Path $Root 'docs/README.md'
	$preparedContributing = Join-Path $Root 'docs/CONTRIBUTING.md'
	if (Test-Path $preparedReadme -PathType Leaf) {
		$preparedReadmeText = Get-Content -Raw $preparedReadme
		if (Test-SourceCheckoutDevToolReference $preparedReadmeText) {
			Add-Failure 'Prepared public export docs/README.md still contains non-shipped helper commands'
		}
		if ($preparedReadmeText -notmatch [regex]::Escape('Dataphyre is a modular PHP framework and runtime') -or
			$preparedReadmeText -notmatch [regex]::Escape('Runtime README') -or
			$preparedReadmeText -notmatch [regex]::Escape('Keep ownership obvious')) {
			Add-Failure 'Prepared public export docs/README.md is missing public overview guidance'
		}
	}
	if (Test-Path $preparedContributing -PathType Leaf) {
		$preparedContributingText = Get-Content -Raw $preparedContributing
		if (Test-SourceCheckoutDevToolReference $preparedContributingText) {
			Add-Failure 'Prepared public export docs/CONTRIBUTING.md still contains non-shipped helper commands'
		}
		if ($preparedContributingText -notmatch [regex]::Escape('Framework contributions, release checks, MCP publication validation')) {
			Add-Failure 'Prepared public export docs/CONTRIBUTING.md is missing project contribution boundary'
		}
		if ($preparedContributingText -notmatch [regex]::Escape('Most MCP users are application agents building applications with Dataphyre')) {
			Add-Failure 'Prepared public export docs/CONTRIBUTING.md is missing app-agent audience boundary'
		}
	}
	$preparedPackage = Join-Path $Root 'docs/PACKAGE.md'
	if (Test-Path $preparedPackage -PathType Leaf) {
		$preparedPackageText = Get-Content -Raw $preparedPackage
		if ($preparedPackageText -notmatch [regex]::Escape('RELEASE_MANIFEST.json') -or
			$preparedPackageText -notmatch [regex]::Escape('the JSON manifest describes the package artifact') -or
			$preparedPackageText -notmatch [regex]::Escape('generated from the') -or
			$preparedPackageText -notmatch [regex]::Escape('package contents')) {
			Add-Failure 'Prepared package docs/PACKAGE.md is missing generated release-manifest boundary'
		}
	}
	else {
		Add-Failure 'Prepared package is missing docs/PACKAGE.md package contract'
	}
	$preparedAgenticEnterprise = Join-Path $Root 'docs/AGENTIC_ENTERPRISE.md'
	if (Test-Path $preparedAgenticEnterprise -PathType Leaf) {
		$preparedAgenticEnterpriseText = Get-Content -Raw $preparedAgenticEnterprise
		if ($preparedAgenticEnterpriseText -match [regex]::Escape('../dev/PERFORMANCE.md') -or
			$preparedAgenticEnterpriseText -match [regex]::Escape('../dev/QUALITY_GATES.md')) {
			Add-Failure 'Prepared public export docs/AGENTIC_ENTERPRISE.md still links to non-shipped helper docs'
		}
	}
	else {
		Add-Failure 'Prepared public export is missing docs/AGENTIC_ENTERPRISE.md app-agent enterprise contract'
	}
	$preparedMcpDocumentation = Join-Path $Root 'runtime/modules/mcp/documentation/Dataphyre_MCP.md'
	$preparedMcpGuidelines = Join-Path $Root 'runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md'
	foreach ($preparedMcpFile in @($preparedMcpDocumentation, $preparedMcpGuidelines)) {
		if (-not (Test-Path $preparedMcpFile -PathType Leaf)) {
			Add-Failure "Prepared public export is missing MCP guidance file: $(Get-RelativePath $preparedMcpFile)"
		}
	}
	if ((Test-Path $preparedMcpDocumentation -PathType Leaf) -and (Test-Path $preparedMcpGuidelines -PathType Leaf)) {
		$preparedMcpText = "$(Get-Content -Raw $preparedMcpDocumentation)`n$(Get-Content -Raw $preparedMcpGuidelines)"
		foreach ($term in @('Application-Agent Default Lane', 'ordinary_app_work', 'focused app/module checks', 'dataphyre_mcp_verify_all', 'not default app-agent requirements')) {
			if ($preparedMcpText -notmatch [regex]::Escape($term)) {
				Add-Failure "Prepared public export MCP guidance is missing app-agent boundary term: $term"
			}
		}
		foreach ($preparedMcpFile in @($preparedMcpDocumentation, $preparedMcpGuidelines)) {
			$insideCodeFence = $false
			$lineNumber = 0
			foreach ($line in Get-Content $preparedMcpFile) {
				$lineNumber++
				if ($line -match '^\s*```') {
					$insideCodeFence = -not $insideCodeFence
					continue
				}
				if (-not $insideCodeFence -and $line.Length -gt 1200) {
					Add-Failure "Prepared public export MCP guidance has an overlong non-code line: $(Get-RelativePath $preparedMcpFile):$lineNumber"
				}
			}
		}
	}
	foreach ($markdownFile in Get-ChildItem -Path $Root -Recurse -File -Filter '*.md') {
		$relativeMarkdown = Get-RelativePath $markdownFile.FullName
		$markdownText = Get-Content -Raw $markdownFile.FullName
		if (Test-SourceCheckoutDevToolReference $markdownText) {
			Add-Failure "Prepared public export Markdown still contains contributor dev tool commands: $relativeMarkdown"
		}
		if ($markdownText -match '\]\((?:\.\./)?dev/') {
			Add-Failure "Prepared public export Markdown still links to contributor dev docs: $relativeMarkdown"
		}
	}
}
Write-Host 'OK: release manifest checked'

foreach ($file in Get-RepoFiles) {
	$relative = Get-RelativePath $file.FullName
	if (Test-ForbiddenPublicPath $relative) {
		Add-ExportIssue "Forbidden public-redacted path present in export: $relative"
		continue
	}
	if (Test-DistIgnored $relative $rules) {
		Add-ExportIssue "Forbidden local/install file present in export: $relative"
	}
}
Write-Host 'OK: forbidden export paths checked'

if (-not $NoSecretScan) {
	foreach ($file in Get-ContentScanFiles | Where-Object { Test-TextFile $_ }) {
		$relative = Get-RelativePath $file.FullName
		foreach ($secretPattern in $SecretPatterns) {
			$matches = Select-String -Path $file.FullName -Pattern $secretPattern.Pattern -AllMatches
			foreach ($match in $matches) {
				Add-ExportIssue "Potential $($secretPattern.Label) in ${relative}:$($match.LineNumber)"
			}
		}
	}
	Write-Host 'OK: high-confidence secret markers checked'
}
else {
	Write-Host 'SKIP: high-confidence secret marker scan disabled'
}

foreach ($file in Get-ContentScanFiles | Where-Object { Test-TextFile $_ }) {
	$relative = Get-RelativePath $file.FullName
	foreach ($marker in $ForbiddenReleaseMarkers) {
		$matches = Select-String -Path $file.FullName -Pattern $marker.Pattern -AllMatches
		foreach ($match in $matches) {
			Add-ExportIssue "Forbidden $($marker.Label) in ${relative}:$($match.LineNumber)"
		}
	}
}
Write-Host 'OK: forbidden release markers checked'

foreach ($file in Get-ContentScanFiles | Where-Object { Test-TextFile $_ }) {
	$relative = Get-RelativePath $file.FullName
	foreach ($marker in $ForbiddenProfanityMarkers) {
		$matches = Select-String -Path $file.FullName -Pattern $marker.Pattern -AllMatches
		foreach ($match in $matches) {
			Add-ExportIssue "Forbidden $($marker.Label) in ${relative}:$($match.LineNumber)"
		}
	}
}
Write-Host 'OK: public profanity markers checked'

if ($failures.Count -gt 0) {
	Write-Host ''
	Write-Host "Public export checks failed with $($failures.Count) issue(s)."
	exit 1
}

Write-Host ''
if ($warningCount -gt 0) {
	Write-Host "Public export checks completed with $warningCount warning(s)."
	if ($suppressedWarningCount -gt 0) {
		Write-Host "Suppressed $suppressedWarningCount warning(s) after WarningLimit=$WarningLimit."
	}
	exit 0
}

Write-Host 'Public export checks passed.'
