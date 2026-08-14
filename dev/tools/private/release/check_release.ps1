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
	$Root = (Resolve-Path (Join-Path $scriptDirectory '..\..\..\..')).Path
}
$Root = (Resolve-Path $Root).Path
$failures = New-Object System.Collections.Generic.List[string]

$VendoredPrefixes = @(
	'runtime/modules/stripe/src/',
	'runtime/modules/sql/third_party/',
	('runtime/modules/' + 'conting' + 'ency/'),
	('runtime/modules/' + 'cj' + 'dropshipping/'),
	('runtime/modules/' + 'shopiro' + '_devapi/'),
	'vendor/',
	'.git/'
)

$GeneratedPrefixes = @(
	'cache/',
	'.codex-tmp/',
	'.tmp/',
	'hosttmp/',
	'logs/',
	'runtime/cache/',
	'runtime/modules/core/unit_tests/fixtures/core-functions-unavailable-missing/cache/',
	'runtime/logs/',
	'tmp/'
)

$ReleaseRedactedModuleNames = @(
	('conting' + 'ency'),
	('cj' + 'dropshipping'),
	('shopiro' + '_devapi'),
	'profanity',
	'sentinel'
)

$HeaderExcludedPrefixes = $VendoredPrefixes + $GeneratedPrefixes + @(
	'config/date_translation/languages/'
)
$HeaderExcludedFiles = @(
	'flight_sheet.php'
)

$BundledComponents = @(
	@{
		Name = 'Stripe PHP'
		Path = 'runtime/modules/stripe/src'
		License = 'runtime/modules/stripe/src/LICENSE'
	},
	@{
		Name = 'Adminer'
		Path = 'runtime/modules/sql/third_party/adminer'
		License = 'runtime/modules/sql/third_party/adminer/LICENSE'
	}
)

function Add-Failure {
	param([string]$Message)
	$script:failures.Add($Message) | Out-Null
	Write-Host "FAIL: $Message"
}

function Get-RelativePath {
	param([string]$Path)
	# File inventories can contain entries that disappear while generated test
	# state is being cleaned. Canonicalizing must not require the path to keep
	# existing after Get-ChildItem returned it.
	$fullPath = [System.IO.Path]::GetFullPath($Path)
	$rootPath = $script:Root.TrimEnd('\', '/')
	if ($fullPath.StartsWith($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
		return $fullPath.Substring($rootPath.Length).TrimStart('\', '/') -replace '\\', '/'
	}
	return $fullPath -replace '\\', '/'
}

function Test-Excluded {
	param(
		[string]$RelativePath,
		[string[]]$Prefixes = $script:VendoredPrefixes
	)
	foreach ($prefix in $Prefixes) {
		if ($RelativePath.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
			return $true
		}
	}
	return $false
}

function Get-RepoFiles {
	Get-ChildItem -Path $script:Root -Recurse -File | Where-Object {
		$relative = Get-RelativePath $_.FullName
		-not (Test-Excluded $relative ($script:VendoredPrefixes + $script:GeneratedPrefixes))
	}
}

function Assert-JsonFileValid {
	param([string]$Path)
	$json = Get-Content -Raw $Path
	$convertFromJson = Get-Command ConvertFrom-Json -ErrorAction SilentlyContinue
	if ($null -ne $convertFromJson -and $convertFromJson.Parameters.ContainsKey('AsHashtable')) {
		$json | ConvertFrom-Json -AsHashtable -ErrorAction Stop | Out-Null
		return
	}
	try {
		Add-Type -AssemblyName System.Web.Extensions -ErrorAction Stop
		$serializer = [System.Web.Script.Serialization.JavaScriptSerializer]::new()
		$serializer.DeserializeObject($json) | Out-Null
		return
	}
	catch {
		if ($null -eq $convertFromJson) {
			throw
		}
	}
	$json | ConvertFrom-Json -ErrorAction Stop | Out-Null
}

function Invoke-McpAppCouplingGuard {
	$mcpScanPaths = New-Object System.Collections.Generic.List[string]
	$mcpRoot = Join-Path $script:Root 'runtime/modules/mcp'
	if (Test-Path $mcpRoot -PathType Container) {
		Get-ChildItem -Path $mcpRoot -Recurse -File -Include '*.php', '*.md', '*.json' | ForEach-Object {
			$mcpScanPaths.Add($_.FullName) | Out-Null
		}
	}
	foreach ($helper in @('mcp_config.php', 'mcp_live_validate.php')) {
		$helperPath = Join-Path $script:Root "dev/tools/public/$helper"
		if (Test-Path $helperPath -PathType Leaf) {
			$mcpScanPaths.Add($helperPath) | Out-Null
		}
	}
	$productNeedles = @(
		'shopiro',
		'Shopiro',
		'applications/shopiro',
		'applications\shopiro',
		'northstar',
		'Northstar',
		'volumetrix',
		'Volumetrix',
		'applications/volumetrix',
		'applications\volumetrix',
		'exodus',
		'Exodus',
		'applications/exodus',
		'applications\exodus',
		'applications/northstar',
		'.local\shopiro',
		'.local/shopiro',
		'Bureau\ShopiCore',
		'Bureau/ShopiCore',
		'OneDrive\Bureau',
		'OneDrive/Bureau'
	)
	foreach ($path in ($mcpScanPaths | Select-Object -Unique)) {
		$text = Get-Content -Raw $path
		# Legal ownership is package metadata, not an application coupling. Strip only
		# the canonical Dataphyre copyright line; product names anywhere else remain
		# release-blocking fixture/runtime leakage.
		$text = [regex]::Replace($text, '(?im)^\s*(?:\*\s*)?Copyright\s+\(c\)\s+\d{4}(?:-\d{4})?\s+Shopiro Ltd\.\s*$', '')
		foreach ($needle in $productNeedles) {
			if ($text.Contains($needle)) {
				$relativePath = Get-RelativePath $path
				Add-Failure "Shared MCP file contains product-specific fixture text '$needle': $relativePath"
			}
		}
	}
}

Write-Host "Checking Dataphyre release surface at $Root"

$mcpUtilitySource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.utility.php'
if (Test-Path $mcpUtilitySource -PathType Leaf) {
	$mcpUtilityText = Get-Content -Raw $mcpUtilitySource
	foreach ($term in @(
		'private function path_is_within_root',
		'$path===$root || str_starts_with($path, $root.''/'')',
		'Prefix-only checks can mistake sibling paths such as /repo2 for /repo'
	)) {
		if ($mcpUtilityText -notmatch [regex]::Escape($term)) {
			Add-Failure "MCP safe_repo_path boundary hardening is missing required source term: $term"
		}
	}
}
else {
	Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.utility.php'
}

$devDirectory = Join-Path $Root 'dev'
if (Test-Path $devDirectory -PathType Container) {
	$qualityGates = Join-Path $devDirectory 'QUALITY_GATES.md'
	if (-not (Test-Path $qualityGates -PathType Leaf)) {
		Add-Failure 'Source checkout is missing dev/QUALITY_GATES.md runtime quality gate contract'
	}
	else {
		$qualityText = Get-Content -Raw $qualityGates
		$requiredQualityTerms = @(
			'Reusable Concept',
			'Inspectable Contract',
			'Provenance',
			'Verification',
			'Small Surface'
		)
		foreach ($term in $requiredQualityTerms) {
			if ($qualityText -notmatch [regex]::Escape($term)) {
				Add-Failure "dev/QUALITY_GATES.md is missing required runtime quality gate: $term"
			}
		}
	}
	Write-Host 'OK: source runtime quality gates checked'

	$performanceContract = Join-Path $devDirectory 'PERFORMANCE.md'
	if (-not (Test-Path $performanceContract -PathType Leaf)) {
		Add-Failure 'Source checkout is missing dev/PERFORMANCE.md optimization proof contract'
	}
	else {
		$performanceText = Get-Content -Raw $performanceContract
		$requiredPerformanceTerms = @(
			'Production Code',
			'Proof',
			'benchmark_hot_paths.php',
			'local maintainer benchmark notes',
			'opcache-jit',
			'Agent Boundary'
		)
		foreach ($term in $requiredPerformanceTerms) {
			if ($performanceText -notmatch [regex]::Escape($term)) {
				Add-Failure "dev/PERFORMANCE.md is missing required hot-path code contract term: $term"
			}
		}
	}
	Write-Host 'OK: source performance contract checked'

	$githubDirectory = Join-Path $Root '.github'
	if (Test-Path $githubDirectory -PathType Container) {
		$releaseToolingFiles = @(
			@{ Path = Join-Path $githubDirectory 'workflows/ci.yml'; Relative = '.github/workflows/ci.yml' },
			@{ Path = Join-Path $githubDirectory 'PULL_REQUEST_TEMPLATE.md'; Relative = '.github/PULL_REQUEST_TEMPLATE.md' }
		)
		foreach ($releaseToolingFile in $releaseToolingFiles) {
			$path = [string]$releaseToolingFile['Path']
			$relative = [string]$releaseToolingFile['Relative']
			if (Test-Path $path -PathType Leaf) {
				$releaseToolingText = Get-Content -Raw $path
				foreach ($staleToolingPath in @('./tools/', 'php tools/')) {
					if ($releaseToolingText.Contains($staleToolingPath)) {
						Add-Failure "$relative still references stale release tooling path: $staleToolingPath"
					}
				}
				if ($relative -eq '.github/workflows/ci.yml') {
					foreach ($requiredToolingPath in @(
						'./dev/tools/public/lint_php.ps1',
						'php dev/tools/public/mcp_self_test.php',
						'php dev/tools/public/mcp_live_validate.php',
						'php bin/dataphyre-test --help',
						'docker build --file docker/testing/Dockerfile --tag dataphyre-test:php8.4 .',
						'DATAPHYRE_TEST_SKIP_BUILD=1 sh bin/dataphyre-test-docker --help',
						'DATAPHYRE_TEST_CONTAINER_ROOT=1',
						'--path=dataphyre.core_application_runtime_secret_broker.test.php',
						'--owner=testing --path=runner',
						'check_release_archive.ps1',
						'-Ref $env:GITHUB_SHA'
					)) {
						if (-not $releaseToolingText.Contains($requiredToolingPath)) {
							Add-Failure "$relative is missing current release tooling path: $requiredToolingPath"
						}
					}
					$frameworkCoverageMatch = [regex]::Match(
						$releaseToolingText,
						'(?ms)^  framework-exact-coverage:\r?\n(?<job>.*?)(?=^  [a-zA-Z0-9_-]+:\r?\n|\z)'
					)
					if (-not $frameworkCoverageMatch.Success) {
						Add-Failure "$relative is missing the generic framework-exact-coverage job"
					}
					else {
						$frameworkCoverageJob = $frameworkCoverageMatch.Groups['job'].Value
						foreach ($requiredCoverageTerm in @(
							'Build the canonical PHP runtime with native descriptor support and Xdebug',
							'DATAPHYRE_TEST_CONTAINER_ROOT=1',
							'DATAPHYRE_TEST_ENGINE=xdebug',
							'sh bin/dataphyre-test-docker run',
							'--scope=framework',
							'--kind=code',
							'--fail-skipped',
							'--fail-todo',
							'--coverage=cache/ci/framework.coverage.json',
							'--coverage-require=xdebug',
							'--coverage-source=runtime/modules',
							'--coverage-closed-world',
							'--coverage-min-percent=100',
							'--junit=cache/ci/framework.junit.xml',
							'--profile=cache/ci/framework.profile.json',
							'> cache/ci/framework.summary.json',
							'name: framework-exact-coverage',
							'if-no-files-found: error'
						)) {
							if (-not $frameworkCoverageJob.Contains($requiredCoverageTerm)) {
								Add-Failure "$relative framework-exact-coverage is missing required contract term: $requiredCoverageTerm"
							}
						}
					}
				}
				else {
					foreach ($requiredToolingPath in @('./dev/tools/public/lint_php.ps1', 'DATAPHYRE_PHP', 'Release sanitation evidence provided separately')) {
						if (-not $releaseToolingText.Contains($requiredToolingPath)) {
							Add-Failure "$relative is missing current contributor tooling path: $requiredToolingPath"
						}
					}
				}
			}
			else {
				Add-Failure "$relative is missing"
			}
			}
			Write-Host 'OK: GitHub release tooling paths checked'
		}

	$sourceCheckoutCheck = Join-Path $devDirectory 'tools/private/release/check_source_checkout.ps1'
	$composerConsumerCheck = Join-Path $devDirectory 'tools/private/release/check_composer_consumer.ps1'
	if (-not (Test-Path $composerConsumerCheck -PathType Leaf)) {
		Add-Failure 'Git worktree is missing dev/tools/private/release/check_composer_consumer.ps1'
	}
	if (-not (Test-Path $sourceCheckoutCheck -PathType Leaf)) {
		Add-Failure 'Git worktree is missing dev/tools/private/release/check_source_checkout.ps1'
	}
	else {
		$sourceCheckoutText = Get-Content -Raw $sourceCheckoutCheck
		foreach ($requiredComposerSmokeTerm in @('check_composer_consumer.ps1', 'Composer consumer package smoke', 'SkipComposerConsumer')) {
			if (-not $sourceCheckoutText.Contains($requiredComposerSmokeTerm)) {
				Add-Failure "dev/tools/private/release/check_source_checkout.ps1 is missing Composer consumer smoke term: $requiredComposerSmokeTerm"
			}
		}
	}

	$agenticEnterpriseContract = Join-Path $Root 'docs/AGENTIC_ENTERPRISE.md'
	if (-not (Test-Path $agenticEnterpriseContract -PathType Leaf)) {
		Add-Failure 'Source checkout is missing docs/AGENTIC_ENTERPRISE.md agentic enterprise contract'
	}
	else {
		$agenticEnterpriseText = Get-Content -Raw $agenticEnterpriseContract
		$requiredAgenticEnterpriseTerms = @(
			'Application-Agent Default Lane',
			'Extension Boundary',
			'MCP And Agent Safety',
			'Governance Baseline',
			'Performance Discipline',
			'Enterprise Adoption Checklist',
			'dialbacks',
			'callbacks',
			'plugins/mcp',
			'application code using Dataphyre',
			'focused application or module checks',
			'does not require publication validation',
			'tenant and application boundaries',
			'access and permission policy',
			'audit and trace evidence',
			'redaction and data classification',
			'framework claims',
			'application-owned behavior',
			'builder_response.first_read',
			'detail_page=planning|implementation|verification|controls'
		)
		foreach ($term in $requiredAgenticEnterpriseTerms) {
			if ($agenticEnterpriseText -notmatch [regex]::Escape($term)) {
				Add-Failure "docs/AGENTIC_ENTERPRISE.md is missing required agentic enterprise contract term: $term"
			}
		}
		if ($agenticEnterpriseText -match [regex]::Escape('../dev/PERFORMANCE.md') -or
			$agenticEnterpriseText -match [regex]::Escape('../dev/QUALITY_GATES.md')) {
			Add-Failure 'docs/AGENTIC_ENTERPRISE.md must not link to contributor dev contracts in public-facing guidance'
		}
		if ($agenticEnterpriseText -match [regex]::Escape('dev/tools/benchmark_hot_paths.php') -or
			$agenticEnterpriseText -match [regex]::Escape('dev/benchmarks/BENCHMARKS.md')) {
			Add-Failure 'docs/AGENTIC_ENTERPRISE.md must not name contributor benchmark paths in public-facing performance guidance'
		}
		if ($agenticEnterpriseText -notmatch [regex]::Escape('Dataphyre is provided under the MIT License') -or
			$agenticEnterpriseText -notmatch [regex]::Escape('not an additional support warranty')) {
			Add-Failure 'docs/AGENTIC_ENTERPRISE.md is missing public release warranty boundary'
		}
	}
	Write-Host 'OK: source agentic enterprise contract checked'

	$agentGuidance = Join-Path $Root 'docs/AGENTS.md'
	if (-not (Test-Path $agentGuidance -PathType Leaf)) {
		Add-Failure 'Source checkout is missing docs/AGENTS.md agent guidance'
	}
	else {
		$agentGuidanceText = Get-Content -Raw $agentGuidance
		$requiredAgentGuidanceTerms = @(
			'Application-Agent Default Lane',
			'focused application or module checks',
			'application-owned adapters',
			'dataphyre_mcp_verify_all',
			'builder_response.first_read.next_action',
			'detail_page=planning|implementation|verification|controls|governance'
		)
		foreach ($term in $requiredAgentGuidanceTerms) {
			if ($agentGuidanceText -notmatch [regex]::Escape($term)) {
				Add-Failure "docs/AGENTS.md is missing required application-agent guidance term: $term"
			}
		}
	}
	Write-Host 'OK: source agent guidance contract checked'

	$mcpDocumentation = Join-Path $Root 'runtime/modules/mcp/documentation/Dataphyre_MCP.md'
	$mcpGuidelines = Join-Path $Root 'runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md'
	if (-not (Test-Path $mcpDocumentation -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/documentation/Dataphyre_MCP.md'
	}
	if (-not (Test-Path $mcpGuidelines -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md'
	}
	if ((Test-Path $mcpDocumentation -PathType Leaf) -and (Test-Path $mcpGuidelines -PathType Leaf)) {
		$mcpDocumentationText = Get-Content -Raw $mcpDocumentation
		$mcpGuidelinesText = Get-Content -Raw $mcpGuidelines
		$mcpCombinedText = "$mcpDocumentationText`n$mcpGuidelinesText"
		$requiredMcpDocumentationTerms = @(
			'dataphyre_mcp_docs_coverage_report',
			'dataphyre_mcp_readiness_report',
			'dataphyre_mcp_safety_boundary_report',
			'dataphyre_mcp_enterprise_adoption_audit',
			'dataphyre_mcp_verify_all',
			'governance baseline',
			'tenant/application boundaries',
			'framework-versus-application verification ownership',
			'Application-Agent Default Lane',
			'ordinary_app_work',
			'tool_audience_boundaries',
			'consuming application as owner',
			'ordinary app-work ownership',
			'publication_validation',
			'publication_validation_not_ordinary_app_work',
			'focused app/module checks',
			'application-owned adapters',
			'product-local',
			'app-specific',
			'--allow-unsafe'
		)
		foreach ($term in $requiredMcpDocumentationTerms) {
			if ($mcpCombinedText -notmatch [regex]::Escape($term)) {
				Add-Failure "MCP documentation is missing required source coverage term: $term"
			}
		}
	}
	Write-Host 'OK: source MCP documentation contract checked'

	$mcpClientSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.php'
	$mcpClientWorkflowTranscriptSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.transcript.php'
	$mcpClientWorkflowStateSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.state.php'
	$mcpClientWorkflowStartPackSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.start_pack.php'
	$mcpClientWorkflowSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.php'
	$mcpClientSafetySource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.safety.php'
	$mcpClientEnterpriseAuditSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.enterprise.audit.php'
	$mcpClientEnterpriseSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.enterprise.php'
	$mcpClientCapabilitiesSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.capabilities.php'
	$mcpClientSkillsSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.skills.php'
	$mcpClientExamplesSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.examples.php'
	$mcpClientBriefSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.brief.php'
	$mcpClientSetupSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.setup.php'
	$mcpClientPromptsSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.prompts.php'
	$mcpClientDocsSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.docs.php'
	$mcpClientDiscoverySource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.discovery.php'
	$mcpClientReadinessSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.client.readiness.php'
	$mcpRegistrySource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.registry.php'
	$mcpRegistryWorkflowToolsSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.registry.workflow_tools.php'
	$mcpRegistryToolsSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.registry.tools.php'
	$mcpRegistryValidationSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.registry.validation.php'
	$mcpPlanningSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.php'
	$mcpPlanningAppBuilderSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.php'
	$mcpPlanningAppBuilderSensitivitySource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.sensitivity.php'
	$mcpPlanningAppBuilderReadinessSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.readiness.php'
	$mcpPlanningAppBuilderContractSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.contract.php'
	$mcpPlanningAppBuilderResponseSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.response.php'
	$mcpPlanningAppBuilderSchemaSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.schema.php'
	$mcpPlanningApiSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.api.php'
	$mcpPlanningDocsSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.docs.php'
	$mcpPlanningTaskPackSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.task_pack.php'
	$mcpPlanningModulesSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.modules.php'
	$mcpPlanningAgentContextSource = Join-Path $Root 'runtime/modules/mcp/kernel/dataphyre_mcp.planning.agent_context.php'
	if (-not (Test-Path $mcpClientSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.php'
	}
	elseif (-not (Test-Path $mcpRegistrySource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.registry.php'
	}
	elseif (-not (Test-Path $mcpRegistryWorkflowToolsSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.registry.workflow_tools.php'
	}
	elseif (-not (Test-Path $mcpRegistryToolsSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.registry.tools.php'
	}
	elseif (-not (Test-Path $mcpRegistryValidationSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.registry.validation.php'
	}
	elseif (-not (Test-Path $mcpClientWorkflowTranscriptSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.transcript.php'
	}
	elseif (-not (Test-Path $mcpClientWorkflowStateSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.state.php'
	}
	elseif (-not (Test-Path $mcpClientWorkflowStartPackSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.start_pack.php'
	}
	elseif (-not (Test-Path $mcpClientWorkflowSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.php'
	}
	elseif (-not (Test-Path $mcpClientSafetySource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.safety.php'
	}
	elseif (-not (Test-Path $mcpClientEnterpriseAuditSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.enterprise.audit.php'
	}
	elseif (-not (Test-Path $mcpClientEnterpriseSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.enterprise.php'
	}
	elseif (-not (Test-Path $mcpClientCapabilitiesSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.capabilities.php'
	}
	elseif (-not (Test-Path $mcpClientSkillsSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.skills.php'
	}
	elseif (-not (Test-Path $mcpClientExamplesSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.examples.php'
	}
	elseif (-not (Test-Path $mcpClientBriefSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.brief.php'
	}
	elseif (-not (Test-Path $mcpClientSetupSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.setup.php'
	}
	elseif (-not (Test-Path $mcpClientPromptsSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.prompts.php'
	}
	elseif (-not (Test-Path $mcpClientDocsSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.docs.php'
	}
	elseif (-not (Test-Path $mcpClientDiscoverySource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.discovery.php'
	}
	elseif (-not (Test-Path $mcpClientReadinessSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.client.readiness.php'
	}
	else {
		$mcpRegistrySourceText = (Get-Content -Raw $mcpRegistryWorkflowToolsSource) + "`n" + (Get-Content -Raw $mcpRegistryToolsSource) + "`n" + (Get-Content -Raw $mcpRegistrySource) + "`n" + (Get-Content -Raw $mcpRegistryValidationSource)
		foreach ($term in @(
			'private function list_tools',
			'dataphyre_app_builder_plan_generate',
			'validate_tool_arguments',
			'additionalProperties',
			'closest_tool_argument',
			'Did you mean'
		)) {
			if ($mcpRegistrySourceText -notmatch [regex]::Escape($term)) {
				Add-Failure "MCP registry source is missing argument validation term: $term"
			}
		}
		$mcpClientSourceText = (Get-Content -Raw $mcpClientSource) + "`n" + (Get-Content -Raw $mcpClientWorkflowTranscriptSource) + "`n" + (Get-Content -Raw $mcpClientWorkflowStateSource) + "`n" + (Get-Content -Raw $mcpClientWorkflowStartPackSource) + "`n" + (Get-Content -Raw $mcpClientWorkflowSource) + "`n" + (Get-Content -Raw $mcpClientSafetySource) + "`n" + (Get-Content -Raw $mcpClientEnterpriseAuditSource) + "`n" + (Get-Content -Raw $mcpClientEnterpriseSource) + "`n" + (Get-Content -Raw $mcpClientCapabilitiesSource) + "`n" + (Get-Content -Raw $mcpClientSkillsSource) + "`n" + (Get-Content -Raw $mcpClientExamplesSource) + "`n" + (Get-Content -Raw $mcpClientBriefSource) + "`n" + (Get-Content -Raw $mcpClientSetupSource) + "`n" + (Get-Content -Raw $mcpClientPromptsSource) + "`n" + (Get-Content -Raw $mcpClientDocsSource) + "`n" + (Get-Content -Raw $mcpClientDiscoverySource) + "`n" + (Get-Content -Raw $mcpClientReadinessSource)
		$appAgentAudienceFallbackCount = ([regex]::Matches($mcpClientSourceText, '\$audience=''agents'';')).Count
		if ($appAgentAudienceFallbackCount -lt 2) {
			Add-Failure 'MCP release-note and surface-changelog audience fallbacks must default to agents'
		}
		foreach ($term in @(
			'mcp_ordinary_app_work_contract',
			'ordinary_app_work',
			'focused application or module checks',
			'not_required_for_ordinary_app_work',
			'Dataphyre shared production hot-path changes',
			'tool_audience_boundaries',
			'mcp_tool_audience_boundaries',
			'mcp_current_tool_audience_boundaries',
			'capability_family_publication_validation',
			'recommended_gate',
			'release_gate_policy',
			'publication_validation_not_ordinary_app_work'
		)) {
			if ($mcpClientSourceText -notmatch [regex]::Escape($term)) {
				Add-Failure "MCP client source is missing ordinary app-work startup contract term: $term"
			}
		}
	}
	if (-not (Test-Path $mcpPlanningSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.php'
	}
	elseif (-not (Test-Path $mcpPlanningAppBuilderSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.php'
	}
	elseif (-not (Test-Path $mcpPlanningAppBuilderSensitivitySource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.sensitivity.php'
	}
	elseif (-not (Test-Path $mcpPlanningAppBuilderReadinessSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.readiness.php'
	}
	elseif (-not (Test-Path $mcpPlanningAppBuilderContractSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.contract.php'
	}
	elseif (-not (Test-Path $mcpPlanningAppBuilderResponseSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.response.php'
	}
	elseif (-not (Test-Path $mcpPlanningAppBuilderSchemaSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.schema.php'
	}
	elseif (-not (Test-Path $mcpPlanningApiSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.api.php'
	}
	elseif (-not (Test-Path $mcpPlanningDocsSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.docs.php'
	}
	elseif (-not (Test-Path $mcpPlanningTaskPackSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.task_pack.php'
	}
	elseif (-not (Test-Path $mcpPlanningModulesSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.modules.php'
	}
	elseif (-not (Test-Path $mcpPlanningAgentContextSource -PathType Leaf)) {
		Add-Failure 'Source checkout is missing runtime/modules/mcp/kernel/dataphyre_mcp.planning.agent_context.php'
	}
	else {
		$mcpPlanningSourceText = (Get-Content -Raw $mcpPlanningSource) + "`n" + (Get-Content -Raw $mcpPlanningAppBuilderSource) + "`n" + (Get-Content -Raw $mcpPlanningAppBuilderSensitivitySource) + "`n" + (Get-Content -Raw $mcpPlanningAppBuilderReadinessSource) + "`n" + (Get-Content -Raw $mcpPlanningAppBuilderContractSource) + "`n" + (Get-Content -Raw $mcpPlanningAppBuilderResponseSource) + "`n" + (Get-Content -Raw $mcpPlanningAppBuilderSchemaSource) + "`n" + (Get-Content -Raw $mcpPlanningApiSource) + "`n" + (Get-Content -Raw $mcpPlanningDocsSource) + "`n" + (Get-Content -Raw $mcpPlanningTaskPackSource) + "`n" + (Get-Content -Raw $mcpPlanningModulesSource) + "`n" + (Get-Content -Raw $mcpPlanningAgentContextSource)
		foreach ($term in @(
			'apply_audit_publication_validation',
			'publication_validation',
			'tool_audience_boundaries',
			'mcp_current_tool_audience_boundaries',
			'dataphyre_mcp_verify_all only before publishing shared MCP/release-surface claims',
			'Dataphyre MCP publication evidence',
			'MCP app-coupling guard scan'
		)) {
			if ($mcpPlanningSourceText -notmatch [regex]::Escape($term)) {
				Add-Failure "MCP planning source is missing publication-validation boundary term: $term"
			}
		}
	}
	Invoke-McpAppCouplingGuard
	Write-Host 'OK: source MCP audience fallback contract checked'

	$manifestDoc = Join-Path $Root 'docs/RELEASE_MANIFEST.md'
	$manifestSchema = Join-Path $Root 'docs/RELEASE_MANIFEST.schema.json'
	$prepareExportTool = Join-Path $devDirectory 'tools/private/release/prepare_public_export.ps1'
	$checkPublicExportTool = Join-Path $devDirectory 'tools/private/release/check_public_export.ps1'
	$checkReleaseArchiveTool = Join-Path $devDirectory 'tools/private/release/check_release_archive.ps1'
	$verifyReleaseManifestTool = Join-Path $devDirectory 'tools/private/release/verify_release_manifest.ps1'
	$lintPhpTool = Join-Path $devDirectory 'tools/public/lint_php.ps1'
	$checkSourceCheckoutTool = Join-Path $devDirectory 'tools/private/release/check_source_checkout.ps1'
	$checkTraceDialbackUsageTool = Join-Path $devDirectory 'tools/public/check_trace_dialback_usage.ps1'
	$checkTraceDialbackUsageSelfTestTool = Join-Path $devDirectory 'tools/public/check_trace_dialback_usage_self_test.ps1'
	$mcpConfigTool = Join-Path $devDirectory 'tools/public/mcp_config.php'
	foreach ($requiredManifestFile in @($manifestDoc, $manifestSchema, $prepareExportTool, $checkPublicExportTool, $checkReleaseArchiveTool, $verifyReleaseManifestTool, $lintPhpTool, $checkSourceCheckoutTool, $checkTraceDialbackUsageTool, $checkTraceDialbackUsageSelfTestTool, $mcpConfigTool)) {
		if (-not (Test-Path $requiredManifestFile -PathType Leaf)) {
			$relativeMissing = $requiredManifestFile.Substring($Root.TrimEnd('\', '/').Length).TrimStart('\', '/') -replace '\\', '/'
			Add-Failure "Source checkout is missing release manifest contract file: $relativeMissing"
		}
	}
	if ((Test-Path $manifestDoc -PathType Leaf) -and
		(Test-Path $manifestSchema -PathType Leaf) -and
		(Test-Path $prepareExportTool -PathType Leaf) -and
		(Test-Path $checkPublicExportTool -PathType Leaf) -and
		(Test-Path $checkReleaseArchiveTool -PathType Leaf) -and
		(Test-Path $verifyReleaseManifestTool -PathType Leaf)) {
		try {
			$manifestSchemaDocument = Get-Content -Raw $manifestSchema | ConvertFrom-Json
			$manifestSchemaValue = [string]$manifestSchemaDocument.properties.schema.const
			if ($manifestSchemaValue -ne 'dataphyre.public_export_manifest.v1') {
				Add-Failure 'docs/RELEASE_MANIFEST.schema.json has an unexpected manifest schema const'
			}
			$manifestDocText = Get-Content -Raw $manifestDoc
			$prepareExportText = Get-Content -Raw $prepareExportTool
			$checkPublicExportText = Get-Content -Raw $checkPublicExportTool
			$checkReleaseArchiveText = Get-Content -Raw $checkReleaseArchiveTool
			$verifyReleaseManifestText = Get-Content -Raw $verifyReleaseManifestTool
			$manifestRequiredTerms = @(
				'RELEASE_MANIFEST.json',
				'dataphyre.public_export_manifest.v1',
				'export_tree_sha256',
				'release_boundary',
				'application_agents_building_apps',
				'ordinary_app_entrypoint',
				'dataphyre_app_builder_plan_generate',
				'ordinary_app_payload_profile',
				'compact',
				'focused application or module checks',
				'app_owned_extension_points',
				'MCP metadata',
				'application-owned adapters',
				'reusable runtime modules',
				'escalate_only_for',
				'legal-hold',
				'access-policy',
				'not_ordinary_app_ceremony',
				'dataphyre_mcp_verify_all',
				'Dataphyre hot-path benchmarks',
				'modules',
				'bundled_components',
				'files'
			)
			foreach ($term in $manifestRequiredTerms) {
				if ($manifestDocText -notmatch [regex]::Escape($term)) {
					Add-Failure "docs/RELEASE_MANIFEST.md is missing release manifest term: $term"
				}
				if ($prepareExportText -notmatch [regex]::Escape($term)) {
					Add-Failure "prepare_public_export.ps1 is missing release manifest term: $term"
				}
				if ($verifyReleaseManifestText -notmatch [regex]::Escape($term)) {
					Add-Failure "verify_release_manifest.ps1 is missing release manifest term: $term"
				}
			}
			foreach ($term in @('builder_response.first_read.next_action', 'detail_page=planning', 'detail_page=implementation', 'detail_page=verification', 'detail_page=controls')) {
				if ($manifestDocText -notmatch [regex]::Escape($term)) {
					Add-Failure "docs/RELEASE_MANIFEST.md is missing compact app-builder detail-page term: $term"
				}
			}
			if ($checkPublicExportText -notmatch [regex]::Escape('verify_release_manifest.ps1')) {
				Add-Failure 'check_public_export.ps1 must delegate release manifest validation to verify_release_manifest.ps1'
			}
			foreach ($testingReleaseTerm in @(
				'.dockerignore',
				'bin/dataphyre-test',
				'bin/dataphyre-test-docker',
				'bin/dataphyre-mutate',
				'docker/testing/Dockerfile',
				'docker/testing/browser/package.json',
				'runtime/modules/testing/tooling/Runner.php',
				'runtime/modules/testing/tooling/bootstrap.php',
				'runtime/modules/testing/tooling/code_worker.php',
				'canonical test CLI smoke'
			)) {
				if ($checkPublicExportText -notmatch [regex]::Escape($testingReleaseTerm)) {
					Add-Failure "check_public_export.ps1 is missing testing release-closure term: $testingReleaseTerm"
				}
			}
			foreach ($archiveExecutableTerm in @(
				'git -C $Root rev-parse --verify',
				'git -C $Root ls-tree $resolvedRef',
				'git -C $Root archive --format=zip --output=$zipPath $resolvedRef',
				'bin/dataphyre-test',
				'bin/dataphyre-test-docker',
				"-ne '100755'"
			)) {
				if ($checkReleaseArchiveText -notmatch [regex]::Escape($archiveExecutableTerm)) {
					Add-Failure "check_release_archive.ps1 is missing immutable executable-mode term: $archiveExecutableTerm"
				}
			}
			if ($checkReleaseArchiveText.Contains('--worktree-attributes')) {
				Add-Failure 'check_release_archive.ps1 must not let working-tree attributes alter an immutable commit archive'
			}
			if (Test-Path $lintPhpTool -PathType Leaf) {
				$lintPhpText = Get-Content -Raw $lintPhpTool
				foreach ($term in @('[string]$Php', 'DATAPHYRE_PHP', 'pass -Php <path>, or set DATAPHYRE_PHP')) {
					if ($lintPhpText -notmatch [regex]::Escape($term)) {
						Add-Failure "lint_php.ps1 is missing maintainer PHP executable override term: $term"
					}
				}
			}
			if (Test-Path $checkSourceCheckoutTool -PathType Leaf) {
				$checkSourceCheckoutText = Get-Content -Raw $checkSourceCheckoutTool
				foreach ($term in @('[string]$Php', 'DATAPHYRE_PHP', 'check_public_export.ps1', 'WarnOnly = $true', 'WarningLimit = $WarningLimit', 'check_trace_dialback_usage_self_test.ps1', '-SourceSet ReleaseOwned', 'mcp_live_validate.php', '--php')) {
					if ($checkSourceCheckoutText -notmatch [regex]::Escape($term)) {
						Add-Failure "check_source_checkout.ps1 is missing maintainer CI-equivalent term: $term"
					}
				}
			}
			if (Test-Path $checkTraceDialbackUsageTool -PathType Leaf) {
				$checkTraceDialbackUsageText = Get-Content -Raw $checkTraceDialbackUsageTool
				foreach ($term in @('ReleaseOwned', 'Filesystem', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', 'Split-NulDelimitedPaths', '[char]0', 'RedirectStandardOutput', 'Test-ExcludedSourcePath', 'Resolve-Path -LiteralPath', '[string](Get-Content -Raw -LiteralPath', '@(Get-Content -LiteralPath $file.FullName)')) {
					if ($checkTraceDialbackUsageText -notmatch [regex]::Escape($term)) {
						Add-Failure "check_trace_dialback_usage.ps1 is missing release-owned source-set term: $term"
					}
				}
			}
			if (Test-Path $checkTraceDialbackUsageSelfTestTool -PathType Leaf) {
				$checkTraceDialbackUsageSelfTestText = Get-Content -Raw $checkTraceDialbackUsageSelfTestTool
				foreach ($term in @('tracked.trap', 'newline.trap', 'nonignored.trap', 'ignored.private', 'archive.private', 'tracked [literal trap].php', 'vendor.excluded', 'empty.php', 'ReleaseOwned', 'Filesystem')) {
					if ($checkTraceDialbackUsageSelfTestText -notmatch [regex]::Escape($term)) {
						Add-Failure "check_trace_dialback_usage_self_test.ps1 is missing source-set fixture term: $term"
					}
				}
			}
			if (Test-Path $mcpConfigTool -PathType Leaf) {
				$mcpConfigText = Get-Content -Raw $mcpConfigTool
				foreach ($term in @('DATAPHYRE_PHP', 'PHP_BINARY', 'dataphyre_mcp_config_env')) {
					if ($mcpConfigText -notmatch [regex]::Escape($term)) {
						Add-Failure "mcp_config.php is missing PHP executable discovery term: $term"
					}
				}
			}
			foreach ($mcpPhpTool in @('mcp_config.php', 'mcp_live_validate.php', 'mcp_self_test.php')) {
				$mcpPhpToolPath = Join-Path $devDirectory "tools/public/$mcpPhpTool"
				if (Test-Path $mcpPhpToolPath -PathType Leaf) {
					$mcpPhpToolText = Get-Content -Raw $mcpPhpToolPath
					foreach ($term in @('workspace_root', 'Dataphyre Git worktree root', 'common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php')) {
						if ($mcpPhpToolText -notmatch [regex]::Escape($term)) {
							Add-Failure "$mcpPhpTool is missing explicit embedded Git worktree root resolver term: $term"
						}
					}
				}
			}
			$mcpLiveValidateTool = Join-Path $devDirectory 'tools/public/mcp_live_validate.php'
			if (Test-Path $mcpLiveValidateTool -PathType Leaf) {
				$mcpLiveValidateText = Get-Content -Raw $mcpLiveValidateTool
				foreach ($term in @('error_reporting=32767', 'display_errors=stderr', 'MCP server wrote to stderr during validation')) {
					if ($mcpLiveValidateText -notmatch [regex]::Escape($term)) {
						Add-Failure "mcp_live_validate.php is missing warning-free validation term: $term"
					}
				}
			}
			foreach ($schemaProperty in @('export_tree_sha256', 'release_boundary', 'modules', 'bundled_components', 'files')) {
				if ($null -eq $manifestSchemaDocument.properties.PSObject.Properties[$schemaProperty]) {
					Add-Failure "docs/RELEASE_MANIFEST.schema.json is missing property: $schemaProperty"
				}
			}
			foreach ($releaseBoundaryProperty in @('app_owned_extension_points', 'app_builder_handoff_fields', 'ordinary_app_entrypoint', 'ordinary_app_payload_profile', 'escalate_only_for', 'not_ordinary_app_ceremony')) {
				if ($null -eq $manifestSchemaDocument.'$defs'.release_boundary.properties.PSObject.Properties[$releaseBoundaryProperty]) {
					Add-Failure "docs/RELEASE_MANIFEST.schema.json is missing release_boundary.$releaseBoundaryProperty"
				}
				if ($manifestSchemaDocument.'$defs'.release_boundary.required -notcontains $releaseBoundaryProperty) {
					Add-Failure "docs/RELEASE_MANIFEST.schema.json release_boundary.required is missing $releaseBoundaryProperty"
				}
			}
			if ([string]$manifestSchemaDocument.'$defs'.release_boundary.properties.ordinary_app_entrypoint.const -ne 'dataphyre_app_builder_plan_generate') {
				Add-Failure 'docs/RELEASE_MANIFEST.schema.json release_boundary.ordinary_app_entrypoint must be a dataphyre_app_builder_plan_generate const'
			}
			if ([string]$manifestSchemaDocument.'$defs'.release_boundary.properties.ordinary_app_payload_profile.const -ne 'compact') {
				Add-Failure 'docs/RELEASE_MANIFEST.schema.json release_boundary.ordinary_app_payload_profile must be a compact const'
			}
			$appBuilderHandoffFieldsSchema = $manifestSchemaDocument.'$defs'.release_boundary.properties.app_builder_handoff_fields
			$appBuilderHandoffMinItems = 0
			if ($null -ne $appBuilderHandoffFieldsSchema.PSObject.Properties['minItems']) {
				$appBuilderHandoffMinItems = [int]$appBuilderHandoffFieldsSchema.minItems
			}
			if ($appBuilderHandoffMinItems -lt 12) {
				Add-Failure 'docs/RELEASE_MANIFEST.schema.json release_boundary.app_builder_handoff_fields must require all app-builder handoff fields'
			}
			foreach ($handoffField in @(
				'prewrite_checklist.implementation_obligations',
				'verification_handoff',
				'verification_execution_plan',
				'acceptance_review_plan',
				'local_convention_probe',
				'write_handoff',
				'implementation_matrix',
				'implementation_recipe',
				'app_contract_summary',
				'tenant_identity_handoff',
				'data_sensitivity_summary',
				'policy_decision_register'
			)) {
				if ($appBuilderHandoffFieldsSchema.items.enum -notcontains $handoffField) {
					Add-Failure "docs/RELEASE_MANIFEST.schema.json app_builder_handoff_fields enum is missing: $handoffField"
				}
				if ($prepareExportText -notmatch [regex]::Escape($handoffField)) {
					Add-Failure "prepare_public_export.ps1 is missing app-builder handoff field: $handoffField"
				}
				if ($verifyReleaseManifestText -notmatch [regex]::Escape($handoffField)) {
					Add-Failure "verify_release_manifest.ps1 is missing app-builder handoff field: $handoffField"
				}
				if ($manifestDocText -notmatch [regex]::Escape($handoffField)) {
					Add-Failure "docs/RELEASE_MANIFEST.md is missing app-builder handoff field: $handoffField"
				}
			}
		}
		catch {
			Add-Failure "docs/RELEASE_MANIFEST.schema.json is not valid JSON: $($_.Exception.Message)"
		}
	}
	Write-Host 'OK: source release manifest contract checked'
}

try {
	$composer = Get-Content -Raw (Join-Path $Root 'composer.json') | ConvertFrom-Json
	Write-Host 'OK: composer.json parses as JSON'
	$composerProperties = @{}
	foreach ($property in $composer.PSObject.Properties) {
		$composerProperties[$property.Name] = $property.Value
	}
	if ($composer.name -ne 'dataphyre/dataphyre') {
		Add-Failure "composer.json package name must be dataphyre/dataphyre"
	}
	if ($composer.license -ne 'MIT') {
		Add-Failure "composer.json license must be MIT"
	}
	if ($composer.type -ne 'library') {
		Add-Failure "composer.json type must be library"
	}
	if ($composer.support.docs -ne 'https://github.com/jeremie5/dataphyre/blob/main/docs/README.md') {
		Add-Failure "composer.json support.docs must point to docs/README.md"
	}
	if (-not $composerProperties.ContainsKey('require') -or $null -eq $composer.require.PSObject.Properties['php']) {
		Add-Failure "composer.json must declare a PHP runtime requirement"
	}
	if ($composerProperties.ContainsKey('autoload') -or $composerProperties.ContainsKey('autoload-dev')) {
		Add-Failure "composer.json must not advertise Composer autoload behavior until docs/PACKAGE.md changes the package contract"
	}
	if (-not $composerProperties.ContainsKey('extra') -or $null -eq $composer.extra.PSObject.Properties['dataphyre'] -or $null -eq $composer.extra.dataphyre.PSObject.Properties['runtime-bootstrap'] -or $composer.extra.dataphyre.'runtime-bootstrap' -ne 'runtime/bootstrap.php') {
		Add-Failure "composer.json extra.dataphyre.runtime-bootstrap must point to runtime/bootstrap.php"
	}
	if ($null -eq $composer.extra.dataphyre.PSObject.Properties['package-contract'] -or $composer.extra.dataphyre.'package-contract' -ne 'docs/PACKAGE.md') {
		Add-Failure "composer.json extra.dataphyre.package-contract must point to docs/PACKAGE.md"
	}
	if ($null -eq $composer.extra.dataphyre.PSObject.Properties['release-manifest'] -or $composer.extra.dataphyre.'release-manifest' -ne 'RELEASE_MANIFEST.json') {
		Add-Failure "composer.json extra.dataphyre.release-manifest must point to RELEASE_MANIFEST.json"
	}
	foreach ($publicDoc in @(
		@{ Path = 'docs/README.md'; Terms = @('Dataphyre is a modular PHP framework and runtime', 'Runtime README', 'Keep ownership obvious') },
		@{ Path = 'docs/GETTING_STARTED.md'; Terms = @('builder_response.first_read.next_action', 'skeleton bodies or cross-page context') },
		@{ Path = 'docs/PACKAGE.md'; Terms = @('builder_response.first_read.next_action', 'detail_page=implementation|verification|controls', 'RELEASE_MANIFEST.json', 'package artifact') }
	)) {
		$publicDocPath = Join-Path $Root $publicDoc.Path
		if (-not (Test-Path $publicDocPath -PathType Leaf)) {
			Add-Failure "$($publicDoc.Path) is missing"
			continue
		}
		$publicDocText = Get-Content -Raw $publicDocPath
		foreach ($term in $publicDoc.Terms) {
			if ($publicDocText -notmatch [regex]::Escape($term)) {
				Add-Failure "$($publicDoc.Path) is missing required public documentation term: $term"
			}
		}
	}
	if ($null -eq $composer.extra.dataphyre.PSObject.Properties['agent-boundary']) {
		Add-Failure "composer.json extra.dataphyre.agent-boundary is required"
	}
	else {
		$agentBoundary = $composer.extra.dataphyre.'agent-boundary'
		if ($agentBoundary.'default-audience' -ne 'application_agents_building_apps') {
			Add-Failure "composer.json extra.dataphyre.agent-boundary.default-audience must be application_agents_building_apps"
		}
		if ($agentBoundary.'mcp-default-work' -ne 'build_applications_with_dataphyre') {
			Add-Failure "composer.json extra.dataphyre.agent-boundary.mcp-default-work must be build_applications_with_dataphyre"
		}
		if ($agentBoundary.'ordinary-app-entrypoint' -ne 'dataphyre_app_builder_plan_generate') {
			Add-Failure "composer.json extra.dataphyre.agent-boundary.ordinary-app-entrypoint must be dataphyre_app_builder_plan_generate"
		}
		if ($agentBoundary.'ordinary-app-payload-profile' -ne 'compact') {
			Add-Failure "composer.json extra.dataphyre.agent-boundary.ordinary-app-payload-profile must be compact"
		}
		if ($agentBoundary.'agentic-enterprise-contract' -ne 'docs/AGENTIC_ENTERPRISE.md') {
			Add-Failure "composer.json extra.dataphyre.agent-boundary.agentic-enterprise-contract must point to docs/AGENTIC_ENTERPRISE.md"
		}
		if ($agentBoundary.'framework-maintenance' -ne 'explicit_framework_contribution_only') {
			Add-Failure "composer.json extra.dataphyre.agent-boundary.framework-maintenance must be explicit_framework_contribution_only"
		}
		foreach ($escalationTrigger in @(
			'release_facing_or_public_framework_claims',
			'corporate_ready_or_enterprise_readiness_claims',
			'security_identity_access_session_credential_governance_tenant_privacy_compliance_billing_data_residency_retention_or_legal_hold_work',
			'dataphyre_framework_internals_or_reusable_framework_contributions',
			'dataphyre_shared_production_hot_path_changes'
		)) {
			if ($agentBoundary.'escalate-only-for' -notcontains $escalationTrigger) {
				Add-Failure "composer.json extra.dataphyre.agent-boundary is missing escalation trigger: $escalationTrigger"
			}
		}
		foreach ($extensionPoint in @('config', 'dialbacks', 'callbacks', 'plugins', 'mcp_metadata', 'application_adapters', 'reusable_modules')) {
			if ($agentBoundary.'extension-points' -notcontains $extensionPoint) {
				Add-Failure "composer.json extra.dataphyre.agent-boundary is missing extension point: $extensionPoint"
			}
		}
		foreach ($extensionPoint in @('config', 'dialbacks', 'callbacks', 'plugins', 'MCP metadata', 'application-owned adapters', 'reusable runtime modules')) {
			if ($agentBoundary.'app-owned-extension-points' -notcontains $extensionPoint) {
				Add-Failure "composer.json extra.dataphyre.agent-boundary is missing app-owned extension point: $extensionPoint"
			}
		}
		foreach ($handoffField in @(
			'prewrite_checklist.implementation_obligations',
			'verification_handoff',
			'verification_execution_plan',
			'acceptance_review_plan',
			'local_convention_probe',
			'write_handoff',
			'implementation_matrix',
			'implementation_recipe',
			'app_contract_summary',
			'tenant_identity_handoff',
			'data_sensitivity_summary',
			'policy_decision_register'
		)) {
			if ($agentBoundary.'app-builder-handoff-fields' -notcontains $handoffField) {
				Add-Failure "composer.json extra.dataphyre.agent-boundary is missing app-builder handoff field: $handoffField"
			}
		}
		foreach ($notDefault in @('project_wide_release_validation', 'dataphyre_mcp_verify_all', 'dataphyre_hot_path_benchmarks', 'runtime_internal_edits')) {
			if ($agentBoundary.'not-default-requirements' -notcontains $notDefault) {
				Add-Failure "composer.json extra.dataphyre.agent-boundary is missing non-default requirement: $notDefault"
			}
		}
	}
	Write-Host 'OK: composer.json package contract checked'
}
catch {
	Add-Failure "composer.json is not valid JSON: $($_.Exception.Message)"
}

$distIgnoreFile = Join-Path $Root '.distignore'
if (Test-Path $distIgnoreFile -PathType Leaf) {
	$distIgnoreRules = Get-Content $distIgnoreFile | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' -and -not $_.StartsWith('#') }
	$requiredDistIgnoreRules = @(
		'/flight_sheet.php',
		'/index.php',
		'/modcache.php',
		'/runtime/modcache.php',
		'/config/*.php',
		'/config/*.php-',
		'!/config/*.example.php',
		'!/config/README.md',
		'/plugins/pre_init/*.php',
		'/plugins/post_init/*.php',
		'/plugins/mcp/*',
		'!/plugins/README.md',
		'/cache/',
		'/cache/*',
		'/hosttmp/',
		'/tmp/',
		'/workspace/',
		'/logs/',
		'/logs/*',
		'/runtime/cache/',
		'/runtime/modules/core/unit_tests/fixtures/core-functions-unavailable-missing/cache/',
		'/runtime/logs/',
		'/runtime/modules/profanity/',
		'/runtime/modules/sentinel/',
		'/sql_migration/plans/',
		'/sql_migration/snapshots/',
		'/dev/',
		'/tools/',
		'/vendor/',
		'/composer.lock',
		'/.github/'
	)
	foreach ($rule in $requiredDistIgnoreRules) {
		if ($rule -notin $distIgnoreRules) {
			Add-Failure ".distignore is missing required public export rule: $rule"
		}
	}
	Write-Host 'OK: .distignore public export rules checked'
}
else {
	Add-Failure '.distignore is missing'
}

if (Test-Path $devDirectory -PathType Container) {
	$prepareExportFile = Join-Path $Root 'dev/tools/private/release/prepare_public_export.ps1'
	$publicExportCheckFile = Join-Path $Root 'dev/tools/private/release/check_public_export.ps1'
	if (Test-Path $prepareExportFile -PathType Leaf) {
		$prepareExportText = Get-Content -Raw $prepareExportFile
		foreach ($term in @(
			'Write-PublicExportDocs',
			'Application agents and framework users should verify the application or module',
			'Framework contributions, release checks, MCP publication validation',
			'Application agents and framework users should verify the application or module',
			'Performance-sensitive Dataphyre framework changes require focused proof before release.',
			'Portable releases keep generated cache and logs out of the runtime package.',
			'dataphyre_public_export_builder'
		)) {
			if ($prepareExportText -notmatch [regex]::Escape($term)) {
				Add-Failure "dev/tools/private/release/prepare_public_export.ps1 is missing package docs boundary term: $term"
			}
		}
	}
	else {
		Add-Failure 'dev/tools/private/release/prepare_public_export.ps1 is missing'
	}
	if (Test-Path $publicExportCheckFile -PathType Leaf) {
		$publicExportCheckText = Get-Content -Raw $publicExportCheckFile
		foreach ($term in @(
			'runtime/modules/core/kernel/application_runtime_realtime_probe.php',
			'Prepared public export docs/README.md still contains non-shipped helper commands',
			'Prepared public export docs/CONTRIBUTING.md still contains non-shipped helper commands',
			'Prepared public export docs/README.md is missing public overview guidance',
			'Prepared public export docs/CONTRIBUTING.md is missing project contribution boundary',
			'Prepared public export docs/CONTRIBUTING.md is missing app-agent audience boundary',
			'Prepared public export is missing MCP guidance file',
			'Prepared public export Markdown still contains contributor dev tool commands',
			'Prepared public export docs/AGENTIC_ENTERPRISE.md still links to non-shipped helper docs',
			'Prepared public export MCP guidance has an overlong non-code line',
			'$insideCodeFence',
			'$line.Length -gt 1200',
			'[int]$WarningLimit = 0',
			'$suppressedWarningCount',
			'Suppressed $suppressedWarningCount warning(s) after WarningLimit=$WarningLimit.'
		)) {
			if ($publicExportCheckText -notmatch [regex]::Escape($term)) {
				Add-Failure "dev/tools/private/release/check_public_export.ps1 is missing package docs guard: $term"
			}
		}
		if ($publicExportCheckText -notmatch [regex]::Escape('[System.IO.Path]::GetFullPath($Path)')) {
			Add-Failure 'dev/tools/private/release/check_public_export.ps1 Get-RelativePath must tolerate absent paths for aggregated missing-file reports'
		}
	}
	else {
		Add-Failure 'dev/tools/private/release/check_public_export.ps1 is missing'
	}
}
Write-Host 'OK: public export docs boundary checked'

$gitIgnoreFile = Join-Path $Root '.gitignore'
if (Test-Path $gitIgnoreFile -PathType Leaf) {
	$gitIgnoreRules = Get-Content $gitIgnoreFile | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' -and -not $_.StartsWith('#') }
	$requiredGitIgnoreRules = @(
		'/flight_sheet.php',
		'/index.php',
		'/modcache.php',
		'/runtime/modcache.php',
		'/config/*.php',
		'/config/*.php-',
		'!/config/*.example.php',
		'!/config/README.md',
		'/plugins/pre_init/*.php',
		'/plugins/post_init/*.php',
		'/plugins/mcp/*',
		'!/plugins/README.md',
		'/cache/',
		'/cache/*',
		'/hosttmp/',
		'/tmp/',
		'/workspace/',
		'/logs/',
		'/logs/*',
		'/runtime/cache/',
		'/runtime/modules/core/unit_tests/fixtures/core-functions-unavailable-missing/cache/',
		'/runtime/logs/',
		'/sql_migration/plans/',
		'/sql_migration/snapshots/',
		'/vendor/',
		'/composer.lock'
	)
	foreach ($rule in $requiredGitIgnoreRules) {
		if ($rule -notin $gitIgnoreRules) {
			Add-Failure ".gitignore is missing required local install rule: $rule"
		}
	}
	Write-Host 'OK: .gitignore local install rules checked'
}
else {
	Add-Failure '.gitignore is missing'
}

$installGuidanceFiles = @(
	@{
		Path = 'config/README.md'
		Terms = @('Extension Boundary', 'application-specific module behavior', 'dialbacks', 'callbacks', 'application-owned adapters', 'runtime-internal edits')
	},
	@{
		Path = 'plugins/README.md'
		Terms = @('app-owned extension layer', 'dialbacks', 'callbacks', 'MCP metadata', 'application-owned adapters', 'runtime-internal edits', 'private declarations')
	},
	@{
		Path = 'docs/GETTING_STARTED.md'
		Terms = @('Application agents', 'install config', 'dialbacks', 'callbacks', 'application-owned adapters', 'runtime-internal edits')
	},
	@{
		Path = 'docs/CONFIGURATION.md'
		Terms = @('Application-Agent Boundary', 'first app-owned extension layer', 'app.php', 'dialbacks', 'callbacks', 'application-owned adapters', 'runtime-internal edits')
	},
	@{
		Path = 'runtime/README.md'
		Terms = @('Runtime Boundary', 'framework code', 'Application behavior', 'install config', 'dialbacks', 'callbacks', 'application-owned adapters', 'runtime internals')
	},
	@{
		Path = 'examples/minimal/README.md'
		Terms = @('Application Boundary', 'Application agents', 'install config', 'dialbacks', 'callbacks', 'application-owned adapters', 'runtime-internal edits')
	}
)
foreach ($installGuidance in $installGuidanceFiles) {
	$installGuidancePath = Join-Path $Root $installGuidance.Path
	if (-not (Test-Path $installGuidancePath -PathType Leaf)) {
		Add-Failure "Install extension guidance file is missing: $($installGuidance.Path)"
		continue
	}
	$installGuidanceText = Get-Content -Raw $installGuidancePath
	foreach ($term in $installGuidance.Terms) {
		if ($installGuidanceText -notmatch [regex]::Escape($term)) {
			Add-Failure "$($installGuidance.Path) is missing install extension boundary term: $term"
		}
	}
}
Write-Host 'OK: install extension boundary docs checked'

$securitySupportGuidanceFiles = @(
	@{
		Path = 'docs/SECURITY.md'
		Terms = @('Agent And Diagnostic Safety', 'redacted MCP diagnostic summaries', 'tenant names', 'customer data', 'dataphyre_mcp_verify_all', 'Dataphyre hot-path benchmarks')
	},
	@{
		Path = 'docs/SUPPORT.md'
		Terms = @('Application agents', 'MCP diagnostic summaries', 'focused application or module checks', 'dataphyre_mcp_verify_all', 'tenant names', 'signed URLs')
	}
)
foreach ($securitySupportGuidance in $securitySupportGuidanceFiles) {
	$securitySupportGuidancePath = Join-Path $Root $securitySupportGuidance.Path
	if (-not (Test-Path $securitySupportGuidancePath -PathType Leaf)) {
		Add-Failure "Security/support guidance file is missing: $($securitySupportGuidance.Path)"
		continue
	}
	$securitySupportGuidanceText = Get-Content -Raw $securitySupportGuidancePath
	foreach ($term in $securitySupportGuidance.Terms) {
		if ($securitySupportGuidanceText -notmatch [regex]::Escape($term)) {
			Add-Failure "$($securitySupportGuidance.Path) is missing security/support agent-safety term: $term"
		}
	}
}
Write-Host 'OK: security/support agent safety docs checked'

$stabilityPolicyFile = Join-Path $Root 'docs/STABILITY.md'
if (-not (Test-Path $stabilityPolicyFile -PathType Leaf)) {
	Add-Failure 'docs/STABILITY.md is missing'
}
else {
	$stabilityPolicyText = Get-Content -Raw $stabilityPolicyFile
	foreach ($term in @(
		'Agent Compatibility Boundary',
		'stable runtime contracts',
		'application-owned adapters',
		'focused application or module checks',
		'dataphyre_mcp_verify_all',
		'Dataphyre hot-path benchmarks',
		'project evidence',
		'shared production hot-path changes'
	)) {
		if ($stabilityPolicyText -notmatch [regex]::Escape($term)) {
			Add-Failure "docs/STABILITY.md is missing agent compatibility boundary term: $term"
		}
	}
}
Write-Host 'OK: stability agent compatibility boundary checked'

$changelogFile = Join-Path $Root 'docs/CHANGELOG.md'
if (-not (Test-Path $changelogFile -PathType Leaf)) {
	Add-Failure 'docs/CHANGELOG.md is missing'
}
else {
	$changelogText = Get-Content -Raw $changelogFile
	foreach ($term in @(
		'application agents building apps',
		'dataphyre_mcp_verify_all',
		'Dataphyre hot-path benchmarks',
		'project evidence',
		'shared production hot-path work'
	)) {
		if ($changelogText -notmatch [regex]::Escape($term)) {
			Add-Failure "docs/CHANGELOG.md is missing application-agent release boundary term: $term"
		}
	}
}
Write-Host 'OK: changelog application-agent release boundary checked'

$moduleRoot = Join-Path $Root 'runtime/modules'
$modulesFile = Join-Path $Root 'docs/MODULES.md'
if (Test-Path $moduleRoot) {
	$moduleDirectories = @(Get-ChildItem -Path $moduleRoot -Directory | Where-Object {
		$relative = (Get-RelativePath $_.FullName).TrimEnd('/') + '/'
		-not (Test-Excluded $relative $script:GeneratedPrefixes) -and
			$null -ne (Get-ChildItem -LiteralPath $_.FullName -Recurse -File | Select-Object -First 1)
	} | Select-Object -ExpandProperty Name | Sort-Object)
	$releaseModuleDirectories = @($moduleDirectories | Where-Object { $_ -notin $ReleaseRedactedModuleNames })
	$redactedModuleDirectories = @($moduleDirectories | Where-Object { $_ -in $ReleaseRedactedModuleNames })
	foreach ($module in $releaseModuleDirectories) {
		$documentationDirectory = Join-Path $moduleRoot "$module/documentation"
		$markdownCount = 0
		if (Test-Path $documentationDirectory) {
			$markdownCount = (Get-ChildItem -Path $documentationDirectory -File -Filter '*.md' | Measure-Object).Count
		}
		if ($markdownCount -lt 1) {
			Add-Failure "Module '$module' has no markdown documentation under runtime/modules/$module/documentation"
		}
	}
	Write-Host 'OK: module documentation directories checked'

	if (Test-Path $modulesFile) {
		$moduleText = Get-Content -Raw $modulesFile
		$listedModules = [regex]::Matches($moduleText, '\| `([^`]+)` \|') | ForEach-Object {
			$_.Groups[1].Value
		} | Sort-Object
		$missing = $releaseModuleDirectories | Where-Object { $_ -notin $listedModules }
		$extra = $listedModules | Where-Object { $_ -notin $releaseModuleDirectories }
		foreach ($module in $missing) {
			Add-Failure "docs/MODULES.md is missing runtime module '$module'"
		}
		foreach ($module in $extra) {
			Add-Failure "docs/MODULES.md lists unknown module '$module'"
		}
		Write-Host 'OK: docs/MODULES.md coverage checked'
	}
	else {
		Add-Failure 'docs/MODULES.md is missing'
	}

	$mcpDeclarationRoot = Join-Path $Root 'plugins/mcp'
	if ((Test-Path $mcpDeclarationRoot -PathType Container) -and $redactedModuleDirectories.Count -gt 0) {
		$declaredModules = @{}
		foreach ($declarationFile in Get-ChildItem -Path $mcpDeclarationRoot -File -Filter '*.json') {
			try {
				$declarationDocument = Get-Content -Raw $declarationFile.FullName | ConvertFrom-Json
			}
			catch {
				Add-Failure "Invalid MCP module declaration JSON in $(Get-RelativePath $declarationFile.FullName): $($_.Exception.Message)"
				continue
			}
			if ($null -eq $declarationDocument.PSObject.Properties['declarations'] -or $declarationDocument.declarations -isnot [System.Collections.IEnumerable]) {
				Add-Failure "MCP module declaration file must contain a declarations array: $(Get-RelativePath $declarationFile.FullName)"
				continue
			}
			foreach ($declaration in $declarationDocument.declarations) {
				$properties = @{}
				foreach ($property in $declaration.PSObject.Properties) {
					$properties[$property.Name] = $property.Value
				}
				if (-not $properties.ContainsKey('name') -or [string]::IsNullOrWhiteSpace([string]$properties['name'])) {
					Add-Failure "MCP module declaration is missing name in $(Get-RelativePath $declarationFile.FullName)"
					continue
				}
				if (-not $properties.ContainsKey('release') -or [string]$properties['release'] -ne 'redacted') {
					Add-Failure "MCP module declaration for '$($properties['name'])' must set release to redacted"
				}
				if (-not $properties.ContainsKey('visibility') -or [string]::IsNullOrWhiteSpace([string]$properties['visibility'])) {
					Add-Failure "MCP module declaration for '$($properties['name'])' must set visibility"
				}
				$declaredModules[[string]$properties['name']] = $true
			}
		}
		foreach ($module in $redactedModuleDirectories) {
			if (-not $declaredModules.ContainsKey($module)) {
				Add-Failure "Redacted runtime module '$module' is present locally but missing from plugins/mcp declarations"
			}
		}
		Write-Host 'OK: MCP redacted module declarations checked'
	}
}
else {
	Add-Failure 'runtime/modules directory is missing'
}

$gitattributesFile = Join-Path $Root '.gitattributes'
$thirdPartyNoticeFile = Join-Path $Root 'docs/THIRD_PARTY_NOTICES.md'
$gitattributesText = ''
if (Test-Path $gitattributesFile) {
	$gitattributesText = Get-Content -Raw $gitattributesFile
}
$thirdPartyNoticeText = ''
if (Test-Path $thirdPartyNoticeFile) {
	$thirdPartyNoticeText = Get-Content -Raw $thirdPartyNoticeFile
}
foreach ($component in $BundledComponents) {
	$componentPath = Join-Path $Root $component.Path
	$licensePath = Join-Path $Root $component.License
	if (-not (Test-Path $componentPath)) {
		Add-Failure "Bundled component path is missing: $($component.Path)"
	}
	if (-not (Test-Path $licensePath -PathType Leaf)) {
		Add-Failure "Bundled component license is missing for $($component.Name): $($component.License)"
	}
	if ($gitattributesText -notmatch [regex]::Escape($component.Path.TrimEnd('/')) -and $gitattributesText -notmatch [regex]::Escape(($component.Path.TrimEnd('/') + '/**'))) {
		Add-Failure "Bundled component is not marked in .gitattributes: $($component.Path)"
	}
	if ($thirdPartyNoticeText -notmatch [regex]::Escape($component.License)) {
		Add-Failure "docs/THIRD_PARTY_NOTICES.md is missing license link for $($component.Name): $($component.License)"
	}
}
Write-Host 'OK: bundled third-party notices checked'

$markdownFiles = Get-RepoFiles | Where-Object { $_.Extension -eq '.md' }
foreach ($file in $markdownFiles) {
	$relativeFile = Get-RelativePath $file.FullName
	$baseDirectory = Split-Path $file.FullName -Parent
	$text = [System.IO.File]::ReadAllText($file.FullName)
	foreach ($match in [regex]::Matches($text, '\[[^\]]*\]\(([^)]+)\)')) {
		$target = $match.Groups[1].Value.Trim()
		if ($target -match '^(https?:|mailto:|#)') {
			continue
		}
		$target = ($target -split '\s+')[0]
		$target = $target.Trim('<', '>')
		$target = $target -replace '#.*$', ''
		if ($target -eq '') {
			continue
		}
		$decodedTarget = [System.Uri]::UnescapeDataString($target)
		$candidate = Join-Path $baseDirectory $decodedTarget
		if (-not (Test-Path $candidate)) {
			Add-Failure "Broken local markdown link in ${relativeFile}: $target"
		}
	}
}
Write-Host 'OK: markdown local links checked'

$jsonFiles = Get-RepoFiles | Where-Object { $_.Extension -eq '.json' }
foreach ($file in $jsonFiles) {
	try {
		Assert-JsonFileValid $file.FullName
	}
	catch {
		Add-Failure "Invalid JSON in $(Get-RelativePath $file.FullName): $($_.Exception.Message)"
	}
}
Write-Host 'OK: JSON files checked'

$phpGuardFiles = Get-RepoFiles | Where-Object {
	$_.Extension -eq '.php' -or $_.Name.EndsWith('.php-', [System.StringComparison]::OrdinalIgnoreCase)
}
foreach ($file in $phpGuardFiles) {
	$relative = Get-RelativePath $file.FullName
	$text = [System.IO.File]::ReadAllText($file.FullName)
	$classDeclarations = [regex]::Matches($text, '(?m)^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b')
	foreach ($classDeclaration in $classDeclarations) {
		$className = $classDeclaration.Groups[1].Value
		$prefix = $text.Substring(0, $classDeclaration.Index)
		$guardPattern = "class_exists\(\s*['""]\\?$([regex]::Escape($className))['""]\s*,\s*false\s*\)"
		if ($prefix -match $guardPattern) {
			Add-Failure "PHP file uses a self class_exists() guard before declaring '$className'; use an explicit load constant instead: $relative"
		}
	}
}
Write-Host 'OK: PHP self class guard patterns checked'

$evalAllowedPrefixes = @(
	'runtime/modules/dpanel/'
)
$evalAllowedFiles = @(
	'runtime/modules/testing/tooling/TestKit.php'
)
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$evalTokenProbe = @'
$source = file_get_contents($argv[1]);
if ($source === false) {
	fwrite(STDERR, "Unable to read PHP source.\n");
	exit(2);
}
foreach (token_get_all($source) as $token) {
	if (is_array($token) && $token[0] === T_EVAL) {
		echo $token[2], PHP_EOL;
	}
}
'@
foreach ($file in $phpGuardFiles) {
	$relative = Get-RelativePath $file.FullName
	$normalizedRelative = $relative -replace '\\', '/'
	$isModuleOrAppUnitTest =
		$normalizedRelative -match '^runtime/modules/[^/]+/unit_tests/' -or
		$normalizedRelative -match '^applications/.+/unit_tests/'
	if ((Test-Excluded $normalizedRelative $evalAllowedPrefixes) -or
		$evalAllowedFiles -contains $normalizedRelative -or
		$isModuleOrAppUnitTest) {
		continue
	}
	if (-not (Select-String -Path $file.FullName -Pattern '\beval\s*\(' -Quiet)) {
		continue
	}
	if ($null -eq $phpCommand) {
		Add-Failure "PHP is required to lexically audit eval usage after a candidate was found: $relative"
		continue
	}
	$global:LASTEXITCODE = 0
	$evalLines = @(& $phpCommand.Source -r $evalTokenProbe -- $file.FullName)
	$evalProbeExitCode = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }
	if ($evalProbeExitCode -ne 0) {
		Add-Failure "PHP lexical eval audit failed for $relative with exit code $evalProbeExitCode"
		continue
	}
	foreach ($line in $evalLines) {
		if ([string]$line -match '^\d+$') {
			Add-Failure "PHP file uses eval() outside the test harness allowlist: ${relative}:$line"
		}
	}
}
Write-Host 'OK: PHP eval usage checked'

$staleLicensePattern = 'All Rights Reserved|dual licensing|Free Personal Use License|Commercial License|property of Shopiro|proprietary to Shopiro|strictly forbidden|Canadian and Foreign Patents'
$licenseScanFiles = Get-RepoFiles | Where-Object {
	$_.Extension -in @('.php', '.md', '.json') -or $_.Name.EndsWith('.php-', [System.StringComparison]::OrdinalIgnoreCase)
}
foreach ($file in $licenseScanFiles) {
	$matches = Select-String -Path $file.FullName -Pattern $staleLicensePattern -AllMatches
	foreach ($match in $matches) {
		Add-Failure "Stale proprietary/license wording in $(Get-RelativePath $file.FullName):$($match.LineNumber)"
	}
}
Write-Host 'OK: stale license wording checked'

$releaseHygienePatterns = @(
	@{ Label = 'legacy project spelling'; Pattern = 'Data' + 'hyre' },
	@{ Label = 'shell recursive delete'; Pattern = 'rm\s+-rf' },
	@{ Label = 'shell command execution'; Pattern = '\bshell' + '_exec\s*\(' },
	@{ Label = 'personal author email'; Pattern = 'jeremie@' + 'phyro\.ca' }
)
foreach ($file in $licenseScanFiles) {
	$relative = Get-RelativePath $file.FullName
	foreach ($hygienePattern in $releaseHygienePatterns) {
		$matches = Select-String -Path $file.FullName -Pattern $hygienePattern.Pattern -AllMatches
		foreach ($match in $matches) {
			Add-Failure "Release hygiene issue ($($hygienePattern.Label)) in ${relative}:$($match.LineNumber)"
		}
	}
}
Write-Host 'OK: release hygiene markers checked'

$phpFiles = Get-ChildItem -Path $Root -Recurse -File | Where-Object {
	$relative = Get-RelativePath $_.FullName
	($_.Extension -eq '.php' -or $_.Name.EndsWith('.php-', [System.StringComparison]::OrdinalIgnoreCase)) -and
	-not (Test-Excluded $relative $HeaderExcludedPrefixes) -and
	$HeaderExcludedFiles -notcontains $relative -and
	-not ($relative -like 'config/*.php' -and $relative -notlike 'config/*.example.php')
}
foreach ($file in $phpFiles) {
	$relative = Get-RelativePath $file.FullName
	$text = [System.IO.File]::ReadAllText($file.FullName)
	if (-not ($text.StartsWith('<?php') -or $text -match '\A#![^\r\n]*(\r?\n)<\?php')) {
		Add-Failure "File has PHP extension but does not start with <?php or a PHP shebang: $relative"
		continue
	}
	if ($text -notmatch 'SPDX-License-Identifier:\s*MIT') {
		Add-Failure "Owned PHP file is missing MIT/SPDX header: $relative"
	}
}
Write-Host 'OK: owned PHP headers checked'

if ($failures.Count -gt 0) {
	Write-Host ''
	Write-Host "Release checks failed with $($failures.Count) issue(s)."
	exit 1
}

Write-Host ''
Write-Host 'Release checks passed.'
