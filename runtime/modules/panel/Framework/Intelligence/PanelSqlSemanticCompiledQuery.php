<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable parameterized SQL semantic plan; SQL and values never enter public serialization. */
final class PanelSqlSemanticCompiledQuery implements \JsonSerializable {
	/** @param array<string,null|bool|int|float|string> $dataParameters @param array<string,null|bool|int|float|string> $countParameters @param array<string,string> $dimensionAliases @param array<string,string> $metricAliases */
	public function __construct(private readonly string $dataSql,private readonly array $dataParameters,private readonly ?string $countSql,private readonly array $countParameters,private readonly array $dimensionAliases,private readonly array $metricAliases,private readonly string $fingerprint){if(trim($dataSql)===''||($countSql!==null&&trim($countSql)==='')||preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1){throw new \InvalidArgumentException('Compiled semantic SQL plan is invalid.');}}
	public function dataSql():string{return$this->dataSql;}/** @return array<string,null|bool|int|float|string> */public function dataParameters():array{return$this->dataParameters;}public function countSql():?string{return$this->countSql;}/** @return array<string,null|bool|int|float|string> */public function countParameters():array{return$this->countParameters;}/** @return array<string,string> */public function dimensionAliases():array{return$this->dimensionAliases;}/** @return array<string,string> */public function metricAliases():array{return$this->metricAliases;}public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_sql_semantic_compiled_query','version'=>1,'fingerprint'=>$this->fingerprint,'dimensions'=>array_keys($this->dimensionAliases),'metrics'=>array_keys($this->metricAliases),'data_parameter_count'=>count($this->dataParameters),'count_parameter_count'=>count($this->countParameters),'count_query'=>$this->countSql!==null,'sql_serialized'=>false,'parameters_serialized'=>false];}
}
