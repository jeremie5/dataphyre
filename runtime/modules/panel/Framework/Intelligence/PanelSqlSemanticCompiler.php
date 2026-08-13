<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Parameterized GROUP BY compiler for all first-party semantic aggregations. */
final class PanelSqlSemanticCompiler {
	private readonly string $driver;
	public function __construct(private readonly PanelSqlSchema $schema,string $driver){$driver=strtolower(trim($driver));if(!in_array($driver,['mysql','pgsql','sqlite'],true)){throw new \InvalidArgumentException('Semantic SQL supports mysql, pgsql, and sqlite only.');}$this->driver=$driver;}

	public function compile(PanelSemanticExecutionPlan $plan,?PanelQueryExpression $securityScope=null):PanelSqlSemanticCompiledQuery{
		$dataParams=[];$dataWhere=$this->where($plan,$securityScope,$dataParams,$plan->query()->dimensions()!==[]);$dimensionAliases=[];$metricAliases=[];$select=[];$group=[];
		foreach($plan->query()->dimensions()as$index=>$dimension){$alias='__dp_sem_dim_'.$index;$dimensionAliases[$dimension]=$alias;$column=$this->column($dimension);$select[]=$column.' AS '.$this->quote($alias);$group[]=$column;}
		foreach($plan->metrics()as$index=>$metric){$alias='__dp_sem_metric_'.$index;$metricAliases[$metric->id()]=$alias;$select[]=$this->aggregate($metric,$dataParams).' AS '.$this->quote($alias);}
		$sql='SELECT '.implode(', ',$select).' FROM '.$this->table().' t0 WHERE '.$dataWhere;if($group!==[]){$sql.=' GROUP BY '.implode(', ',$group);}$sql.=$this->order($plan,$dimensionAliases,$metricAliases);$sql.=' LIMIT '.$plan->query()->limit().' OFFSET '.$plan->query()->offset();
		$countSql=null;$countParams=[];if($group!==[]){$countWhere=$this->where($plan,$securityScope,$countParams,true);$countSql='SELECT COUNT(*) FROM (SELECT 1 FROM '.$this->table().' t0 WHERE '.$countWhere.' GROUP BY '.implode(', ',$group).') dp_semantic_groups';}
		$fingerprint=PanelOperationsGuard::digest(['plan'=>$plan->fingerprint(),'driver'=>$this->driver,'schema'=>$this->schema->fingerprint(),'data_sql'=>hash('sha256',$sql),'data_parameter_names'=>array_keys($dataParams),'count_sql'=>$countSql===null?null:hash('sha256',$countSql),'count_parameter_names'=>array_keys($countParams)]);
		return new PanelSqlSemanticCompiledQuery($sql,$dataParams,$countSql,$countParams,$dimensionAliases,$metricAliases,$fingerprint);
	}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function where(PanelSemanticExecutionPlan $plan,?PanelQueryExpression $securityScope,array &$parameters,bool $universe):string{
		$parts=[];$query=$plan->query();if($query->filter()!==null){$parts[]=$this->expression($query->filter(),$parameters);}if($securityScope!==null){$parts[]=$this->expression($securityScope,$parameters);}
		$tenantField=$this->schema->tenantField();if($tenantField!==null&&$query->tenantKey()!==null){$parts[]=$this->column($tenantField).' = '.$this->bind($parameters,$query->tenantKey());}
		if($universe){$filters=[];foreach($plan->metrics()as$metric){$filters[]=$this->mapFilter($metric->filter(),$parameters);}$filters=array_values(array_unique($filters));if(!in_array('1 = 1',$filters,true)){$parts[]='('.implode(' OR ',$filters).')';}}
		return$parts===[]?'1 = 1':implode(' AND ',array_map(static fn(string $part):string=>'('.$part.')',$parts));
	}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function aggregate(PanelSemanticMetric $metric,array &$parameters):string{
		$base=$this->mapFilter($metric->filter(),$parameters);$field=$metric->field()===null?null:$this->column($metric->field());
		return match($metric->aggregation()){
			'count'=>'COALESCE(SUM(CASE WHEN '.$base.' THEN 1 ELSE 0 END), 0)',
			'sum'=>'COALESCE(SUM(CASE WHEN '.$base.' THEN COALESCE('.$field.', 0) ELSE 0 END), 0)',
			'average'=>'AVG(CASE WHEN '.$base.' THEN '.$field.' ELSE NULL END)',
			'minimum'=>'MIN(CASE WHEN '.$base.' THEN '.$field.' ELSE NULL END)',
			'maximum'=>'MAX(CASE WHEN '.$base.' THEN '.$field.' ELSE NULL END)',
			'distinct_count'=>'COUNT(DISTINCT CASE WHEN '.$base.' THEN '.$field.' ELSE NULL END)',
			'ratio'=>$this->ratio($metric,$base,$parameters),
		};
	}
	/** @param array<string,null|bool|int|float|string> $parameters */private function ratio(PanelSemanticMetric $metric,string $base,array &$parameters):string{$numerator=$this->mapFilter($metric->numeratorFilter(),$parameters);$denominator=$this->mapFilter($metric->denominatorFilter(),$parameters);return'(1.0 * COALESCE(SUM(CASE WHEN ('.$base.') AND ('.$numerator.') THEN 1 ELSE 0 END), 0) / NULLIF(COALESCE(SUM(CASE WHEN ('.$base.') AND ('.$denominator.') THEN 1 ELSE 0 END), 0), 0))';}

	/** @param array<string,mixed> $filter @param array<string,null|bool|int|float|string> $parameters */
	private function mapFilter(array $filter,array &$parameters):string{$parts=[];foreach($filter as$field=>$expected){$column=$this->column($field);if(is_array($expected)){if(!array_is_list($expected)){throw new PanelSemanticUnsupported(['metric_filter_object']);}$parts[]=$this->membership($column,$expected,false,$parameters);}elseif($expected===null){$parts[]=$column.' IS NULL';}else{$parts[]=$column.' = '.$this->scalar($expected,$parameters);}}return$parts===[]?'1 = 1':implode(' AND ',array_map(static fn(string $part):string=>'('.$part.')',$parts));}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function expression(PanelQueryExpression $expression,array &$parameters):string{
		if($expression instanceof PanelQueryGroup){$parts=array_map(fn(PanelQueryExpression $child):string=>'('.$this->expression($child,$parameters).')',$expression->children());return implode($expression->boolean()==='and'?' AND ':' OR ',$parts);}
		if($expression instanceof PanelQueryRelation){throw new PanelSemanticUnsupported(['relations']);}
		if($expression instanceof PanelQueryIn){return$this->membership($this->column($expression->field()->value()),$expression->values(),$expression->negated(),$parameters);}
		if($expression instanceof PanelQueryBetween){$column=$this->column($expression->field()->value());$sql=$column.' >= '.$this->scalar($expression->from(),$parameters).' AND '.$column.' <= '.$this->scalar($expression->to(),$parameters);return$expression->negated()?'NOT ('.$sql.')':$sql;}
		if($expression instanceof PanelQueryNull){return$this->column($expression->field()->value()).($expression->negated()?' IS NOT NULL':' IS NULL');}
		if(!$expression instanceof PanelQueryComparison){throw new PanelSemanticUnsupported(['expression:'.$expression->type()]);}
		$column=$this->column($expression->field()->value());$operator=$expression->operator();$value=$expression->value();
		if($operator==='eq'||$operator==='neq'){if($value===null){return$column.($operator==='eq'?' IS NULL':' IS NOT NULL');}return$column.($operator==='eq'?' = ':' <> ').$this->scalar($value,$parameters);}
		if(in_array($operator,['gt','gte','lt','lte'],true)){return$column.' '.['gt'=>'>','gte'=>'>=','lt'=>'<','lte'=>'<='][$operator].' '.$this->scalar($value,$parameters);}
		if(in_array($operator,['contains','not_contains','starts_with','ends_with'],true)){if(!is_scalar($value)&&$value!==null){throw new PanelSemanticUnsupported(['operator_value:'.$operator]);}$escaped=str_replace(['!','%','_'],['!!','!%','!_'],(string)$value);$pattern=match($operator){'contains','not_contains'=>'%'.$escaped.'%','starts_with'=>$escaped.'%',default=>'%'.$escaped};$like='LOWER('.$column.') LIKE LOWER('.$this->bind($parameters,$pattern).") ESCAPE '!'";return$operator==='not_contains'?'NOT ('.$like.')':$like;}
		throw new PanelSemanticUnsupported(['operator:'.$operator]);
	}

	/** @param list<mixed> $values @param array<string,null|bool|int|float|string> $parameters */
	private function membership(string $column,array $values,bool $negated,array &$parameters):string{$hasNull=false;$bound=[];foreach($values as$value){if($value===null){$hasNull=true;}else{$bound[]=$this->scalar($value,$parameters);}}if($bound===[]){$match=$hasNull?$column.' IS NULL':'0 = 1';return$negated?'NOT ('.$match.')':$match;}$in=$column.' IN ('.implode(', ',$bound).')';if(!$negated){return$hasNull?'('.$in.' OR '.$column.' IS NULL)':'('.$in.')';}$not=$column.' NOT IN ('.implode(', ',$bound).')';return$hasNull?'('.$not.' AND '.$column.' IS NOT NULL)':'('.$not.' OR '.$column.' IS NULL)';}
	/** @param array<string,null|bool|int|float|string> $parameters */private function scalar(mixed $value,array &$parameters):string{if(!($value===null||is_bool($value)||is_int($value)||is_string($value)||(is_float($value)&&is_finite($value)))){throw new PanelSemanticUnsupported(['non_scalar_filter']);}return$this->bind($parameters,$value);}
	/** @param array<string,null|bool|int|float|string> $parameters */private function bind(array &$parameters,null|bool|int|float|string $value):string{$name='p'.(count($parameters)+1);$parameters[$name]=$value;return':'.$name;}
	/** @param array<string,string> $dimensionAliases @param array<string,string> $metricAliases */private function order(PanelSemanticExecutionPlan $plan,array $dimensionAliases,array $metricAliases):string{$sorts=$plan->query()->sorts();if($sorts===[]){$sorts=array_map(static fn(string $dimension):PanelSemanticSort=>PanelSemanticSort::asc($dimension),$plan->query()->dimensions());}$parts=[];$seen=[];foreach($sorts as$sort){$alias=$dimensionAliases[$sort->target()]??$metricAliases[$sort->target()]??throw new \LogicException('Semantic sort alias is missing.');$quoted=$this->quote($alias);$parts[]='CASE WHEN '.$quoted.' IS NULL THEN '.($sort->nulls()==='first'?0:1).' ELSE '.($sort->nulls()==='first'?1:0).' END ASC';$parts[]=$quoted.' '.strtoupper($sort->direction());$seen[$alias]=true;}foreach($dimensionAliases as$alias){if(!isset($seen[$alias])){$parts[]=$this->quote($alias).' ASC';}}return$parts===[]?'':' ORDER BY '.implode(', ',$parts);}
	private function table():string{return implode('.',array_map($this->quote(...),explode('.',$this->schema->table())));}
	private function column(string $field):string{return't0.'.$this->quote($this->schema->column($field));}
	private function quote(string $identifier):string{return$this->driver==='mysql'?'`'.$identifier.'`':'"'.$identifier.'"';}
}
