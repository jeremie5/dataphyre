[CmdletBinding()]
param(
	[string]$Root,
	[Parameter(Mandatory = $true)]
	[string]$Output,
	[string]$RulesFile,
	[string]$Php,
	[switch]$SkipChecks
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
if ([string]::IsNullOrWhiteSpace($RulesFile)) {
	$RulesFile = Join-Path $Root '.distignore'
}
else {
	$RulesFile = (Resolve-Path $RulesFile).Path
}

function Resolve-ParentPath {
	param([string]$Path)
	$parent = Split-Path -Path $Path -Parent
	if ([string]::IsNullOrWhiteSpace($parent)) {
		$parent = '.'
	}
	return (Resolve-Path $parent).Path
}

function Normalize-FullPath {
	param([string]$Path)
	$parent = Resolve-ParentPath $Path
	$leaf = Split-Path -Path $Path -Leaf
	if ([string]::IsNullOrWhiteSpace($leaf)) {
		return (Resolve-Path $Path).Path
	}
	return [System.IO.Path]::GetFullPath((Join-Path $parent $leaf))
}

$Output = Normalize-FullPath $Output
$rootWithSeparator = $Root.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
$outputWithSeparator = $Output.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar

if ($Output.Equals($Root, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'Output directory cannot be the source root.'
}
if ($Output.StartsWith($rootWithSeparator, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'Output directory must be outside the source root so the export cannot copy itself.'
}
if ($Root.StartsWith($outputWithSeparator, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'Output directory cannot contain the source root.'
}
if (-not (Test-Path $RulesFile -PathType Leaf)) {
	throw "Rules file is missing: $RulesFile"
}

function Get-RelativePath {
	param([string]$Path)
	$rootPath = $script:Root.TrimEnd('\', '/')
	$rootPrefix = $rootPath + [System.IO.Path]::DirectorySeparatorChar
	# Get-ChildItem can enumerate names such as Windows' reserved `NUL`, but
	# Resolve-Path and GetFullPath translate them to device paths. Prefer the
	# already-absolute enumerated spelling so ignore rules run before any reopen.
	if ($Path.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
		return $Path.Substring($rootPrefix.Length) -replace '\\', '/'
	}
	$fullPath = [System.IO.Path]::GetFullPath($Path)
	if ($fullPath.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
		return $fullPath.Substring($rootPrefix.Length) -replace '\\', '/'
	}
	return $fullPath -replace '\\', '/'
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
	if ($RelativePath.StartsWith('.git/', [System.StringComparison]::OrdinalIgnoreCase)) {
		return $true
	}
	$ignored = $false
	foreach ($rule in $Rules) {
		if (Test-RuleMatch $rule $RelativePath) {
			$ignored = -not $rule.Negated
		}
	}
	return $ignored
}

if (Test-Path $Output) {
	$existingItems = Get-ChildItem -Path $Output -Force
	if (($existingItems | Measure-Object).Count -gt 0) {
		throw "Output directory already exists and is not empty: $Output"
	}
}
else {
	New-Item -ItemType Directory -Path $Output | Out-Null
}

$rules = Get-Content $RulesFile | ForEach-Object { ConvertTo-Rule $_ } | Where-Object { $_ -ne $null }
$redactedPublicPrefixes = @(
	('runtime/modules/' + 'conting' + 'ency/'),
	('runtime/modules/' + 'cj' + 'dropshipping/'),
	('runtime/modules/' + 'shopiro' + '_devapi/'),
	'runtime/modules/profanity/',
	'runtime/modules/sentinel/'
)
$copied = 0
$skipped = 0

function Test-RedactedPublicPath {
	param([string]$RelativePath)
	$path = $RelativePath.TrimEnd('/')
	foreach ($prefix in $script:redactedPublicPrefixes) {
		$directory = $prefix.TrimEnd('/')
		if ($path.Equals($directory, [System.StringComparison]::OrdinalIgnoreCase) -or
			$path.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
			return $true
		}
	}
	return $false
}

function Set-TextNoBom {
	param(
		[string]$Path,
		[string]$Text
	)
	[System.IO.File]::WriteAllText($Path, $Text, [System.Text.UTF8Encoding]::new($false))
}

function Remove-RedactedExportMetadata {
	$attributesPath = Join-Path $script:Output '.gitattributes'
	if (-not (Test-Path $attributesPath -PathType Leaf)) {
		return
	}
	$lines = Get-Content $attributesPath
	$filtered = New-Object System.Collections.Generic.List[string]
	foreach ($line in $lines) {
		$trimmed = $line.Trim()
		if ($trimmed -match '^#\s*Internal service clients' -or
			$trimmed -match '^#\s*Archive/release boundary\.?$') {
			continue
		}
		$pathToken = ($trimmed -split '\s+')[0]
		$normalized = $pathToken.TrimStart('/') -replace '\\', '/'
		$normalized = $normalized -replace '/\*\*$', '/'
		if ($normalized -ne '' -and (Test-RedactedPublicPath $normalized)) {
			continue
		}
		$filtered.Add($line) | Out-Null
	}
	$text=($filtered -join "`n")+"`n"
	if((Get-Content -Raw $attributesPath) -ne $text){
		Set-TextNoBom -Path $attributesPath -Text $text
	}
}

function Write-PublicExportDocs {
	$readmePath = Join-Path $script:Output 'docs/README.md'
	if (Test-Path $readmePath -PathType Leaf) {
		$readme = Get-Content -Raw $readmePath
		$replacement = @'
## Verification

This Dataphyre release ships runtime code, public docs, examples, and
`RELEASE_MANIFEST.json`.

Application agents and framework users should verify the application or module
they are changing with focused app-owned tests, diagnostics, or deployment
checks.

## License
'@
		$readme = [regex]::Replace($readme, '(?s)## (?:Maintainer Source-Checkout )?Verification\s+.*?## License', $replacement)
		if((Get-Content -Raw $readmePath) -ne $readme){
			Set-TextNoBom -Path $readmePath -Text $readme
		}
	}

	$agenticPath = Join-Path $script:Output 'docs/AGENTIC_ENTERPRISE.md'
	if (Test-Path $agenticPath -PathType Leaf) {
		$agentic = Get-Content -Raw $agenticPath
		$agentic = [regex]::Replace(
			$agentic,
			"(?m)^- \[Performance contract\]\(\.\./dev/PERFORMANCE\.md\)\r?\n- \[Quality gates\]\(\.\./dev/QUALITY_GATES\.md\)",
			'- Performance-sensitive Dataphyre framework changes require focused proof before release.'
		)
		$agentic = [regex]::Replace(
			$agentic,
			"(?s)Maintainer-only source-checkout contracts live at `dev/PERFORMANCE\.md` and\s+`dev/QUALITY_GATES\.md`\. They are intentionally not linked as public release\s+documents because `dev/` is excluded from prepared public exports\.",
			'Performance-sensitive Dataphyre framework changes require focused proof before release.'
		)
		if((Get-Content -Raw $agenticPath) -ne $agentic){
			Set-TextNoBom -Path $agenticPath -Text $agentic
		}
	}

	$architecturePath = Join-Path $script:Output 'docs/ARCHITECTURE.md'
	if (Test-Path $architecturePath -PathType Leaf) {
		$architecture = Get-Content -Raw $architecturePath
		$architecture = [regex]::Replace(
			$architecture,
			'(?s)Contributor-only release and export scripts under `dev/tools/` enforce that\s+boundary in source checkouts\. Prepared public exports do not include `dev/`,\s+legacy `tools/`,(?: GitHub CI/PR templates,)? benchmark artifacts, generated cache,\s+or logs\.',
			'Portable releases keep generated cache and logs out of the runtime package.'
		)
		if((Get-Content -Raw $architecturePath) -ne $architecture){
			Set-TextNoBom -Path $architecturePath -Text $architecture
		}
	}

	$changelogPath = Join-Path $script:Output 'docs/CHANGELOG.md'
	if (Test-Path $changelogPath -PathType Leaf) {
		$changelog = Get-Content -Raw $changelogPath
			$changelog = $changelog.Replace(
			'Added release verification tooling in `dev/tools/check_release.ps1`, including',
			'Added release verification coverage, including'
		)
		if((Get-Content -Raw $changelogPath) -ne $changelog){
			Set-TextNoBom -Path $changelogPath -Text $changelog
		}
	}

	$stabilityPath = Join-Path $script:Output 'docs/STABILITY.md'
	if (Test-Path $stabilityPath -PathType Leaf) {
		$stability = Get-Content -Raw $stabilityPath
		$stability = [regex]::Replace(
			$stability,
			'(?s)- Maintainer/source-checkout public release tooling:\s+`dev/tools/check_release\.ps1`, `dev/tools/check_public_export\.ps1`, and\s+`dev/tools/prepare_public_export\.ps1`\.',
			'- Project release validation applies to Dataphyre publication work, not ordinary application work.'
		)
		if((Get-Content -Raw $stabilityPath) -ne $stability){
			Set-TextNoBom -Path $stabilityPath -Text $stability
		}
	}

	$contributingPath = Join-Path $script:Output 'docs/CONTRIBUTING.md'
	if (Test-Path $contributingPath -PathType Leaf) {
		$contributing = @'
# Contributing to Dataphyre

This package contains runtime docs, examples, package metadata, and release
attestation for Dataphyre.

Most MCP users are application agents building applications with Dataphyre, not
Dataphyre framework contributors. Application work should stay in the consuming
application unless the change is truly reusable framework behavior.

Application teams building on Dataphyre should not patch framework internals to
make one application work. Use configuration, dialbacks, callbacks, plugins,
application-owned adapters, or reusable runtime modules first, then verify the
affected application or module with focused app-owned checks.

Framework contributions, release checks, MCP publication validation, and
Dataphyre hot-path benchmark evidence are project workflows. Open issues and
pull requests against the source repository referenced by the package metadata.

By contributing, you agree that your contributions are licensed under the same
license as Dataphyre.
'@
		if((Get-Content -Raw $contributingPath) -ne $contributing){
			Set-TextNoBom -Path $contributingPath -Text $contributing
		}
	}

	# Contributor CLIs live under dev/, which is intentionally absent from the
	# prepared package. Keep source-checkout docs useful in git while ensuring
	# exported Markdown never advertises an executable path that was not shipped.
	foreach ($markdownFile in Get-ChildItem -Path $script:Output -Recurse -File -Filter '*.md') {
		$markdown = Get-Content -Raw $markdownFile.FullName
		$publicMarkdown = [regex]::Replace(
			$markdown,
			'(?i)(?:\./)?dev[\\/]tools[\\/](?:[a-z0-9_.-]+[\\/])*[a-z0-9_.-]+',
			'source-checkout-maintainer-tool'
		)
		if ($markdown -ne $publicMarkdown) {
			Set-TextNoBom -Path $markdownFile.FullName -Text $publicMarkdown
		}
	}
}

function Write-ReleaseManifest {
	$manifestPath = Join-Path $script:Output 'RELEASE_MANIFEST.json'
	$exportFiles = Get-ChildItem -Path $script:Output -Recurse -File -Force | Where-Object {
		$_.FullName -ne $manifestPath
	} | Sort-Object FullName
	$fileEntries = @(
		foreach ($file in $exportFiles) {
			$relative = $file.FullName.Substring($script:Output.TrimEnd('\', '/').Length).TrimStart('\', '/') -replace '\\', '/'
			[ordered]@{
				path = $relative
				bytes = $file.Length
				sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash.ToLowerInvariant()
			}
		}
	)
	$treeHashInput = New-Object System.Text.StringBuilder
	$entryByPath = @{}
	[string[]]$entryPaths = @(
		foreach ($entry in $fileEntries) {
			$path = [string]$entry.path
			$entryByPath[$path] = $entry
			$path
		}
	)
	[Array]::Sort($entryPaths, [StringComparer]::Ordinal)
	foreach ($path in $entryPaths) {
		$entry = $entryByPath[$path]
		[void]$treeHashInput.Append($entry.path)
		[void]$treeHashInput.Append("`t")
		[void]$treeHashInput.Append($entry.bytes)
		[void]$treeHashInput.Append("`t")
		[void]$treeHashInput.Append($entry.sha256)
		[void]$treeHashInput.Append("`n")
	}
	$treeHashBytes = [System.Text.Encoding]::UTF8.GetBytes($treeHashInput.ToString())
	$treeHash = [System.BitConverter]::ToString([System.Security.Cryptography.SHA256]::Create().ComputeHash($treeHashBytes)).Replace('-', '').ToLowerInvariant()
	$moduleInventory = @()
	$modulesFile = Join-Path $script:Output 'docs/MODULES.md'
	if (Test-Path $modulesFile -PathType Leaf) {
		$moduleInventory = @(
			foreach ($line in Get-Content $modulesFile) {
				$match = [regex]::Match($line, '^\| `([^`]+)` \| ([^|]+) \| ([^|]+) \| \[docs\]\(([^)]+)\) \| (.+) \|$')
				if (-not $match.Success) {
					continue
				}
				[ordered]@{
					name = $match.Groups[1].Value
					status = $match.Groups[2].Value.Trim()
					runtime_critical = $match.Groups[3].Value.Trim().Equals('Yes', [System.StringComparison]::OrdinalIgnoreCase)
					docs = $match.Groups[4].Value.Trim()
					purpose = $match.Groups[5].Value.Trim()
				}
			}
		)
	}
	$bundledComponents = @(
		[ordered]@{
			name = 'Stripe PHP'
			path = 'runtime/modules/stripe/src/'
			license = 'MIT'
			license_file = 'runtime/modules/stripe/src/LICENSE'
		},
		[ordered]@{
			name = 'Adminer'
			path = 'runtime/modules/sql/third_party/adminer/'
			license = 'Apache-2.0'
			license_file = 'runtime/modules/sql/third_party/adminer/LICENSE'
		}
	)
	$manifest = [ordered]@{
		schema = 'dataphyre.public_export_manifest.v1'
		package = 'dataphyre/dataphyre'
		generated_by = 'dataphyre_public_export_builder'
		generated_at_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
		copied_source_files = $script:copied
		skipped_source_files = $script:skipped
		export_file_count = $fileEntries.Count + 1
		export_tree_sha256 = $treeHash
		release_boundary = [ordered]@{
			default_audience = 'application_agents_building_apps'
			intended_use = 'runtime_docs_examples_and_release_attestation'
			ordinary_app_entrypoint = 'dataphyre_app_builder_plan_generate'
			ordinary_app_payload_profile = 'compact'
			ordinary_agent_verification = 'focused application or module checks owned by the consuming application'
			app_owned_extension_points = @(
				'config',
				'dialbacks',
				'callbacks',
				'plugins',
				'MCP metadata',
				'application-owned adapters',
				'reusable runtime modules'
			)
			app_builder_handoff_fields = @(
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
			)
			escalate_only_for = @(
				'release-facing or public Dataphyre framework claims',
				'corporate-ready or enterprise-readiness claims',
				'security, identity/access, session, credential, governance, tenant isolation, billing, privacy, compliance, data residency, retention, legal-hold, or access-policy work',
				'Dataphyre framework internals or reusable framework contributions',
				'Dataphyre shared production hot-path changes'
			)
			not_ordinary_app_ceremony = @(
				'dataphyre_mcp_verify_all',
				'Dataphyre project-wide release validation',
				'Dataphyre hot-path benchmarks',
				'Dataphyre runtime-internal edits to make one application work'
			)
			project_evidence_scope = @(
				'Dataphyre framework changes',
				'MCP/release-surface claims',
				'public release preparation',
				'shared production hot-path changes'
			)
		}
		excluded_categories = @(
			'local install state',
			'generated cache and logs',
			'private adapter modules',
			'install plugin declarations',
			'dependency lockfiles'
		)
		verification = @(
			'release_manifest_integrity',
			'public_file_inventory',
			'public_boundary_checks'
		)
		verification_scope = 'release_attestation_not_app_runtime_requirement'
		modules = $moduleInventory
		bundled_components = $bundledComponents
		files = $fileEntries
	}
	Set-TextNoBom -Path $manifestPath -Text (($manifest | ConvertTo-Json -Depth 4)+"`n")
}

Write-Host "Preparing Dataphyre public export"
Write-Host "Source: $Root"
Write-Host "Output: $Output"
Write-Host "Rules:  $RulesFile"

Get-ChildItem -Path $Root -Recurse -File -Force | ForEach-Object {
	$relative = Get-RelativePath $_.FullName
	if ((Test-DistIgnored $relative $rules) -or (Test-RedactedPublicPath $relative)) {
		$script:skipped++
		return
	}
	$localRelative = $relative.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
	$target = Join-Path $Output $localRelative
	$targetDirectory = Split-Path -Path $target -Parent
	if (-not (Test-Path $targetDirectory)) {
		New-Item -ItemType Directory -Path $targetDirectory -Force | Out-Null
	}
	Copy-Item -LiteralPath $_.FullName -Destination $target -Force
	$script:copied++
}

Remove-RedactedExportMetadata
Write-PublicExportDocs
Write-ReleaseManifest
Write-Host "Copied $copied file(s); skipped $skipped file(s)."
Write-Host 'Release manifest written.'

if (-not $SkipChecks) {
	$exportCheck = Join-Path $scriptDirectory 'check_public_export.ps1'
	$releaseCheck = Join-Path $scriptDirectory 'check_release.ps1'
	Write-Host ''
	$global:LASTEXITCODE = 0
	$exportCheckArgs = @{ Root = $Output }
	if (-not [string]::IsNullOrWhiteSpace($Php)) {
		$exportCheckArgs.Php = $Php
	}
	& $exportCheck @exportCheckArgs
	$exportCheckSucceeded = $?
	$exportCheckExitCode = $LASTEXITCODE
	if (-not $exportCheckSucceeded -or $exportCheckExitCode -ne 0) {
		throw "Public export check failed with exit code $exportCheckExitCode."
	}
	Write-Host ''
	$global:LASTEXITCODE = 0
	& $releaseCheck -Root $Output
	$releaseCheckSucceeded = $?
	$releaseCheckExitCode = $LASTEXITCODE
	if (-not $releaseCheckSucceeded -or $releaseCheckExitCode -ne 0) {
		throw "Release check failed with exit code $releaseCheckExitCode."
	}
}

Write-Host ''
Write-Host 'Public export prepared.'
