<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Reactor\ReactorEffects;
use Dataphyre\Reactor\ReactorTestHarness;

if(!function_exists('dp_reactor_unit_test_bootstrap')){
	function dp_reactor_unit_test_bootstrap(): void {
		$modules_root=dirname(__DIR__, 2);
		$autoloader=$modules_root.'/core/kernel/autoloader.php';
		if(is_file($autoloader)){
			require_once $autoloader;
			if(class_exists('dataphyre\\autoloader', false)){
				\dataphyre\autoloader::register($modules_root);
				\dataphyre\autoloader::register_framework_modules(['reactor']);
			}
		}
	}
}

function dp_reactor_test_harness_mount_summary(): string {
	dp_reactor_unit_test_bootstrap();

	$harness=ReactorTestHarness::make();
	$harness->register(
		ReactorComponent::make('Counter Box')
			->state(['count'=>2])
			->computed('double', static fn(array $state): int => (int)($state['count'] ?? 0) * 2)
			->render('<strong>{{ count }}</strong><span>{{ double }}</span>')
	);

	$mounted=$harness->mount('counter box');
	ReactorTestHarness::assertHtmlContains($mounted, '<strong>2</strong>');

	return json_encode([
		'component'=>$mounted['component'],
		'html_has_root'=>str_contains($mounted['html'], 'data-dp-reactor-component="counter_box"'),
		'snapshot_component'=>$mounted['snapshot']['component'] ?? '',
		'snapshot_count'=>$mounted['snapshot']['state']['count'] ?? null,
		'snapshot_double'=>$mounted['snapshot']['state']['double'] ?? null,
		'manifest_actions'=>count($mounted['manifest']['actions'] ?? []),
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_reactor_test_harness_dispatch_summary(): string {
	dp_reactor_unit_test_bootstrap();

	$harness=ReactorTestHarness::make();
	$harness->register(
		ReactorComponent::make('counter')
			->state(['count'=>0])
			->action('inc', static function(array $state, array $params, ReactorComponent $component, ReactorEffects $effects): array {
				$effects->dispatchSelf('counter:changed', ['next'=>(int)($state['count'] ?? 0) + (int)($params['by'] ?? 1)]);
				return ['count'=>(int)($state['count'] ?? 0) + (int)($params['by'] ?? 1)];
			})
			->render('<strong>{{ count }}</strong>')
	);

	$response=$harness->dispatch('counter', 'inc', ['count'=>1], ['by'=>2]);
	ReactorTestHarness::assertOk($response);
	ReactorTestHarness::assertState($response, 'count', 3);
	ReactorTestHarness::assertEffect($response, 'events');
	ReactorTestHarness::assertHtmlContains($response, '<strong>3</strong>');
	$snapshot=ReactorTestHarness::responseSnapshot($response);

	return json_encode([
		'status'=>$snapshot['status'],
		'state_keys'=>$snapshot['state_keys'],
		'effect_keys'=>$snapshot['effect_keys'],
		'event_name'=>$response->effects()['events'][0]['name'] ?? '',
		'event_self'=>$response->effects()['events'][0]['detail']['_reactor_self'] ?? false,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_reactor_test_harness_locked_state_summary(): string {
	dp_reactor_unit_test_bootstrap();

	$harness=ReactorTestHarness::make();
	$harness->register(
		ReactorComponent::make('locked-counter')
			->state(['count'=>1, 'account'=>['id'=>'acct_1']])
			->locked('account.id')
			->action('replace', static fn(array $state): array => [
				'count'=>9,
				'account'=>['id'=>'acct_999'],
			])
			->render('<span>{{ count }}</span>')
	);

	$snapshot=$harness->manager()->snapshot('locked-counter', ['count'=>1, 'account'=>['id'=>'acct_1']]);
	$response=$harness->dispatch('locked-counter', 'replace', ['count'=>1, 'account'=>['id'=>'tampered']], [], $snapshot);
	ReactorTestHarness::assertOk($response);
	ReactorTestHarness::assertState($response, 'account.id', 'acct_1');

	return json_encode([
		'count'=>$response->state()['count'] ?? null,
		'account_id'=>$response->state()['account']['id'] ?? '',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_reactor_test_harness_child_slot_summary(): string {
	dp_reactor_unit_test_bootstrap();

	$harness=ReactorTestHarness::make();
	$harness
		->register(ReactorComponent::make('child-card')->render('<em>{{ label }}</em>'))
		->register(
			ReactorComponent::make('parent-card')
				->state(['child_label'=>'Nested'])
				->child('body', 'child-card', static fn(array $state): array => ['label'=>$state['child_label'] ?? ''])
				->render('<section>{{ reactor:body }}</section>')
		);

	$mounted=$harness->mount('parent-card');

	return json_encode([
		'has_parent'=>$mounted['component']==='parent-card',
		'has_child_html'=>str_contains($mounted['html'], '<em>Nested</em>'),
		'child_parent_attribute'=>str_contains($mounted['html'], 'data-dp-reactor-parent="parent-card"'),
		'manifest_children'=>array_keys($mounted['manifest']['children'] ?? []),
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_reactor_support_contracts_summary(): string {
	dp_reactor_unit_test_bootstrap();

	$ok=\Dataphyre\Reactor\ReactorResponse::ok('<b>Ready</b>', ['count'=>2], ['toast'=>['Saved']]);
	$error=\Dataphyre\Reactor\ReactorResponse::error('  Missing field  ', 200, ['focus'=>'name']);
	return json_encode([
		'error'=>$error,
		'name'=>\Dataphyre\Reactor\ReactorName::normalize('  Counter Box / Primary!  '),
		'ok'=>$ok,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dp_reactor_signer_contract_summary(): string {
	dp_reactor_unit_test_bootstrap();

	$payload_a=['z'=>2, 'a'=>['b'=>1, 'a'=>0]];
	$payload_b=['a'=>['a'=>0, 'b'=>1], 'z'=>2];
	$signature=\Dataphyre\Reactor\ReactorSigner::sign($payload_a);
	return json_encode([
		'ordered_verify'=>\Dataphyre\Reactor\ReactorSigner::verify($payload_b, $signature),
		'tampered_verify'=>\Dataphyre\Reactor\ReactorSigner::verify(['a'=>['a'=>0], 'z'=>2], $signature),
		'unsigned_allowed'=>\Dataphyre\Reactor\ReactorSigner::verify($payload_a, ''),
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
