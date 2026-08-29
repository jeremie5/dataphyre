<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;

foreach(['DATAPHYRE_VESTRA_NO_DISPATCH','DATAPHYRE_VESTRA_LOADER_NO_DISPATCH'] as $constant){
	if(!defined($constant)){
		define($constant, true);
	}
}

require_once dirname(__DIR__).'/kernel/vestra.main.php';
require_once dirname(__DIR__).'/kernel/loader.php';
require_once dirname(__DIR__).'/Framework/Bootstrap.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableSchema.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

/** Intent-level setup for Vestra tokens, transport, SQL, URLs, and files. */
final class DpVestraRuntimeScenario {
	private TempWorkspace $workspace;
	/** @var array<string,mixed> */
	private array $config=[];
	/** @var list<array{match:string,response:mixed}> */
	private array $httpQueue=[];
	/** @var list<array<string,mixed>> */
	private array $httpCalls=[];
	/** @var list<array<string,mixed>> */
	private array $httpObservations=[];
	/** @var list<mixed> */
	private array $sqlSelectQueue=[];
	/** @var list<array{operation:string,arguments:array}> */
	private array $sqlCalls=[];
	/** @var list<string> */
	private array $logs=[];
	/** @var list<int> */
	private array $sleepCalls=[];
	/** @var array<string,mixed> */
	private array $dialbacks=[];
	/** @var array<string,string> */
	private array $environment=[];
	/** @var array<string,mixed> */
	private array $legacy=[];
	/** @var array<string,mixed> */
	private array $ingestionReferences=[];
	private mixed $sqlInsert=true;
	private mixed $sqlUpdate=true;
	private mixed $sqlDelete=true;

	private function __construct(private Context $context) {
		$this->workspace=$context->workspace('vestra-runtime');
		$this->workspace->directory('cache');
		$this->reset();
	}

	public static function open(Context $context): self {
		return new self($context);
	}

	public function reset(array $overrides=[]): self {
		$this->config=$this->defaultConfig();
		$this->httpQueue=[];
		$this->httpCalls=[];
		$this->httpObservations=[];
		$this->sqlSelectQueue=[];
		$this->sqlCalls=[];
		$this->logs=[];
		$this->sleepCalls=[];
		$this->dialbacks=[
			'CALL_VESTRA_ISSUE_TENANT_TOKEN'=>[
				'token'=>'g1.runtime-grant','expires_at'=>1700000300,'tenant_grant'=>true,
			],
		];
		$this->environment=[];
		$this->legacy=[];
		$this->ingestionReferences=[];
		$this->sqlInsert=true;
		$this->sqlUpdate=true;
		$this->sqlDelete=true;
		$runtime=[
			'config'=>$this->config,
			'cache_directory'=>$this->workspace->path('cache'),
			'time'=>1700000000,
			'uuid'=>static fn(): string=>'fixture-object',
			'env'=>fn(string $name): string|false=>$this->environment[$name] ?? false,
			'legacy_config'=>fn(string $name): mixed=>$this->legacy[$name] ?? null,
			'http'=>fn(array $request): mixed=>$this->respond($request),
			'http_observer'=>function(array $event): void { $this->httpObservations[]=$event; },
			'sql_select'=>fn(mixed ...$arguments): mixed=>$this->observeSql('select', $arguments),
			'sql_insert'=>fn(mixed ...$arguments): mixed=>$this->observeSql('insert', $arguments),
			'sql_update'=>fn(mixed ...$arguments): mixed=>$this->observeSql('update', $arguments),
			'sql_delete'=>fn(mixed ...$arguments): mixed=>$this->observeSql('delete', $arguments),
			'dialback'=>fn(string $event, mixed ...$arguments): mixed=>$this->resolveDialback($event, $arguments),
			'trace'=>static fn(mixed ...$arguments): null=>null,
			'log'=>function(string $message): void {
				$this->logs[]=$message;
			},
			'sleep'=>function(int $microseconds): void {
				$this->sleepCalls[]=$microseconds;
			},
			'mime'=>static fn(string $file): string=>str_ends_with($file, '.png') ? 'image/png' : 'application/octet-stream',
			'encrypt'=>static fn(string $content, array $salt): string=>'encrypted:'.$content,
			'server'=>[
				'HTTPS'=>'on','HTTP_HOST'=>'shop.example.test','SERVER_PORT'=>443,
			],
			'ingest_propagate'=>fn(string $url): mixed=>$this->ingestionReferences[$url] ?? false,
		];
		\dataphyre\vestra::resetRuntime(array_replace($runtime, $overrides));
		return $this;
	}

	/** @return array<string,mixed> */
	private function defaultConfig(): array {
		return array_replace(\dataphyre\vestra::defaults(), [
			'base_url'=>'https://node.vestra.test/',
			'object_url'=>'https://objects.vestra.test/',
			'default_tenant'=>'tenant-one',
			'rate'=>'s.p',
			'api_url'=>'https://control.vestra.test/api/',
			'api_token'=>'control-key',
			'write_token'=>'write-fixed',
			'tenant_read_token'=>'read-fixed',
			'node_token'=>'node-fixed',
			'token_ttl'=>300,
			'token_grace'=>30,
			'tenants'=>[
				'media'=>[
					'tenant'=>'tenant-media','rate'=>'m.p','object_url'=>'https://media.vestra.test/',
				],
			],
		]);
	}

	public function withConfig(array $changes): self {
		$this->config=array_replace($this->config, $changes);
		\dataphyre\vestra::configureRuntime(['config'=>$this->config]);
		return $this;
	}

	public function withoutTokenDialback(): self {
		$this->dialbacks['CALL_VESTRA_ISSUE_TENANT_TOKEN']=null;
		return $this;
	}

	public function withDialback(string $event, mixed $result): self {
		$this->dialbacks[$event]=$result;
		return $this;
	}

	public function queueHttp(string $match, array|false $response): self {
		$this->httpQueue[]=['match'=>$match,'response'=>$response];
		return $this;
	}

	public function queueJson(string $match, array $json, int $status=200): self {
		return $this->queueHttp($match, ['status'=>$status,'json'=>$json,'body'=>'']);
	}

	public function queueSelect(mixed ...$results): self {
		array_push($this->sqlSelectQueue, ...$results);
		return $this;
	}

	public function file(string $relative, string $contents): string {
		return $this->workspace->file($relative, $contents);
	}

	public function path(string $relative=''): string {
		return $this->workspace->path($relative);
	}

	/** @return array<string,mixed> */
	public function configurationContract(): array {
		$this->reset();
		$snapshot=\dataphyre\vestra::runtimeState();
		$defaults=\dataphyre\vestra::defaults();
		$configured=\dataphyre\vestra::configured();
		$cache=\dataphyre\vestra::cacheDirectory();
		$this->environment=['VESTRA_BASE_URL'=>'https://env-node.test','VESTRA_PUBLIC_URL'=>'https://env-public.test'];
		$this->legacy=['vestra_tenant'=>'legacy-tenant','vestra_rate'=>'legacy-rate'];
		$this->withConfig(['base_url'=>'','object_url'=>'','default_tenant'=>'','tenant'=>'','rate'=>'']);
		$base=$this->invoke('baseUrl');
		$public=$this->invoke('publicBaseUrl');
		$tenant=$this->invoke('tenant');
		$rate=$this->invoke('rate');
		return [
			'has_config'=>is_array($snapshot['config'] ?? null),
			'default_safe_delete'=>$defaults['delete_source_after_propagate'],
			'configured'=>$configured,
			'cache'=>$cache,
			'fallback_base'=>$base,
			'fallback_public'=>$public,
			'fallback_tenant'=>$tenant,
			'fallback_rate'=>$rate,
		];
	}

	/** @return array<string,mixed> */
	public function referenceContract(): array {
		$this->reset();
		$nested=['reference'=>['data'=>['object'=>[
			'objectId'=>'42','object_handle'=>'obj_handle','tenant'=>'tenant-one',
		]]]];
		$normalized=$this->invoke('normalizeReference', $nested);
		$linkOnly=$this->invoke('normalizeReference', ['links'=>['object'=>'https://persisted.test/object']]);
		$handleOnly=$this->invoke('normalizeReference', ['metadata'=>['handle'=>'handle-only']]);
		$invalid=$this->invoke('normalizeReference', ['unknown'=>'value']);
		$response=$this->invoke('referenceFromResponse', [
			'ok'=>true,
			'data'=>['reference'=>[
				'object_id'=>73,'tenant'=>'tenant-one','asset_url'=>'https://persisted.test/asset',
				'passkey'=>'pass-one','mime_type'=>'image/png','filesize'=>120,
				'metadata'=>['owner'=>'shop'],
			]],
		], 'hash-one');
		return [
			'nested_id'=>$normalized['object_id'] ?? null,
			'nested_driver'=>$normalized['driver'] ?? null,
			'link_only'=>is_array($linkOnly),
			'handle'=>$handleOnly['handle'] ?? null,
			'invalid'=>$invalid,
			'response_id'=>$response['object_id'] ?? null,
			'response_asset'=>$response['links']['asset'] ?? null,
			'response_passkey'=>$response['tokens']['passkey'] ?? null,
			'response_hash'=>$response['hash'] ?? null,
			'response_owner'=>$response['metadata']['owner'] ?? null,
			'negative_ok'=>$this->invoke('referenceFromResponse', ['ok'=>false]),
			'negative_status'=>$this->invoke('referenceFromResponse', ['status'=>'failed','object_id'=>1]),
		];
	}

	/** @return array<string,mixed> */
	public function urlContract(): array {
		$this->reset();
		$reference=[
			'object_id'=>99,'tenant'=>'tenant-one','filename'=>'folder/My Image',
			'tokens'=>['passkey'=>'secret'],
		];
		$transformed=\dataphyre\vestra::asset_url($reference, 'webp', [
			'width'=>640,'h'=>480,'mode'=>'cover','quality'=>120,'mime'=>'image/jpg','fit'=>'crop',
		]);
		$alias=\dataphyre\vestra::object_url(['object_id'=>99], ['tenant'=>'media']);
		$explicit=\dataphyre\vestra::object_url(['object_id'=>99,'tenant'=>'tenant-one'], [
			'token'=>'explicit-token','object_expires_at'=>1700000500,'filename'=>'safe/name',
		]);
		$this->reset()->withoutTokenDialback()->withConfig(['api_token'=>'','tenant_read_token'=>'','node_token'=>'']);
		$unsigned=\dataphyre\vestra::object_url(['object_id'=>99,'tenant'=>'tenant-one'], ['allow_unsigned'=>true]);
		$this->withConfig(['base_url'=>'','object_url'=>'']);
		$fallback=\dataphyre\vestra::object_url([
			'links'=>['object'=>'https://persisted.test/object?old=1'],'tokens'=>['passkey'=>'p'],
		], ['variant'=>'large']);
		$asset=\dataphyre\vestra::asset_url([
			'links'=>['asset'=>'https://persisted.test/file?download=1#view'],
		], 'png', ['v'=>2]);
		$template=\dataphyre\vestra::object_url([
			'object_handle'=>'handle','tenant'=>'tenant-one','rate'=>'s.p',
			'url_template'=>'https://persisted.test/v/{tenant}/{rate}/{object_id}',
		]);
		return compact('transformed','alias','explicit','unsigned','fallback','asset','template');
	}

	/** @return array<string,mixed> */
	public function tokenContract(): array {
		$this->reset();
		$configured=$this->invoke('writeToken', 'tenant-one', 'PUT', '/objects/one', ['max_bytes'=>12]);
		$this->withoutTokenDialback()->withConfig(['write_token'=>'']);
		$this->queueJson('/tokens/write', ['data'=>['write_token'=>['token'=>'write-issued','expires_at'=>1700000600]]]);
		$issued=$this->invoke('writeToken', 'tenant-one', 'PUT', '/objects/two', ['max_bytes'=>12]);
		$cached=$this->invoke('writeToken', 'tenant-one', 'PUT', '/objects/two', ['max_bytes'=>12]);
		$writeCalls=count(array_filter($this->httpCalls, static fn(array $call): bool=>str_contains((string)$call['url'], '/tokens/write')));

		$this->reset()->withoutTokenDialback()->withConfig(['write_token'=>'','tenant_read_token'=>'']);
		$this->queueJson('/tokens/access', ['data'=>['access_token'=>[
			'token'=>'access-issued','expires_at'=>1700000600,
		]]]);
		$access=\dataphyre\vestra::object_url(['object_id'=>77,'tenant'=>'tenant-one']);

		$this->reset()->withoutTokenDialback()->withConfig(['write_token'=>'','tenant_read_token'=>'']);
		$this->queueJson('/tokens/access', ['ok'=>false], 503);
		$this->queueJson('/tokens/access', ['data'=>['access_token'=>[
			'token'=>'access-after-retry','expires_at'=>1700000600,
		]]]);
		$retriedAccess=\dataphyre\vestra::object_url(['object_id'=>78,'tenant'=>'tenant-one']);
		$retryCalls=count(array_filter($this->httpCalls, static fn(array $call): bool=>str_contains((string)$call['url'], '/tokens/access')));
		$retryDelays=$this->sleepCalls;

		$this->reset()->withoutTokenDialback()->withConfig(['api_token'=>'','tenant_read_token'=>'read-configured']);
		$read=\dataphyre\vestra::object_url(['object_id'=>77,'tenant'=>'tenant-one']);

		$this->reset()->withoutTokenDialback()->withConfig(['api_token'=>'','tenant_read_token'=>'','node_token'=>'node-fixed']);
		$this->queueJson('/tenant/token/issue', ['token'=>'node-issued','expires_at'=>1700000600]);
		$node=\dataphyre\vestra::object_url(['object_id'=>77,'tenant'=>'tenant-one']);

		$this->reset()->withoutTokenDialback()->withConfig(['tenant_read_token'=>'','node_token'=>'']);
		for($attempt=0; $attempt<5; $attempt++) $this->queueJson('/tokens/access', ['ok'=>false], 503);
		$outageFirst=\dataphyre\vestra::asset_url(['object_id'=>79,'tenant'=>'tenant-one'], 'png');
		$outageSecond=\dataphyre\vestra::asset_url(['object_id'=>79,'tenant'=>'tenant-one'], 'png');
		$outageCalls=count(array_filter($this->httpCalls, static fn(array $call): bool=>str_contains((string)$call['url'], '/tokens/access')));
		$outageDelays=$this->sleepCalls;
		return compact('configured','issued','cached','writeCalls','access','retriedAccess','retryCalls','retryDelays','read','node','outageFirst','outageSecond','outageCalls','outageDelays');
	}

	/** @return array<string,mixed> */
	public function usageContract(): array {
		$this->reset();
		$invalid=\dataphyre\vestra::update_use_count(['missing'=>'id'], 1);
		$missing=\dataphyre\vestra::update_use_count(['object_id'=>1], 1);
		$this->queueSelect(['use_count'=>2]);
		$positive=\dataphyre\vestra::update_use_count(['object_id'=>1], 3);
		$this->queueSelect(['use_count'=>2]);
		$this->sqlUpdate=false;
		$updateFailure=\dataphyre\vestra::update_use_count(['object_id'=>1], 3);

		$this->reset()->queueSelect(['use_count'=>1])->queueJson('/objects/1', ['status'=>'success']);
		$purged=\dataphyre\vestra::update_use_count(['object_id'=>1,'tenant'=>'tenant-one'], -1);
		$deleted=count(array_filter($this->sqlCalls, static fn(array $call): bool=>$call['operation']==='delete'));

		$this->reset()->queueSelect(['use_count'=>1])->queueJson('/objects/1', ['status'=>'failed']);
		$purgeFailure=\dataphyre\vestra::update_use_count(['object_id'=>1,'tenant'=>'tenant-one'], -1);
		return compact('invalid','missing','positive','updateFailure','purged','deleted','purgeFailure');
	}

	/** @return array<string,mixed> */
	public function ingestionContract(): array {
		$this->reset();
		$this->ingestionReferences=[
			'https://cdn.test/new.png'=>['object_id'=>2,'tenant'=>'tenant-one','token'=>'inline-token'],
			'https://cdn.test/bg.jpg'=>['object_id'=>3,'tenant'=>'tenant-one','token'=>'inline-token'],
		];
		$known=['https://cdn.test/known.css'=>['links'=>['asset'=>'https://persisted.test/known.css']]];
		$html='<link href="https://cdn.test/known.css"><img src="https://cdn.test/new.png">'
			.'<style>.hero{background:url(https://cdn.test/bg.jpg)}</style>'
			.'<img src="data:image/png;base64,abc"><a href="#section">jump</a>';
		$all=\dataphyre\vestra::ingest_resources($html, null, $known);
		$limited=\dataphyre\vestra::ingest_resources($html, 1, $known);
		$bad=\dataphyre\vestra::ingest_resources('<img src="https://cdn.test/missing.png">');
		return [
			'all_html'=>$all['new_html'],
			'all_changes'=>array_keys($all['changes']),
			'limited_changes'=>count($limited['changes']),
			'bad_html'=>$bad['new_html'],
			'logged'=>count($this->logs)>0,
		];
	}

	/** @return array<string,mixed> */
	public function remotePropagationContract(): array {
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', ['object_id'=>10]);
		$dialback=\dataphyre\vestra::propagate('https://origin.test/dialback.png');
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', 'invalid');
		$invalidDialback=\dataphyre\vestra::propagate('https://origin.test/invalid.png');
		$this->reset()->withoutTokenDialback()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$empty=\dataphyre\vestra::propagate(' ');
		$this->queueJson('/objects/fetch', [
			'status'=>'success','reference'=>[
				'object_id'=>11,'tenant'=>'tenant-one','asset_url'=>'https://persisted.test/eleven',
			],
		]);
		$remote=\dataphyre\vestra::propagate('https://origin.test/remote.png');
		$inserted=count(array_filter($this->sqlCalls, static fn(array $call): bool=>$call['operation']==='insert'));
		$this->reset()->withoutTokenDialback()->withDialback('CALL_VESTRA_PROPAGATE', null)->queueJson('/objects/fetch', ['status'=>'failed']);
		$failed=\dataphyre\vestra::propagate('https://origin.test/failed.png');
		return compact('dialback','invalidDialback','empty','remote','inserted','failed');
	}

	/** @return array<string,mixed> */
	public function localPropagationContract(): array {
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$missing=\dataphyre\vestra::propagate($this->path('missing.png'));

		$file=$this->file('sources/plain.png', 'plain image');
		$this->queueJson('/objects/reserve', ['ok'=>false]);
		$this->queueJson('/objects/fetch', ['status'=>'success','reference'=>['object_id'=>21,'tenant'=>'tenant-one']]);
		$plain=\dataphyre\vestra::propagate($file);
		$sourcePreserved=is_file($file);
		$stageFiles=glob($this->path('cache/*')) ?: [];

		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$encryptedFile=$this->file('sources/secret.png', 'secret image');
		$this->queueJson('/objects/reserve', ['ok'=>false]);
		$this->queueJson('/objects/fetch', ['status'=>'success','reference'=>['object_id'=>22,'tenant'=>'tenant-one']]);
		$encrypted=\dataphyre\vestra::propagate($encryptedFile, true);

		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->withConfig(['delete_source_after_propagate'=>true]);
		$disposable=$this->file('sources/disposable.png', 'delete me');
		$this->queueJson('/objects/reserve', ['ok'=>false]);
		$this->queueJson('/objects/fetch', ['status'=>'success','reference'=>['object_id'=>23,'tenant'=>'tenant-one']]);
		\dataphyre\vestra::propagate($disposable);
		return [
			'missing'=>$missing,
			'plain_id'=>$plain['object_id'] ?? null,
			'source_preserved'=>$sourcePreserved,
			'stage_cleaned'=>$stageFiles===[],
			'encrypted'=>$encrypted['encrypted'] ?? false,
			'original_size'=>$encrypted['metadata']['original_filesize'] ?? null,
			'delete_opt_in'=>!is_file($disposable),
		];
	}

	/** @return array<string,mixed> */
	public function directUploadContract(): array {
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$file=$this->file('sources/direct.png', 'direct image');
		$this->queueJson('/objects/reserve', [
			'ok'=>true,
			'data'=>[
				'object_id'=>31,
				'upload'=>['url'=>'/uploads/31','method'=>'PUT','headers'=>['X-Upload'=>'yes']],
			],
		]);
		$this->queueHttp('/uploads/31', ['status'=>201,'json'=>['asset_url'=>'https://persisted.test/31'],'body'=>'']);
		$reference=\dataphyre\vestra::propagate($file);
		return [
			'id'=>$reference['object_id'] ?? null,
			'asset'=>$reference['links']['asset'] ?? null,
			'purposes'=>array_column($this->httpCalls, 'purpose'),
			'source_preserved'=>is_file($file),
		];
	}

	/** @return array<string,mixed> */
	public function propagationExpiryContract(): array {
		$file=$this->file('sources/expiring.png', 'expiring image');
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$this->queueJson('/objects/reserve', [
			'ok'=>true,
			'object_expires_at'=>1700000600,
			'data'=>[
				'object_id'=>41,
				'object_expires_at'=>1700000600,
				'upload'=>['url'=>'/uploads/41','method'=>'PUT'],
				'cleanup'=>[
					'url'=>'https://objects.vestra.test/objects/41','path'=>'/objects/41',
					'method'=>'DELETE','scope'=>'reserved_object_only',
					'headers'=>['X-Vestra-Write-Token'=>'cleanup-41'],
				],
			],
		]);
		$this->queueHttp('/uploads/41', ['status'=>201,'json'=>['asset_url'=>'https://persisted.test/41'],'body'=>'']);
		$ttlReference=\dataphyre\vestra::propagate($file, false, ['object_expires_in_secs'=>600]);
		$ttlReserve=array_values(array_filter($this->httpCalls, static fn(array $call): bool=>($call['purpose'] ?? '')==='control'))[0] ?? [];
		$ttlPayload=[];
		if(is_string($ttlReserve['body'] ?? null)){
			parse_str($ttlReserve['body'], $ttlPayload);
		}
		$ttlStored=array_values(array_filter($this->sqlCalls, static fn(array $call): bool=>($call['operation'] ?? '')==='insert'))[0] ?? [];
		$ttlStoredFields=$ttlStored['arguments'][1] ?? [];
		$ttlStoredReference=json_decode((string)($ttlStoredFields['reference'] ?? ''), true);
		$ttlHashQueries=count(array_filter($this->sqlCalls, static fn(array $call): bool=>($call['operation'] ?? '')==='select' && str_contains((string)($call['arguments'][3] ?? ''), 'hash=?')));
		$this->queueSelect(['use_count'=>1]);
		$this->queueHttp('/objects/41', ['status'=>204,'body'=>'']);
		$ttlReleased=\dataphyre\vestra::update_use_count($ttlReference,-1);
		$ttlCleanup=array_values(array_filter($this->httpCalls, static fn(array $call): bool=>($call['method'] ?? '')==='DELETE'))[0] ?? [];

		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$this->queueJson('/objects/reserve', [
			'ok'=>true,
			'object_expires_at'=>1700000700,
			'data'=>[
				'object_id'=>42,
				'object_expires_at'=>1700000700,
				'upload'=>['url'=>'/uploads/42','method'=>'PUT'],
			],
		]);
		$this->queueHttp('/uploads/42', ['status'=>201,'json'=>[],'body'=>'']);
		$atReference=\dataphyre\vestra::propagate($file, false, ['object_expires_at'=>1700000700]);
		$atReserve=array_values(array_filter($this->httpCalls, static fn(array $call): bool=>($call['purpose'] ?? '')==='control'))[0] ?? [];
		$atPayload=[];
		if(is_string($atReserve['body'] ?? null)){
			parse_str($atReserve['body'], $atPayload);
		}
		$atStored=array_values(array_filter($this->sqlCalls, static fn(array $call): bool=>($call['operation'] ?? '')==='insert'))[0] ?? [];
		$atStoredFields=$atStored['arguments'][1] ?? [];
		$atStoredReference=json_decode((string)($atStoredFields['reference'] ?? ''), true);
		$atHashQueries=count(array_filter($this->sqlCalls, static fn(array $call): bool=>($call['operation'] ?? '')==='select' && str_contains((string)($call['arguments'][3] ?? ''), 'hash=?')));

		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$invalid=\dataphyre\vestra::propagate($file, false, ['object_expires_in_secs'=>0]);
		$invalidCalls=$this->httpCalls;
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null);
		$remote=\dataphyre\vestra::propagate('https://origin.test/remote.png', false, ['object_expires_in_secs'=>600]);
		$remoteCalls=$this->httpCalls;
		return [
			'ttl_id'=>$ttlReference['object_id'] ?? null,
			'ttl_expiry'=>$ttlReference['object_expires_at'] ?? null,
			'ttl_payload'=>$ttlPayload,
			'ttl_idempotency'=>$ttlReserve['headers']['Idempotency-Key'] ?? null,
			'ttl_stored_expiry'=>is_array($ttlStoredReference) ? ($ttlStoredReference['object_expires_at'] ?? null) : null,
			'ttl_hash_queries'=>$ttlHashQueries,
			'ttl_cleanup_scope'=>$ttlReference['cleanup']['scope'] ?? null,
			'ttl_cleanup_result'=>$ttlReleased,
			'ttl_cleanup_url'=>$ttlCleanup['url'] ?? null,
			'ttl_cleanup_token'=>$ttlCleanup['headers']['X-Vestra-Write-Token'] ?? null,
			'at_id'=>$atReference['object_id'] ?? null,
			'at_expiry'=>$atReference['object_expires_at'] ?? null,
			'at_payload'=>$atPayload,
			'at_idempotency'=>$atReserve['headers']['Idempotency-Key'] ?? null,
			'at_stored_expiry'=>is_array($atStoredReference) ? ($atStoredReference['object_expires_at'] ?? null) : null,
			'at_hash_queries'=>$atHashQueries,
			'invalid'=>$invalid,
			'invalid_calls'=>count($invalidCalls),
			'remote'=>$remote,
			'remote_calls'=>count($remoteCalls),
		];
	}

	/** @return array<string,mixed> */
	public function transportFailureContract(): array {
		$invalidBoundary=function(): mixed {
			$this->reset(['http'=>'invalid'])->withDialback('CALL_VESTRA_PROPAGATE', null);
			return \dataphyre\vestra::propagate('https://origin.test/file');
		};

		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->queueHttp('/objects/fetch', false);
		$transport=\dataphyre\vestra::propagate('https://origin.test/file');
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->queueHttp('/objects/fetch', ['status'=>500,'body'=>'error']);
		$status=\dataphyre\vestra::propagate('https://origin.test/file');
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->queueHttp('/objects/fetch', ['status'=>200,'body'=>'not-json']);
		$json=\dataphyre\vestra::propagate('https://origin.test/file');
		return ['invalid_boundary'=>$invalidBoundary,'transport'=>$transport,'status'=>$status,'json'=>$json,'logged'=>count($this->logs)>0];
	}

	/** @return array<string,mixed> */
	public function transportObserverContract(): array {
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->queueJson('/objects/fetch', [
			'ok'=>false,
			'error'=>[
				'status'=>'denied_by_control_plane_policy',
				'code'=>'VES_CONTROL_SCOPE_DENIED',
				'reason'=>'scope_denied',
			],
			'errors'=>['scope_not_allowed','must-not-cross-observer'],
			'detail'=>'must-not-cross-observer',
		]);
		\dataphyre\vestra::propagate('https://origin.test/file');
		$rejected=$this->httpObservations[0] ?? [];
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->queueHttp('/objects/fetch', false);
		\dataphyre\vestra::propagate('https://origin.test/file');
		$transport=$this->httpObservations[0] ?? [];
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->withConfig(['base_url'=>'http://127.0.0.1:17821/'])->queueHttp('/objects/fetch', false);
		\dataphyre\vestra::propagate('https://origin.test/file');
		$loopback=$this->httpObservations[0] ?? [];
		return ['rejected'=>$rejected,'transport'=>$transport,'loopback'=>$loopback];
	}

	/** @return array<string,mixed> */
	public function URLFailureAndFallbackContract(): array {
		$this->reset();
		$invalidObject=\dataphyre\vestra::object_url('not-a-reference');
		$invalidAsset=\dataphyre\vestra::asset_url(42, 'png');
		$missingLink=\dataphyre\vestra::object_url(['object_handle'=>'handle-without-link']);

		$this->reset()->withConfig(['base_url'=>'','object_url'=>'']);
		$assetFromObject=\dataphyre\vestra::asset_url(['links'=>[
			'object'=>'https://persisted.test/from-object?one=1#fragment',
		]], 'jpg');

		$this->reset()->withoutTokenDialback()->withConfig([
			'api_token'=>'','tenant_read_token'=>'','node_token'=>'',
		]);
		$unsignedRejected=\dataphyre\vestra::object_url(['object_id'=>5,'tenant'=>'tenant-one']);

		$this->reset()->withDialback('CALL_VESTRA_ISSUE_TENANT_TOKEN', 'string-token');
		$stringToken=\dataphyre\vestra::object_url(['object_id'=>5,'tenant'=>'tenant-one']);
		$this->withDialback('CALL_VESTRA_RESOLVE_TENANT_CONTEXT', ['rate'=>'array-rate']);
		$arrayContext=\dataphyre\vestra::object_url(['object_id'=>6,'tenant'=>'tenant-one']);
		$this->reset()->withDialback('CALL_VESTRA_RESOLVE_TENANT_CONTEXT', 'string-rate');
		$stringContext=\dataphyre\vestra::object_url(['object_id'=>7,'tenant'=>'tenant-one']);

		$this->reset()->withConfig(['default_tenant'=>'','tenant'=>'','rate'=>'']);
		$missingContext=\dataphyre\vestra::object_url(['object_id'=>8]);
		$this->reset()->withConfig(['base_url'=>'','object_url'=>'']);
		$missingBase=\dataphyre\vestra::object_url(['object_id'=>9,'tenant'=>'tenant-one']);
		return [
			'invalid_object'=>$invalidObject,
			'invalid_asset'=>$invalidAsset,
			'missing_link'=>$missingLink,
			'asset_from_object'=>$assetFromObject,
			'unsigned_rejected'=>$unsignedRejected,
			'string_token'=>$stringToken,
			'array_context'=>$arrayContext,
			'string_context'=>$stringContext,
			'missing_context'=>$missingContext,
			'missing_base'=>$missingBase,
			'empty_extension'=>$this->invoke('urlWithExtension', 'https://files.test/a', ''),
			'invalid_extension_url'=>$this->invoke('urlWithExtension', 'http://', 'png'),
			'invalid_query_url'=>$this->invoke('updateQuery', 'http://', ['a'=>1]),
			'safe_filename'=>$this->invoke('decorativeFilename', ['object_id'=>9], ['filename'=>'/unsafe'], 'png'),
			'scalar_id'=>$this->invoke('objectId', 'invalid'),
			'scalar_handle'=>$this->invoke('objectHandle', 'invalid'),
			'nested_handle'=>$this->invoke('objectHandle', ['data'=>['object'=>['handle'=>'nested-handle']]]),
		];
	}

	/** @return array<string,mixed> */
	public function configurationAndRequestEdgeContract(): array {
		$this->reset()->withConfig(['tenants'=>['alias'=>['rate'=>'a.p']]]);
		$alias=$this->invoke('profile', 'alias');

		$this->reset(['legacy_config'=>['vestra_url'=>'https://legacy-array.test']]);
		$this->withConfig(['base_url'=>'']);
		$legacyArray=$this->invoke('baseUrl');

		$this->reset()->withConfig(['api_url'=>'']);
		$derivedApi=$this->invoke('apiUrl', 'tenant-one');

		$this->reset()->withConfig(['api_url'=>'','base_url'=>'','api_token'=>'']);
		$controlMissing=$this->invoke('controlRequest', 'POST', '/missing', [], 'tenant-one', 'json');

		$this->reset()->queueJson('/json', ['ok'=>true]);
		$jsonControl=$this->invoke('controlRequest', 'POST', '/json', ['hello'=>'world'], 'tenant-one', 'json');

		$this->reset()->withConfig(['base_url'=>'']);
		$baseMissing=$this->invoke('objectRequest', 'POST', '/objects', [], 'tenant-one', []);

		$this->reset()->withConfig(['node_token'=>'']);
		$nodeMissing=$this->invoke('objectRequest', 'POST', '/node', [], 'tenant-one', ['auth'=>'node']);

		$this->reset()->withConfig(['write_token'=>'','api_token'=>'']);
		$writeMissing=$this->invoke('objectRequest', 'POST', '/write', [], 'tenant-one', []);
		return [
			'alias_tenant'=>$alias['tenant'] ?? null,
			'legacy_array'=>$legacyArray,
			'derived_api'=>$derivedApi,
			'control_missing'=>$controlMissing,
			'json_control'=>$jsonControl,
			'base_missing'=>$baseMissing,
			'node_missing'=>$nodeMissing,
			'write_missing'=>$writeMissing,
		];
	}

	public function invalidEnvironmentBoundary(): mixed {
		$this->reset(['env'=>'invalid'])->withConfig(['base_url'=>'']);
		return $this->invoke('baseUrl');
	}

	public function invalidIngestionBoundary(): array {
		$this->reset(['ingest_propagate'=>'invalid']);
		return \dataphyre\vestra::ingest_resources('<img src="https://origin.test/a.png">');
	}

	/** @return array<string,mixed> */
	public function tokenFailureContract(): array {
		$this->reset()->withoutTokenDialback()->withConfig(['write_token'=>'']);
		$this->queueJson('/tokens/write', ['ok'=>true]);
		$missingWrite=$this->invoke('writeToken', 'tenant-one', 'PUT', '/missing', ['max_bytes'=>1]);

		$this->reset()->withoutTokenDialback()->withConfig(['tenant_read_token'=>'']);
		$this->queueJson('/tokens/access', ['ok'=>false]);
		$rejectedAccess=\dataphyre\vestra::object_url(['object_id'=>10,'tenant'=>'tenant-one']);

		$this->reset()->withoutTokenDialback()->withConfig(['tenant_read_token'=>'']);
		$this->queueJson('/tokens/access', ['ok'=>true]);
		$missingAccessToken=\dataphyre\vestra::object_url(['object_id'=>11,'tenant'=>'tenant-one']);

		$this->reset()->withoutTokenDialback()->withConfig(['tenant_read_token'=>'']);
		$this->queueJson('/tokens/access', ['token'=>'expiring-token','expires_at'=>1700000600]);
		$objectExpiry=\dataphyre\vestra::object_url(['object_id'=>12,'tenant'=>'tenant-one'], ['object_expires_at'=>1700000400]);
		return compact('missingWrite','rejectedAccess','missingAccessToken','objectExpiry');
	}

	/** @return array<string,mixed> */
	public function propagationFilesystemFailureContract(): array {
		$hashFile=$this->file('failures/hash.png', 'hash');
		$this->reset(['fs_hash'=>static fn(): false=>false])->withDialback('CALL_VESTRA_PROPAGATE', null);
		$hash=\dataphyre\vestra::propagate($hashFile);

		$cacheFile=$this->file('failures/cache.png', 'cache');
		$this->reset([
			'cache_directory'=>$this->path('unavailable-cache'),
			'fs_is_dir'=>static fn(): bool=>false,
			'fs_mkdir'=>static fn(): bool=>false,
		])->withDialback('CALL_VESTRA_PROPAGATE', null);
		$cache=\dataphyre\vestra::propagate($cacheFile);

		$encryptFile=$this->file('failures/encrypt.png', 'encrypt');
		$this->reset(['encrypt'=>'invalid'])->withDialback('CALL_VESTRA_PROPAGATE', null);
		$encryptUnavailable=\dataphyre\vestra::propagate($encryptFile, true);

		$this->reset(['encrypt'=>static fn(): string=>''])->withDialback('CALL_VESTRA_PROPAGATE', null);
		$encryptEmpty=\dataphyre\vestra::propagate($encryptFile, true);

		$this->reset(['fs_write'=>static fn(): false=>false])->withDialback('CALL_VESTRA_PROPAGATE', null);
		$write=\dataphyre\vestra::propagate($encryptFile, true);

		$copyFile=$this->file('failures/copy.png', 'copy');
		$this->reset(['fs_copy'=>static fn(): bool=>false])->withDialback('CALL_VESTRA_PROPAGATE', null);
		$copy=\dataphyre\vestra::propagate($copyFile);

		$dedupeFile=$this->file('failures/dedupe.png', 'dedupe');
		$known=['object_id'=>88,'tenant'=>'tenant-one'];
		$this->reset()->withDialback('CALL_VESTRA_PROPAGATE', null)->queueSelect(
			['reference'=>json_encode($known, JSON_THROW_ON_ERROR)],
			['use_count'=>1],
		);
		$dedupe=\dataphyre\vestra::propagate($dedupeFile);
		return [
			'hash'=>$hash,'cache'=>$cache,'encrypt_unavailable'=>$encryptUnavailable,
			'encrypt_empty'=>$encryptEmpty,'write'=>$write,'copy'=>$copy,
			'dedupe_id'=>$dedupe['object_id'] ?? null,
		];
	}

	public function invalidFilesystemBoundary(): mixed {
		return $this->invoke('fs', 'unsupported-operation');
	}

	/** @return array<string,mixed> */
	public function legacyPlatformConfigurationContract(): array {
		$root=dirname(__DIR__, 4);
		return $this->context->processSucceeded($this->context->coveredPhpFixture(
			__DIR__.'/fixtures/vestra_legacy_configuration_probe.php',
			[dirname(__DIR__).'/kernel/vestra.main.php'],
			working_directory:$root,
			framework_root:$root,
		))->json();
	}

	/** @return array<string,mixed> */
	public function routerOwnedCacheDeliveryContract(): array {
		$root=dirname(__DIR__, 4);
		return $this->context->processSucceeded($this->context->coveredPhpFixture(
			__DIR__.'/fixtures/vestra_router_bindings_probe.php',
			[dirname(__DIR__).'/kernel/loader.php'],
			working_directory:$root,
			framework_root:$root,
		))->json();
	}

	/** @return array<string,mixed> */
	public function responseUploadAndRecordEdgeContract(): array {
		$this->reset();
		$missingIdentity=$this->invoke('referenceFromResponse', ['status'=>'success','data'=>['name'=>'no identity']]);
		$handle=$this->invoke('referenceFromResponse', [
			'status'=>'success','data'=>['object'=>[
				'handle'=>'handle-response','links'=>['object'=>'https://objects.test/handle'],
			]],
		]);
		$links=$this->invoke('referenceFromResponse', [
			'status'=>'success','object_id'=>91,
			'urls'=>['asset'=>'https://objects.test/91'],
		]);
		$guidance=$this->invoke('uploadGuidance', [
			'upload'=>['headers'=>[]],
			'upload_url'=>'https://uploads.test/fallback',
		]);
		$missingGuidance=$this->invoke('uploadGuidance', ['upload'=>['headers'=>[]]]);

		$file=$this->file('edges/upload.png', 'upload');
		$reserveMissing=$this->invoke('reserveAndUpload', $file, 'hash', '', 6, 'image/png');

		$this->reset()->queueJson('/objects/reserve', ['ok'=>true,'data'=>['object_id'=>92]]);
		$reserveNoGuidance=$this->invoke('reserveAndUpload', $file, 'hash', 'tenant-one', 6, 'image/png');

		$this->reset()->withConfig(['base_url'=>'','object_url'=>''])->queueJson('/objects/reserve', [
			'ok'=>true,'data'=>['object_id'=>93,'upload'=>['url'=>'/relative']],
		]);
		$relativeNoBase=$this->invoke('reserveAndUpload', $file, 'hash', 'tenant-one', 6, 'image/png');

		$this->reset()->queueJson('/objects/reserve', [
			'ok'=>true,'data'=>['object_id'=>94,'upload'=>['url'=>'https://uploads.test/fail']],
		])->queueHttp('https://uploads.test/fail', false);
		$uploadFailure=$this->invoke('reserveAndUpload', $file, 'hash', 'tenant-one', 6, 'image/png');

		$this->reset();
		$this->invoke('recordObject', ['missing'=>'identity']);
		$this->queueSelect(['object_id'=>95]);
		$this->invoke('recordObject', ['object_id'=>95,'tenant'=>'tenant-one']);
		$updated=count(array_filter($this->sqlCalls, static fn(array $call): bool=>$call['operation']==='update'));

		$this->reset(['server'=>['HTTPS'=>'off','SERVER_ADDR'=>'2001:db8::1','SERVER_PORT'=>8080]]);
		$origin=$this->invoke('localOriginUrl', 'asset one.png');
		return [
			'missing_identity'=>$missingIdentity,
			'handle'=>$handle['handle'] ?? null,
			'handle_link'=>$handle['links']['object'] ?? null,
			'container_link'=>$links['links']['asset'] ?? null,
			'guidance_url'=>$guidance['url'] ?? null,
			'missing_guidance'=>$missingGuidance,
			'reserve_missing'=>$reserveMissing,
			'reserve_no_guidance'=>$reserveNoGuidance,
			'relative_no_base'=>$relativeNoBase,
			'upload_failure'=>$uploadFailure,
			'updated'=>$updated,
			'origin'=>$origin,
		];
	}

	/** @return array<string,mixed> */
	public function cacheEndpointEdgeContract(): array {
		$this->reset();
		$rootFallback=dataphyre_vestra_cache_endpoint::dispatch(['filename'=>'missing.file']);
		$readFailure=dataphyre_vestra_cache_endpoint::dispatch([
			'cache_directory'=>$this->path(),
			'filename'=>'virtual.bin',
			'exists'=>static fn(): bool=>true,
			'read'=>static fn(): false=>false,
			'mtime'=>static fn(): int=>1700000000,
		]);
		$unknown=$this->file('delivery/archive.custom', 'bytes');
		$unknownResponse=dataphyre_vestra_cache_endpoint::dispatch([
			'cache_directory'=>dirname($unknown),'filename'=>basename($unknown),
			'server'=>['HTTP_IF_MODIFIED_SINCE'=>'Wed, 15 Nov 2023 00:00:00 GMT'],
			'mtime'=>static fn(): int=>1700000000,
		]);
		return [
			'root_fallback'=>$rootFallback['status'],
			'read_failure'=>$readFailure['status'],
			'unknown_type'=>$unknownResponse['headers']['Content-Type'],
			'modified_status'=>$unknownResponse['status'],
		];
	}

	public function invalidCacheEndpointBoundary(): array {
		return dataphyre_vestra_cache_endpoint::dispatch([
			'cache_directory'=>$this->path(),'filename'=>'x','exists'=>'invalid',
		]);
	}

	public function invalidCacheEmitter(): mixed {
		return dataphyre_vestra_cache_endpoint::bootstrap(true, [
			'cache_directory'=>$this->path(),'bindings'=>['filename'=>'missing'],'server'=>[],'emit'=>'invalid',
		]);
	}

	/** @return list<array<string,mixed>> */
	public function httpCalls(): array {
		return $this->httpCalls;
	}

	/** @return list<string> */
	public function logs(): array {
		return $this->logs;
	}

	private function invoke(string $method, mixed ...$arguments): mixed {
		return $this->context->nonPublic(\dataphyre\vestra::class)->invoke($method, ...$arguments);
	}

	private function respond(array $request): mixed {
		$this->httpCalls[]=$request;
		foreach($this->httpQueue as $index=>$queued){
			if(str_contains((string)($request['url'] ?? ''), $queued['match'])){
				array_splice($this->httpQueue, $index, 1);
				return $queued['response'];
			}
		}
		return ['status'=>503,'body'=>'unplanned request'];
	}

	private function observeSql(string $operation, array $arguments): mixed {
		$this->sqlCalls[]=['operation'=>$operation,'arguments'=>$arguments];
		return match($operation){
			'select'=>$this->sqlSelectQueue!==[] ? array_shift($this->sqlSelectQueue) : false,
			'insert'=>$this->sqlInsert,
			'update'=>$this->sqlUpdate,
			'delete'=>$this->sqlDelete,
			default=>false,
		};
	}

	private function resolveDialback(string $event, array $arguments): mixed {
		$value=$this->dialbacks[$event] ?? null;
		return is_callable($value) ? $value(...$arguments) : $value;
	}
}
