<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Runtime callback provider pack for Flysystem, Spatie, Cloudinary, and application media libraries. */
final class PanelEditorCallbackAssetProvider implements PanelEditorAssetProvider {
	private PanelEditorUpload $validator;
	private ?\Closure $authorizer=null;
	private ?\Closure $browser=null;
	private ?\Closure $finder=null;
	private ?\Closure $storer=null;
	private ?\Closure $deleter=null;
	private ?\Closure $deliverer=null;
	private ?\Closure $normalizer=null;
	private string $providerType='callback';
	private string $browserDriver='';
	private bool $uploads=true;
	private bool $deletes=false;
	private bool $enabled=true;
	private bool $csrfRequired=true;
	private string $csrfField='csrf';
	private string $csrfHeader='X-CSRF-Token';

	private function __construct(private string $providerName, private string $endpoint='') {
		$this->providerName=PanelEditorManifest::name($providerName, 'assets');
		try{ $this->endpoint=$endpoint!=='' ? PanelEditorAsset::normalizeEndpoint($endpoint) : ''; }catch(\Throwable){ $this->endpoint=''; }
		$this->browserDriver=$this->endpoint!=='' ? 'http' : $this->providerName;
		$this->validator=PanelEditorUpload::make($this->providerName, $this->endpoint);
	}
	public static function make(string $name='assets', string $endpoint=''): self { return new self($name,$endpoint); }
	public static function fromArray(array $definition): self {
		$browser=is_array($definition['browser'] ?? null) ? $definition['browser'] : [];
		$provider=self::make((string)($definition['name'] ?? 'assets'), (string)($browser['endpoint'] ?? $definition['endpoint'] ?? ''))
			->providerType((string)($definition['provider'] ?? 'callback'))
			->browserDriver((string)($browser['driver'] ?? ''))
			->accept(is_array($definition['accepted'] ?? null) ? $definition['accepted'] : [])
			->maxBytes((int)($definition['max_bytes'] ?? 10485760))
			->uploads(($definition['capabilities']['upload'] ?? false)===true)
			->deletes(($definition['capabilities']['delete'] ?? false)===true)
			->csrf(($browser['request_verification_required'] ?? $browser['csrf_required'] ?? true)!==false,(string)($browser['verification_field'] ?? $browser['csrf_field'] ?? 'csrf'),(string)($browser['verification_header'] ?? $browser['csrf_header'] ?? 'X-CSRF-Token'));
		return $provider->enabled(false);
	}
	public function providerType(string $type): self { $clone=clone $this; $clone->providerType=PanelEditorManifest::name($type,'callback'); return $clone; }
	public function browserDriver(string $driver): self { $clone=clone $this; $clone->browserDriver=PanelEditorManifest::name($driver,$clone->endpoint!==''?'http':$clone->providerName); return $clone; }
	public function accept(array|string $types): self { $clone=clone $this; $clone->validator=$clone->validator->accept($types); return $clone; }
	public function maxBytes(int $bytes): self { $clone=clone $this; $clone->validator=$clone->validator->maxBytes($bytes); return $clone; }
	public function detectUsing(callable $detector): self { $clone=clone $this; $clone->validator=$clone->validator->detectUsing($detector); return $clone; }
	public function uploads(bool $enabled=true): self { $clone=clone $this; $clone->uploads=$enabled; return $clone; }
	public function deletes(bool $enabled=true): self { $clone=clone $this; $clone->deletes=$enabled; return $clone; }
	public function enabled(bool $enabled=true): self { $clone=clone $this; $clone->enabled=$enabled; return $clone; }
	public function csrf(bool $required=true,string $field='csrf',string $header='X-CSRF-Token'): self { $clone=clone $this; $clone->csrfRequired=$required; $clone->csrfField=PanelEditorManifest::name($field,'csrf'); $header=trim($header); $clone->csrfHeader=preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D',$header)===1?$header:'X-CSRF-Token'; return $clone; }
	public function authorizeUsing(callable $authorizer): self { $clone=clone $this; $clone->authorizer=\Closure::fromCallable($authorizer); return $clone; }
	public function browseUsing(callable $browser): self { $clone=clone $this; $clone->browser=\Closure::fromCallable($browser); return $clone; }
	public function findUsing(callable $finder): self { $clone=clone $this; $clone->finder=\Closure::fromCallable($finder); return $clone; }
	public function storeUsing(callable $storer): self { $clone=clone $this; $clone->storer=\Closure::fromCallable($storer); return $clone; }
	public function deleteUsing(callable $deleter): self { $clone=clone $this; $clone->deleter=\Closure::fromCallable($deleter); return $clone; }
	public function deliverUsing(callable $deliverer): self { $clone=clone $this; $clone->deliverer=\Closure::fromCallable($deliverer); return $clone; }
	public function normalizeUsing(callable $normalizer): self { $clone=clone $this; $clone->normalizer=\Closure::fromCallable($normalizer); return $clone; }

	public function name(): string { return $this->providerName; }
	public function ready(): bool { return $this->enabled && $this->authorizer!==null && $this->browser!==null && $this->finder!==null && $this->normalizer!==null && (!$this->uploads || ($this->storer!==null && $this->validator->ready())); }
	public function validateUpload(array $upload, PanelEditorContext $context): PanelEditorContentResult { return $this->uploads ? $this->validator->validateUpload($upload,$context) : PanelEditorContentResult::reject((string)($upload['name']??''),'Editor asset uploads are disabled.'); }
	public function browse(array $query, PanelEditorContext $context): PanelEditorAssetPage {
		if(!$this->ready() || !$this->authorized('browse',$context,['query'=>$query])){ return new PanelEditorAssetPage(); }
		try{ $value=PanelUtilityResolver::evaluate($this->browser,['query'=>$query,'context'=>$context,'provider'=>$this],['query','context','provider']); return $value instanceof PanelEditorAssetPage ? $value : (is_array($value)?PanelEditorAssetPage::fromArray(array_is_list($value)?['assets'=>$value]:$value):new PanelEditorAssetPage()); }catch(\Throwable){ return new PanelEditorAssetPage(); }
	}
	public function findAsset(string $id, PanelEditorContext $context): ?PanelEditorAsset {
		try{ $id=PanelEditorAsset::normalizeId($id); }catch(\Throwable){ return null; }
		if(!$this->ready() || !$this->authorized('read',$context,['id'=>$id])){ return null; }
		try{ $value=PanelUtilityResolver::evaluate($this->finder,['id'=>$id,'context'=>$context,'provider'=>$this],['id','context','provider']); return $this->asset($value); }catch(\Throwable){ return null; }
	}
	public function storeAsset(array $upload, PanelEditorContext $context): PanelEditorAssetResult {
		$validation=$this->validateUpload($upload,$context);
		if(!$validation->valid()){ return PanelEditorAssetResult::failure('upload_invalid',implode(' ',$validation->errors()),422); }
		if(!$this->ready() || !$this->uploads || $this->storer===null || !$this->authorized('upload',$context,['upload'=>$upload])){ return PanelEditorAssetResult::failure('operation_denied','The editor asset operation is not available.',403); }
		try{ $value=PanelUtilityResolver::evaluate($this->storer,['upload'=>$upload,'context'=>$context,'provider'=>$this,'validation'=>$validation],['upload','context','provider','validation']); return $this->result($value,'uploaded','Editor asset uploaded.'); }catch(\Throwable){ return PanelEditorAssetResult::failure('upload_failed','The editor asset upload failed.',422); }
	}
	public function deleteAsset(string $id, PanelEditorContext $context): PanelEditorAssetResult {
		$asset=$this->findAsset($id,$context);
		if($asset===null){ return PanelEditorAssetResult::failure('asset_not_found','The editor asset was not found.',404); }
		if(!$this->deletes || $this->deleter===null || !$this->authorized('delete',$context,['id'=>$asset->id(),'asset'=>$asset])){ return PanelEditorAssetResult::failure('operation_denied','The editor asset operation is not available.',403); }
		try{ $value=PanelUtilityResolver::evaluate($this->deleter,['id'=>$asset->id(),'asset'=>$asset,'context'=>$context,'provider'=>$this],['id','asset','context','provider']); if($value===true){ return PanelEditorAssetResult::success('deleted','Editor asset deleted.'); } return $value instanceof PanelEditorAssetResult ? $value : (is_array($value)?PanelEditorAssetResult::fromArray($value):PanelEditorAssetResult::failure('delete_failed','The editor asset could not be deleted.',422)); }catch(\Throwable){ return PanelEditorAssetResult::failure('delete_failed','The editor asset could not be deleted.',422); }
	}
	public function delivery(string $id, PanelEditorContext $context): PanelEditorAssetResult {
		$asset=$this->findAsset($id,$context);
		if($asset===null){ return PanelEditorAssetResult::failure('asset_not_found','The editor asset was not found.',404); }
		if(!$this->authorized('delivery',$context,['id'=>$asset->id(),'asset'=>$asset])){ return PanelEditorAssetResult::failure('operation_denied','The editor asset operation is not available.',403); }
		if($this->deliverer===null){ return PanelEditorAssetResult::success('delivery_ready','Editor asset delivery is ready.',$asset,null,['url'=>$asset->url()]); }
		try{ $value=PanelUtilityResolver::evaluate($this->deliverer,['id'=>$asset->id(),'asset'=>$asset,'context'=>$context,'provider'=>$this],['id','asset','context','provider']); return $this->result($value,'delivery_ready','Editor asset delivery is ready.',$asset); }catch(\Throwable){ return PanelEditorAssetResult::failure('delivery_failed','The editor asset delivery could not be prepared.',422); }
	}
	public function normalizeReference(string $url, PanelEditorContext $context): ?string {
		if(!$this->ready() || $this->normalizer===null || !$this->authorized('normalize',$context,['url'=>$url])){ return null; }
		try{ $value=PanelUtilityResolver::evaluate($this->normalizer,['url'=>$url,'context'=>$context,'provider'=>$this],['url','context','provider']); return is_string($value)&&trim($value)!=='' ? PanelEditorAsset::normalizeUrl($value) : null; }catch(\Throwable){ return null; }
	}
	public function manifest(): array {
		$upload=$this->validator->manifest();
		return PanelEditorManifest::sanitize([
			'type'=>'panel_editor_asset_provider','schema_version'=>1,'name'=>$this->providerName,'provider'=>$this->providerType,
			'accepted'=>$upload['accepted']??[],'max_bytes'=>$upload['max_bytes']??0,'enabled'=>$this->enabled,'ready'=>$this->ready(),
			'capabilities'=>['browse'=>$this->browser!==null,'read'=>$this->finder!==null,'upload'=>$this->uploads&&$this->storer!==null,'delete'=>$this->deletes&&$this->deleter!==null,'delivery'=>true,'canonical_references'=>$this->normalizer!==null],
			'browser'=>['schema_version'=>1,'driver'=>$this->browserDriver,'endpoint'=>$this->endpoint,'request_verification_required'=>$this->csrfRequired,'verification_field'=>$this->csrfField,'verification_header'=>$this->csrfHeader,'credentials'=>'same-origin'],
			'runtime'=>['authorization'=>$this->authorizer!==null,'browse'=>$this->browser!==null,'find'=>$this->finder!==null,'store'=>$this->storer!==null,'delete'=>$this->deleter!==null,'delivery'=>$this->deliverer!==null,'normalize'=>$this->normalizer!==null],
		]);
	}
	public function jsonSerialize(): array { return $this->manifest(); }

	private function authorized(string $operation,PanelEditorContext $context,array $values=[]): bool { if($this->authorizer===null){return false;} try{return PanelUtilityResolver::evaluate($this->authorizer,['operation'=>$operation,'context'=>$context,'provider'=>$this]+$values,['operation','context'])===true;}catch(\Throwable){return false;} }
	private function asset(mixed $value): ?PanelEditorAsset { if($value instanceof PanelEditorAsset){return $value;} if(!is_array($value)){return null;} try{return PanelEditorAsset::fromArray($value);}catch(\Throwable){return null;} }
	private function result(mixed $value,string $code,string $message,?PanelEditorAsset $fallback=null): PanelEditorAssetResult { if($value instanceof PanelEditorAssetResult){return $value;} $asset=$this->asset($value); if($asset!==null){return PanelEditorAssetResult::success($code,$message,$asset);} if(is_array($value)&&(array_key_exists('ok',$value)||($value['type']??null)==='panel_editor_asset_result')){try{return PanelEditorAssetResult::fromArray($value);}catch(\Throwable){}} return $fallback!==null?PanelEditorAssetResult::success($code,$message,$fallback,null,is_array($value)?$value:[]):PanelEditorAssetResult::failure('provider_invalid','The editor asset provider returned an invalid response.',502); }
}
