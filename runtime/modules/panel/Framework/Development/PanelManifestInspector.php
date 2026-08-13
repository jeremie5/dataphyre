<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deep manifest inspector for duplicate identities, unsafe values, and stable hashes. */
final class PanelManifestInspector implements \JsonSerializable {
	private array $manifest;
	private array $issues=[];
	private array $metrics=['nodes'=>0, 'arrays'=>0, 'scalars'=>0, 'maximum_depth'=>0, 'callables'=>0, 'objects'=>0];
	private array $capabilities=[];

	private function __construct(mixed $source) { $this->manifest=$this->normalize($source); $this->walk($this->manifest, '', 0); $this->auditCollections($this->manifest); }
	public static function inspect(mixed $source): self { return new self($source); }
	public function passed(): bool { return !array_filter($this->issues, static fn(array $issue): bool => $issue['severity']==='error'); }
	public function issues(?string $severity=null): array { return $severity===null ? $this->issues : array_values(array_filter($this->issues, static fn(array $issue): bool => $issue['severity']===$severity)); }
	public function metrics(): array { return $this->metrics; }
	public function hash(): string { $copy=$this->manifest; self::sort($copy); return hash('sha256', json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
	public function jsonSerialize(): array { return ['type'=>'panel_manifest_inspection', 'passed'=>$this->passed(), 'hash'=>$this->hash(), 'metrics'=>$this->metrics, 'capabilities'=>array_values(array_unique($this->capabilities)), 'issues'=>$this->issues, 'manifest'=>$this->manifest]; }

	private function normalize(mixed $source): array {
		if(is_array($source)){ return $source; }
		if($source instanceof \JsonSerializable){ $value=$source->jsonSerialize(); return is_array($value) ? $value : ['value'=>$value]; }
		if(is_object($source) && method_exists($source, 'toArray')){ $value=$source->toArray(); return is_array($value) ? $value : ['value'=>$value]; }
		throw new \InvalidArgumentException('Panel manifest inspection requires an array, JsonSerializable object, or toArray() object.');
	}

	private function walk(mixed $value, string $path, int $depth): void {
		$this->metrics['nodes']++; $this->metrics['maximum_depth']=max($this->metrics['maximum_depth'], $depth);
		if(is_array($value)){
			$this->metrics['arrays']++;
			foreach($value as $key=>$item){ $child=$path==='' ? (string)$key : $path.'.'.$key; if($key==='capabilities' && is_array($item)){ $this->collectCapabilities($item); } $this->walk($item, $child, $depth+1); }
			return;
		}
		if(is_callable($value)){ $this->metrics['callables']++; $this->issue('warning', 'runtime_callable', $path, 'Manifest contains a runtime-only callable.'); return; }
		if(is_object($value) || is_resource($value)){ $this->metrics['objects']++; $this->issue('error', 'unserializable_value', $path, 'Manifest contains an unserializable value.'); return; }
		$this->metrics['scalars']++;
		if(is_string($value) && preg_match('/(?:password|secret|token|private[_-]?key)\s*[:=]/i', $value)===1){ $this->issue('error', 'possible_secret', $path, 'Manifest appears to contain secret material.'); }
		if(is_string($value) && str_contains(strtolower($path), 'url') && preg_match('/^javascript:/i', trim($value))===1){ $this->issue('error', 'unsafe_url', $path, 'Manifest URL uses an unsafe scheme.'); }
	}

	private function auditCollections(array $manifest): void {
		foreach(['resources', 'pages', 'plugins', 'widgets', 'themes', 'navigation', 'actions'] as $collection){
			$items=$manifest[$collection] ?? null;
			if(!is_array($items)){ continue; }
			$seen=[];
			foreach($items as $index=>$item){
				if(!is_array($item)){ continue; }
				$name=trim((string)($item['name'] ?? $item['id'] ?? $item['slug'] ?? ''));
				if($name===''){ $this->issue('warning', 'missing_identity', $collection.'.'.$index, 'Manifest entry has no stable name, id, or slug.'); continue; }
				if(isset($seen[$name])){ $this->issue('error', 'duplicate_identity', $collection.'.'.$index, 'Duplicate '.$collection.' identity: '.$name); }
				$seen[$name]=true;
			}
		}
	}

	private function collectCapabilities(array $capabilities, string $prefix=''): void { foreach($capabilities as $key=>$value){ $name=$prefix==='' ? (string)$key : $prefix.'.'.$key; if(is_array($value)){ $this->collectCapabilities($value, $name); } elseif($value===true || (is_numeric($value) && $value>0)){ $this->capabilities[]=$name; } } }
	private function issue(string $severity, string $rule, string $path, string $message): void { $this->issues[]=['severity'=>$severity, 'rule'=>$rule, 'path'=>$path, 'message'=>$message]; }
	private static function sort(array &$value): void { if(!array_is_list($value)){ ksort($value); } foreach($value as &$item){ if(is_array($item)){ self::sort($item); } } }
}
