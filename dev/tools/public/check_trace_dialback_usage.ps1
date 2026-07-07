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
	$Root = (Resolve-Path (Join-Path $scriptDirectory '..\..\..')).Path
}
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

function Get-SourceFiles {
	Get-ChildItem -Path $script:Root -Recurse -File -Include '*.php', '*.md', '*.json' | Where-Object {
		$relative = Get-RelativePath $_.FullName
		-not (
			$relative.StartsWith('.git/', [System.StringComparison]::OrdinalIgnoreCase) -or
			$relative.StartsWith('cache/', [System.StringComparison]::OrdinalIgnoreCase) -or
			$relative.StartsWith('vendor/', [System.StringComparison]::OrdinalIgnoreCase) -or
			$relative.StartsWith('runtime/modules/stripe/src/', [System.StringComparison]::OrdinalIgnoreCase) -or
			$relative.StartsWith('runtime/modules/sql/third_party/', [System.StringComparison]::OrdinalIgnoreCase)
		)
	}
}

Write-Host "Checking Dataphyre tracelog and dialback usage at $Root"

$files = @(Get-SourceFiles)
$extensionPointsPath = Join-Path $Root 'docs/EXTENSION_POINTS.md'
$extensionPointsText = if (Test-Path $extensionPointsPath) {
	Get-Content -Raw $extensionPointsPath
}
else {
	''
}
$documentedDialbackFamilies = New-Object System.Collections.Generic.List[string]
foreach ($familyMatch in [regex]::Matches($extensionPointsText, 'CALL_[A-Z0-9_]+\*')) {
	$documentedDialbackFamilies.Add($familyMatch.Value.TrimEnd('*')) | Out-Null
}
$privateDialbackDocumentationPrefixes = @(
)
$frameworkKernelDialbackBridgePatterns = @(
	@{ Path = '^runtime/modules/access/Framework/Bootstrap\.php$'; Event = '^CALL_ACCESS_(?:LOGGED_IN|USERID|VALIDATE_SESSION|RECOVER_SESSION|DISABLE_SESSION)_AUTH_TYPE$' },
	@{ Path = '^runtime/modules/permission/Framework/Bootstrap\.php$'; Event = '^CALL_PERMISSION_SUBJECT_(?:ID|PERMISSIONS|ROLES)$' },
	@{ Path = '^runtime/modules/permission/Framework/SubjectResolver\.php$'; Event = '^CALL_PERMISSION_RESOLVE_SUBJECT_(?:PERMISSIONS|ROLES)$' },
	@{ Path = '^runtime/modules/core/Framework/(?:Dialback|DialbackEvent)\.php$'; Event = '.*' }
)

$mixedCaseDialbackNamePattern = 'CALL_[A-Za-z0-9_]*[a-z][A-Za-z0-9_]*'
$mixedCaseDataphyreNamePattern = 'DATAPHYRE_[A-Za-z0-9_]*[a-z][A-Za-z0-9_]*'
if ('CALL_DATAPHYRE_Vestra_EXAMPLE' -notmatch $mixedCaseDialbackNamePattern) {
	Add-Failure 'Internal checker error: mixed-case CALL_* fixture was not detected.'
}
if ('DATAPHYRE_Vestra_EXAMPLE' -notmatch $mixedCaseDataphyreNamePattern) {
	Add-Failure 'Internal checker error: mixed-case DATAPHYRE_* fixture was not detected.'
}

foreach ($file in $files) {
	$relative = Get-RelativePath $file.FullName
	$text = Get-Content -Raw $file.FullName

	if (
		$relative -match '^runtime/modules/[^/]+/Framework/' -and
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$relative -notmatch '/unit_tests/' -and
		$relative -match '^runtime/modules/(?:panel/Framework/(?:Forms/Field|Tables/Column|Rendering/|Schemas/(?:Schema|SchemaComponent)|Support/Panel(?:ActionState|LifecycleResult))|sql/Framework/(?:Record|RepositoryQuery|TableQuery|QuerySpec|Relation|PageResult|Mutation(?:Batch)?Result|TransactionResult)|core/Framework/(?:Config|ConfigRepository|DialbackCatalog|DialbackEvent)|permission/Framework/(?:SubjectResolver|PermissionRule|PermissionSet|PermissionNamer)|api/Framework/(?:ApiCallableBinding|Endpoint|ApiGroup)|reactor/Framework/Components/ReactorComponent)\.php$' -and
		$text -match 'tracelog\s*\('
	) {
		Add-Failure "Framework hot/noisy paths should not add tracelog calls without a benchmarked exception: $relative"
	}

	if ($relative -match '^runtime/modules/tracelog/documentation/Dataphyre_Tracelog\.md$') {
		foreach ($staleTerm in @('setPlotting', 'getPlotting', '$profiling', 'tracelog::tracelog')) {
			if ($text.Contains($staleTerm)) {
				Add-Failure "Tracelog docs still teach stale usage '$staleTerm': $relative"
			}
		}
	}

	if (
		$relative -notmatch '^runtime/modules/tracelog/kernel/tracelog\.main\.php$' -and
		$relative -notmatch '^runtime/bootstrap\.php$' -and
		$text -match '(?<!function\s)(?:\\?dataphyre\\)?tracelog::tracelog\s*\('
	) {
		Add-Failure "Use global tracelog() outside the Tracelog backend instead of direct backend dispatch: $relative"
	}

	if ($text -match '\$S\s*=\s*null\s*,\s*\$T\s*=\s*[''"]function_call(?:_with_test)?[''"]') {
		Add-Failure "Tracelog function-call markers should use `$T=null, `$S='function_call...' for readable canonical argument names: $relative"
	}

	if (
		$relative -notmatch '^runtime/modules/core/unit_tests/' -and
		$text -match '(?:register_dialback|dialback)\s*\(\s*["'']my_[^"'']+["'']'
	) {
		Add-Failure "Dialback examples should not teach my_* event names; use CALL_<MODULE>_<ACTION>: $relative"
	}

	if (
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$text -match '(?s)#\[\s*\\SensitiveParameter\s*\].{0,1500}?tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Tracelog calls in functions with #[SensitiveParameter] should pass an explicit redacted argument list instead of func_get_args(): $relative"
	}

	if (
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$text -match '(?s)#\[\s*\\SensitiveParameter\s*\].{0,1500}?tracelog\s*\([^\r\n]*[''"]function_call_with_test[''"]'
	) {
		Add-Failure "Tracelog calls in functions with #[SensitiveParameter] should use function_call, not function_call_with_test: $relative"
	}

	if (
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$relative -notmatch '/unit_tests/'
	) {
		$lines = Get-Content $file.FullName
		for ($lineIndex = 0; $lineIndex -lt $lines.Count; $lineIndex++) {
			$lineText = $lines[$lineIndex]
			if ($lineText -notmatch 'tracelog\s*\(' -or $lineText -notmatch 'function_call') {
				continue
			}
			$signature = ''
			for ($scanIndex = $lineIndex; $scanIndex -ge 0 -and $scanIndex -ge ($lineIndex - 100); $scanIndex--) {
				if ($lines[$scanIndex] -match 'function\s+[A-Za-z0-9_]+\s*\(') {
					for ($signatureIndex = $scanIndex; $signatureIndex -lt $lines.Count; $signatureIndex++) {
						$signature += $lines[$signatureIndex].Trim() + ' '
						if ($lines[$signatureIndex] -match '\)\s*[:{]') {
							break
						}
					}
					break
				}
			}
			$docContext = ''
			for ($docIndex = [Math]::Max(0, $lineIndex - 20); $docIndex -le $lineIndex; $docIndex++) {
				$docContext += $lines[$docIndex].Trim() + ' '
			}
			if ($signature -notmatch '(?i)callable|Closure' -and $docContext -notmatch '(?i)@param\s+array<[^>]*callable') {
				continue
			}
			if ($lineText -match 'function_call_with_test' -or $lineText -match '\$A\s*=\s*func_get_args\s*\(\s*\)') {
				Add-Failure "Tracelog calls in callable signatures should use function_call with no raw argument payload: ${relative}:$($lineIndex + 1)"
			}
		}
	}

	if (
		$relative -match '^runtime/modules/fulltext_engine/external_engines/' -and
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$text -match 'tracelog\s*\([^\r\n]*json_encode\s*\(\s*\$document\s*\)'
	) {
		Add-Failure "Fulltext indexing traces should log metadata, not full document payloads: $relative"
	}

	if (
		$relative -match '^runtime/modules/fulltext_engine/external_engines/' -and
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$text -match 'tracelog\s*\([^\r\n]*(?:Response:\s*| \| )\$response'
	) {
		Add-Failure "Fulltext external-engine traces should log response metadata, not raw response bodies: $relative"
	}

	if (
		$relative -match '^runtime/modules/fulltext_engine/(?:kernel/fulltext_engine\.main|external_engines/[^/]+)\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Fulltext traces should not log raw search, index, or external-engine payloads: $relative"
	}

	if (
		$relative -match '^runtime/modules/(?:access|firewall|supercookie)/kernel/[^/]+\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Access, firewall, and supercookie traces should not log raw session, identity, request, or cookie payloads: $relative"
	}

	if (
		$relative -match '^runtime/modules/(?:localization|geoposition|currency)/kernel/[^/]+\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Localization, geoposition, and currency traces should not log raw locale, path, location, or money payloads: $relative"
	}

	if (
		$relative -match '^runtime/modules/(?:issue|routing|caspow|scheduling|time_machine)/kernel/[^/]+\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Operational/security traces should not log raw issue, route, proof, scheduler, or rollback payloads: $relative"
	}

	if (
		$relative -match '^runtime/modules/localization/kernel/localization\.main\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*(?:Reading locale file\s+\$path|Locale file at\s+\$path)'
	) {
		Add-Failure "Localization traces should not include raw locale file paths: $relative"
	}

	if (
		$relative -match '^runtime/modules/access/kernel/access\.main\.php$' -and
		$text -match 'Invalid DPID format:\s*\$dpid'
	) {
		Add-Failure "Access traces should not include raw DPID values: $relative"
	}

	if (
		$relative -match '^runtime/modules/firewall/kernel/firewall\.main\.php$' -and
		$text -match 'Captcha block removed for IP\s*\$ipaddress'
	) {
		Add-Failure "Firewall traces should not include raw visitor IP addresses: $relative"
	}

	if (
		$relative -match '^runtime/modules/vestra/kernel/vestra\.main\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*(?:invalid JSON:\s*''\s*\.\s*\$result|json_encode\s*\(\s*\$decoded_result\s*\))'
	) {
		Add-Failure "Vestra traces should log response metadata, not raw response bodies: $relative"
	}

	if (
		$relative -match '^runtime/modules/sql/kernel/(?:sql\.main|mysql_query|postgresql_query|sqlite_query)\.php$' -and
		$text -match 'log_query_error\s*\([^\r\n]*json_encode\s*\(\s*func_get_args\s*\(\s*\)\s*\)'
	) {
		Add-Failure "SQL error logging should use operation metadata, not json_encode(func_get_args()): $relative"
	}

	if (
		$relative -match '^runtime/modules/sql/kernel/sql\.main\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*\$T\s*=\s*\$error'
	) {
		Add-Failure "SQL log_query_error should not trace rendered query/variable error HTML: $relative"
	}

	if (
		$relative -match '^runtime/modules/sql/kernel/sql\.main\.php$' -and
		$text -match '\$resolved\[\$key\]\s*=\s*\$value\s*;'
	) {
		Add-Failure "SQL observer bound-variable traces should expose metadata, not raw values: $relative"
	}

	if (
		$relative -match '^runtime/modules/sql/kernel/(?:sql\.main|mysql_query|postgresql_query|sqlite_query)\.php$' -and
		$relative -notmatch '/unit_tests/'
	) {
		$sqlSensitiveTraceFunctions = @(
			'log_query_error',
			'query_has_write',
			'cache_query_result',
			'invalidate_cache',
			'execute_prepared_statements',
			'execute_multi_query_string',
			'process_results',
			'mysql_compatibility_layer',
			'mysql_query',
			'mysql_select',
			'mysql_count',
			'mysql_update',
			'mysql_insert',
			'mysql_delete',
			'postgresql_query',
			'postgresql_select',
			'postgresql_count',
			'postgresql_update',
			'postgresql_insert',
			'postgresql_delete',
			'sqlite_query',
			'sqlite_select',
			'sqlite_count',
			'sqlite_update',
			'sqlite_insert',
			'sqlite_delete'
		)
		$lines = Get-Content $file.FullName
		$currentFunction = ''
		for ($lineIndex = 0; $lineIndex -lt $lines.Count; $lineIndex++) {
			$lineText = $lines[$lineIndex]
			if ($lineText -match 'function\s+([A-Za-z0-9_]+)\s*\(') {
				$currentFunction = $Matches[1]
			}
			if (
				$sqlSensitiveTraceFunctions -contains $currentFunction -and
				$lineText -match 'tracelog\s*\(' -and
				$lineText -match '\$A\s*=\s*func_get_args\s*\(\s*\)'
			) {
				Add-Failure "SQL traces in $currentFunction should use metadata/no raw argument payload: ${relative}:$($lineIndex + 1)"
			}
		}
	}

	if (
		$relative -match '^runtime/modules/(?:async|cache|stripe)/kernel/[^/]+\.php$' -and
		$text -match 'tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Async, cache, and Stripe traces should not log raw HTTP/cache/payment argument payloads: $relative"
	}

	if (
		$relative -match '^runtime/modules/(?:core|sanitation|vestra|datadoc)/kernel/[^/]+\.php$' -and
		$text -match '(?s)function\s+(?:end_client_connection|set_http_headers|add_config|get_config|unavailable|url_updated_querystring|url_self_updated_querystring|get_client_ip_details|anonymize_email|sanitize|sanitize_many|ingest_resources|propagate|update_use_count|create_project|reference_functions|add_files_to_project|discover_files_to_project|add_file_to_project|register_file_to_project|delete_file|get_stale_files|sync_all_files|sync_project_batch|sync_file|change_filepath)\s*\(.{0,800}?tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Core response, sanitation, Vestra, and Datadoc traces should not log raw content/path/value payloads: $relative"
	}

	if (
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$relative -notmatch '/unit_tests/' -and
		$relative -notmatch '/third_party/' -and
		$relative.StartsWith('runtime/', [System.StringComparison]::OrdinalIgnoreCase) -and
		$text -match 'tracelog\s*\([^\r\n]*\$A\s*=\s*func_get_args\s*\(\s*\)'
	) {
		Add-Failure "Runtime tracelog calls should not pass raw func_get_args() payloads: $relative"
	}

	if (
		$relative.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase) -and
		$relative -notmatch '/unit_tests/' -and
		$text -match '(?:dialback|register_dialback)\s*\(\s*["'']CALL_[A-Z0-9_]+_["'']\s*\.'
	) {
		$dynamicMatches = [regex]::Matches($text, '(?:dialback|register_dialback)\s*\(\s*["''](CALL_[A-Z0-9_]+_)["'']\s*\.')
		foreach ($dynamicMatch in $dynamicMatches) {
			$prefix = $dynamicMatch.Groups[1].Value
			if ($extensionPointsText -notmatch [regex]::Escape($prefix)) {
				Add-Failure "Dynamic dialback prefix must be documented in docs/EXTENSION_POINTS.md: $prefix in $relative"
			}
		}
	}

	$matches = [regex]::Matches($text, $mixedCaseDialbackNamePattern)
	foreach ($match in $matches) {
		$value = $match.Value
		Add-Failure "Dialback names should be all-caps module-scoped contracts: $value in $relative"
	}

	$dataphyreNameMatches = [regex]::Matches($text, $mixedCaseDataphyreNamePattern)
	foreach ($match in $dataphyreNameMatches) {
		$value = $match.Value
		Add-Failure "Dataphyre runtime string/constant names should be all-caps: $value in $relative"
	}

	$dialbackStringMatches = [regex]::Matches($text, '(?:dialback|register_dialback)\s*\(\s*["'']([^"'']+)["'']')
	foreach ($match in $dialbackStringMatches) {
		$value = $match.Groups[1].Value
		if ($value -match '^unit_') {
			continue
		}
		if ($value -match '^CALL_[A-Z0-9]+_$') {
			continue
		}
		if ($value -notmatch '^CALL_[A-Z0-9]+_[A-Z0-9_]+$') {
			Add-Failure "Dialback event strings should use CALL_<MODULE>_<ACTION>: $value in $relative"
		}
		if (
			$value -match '^CALL_[A-Z0-9]+_[A-Z0-9_]+$' -and
			$relative -match '^runtime/modules/[^/]+/Framework/' -and
			$relative -notmatch '/unit_tests/'
		) {
			$isFrameworkBridge = $false
			foreach ($bridge in $frameworkKernelDialbackBridgePatterns) {
				if (
					$relative -match [string]$bridge['Path'] -and
					$value -match [string]$bridge['Event']
				) {
					$isFrameworkBridge = $true
					break
				}
			}
			if (-not $isFrameworkBridge -and $value -notmatch '^CALL_[A-Z0-9]+_FRAMEWORK_[A-Z0-9_]+$') {
				Add-Failure "Framework-owned dialbacks should use CALL_<MODULE>_FRAMEWORK_<ACTION> unless bridging an existing kernel hook: $value in $relative"
			}
		}
		if (
			$value -match '^CALL_[A-Z0-9]+_[A-Z0-9_]+$' -and
			$relative -notmatch '/documentation/' -and
			$relative -notmatch '^docs/' -and
			$relative -notmatch '/unit_tests/'
		) {
			$privateDocumentationOnly = $false
			foreach ($privatePrefix in $privateDialbackDocumentationPrefixes) {
				if ($relative.StartsWith($privatePrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
					$privateDocumentationOnly = $true
					break
				}
			}
			if ($privateDocumentationOnly) {
				continue
			}
			$covered = $false
			foreach ($familyPrefix in $documentedDialbackFamilies) {
				if ($value.StartsWith($familyPrefix, [System.StringComparison]::Ordinal)) {
					$covered = $true
					break
				}
			}
			if ($covered -eq $false) {
				Add-Failure "Runtime dialback family should be documented in docs/EXTENSION_POINTS.md: $value in $relative"
			}
		}
	}
}

if ($failures.Count -gt 0) {
	Write-Host ''
	Write-Host "Trace/dialback usage check failed with $($failures.Count) issue(s)."
	exit 1
}

Write-Host 'Trace/dialback usage checks passed.'
