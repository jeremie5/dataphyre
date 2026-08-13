<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Sanitation\Sanitation;
use Dataphyre\Sanitation\SanitationManager;
use Dataphyre\Sanitation\SanitizationException;
use Dataphyre\Sanitation\SanitizationResult;
use Dataphyre\Sanitation\Sanitizer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'sanitation'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_sanitation_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_sanitation_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_sanitation_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'sanitation']);
if(!class_exists(\dataphyre\sanitation::class, false)){
	require_once $dp_sanitation_modules_root.'/sanitation/unit_tests/sanitation_test_helpers.php';
}

/** @return array<int,mixed> */
function dp_sanitation_fluent_arguments(ReflectionMethod $method): array {
	$arguments=[];
	foreach($method->getParameters() as $parameter){
		if($parameter->isVariadic()){
			$arguments[]='field';
			continue;
		}
		if($parameter->isDefaultValueAvailable()){
			$arguments[]=$parameter->getDefaultValue();
			continue;
		}
		$type=$parameter->getType();
		$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
		$value=null;
		foreach($types as $candidate){
			if(!$candidate instanceof ReflectionNamedType || !$candidate->isBuiltin()){
				continue;
			}
			$value=match($candidate->getName()){
				'array'=>['allowed'],
				'bool'=>true,
				'callable'=>static fn(mixed $value): bool=>$value!=='invalid',
				'float'=>1.5,
				'int'=>2,
				'string'=>match($parameter->getName()){
					'pattern'=>'/^value$/',
					'message'=>'Custom validation message.',
					default=>'field',
				},
				default=>null,
			};
			break;
		}
		$arguments[]=$value;
	}
	return $arguments;
}

test('every fluent sanitizer method accepts a type-safe contract value', static function(Context $t): void {
	$inventory=$t->inventory(Sanitizer::class);
	$called=0;
	foreach($inventory->declaredPublicMethods() as $method){
		if($method->isConstructor() || in_array($method->getName(), ['sanitize', 'get', 'valid', 'failed', 'error'], true)){
			continue;
		}
		$sanitizer=(new SanitationManager())->string('value');
		$result=$inventory->invokeWithArguments($method, $sanitizer, dp_sanitation_fluent_arguments($method));
		$t->instanceOf(Sanitizer::class, $result);
		$called++;
	}
	$t->isTrue($called>=55);
})->tag('sanitation', 'coverage')->group('framework-coverage');

test('sanitation manager covers scalar array presence and constraint branches', static function(Context $t): void {
	$manager=new SanitationManager();
	$validCases=[
		[' Ada  Lovelace ', 'default|trim|squish', 'Ada Lovelace'],
		['ADA@EXAMPLE.TEST', 'email|lower', 'ada@example.test'],
		['https://example.test/path', 'url', 'https://example.test/path'],
		['+1 (514) 555-0100', 'phone', '+1 (514) 555-0100'],
		['Ada Lovelace', 'name', 'Ada Lovelace'],
		['1234', 'numeric', '1234'],
		['42', 'integer', 42],
		['4.25', 'float', 4.25],
		['true', 'boolean', true],
		['Hello World', 'slug', 'hello-world'],
		['ada_lovelace', 'username', 'ada_lovelace'],
		['H2X 1Y4', 'postal', 'H2X 1Y4'],
	];
	foreach($validCases as [$value, $rule, $expected]){
		$t->same($expected, $manager->sanitize($value, $rule));
	}

	$t->pathEquals('failed', true, $manager->sanitizeDetailed([], 'email'));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed('bad', 'email'));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed(null, 'required'));
	$t->pathEquals('failed', false, $manager->sanitizeDetailed(null, 'nullable'));
	$t->pathEquals('value', 7, $manager->sanitizeDetailed(null, ['type'=>'integer', 'default'=>7, 'default_provided'=>true]));
	$t->pathEquals('include', false, $manager->sanitizeDetailed(null, 'default', ['present'=>false]));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed(null, 'present', ['present'=>false]));
	$t->pathEquals('value', 3, $manager->sanitizeDetailed(null, ['type'=>'integer', 'default'=>3, 'default_provided'=>true], ['present'=>false]));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed(null, 'array'));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed('not-array', 'array'));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed(['named'=>'value'], 'list'));
	$t->pathEquals('failed', false, $manager->sanitizeDetailed(['a', 'b'], 'list|min_items:1|max_items:3|distinct'));

	$failRules=[
		['x', 'min:2'], ['toolong', 'max:3'], ['other', 'in:yes,no'], ['yes', 'not_in:yes,no'],
		['no', 'accepted'], ['yes', 'declined'], ['12', 'digits:3'], ['4', 'min_value:5'], ['6', 'max_value:5'],
		['prefix', 'starts_with:other'], ['suffix', 'ends_with:other'], ['middle', 'contains:absent'],
		['abc', ['type'=>'default', 'regex'=>'/^z/']],
	];
	foreach($failRules as [$value, $rule]){
		$t->pathEquals('failed', true, $manager->sanitizeDetailed($value, $rule, ['field'=>'contract']));
	}
	$t->pathEquals('failed', true, $manager->sanitizeDetailed('different', 'same:peer', ['context'=>['peer'=>'same']]));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed('same', 'different:peer', ['context'=>['peer'=>'same']]));
	$t->pathEquals('excluded', true, $manager->sanitizeDetailed('', 'exclude_when_blank'));
	$t->pathEquals('excluded', true, $manager->sanitizeDetailed('value', 'exclude_if:mode,private', ['input'=>['mode'=>'private']]));
	$t->pathEquals('excluded', true, $manager->sanitizeDetailed('value', 'exclude_unless:mode,public', ['input'=>['mode'=>'private']]));
	$t->pathEquals('failed', true, $manager->sanitizeDetailed('invalid', ['validate'=>static fn(): string=>'Callback failed.']));
})->tag('sanitation', 'coverage')->group('framework-coverage');

test('sanitation schemas cover wildcards conditions distinct defaults and presets', static function(Context $t): void {
	$manager=new SanitationManager();
	$input=[
		'mode'=>'business',
		'company'=>'',
		'password'=>'secret123',
		'password_confirmation'=>'different',
		'tags'=>['Alpha', 'alpha'],
		'users'=>[
			['email'=>'ADA@EXAMPLE.TEST', 'profile'=>['code'=>'A']],
			['email'=>'ada@example.test', 'profile'=>['code'=>'A']],
		],
	];
	$schema=[
		'mode'=>'required|in:personal,business',
		'company'=>'name|required_if:mode,business',
		'password'=>'required|min:8',
		'password_confirmation'=>'required|same:password',
		'tags'=>'list|distinct:ignore_case',
		'users'=>'list|unique_by_ignore_case:email',
		'users.*.email'=>'required|email|lower|distinct:ignore_case',
		'users.*.profile.code'=>'required|ascii|upper',
		'optional'=>'sometimes|default:fallback',
		'excluded'=>'exclude_if:mode,business',
	];
	$result=$manager->schema($input, $schema, ['seed'=>'kept'], [
		'labels'=>['company'=>'company name'],
		'messages'=>['company.required'=>'A company name is required.'],
	]);
	$t->isTrue($result->failed());
	$t->isTrue($result->fails());
	$t->isFalse($result->passed());
	$t->isFalse($result->passes());
	$t->isTrue($result->invalid('company'));
	$t->isTrue($result->has('seed'));
	$t->same('kept', $result->get('seed'));
	$t->same('business', $result->raw('mode'));
	$t->contains('company', array_keys($result->errors()));
	$t->notEmpty($result->messages());
	$t->notEmpty($result->firstError());
	$t->notEmpty($result->error());
	$t->notEmpty($result->error('company'));
	$t->same(['seed'=>'kept'], $result->only(['seed']));
	$t->isFalse(array_key_exists('seed', $result->except(['seed'])));
	$t->same($input, $result->input());
	$t->same($result->validated(), $result->data());
	$t->throws(static fn()=>$result->ensureValid('Schema failed.', ['case'=>'coverage']), SanitizationException::class);
	$t->throws(static fn()=>$result->throwIfFailed(), SanitizationException::class);

	$valid=$manager->schema([
		'mode'=>'personal',
		'company'=>'',
		'password'=>'secret123',
		'password_confirmation'=>'secret123',
		'tags'=>['alpha', 'beta'],
		'users'=>[['email'=>'ada@example.test', 'profile'=>['code'=>'a']]],
	], $schema, ['seed'=>'kept']);
	$t->isTrue($valid->passed());
	$t->instanceOf(SanitizationResult::class, $valid->ensureValid());
	$t->same($valid->validated(), $manager->validated($valid->input(), $schema, ['seed'=>'kept']));
	$t->same($valid->validated(), $manager->schemaOrFail($valid->input(), $schema, ['seed'=>'kept']));
	$t->same($valid->validated(), $manager->validateOrFail($valid->input(), $schema, ['seed'=>'kept']));
	// Exact repeated inputs exercise the schema-result cache path.
	$t->same($valid->validated(), $manager->schema($valid->input(), $schema, ['seed'=>'kept'])->validated());

	$manager->registerPreset('coverage', [
		'schema'=>['email'=>'required|email|lower', 'page'=>'integer|min_value:1'],
		'defaults'=>['page'=>1],
		'options'=>['labels'=>['email'=>'email address']],
	]);
	$manager->registerPreset('dynamic', static fn(array $overrides): array=>[
		'schema'=>['name'=>'required|name'],
		'defaults'=>['source'=>$overrides['source'] ?? 'default'],
	]);
	$t->isTrue($manager->hasPreset('COVERAGE'));
	$t->contains('coverage', $manager->presets());
	$t->contains('email', array_keys($manager->presetSchema('coverage')));
	$t->same('ada@example.test', $manager->preset('coverage', ['email'=>'ADA@EXAMPLE.TEST'])->validated()['email']);
	$t->same('ada@example.test', $manager->validatePreset('coverage', ['email'=>'ADA@EXAMPLE.TEST'])->validated()['email']);
	$t->same('ada@example.test', $manager->validatedPreset('coverage', ['email'=>'ADA@EXAMPLE.TEST'])['email']);
	$t->same('ada@example.test', $manager->presetOrFail('coverage', ['email'=>'ADA@EXAMPLE.TEST'])['email']);
	$t->same('coverage', $manager->preset('dynamic', ['name'=>'Ada'], ['source'=>'coverage'])->validated()['source']);
	$t->throws(static fn()=>$manager->presetSchema('missing'), InvalidArgumentException::class);
	$manager->registerPreset('invalid', []);
	$t->throws(static fn()=>$manager->presetSchema('invalid'), InvalidArgumentException::class);
})->tag('sanitation', 'coverage')->group('framework-coverage');

test('sanitation facade fluent result and input bag surfaces remain coherent', static function(Context $t): void {
	Sanitation::flush();
	$t->instanceOf(SanitationManager::class, Sanitation::manager());
	Sanitation::registerPreset('bag', ['schema'=>['email'=>'required|email|lower']]);
	$bag=Sanitation::bag([
		'name'=>' Ada ', 'empty'=>' ', 'count'=>'42', 'price'=>'4.25', 'enabled'=>'true',
		'items'=>['a', 'b'], 'email'=>'ADA@EXAMPLE.TEST', 'url'=>'https://example.test',
		'phone'=>'+1 514 555 0100', 'slug'=>'Hello World', 'username'=>'ada_user', 'postal'=>'H2X 1Y4',
	]);
	$t->notEmpty($bag->all());
	$t->isTrue($bag->has('name'));
	$t->isTrue($bag->present('name'));
	$t->isTrue($bag->missing('missing'));
	$t->isTrue($bag->filled('name'));
	$t->isTrue($bag->blank('empty'));
	$t->same('fallback', $bag->get('missing', 'fallback'));
	$t->same(['name'=>' Ada '], $bag->only(['name']));
	$t->isFalse(array_key_exists('name', $bag->except(['name'])));
	$t->same('Ada', $bag->string('name'));
	$t->same('Ada', $bag->text('name'));
	$t->same('Ada', $bag->textNoSpecial('name'));
	$t->same('Ada', $bag->basicHtml('name'));
	$t->same(42, $bag->integer('count'));
	$t->same(4.25, $bag->float('price'));
	$t->isTrue($bag->boolean('enabled'));
	$t->same(['a', 'b'], $bag->arrayValue('items'));
	$t->same(['a', 'b'], $bag->listValue('items'));
	$t->same('ADA@EXAMPLE.TEST', $bag->email('email'));
	$t->same('https://example.test', $bag->url('url'));
	$t->same('+1 514 555 0100', $bag->phone('phone'));
	$t->same('Ada', $bag->name('name'));
	$t->same('42', $bag->numeric('count'));
	$t->same('hello-world', $bag->slug('slug'));
	$t->same('ada_user', $bag->username('username'));
	$t->same('H2X 1Y4', $bag->postalCode('postal'));
	$t->same('ADA@EXAMPLE.TEST', $bag->clean('email', 'default'));
	$t->same('ADA', $bag->whenPresent('name', static fn(string $value): string=>strtoupper(trim($value))));
	$t->same('default', $bag->whenPresent('missing', static fn(): string=>'never', 'default'));
	$t->same('ADA', $bag->whenFilled('name', static fn(string $value): string=>strtoupper(trim($value))));
	$t->same('default', $bag->whenFilled('empty', static fn(): string=>'never', 'default'));
	$t->isTrue($bag->sanitize(['email'=>'email'])->passed());
	$t->isTrue($bag->validate(['email'=>'email'])->passed());
	$t->same('ada@example.test', $bag->validated(['email'=>'email|lower'])['email']);
	$t->same('ada@example.test', $bag->validatedOrFail(['email'=>'email|lower'])['email']);
	$t->isTrue($bag->preset('bag')->passed());
	$t->isTrue($bag->validatePreset('bag')->passed());
	$t->same('ada@example.test', $bag->validatedPreset('bag')['email']);
	$t->same('ada@example.test', $bag->validatedPresetOrFail('bag')['email']);

	$fluent=Sanitation::string(' ADA@EXAMPLE.TEST ')->email()->lower()->required();
	$t->same('ada@example.test', $fluent->sanitize());
	$t->same('ada@example.test', $fluent->get());
	$t->isTrue($fluent->valid());
	$t->isFalse($fluent->failed());
	$t->same(null, $fluent->error());
	$failed=Sanitation::string('bad')->email();
	$t->isFalse($failed->valid());
	$t->isTrue($failed->failed());
	$t->notEmpty($failed->error());

	$t->same('Ada', Sanitation::text(' Ada '));
	$t->same('Ada', Sanitation::textNoSpecial(' Ada '));
	$t->same('Ada', Sanitation::basicHtml(' Ada '));
	$t->same('ada@example.test', Sanitation::email('ADA@EXAMPLE.TEST', ['lower'=>true]));
	$t->same('https://example.test', Sanitation::url('https://example.test'));
	$t->same('+1 514 555 0100', Sanitation::phone('+1 514 555 0100'));
	$t->same('Ada', Sanitation::name('Ada'));
	$t->same('42', Sanitation::numeric('42'));
	$t->same(42, Sanitation::integer('42'));
	$t->same(4.25, Sanitation::float('4.25'));
	$t->isTrue(Sanitation::boolean('true'));
	$t->same(['a'], Sanitation::arrayValue(['a']));
	$t->same(['a'], Sanitation::listValue(['a']));
	$t->same('hello-world', Sanitation::slug('Hello World'));
	$t->same('ada_user', Sanitation::username('ada_user'));
	$t->same('H2X 1Y4', Sanitation::postalCode('H2X 1Y4'));
})->tag('sanitation', 'coverage')->group('framework-coverage');
