<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic portable evaluator for the complete typed Panel query-expression AST. */
final class PanelQueryExpressionEvaluator {
	/** @param array<string,mixed> $row */public static function matches(array $row,?PanelQueryExpression $expression):bool{
		if($expression===null){return true;}
		if($expression instanceof PanelQueryGroup){$matches=array_map(static fn(PanelQueryExpression $child):bool=>self::matches($row,$child),$expression->children());return$expression->boolean()==='and'?!in_array(false,$matches,true):in_array(true,$matches,true);}
		if($expression instanceof PanelQueryRelation){$items=self::relationItems($row,$expression->relation());if($expression->scope()!==null){$items=array_values(array_filter($items,static fn(array $item):bool=>self::matches($item,$expression->scope())));}$matches=array_map(static fn(array $item):bool=>self::matches($item,$expression->expression()),$items);return match($expression->quantifier()){'any'=>in_array(true,$matches,true),'none'=>!in_array(true,$matches,true),'all'=>!in_array(false,$matches,true)};}
		$filters=PanelQueryExpressionCodec::legacyFilters($expression);if($filters===null||count($filters)!==1){throw new \LogicException('Unknown Panel query expression node.');}return self::filter($row,$filters[0]);
	}
	/** @param array<string,mixed> $row @param array<string,mixed> $filter */private static function filter(array $row,array $filter):bool{[$exists,$actual]=self::value($row,(string)$filter['field']);$expected=$filter['value']??null;return match($filter['operator']){'eq'=>$exists&&self::equal($actual,$expected),'neq'=>!$exists||!self::equal($actual,$expected),'gt'=>$exists&&self::compare($actual,$expected)>0,'gte'=>$exists&&self::compare($actual,$expected)>=0,'lt'=>$exists&&self::compare($actual,$expected)<0,'lte'=>$exists&&self::compare($actual,$expected)<=0,'in'=>$exists&&self::in($actual,$expected),'not_in'=>!$exists||!self::in($actual,$expected),'contains'=>$exists&&self::contains($actual,$expected),'not_contains'=>!$exists||!self::contains($actual,$expected),'starts_with'=>$exists&&str_starts_with(self::lower($actual),self::lower($expected)),'ends_with'=>$exists&&str_ends_with(self::lower($actual),self::lower($expected)),'between'=>$exists&&self::compare($actual,$expected[0]??null)>=0&&self::compare($actual,$expected[1]??null)<=0,'not_between'=>!$exists||!(self::compare($actual,$expected[0]??null)>=0&&self::compare($actual,$expected[1]??null)<=0),'is_null'=>!$exists||$actual===null,'not_null'=>$exists&&$actual!==null,default=>false};}
	/** @param array<string,mixed> $row @return array{bool,mixed} */private static function value(array $row,string $path):array{$current=$row;foreach(explode('.',$path)as$segment){if(!is_array($current)||!array_key_exists($segment,$current)){return[false,null];}$current=$current[$segment];}return[true,$current];}
	/** @return list<array<string,mixed>> */private static function relationItems(array $row,string $relation):array{if(!array_key_exists($relation,$row)||$row[$relation]===null){return[];}$value=$row[$relation];if($value instanceof \JsonSerializable){$value=$value->jsonSerialize();}elseif(is_object($value)){$value=get_object_vars($value);}if(!is_array($value)){return[];}$values=array_is_list($value)?$value:[$value];$items=[];foreach($values as$item){if($item instanceof \JsonSerializable){$item=$item->jsonSerialize();}elseif(is_object($item)){$item=get_object_vars($item);}if(is_array($item)&&!array_is_list($item)){$items[]=$item;}}return$items;}
	private static function equal(mixed $left,mixed $right):bool{return is_numeric($left)&&is_numeric($right)?(float)$left===(float)$right:$left===$right;}
	private static function compare(mixed $left,mixed $right):int{if($left===null&&$right===null){return 0;}if($left===null){return-1;}if($right===null){return 1;}if(is_numeric($left)&&is_numeric($right)){return(float)$left<=>(float)$right;}if($left instanceof \DateTimeInterface){$left=$left->format(DATE_ATOM);}if($right instanceof \DateTimeInterface){$right=$right->format(DATE_ATOM);}return strnatcasecmp(self::stable($left),self::stable($right));}
	private static function in(mixed $actual,mixed $expected):bool{if(!is_array($expected)){return false;}foreach($expected as$candidate){if(self::equal($actual,$candidate)){return true;}}return false;}
	private static function contains(mixed $actual,mixed $expected):bool{if(is_array($actual)){foreach($actual as$item){if(self::equal($item,$expected)){return true;}}return false;}return str_contains(self::lower($actual),self::lower($expected));}
	private static function stable(mixed $value):string{return is_scalar($value)||$value===null?(string)$value:json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
	private static function lower(mixed $value):string{$value=self::stable($value);return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);}
}
