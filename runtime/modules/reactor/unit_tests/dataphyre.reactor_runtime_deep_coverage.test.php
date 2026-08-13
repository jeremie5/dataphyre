<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\Reactor;
use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Reactor\ReactorEffects;
use Dataphyre\Reactor\ReactorEndpoint;
use Dataphyre\Reactor\ReactorFileSnapshotVersionStore;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorManifest;
use Dataphyre\Reactor\ReactorRequest;
use Dataphyre\Reactor\ReactorResponse;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Reactor\ReactorTrace;
use Dataphyre\Reactor\ReactorValidator;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\reactor', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class reactor {
	public static mixed $runtimeConfig=[];
	public static function config(string $key, mixed $default=null): mixed {
		return is_array(self::$runtimeConfig) && array_key_exists($key, self::$runtimeConfig)
			? self::$runtimeConfig[$key]
			: $default;
	}
}
PHP);
}

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'mvc'=>true, 'reactor'=>true, 'templating'=>false],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

$dp_reactor_runtime_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_runtime_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_runtime_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'mvc', 'reactor']);

test('reactor effects cover targeted events guarded values normalization and serialization', static function(Context $t): void {
	$effects=ReactorEffects::make();
	$effects
		->dispatch('   ')
		->dispatchTo('!!!', 'ignored-target')
		->dispatchTo(' Target Component ', 'targeted', ['id'=>7])
		->dispatchSelf('self-event', ['ready'=>true])
		->toast('   ')
		->redirect(' /orders ', true)
		->fragment('bad name!', '<p>ignored</p>')
		->fragment('orders:table', '<p>Orders</p>', 'unsupported', 'document')
		->focus(' #email ', true)
		->focus('   ')
		->scroll(' #orders ', 'unsupported', 'center')
		->open(' /preview ', ' ')
		->download(' /export ', ' report.csv ')
		->replaceMode('inner')
		->errors([
			'   '=>['ignored'],
			' email '=>[' ', ' First error ', 'Second error'],
		])
		->clearErrors()
		->copy(' exact ')
		->skipRender(false);

	$payload=$effects->all();
	$t->same('target_component', $payload['events'][0]['detail']['_reactor_to']);
	$t->isTrue($payload['events'][1]['detail']['_reactor_self']);
	$t->isTrue($payload['redirect']['replace']);
	$t->same('morph', $payload['fragments'][0]['mode']);
	$t->same('document', $payload['fragments'][0]['scope']);
	$t->isTrue($payload['focus']['prevent_scroll']);
	$t->same('nearest', $payload['scroll']['block']);
	$t->same('_blank', $payload['open']['target']);
	$t->same('report.csv', $payload['download']['filename']);
	$t->same('inner', $payload['replace']);
	$t->same([], $payload['errors']);
	$t->same(' exact ', $payload['copy']);
	$t->same($payload, $effects->jsonSerialize());
})->tag('reactor', 'coverage', 'reactor-runtime')->group('framework-coverage');

test('reactor validator covers cached array rules optional constraints messages and cache guards', static function(Context $t): void {
	$state=[
		'required_field'=>'',
		'email_field'=>'invalid',
		'min_field'=>'x',
		'max_field'=>'long',
		'in_field'=>'outside',
		'included'=>'green',
		'string_field'=>12,
		'numeric_field'=>'abc',
		'boolean_field'=>'yes',
		'empty_min'=>null,
		'empty_max'=>'',
		'empty_in'=>null,
		'profile'=>'not-an-array',
	];
	$rules=[
		'   '=>'required',
		'required_field'=>[' required ', ''],
		'email_field'=>'email',
		'min_field'=>'min:3',
		'max_field'=>'max:3',
		'in_field'=>'in:red, green, blue',
		'included'=>'in:red, green, blue',
		'string_field'=>['string', 'string'],
		'numeric_field'=>'numeric',
		'boolean_field'=>'boolean',
		'empty_min'=>'min:3',
		'empty_max'=>'max:3',
		'empty_in'=>'in:red,blue',
		'profile.email'=>'required',
		'unknown'=>'future_rule',
	];
	$errors=ReactorValidator::validate($state, $rules);
	$t->same('Required field is required.', $errors['required_field'][0]);
	$t->same('Email field must be a valid email address.', $errors['email_field'][0]);
	$t->same('Min field must be at least 3.', $errors['min_field'][0]);
	$t->same('Max field must be at most 3.', $errors['max_field'][0]);
	$t->same('In field has an invalid value.', $errors['in_field'][0]);
	$t->same('String field is invalid.', $errors['string_field'][0]);
	$t->same([], array_intersect_key($errors, array_flip(['included', 'empty_min', 'empty_max', 'empty_in', 'unknown'])));
	$t->same($errors, ReactorValidator::validate($state, $rules));

	$many=[];
	for($index=0; $index<520; $index++){
		$many['cache_path_'.$index]='required';
	}
	$t->same(520, count(ReactorValidator::validate([], $many)));
	$t->same([], ReactorValidator::validate(['object'=>new stdClass()], []));
	$t->same([], ReactorValidator::validate(['nested'=>['object'=>new stdClass()]], []));
})->tag('reactor', 'coverage', 'reactor-runtime')->group('framework-coverage');

test('reactor request covers globals JSON batches aliases uploads headers and payload limits', static function(Context $t): void {
	$previousConfig=\dataphyre\reactor::$runtimeConfig;
	$query=$t->globalMap('_GET')->replace(['component'=>' Upload Form ', 'state'=>'{"query":1}']);
	$form=$t->globalMap('_POST')->replace(['action'=>' Save ', 'params'=>'{"posted":true}']);
	$server=$t->globalMap('_SERVER')->replace([
		'CONTENT_TYPE'=>'text/plain',
		'CONTENT_LENGTH'=>'42',
		'HTTP_X_DATAPHYRE_REACTOR'=>'DataphyreReactor',
		'IGNORED_SERVER_KEY'=>'ignored',
	]);
	$uploads=$t->globalMap('_FILES')->replace([
		'invalid'=>'not-an-upload',
		'avatar'=>[
			'name'=>'avatar.png', 'type'=>'image/png', 'tmp_name'=>'C:/tmp/avatar',
			'error'=>UPLOAD_ERR_OK, 'size'=>123,
		],
		'empty'=>[
			'name'=>'', 'type'=>'', 'tmp_name'=>'', 'error'=>UPLOAD_ERR_NO_FILE, 'size'=>0,
		],
		'documents'=>[
			'name'=>['contract'=>'contract.pdf', 'optional'=>''],
			'type'=>['contract'=>'application/pdf', 'optional'=>''],
			'tmp_name'=>['contract'=>'C:/tmp/contract', 'optional'=>''],
			'error'=>['contract'=>UPLOAD_ERR_OK, 'optional'=>UPLOAD_ERR_NO_FILE],
			'size'=>['contract'=>456, 'optional'=>0],
		],
	]);
	try{
		ReactorRequest::useInputReader(null);
		\dataphyre\reactor::$runtimeConfig=['max_payload_bytes'=>1024, 'secret'=>'runtime-secret'];
		$request=ReactorRequest::capture();
		$t->same('upload_form', $request->component());
		$t->same('save', $request->action());
		$t->same(1, $request->state()['query']);
		$t->isTrue($request->params()['posted']);
		$t->same(['avatar', 'documents.contract'], array_keys($request->uploads()));
		$t->same($request->uploads(), $request->params()['_uploads']);
		$t->isTrue($request->isReactorRequest());
		$t->same($request, ReactorRequest::from($request));

		$legacy=ReactorRequest::fromArray(['headers'=>['x-requested-with'=>'XMLHttpRequest']]);
		$t->isTrue($legacy->isReactorRequest());
		$t->isFalse(ReactorRequest::fromArray(['headers'=>['x-requested-with'=>'fetch']])->isReactorRequest());
		$fromJson=ReactorRequest::fromArray([
			'name'=>' JSON Alias ',
			'action'=>'   ',
			'state'=>'{"count":2}',
			'params'=>'{invalid',
			'uploads'=>'invalid',
			'headers'=>'invalid',
			'snapshot'=>ReactorSnapshot::make('json_alias', ['count'=>1], [], ['audience'=>'reactor:runtime-test'])->jsonSerialize(),
		]);
		$t->same('json_alias', $fromJson->component());
		$t->same(null, $fromJson->action());
		$t->same(2, $fromJson->state()['count']);
		$t->same([], $fromJson->params());
		$t->instanceOf(ReactorSnapshot::class, $fromJson->snapshot());
		$t->instanceOf(ReactorRequest::class, ReactorRequest::from(['component'=>'array-request']));
		$t->same([], ReactorRequest::fromArray(['state'=>42, 'params'=>null])->state());

		$readerLength=0;
		\dataphyre\reactor::$runtimeConfig=['max_payload_bytes'=>12, 'secret'=>'runtime-secret'];
		$server->replace(['CONTENT_TYPE'=>'application/json']);
		$query->clear();
		$form->clear();
		$uploads->clear();
		ReactorRequest::useInputReader(static function(int $length) use (&$readerLength): string {
			$readerLength=$length;
			return str_repeat('x', 13);
		});
		$t->same('', ReactorRequest::capture()->component());
		$t->same(13, $readerLength);

		\dataphyre\reactor::$runtimeConfig=['max_payload_bytes'=>2048, 'secret'=>'runtime-secret'];
		ReactorRequest::useInputReader(static fn(int $length): string=>json_encode([
			'batch'=>[
				['component'=>'first', 'state'=>['count'=>1]],
				'ignored',
			],
		], JSON_THROW_ON_ERROR));
		$batch=ReactorRequest::captureBatch();
		$t->same(1, count($batch));
		$t->same('first', $batch[0]->component());

		ReactorRequest::useInputReader(static fn(int $length): string=>'{invalid');
		$t->same('', ReactorRequest::capture()->component());
		ReactorRequest::useInputReader(static fn(int $length): string=>' {"batch":["invalid"]} ');
		$t->same(1, count(ReactorRequest::captureBatch()));

		ReactorRequest::useInputReader(null);
		$t->same('', ReactorRequest::capture()->component());
		$server->replace(['CONTENT_TYPE'=>'text/plain']);
		$t->same(1, count(ReactorRequest::captureBatch()));
		$t->instanceOf(ReactorRequest::class, ReactorRequest::from(null));
	}
	finally{
		ReactorRequest::useInputReader(null);
		\dataphyre\reactor::$runtimeConfig=$previousConfig;
	}
})->tag('reactor', 'coverage', 'reactor-runtime')->group('framework-coverage');

test('reactor manager covers configured children dispatch authorization state models and failures', static function(Context $t): void {
	$previousConfig=\dataphyre\reactor::$runtimeConfig;
	try{
		\dataphyre\reactor::$runtimeConfig=['secret'=>'runtime-secret', 'allow_unsigned_in_debug'=>true, 'components'=>[]];
		$manager=(new ReactorManager())->trustInternalTransport('reactor:runtime-test');
		$t->throws(static fn()=>$manager->register(ReactorComponent::make('!!!')), InvalidArgumentException::class);
		$t->isFalse($manager->has('missing'));
		$t->throws(static fn()=>$manager->snapshot('missing'), InvalidArgumentException::class);

		$parent=ReactorComponent::make('parent');
		$managerInternals=$t->nonPublic($manager);
		$managerInternals->writeProperty('mountDepth', 17);
		$t->contains('depth exceeded', $manager->mountChild($parent, 'deep<slot>', ['component'=>'missing']));
		$managerInternals->writeProperty('mountDepth', 0);
		$child=ReactorComponent::make('object-child')->state(['label'=>'Object'])->render('<p>{{ label }}</p>');
		$html=$manager->mountChild($parent, 'object', [
			'component'=>$child,
			'state'=>static fn(): string=>'not-state',
			'attributes'=>'invalid',
		]);
		$t->contains('Object', $html);

		$runtime=ReactorComponent::make('runtime')
			->state(['count'=>1, 'profile'=>['name'=>'Old']])
			->model('profile.name')
			->validateOnUpdate(['profile.name'])
			->rules(['profile.name'=>'required'])
			->action('skip', static function(array $state, array $params, ReactorComponent $component, ReactorEffects $effects): array {
				$effects->skipRender();
				return $state;
			})
			->action('explode', static function(): never { throw new RuntimeException('reactor explosion'); })
			->render('<p>{{ profile.name }}</p>');
		$manager->register($runtime);
		$validSnapshot=$manager->snapshot('runtime', ['count'=>1, 'profile'=>['name'=>'Old']]);
		$tampered=$validSnapshot->jsonSerialize();
		$tampered['state']['count']=99;
		$t->same(419, $manager->dispatch(['component'=>'runtime', 'snapshot'=>$tampered])->status());
		$t->same(419, $manager->dispatch([
			'component'=>'runtime',
			'snapshot'=>ReactorSnapshot::make('other', [], [], ['audience'=>'reactor:runtime-test'])->jsonSerialize(),
		])->status());

		$denied=ReactorComponent::make('denied')->authorize(static fn(): bool=>false)->render('denied');
		$manager->register($denied);
		$t->same(403, $manager->dispatch(['component'=>'denied'])->status());

		$modelResponse=$manager->dispatch([
			'component'=>'runtime',
			'snapshot'=>$validSnapshot->jsonSerialize(),
			'state'=>['profile'=>['name'=>'New']],
			'params'=>['_reactor'=>['model'=>'profile.name', 'event'=>'blur']],
		]);
		$t->same(200, $modelResponse->status());
		$t->contains('New', $modelResponse->html());
		$t->same(200, $manager->dispatch([
			'component'=>'runtime',
			'snapshot'=>$manager->snapshot('runtime', ['count'=>1, 'profile'=>['name'=>'Old']])->jsonSerialize(),
			'state'=>['profile'=>['name'=>'Old']],
			'params'=>['_reactor'=>['model'=>'profile.name']],
		])->status());
		$t->same(200, $manager->dispatch([
			'component'=>'runtime',
			'snapshot'=>$manager->snapshot('runtime', ['count'=>1, 'profile'=>['name'=>'Old']])->jsonSerialize(),
			'state'=>['profile'=>['name'=>'New'], 'extra'=>['deep'=>1]],
		])->status());

		$signed=ReactorComponent::make('signed')
			->requireSignedParams()
			->action('save', static fn(array $state): array=>$state)
			->render('signed');
		$manager->register($signed);
		$t->same(419, $manager->dispatch(['component'=>'signed', 'action'=>'save'])->status());

		$locked=ReactorComponent::make('locked')
			->state(['id'=>7])
			->lockedParams(['id'=>null])
			->action('save', static fn(array $state): array=>$state)
			->render('locked');
		$manager->register($locked);
		$t->same(419, $manager->dispatch([
			'component'=>'locked', 'action'=>'save', 'params'=>['id'=>8],
		])->status());
		$skip=$manager->dispatch(['component'=>'runtime', 'action'=>'skip', 'snapshot'=>$manager->snapshot('runtime')->jsonSerialize()]);
		$t->same(200, $skip->status());
		$t->same('', $skip->html());
		$t->same(500, $manager->dispatch(['component'=>'runtime', 'action'=>'explode', 'snapshot'=>$manager->snapshot('runtime')->jsonSerialize()])->status());

		$configuredObject=ReactorComponent::make('configured-object')->state(['kind'=>'object'])->render('object');
		\dataphyre\reactor::$runtimeConfig=[
			'secret'=>'runtime-secret',
			'components'=>[
				'configured_object'=>$configuredObject,
				'configured_array'=>['state'=>['kind'=>'array'], 'render'=>'array'],
				'configured_callable'=>static fn(ReactorComponent $component): ReactorComponent=>$component->state(['kind'=>'callable'])->render('callable'),
				'configured_mutator'=>static function(ReactorComponent $component): void { $component->render('mutated'); },
				'configured_invalid'=>42,
			],
		];
		$configured=(new ReactorManager())->trustInternalTransport('reactor:runtime-test');
		$t->same('configured-object', $configured->snapshot('configured_object')->component());
		$t->same('configured_array', $configured->snapshot('configured_array')->component());
		$t->same('configured_callable', $configured->snapshot('configured_callable')->component());
		$t->same('configured_mutator', $configured->snapshot('configured_mutator')->component());
		$t->throws(static fn()=>$configured->snapshot('configured_invalid'), InvalidArgumentException::class);
		\dataphyre\reactor::$runtimeConfig=['components'=>'invalid'];
		$t->throws(static fn()=>(new ReactorManager())->snapshot('none'), InvalidArgumentException::class);
	}
	finally{
		\dataphyre\reactor::$runtimeConfig=$previousConfig;
	}
})->tag('reactor', 'coverage', 'reactor-runtime')->group('framework-coverage');

test('reactor endpoint covers single batch manifest emission and JSON failures', static function(Context $t): void {
	$previousConfig=\dataphyre\reactor::$runtimeConfig;
	$t->globalMap('_GET')->replace(['component'=>'endpoint']);
	$t->globalMap('_POST')->clear();
	$t->globalMap('_FILES')->clear();
	$t->globalMap('_SERVER')->replace(['CONTENT_TYPE'=>'text/plain']);
	try{
		\dataphyre\reactor::$runtimeConfig=['secret'=>'runtime-secret'];
		$store=new ReactorFileSnapshotVersionStore($t->workspace('reactor-endpoint-snapshot-store')->root(), sharedFilesystemAttested:true);
		$manager=Reactor::reset((new ReactorManager($store))->trustInternalTransport('reactor:endpoint-test'));
		$manager->register(ReactorComponent::make('endpoint')->state(['count'=>1])->action('increment', static fn(array $state): array=>['count'=>(int)($state['count']??0)+1])->render('<p>{{ count }}</p>'));
		$manager->register(ReactorComponent::make('bad-state')->state(['bad'=>INF])->render('bad'));
		$manager->register(ReactorComponent::make('bad-json')->state(['safe'=>true])->render("\xB1"));

		$t->instanceOf(ReactorResponse::class, ReactorEndpoint::handle(['component'=>'endpoint']));
		$batch=ReactorEndpoint::handleBatch([
			['component'=>'endpoint'],
			['component'=>'missing'],
		]);
		$t->same(2, count($batch));
		$t->isTrue($batch[0]['ok']);
		$t->isFalse($batch[1]['ok']);

		$t->same(1, count(ReactorEndpoint::handleBatch(null)));

		$single=ReactorEndpoint::emit(['component'=>'endpoint'], false);
		$t->pathEquals('ok', true, $t->jsonArray($single));
		$echoed=$t->captureOutput(static fn()=>ReactorEndpoint::emit(['component'=>'endpoint'], true));
		$t->same($echoed->result(), $echoed->output());

		$batchJson=ReactorEndpoint::emitBatch([
			['component'=>'endpoint'],
			['component'=>'missing'],
		], false);
		$t->pathEquals('ok', false, $t->jsonArray($batchJson));
		$echoedBatch=$t->captureOutput(static fn()=>ReactorEndpoint::emitBatch([['component'=>'endpoint']], true));
		$t->same($echoedBatch->result(), $echoedBatch->output());

		$manifest=ReactorEndpoint::emitManifest(false);
		$manifestPayload=$t->jsonArray($manifest);
		$t->pathEquals('module', 'reactor', $manifestPayload);
		$t->pathEquals('signing.secrets_serialized', false, $manifestPayload);
		$t->pathEquals('trace.detail_exposed', true, $manifestPayload);
		$echoedManifest=$t->captureOutput(static fn()=>ReactorEndpoint::emitManifest(true));
		$t->same($echoedManifest->result(), $echoedManifest->output());

		ReactorTrace::record('production.secret', ['authorization'=>'Bearer must-not-leak']);
		\dataphyre\reactor::$runtimeConfig=[
			'secret'=>str_repeat('p', 32),
			'production'=>true,
		];
		$productionManifest=json_decode(ReactorEndpoint::emitManifest(false), true, flags: JSON_THROW_ON_ERROR);
		$t->pathEquals('signing.production', true, $productionManifest);
		$t->pathEquals('trace.detail_exposed', false, $productionManifest);
		$t->pathEquals('trace.latest', [], $productionManifest);
		$t->pathEquals('trace.active_spans', [], $productionManifest);
		$t->notContains('must-not-leak', json_encode($productionManifest, JSON_THROW_ON_ERROR));
		$t->same(419, $manager->dispatch(['component'=>'endpoint','action'=>'increment'])->status());
		$t->same(419, $manager->dispatch(['component'=>'endpoint','state'=>['count'=>9]])->status());
		$signedMutation=$manager->snapshot('endpoint', ['count'=>1]);
		$t->same(200, $manager->dispatch(['component'=>'endpoint','action'=>'increment','snapshot'=>$signedMutation->jsonSerialize()])->status());
		\dataphyre\reactor::$runtimeConfig=[
			'secret'=>str_repeat('p', 32),
			'production'=>true,
			'expose_trace_manifest'=>true,
		];
		$diagnosticManifest=json_decode(ReactorEndpoint::emitManifest(false), true, flags: JSON_THROW_ON_ERROR);
		$t->pathEquals('trace.detail_exposed', true, $diagnosticManifest);
		$t->notContains('must-not-leak', json_encode($diagnosticManifest, JSON_THROW_ON_ERROR));
		\dataphyre\reactor::$runtimeConfig=['secret'=>'runtime-secret','require_signed_mutation_snapshots'=>true];
		$t->same(419, $manager->dispatch(['component'=>'endpoint','params'=>['count'=>2]])->status());
		\dataphyre\reactor::$runtimeConfig=['secret'=>'runtime-secret','require_signed_mutation_snapshots'=>'invalid'];
		$t->same(500, $manager->dispatch(['component'=>'endpoint','state'=>['count'=>2]])->status());
		\dataphyre\reactor::$runtimeConfig=['secret'=>'runtime-secret'];

		$badState=ReactorEndpoint::emit(['component'=>'bad-state'], false);
		$t->contains('Reactor request failed.', $badState);
		$t->notContains('Inf and NaN', $badState);
		$badJson=json_decode(ReactorEndpoint::emit(['component'=>'bad-json'], false), true, flags: JSON_THROW_ON_ERROR);
		$t->pathEquals('status', 500, $badJson);
		$t->pathEquals('effects.error.code', 'reactor_request_failed', $badJson);
		$badJsonBatch=json_decode(ReactorEndpoint::emitBatch([['component'=>'bad-json']], false), true, flags: JSON_THROW_ON_ERROR);
		$t->pathEquals('status', 500, $badJsonBatch);
		$t->pathEquals('batch.0.effects.error.code', 'reactor_request_failed', $badJsonBatch);
		ReactorTrace::record('invalid.manifest', ['invalid_utf8'=>"\xB1"]);
		$t->notContains('Unable to encode Reactor manifest', ReactorEndpoint::emitManifest(false));
		try{
			ReactorManifest::useVersionFile($t->workspace('reactor-missing-manifest')->path('missing-version'));
			$t->pathEquals('version', '1.0.0', json_decode(ReactorEndpoint::emitManifest(false), true, flags: JSON_THROW_ON_ERROR));
			ReactorManifest::useVersionFile($t->workspace('reactor-invalid-manifest')->file('version', "\xB1"));
			$t->contains('Unable to encode Reactor manifest', ReactorEndpoint::emitManifest(false));
		}
		finally{ ReactorManifest::useVersionFile(null); }
	}
	finally{
		ReactorRequest::useInputReader(null);
		\dataphyre\reactor::$runtimeConfig=$previousConfig;
	}
})->tag('reactor', 'coverage', 'reactor-runtime')->group('framework-coverage');
