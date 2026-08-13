<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\FormSection;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel form section factory and immutable state builders cover defaults and boundaries',static function(Context $t): void {
	FormSection::flushConfigurators();
	$base=FormSection::make(' account.details ');
	$t->same('account.details',$base->name());
	$t->same([
		'name'=>'account.details',
		'label'=>'Account Details',
		'description'=>null,
		'columns'=>0,
		'collapsible'=>false,
		'collapsed'=>false,
		'presentation'=>[],
		'meta'=>[],
	],$base->toArray());

	$changed=$base
		->label('  Account profile  ')
		->description('  Personal details  ')
		->columns(99)
		->collapsible(false)
		->collapsed(true)
		->meta(['one'=>1])
		->meta(['two'=>2]);
	$t->same('Account profile',$changed->toArray()['label']);
	$t->same('Personal details',$changed->toArray()['description']);
	$t->same(12,$changed->toArray()['columns']);
	$t->isTrue($changed->toArray()['collapsible']);
	$t->isTrue($changed->toArray()['collapsed']);
	$t->same(['one'=>1,'two'=>2],$changed->toArray()['meta']);
	$t->same(0,$base->columns(-4)->toArray()['columns']);
	$t->isFalse($base->collapsible()->collapsible(false)->toArray()['collapsible']);
	$t->isFalse($base->collapsed(false)->toArray()['collapsed']);
	$t->same('Account Details',$base->label('   ')->toArray()['label']);
	$t->same(null,$base->description('   ')->toArray()['description']);
	$t->same('',FormSection::make('---')->toArray()['label']);
})->tag('panel','form-section','coverage')->group('framework-coverage');

test('panel form section declarative hydration covers every supported section shortcut',static function(Context $t): void {
	$section=FormSection::fromArray([
		'name'=>'Checkout Details',
		'label'=>' Checkout information ',
		'description'=>' Payment and delivery ',
		'columns'=>[
			'base'=>0,
			'small'=>2,
			'medium'=>14,
			'large'=>4,
			'xl'=>5,
			'wide'=>6,
			'unsupported'=>8,
		],
		'collapsible'=>true,
		'collapsed'=>true,
		'accessibility'=>[
			'min_width'=>-10,
			'unit'=>'CH',
			'min_chars'=>-2,
			'touch_target'=>-3,
			'adornment_ratio'=>2,
			'label_ratio'=>-1,
			'contrast_policy'=>[
				'ratio'=>30,
				'scope'=>'field',
				'large_text_min_ratio'=>0,
			],
		],
		'a11y'=>[
			'contrast'=>[
				'min_ratio'=>0,
				'scope'=>'not supported',
				'large_text_min_ratio'=>30,
			],
		],
		'min_usable_width'=>320,
		'min_usable_width_unit'=>'rem',
		'min_usable_chars'=>25,
		'min_touch_target'=>48,
		'max_adornment_ratio'=>0.4,
		'max_label_ratio'=>0.5,
		'contrast_policy'=>[
			'min_ratio'=>7,
			'scope'=>'label',
			'large_text_min_ratio'=>4.5,
		],
		'meta'=>['surface'=>'checkout'],
	]);
	$data=$section->toArray();
	$t->same('checkout_details',$data['name']);
	$t->same('Checkout information',$data['label']);
	$t->same('Payment and delivery',$data['description']);
	$t->same(12,$data['columns']);
	$t->same([
		'default'=>1,
		'sm'=>2,
		'md'=>12,
		'lg'=>4,
		'xl'=>5,
		'2xl'=>6,
	],$data['meta']['grid_columns']);
	$t->isTrue($data['collapsible']);
	$t->isTrue($data['collapsed']);
	$t->same('checkout',$data['meta']['surface']);
	$t->same([
		'min_usable_width'=>320,
		'min_usable_width_unit'=>'px',
		'min_usable_chars'=>25,
		'min_touch_target'=>48,
		'max_adornment_ratio'=>0.4,
		'max_label_ratio'=>0.5,
		'contrast_policy'=>[
			'min_ratio'=>7.0,
			'scope'=>'label',
			'large_text_min_ratio'=>4.5,
		],
	],$data['meta']['accessibility']);

	$t->same('Fallback Label',FormSection::fromArray(['label'=>'Fallback Label'])->toArray()['label']);
	$t->same(null,FormSection::fromArray(['name'=>'plain','description'=>123])->toArray()['description']);
	$t->same([],FormSection::make('empty')->columns([])->toArray()['meta']['grid_columns']);
})->tag('panel','form-section','coverage')->group('framework-coverage');

test('panel form section grid builders normalize responsive aliases spans and starts',static function(Context $t): void {
	$section=FormSection::make('layout')
		->columnSpan([
			''=>0,
			'sm'=>'full',
			'md'=>99,
			'lg'=>-2,
			'xl'=>7,
			'xxl'=>8,
			'invalid'=>4,
		])
		->columnStart([
			'initial'=>0,
			'small'=>2,
			'medium'=>99,
			'large'=>-4,
			'xl'=>6,
			'2xl'=>7,
			'invalid'=>3,
		]);
	$data=$section->toArray();
	$t->same([
		'default'=>1,
		'sm'=>'full',
		'md'=>12,
		'lg'=>1,
		'xl'=>7,
		'2xl'=>8,
	],$data['meta']['column_span']);
	$t->same([
		'default'=>1,
		'sm'=>2,
		'md'=>12,
		'lg'=>1,
		'xl'=>6,
		'2xl'=>7,
	],$data['meta']['column_start']);
	$t->same('full',FormSection::make('full')->columnSpan(' FULL ')->toArray()['meta']['column_span']);
	$t->same(1,FormSection::make('minimum')->columnSpan(0)->toArray()['meta']['column_span']);
	$t->same(12,FormSection::make('maximum')->columnSpan(20)->toArray()['meta']['column_span']);
	$t->same(1,FormSection::make('start')->columnStart('-4')->toArray()['meta']['column_start']);
	$t->same(12,FormSection::make('start')->columnStart('20')->toArray()['meta']['column_start']);
})->tag('panel','form-section','coverage')->group('framework-coverage');

test('panel form section accessibility builders normalize aliases clamps and merged state',static function(Context $t): void {
	$section=FormSection::make('accessibility')
		->meta(['accessibility'=>'replace invalid legacy value'])
		->accessibilityPolicy([
			'min_width'=>-2,
			'unit'=>'ch',
			'min_chars'=>-3,
			'touch_target'=>-4,
			'adornment_ratio'=>1.5,
			'label_ratio'=>-0.5,
			'contrast_min_ratio'=>30,
		])
		->minUsableWidth(18,'CH')
		->minUsableCharacters(30)
		->minTouchTarget()
		->maxAdornmentRatio()
		->maxLabelRatio()
		->contrastPolicy(7,'input')
		->contrastPolicy([
			'ratio'=>0,
			'scope'=>'unknown',
			'large_text_min_ratio'=>30,
		]);
	$policy=$section->toArray()['meta']['accessibility'];
	$t->same(18,$policy['min_usable_width']);
	$t->same('ch',$policy['min_usable_width_unit']);
	$t->same(30,$policy['min_usable_chars']);
	$t->same(44,$policy['min_touch_target']);
	$t->same(0.45,$policy['max_adornment_ratio']);
	$t->same(0.55,$policy['max_label_ratio']);
	$t->same([
		'min_ratio'=>1.0,
		'scope'=>'control',
		'large_text_min_ratio'=>21.0,
	],$policy['contrast_policy']);

	$direct=FormSection::make('direct')->accessibilityPolicy([
		'min_usable_width'=>40,
		'min_usable_width_unit'=>'invalid',
		'min_usable_chars'=>12,
		'min_touch_target'=>24,
		'max_adornment_ratio'=>-1,
		'max_label_ratio'=>2,
		'min_ratio'=>4.75,
	]);
	$directPolicy=$direct->toArray()['meta']['accessibility'];
	$t->same('px',$directPolicy['min_usable_width_unit']);
	$t->same(0.0,$directPolicy['max_adornment_ratio']);
	$t->same(1.0,$directPolicy['max_label_ratio']);
	$t->same(4.75,$directPolicy['contrast_policy']['min_ratio']);
	$t->same(3.0,$directPolicy['contrast_policy']['large_text_min_ratio']);
})->tag('panel','form-section','coverage')->group('framework-coverage');
