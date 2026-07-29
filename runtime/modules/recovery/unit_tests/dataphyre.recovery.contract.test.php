<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Recovery\Evidence;
use Dataphyre\Recovery\IncidentFingerprint;
use Dataphyre\Recovery\LocalizedText;
use Dataphyre\Recovery\ProblemDefinition;
use Dataphyre\Recovery\RecoveryActionDefinition;
use Dataphyre\Recovery\RecoveryContext;
use Dataphyre\Recovery\RecoveryRegistry;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/Framework/Bootstrap.php';

test('recovery definitions localize aliases and authorize corrective actions', static function(Context $t): void {
	$copy=new LocalizedText(['en'=>'Try again', 'fr'=>'Réessayer']);
	$t->same('Réessayer', $copy->forLocale('fr-CA'));
	$t->same('Try again', $copy->forLocale('de-DE'));

	$retry=new RecoveryActionDefinition('retry', ['en'=>'Try again', 'fr'=>'Réessayer'], [
		'kind'=>'retry',
		'required_permissions'=>['orders.retry'],
		'scope_types'=>['store'],
		'retry_safe'=>true,
		'meta'=>['surface'=>'order', 'password'=>'discarded'],
	]);
	$changeScope=new RecoveryActionDefinition('change_scope', 'Change scope', [
		'kind'=>'change_scope',
		'href_resolver'=>static fn(RecoveryContext $context): string => '/scope?from='.rawurlencode($context->requestPath()),
	]);
	$problem=new ProblemDefinition('provider_unavailable', [
		'en'=>'Provider unavailable',
		'fr'=>'Fournisseur indisponible',
	], 503, [
		'en'=>'Wait a moment and try again.',
		'fr'=>'Attendez un moment, puis réessayez.',
	], [
		'help_topic'=>'connection',
		'retry_policy'=>'backoff',
		'retry_after_seconds'=>15,
		'data_state'=>'stale',
		'incident_policy'=>'aggregate',
		'evidence_keys'=>['provider', 'attempt.count'],
		'fingerprint_keys'=>['provider'],
		'actions'=>['retry','change_scope'],
	]);
	$registry=(new RecoveryRegistry())
		->registerAction($retry)
		->registerAction($changeScope)
		->registerProblem($problem, ['stripe_down'])
		->pattern('/^provider_.*_failed$/', 'provider_unavailable')
		->fallback('provider_unavailable');

	$t->same($problem, $registry->problem('stripe_down'));
	$t->same($problem, $registry->problem('provider_maps_failed'));
	$t->same($problem, $registry->problem('not_registered'));
	$t->count(2, $registry->actions());
	$t->count(1, $registry->problems());

	$allowed=new RecoveryContext(
		permissions:['orders.*'],
		scope:['scope_type'=>'store','store_id'=>4],
		locale:'fr-CA',
		requestPath:'/orders'
	);
	$resolved=$retry->resolve($allowed);
	$t->same('Réessayer', $resolved?->jsonSerialize()['label'] ?? null);
	$t->isTrue((bool)($resolved?->jsonSerialize()['retry_safe'] ?? false));
	$t->same('order', $resolved?->jsonSerialize()['meta']['surface'] ?? null);
	$t->isFalse(isset($resolved?->jsonSerialize()['meta']['password']));
	$t->same('/scope?from=%2Forders', $changeScope->resolve($allowed)?->jsonSerialize()['href'] ?? null);
	$t->same(null, $retry->resolve(new RecoveryContext(scope:['scope_type'=>'store'])));
	$t->same(null, $retry->resolve(new RecoveryContext(permissions:['orders.retry'], scope:['scope_type'=>'brand'])));
})->tag('recovery','framework','catalog','permissions','localization')->maxMillis(5000);

test('recovery evidence and fingerprints are bounded deterministic and secret safe', static function(Context $t): void {
	$definition=new ProblemDefinition('payment_provider_unavailable', 'Payment unavailable', 503, 'Try again later.', [
		'incident_policy'=>'aggregate',
		'evidence_keys'=>['provider','attempt.count','authorization','customer.token','nested.safe'],
		'fingerprint_keys'=>['provider','attempt.count'],
	]);
	$source=[
		'provider'=>'moneris',
		'attempt'=>['count'=>2],
		'authorization'=>'Bearer never',
		'customer'=>['token'=>'never'],
		'nested'=>['safe'=>str_repeat('x', 300), 'password'=>'never'],
		'unlisted'=>'never',
	];
	$evidence=Evidence::from($source, $definition->evidenceKeys(), 24, 40);
	$t->same('moneris', $evidence->all()['provider'] ?? null);
	$t->same(2, $evidence->all()['attempt']['count'] ?? null);
	$t->same(40, strlen((string)($evidence->all()['nested']['safe'] ?? '')));
	$t->isFalse(isset($evidence->all()['authorization']));
	$t->isFalse(isset($evidence->all()['customer']));
	$t->isFalse(isset($evidence->all()['unlisted']));

	$left=new RecoveryContext(scope:['store_id'=>7,'brand_id'=>2,'scope_type'=>'store']);
	$right=new RecoveryContext(scope:['scope_type'=>'store','brand_id'=>2,'store_id'=>7]);
	$one=IncidentFingerprint::for($definition, $left, $evidence);
	$two=IncidentFingerprint::for($definition, $right, $evidence);
	$t->same($one, $two);
	$t->contains('rec1_', $one);
	$t->isFalse(str_contains($one, 'moneris'));
	$changed=IncidentFingerprint::for($definition, new RecoveryContext(scope:['store_id'=>8,'brand_id'=>2,'scope_type'=>'store']), $evidence);
	$t->isFalse($one===$changed);
})->tag('recovery','framework','evidence','privacy','incidents')->maxMillis(5000);

test('recovery definitions reject malformed public contracts', static function(Context $t): void {
	$t->throws(static fn()=>new LocalizedText([]), InvalidArgumentException::class);
	$t->throws(static fn()=>new ProblemDefinition('', 'Bad'), InvalidArgumentException::class);
	$t->throws(static fn()=>new ProblemDefinition('bad', 'Bad', 200), InvalidArgumentException::class);
	$t->throws(static fn()=>new ProblemDefinition('bad', 'Bad', 500, 'Bad', ['type_uri'=>'/relative']), InvalidArgumentException::class);
	$t->throws(static fn()=>new ProblemDefinition('bad', 'Bad', 500, 'Bad', ['evidence_keys'=>['bad-path']]), InvalidArgumentException::class);
	$t->throws(static fn()=>new RecoveryActionDefinition('bad action', 'Bad'), InvalidArgumentException::class);
	$t->throws(static fn()=>new RecoveryActionDefinition('bad', 'Bad', ['method'=>'TRACE']), InvalidArgumentException::class);
	$t->throws(static fn()=>(new RecoveryRegistry())->pattern('/[/', 'bad'), InvalidArgumentException::class);
})->tag('recovery','framework','validation')->maxMillis(5000);
