<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Diagnostic, Tracelog, and redacted artifact inspection surfaces for Dataphyre MCP.
 */
trait dataphyre_mcp_inspection_diagnostics_surfaces {

	/**
	 * Describes the reusable safety contract for diagnostic payloads.
	 *
	 * @param string $surface Diagnostic surface label.
	 * @return array<string,mixed> Diagnostic safety metadata.
	 */
	private function diagnostic_safety_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'classification'=>'diagnostic_metadata_or_redacted_preview',
			'sharing_default'=>'share_summary_not_raw_payload',
			'redacts'=>[
				'secrets',
				'tokens',
				'passwords',
				'auth headers',
				'cookies',
				'private keys',
				'connection strings',
				'signed URLs',
				'tenant names',
				'product identifiers',
				'machine-local paths',
			],
			'denied_sharing'=>[
				'raw unredacted logs',
				'auth headers, cookies, tokens, or signed query strings',
				'tenant-identifying or product-identifying snippets',
				'machine-local absolute paths unless the user explicitly needs local debugging context',
				'unbounded trace buffers or full HTML pages',
			],
			'ordinary_app_summary'=>[
				'owner'=>'consuming_application',
				'verification'=>'focused application or module checks',
				'default_posture'=>'read_only_metadata_first',
			],
			'boundary_refs'=>[
				'application_agent_operating_contract'=>'dataphyre_mcp_readiness_report',
				'ordinary_app_work'=>'dataphyre_mcp_readiness_report',
				'tool_audience_boundaries'=>'dataphyre_mcp_readiness_report',
			],
			'agent_rule'=>'Use bounded snippets for application debugging; run governance review only before corporate-ready, security-sensitive, tenant/privacy/compliance, or release-facing claims.',
		];
	}

	/**
	 * Builds a safe, compact diagnostics handoff contract for agents.
	 *
	 * @param string $surface Diagnostic surface label.
	 * @param array<int,string> $evidence_keys Payload keys that can be summarized safely.
	 * @return array<string,mixed> Summary-first handoff guidance.
	 */
	private function diagnostic_handoff_contract(string $surface, array $evidence_keys): array {
		return [
			'surface'=>$surface,
			'share_default'=>'summary_first',
			'owner'=>'consuming_application',
			'evidence_keys'=>array_values($evidence_keys),
			'safe_summary_shape'=>[
				'scope'=>'repo-relative scope only',
				'finding'=>'short redacted symptom or absence of matches',
				'evidence'=>'artifact path, kind, line, severity, counts, and truncated flag when present',
				'next_reads'=>'bounded MCP tools to inspect next without executing app code',
			],
			'handoff_template'=>[
				'status'=>'copy_safe_summary_ready',
				'fields'=>['surface', 'scope', 'finding', 'evidence', 'next_reads', 'not_included'],
				'use'=>'Copy diagnostic_summary.copy_safe_evidence into app-owned issue notes or handoffs; do not copy raw log payloads.',
			],
			'next_reads'=>[
				'dataphyre_tracelog_artifacts_list',
				'dataphyre_tracelog_search',
				'dataphyre_diagnostics_last_error',
				'dataphyre_verification_surface_catalog',
			],
			'do_not_share'=>[
				'raw full logs',
				'unredacted snippets',
				'auth headers, cookies, bearer tokens, signed URLs, or connection strings',
				'tenant names, product identifiers, local usernames, or machine-local absolute paths',
			],
			'escalate_only_for'=>[
				'security, credential, tenant, privacy, compliance, or billing-sensitive diagnostics',
				'corporate-ready, public, release-facing, or Dataphyre framework claims',
				'requests to execute browser, route, SQL, or external-service diagnostics',
			],
		];
	}

	/**
	 * Reduces a redacted diagnostic summary to one safe next action.
	 *
	 * @param string $surface Diagnostic surface label.
	 * @param array<string,mixed> $payload Summary payload with redacted evidence.
	 * @return array<string,mixed> Machine-readable diagnostic next action.
	 */
	private function diagnostic_next_action(string $surface, array $payload): array {
		$evidence=is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
		$error_count=(int)($evidence['error_count'] ?? 0);
		$match_count=(int)($evidence['match_count'] ?? 0);
		$artifact_count=(int)($evidence['artifact_count'] ?? 0);
		$has_error=$error_count>0 || trim((string)($evidence['latest_severity'] ?? ''))!=='';
		$has_match=$match_count>0;
		$has_artifact=$artifact_count>0 || trim((string)($evidence['path'] ?? $evidence['latest_path'] ?? $evidence['first_match_path'] ?? ''))!=='';
		if($has_error){
			$status='triage_redacted_error';
			$next_tool='dataphyre_verification_surface_catalog';
			$action='Use diagnostic_summary.copy_safe_evidence to identify the failing app/module surface, then run focused app/module verification for concrete paths.';
		}elseif($has_match){
			$status='inspect_redacted_matches';
			$next_tool='dataphyre_diagnostics_last_error';
			$action='Use redacted match snippets to narrow the symptom, then check recent error-looking snippets before app-owned fixes.';
		}elseif($has_artifact){
			$status='inspect_redacted_artifact';
			$next_tool=$surface==='tracelog_read' ? 'dataphyre_tracelog_search' : 'dataphyre_diagnostics_last_error';
			$action='Inspect bounded redacted diagnostics further before changing app-owned files.';
		}else{
			$status='broaden_bounded_diagnostic_search';
			$next_tool='dataphyre_tracelog_artifacts_list';
			$action='Broaden the repo-local artifact scope or search query before concluding no diagnostic evidence exists.';
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$status,
			'next_tool'=>$next_tool,
			'action'=>$action,
			'next_arguments_hint'=>'Use repo-relative scopes, concrete app/module paths, or focused search terms; do not paste raw logs.',
			'handoff_fields'=>['diagnostic_summary.copy_safe_evidence', 'diagnostic_next_action', 'verification_handoff when focused checks run'],
			'not_required'=>[
				'raw log sharing',
				'MCP/release-surface validation for ordinary app diagnostics',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Builds a concrete summary object that agents can hand off instead of raw diagnostics.
	 *
	 * @param array<string,mixed> $summary Summary fields from an already bounded/redacted diagnostic payload.
	 * @return array<string,mixed> Copy-safe diagnostic summary.
	 */
	private function diagnostic_summary(string $surface, array $summary): array {
		$payload=array_merge([
			'surface'=>$surface,
			'share_default'=>'summary_first',
			'owner'=>'consuming_application',
			'redaction'=>'already redacted by MCP before summary construction',
			'finding'=>'No diagnostic finding was provided.',
			'evidence'=>[],
			'next_reads'=>[
				'dataphyre_tracelog_artifacts_list',
				'dataphyre_tracelog_search',
				'dataphyre_diagnostics_last_error',
			],
		], $summary);
		$payload['diagnostic_next_action']=$this->diagnostic_next_action($surface, $payload);
		$payload['copy_safe_evidence']=[
			'surface'=>$payload['surface'],
			'owner'=>$payload['owner'],
			'share_default'=>$payload['share_default'],
			'internal_share_default'=>'app_team_summary_ok',
			'external_share_default'=>'remove_or_generalize_identifiers_first',
			'safe_to_paste_externally'=>false,
			'handoff_status'=>'copy_safe_summary_ready',
			'redaction'=>$payload['redaction'],
			'finding'=>$payload['finding'],
			'evidence'=>$payload['evidence'],
			'next_reads'=>$payload['next_reads'],
			'diagnostic_next_action'=>$payload['diagnostic_next_action'],
			'external_share_review'=>[
				'required'=>true,
				'reason'=>'Repo-relative paths, scopes, queries, and artifact names can still reveal app, tenant, product, customer, or local install context.',
				'action'=>'Before sharing outside the owning application team, remove or generalize path, scope, query, artifact, tenant, product, customer, and local install identifiers.',
				'not_a_governance_gate'=>'Ordinary app debugging can use this summary internally without opening enterprise or release validation.',
			],
			'copy_fields'=>['surface', 'owner', 'share_default', 'internal_share_default', 'external_share_default', 'safe_to_paste_externally', 'handoff_status', 'finding', 'evidence', 'next_reads', 'diagnostic_next_action', 'external_share_review', 'not_included'],
			'not_included'=>[
				'raw full logs',
				'unredacted snippets',
				'secrets, tokens, cookies, auth headers, signed URLs, or connection strings',
				'tenant names, product identifiers, local usernames, or machine-local absolute paths',
			],
			'use'=>'Paste this object or its fields into app-owned diagnostic handoffs; keep raw diagnostics local and bounded.',
		];
		return $payload;
	}

	/**
	 * Lists repo-local Tracelog and log artifacts under a bounded scope.
	 *
	 * enumerates artifact paths, kinds, sizes, and modification times only. File contents are not read
	 * here, and scope resolution stays inside the repository.
	 *
	 * @param array{scope?: string, limit?: int} $args Artifact listing options.
	 * @return array{scope: string, artifacts: array<int, array>} Artifact inventory.
	 *
	 * @throws InvalidArgumentException When scope is not a repo-local directory.
	 */
	private function list_tracelog_artifacts(array $args): array {
		$scope=trim((string)($args['scope'] ?? ''));
		$base=$scope!=='' ? $this->safe_repo_path($scope) : $this->common_root.'/dataphyre';
		if(!is_dir($base)){
			throw new InvalidArgumentException('scope must point to a repo-local directory.');
		}
		$limit=max(1, min((int)($args['limit'] ?? 30) ?: 30, 100));
		$artifacts=[];
		foreach($this->all_files($base, 12000) as $path){
			$relative=$this->relative_path($path);
			if(!$this->is_tracelog_artifact($relative)){
				continue;
			}
			$artifacts[]=[
				'path'=>$relative,
				'size_bytes'=>(int)@filesize($path),
				'modified_at'=>$this->file_modified_iso($path),
				'kind'=>$this->tracelog_artifact_kind($relative),
			];
			if(count($artifacts)>=$limit){
				break;
			}
		}
		usort($artifacts, static fn(array $a, array $b): int => strcmp((string)($b['modified_at'] ?? ''), (string)($a['modified_at'] ?? '')));
		return [
			'scope'=>$this->relative_path($base),
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'diagnostic_safety'=>$this->diagnostic_safety_contract('tracelog_artifact_list'),
			'diagnostic_handoff'=>$this->diagnostic_handoff_contract('tracelog_artifact_list', ['scope', 'artifacts.path', 'artifacts.kind', 'artifacts.size_bytes', 'artifacts.modified_at']),
			'diagnostic_summary'=>$this->diagnostic_summary('tracelog_artifact_list', [
				'finding'=>$artifacts===[] ? 'No Tracelog or log artifacts were found in the requested scope.' : count($artifacts).' Tracelog/log artifact(s) found in the requested scope.',
				'evidence'=>[
					'scope'=>$this->relative_path($base),
					'artifact_count'=>count($artifacts),
					'latest_path'=>(string)($artifacts[0]['path'] ?? ''),
					'latest_kind'=>(string)($artifacts[0]['kind'] ?? ''),
					'latest_modified_at'=>(string)($artifacts[0]['modified_at'] ?? ''),
				],
			]),
			'artifacts'=>$artifacts,
		];
	}

	/**
	 * Reads a bounded, redacted Tracelog or log artifact.
	 *
	 * accepts only repo-local artifacts recognized by the Tracelog classifier, caps bytes read, can
	 * strip HTML, and redacts sensitive text before returning the payload.
	 *
	 * @param array{path?: string, max_bytes?: int, strip_html?: bool} $args Artifact read options.
	 * @return array{path: string, kind: string, size_bytes: int, modified_at: mixed, truncated: bool, max_bytes: int, text: string} Redacted artifact content.
	 *
	 * @throws InvalidArgumentException When the path is not an allowed artifact.
	 */
	private function read_tracelog_artifact(array $args): array {
		$path=$this->safe_repo_path((string)($args['path'] ?? ''));
		$relative=$this->relative_path($path);
		if(!is_file($path) || !$this->is_tracelog_artifact($relative)){
			throw new InvalidArgumentException('path must point to a repo-local Tracelog or log artifact.');
		}
		$max_bytes=max(1000, min((int)($args['max_bytes'] ?? 20000) ?: 20000, 120000));
		$text=(string)file_get_contents($path, false, null, 0, $max_bytes);
		if((bool)($args['strip_html'] ?? true)){
			$text=html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		$text=$this->redact_sensitive_text($text);
		$truncated=(int)@filesize($path)>$max_bytes;
		return [
			'path'=>$relative,
			'kind'=>$this->tracelog_artifact_kind($relative),
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'diagnostic_safety'=>$this->diagnostic_safety_contract('tracelog_read'),
			'diagnostic_handoff'=>$this->diagnostic_handoff_contract('tracelog_read', ['path', 'kind', 'modified_at', 'truncated', 'max_bytes', 'text_summary']),
			'size_bytes'=>(int)@filesize($path),
			'modified_at'=>$this->file_modified_iso($path),
			'truncated'=>$truncated,
			'max_bytes'=>$max_bytes,
			'diagnostic_summary'=>$this->diagnostic_summary('tracelog_read', [
				'finding'=>'Read a bounded redacted diagnostic preview from '.$relative.'.',
				'evidence'=>[
					'path'=>$relative,
					'kind'=>$this->tracelog_artifact_kind($relative),
					'modified_at'=>$this->file_modified_iso($path),
					'truncated'=>$truncated,
					'preview_chars'=>strlen($text),
				],
			]),
			'text_policy'=>[
				'classification'=>'internal_triage_only',
				'default_handoff'=>'diagnostic_summary.copy_safe_evidence',
				'external_share'=>'Do not copy text outside app-local debugging; summarize through diagnostic_summary.copy_safe_evidence and external_share_review.',
			],
			'text'=>$text,
		];
	}

	/**
	 * Searches bounded, redacted Tracelog artifacts for a caller-provided query.
	 *
	 * scans only recognized repo-local artifacts, limits files and matches, strips HTML by default,
	 * redacts sensitive text before matching snippets, and never invokes diagnostics or external services.
	 *
	 * @param array{query?: string, scope?: string, limit?: int, max_bytes_per_file?: int, strip_html?: bool} $args Search options.
	 * @return array{query: string, scope: string, write_policy: string, execution: string, scanned_artifacts: int, match_count: int, max_bytes_per_file: int, matches: array} Search report.
	 *
	 * @throws InvalidArgumentException When query is missing or scope is invalid.
	 */
	private function search_tracelog_artifacts(array $args): array {
		$query=trim((string)($args['query'] ?? ''));
		if($query===''){
			throw new InvalidArgumentException('query is required.');
		}
		$scope=trim((string)($args['scope'] ?? ''));
		$base=$scope!=='' ? $this->safe_repo_path($scope) : $this->common_root.'/dataphyre';
		if(!is_dir($base)){
			throw new InvalidArgumentException('scope must point to a repo-local directory.');
		}
		$limit=max(1, min((int)($args['limit'] ?? 12) ?: 12, 100));
		$max_bytes=max(1000, min((int)($args['max_bytes_per_file'] ?? 50000) ?: 50000, 200000));
		$strip_html=!array_key_exists('strip_html', $args) || (bool)$args['strip_html'];
		$matches=[];
		$scanned=0;
		foreach($this->all_files($base, 12000) as $path){
			$relative=$this->relative_path($path);
			if(!$this->is_tracelog_artifact($relative)){
				continue;
			}
			$scanned++;
			$text=(string)file_get_contents($path, false, null, 0, $max_bytes);
			if($strip_html){
				$text=html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
			$text=$this->redact_sensitive_text($text);
			$offset=0;
			while(($pos=stripos($text, $query, $offset))!==false){
				$matches[]=[
					'path'=>$relative,
					'kind'=>$this->tracelog_artifact_kind($relative),
					'line'=>$this->line_number_for_offset($text, $pos),
					'snippet'=>$this->snippet_around($text, $pos, strlen($query), 420),
					'truncated'=>(int)@filesize($path)>$max_bytes,
				];
				if(count($matches)>=$limit){
					break 2;
				}
				$offset=$pos+max(1, strlen($query));
			}
		}
		return [
			'query'=>$query,
			'scope'=>$this->relative_path($base),
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'diagnostic_safety'=>$this->diagnostic_safety_contract('tracelog_search'),
			'diagnostic_handoff'=>$this->diagnostic_handoff_contract('tracelog_search', ['query', 'scope', 'scanned_artifacts', 'match_count', 'matches.path', 'matches.line', 'matches.snippet']),
			'diagnostic_summary'=>$this->diagnostic_summary('tracelog_search', [
				'finding'=>$matches===[] ? 'No redacted diagnostic matches found for the query.' : count($matches).' redacted diagnostic match(es) found for the query.',
				'evidence'=>[
					'query'=>$query,
					'scope'=>$this->relative_path($base),
					'scanned_artifacts'=>$scanned,
					'match_count'=>count($matches),
					'first_match_path'=>(string)($matches[0]['path'] ?? ''),
					'first_match_line'=>(int)($matches[0]['line'] ?? 0),
				],
			]),
			'scanned_artifacts'=>$scanned,
			'match_count'=>count($matches),
			'max_bytes_per_file'=>$max_bytes,
			'matches_policy'=>[
				'classification'=>'internal_triage_only',
				'default_handoff'=>'diagnostic_summary.copy_safe_evidence',
				'external_share'=>'Do not copy matches[].snippet outside app-local debugging; summarize through diagnostic_summary.copy_safe_evidence and external_share_review.',
			],
			'matches'=>$matches,
		];
	}

	/**
	 * Finds recent error-like diagnostics across the newest Tracelog artifacts.
	 *
	 * sorts artifacts by modification time, reads bounded redacted text, and classifies line-level
	 * indicators using local patterns only. It does not run diagnostics, launch browsers, dispatch routes, or touch
	 * services.
	 *
	 * @param array{scope?: string, limit?: int, max_artifacts?: int, max_bytes_per_file?: int, strip_html?: bool} $args Diagnostic scan options.
	 * @return array<string, mixed> Latest diagnostic error report.
	 *
	 * @throws InvalidArgumentException When scope is not a repo-local directory.
	 */
	private function diagnostics_last_error(array $args): array {
		$scope=trim((string)($args['scope'] ?? ''));
		$base=$scope!=='' ? $this->safe_repo_path($scope) : $this->common_root.'/dataphyre';
		if(!is_dir($base)){
			throw new InvalidArgumentException('scope must point to a repo-local directory.');
		}
		$limit=max(1, min((int)($args['limit'] ?? 5) ?: 5, 25));
		$max_artifacts=max(1, min((int)($args['max_artifacts'] ?? 20) ?: 20, 100));
		$max_bytes=max(1000, min((int)($args['max_bytes_per_file'] ?? 80000) ?: 80000, 250000));
		$strip_html=!array_key_exists('strip_html', $args) || (bool)$args['strip_html'];
		$artifacts=[];
		foreach($this->all_files($base, 12000) as $path){
			$relative=$this->relative_path($path);
			if(!$this->is_tracelog_artifact($relative)){
				continue;
			}
			$artifacts[]=[
				'path'=>$path,
				'relative'=>$relative,
				'modified_at'=>$this->file_modified_iso($path),
				'size_bytes'=>(int)@filesize($path),
				'kind'=>$this->tracelog_artifact_kind($relative),
			];
		}
		usort($artifacts, static fn(array $a, array $b): int => strcmp((string)($b['modified_at'] ?? ''), (string)($a['modified_at'] ?? '')));
		$artifacts=array_slice($artifacts, 0, $max_artifacts);
		$matches=[];
		foreach($artifacts as $artifact){
			$text=(string)file_get_contents((string)$artifact['path'], false, null, 0, $max_bytes);
			if($strip_html){
				$text=html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
			$text=$this->redact_sensitive_text($text);
			foreach($this->diagnostic_error_matches($text) as $match){
				$matches[]=[
					'path'=>$artifact['relative'],
					'kind'=>$artifact['kind'],
					'modified_at'=>$artifact['modified_at'],
					'line'=>$match['line'],
					'severity'=>$match['severity'],
					'indicator'=>$match['indicator'],
					'snippet'=>$match['snippet'],
					'truncated'=>$artifact['size_bytes']>$max_bytes,
				];
				if(count($matches)>=$limit){
					break 2;
				}
			}
		}
		return [
			'scope'=>$this->relative_path($base),
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'diagnostic_safety'=>$this->diagnostic_safety_contract('diagnostics_last_error'),
			'diagnostic_handoff'=>$this->diagnostic_handoff_contract('diagnostics_last_error', ['scope', 'artifacts_scanned', 'error_count', 'latest.path', 'latest.severity', 'latest.indicator', 'latest.snippet']),
			'diagnostic_summary'=>$this->diagnostic_summary('diagnostics_last_error', [
				'finding'=>$matches===[] ? 'No recent error-looking diagnostics found in the requested scope.' : 'Latest redacted diagnostic is '.$matches[0]['severity'].' in '.(string)$matches[0]['path'].'.',
				'evidence'=>[
					'scope'=>$this->relative_path($base),
					'artifacts_scanned'=>count($artifacts),
					'error_count'=>count($matches),
					'latest_path'=>(string)($matches[0]['path'] ?? ''),
					'latest_severity'=>(string)($matches[0]['severity'] ?? ''),
					'latest_indicator'=>(string)($matches[0]['indicator'] ?? ''),
				],
			]),
			'artifacts_scanned'=>count($artifacts),
			'error_count'=>count($matches),
			'max_artifacts'=>$max_artifacts,
			'max_bytes_per_file'=>$max_bytes,
			'latest'=>$matches[0] ?? null,
			'errors_policy'=>[
				'classification'=>'internal_triage_only',
				'default_handoff'=>'diagnostic_summary.copy_safe_evidence',
				'external_share'=>'Do not copy latest.snippet or errors[].snippet outside app-local debugging; summarize through diagnostic_summary.copy_safe_evidence and external_share_review.',
			],
			'errors'=>$matches,
			'patterns'=>['fatal error', 'uncaught', 'exception', 'traceback', 'stack trace', 'error', 'warning', 'failed'],
			'guardrails'=>[
				'Only repo-local Tracelog/log artifacts are scanned.',
				'Output is bounded and secret-like values are redacted.',
				'No diagnostics, route dispatch, browser runners, or external services are executed.',
			],
		];
	}

	/**
	 * Describes the safety envelope required before any future browser diagnostics runner exists.
	 *
	 * emits a product-neutral plan only. It does not launch a browser, start a server, navigate URLs,
	 * write artifacts, read cookies, or collect page content.
	 *
	 * @param array{base_url?: string} $args Optional caller-owned base URL placeholder.
	 * @return array<string, mixed> Browser diagnostics readiness plan.
	 */
	private function browser_diagnostics_readiness_plan(array $args): array {
		$base_url=trim((string)($args['base_url'] ?? '<base-url>'));
		if($base_url===''){
			$base_url='<base-url>';
		}
		return [
			'plan_type'=>'dataphyre_browser_diagnostics_readiness_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'browser_launched'=>false,
			'http_requests_sent'=>false,
			'artifacts_written'=>false,
			'base_url'=>$base_url,
			'diagnostic_safety'=>$this->diagnostic_safety_contract('browser_diagnostics_readiness'),
			'current_safe_surfaces'=>[
				'browser_manifest'=>'dataphyre_browser_regression_manifest_summary',
				'flightdeck_surfaces'=>'dataphyre_flightdeck_surfaces_list',
				'tracelog_artifacts'=>'dataphyre_tracelog_artifacts_list',
				'last_error'=>'dataphyre_diagnostics_last_error',
				'verification_catalog'=>'dataphyre_verification_surface_catalog',
			],
			'future_runner_preconditions'=>[
				'unsafe opt-in must be explicit and visible in the call envelope',
				'base_url must be caller-provided, absolute http(s), and not hardcoded in shared MCP code',
				'browser process lifecycle, timeout, viewport, and user data directory must be bounded',
				'allowed paths or route names must be caller-provided and audited before navigation',
				'cookies, local storage, auth headers, screenshots, videos, traces, and console logs must be redacted or bounded',
				'artifact writes must stay in caller-owned output directories and never shared MCP module paths',
				'runner must not click destructive actions, submit mutating forms, or bypass auth guards unless a separate explicit workflow allows it',
			],
			'allowed_future_outputs'=>[
				'navigation status and final URL',
				'bounded console errors and network failures',
				'selected accessibility or layout findings',
				'screenshot and trace artifact paths in caller-owned output directories',
				'timing, viewport, user agent, and runner version metadata',
				'redacted diagnostics summary',
			],
			'denied_future_outputs'=>[
				'raw cookies or session storage',
				'authorization headers or CSRF tokens',
				'full page HTML containing secrets',
				'unbounded screenshots, videos, traces, or network bodies',
				'destructive clicks, form submissions, or route dispatch outside the browser runner contract',
				'product-specific local scripts, ports, or binary paths in shared MCP metadata',
			],
			'audit_envelope_required_fields'=>[
				'tool_name',
				'unsafe_enabled',
				'base_url',
				'allowed_paths',
				'viewport',
				'timeout_ms',
				'artifact_output_dir',
				'redaction_policy',
				'mutation_policy',
				'verification_steps',
			],
			'client_steps'=>[
				'Use dataphyre_browser_regression_manifest_summary to inspect existing browser test contracts before running anything.',
				'Use dataphyre_flightdeck_surfaces_list and route tools to understand target surfaces without dispatching them from MCP.',
				'Use dataphyre_diagnostics_last_error and Tracelog tools to correlate failures from existing artifacts first.',
				'Only consider a future browser runner after process lifecycle, URL allow-list, artifact directory, redaction, and mutation policies are enforceable.',
				'Run dataphyre_mcp_verify_all before publishing any browser diagnostics runner capability.',
			],
			'safety_notes'=>[
				'This plan does not launch a browser, start servers, send HTTP requests, click UI, or write reports.',
				'Browser diagnostics remain intentionally outside default read-only MCP behavior.',
				'Keep shared MCP plans product-neutral; base_url is a caller-owned placeholder unless an explicit unsafe workflow validates it.',
			],
		];
	}

	/**
	 * Classifies error-like lines within already-redacted diagnostic text.
	 *
	 * pattern matching is intentionally local and conservative; it creates snippets around matching
	 * lines but does not infer stack semantics or inspect files referenced by the diagnostics.
	 *
	 * @param string $text Redacted diagnostic text.
	 * @return array<int, array{line: int, severity: string, indicator: string, snippet: string}> Error-like matches.
	 */
	private function diagnostic_error_matches(string $text): array {
		$patterns=[
			'fatal'=>'/(fatal\s+error|uncaught\s+(?:exception|error)|parse\s+error|typeerror|runtimeexception|logicexception)/i',
			'exception'=>'/(\bexception\b|traceback|stack\s+trace)/i',
			'error'=>'/(\berror\b|failed|failure)/i',
			'warning'=>'/(\bwarning\b|\bdeprecated\b|\bnotice\b)/i',
		];
		$matches=[];
		foreach(preg_split('/\R/', $text) ?: [] as $index=>$line){
			$line=trim((string)$line);
			if($line===''){
				continue;
			}
			foreach($patterns as $severity=>$pattern){
				if(preg_match($pattern, $line, $hit)!==1){
					continue;
				}
				$pos=stripos($text, $line);
				$matches[]=[
					'line'=>$index+1,
					'severity'=>$severity,
					'indicator'=>strtolower((string)($hit[1] ?? $severity)),
					'snippet'=>$this->snippet_around($text, is_int($pos) ? $pos : 0, strlen($line), 520),
				];
				break;
			}
		}
		return $matches;
	}

}
