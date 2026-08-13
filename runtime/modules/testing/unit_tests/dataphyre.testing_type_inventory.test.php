<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\TypeInventory;
use function Dataphyre\Test\test;

abstract class DpTypeInventoryAbstractContract {
	abstract public function missing(): void;
}

class DpTypeInventoryParentContract {
	public function inherited(): string { return 'inherited'; }
	protected function protectedParent(): string { return 'parent'; }
}

final class DpTypeInventoryContract extends DpTypeInventoryParentContract {
	public function __construct(public string $value='default') {}
	public static function staticJoin(string $left,string $right): string { return $left.'-'.$right; }
	public function instanceJoin(string $suffix): string { return $this->value.':'.$suffix; }
	protected function protectedChild(): string { return 'child'; }
	private function hidden(): string { return 'hidden'; }
}

test('type inventory makes API shape selection and invocation self-describing',static function(Context $t): void {
	$inventory=$t->inventory(DpTypeInventoryContract::class);
	$t->instanceOf(TypeInventory::class,$inventory);
	$t->same(DpTypeInventoryContract::class,$inventory->name());
	$t->isTrue($inventory->isInstantiable());
	$t->isTrue($inventory->isFinal());
	$t->isFalse(TypeInventory::of(DpTypeInventoryParentContract::class)->isFinal());
	$t->isTrue($inventory->hasMethod('instanceJoin'));
	$t->isFalse($inventory->hasMethod('missing'));
	$t->same('__construct',$inventory->constructor()?->getName());
	$t->notEmpty($inventory->methods());
	$t->notEmpty($inventory->methods(ReflectionMethod::IS_PUBLIC));

	$allPublic=array_column(array_map(static fn(ReflectionMethod $method): array=>['name'=>$method->getName()],$inventory->publicMethods()),'name');
	$t->contains('inherited',$allPublic);
	$t->same(['staticJoin'],array_map(
		static fn(ReflectionMethod $method): string=>$method->getName(),
		$inventory->declaredPublicMethods(true)
	));
	$t->containsAll(['__construct','instanceJoin'],array_map(
		static fn(ReflectionMethod $method): string=>$method->getName(),
		$inventory->declaredPublicMethods(false)
	));
	$t->same(['protectedChild'],array_map(
		static fn(ReflectionMethod $method): string=>$method->getName(),
		$inventory->protectedMethods(DpTypeInventoryContract::class)
	));
	$t->count(2,$inventory->protectedMethods());

	$t->subset([
		'public'=>true,
		'static'=>true,
		'parameters'=>2,
		'return_type'=>'string',
	],$inventory->methodShape('staticJoin'));
	$t->subset(['private'=>true,'public'=>false],$inventory->methodShape('hidden'));

	$instance=$inventory->newInstance('value');
	$t->instanceOf(DpTypeInventoryContract::class,$instance);
	$t->same('value',$instance->value);
	$t->same('array',$inventory->newInstanceWithArguments(['array'])->value);
	$t->same('left-right',$inventory->invoke('staticJoin',null,'left','right'));
	$t->same('value:suffix',$inventory->invokeWithArguments($inventory->method('instanceJoin'),$instance,['suffix']));
	$t->throws(static fn()=>$inventory->invoke('hidden',$instance),LogicException::class);
	$t->throws(static fn()=>TypeInventory::of(DpTypeInventoryAbstractContract::class)->newInstance(),LogicException::class);
})->tag('testing','type-inventory','api')->group('framework-coverage');
