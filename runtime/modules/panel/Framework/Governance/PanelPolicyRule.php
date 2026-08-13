<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Portable, callback-free policy rule with explainable condition evaluation. */
final class PanelPolicyRule implements \JsonSerializable {
	public const OPERATORS=['eq','neq','in','not_in','contains','not_contains','exists','gt','gte','lt','lte','starts_with','ends_with','matches'];
	private readonly string $id;private readonly string $effect;/** @var list<string> */private readonly array $abilities;private readonly int $priority;/** @var array<string,mixed> */private readonly array $when;/** @var array<string,mixed> */private readonly array $obligations;private readonly string $reason;
	/** @param list<string> $abilities @param array<string,mixed> $when @param array<string,mixed> $obligations */
	public function __construct(string $id,string $effect,array $abilities,int $priority=0,array $when=[],array $obligations=[],string $reason='Policy rule matched.'){
		$this->id=PanelOperationsGuard::name($id,'policy rule id');$effect=strtolower(trim($effect));if(!in_array($effect,['allow','deny'],true)){throw new \InvalidArgumentException('Policy rule effect is invalid.');}$this->effect=$effect;if($abilities===[]){throw new \InvalidArgumentException('Policy rule requires at least one ability.');}$this->abilities=PanelOperationsGuard::abilityPatterns($abilities,'policy ability');if($priority<-100000||$priority>100000){throw new \InvalidArgumentException('Policy rule priority is outside its bounds.');}$this->priority=$priority;$when=PanelOperationsGuard::canonical($when);self::validateCondition($when);$this->when=$when;PanelOperationsGuard::object($obligations,'policy obligations',128);$this->obligations=PanelOperationsGuard::canonical($obligations);$this->reason=PanelOperationsGuard::label($reason,'policy reason',1024);
	}

	/** @param array<string,mixed> $rule */
	public static function from(string $id,array $rule):self {$abilities=PanelOperationsGuard::abilityPatterns(is_array($rule['abilities']??null)?$rule['abilities']:[(string)($rule['ability']??'*')],'policy ability');return new self(PanelOperationsGuard::name($id,'policy rule id'),in_array(($effect=strtolower((string)($rule['effect']??'deny'))),['allow','deny'],true)?$effect:'deny',$abilities,max(-100000,min(100000,(int)($rule['priority']??0))),PanelOperationsGuard::canonical(is_array($rule['when']??null)?$rule['when']:[]),PanelOperationsGuard::canonical(is_array($rule['obligations']??null)?$rule['obligations']:[]),PanelOperationsGuard::label((string)($rule['reason']??'Policy rule '.$id.' matched.'),'policy reason',1024));}

	public function id():string{return$this->id;}public function effect():string{return$this->effect;}public function priority():int{return$this->priority;}/** @return list<string> */public function abilities():array{return$this->abilities;}/** @return array<string,mixed> */public function obligations():array{return$this->obligations;}public function reason():string{return$this->reason;}
	public function matches(PanelPolicyRequest $request):bool {$ability=false;foreach($this->abilities as$pattern){if(PanelOperationsGuard::abilityMatches($pattern,$request->ability())){$ability=true;break;}}return$ability&&self::evaluateCondition($this->when,$request->attributes());}
	public function fingerprint():string{return PanelOperationsGuard::digest($this->values());}
	public function jsonSerialize():array{return$this->values()+['fingerprint'=>$this->fingerprint()];}
	/** @return array<string,mixed> */ private function values():array{return['type'=>'panel_policy_rule','id'=>$this->id,'effect'=>$this->effect,'abilities'=>$this->abilities,'priority'=>$this->priority,'when'=>$this->when,'obligations'=>$this->obligations,'reason'=>$this->reason];}

	/** @param array<string,mixed> $condition */
	private static function validateCondition(array $condition,int $depth=0):void {
		if($condition===[]){return;}if($depth>16){throw new \LengthException('Policy condition exceeds its depth limit.');}$keys=array_keys($condition);
		if(count($keys)===1&&in_array($keys[0],['all','any'],true)){$children=$condition[$keys[0]];if(!is_array($children)||!array_is_list($children)||$children===[]||count($children)>128){throw new \InvalidArgumentException('Policy condition groups require a bounded non-empty list.');}foreach($children as$child){if(!is_array($child)||($child!==[]&&array_is_list($child))){throw new \InvalidArgumentException('Policy condition children must be objects.');}self::validateCondition($child,$depth+1);}return;}
		if(count($keys)===1&&$keys[0]==='not'){$child=$condition['not'];if(!is_array($child)||($child!==[]&&array_is_list($child))){throw new \InvalidArgumentException('Policy not condition must contain an object.');}self::validateCondition($child,$depth+1);return;}
		$expected=['path','op','value'];$optional=['value'];$present=array_keys($condition);sort($present,SORT_STRING);$withoutValue=array_values(array_diff($present,$optional));sort($withoutValue,SORT_STRING);if($withoutValue!==['op','path']||array_diff($present,$expected)!==[]){throw new \InvalidArgumentException('Policy leaf condition shape is invalid.');}$path=(string)$condition['path'];if($path===''||strlen($path)>256||preg_match('/^[a-zA-Z0-9_.-]+$/D',$path)!==1){throw new \InvalidArgumentException('Policy condition path is invalid.');}$operator=strtolower((string)$condition['op']);if(!in_array($operator,self::OPERATORS,true)){throw new \InvalidArgumentException('Policy condition operator is invalid.');}if($operator!=='exists'&&!array_key_exists('value',$condition)){throw new \InvalidArgumentException('Policy condition value is required.');}if(array_key_exists('value',$condition)){PanelOperationsGuard::canonical($condition['value']);}
	}

	/** @param array<string,mixed> $condition @param array<string,mixed> $attributes */
	private static function evaluateCondition(array $condition,array $attributes):bool {
		if($condition===[]){return true;}if(isset($condition['all'])){foreach($condition['all']as$child){if(!self::evaluateCondition($child,$attributes)){return false;}}return true;}if(isset($condition['any'])){foreach($condition['any']as$child){if(self::evaluateCondition($child,$attributes)){return true;}}return false;}if(isset($condition['not'])){return!self::evaluateCondition($condition['not'],$attributes);}
		$missing=new \stdClass();$actual=PanelOperationsGuard::valueAt($attributes,(string)$condition['path'],$missing);$expected=$condition['value']??null;$operator=(string)$condition['op'];return match($operator){'exists'=>($actual!==$missing)===(bool)($expected??true),'eq'=>$actual!==$missing&&$actual===$expected,'neq'=>$actual===$missing||$actual!==$expected,'in'=>$actual!==$missing&&is_array($expected)&&in_array($actual,$expected,true),'not_in'=>$actual===$missing||!is_array($expected)||!in_array($actual,$expected,true),'contains'=>$actual!==$missing&&self::contains($actual,$expected),'not_contains'=>$actual===$missing||!self::contains($actual,$expected),'gt'=>$actual!==$missing&&is_numeric($actual)&&is_numeric($expected)&&(float)$actual>(float)$expected,'gte'=>$actual!==$missing&&is_numeric($actual)&&is_numeric($expected)&&(float)$actual>=(float)$expected,'lt'=>$actual!==$missing&&is_numeric($actual)&&is_numeric($expected)&&(float)$actual<(float)$expected,'lte'=>$actual!==$missing&&is_numeric($actual)&&is_numeric($expected)&&(float)$actual<=(float)$expected,'starts_with'=>$actual!==$missing&&is_string($actual)&&is_string($expected)&&str_starts_with($actual,$expected),'ends_with'=>$actual!==$missing&&is_string($actual)&&is_string($expected)&&str_ends_with($actual,$expected),'matches'=>$actual!==$missing&&is_string($actual)&&is_string($expected)&&PanelOperationsGuard::abilityMatches($expected,$actual),default=>false};
	}
	private static function contains(mixed $actual,mixed $expected):bool {return is_array($actual)?in_array($expected,$actual,true):(is_string($actual)&&is_string($expected)&&str_contains($actual,$expected));}
}
