<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Sanitation\PresetRegistry;
use Dataphyre\Sanitation\Sanitation;
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
$dp_sanitation_facade_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_sanitation_facade_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_sanitation_facade_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'sanitation']);
if(!class_exists(\dataphyre\sanitation::class, false)){
	require_once $dp_sanitation_facade_modules_root.'/sanitation/unit_tests/sanitation_test_helpers.php';
}

final class DpSanitationPresetFactory {
	public static function definition(array $overrides): array {
		return [
			'schema'=>['email'=>'required|email|lower'],
			'defaults'=>['role'=>'member'],
			'options'=>['labels'=>['email'=>'email address']],
		];
	}
}

test('sanitation preset registry covers array callables and all override collections', static function(Context $t): void {
	$registry=new PresetRegistry();
	$registry->register(' Dynamic ', [DpSanitationPresetFactory::class, 'definition']);
	$resolved=$registry->resolve('dynamic', [
		'schema'=>['name'=>'nullable|name'],
		'defaults'=>['role'=>'admin'],
		'options'=>['labels'=>['name'=>'display name']],
	]);
	$t->same('dynamic', $resolved['name']);
	$t->contains('email', array_keys($resolved['schema']));
	$t->contains('name', array_keys($resolved['schema']));
	$t->same('admin', $resolved['defaults']['role']);
	$t->same('email address', $resolved['options']['labels']['email']);
	$t->same('display name', $resolved['options']['labels']['name']);
})->tag('sanitation', 'coverage')->group('framework-coverage');

test('sanitation static facade covers preset and schema aliases plus clean and anonymization', static function(Context $t): void {
	Sanitation::flush();
	Sanitation::registerPreset('coverage', [
		'schema'=>['email'=>'required|email|lower'],
		'defaults'=>['role'=>'member'],
	]);
	$t->notEmpty(Sanitation::anonymizeEmail('ada@example.com', 1, '#'));
	$t->same('Ada', Sanitation::clean(' Ada ', 'default'));
	$t->contains('coverage', Sanitation::presets());
	$t->isTrue(Sanitation::hasPreset('coverage'));
	$t->contains('email', array_keys(Sanitation::presetSchema('coverage')));

	$input=['email'=>' ADA@EXAMPLE.COM '];
	$t->isTrue(Sanitation::preset('coverage', $input)->passed());
	$t->isTrue(Sanitation::validatePreset('coverage', $input)->passed());
	$t->same('ada@example.com', Sanitation::validatedPreset('coverage', $input)['email']);
	$t->same('ada@example.com', Sanitation::presetOrFail('coverage', $input)['email']);

	$schema=['name'=>'required|name'];
	$data=['name'=>' Ada Lovelace '];
	$t->isTrue(Sanitation::schema($data, $schema)->passed());
	$t->isTrue(Sanitation::validate($data, $schema)->passed());
	$t->same('Ada Lovelace', Sanitation::validated($data, $schema)['name']);
	$t->same('Ada Lovelace', Sanitation::schemaOrFail($data, $schema)['name']);
	$t->same('Ada Lovelace', Sanitation::validateOrFail($data, $schema)['name']);

	$sanitizer=Sanitation::string(['A', 'a']);
	$t->instanceOf(Sanitizer::class, $sanitizer->distinctIgnoreCase()->distinct(false));
})->tag('sanitation', 'coverage')->group('framework-coverage');

test('sanitization exception exposes result errors input first error and caller context', static function(Context $t): void {
	$result=new SanitizationResult([], ['email'=>'Invalid email.'], ['email'=>'bad']);
	$previous=new LogicException('previous');
	$exception=new SanitizationException($result, ['source'=>'api'], null, 422, $previous);
	$t->same($result, $exception->result());
	$t->same(['email'=>'Invalid email.'], $exception->errors());
	$t->same(['email'=>'bad'], $exception->input());
	$t->same('Invalid email.', $exception->firstError());
	$t->same(['source'=>'api'], $exception->context());
	$t->same('Invalid email.', $exception->getMessage());
	$t->same(422, $exception->getCode());
	$t->same($previous, $exception->getPrevious());
})->tag('sanitation', 'coverage')->group('framework-coverage');
