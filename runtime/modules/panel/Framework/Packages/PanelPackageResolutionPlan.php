<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable deterministic dependency and update resolution result. */
final class PanelPackageResolutionPlan implements \JsonSerializable {

	private bool $ok;
	private string $registry;
	private int $sequence;
	private string $indexDigest;
	private array $selected;
	private array $steps;
	private array $errors;
	private array $meta;
	private string $checksum;

	/** @param array<string,mixed> $data Resolver output. */
	public function __construct(array $data=[]) {
		$this->registry=(string)($data['registry'] ?? '');
		$this->sequence=max(0, (int)($data['sequence'] ?? 0));
		$this->indexDigest=(string)($data['index_digest'] ?? '');
		$this->selected=is_array($data['selected'] ?? null) ? $data['selected'] : [];
		$this->steps=array_values(array_filter((array)($data['steps'] ?? []), 'is_array'));
		$this->errors=array_values(array_filter(array_map(static fn(mixed $error): string=>trim((string)$error), (array)($data['errors'] ?? [])), static fn(string $error): bool=>$error!==''));
		$this->meta=is_array($data['meta'] ?? null) ? $this->sanitize($data['meta']) : [];
		ksort($this->selected, SORT_STRING);
		$lock=$this->safeSelected();
		$this->checksum=hash('sha256', self::canonicalJson([
			'registry'=>$this->registry,'sequence'=>$this->sequence,'index_digest'=>$this->indexDigest,'packages'=>$lock,
		]));
		$this->ok=(bool)($data['ok'] ?? false) && $this->errors===[] && $this->registry!=='' && $this->indexDigest!=='';
	}

	public static function make(array $data=[]): self { return new self($data); }
	public function ok(): bool { return $this->ok; }
	public function registry(): string { return $this->registry; }
	public function sequence(): int { return $this->sequence; }
	public function indexDigest(): string { return $this->indexDigest; }
	/** @return array<string,array<string,mixed>> Trusted entries including opaque locators. */
	public function selected(): array { return $this->selected; }
	/** @return array<int,array<string,mixed>> */
	public function steps(): array { return $this->steps; }
	/** @return array<int,string> */
	public function errors(): array { return $this->errors; }
	public function checksum(): string { return $this->checksum; }

	/** @return array<string,mixed> Deterministic CI lock/update manifest without transport locators. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_resolution_plan',
			'ok'=>$this->ok,
			'registry'=>$this->registry,
			'sequence'=>$this->sequence,
			'index_digest'=>$this->indexDigest,
			'checksum'=>$this->checksum,
			'package_count'=>count($this->selected),
			'packages'=>$this->safeSelected(),
			'steps'=>$this->sanitize($this->steps),
			'errors'=>$this->errors,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private function safeSelected(): array {
		$safe=[];
		foreach($this->selected as $id=>$entry){
			if(!is_array($entry)){continue;}
			$row=$entry;
			$locator=(string)($row['artifact']['locator'] ?? '');
			unset($row['artifact']['locator'], $row['transparency']);
			$row['artifact']['locator_digest']=$locator!=='' ? hash('sha256', $locator) : '';
			$safe[(string)$id]=$this->sanitize($row);
		}
		ksort($safe, SORT_STRING);
		return $safe;
	}

	private static function canonicalJson(mixed $value): string {
		return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private static function canonicalize(mixed $value): mixed {
		if(is_array($value)){
			if(!array_is_list($value)){ksort($value, SORT_STRING);}
			foreach($value as $key=>$item){$value[$key]=self::canonicalize($item);}
		}
		return $value;
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && $this->sensitiveKey($key)){return '[REDACTED]';}
		if(!is_array($value)){return is_object($value) ? '[OBJECT]' : $value;}
		$safe=[];
		foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}
		return $safe;
	}

	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i', $key)===1;
	}
}
