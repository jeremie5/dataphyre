<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Exact server-owned capability contract echoed by every upstream response. */
final class PanelHttpDataSourceCapabilityPin implements \JsonSerializable {
	private const KEYS=[
		'adapter','filters','query_expression','expression_version','operators','groups','relations','relation_depth',
		'sorts','sort_nulls','legacy_filters','search','select','include','cursor','offset','aggregates','tenant',
		'authorization','find','stable_record_keys','record_key_field','max_limit','count_total','cursor_previous','mutations',
	];
	/** @var array<string,mixed> */
	private readonly array $capabilities;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $capabilities */
	private function __construct(private readonly int $version, array $capabilities){
		if($version<1 || $version>1000000){ throw new \InvalidArgumentException('Remote capability versions must be between 1 and 1000000.'); }
		PanelHttpDataSourceValue::exactKeys($capabilities, self::KEYS, 'Remote capability pin');
		if(($capabilities['adapter'] ?? null)!=='http_remote'){ throw new \InvalidArgumentException('Remote capability pins require the http_remote adapter identity.'); }
		foreach(['filters','query_expression','relations','sorts','legacy_filters','search','select','include','cursor','offset','aggregates','tenant','authorization','find','stable_record_keys','count_total','cursor_previous','mutations'] as $key){
			if(!is_bool($capabilities[$key])){ throw new \InvalidArgumentException("Remote capability '{$key}' must be boolean."); }
		}
		if($capabilities['stable_record_keys']!==true || $capabilities['mutations']!==false){ throw new \InvalidArgumentException('Remote data sources require stable keys and must remain read-only.'); }
		if(!is_int($capabilities['expression_version']) || !in_array($capabilities['expression_version'], [1,2], true)){ throw new \InvalidArgumentException('Remote expression_version must be 1 or 2.'); }
		if($capabilities['query_expression'] && $capabilities['expression_version']!==2){ throw new \InvalidArgumentException('Remote expression AST support requires expression version 2.'); }
		if(!is_int($capabilities['relation_depth']) || $capabilities['relation_depth']<0 || $capabilities['relation_depth']>16){ throw new \InvalidArgumentException('Remote relation_depth must be between 0 and 16.'); }
		if(!$capabilities['relations'] && $capabilities['relation_depth']!==0){ throw new \InvalidArgumentException('Remote relation depth requires relation support.'); }
		if(!is_int($capabilities['max_limit']) || $capabilities['max_limit']<1 || $capabilities['max_limit']>10000){ throw new \InvalidArgumentException('Remote max_limit must be between 1 and 10000.'); }
		$recordKey=$capabilities['record_key_field'];
		if(!is_string($recordKey) || PanelQueryPath::make($recordKey)->value()!==$recordKey){ throw new \InvalidArgumentException('Remote record_key_field must be a normalized Panel query path.'); }
		$capabilities['operators']=self::names($capabilities['operators'], PanelQueryCapabilities::OPERATORS, 'operators');
		$capabilities['groups']=self::names($capabilities['groups'], ['and','or'], 'groups');
		$capabilities['sort_nulls']=self::names($capabilities['sort_nulls'], ['native','first','last'], 'sort_nulls');
		if(!$capabilities['filters'] && $capabilities['operators']!==[]){ throw new \InvalidArgumentException('Remote operators require filter support.'); }
		if(!$capabilities['query_expression'] && $capabilities['groups']!==[]){ throw new \InvalidArgumentException('Remote expression groups require expression support.'); }
		$this->capabilities=$capabilities;
		$this->fingerprint=hash('sha256', PanelHttpDataSourceValue::canonical(['version'=>$version,'capabilities'=>$capabilities]));
	}

	/** @param array<string,mixed> $overrides */
	public static function readOnly(string $recordKeyField='id', array $overrides=[], int $version=1): self {
		$unknown=array_values(array_diff(array_keys($overrides), self::KEYS));
		if($unknown!==[]){ throw new \InvalidArgumentException('Unknown remote capability: '.(string)$unknown[0]); }
		$capabilities=[
			'adapter'=>'http_remote', 'filters'=>true, 'query_expression'=>true, 'expression_version'=>2,
			'operators'=>PanelQueryCapabilities::OPERATORS, 'groups'=>['and','or'], 'relations'=>false, 'relation_depth'=>0,
			'sorts'=>true, 'sort_nulls'=>['native','first','last'], 'legacy_filters'=>true,
			'search'=>true, 'select'=>true, 'include'=>false, 'cursor'=>true, 'offset'=>true,
			'aggregates'=>true, 'tenant'=>true, 'authorization'=>true, 'find'=>true,
			'stable_record_keys'=>true, 'record_key_field'=>PanelQueryPath::make($recordKeyField)->value(),
			'max_limit'=>250, 'count_total'=>true, 'cursor_previous'=>false, 'mutations'=>false,
		];
		return new self($version, array_replace($capabilities, $overrides));
	}

	/** @param array<string,mixed> $capabilities */
	public static function fromArray(int $version, array $capabilities): self { return new self($version, $capabilities); }

	public function version(): int { return $this->version; }
	public function fingerprint(): string { return $this->fingerprint; }
	/** @return array<string,mixed> */ public function capabilities(): array { return $this->capabilities; }
	public function recordKeyField(): string { return (string)$this->capabilities['record_key_field']; }
	public function maxLimit(): int { return (int)$this->capabilities['max_limit']; }
	public function supportsFind(): bool { return $this->capabilities['find']===true; }

	public function assertSupports(PanelDataQuery $query): void {
		PanelQueryCapabilities::fromArray($this->capabilities)->assertSupports($query);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['type'=>'panel_http_data_capability_pin','version'=>$this->version,'fingerprint'=>$this->fingerprint,'capabilities'=>$this->capabilities];
	}

	/** @param mixed $values @param list<string> $allowed @return list<string> */
	private static function names(mixed $values, array $allowed, string $label): array {
		if(!is_array($values) || !array_is_list($values)){ throw new \InvalidArgumentException("Remote {$label} must be a list."); }
		$out=[];
		foreach($values as $value){
			if(!is_string($value)){ throw new \InvalidArgumentException("Remote {$label} entries must be strings."); }
			$value=strtolower(trim($value));
			if(!in_array($value, $allowed, true)){ throw new \InvalidArgumentException("Remote {$label} contains an unsupported value."); }
			$out[]=$value;
		}
		$out=array_values(array_unique($out)); sort($out, SORT_STRING); return $out;
	}
}
