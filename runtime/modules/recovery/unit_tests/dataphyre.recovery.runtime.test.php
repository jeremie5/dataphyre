<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Recovery\ProblemDefinition;
use Dataphyre\Recovery\ProblemResponse;
use Dataphyre\Recovery\Recovery;
use Dataphyre\Recovery\RecoveryActionDefinition;
use Dataphyre\Recovery\RecoveryContext;
use Dataphyre\Recovery\RecoveryManager;
use Dataphyre\Recovery\RecoveryRegistry;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/Framework/Bootstrap.php';

test('recovery manager emits RFC problem details compatibility and incident observations', static function(Context $t): void {
	$observed=[];
	$registry=(new RecoveryRegistry())
		->registerAction(new RecoveryActionDefinition('retry', ['en'=>'Try again','fr'=>'Réessayer'], [
			'kind'=>'retry',
			'retry_safe'=>true,
		]))
		->registerProblem(new ProblemDefinition('service_unavailable', [
			'en'=>'Service unavailable',
			'fr'=>'Service indisponible',
		], 503, [
			'en'=>'Wait a moment and try again.',
			'fr'=>'Attendez un moment, puis réessayez.',
		], [
			'help_topic'=>'connection',
			'retry_policy'=>'backoff',
			'retry_after_seconds'=>12,
			'data_state'=>'stale',
			'incident_policy'=>'aggregate',
			'evidence_keys'=>['provider','surface'],
			'fingerprint_keys'=>['provider'],
			'actions'=>['retry'],
		]), ['provider_down'])
		->registerProblem(new ProblemDefinition('validation_failed', 'Check the information', 422, 'Correct the highlighted fields.', [
			'help_topic'=>'action',
			'data_state'=>'current',
		]))
		->fallback('service_unavailable');
	$manager=new RecoveryManager(
		$registry,
		'https://docs.example.test/problems',
		static fn(ProblemDefinition $definition): string => '/docs/messages#'.$definition->helpTopic(),
		static function($problem, $context) use (&$observed): bool {
			$observed[]=['problem'=>$problem, 'context'=>$context];
			return true;
		}
	);
	$context=new RecoveryContext(
		scope:['scope_type'=>'store','store_id'=>17,'environment'=>'sandbox'],
		locale:'fr-CA',
		requestMethod:'POST',
		requestPath:'/fixture/api/orders',
		correlationId:'caller-correlation-1234'
	);
	$problem=$manager->problem('provider_down', $context, [], [
		'provider'=>'payments',
		'surface'=>'pos',
		'token'=>'never',
	]);
	$json=$problem->jsonSerialize();
	$t->same('https://docs.example.test/problems/service_unavailable', $json['type']);
	$t->same('Service indisponible', $json['title']);
	$t->same(503, $json['status']);
	$t->same('caller-correlation-1234', $json['correlation_id']);
	$t->same('/docs/messages#connection', $json['help_url']);
	$t->same('backoff', $json['retry']['policy']);
	$t->same(12, $json['retry']['after_seconds']);
	$t->same('stale', $json['data_state']);
	$t->same(true, $json['incident']['acknowledged'] ?? null);
	$t->same(true, $problem->incidentAcknowledged());
	$t->same('payments', $json['support_evidence']['provider']);
	$t->count(1, $json['actions']);
	$t->count(1, $observed);

	$compatibility=ProblemResponse::compatibility($problem, ['ok'=>false, 'status'=>'provider_down']);
	$compatibilityJson=json_decode($compatibility->body, true);
	$t->same(503, $compatibility->status);
	$t->same('provider_down', $compatibilityJson['status']);
	$t->same(503, $compatibilityJson['problem']['status']);
	$t->same('caller-correlation-1234', $compatibility->headers['X-Correlation-Id'] ?? null);
	$t->same('service_unavailable', $compatibility->headers['X-Recovery-Problem'] ?? null);
	$t->same('stale', $compatibility->headers['X-Recovery-Data-State'] ?? null);
	$t->same('12', $compatibility->headers['Retry-After'] ?? null);
	$t->contains('rel="help"', $compatibility->headers['Link'] ?? '');

	$native=ProblemResponse::make($problem);
	$t->contains('application/problem+json', $native->headers['Content-Type'] ?? '');
	$t->same('service_unavailable', json_decode($native->body, true)['code'] ?? null);

	$manager->problem('validation_failed', $context);
	$t->count(1, $observed);
})->tag('recovery','framework','runtime','rfc9457','http')->maxMillis(5000);

test('recovery facade requires explicit application ownership and can be reset', static function(Context $t): void {
	Recovery::reset();
	$t->throws(static fn()=>Recovery::manager(), LogicException::class);
	$registry=(new RecoveryRegistry())
		->registerProblem(new ProblemDefinition('unexpected', 'Unexpected problem'))
		->fallback('unexpected');
	$manager=new RecoveryManager($registry);
	Recovery::use($manager);
	$t->same($manager, Recovery::manager());
	$t->same('unexpected', Recovery::problem('anything', new RecoveryContext())->code());
	Recovery::reset();
})->tag('recovery','framework','facade')->maxMillis(5000);

test('recovery context honors exact, hierarchical, and global permission grants', static function(Context $t): void {
	$exact=new RecoveryContext(permissions:['fixture.settings.general.manage']);
	$t->same(true, $exact->can('fixture.settings.general.manage'));
	$t->same(false, $exact->can('fixture.settings.policy.manage'));
	$t->same(true, $exact->canAll(['fixture.settings.general.manage']));
	$t->same(false, $exact->canAll(['fixture.settings.general.manage','fixture.settings.policy.manage']));

	$hierarchical=new RecoveryContext(permissions:['fixture.settings.*']);
	$t->same(true, $hierarchical->can('fixture.settings.general.manage'));
	$t->same(false, $hierarchical->can('fixture.service_requests.create'));

	$global=new RecoveryContext(permissions:['*']);
	$t->same(true, $global->can('fixture.settings.policy.manage'));
	$t->same(true, $global->can('fixture.service_requests.create'));
})->tag('recovery','framework','permissions','security')->maxMillis(5000);

test('recovery kernel facade exposes bounded defaults', static function(Context $t): void {
	$t->same(24, \dataphyre\recovery::config('evidence_max_items'));
	$t->same('fallback', \dataphyre\recovery::config('missing', 'fallback'));
})->tag('recovery','framework','kernel')->maxMillis(5000);
