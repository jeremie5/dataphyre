<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Verified remote/cached registry load result without serialized raw bytes. */
final class PanelPackageRegistryLoadResult implements \JsonSerializable {

	private ?PanelPackageRegistryIndex $index;
	private string $body;
	private string $source;
	private array $errors;
	private array $meta;
	private bool $ok;

	public function __construct(?PanelPackageRegistryIndex $index=null, string $body='', string $source='', array $errors=[], array $meta=[]) {
		$this->index=$index;
		$this->body=$body;
		$this->source=in_array($source, ['transport','cache'], true) ? $source : '';
		$this->errors=array_values(array_filter(array_map(static fn(mixed $error): string=>trim((string)$error), $errors), static fn(string $error): bool=>$error!==''));
		$this->meta=$this->sanitize($meta);
		if($index instanceof PanelPackageRegistryIndex && !$index->ok()){$this->errors=array_merge($this->errors, $index->errors());}
		if($index instanceof PanelPackageRegistryIndex && !$this->bodyMatchesIndex($body, $index)){$this->errors[]='Registry result bytes do not match the verified index envelope.';}
		$this->errors=array_values(array_unique($this->errors));
		$this->ok=$index instanceof PanelPackageRegistryIndex && $index->ok() && $body!=='' && $this->source!=='' && $this->errors===[];
	}

	public static function make(?PanelPackageRegistryIndex $index=null, string $body='', string $source='', array $errors=[], array $meta=[]): self {
		return new self($index, $body, $source, $errors, $meta);
	}

	public function ok(): bool { return $this->ok; }
	public function index(): ?PanelPackageRegistryIndex { return $this->ok ? $this->index : null; }
	/** Signed index bytes for explicit host-owned persistence. Never serialized. */
	public function body(): ?string { return $this->ok ? $this->body : null; }
	/** @return array<int,string> */
	public function errors(): array { return $this->errors; }

	/** @return array<string,mixed> Safe CI manifest without locators or raw bytes. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_registry_load_result','ok'=>$this->ok,'source'=>$this->source,
			'bytes'=>$this->body!=='' ? strlen($this->body) : 0,
			'body_sha256'=>$this->body!=='' ? hash('sha256', $this->body) : '',
			'index'=>$this->index instanceof PanelPackageRegistryIndex ? $this->index->toArray() : null,
			'errors'=>$this->errors,'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private function bodyMatchesIndex(string $body, PanelPackageRegistryIndex $index): bool {
		if($body===''){return false;}
		try{$decoded=json_decode($body, true, 128, JSON_THROW_ON_ERROR);}
		catch(\Throwable){return false;}
		if(!is_array($decoded)){return false;}
		try{$canonical=json_encode($this->canonicalize($decoded), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
		catch(\Throwable){return false;}
		return hash_equals($index->envelopeDigest(), hash('sha256', $canonical));
	}

	private function canonicalize(mixed $value): mixed {
		if(!is_array($value)){return $value;}
		if(!array_is_list($value)){ksort($value, SORT_STRING);}
		foreach($value as $key=>$item){$value[$key]=$this->canonicalize($item);}
		return $value;
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && $this->sensitiveKey($key)){return '[REDACTED]';}
		if(!is_array($value)){return is_object($value) ? '[OBJECT]' : $value;}
		$safe=[];foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}return $safe;
	}

	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i', $key)===1;
	}
}
