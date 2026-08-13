<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable record-key, projected fields, labels, and semantic slot mapping. */
final class PanelDataSurfaceProjection implements \JsonSerializable {
	private const SLOTS=['title','description','image','alt','time','start','end','badge','meta','parent','source','target','row','column','value','group','progress','latitude','longitude','x','y','width','height','color','cross_filter'];

	/** @param list<string> $fields @param array<string,string> $slots @param array<string,string> $labels */
	private function __construct(
		private readonly array $fields,
		private readonly string $stableKey,
		private readonly array $slots,
		private readonly array $labels
	){}

	/** @param list<string> $fields @param array<string,string> $slots @param array<string,string> $labels */
	public static function make(array $fields, string $stableKey='id', array $slots=[], array $labels=[]): self {
		$stableKey=PanelDataSurfaceGuard::field($stableKey);
		$normalized=[];
		foreach($fields as $field){ $normalized[]=PanelDataSurfaceGuard::field((string)$field); }
		$normalized=array_values(array_unique($normalized));
		if(!in_array($stableKey, $normalized, true)){ array_unshift($normalized, $stableKey); }
		if($normalized===[] || count($normalized)>64){ throw new \LengthException('Panel DataSurface projections require 1 to 64 fields.'); }
		$slotMap=[];
		foreach($slots as $slot=>$field){
			$slot=strtolower(trim((string)$slot));
			if(!in_array($slot, self::SLOTS, true)){ throw new \InvalidArgumentException("Unsupported Panel DataSurface projection slot '{$slot}'."); }
			$field=PanelDataSurfaceGuard::field((string)$field);
			if(!in_array($field, $normalized, true)){ throw new \InvalidArgumentException("Panel DataSurface slot '{$slot}' references an unprojected field."); }
			$slotMap[$slot]=$field;
		}
		$labelMap=[];
		foreach($labels as $field=>$label){
			$field=PanelDataSurfaceGuard::field((string)$field);
			if(!in_array($field, $normalized, true)){ throw new \InvalidArgumentException('Panel DataSurface labels may only describe projected fields.'); }
			$labelMap[$field]=PanelDataSurfaceGuard::boundedString($label, 'field label', 128);
		}
		return new self($normalized, $stableKey, $slotMap, $labelMap);
	}

	/** @param array<string,mixed> $value */
	public static function fromArray(array $value): self {
		return self::make(
			is_array($value['fields'] ?? null) ? array_values($value['fields']) : [],
			(string)($value['stable_key'] ?? 'id'),
			is_array($value['slots'] ?? null) ? $value['slots'] : [],
			is_array($value['labels'] ?? null) ? $value['labels'] : []
		);
	}

	/** @return list<string> */ public function fields(): array { return $this->fields; }
	public function stableKey(): string { return $this->stableKey; }
	/** @return array<string,string> */ public function slots(): array { return $this->slots; }
	/** @return array<string,string> */ public function labels(): array { return $this->labels; }
	public function slot(string $name): ?string { return $this->slots[strtolower(trim($name))] ?? null; }
	public function label(string $field): string {
		$field=PanelDataSurfaceGuard::field($field);
		if(isset($this->labels[$field])){ return $this->labels[$field]; }
		$segments=explode('.', $field);
		$leaf=(string)$segments[array_key_last($segments)];
		return ucwords(str_replace('_', ' ', $leaf));
	}

	/** @return array{key:string,data:array<string,mixed>} */
	public function project(mixed $record): array {
		if($record instanceof \JsonSerializable){ $record=$record->jsonSerialize(); }
		elseif(is_object($record)){ $record=get_object_vars($record); }
		if(!is_array($record) || array_is_list($record)){ throw new \UnexpectedValueException('Panel DataSurface records must be object-like arrays or objects.'); }
		[$present,$key]=$this->path($record, $this->stableKey);
		if(!$present || (!is_string($key) && !is_int($key))){ throw new \UnexpectedValueException("Panel DataSurface records require scalar stable key '{$this->stableKey}'."); }
		$key=PanelDataSurfaceGuard::boundedString($key, 'record key', 256);
		$data=[];
		foreach($this->fields as $field){
			[$exists,$value]=$this->path($record, $field);
			$value=$exists ? $value : null;
			PanelDataSurfaceGuard::assertJson($value, PanelDataSurfaceGuard::MAX_STRING_BYTES);
			$data[$field]=$value;
		}
		return ['key'=>$key,'data'=>$data];
	}

	public function fingerprint(): string {
		return hash('sha256', "panel-data-surface-projection-v1\0".PanelDataSurfaceGuard::canonicalJson($this->jsonSerialize()));
	}

	public function jsonSerialize(): array {
		return ['fields'=>$this->fields,'stable_key'=>$this->stableKey,'slots'=>$this->slots,'labels'=>$this->labels];
	}

	/** @return array{bool,mixed} */
	private function path(array $record, string $path): array {
		$value=$record;
		foreach(explode('.', $path) as $segment){
			if(!is_array($value) || !array_key_exists($segment, $value)){ return [false,null]; }
			$value=$value[$segment];
		}
		return [true,$value];
	}
}
