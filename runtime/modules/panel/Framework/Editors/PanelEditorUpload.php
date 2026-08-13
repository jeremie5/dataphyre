<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Declarative upload boundary for editor media pickers. */
final class PanelEditorUpload implements PanelEditorUploadAdapter, \JsonSerializable {
	private const DANGEROUS_EXTENSIONS=['bat','cgi','cmd','com','exe','htm','html','js','mjs','phar','php','phtml','pl','ps1','svg'];
	private const DANGEROUS_MIME_TYPES=['application/javascript','application/x-httpd-php','image/svg+xml','text/html','text/javascript'];
	/** @var list<string> */
	private array $accepted=[];
	private int $maxBytes=10485760;
	private ?\Closure $detector=null;
	private bool $enabled=true;

	private function __construct(private string $adapterName, private string $endpoint) {
		$this->adapterName=PanelEditorManifest::name($adapterName, 'upload');
		$this->endpoint=self::endpoint($endpoint);
	}
	public static function make(string $name, string $endpoint): self { return new self($name, $endpoint); }
	public static function fromArray(array $definition): self {
		$upload=self::make((string)($definition['name'] ?? 'upload'), (string)($definition['endpoint'] ?? ''))
			->accept(is_array($definition['accepted'] ?? null) ? $definition['accepted'] : [])
			->maxBytes((int)($definition['max_bytes'] ?? 10485760))
			->enabled((bool)($definition['enabled'] ?? true));
		return ($definition['runtime'] ?? '')==='detected' ? $upload->enabled(false) : $upload;
	}
	public function accept(array|string $types): self {
		$clone=clone $this;
		foreach((array)$types as $type){
			$type=strtolower(trim((string)$type));
			if(preg_match('/^(?:[a-z0-9.+-]+\/[a-z0-9.+*-]+|\.[a-z0-9]+)$/', $type)===1 && !in_array($type, $clone->accepted, true)){ $clone->accepted[]=$type; }
		}
		return $clone;
	}
	public function maxBytes(int $bytes): self { $clone=clone $this; $clone->maxBytes=max(1, $bytes); return $clone; }
	public function enabled(bool $enabled=true): self { $clone=clone $this; $clone->enabled=$enabled; return $clone; }
	public function detectUsing(callable $detector): self { $clone=clone $this; $clone->detector=\Closure::fromCallable($detector); return $clone; }
	public function name(): string { return $this->adapterName; }
	public function ready(): bool { return $this->enabled && $this->adapterName!=='' && $this->endpoint!=='' && $this->accepted!==[]; }
	public function validateUpload(array $upload, PanelEditorContext $context): PanelEditorContentResult {
		$errors=[];
		$rawName=(string)($upload['name'] ?? '');
		$name=basename(str_replace('\\', '/', $rawName));
		$size=(int)($upload['size'] ?? -1);
		$error=(int)($upload['error'] ?? 0);
		$type=''; $trustedMime=false;
		$tmpName=is_string($upload['tmp_name'] ?? null) ? trim((string)$upload['tmp_name']) : '';
		$hasTmpFile=$tmpName!=='' && is_file($tmpName);
		if(!$this->ready()){ $errors[]='The editor upload adapter is not ready.'; }
		if($error!==0){ $errors[]='The editor upload did not complete successfully.'; }
		if($name==='' || $name==='.' || $name==='..' || $name!==$rawName || preg_match('/[\x00-\x1f\x7f]/', $name)===1){ $errors[]='The editor upload filename is invalid.'; }
		$extension=strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if(in_array($extension, self::DANGEROUS_EXTENSIONS, true)){ $errors[]='The editor upload file type is unsafe.'; }
		if(array_key_exists('tmp_name', $upload) && !$hasTmpFile){ $errors[]='The editor upload temporary file is unavailable.'; }
		if($hasTmpFile){
			$actualSize=filesize($tmpName);
			if($actualSize===false){ $errors[]='The editor upload size could not be verified.'; }
			else{ $size=$actualSize; }
		}
		if($size<0 || $size>$this->maxBytes){ $errors[]='The editor upload exceeds its size policy.'; }
		if($this->detector!==null){
			$detected=PanelUtilityResolver::evaluate($this->detector, ['upload'=>$upload, 'context'=>$context], ['upload','context']);
			if(is_string($detected) && self::validMime($detected)){ $type=strtolower(trim($detected)); $trustedMime=true; }
			else{ $errors[]='The editor upload MIME type could not be verified.'; }
		}
		elseif($hasTmpFile && class_exists(\finfo::class)){
			$detected=(new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
			if(is_string($detected) && self::validMime($detected)){ $type=strtolower(trim($detected)); $trustedMime=true; }
		}
		if($hasTmpFile && $this->detector===null && !$trustedMime){ $errors[]='The editor upload MIME type could not be verified server-side.'; }
		if($trustedMime && in_array($type, self::DANGEROUS_MIME_TYPES, true)){ $errors[]='The editor upload MIME type is unsafe.'; }
		if($this->accepted!==[] && !$this->accepts($type, $extension, $trustedMime)){ $errors[]='The editor upload type is not allowed.'; }
		return $errors===[] ? PanelEditorContentResult::accept($name, false, [], ['mime'=>$type, 'size'=>$size]) : PanelEditorContentResult::reject($name, $errors);
	}
	public function manifest(): array { return ['name'=>$this->name(), 'endpoint'=>$this->endpoint, 'accepted'=>$this->accepted, 'max_bytes'=>$this->maxBytes, 'enabled'=>$this->enabled, 'ready'=>$this->ready(), 'runtime'=>$this->detector!==null ? 'detected' : 'declared']; }
	public function jsonSerialize(): array { return $this->manifest(); }
	private function accepts(string $mime, string $extension, bool $trustedMime): bool {
		foreach($this->accepted as $accepted){
			if(str_starts_with($accepted, '.')){ if($extension!=='' && $accepted==='.'.ltrim($extension, '.')){ return true; } continue; }
			if(!$trustedMime){ continue; }
			if(str_ends_with($accepted, '/*')){ if(str_starts_with($mime, substr($accepted, 0, -1))){ return true; } continue; }
			if($mime===$accepted){ return true; }
		}
		return false;
	}
	private static function endpoint(string $endpoint): string {
		$endpoint=trim($endpoint);
		if($endpoint==='' || str_contains($endpoint, '\\') || preg_match('/[\x00-\x20\x7f]/', $endpoint)===1){ return ''; }
		$parts=parse_url($endpoint);
		if(!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])){ return ''; }
		$path=self::decoded((string)($parts['path'] ?? ''));
		if($path===null || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)===1){ return ''; }
		if(isset($parts['query'])){
			parse_str((string)$parts['query'], $parameters);
			if(self::sensitiveQuery($parameters)){ return ''; }
		}
		if(str_starts_with($endpoint, '/') && !str_starts_with($endpoint, '//') && !isset($parts['scheme']) && !isset($parts['host'])){ return $endpoint; }
		return strtolower((string)($parts['scheme'] ?? ''))==='https' && trim((string)($parts['host'] ?? ''))!=='' ? $endpoint : '';
	}
	private static function validMime(string $mime): bool { return preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/', strtolower(trim($mime)))===1; }
	private static function sensitiveQuery(array $parameters): bool {
		foreach($parameters as $key=>$value){
			$key=self::decoded((string)$key);
			if($key===null || preg_match('/(?:secret|token|password|authorization|credential|api[_-]?key|access[_-]?key)/i', $key)===1){ return true; }
			if(is_array($value) && self::sensitiveQuery($value)){ return true; }
		}
		return false;
	}
	private static function decoded(string $value): ?string {
		for($pass=0;$pass<4;$pass++){
			$decoded=rawurldecode($value);
			if($decoded===$value){ return $value; }
			$value=$decoded;
		}
		return rawurldecode($value)===$value ? $value : null;
	}
}
