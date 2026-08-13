[CmdletBinding()]
param(
	[Parameter(Mandatory = $true)]
	[string]$Root
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Root = (Resolve-Path $Root).Path
$failures = New-Object System.Collections.Generic.List[string]

function Add-Failure {
	param([string]$Message)
	$script:failures.Add($Message) | Out-Null
	Write-Host "FAIL: $Message"
}

function Get-RelativePath {
	param([string]$Path)
	$fullPath = (Resolve-Path $Path).Path
	$rootPath = $script:Root.TrimEnd('\', '/')
	if ($fullPath.StartsWith($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
		return $fullPath.Substring($rootPath.Length).TrimStart('\', '/') -replace '\\', '/'
	}
	return $fullPath -replace '\\', '/'
}

function Test-IntegerAtLeast {
	param(
		[object]$Value,
		[long]$Minimum
	)
	if ($null -eq $Value) {
		return $false
	}
	foreach ($type in @(
		[byte],
		[sbyte],
		[int16],
		[uint16],
		[int],
		[uint32],
		[long],
		[uint64]
	)) {
		if ($Value -is $type) {
			return [decimal]$Value -ge [decimal]$Minimum
		}
	}
	return $false
}

Write-Host "Verifying Dataphyre release manifest at $Root"

$manifestPath = Join-Path $Root 'RELEASE_MANIFEST.json'
$manifestSchemaPath = Join-Path $Root 'docs/RELEASE_MANIFEST.schema.json'

if (-not (Test-Path $manifestSchemaPath -PathType Leaf)) {
	Add-Failure 'Prepared public export is missing docs/RELEASE_MANIFEST.schema.json'
}
else {
	try {
		$manifestSchema = Get-Content -Raw $manifestSchemaPath | ConvertFrom-Json
		if ($manifestSchema.title -ne 'Dataphyre Public Export Manifest') {
			Add-Failure 'docs/RELEASE_MANIFEST.schema.json has an unexpected title'
		}
		if ($manifestSchema.properties.schema.const -ne 'dataphyre.public_export_manifest.v1') {
			Add-Failure 'docs/RELEASE_MANIFEST.schema.json has an unexpected manifest schema const'
		}
	}
	catch {
		Add-Failure "docs/RELEASE_MANIFEST.schema.json is not valid JSON: $($_.Exception.Message)"
	}
}

if (-not (Test-Path $manifestPath -PathType Leaf)) {
	Add-Failure 'Prepared public export is missing RELEASE_MANIFEST.json'
}
else {
	try {
		$manifest = Get-Content -Raw $manifestPath | ConvertFrom-Json
		if ($manifest.schema -ne 'dataphyre.public_export_manifest.v1') {
			Add-Failure 'RELEASE_MANIFEST.json has an unexpected schema value'
		}
		if ($manifest.package -ne 'dataphyre/dataphyre') {
			Add-Failure 'RELEASE_MANIFEST.json has an unexpected package value'
		}
		if ($manifest.generated_by -ne 'dataphyre_public_export_builder') {
			Add-Failure 'RELEASE_MANIFEST.json has an unexpected generated_by value'
		}
		if (-not (Test-IntegerAtLeast $manifest.copied_source_files 1)) {
			Add-Failure 'RELEASE_MANIFEST.json copied_source_files must be a positive integer'
		}
		if (-not (Test-IntegerAtLeast $manifest.skipped_source_files 0)) {
			Add-Failure 'RELEASE_MANIFEST.json skipped_source_files must be a non-negative integer'
		}
		if (-not (Test-IntegerAtLeast $manifest.export_file_count 1) -or [decimal]$manifest.export_file_count -lt [decimal]$manifest.copied_source_files) {
			Add-Failure 'RELEASE_MANIFEST.json export_file_count must cover exported source files'
		}

		$exportFiles = @(Get-ChildItem -Path $Root -Recurse -File -Force | Where-Object {
			(Get-RelativePath $_.FullName) -ne 'RELEASE_MANIFEST.json'
		})
		if ($manifest.export_file_count -ne ($exportFiles.Count + 1)) {
			Add-Failure 'RELEASE_MANIFEST.json export_file_count does not match the prepared export'
		}
		if ($null -eq $manifest.files -or $manifest.files.Count -ne $exportFiles.Count) {
			Add-Failure 'RELEASE_MANIFEST.json file entry count does not match the prepared export'
		}
		else {
			$manifestFiles = @{}
			foreach ($entry in $manifest.files) {
				$path = ''
				if ($null -ne $entry.PSObject.Properties['path']) {
					$path = [string]$entry.path
				}
				if ($path -eq '' -or $path -match '(^|/)\.\.(/|$)' -or $path.StartsWith('/', [System.StringComparison]::Ordinal)) {
					Add-Failure "RELEASE_MANIFEST.json contains an invalid file path: $path"
					continue
				}
				if ($manifestFiles.ContainsKey($path)) {
					Add-Failure "RELEASE_MANIFEST.json contains a duplicate file path: $path"
					continue
				}
				$manifestFiles[$path] = $entry
			}
			foreach ($file in $exportFiles) {
				$relative = Get-RelativePath $file.FullName
				if (-not $manifestFiles.ContainsKey($relative)) {
					Add-Failure "RELEASE_MANIFEST.json is missing file entry: $relative"
					continue
				}
				$entry = $manifestFiles[$relative]
				if ($entry.bytes -ne $file.Length) {
					Add-Failure "RELEASE_MANIFEST.json byte count mismatch for $relative"
				}
				$actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash.ToLowerInvariant()
				if ([string]$entry.sha256 -ne $actualHash) {
					Add-Failure "RELEASE_MANIFEST.json SHA-256 mismatch for $relative"
				}
			}
			$treeHashInput = New-Object System.Text.StringBuilder
			[string[]]$treeEntryPaths = @($manifestFiles.Keys)
			[Array]::Sort($treeEntryPaths, [StringComparer]::Ordinal)
			foreach ($path in $treeEntryPaths) {
				$entry = $manifestFiles[$path]
				[void]$treeHashInput.Append([string]$entry.path)
				[void]$treeHashInput.Append("`t")
				[void]$treeHashInput.Append([string]$entry.bytes)
				[void]$treeHashInput.Append("`t")
				[void]$treeHashInput.Append([string]$entry.sha256)
				[void]$treeHashInput.Append("`n")
			}
			$treeHashBytes = [System.Text.Encoding]::UTF8.GetBytes($treeHashInput.ToString())
			$actualTreeHash = [System.BitConverter]::ToString([System.Security.Cryptography.SHA256]::Create().ComputeHash($treeHashBytes)).Replace('-', '').ToLowerInvariant()
		if ([string]$manifest.export_tree_sha256 -ne $actualTreeHash) {
			Add-Failure 'RELEASE_MANIFEST.json export_tree_sha256 does not match manifest file entries'
		}
	}

		if ($null -eq $manifest.PSObject.Properties['release_boundary']) {
			Add-Failure 'RELEASE_MANIFEST.json is missing release_boundary'
		}
		else {
			$releaseBoundary = $manifest.release_boundary
			if ([string]$releaseBoundary.default_audience -ne 'application_agents_building_apps') {
				Add-Failure 'RELEASE_MANIFEST.json release_boundary.default_audience must be application_agents_building_apps'
			}
			if ([string]$releaseBoundary.intended_use -ne 'runtime_docs_examples_and_release_attestation') {
				Add-Failure 'RELEASE_MANIFEST.json release_boundary.intended_use has an unexpected value'
			}
			if ([string]$releaseBoundary.ordinary_app_entrypoint -ne 'dataphyre_app_builder_plan_generate') {
				Add-Failure 'RELEASE_MANIFEST.json release_boundary.ordinary_app_entrypoint must be dataphyre_app_builder_plan_generate'
			}
			if ([string]$releaseBoundary.ordinary_app_payload_profile -ne 'compact') {
				Add-Failure 'RELEASE_MANIFEST.json release_boundary.ordinary_app_payload_profile must be compact'
			}
			if ([string]$releaseBoundary.ordinary_agent_verification -notmatch 'focused application or module checks') {
				Add-Failure 'RELEASE_MANIFEST.json release_boundary.ordinary_agent_verification must point to focused application or module checks'
			}
			foreach ($extensionPoint in @('config', 'dialbacks', 'callbacks', 'plugins', 'MCP metadata', 'application-owned adapters', 'reusable runtime modules')) {
				if ($releaseBoundary.app_owned_extension_points -notcontains $extensionPoint) {
					Add-Failure "RELEASE_MANIFEST.json release_boundary is missing app-owned extension point: $extensionPoint"
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
				if ($releaseBoundary.app_builder_handoff_fields -notcontains $handoffField) {
					Add-Failure "RELEASE_MANIFEST.json release_boundary is missing app-builder handoff field: $handoffField"
				}
			}
			foreach ($trigger in @(
				'release-facing or public Dataphyre framework claims',
				'corporate-ready or enterprise-readiness claims',
				'security, identity/access, session, credential, governance, tenant isolation, billing, privacy, compliance, data residency, retention, legal-hold, or access-policy work',
				'Dataphyre framework internals or reusable framework contributions',
				'Dataphyre shared production hot-path changes'
			)) {
				if ($releaseBoundary.escalate_only_for -notcontains $trigger) {
					Add-Failure "RELEASE_MANIFEST.json release_boundary is missing escalation trigger: $trigger"
				}
			}
			foreach ($ceremony in @('dataphyre_mcp_verify_all', 'Dataphyre project-wide release validation', 'Dataphyre hot-path benchmarks', 'Dataphyre runtime-internal edits to make one application work')) {
				if ($releaseBoundary.not_ordinary_app_ceremony -notcontains $ceremony) {
					Add-Failure "RELEASE_MANIFEST.json release_boundary is missing non-ordinary app ceremony: $ceremony"
				}
			}
			foreach ($scope in @('Dataphyre framework changes', 'MCP/release-surface claims', 'public release preparation', 'shared production hot-path changes')) {
				if ($releaseBoundary.project_evidence_scope -notcontains $scope) {
					Add-Failure "RELEASE_MANIFEST.json release_boundary is missing project evidence scope: $scope"
				}
			}
		}

		foreach ($requiredCategory in @('local install state', 'generated cache and logs', 'private adapter modules')) {
			if ($manifest.excluded_categories -notcontains $requiredCategory) {
				Add-Failure "RELEASE_MANIFEST.json is missing excluded category: $requiredCategory"
			}
		}
		foreach ($requiredCheck in @('release_manifest_integrity', 'public_file_inventory', 'public_boundary_checks')) {
			if ($manifest.verification -notcontains $requiredCheck) {
				Add-Failure "RELEASE_MANIFEST.json is missing verification entry: $requiredCheck"
			}
		}
		if ([string]$manifest.verification_scope -ne 'release_attestation_not_app_runtime_requirement') {
			Add-Failure 'RELEASE_MANIFEST.json verification_scope must mark verification as release attestation, not an app runtime requirement'
		}

		$moduleRoot = Join-Path $Root 'runtime/modules'
		$modulesFile = Join-Path $Root 'docs/MODULES.md'
		if (-not (Test-Path $moduleRoot -PathType Container)) {
			Add-Failure 'Prepared public export is missing runtime/modules for module inventory validation'
		}
		elseif (-not (Test-Path $modulesFile -PathType Leaf)) {
			Add-Failure 'Prepared public export is missing docs/MODULES.md for module inventory validation'
		}
		else {
			$moduleDirectories = @(Get-ChildItem -Path $moduleRoot -Directory | Select-Object -ExpandProperty Name | Sort-Object)
			$docModules = @{}
			foreach ($line in Get-Content $modulesFile) {
				$match = [regex]::Match($line, '^\| `([^`]+)` \| ([^|]+) \| ([^|]+) \| \[docs\]\(([^)]+)\) \| (.+) \|$')
				if (-not $match.Success) {
					continue
				}
				$name = $match.Groups[1].Value
				$docModules[$name] = [pscustomobject]@{
					name = $name
					status = $match.Groups[2].Value.Trim()
					runtime_critical = $match.Groups[3].Value.Trim().Equals('Yes', [System.StringComparison]::OrdinalIgnoreCase)
					docs = $match.Groups[4].Value.Trim()
					purpose = $match.Groups[5].Value.Trim()
				}
			}
			if ($null -eq $manifest.modules -or $manifest.modules.Count -ne $moduleDirectories.Count) {
				Add-Failure 'RELEASE_MANIFEST.json module inventory count does not match exported module directories'
			}
			else {
				$manifestModules = @{}
				foreach ($module in $manifest.modules) {
					$name = ''
					if ($null -ne $module.PSObject.Properties['name']) {
						$name = [string]$module.name
					}
					if ($name -eq '' -or $name -notmatch '^[a-z0-9_]+$') {
						Add-Failure "RELEASE_MANIFEST.json contains an invalid module name: $name"
						continue
					}
					if ($manifestModules.ContainsKey($name)) {
						Add-Failure "RELEASE_MANIFEST.json contains a duplicate module inventory entry: $name"
						continue
					}
					$manifestModules[$name] = $module
				}
				foreach ($moduleName in $moduleDirectories) {
					if (-not $docModules.ContainsKey($moduleName)) {
						Add-Failure "docs/MODULES.md is missing exported module: $moduleName"
						continue
					}
					if (-not $manifestModules.ContainsKey($moduleName)) {
						Add-Failure "RELEASE_MANIFEST.json is missing module inventory entry: $moduleName"
						continue
					}
					$expected = $docModules[$moduleName]
					$actual = $manifestModules[$moduleName]
					if ([string]$actual.status -ne $expected.status) {
						Add-Failure "RELEASE_MANIFEST.json status mismatch for module $moduleName"
					}
					if ([bool]$actual.runtime_critical -ne $expected.runtime_critical) {
						Add-Failure "RELEASE_MANIFEST.json runtime_critical mismatch for module $moduleName"
					}
					if ([string]$actual.docs -ne $expected.docs) {
						Add-Failure "RELEASE_MANIFEST.json docs target mismatch for module $moduleName"
					}
					if ([string]$actual.purpose -ne $expected.purpose) {
						Add-Failure "RELEASE_MANIFEST.json purpose mismatch for module $moduleName"
					}
				}
			}
		}

		$expectedComponents = @(
			[pscustomobject]@{
				name = 'Stripe PHP'
				path = 'runtime/modules/stripe/src/'
				license = 'MIT'
				license_file = 'runtime/modules/stripe/src/LICENSE'
			},
			[pscustomobject]@{
				name = 'Adminer'
				path = 'runtime/modules/sql/third_party/adminer/'
				license = 'Apache-2.0'
				license_file = 'runtime/modules/sql/third_party/adminer/LICENSE'
			}
		)
		if ($null -eq $manifest.bundled_components -or $manifest.bundled_components.Count -ne $expectedComponents.Count) {
			Add-Failure 'RELEASE_MANIFEST.json bundled component count does not match the public third-party inventory'
		}
		else {
			$noticeText = ''
			$noticePath = Join-Path $Root 'docs/THIRD_PARTY_NOTICES.md'
			if (Test-Path $noticePath -PathType Leaf) {
				$noticeText = Get-Content -Raw $noticePath
			}
			$actualComponents = @{}
			foreach ($component in $manifest.bundled_components) {
				$name = [string]$component.name
				if ($name -eq '') {
					Add-Failure 'RELEASE_MANIFEST.json contains a bundled component without a name'
					continue
				}
				if ($actualComponents.ContainsKey($name)) {
					Add-Failure "RELEASE_MANIFEST.json contains a duplicate bundled component: $name"
					continue
				}
				$actualComponents[$name] = $component
			}
			foreach ($expectedComponent in $expectedComponents) {
				if (-not $actualComponents.ContainsKey($expectedComponent.name)) {
					Add-Failure "RELEASE_MANIFEST.json is missing bundled component: $($expectedComponent.name)"
					continue
				}
				$actualComponent = $actualComponents[$expectedComponent.name]
				foreach ($property in @('path', 'license', 'license_file')) {
					if ([string]$actualComponent.$property -ne [string]$expectedComponent.$property) {
						Add-Failure "RELEASE_MANIFEST.json bundled component $($expectedComponent.name) has unexpected ${property}"
					}
				}
				$componentPath = Join-Path $Root ($expectedComponent.path.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
				if (-not (Test-Path $componentPath)) {
					Add-Failure "Bundled component path from manifest is missing: $($expectedComponent.path)"
				}
				$licensePath = Join-Path $Root ($expectedComponent.license_file.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
				if (-not (Test-Path $licensePath -PathType Leaf)) {
					Add-Failure "Bundled component license file from manifest is missing: $($expectedComponent.license_file)"
				}
				if ($noticeText -notmatch [regex]::Escape($expectedComponent.name) -or
					$noticeText -notmatch [regex]::Escape($expectedComponent.license_file)) {
					Add-Failure "docs/THIRD_PARTY_NOTICES.md does not mention bundled component manifest entry: $($expectedComponent.name)"
				}
			}
		}
	}
	catch {
		Add-Failure "RELEASE_MANIFEST.json is not valid JSON: $($_.Exception.Message)"
	}
}

if ($failures.Count -gt 0) {
	Write-Host ''
	Write-Host "Release manifest verification failed with $($failures.Count) issue(s)."
	exit 1
}

Write-Host 'Release manifest verification passed.'
