<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Truthful, bounded capability inventory for inclusive-quality executors. */
final class PanelQualityCapabilityReport implements \JsonSerializable {
	private const MAX_CAPABILITIES=128;
	private const STATUSES=['available','unavailable','declared'];
	private const EXECUTIONS=['runtime','php','browser','adapter','manual'];

	/** @param array<string,array{status:string,execution:string,source:?string,version:?string}> $capabilities */
	private function __construct(private readonly array $capabilities) {}

	/**
	 * Detects PHP and host capabilities and merges explicit runner reports.
	 * Explicit entries must identify their evidence source; a bare boolean is
	 * intentionally rejected because it cannot establish how a capability was observed.
	 *
	 * @param array<string,mixed> $reported
	 */
	public static function detect(array $reported=[]): self {
		$family=PHP_OS_FAMILY;
		$capabilities=[
			'php.json'=>self::entry('available','php','php-extension',PHP_VERSION),
			'php.intl'=>self::entry(extension_loaded('intl')?'available':'unavailable','php','php-extension',PHP_VERSION),
			'runtime.timezone'=>self::entry(class_exists(\DateTimeZone::class)?'available':'unavailable','runtime','php-runtime',PHP_VERSION),
			'os.windows'=>self::entry($family==='Windows'?'available':'unavailable','runtime','php-runtime',$family),
			'os.linux'=>self::entry($family==='Linux'?'available':'unavailable','runtime','php-runtime',$family),
			'os.macos'=>self::entry($family==='Darwin'?'available':'unavailable','runtime','php-runtime',$family),
		];
		foreach($reported as $id=>$definition){
			$id=self::id((string)$id);
			if($id==='' || isset($capabilities[$id]) && !is_array($definition)){
				throw new \InvalidArgumentException('Panel quality capability ids and definitions must be stable objects.');
			}
			if(!is_array($definition)){
				throw new \InvalidArgumentException("Panel quality capability '{$id}' must include status, execution, and source evidence.");
			}
			$status=strtolower(trim((string)($definition['status'] ?? '')));
			$execution=strtolower(trim((string)($definition['execution'] ?? '')));
			$source=self::text($definition['source'] ?? null, 512, true);
			$version=self::text($definition['version'] ?? null, 256, true);
			if(!in_array($status,self::STATUSES,true) || !in_array($execution,self::EXECUTIONS,true)){
				throw new \InvalidArgumentException("Panel quality capability '{$id}' has an invalid status or execution channel.");
			}
			if(in_array($status,['available','declared'],true) && $source===null){
				throw new \InvalidArgumentException("Panel quality capability '{$id}' requires an evidence source.");
			}
			$capabilities[$id]=self::entry($status,$execution,$source,$version);
		}
		if(count($capabilities)>self::MAX_CAPABILITIES){ throw new \LengthException('Panel quality capability reports support at most 128 entries.'); }
		ksort($capabilities,SORT_STRING);
		return new self($capabilities);
	}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload): self {
		$reported=is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $payload;
		return self::detect($reported);
	}

	public function supports(string $id, ?string $execution=null): bool {
		$id=self::id($id);
		$entry=$this->capabilities[$id] ?? null;
		if(!is_array($entry) || $entry['status']!=='available'){ return false; }
		return $execution===null || $entry['execution']===strtolower(trim($execution));
	}

	/** @return array{status:string,execution:string,source:?string,version:?string}|null */
	public function capability(string $id): ?array { return $this->capabilities[self::id($id)] ?? null; }

	/** @return array<string,array{status:string,execution:string,source:?string,version:?string}> */
	public function capabilities(): array { return $this->capabilities; }

	public function fingerprint(): string { return hash('sha256',json_encode($this->capabilities,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return ['type'=>'panel_quality_capability_report','version'=>1,'fingerprint'=>$this->fingerprint(),'capabilities'=>$this->capabilities]; }

	/** @return array{status:string,execution:string,source:?string,version:?string} */
	private static function entry(string $status,string $execution,?string $source,?string $version): array { return ['status'=>$status,'execution'=>$execution,'source'=>$source,'version'=>$version]; }

	private static function id(string $value): string {
		$value=strtolower(trim($value));
		return preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D',$value)===1 ? $value : '';
	}

	private static function text(mixed $value,int $maximum,bool $nullable=false): ?string {
		if($value===null && $nullable){ return null; }
		if(!is_scalar($value)){ throw new \InvalidArgumentException('Panel quality capability text must be scalar.'); }
		$value=trim((string)$value);
		if($value==='' && $nullable){ return null; }
		if($value==='' || preg_match('//u',$value)!==1 || strlen($value)>$maximum || preg_match('/[\x00-\x1F\x7F]/',$value)===1){ throw new \InvalidArgumentException('Panel quality capability text is invalid or exceeds its budget.'); }
		return $value;
	}
}
