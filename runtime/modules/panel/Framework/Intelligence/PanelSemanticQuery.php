<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable multi-metric semantic query with explicit scope, consistency, and fallback policy. */
final class PanelSemanticQuery implements \JsonSerializable {
	/** @var list<string> */private readonly array $metrics;/** @var list<string> */private readonly array $dimensions;/** @var list<PanelSemanticSort> */private readonly array $sorts;/** @var array<string,mixed> */private readonly array $authorization;private readonly string $fingerprint;
	/** @param list<string> $metrics @param array<string,mixed> $options */
	public function __construct(array $metrics,array $options=[]){
		$unknown=array_values(array_diff(array_keys($options),['dimensions','filter','sorts','offset','limit','tenant','authorization','consistency','allow_fallback']));if($unknown!==[]){throw new \InvalidArgumentException('Unknown semantic query option: '.(string)$unknown[0]);}
		if(!array_is_list($metrics)||$metrics===[]||count($metrics)>50){throw new \InvalidArgumentException('Semantic queries require 1-50 metric ids.');}$clean=[];foreach($metrics as$id){if(!is_string($id)){throw new \InvalidArgumentException('Semantic metric ids must be strings.');}$clean[PanelOperationsGuard::name($id,'semantic metric id')]=true;}$this->metrics=array_keys($clean);
		$dimensions=$options['dimensions']??[];if(!is_array($dimensions)||!array_is_list($dimensions)||count($dimensions)>20){throw new \InvalidArgumentException('Semantic query dimensions must be a list of at most 20 names.');}$this->dimensions=PanelOperationsGuard::names($dimensions,'semantic query dimension');
		$filter=$options['filter']??null;if($filter!==null&&!$filter instanceof PanelQueryExpression){throw new \InvalidArgumentException('Semantic query filter must implement PanelQueryExpression.');}$this->filter=$filter;
		$sorts=$options['sorts']??[];if(!is_array($sorts)||!array_is_list($sorts)||count($sorts)>20){throw new \InvalidArgumentException('Semantic query sorts must be a bounded list.');}foreach($sorts as$sort){if(!$sort instanceof PanelSemanticSort){throw new \InvalidArgumentException('Semantic query sorts must be PanelSemanticSort values.');}}$this->sorts=$sorts;
		$offset=$options['offset']??0;$limit=$options['limit']??100;if(!is_int($offset)||$offset<0||$offset>10000000||!is_int($limit)||$limit<1||$limit>10000){throw new \InvalidArgumentException('Semantic query pagination is outside its supported bounds.');}$this->offset=$offset;$this->limit=$limit;
		$tenant=$options['tenant']??null;if($tenant!==null&&!is_string($tenant)&&!is_int($tenant)){throw new \InvalidArgumentException('Semantic query tenant must be scalar.');}$this->tenant=$tenant;
		$authorization=$options['authorization']??[];if(!is_array($authorization)||($authorization!==[]&&array_is_list($authorization))){throw new \InvalidArgumentException('Semantic query authorization must be an object-like map.');}$safe=PanelSensitiveDataSanitizer::sanitize(PanelQueryValue::normalize($authorization,'semantic authorization'));if(!is_array($safe)||($safe!==[]&&array_is_list($safe))){throw new \InvalidArgumentException('Semantic query authorization is invalid.');}$this->authorization=$authorization;
		$consistency=$options['consistency']??'eventual';if(!is_string($consistency)||!in_array($consistency,['eventual','snapshot'],true)){throw new \InvalidArgumentException('Semantic query consistency must be eventual or snapshot.');}$this->consistency=$consistency;
		$allowFallback=$options['allow_fallback']??true;if(!is_bool($allowFallback)){throw new \InvalidArgumentException('Semantic query allow_fallback must be boolean.');}$this->allowFallback=$allowFallback;
		$this->fingerprint=PanelOperationsGuard::digest($this->trustedSpec());
	}
	private readonly ?PanelQueryExpression $filter;private readonly int $offset;private readonly int $limit;private readonly string|int|null $tenant;private readonly string $consistency;private readonly bool $allowFallback;
	/** @return list<string> */public function metricIds():array{return$this->metrics;}/** @return list<string> */public function dimensions():array{return$this->dimensions;}public function filter():?PanelQueryExpression{return$this->filter;}/** @return list<PanelSemanticSort> */public function sorts():array{return$this->sorts;}public function offset():int{return$this->offset;}public function limit():int{return$this->limit;}public function tenantKey():string|int|null{return$this->tenant;}/** @return array<string,mixed> */public function authorizationMetadata():array{return$this->authorization;}public function consistency():string{return$this->consistency;}public function allowsFallback():bool{return$this->allowFallback;}public function fingerprint():string{return$this->fingerprint;}
	/** Trusted execution spec; keep authority out of logs. @return array<string,mixed> */public function trustedSpec():array{return['type'=>'panel_semantic_query','version'=>1,'metrics'=>$this->metrics,'dimensions'=>$this->dimensions,'filter'=>$this->filter?->jsonSerialize(),'sorts'=>array_map(static fn(PanelSemanticSort $sort):array=>$sort->jsonSerialize(),$this->sorts),'offset'=>$this->offset,'limit'=>$this->limit,'tenant'=>$this->tenant,'authorization'=>$this->authorization,'consistency'=>$this->consistency,'allow_fallback'=>$this->allowFallback];}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_semantic_query_manifest','version'=>1,'metrics'=>$this->metrics,'dimensions'=>$this->dimensions,'filter_fields'=>$this->filter?->fields()??[],'filter_operators'=>$this->filter?->operators()??[],'sorts'=>array_map(static fn(PanelSemanticSort $sort):array=>$sort->jsonSerialize(),$this->sorts),'offset'=>$this->offset,'limit'=>$this->limit,'tenant_hash'=>$this->tenant===null?null:hash('sha256',(string)$this->tenant),'authorization_keys'=>array_keys($this->authorization),'authorization_serialized'=>false,'consistency'=>$this->consistency,'allow_fallback'=>$this->allowFallback,'fingerprint'=>$this->fingerprint];}
}
