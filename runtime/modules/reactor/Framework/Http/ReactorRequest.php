<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Immutable HTTP request envelope consumed by Reactor component actions.
 *
 * Reactor accepts payloads from regular form posts, query strings, JSON bodies,
 * and explicit arrays used by tests or internal dispatch. This object normalizes
 * component/action names, separates persistent component state from action
 * parameters, attaches optional signed snapshots, flattens PHP upload structures,
 * and records creation metadata for Reactor tracing.
 */
final class ReactorRequest {

	/**
	 * Stores a normalized Reactor request after all ingress parsing has completed.
	 *
	 * The constructor is private so every instance passes through `fromArray()`,
	 * which enforces name normalization, array coercion, upload shape, snapshot
	 * hydration, and trace emission consistently across HTTP and programmatic use.
	 *
	 * @param string $component Normalized component identifier.
	 * @param ?string $action Normalized action name, or `null` for render-only requests.
	 * @param array<string, mixed> $state Component state supplied by the browser.
	 * @param array<string, mixed> $params Action parameters and synthetic `_uploads` entries.
	 * @param ?ReactorSnapshot $snapshot Signed snapshot used to validate or restore server state.
	 * @param array<string, array<string, mixed>> $uploads Flattened upload descriptors keyed by dotted input path.
	 * @param array<string, string> $headers Lowercase HTTP header map captured from the server environment.
	 */
	private function __construct(
		private readonly string $component,
		private readonly ?string $action,
		private readonly array $state,
		private readonly array $params,
		private readonly ?ReactorSnapshot $snapshot,
		private readonly array $uploads=[],
		private readonly array $headers=[]
	){}

	/**
	 * Captures the current PHP request globals as a normalized Reactor request.
	*
	 * Query parameters, form fields, and JSON body values are merged with later
	 * sources taking precedence. File uploads are flattened and mirrored into
	 * `_uploads` inside params so action handlers can inspect uploaded files through
	 * the same payload channel used for scalar action arguments.
	*
	 * @return self Request envelope for the current HTTP interaction.
	 */
	public static function capture(): self {
		$input=self::jsonInput();
		$data=array_replace($_GET, $_POST, $input);
		$state=self::arrayValue($data['state'] ?? []);
		$params=self::arrayValue($data['params'] ?? []);
		$uploads=self::normalizeUploads($_FILES);
		if($uploads!==[]){
			$params['_uploads']=$uploads;
		}
		return self::fromArray([
			'component'=>$data['component'] ?? $data['name'] ?? null,
			'action'=>$data['action'] ?? null,
			'state'=>$state,
			'params'=>$params,
			'snapshot'=>$data['snapshot'] ?? null,
			'uploads'=>$uploads,
			'headers'=>self::headers(),
		]);
	}

	/**
	 * Captures a batch request payload or falls back to the single current request.
	*
	 * JSON bodies may provide a `batch` array containing multiple Reactor request
	 * shapes. Invalid non-array entries are ignored; if the batch is absent or all
	 * entries are invalid, the method returns a one-item batch from `capture()`.
	*
	 * @return array<int, self> Ordered Reactor request envelopes ready for dispatch.
	 */
	public static function captureBatch(): array {
		$input=self::jsonInput();
		$batch=is_array($input['batch'] ?? null) ? $input['batch'] : [];
		if($batch===[]){
			return [self::capture()];
		}
		$requests=[];
		foreach($batch as $item){
			if(!is_array($item)){
				continue;
			}
			$requests[]=self::fromArray($item);
		}
		return $requests!==[] ? $requests : [self::capture()];
	}

	/**
	 * Coerces an existing request, raw request array, or null value into an envelope.
	*
	 * Existing instances are returned unchanged. Arrays are treated as already
	 * captured request data and normalized through `fromArray()`. A null value means
	 * "read the active HTTP request" and delegates to `capture()`.
	*
	 * @param ReactorRequest|array<string, mixed>|null $request Request instance, raw request shape, or current-request sentinel.
	 * @return self Normalized request envelope.
	 */
	public static function from(ReactorRequest|array|null $request): self {
		if($request instanceof self){
			return $request;
		}
		if(is_array($request)){
			return self::fromArray($request);
		}
		return self::capture();
	}

	/**
	 * Normalizes a raw Reactor request array into an immutable request envelope.
	*
	 * Accepted aliases mirror the browser payload: `component` or legacy `name`,
	 * optional `action`, `state`, `params`, `snapshot`, `uploads`, and `headers`.
	 * Stringified JSON arrays are accepted for state and params. Snapshot values
	 * are hydrated through `ReactorSnapshot::from()` before dispatch.
	*
	 * @param array<string, mixed> $data Raw request payload.
	 * @return self Normalized request envelope with trace metadata recorded.
	 */
	public static function fromArray(array $data): self {
		$snapshot=null;
		if($data['snapshot'] ?? null){
			$snapshot=ReactorSnapshot::from($data['snapshot']);
		}
		$request=new self(
			ReactorName::normalize((string)($data['component'] ?? $data['name'] ?? '')),
			isset($data['action']) && trim((string)$data['action'])!=='' ? ReactorName::normalize((string)$data['action']) : null,
			self::arrayValue($data['state'] ?? []),
			self::arrayValue($data['params'] ?? []),
			$snapshot,
			self::arrayValue($data['uploads'] ?? []),
			is_array($data['headers'] ?? null) ? $data['headers'] : []
		);
		ReactorTrace::record('request.created', [
			'component'=>$request->component,
			'action'=>$request->action,
			'state_keys'=>array_keys($request->state),
			'param_keys'=>array_keys($request->params),
			'uploads'=>count($request->uploads),
			'signed_snapshot'=>$request->snapshot instanceof ReactorSnapshot,
		]);
		return $request;
	}

	/**
	 * Returns the normalized component identifier targeted by this request.
	*
	 * @return string Component name after `ReactorName::normalize()`.
	 */
	public function component(): string {
		return $this->component;
	}

	/**
	 * Returns the normalized action name requested by the browser, when present.
	*
	 * @return ?string Action name after `ReactorName::normalize()`, or `null` for render requests.
	 */
	public function action(): ?string {
		return $this->action;
	}

	/**
	 * Returns browser-supplied component state to hydrate before action execution.
	 *
	 * @return array<string, mixed> State fields captured from JSON, form, or programmatic input.
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Returns action parameters supplied alongside the Reactor request.
	*
	 * @return array<string, mixed> Parameter map, possibly including `_uploads` for flattened file uploads.
	 */
	public function params(): array {
		return $this->params;
	}

	/**
	 * Returns flattened PHP upload descriptors captured for this request.
	*
	 * @return array<string, array{name:string,type:string,tmp_name:string,error:int,size:int}> Uploads keyed by dotted input path.
	 */
	public function uploads(): array {
		return $this->uploads;
	}

	/**
	 * Returns the optional signed snapshot sent with the request.
	 *
	 * @return ?ReactorSnapshot Snapshot envelope used by Reactor hydration and integrity checks.
	 */
	public function snapshot(): ?ReactorSnapshot {
		return $this->snapshot;
	}

	/**
	 * Identifies requests that were sent by the Reactor client transport.
	*
	 * The primary signal is `X-Dataphyre-Reactor: DataphyreReactor`; legacy AJAX
	 * calls are also accepted through `X-Requested-With: XMLHttpRequest`.
	 *
	 * @return bool `true` when captured headers identify the request as Reactor/AJAX traffic.
	 */
	public function isReactorRequest(): bool {
		$value=$this->headers['x-dataphyre-reactor'] ?? $this->headers['x-requested-with'] ?? '';
		return strcasecmp((string)$value, 'DataphyreReactor')===0 || strcasecmp((string)$value, 'XMLHttpRequest')===0;
	}

	/**
	 * Reads and decodes the JSON request body within the configured payload limit.
	 *
	 * Non-JSON content types, invalid JSON, and bodies larger than
	 * `Reactor::config('max_payload_bytes')` intentionally produce an empty array
	 * instead of throwing, allowing form-encoded Reactor requests to share capture
	 * code with JSON requests.
	 *
	 * @return array<string, mixed> Decoded JSON object data, or an empty array.
	 */
	private static function jsonInput(): array {
		$contentType=(string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
		if(!str_contains(strtolower($contentType), 'application/json')){
			return [];
		}
		$max=(int)Reactor::config('max_payload_bytes', 262144);
		$raw=(string)file_get_contents('php://input', false, null, 0, max(1, $max+1));
		if(strlen($raw)>$max){
			return [];
		}
		$data=json_decode($raw, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Coerces arrays and JSON object strings into request payload arrays.
	 *
	 * Scalars, empty strings, and invalid JSON collapse to an empty array so state
	 * and params never expose unparsed transport values to component handlers.
	 *
	 * @param mixed $value Transport value from globals or an explicit request array.
	 * @return array<string, mixed> Parsed object-like payload.
	 */
	private static function arrayValue(mixed $value): array {
		if(is_array($value)){
			return $value;
		}
		if(is_string($value) && trim($value)!==''){
			$decoded=json_decode($value, true);
			return is_array($decoded) ? $decoded : [];
		}
		return [];
	}

	/**
	 * Normalizes PHP's nested `$_FILES` structure into dotted upload paths.
	 *
	 * Entries with `UPLOAD_ERR_NO_FILE` are skipped because they represent empty
	 * optional fields rather than files an action can consume.
	 *
	 * @param array<string, mixed> $files Raw `$_FILES` payload.
	 * @return array<string, array{name:string,type:string,tmp_name:string,error:int,size:int}> Flattened upload descriptors.
	 */
	private static function normalizeUploads(array $files): array {
		$uploads=[];
		foreach($files as $field=>$spec){
			if(!is_array($spec)){
				continue;
			}
			foreach(self::walkUpload((string)$field, $spec) as $path=>$upload){
				if(($upload['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){
					continue;
				}
				$uploads[$path]=$upload;
			}
		}
		return $uploads;
	}

	/**
	 * Recursively flattens one upload field while preserving PHP upload metadata.
	 *
	 * Multi-file inputs are represented as dotted paths such as `avatar.0` or
	 * `documents.contract`, which gives Reactor actions stable keys independent of
	 * PHP's nested upload array layout.
	 *
	 * @param string $path Dotted upload field path accumulated from parent keys.
	 * @param array<string, mixed> $spec PHP upload field descriptor or nested descriptor branch.
	 * @return array<string, array{name:string,type:string,tmp_name:string,error:int,size:int}> Upload descriptors keyed by dotted path.
	 */
	private static function walkUpload(string $path, array $spec): array {
		$name=$spec['name'] ?? null;
		if(!is_array($name)){
			return [$path=>[
				'name'=>(string)($spec['name'] ?? ''),
				'type'=>(string)($spec['type'] ?? ''),
				'tmp_name'=>(string)($spec['tmp_name'] ?? ''),
				'error'=>(int)($spec['error'] ?? UPLOAD_ERR_NO_FILE),
				'size'=>(int)($spec['size'] ?? 0),
			]];
		}
		$uploads=[];
		foreach($name as $key=>$_){
			$child=[
				'name'=>$spec['name'][$key] ?? null,
				'type'=>$spec['type'][$key] ?? null,
				'tmp_name'=>$spec['tmp_name'][$key] ?? null,
				'error'=>$spec['error'][$key] ?? null,
				'size'=>$spec['size'][$key] ?? null,
			];
			$uploads+=self::walkUpload($path.'.'.(string)$key, $child);
		}
		return $uploads;
	}

	/**
	 * Captures request headers from `$_SERVER` into Reactor's lowercase header map.
	 *
	 * Both regular `HTTP_*` keys and PHP's special content headers are included,
	 * with underscores converted to hyphens to match the lookup style used by
	 * `isReactorRequest()`.
	 *
	 * @return array<string, string> Lowercase HTTP header names mapped to string values.
	 */
	private static function headers(): array {
		$headers=[];
		foreach($_SERVER as $key=>$value){
			if(!str_starts_with($key, 'HTTP_') && !in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)){
				continue;
			}
			$name=strtolower(str_replace('_', '-', preg_replace('/^HTTP_/', '', $key) ?? $key));
			$headers[$name]=(string)$value;
		}
		return $headers;
	}
}
