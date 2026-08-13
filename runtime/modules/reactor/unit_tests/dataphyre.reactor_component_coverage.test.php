<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Reactor\ReactorEffects;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>[
			'core'=>true,
			'mvc'=>true,
			'reactor'=>true,
			'templating'=>false,
		],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_reactor_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'mvc', 'reactor']);

suite('Reactor component definition and lifecycle contracts')
	->contract('reactor.component-lifecycle', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:reactor')
	->through('component-definition', 'security', 'validation', 'action', 'render', 'session-lifecycle')
	->isolation('case')
	->tag('reactor', 'component-lifecycle')
	->group('framework-coverage');

test('reactor component definitions normalize the complete manifest contract', static function(Context $t): void {
	$callback=static fn(array $state): array=>$state;
	$component=ReactorComponent::fromArray([
		'name'=>' Order Editor ',
		'state'=>['id'=>7, 'profile'=>['email'=>'ada@example.test'], 'count'=>1],
		'locked'=>['id', 'profile.email'],
		'locked_params'=>[
			'id'=>null,
			'tenant'=>'state:tenant_id',
			'scope'=>['value'=>'orders'],
			'page'=>2,
		],
		'require_signed_params'=>true,
		'render'=>'<p>{{ profile.email }}</p>',
		'authorize'=>static fn(): bool=>true,
		'rules'=>['profile.email'=>'required|email', 'count'=>'integer|min:1|max:9'],
		'messages'=>['profile.email.required'=>'Email is required.'],
		'validate_actions'=>'save, publish',
		'validate_updates'=>'profile.email,count',
		'actions'=>['save'=>$callback],
		'computed'=>['summary'=>static fn(array $state): string=>'#'.($state['id'] ?? '')],
		'updating'=>['count'=>$callback],
		'updated'=>['count'=>$callback],
		'lifecycle'=>['booted'=>$callback],
		'listeners'=>[
			'orders.refresh'=>'save',
			'orders.inline'=>static fn(array $state): array=>$state,
		],
		'models'=>[
			'profile.email'=>['mode'=>'blur', 'debounce_ms'=>6000],
			'count'=>['mode'=>'invalid', 'debounce_ms'=>-20],
		],
		'url'=>[
			'profile.email'=>['history'=>'push', 'as'=>'email', 'except_empty'=>false],
			'count'=>['history'=>'invalid', 'as'=>'bad name'],
		],
		'persist'=>[
			'count'=>['driver'=>'session', 'key'=>'counter'],
			'profile.email'=>['driver'=>'invalid', 'key'=>''],
		],
		'session'=>['count'=>['key'=>'reactor.counter']],
		'children'=>[
			'header'=>['component'=>['name'=>'order-header'], 'state'=>['compact'=>true], 'attributes'=>['class'=>'header']],
			['slot'=>'footer', 'name'=>'order-footer'],
		],
	]);

	$component
		->hydrate(static fn(array $state): array=>array_replace($state, ['hydrated'=>true]))
		->dehydrateUsing(static fn(array $state): array=>array_replace($state, ['dehydrated'=>true]))
		->model('title', 'change', 40)
		->url('title', 'replace', 'q')
		->persist('title', 'local')
		->session('title')
		->child('aside', ReactorComponent::make('order-aside'), static fn(): array=>['visible'=>true], ['role'=>'complementary']);

	$t->same('order_editor', $component->name());
	$t->same(['id', 'profile.email'], $component->lockedStateKeys());
	$t->same(['id', 'tenant', 'scope', 'page'], $component->lockedParamKeys());
	$t->same('save', $component->clientListeners()['orders.refresh']);
	$t->same('listener_orders.inline', $component->clientListeners()['orders.inline']);
	$t->same('blur', $component->clientBindings()['models']['profile.email']['mode']);
	$t->same(5000, $component->clientBindings()['models']['profile.email']['debounce_ms']);
	$t->same('live', $component->clientBindings()['models']['count']['mode']);
	$t->same(0, $component->clientBindings()['models']['count']['debounce_ms']);
	$t->same('replace', $component->clientBindings()['url']['count']['history']);
	$t->same('count', $component->clientBindings()['url']['count']['as']);
	$t->same('local', $component->clientBindings()['persist']['profile.email']['driver']);
	$t->same('order_editor.profile.email', $component->clientBindings()['persist']['profile.email']['key']);
	$t->same('order-header', $component->childDefinitions()['header']['component']);
	$t->isTrue($component->childDefinitions()['aside']['state_resolver']);
	$t->contains('summary', $component->jsonSerialize()['computed']);
	$t->contains('orders.inline', array_keys($component->jsonSerialize()['listeners']));
	$t->contains('data-dp-reactor-child-slot="main"', ReactorComponent::childSlot('main', 'Empty'));

	$initial=$component->initialState(['id'=>999, 'tenant_id'=>44]);
	$t->pathEquals('hydrated', true, $initial);
	$t->pathEquals('summary', '#999', $initial);
	$enforced=$component->enforceLockedState(['id'=>999, 'profile'=>['email'=>'changed@example.test']], ['id'=>8]);
	$t->same(8, $enforced['id']);
	$t->same('ada@example.test', $enforced['profile']['email']);
	$t->pathEquals('dehydrated', true, $component->dehydrate($initial));

	$t->throws(static fn()=>ReactorComponent::make('x')->action('', $callback), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorComponent::make('x')->computed('', $callback), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorComponent::make('x')->listen('', 'save'), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorComponent::make('x')->listen('event', ''), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorComponent::make('x')->lifecycle('', $callback), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorComponent::make('x')->updating('', null), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorComponent::make('x')->child('', 'child'), InvalidArgumentException::class);
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor component security validation actions and lifecycle cover success and failure contracts', static function(Context $t): void {
	$effects=ReactorEffects::make();
	$request=ReactorRequest::fromArray([
		'component'=>'secure-editor',
		'action'=>'save',
		'state'=>['id'=>7],
		'params'=>['id'=>7],
		'headers'=>['x-dataphyre-reactor'=>'DataphyreReactor'],
	]);
	$component=ReactorComponent::make('secure-editor')
		->state(['id'=>7, 'tenant_id'=>42, 'profile'=>['email'=>'ada@example.test'], 'count'=>1])
		->locked(['id', 'profile.email'])
		->lockedParams([
			'id'=>null,
			'tenant'=>'state:tenant_id',
			'scope'=>['value'=>'orders'],
			'meta'=>['value'=>['b'=>2, 'a'=>1]],
		])
		->requireSignedParams()
		->authorize(static fn(array $state, ?ReactorRequest $request, ReactorComponent $component, ?string $action): mixed=>$state['auth'] ?? false)
		->rules(static fn(array $state): array=>[
			'profile.email'=>'required|email',
			'count'=>'required|integer|min:1|max:5',
		], ['profile.email.required'=>'Email missing.'], ['save'])
		->validateOnUpdate(['profile.email', 'count'])
		->computed('double', static fn(array $state): int=>(int)($state['count'] ?? 0) * 2)
		->action('save', static fn(array $state, array $params, ReactorComponent $component, ReactorEffects $effects): array=>[
			'state'=>['count'=>(int)$state['count'] + (int)($params['step'] ?? 1)],
			'effects'=>['saved'=>true],
		])
		->action('patch', static fn(): array=>['count'=>4])
		->action('noop', static fn(): string=>'ignored')
		->action('effects', static fn(): ReactorEffects=>ReactorEffects::make()->toast('Saved'))
		->updating('count', static fn(mixed $value): array=>['before'=>$value])
		->updating(static fn(mixed $value): array=>['wild_before'=>$value])
		->updated('count', static fn(mixed $value): array=>['after'=>$value])
		->updated(static fn(mixed $value): array=>['wild_after'=>$value])
		->lifecycle(['boot', 'finish'], static fn(array $state, array $context, ReactorComponent $component, ReactorEffects $effects): array=>[
			'state'=>['phase'=>$context['phase'] ?? 'unknown'],
			'effects'=>['lifecycle'=>true],
		])
		->lifecycle('object_effect', static fn(): ReactorEffects=>ReactorEffects::make()->title('Ready'))
		->lifecycle('ignored', static fn(): string=>'ignored');

	$t->pathEquals('ok', true, ReactorComponent::make('open')->authorizeRequest([]));
	$t->pathEquals('ok', true, ReactorComponent::make('truthy')->authorize(static fn()=>null)->authorizeRequest([]));
	$t->pathEquals('status', 451, ReactorComponent::make('array')->authorize(static fn()=>['ok'=>false, 'status'=>451, 'message'=>'Denied'])->authorizeRequest([]));
	$t->same('Denied', ReactorComponent::make('string')->authorize(static fn()=>' Denied ')->authorizeRequest([])['message']);
	$t->pathEquals('ok', false, $component->authorizeRequest([], $request, 'save'));
	$t->pathEquals('ok', true, $component->authorizeRequest(['auth'=>true], $request, 'save'));

	$t->pathEquals('ok', true, ReactorComponent::make('none')->authorizeActionParams([], []));
	$t->pathEquals('status', 419, $component->authorizeActionParams(['id'=>7], [], 'save'));
	$t->pathEquals('status', 419, $component->authorizeActionParams(['id'=>7], ['id'=>7, 'tenant'=>42, 'scope'=>'orders', 'meta'=>[]], 'save'));
	$t->pathEquals('status', 419, $component->authorizeActionParams(['id'=>7, 'tenant_id'=>42], ['id'=>8, 'tenant'=>42, 'scope'=>'orders', 'meta'=>['a'=>1, 'b'=>2]], 'save'));
	$t->pathEquals('ok', true, $component->authorizeActionParams(
		['id'=>7, 'tenant_id'=>42],
		['id'=>'7', 'tenant'=>'42', 'scope'=>'orders', 'meta'=>['a'=>1, 'b'=>2]],
		'save'
	));

	$t->pathEquals('status', 419, $component->resolveSignedActionParams(['plain'=>1], 'save'));
	$signed=$component->signedParams('save', ['step'=>2]);
	$t->contains('_reactor_signed', $component->signedParamsJson('save', ['step'=>2]));
	$t->pathEquals('step', 2, $component->resolveSignedActionParams(array_replace(['plain'=>1], $signed), 'save')['params']);
	$bad=$signed;
	$bad['_reactor_signed']['signature']='invalid';
	$t->pathEquals('status', 419, $component->resolveSignedActionParams($bad, 'save'));
	$t->pathEquals('ok', true, ReactorComponent::make('unsigned')->resolveSignedActionParams(['plain'=>1], null));

	$invalid=['profile'=>['email'=>''], 'count'=>9];
	$t->notEmpty($component->validate($invalid, $effects));
	$t->notEmpty($effects->all()['errors']);
	$t->same([], $component->validate(['profile'=>['email'=>'ada@example.test'], 'count'=>2]));
	$t->same([], ReactorComponent::make('no-rules')->validate([]));
	$t->notEmpty($component->validateModelUpdates($invalid, [['field'=>'profile.email', 'value'=>'']], $effects));
	$t->same([], $component->validateModelUpdates($invalid, [['field'=>'unwatched', 'value'=>'']], $effects));
	$t->same([], $component->validateModelUpdates($invalid, [], $effects));

	$blocked=$component->callAction('save', $invalid, ['step'=>2], $effects);
	$t->same(9, $blocked['count']);
	$saved=$component->callAction('save', ['profile'=>['email'=>'ada@example.test'], 'count'=>2], ['step'=>2], $effects);
	$t->same(4, $saved['count']);
	$t->same(8, $saved['double']);
	$t->pathEquals('saved', true, $effects->all());
	$t->same(4, $component->callAction('patch', ['count'=>1])['count']);
	$t->same(1, $component->callAction('noop', ['count'=>1])['count']);
	$t->same(1, $component->callAction('effects', ['count'=>1], [], $effects)['count']);
	$t->throws(static fn()=>$component->callAction('missing', []), InvalidArgumentException::class);

	$changed=$component->applyModelLifecycle(['count'=>1], [['field'=>'count', 'value'=>3]], $effects, $request);
	$t->same(3, $changed['before']);
	$t->same(3, $changed['wild_before']);
	$t->same(3, $changed['after']);
	$t->same(3, $changed['wild_after']);
	$t->same([], $component->applyModelLifecycle([], [], $effects));
	$t->pathEquals('phase', 'ready', $component->runLifecycle('boot', [], ['phase'=>'ready'], $effects));
	$t->same([], $component->runLifecycle('unknown', []));
	$t->same(['double'=>0], $component->runLifecycle('ignored', [], [], $effects));
	$t->same(['double'=>0], $component->runLifecycle('object_effect', [], [], $effects));
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor component renderers mount callable string and child slot variants', static function(Context $t): void {
	$manager=(new ReactorManager())->trustInternalTransport('reactor:component-test');
	$manager->register(ReactorComponent::make('child')->state(['label'=>'Child'])->render('<strong>{{ label }}</strong>'));
	$manager->register(ReactorComponent::make('inline-child')->state(['label'=>'Inline'])->render(static fn(array $state): string=>'<i>'.($state['label'] ?? '').'</i>'));

	$parent=ReactorComponent::make('parent')
		->state(['label'=>'Parent'])
		->listen('refresh', 'reload')
		->models('label')
		->url('label')
		->persist('label')
		->child('main', 'child', static fn(array $state): array=>['label'=>$state['label'].' child'])
		->child('inline', ['name'=>'inline-child'], ['label'=>'Array child'])
		->child('missing', 'missing-child')
		->render(static fn(array $state): string=>'<section>'.ReactorComponent::childSlot('main').'<div>{{ reactor:inline }}</div></section>');
	$manager->register($parent);

	$html=$parent->renderHtml(['label'=>'Parent'], ReactorRequest::fromArray(['component'=>'parent']), $manager);
	$t->contains('Parent child', $html);
	$t->contains('Array child', $html);
	$t->contains('Reactor child missing', $html);
	$t->contains('data-dp-reactor-parent="parent"', $html);

	$mount=$manager->mount('parent', ['label'=>'Mounted'], ['class'=>'reactor-root']);
	$t->contains('data-dp-reactor-listeners', $mount);
	$t->contains('data-dp-reactor-models', $mount);
	$t->contains('data-dp-reactor-url', $mount);
	$t->contains('data-dp-reactor-persist', $mount);
	$t->contains('reactor-root', $mount);
	$t->contains('Mounted child', $mount);

	$string=ReactorComponent::make('string-render')->render('<p>{{ user.name }} {{ missing }}</p>');
	$t->same('<p>Ada &amp; Grace </p>', $string->renderHtml(['user'=>['name'=>'Ada & Grace']]));
	$t->same('', ReactorComponent::make('empty-render')->renderHtml([]));
	$t->contains('Reactor child missing', $manager->mountChild($parent, 'orphan', ['component'=>'unknown'], []));
	$t->throws(static fn()=>$manager->mount('unknown'), InvalidArgumentException::class);
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor component definition aliases and edge helpers preserve normalized contracts', static function(Context $t): void {
	$patch=static fn(array $state): array=>$state;
	$component=ReactorComponent::fromArray([
		'name'=>'remaining-edges',
		'state'=>[
			'tenant'=>7,
			'profile'=>['email'=>'server@example.test'],
		],
		'locked'=>['profile.email'],
		'locked_params'=>[
			'tenant',
			'bad field',
			'email'=>['source'=>'state', 'path'=>'profile.email'],
			'nested'=>['value'=>['outer'=>['b'=>2, 'a'=>1]]],
		],
		'model'=>[
			'nickname',
			'bad field',
			'search'=>'query',
		],
		'children'=>['footer'=>'footer-component'],
	]);
	$component
		->rules(['profile.email'=>'required'], [], false)
		->validateOnUpdate(false)
		->hydrating($patch)
		->hydrated($patch)
		->actionCalling($patch)
		->actionCalled($patch)
		->rendering($patch)
		->rendered($patch)
		->dehydrating($patch)
		->dehydrated($patch);

	$t->same(['tenant', 'email', 'nested'], $component->lockedParamKeys());
	$t->contains('nickname', array_keys($component->clientBindings()['models']));
	$t->contains('search', array_keys($component->clientBindings()['models']));
	$t->same('footer-component', $component->childDefinitions()['footer']['component']);

	$authorized=$component->authorizeActionParams(
		['tenant'=>7, 'profile'=>['email'=>'server@example.test']],
		[
			'tenant'=>'7',
			'email'=>'server@example.test',
			'nested'=>['outer'=>['a'=>1, 'b'=>2]],
		]
	);
	$t->pathEquals('ok', true, $authorized);

	$initial=$component->initialState(['profile'=>'client-scalar']);
	$t->pathEquals('profile.email', 'server@example.test', $initial);

	$events=['hydrating', 'hydrated', 'action_calling', 'action_called', 'rendering', 'rendered', 'dehydrating', 'dehydrated'];
	foreach($events as $event){
		$t->same([], $component->runLifecycle($event, []));
	}
	$component->lifecycle('plain_patch', static fn(): array=>['patched'=>true]);
	$t->pathEquals('patched', true, $component->runLifecycle('plain_patch', []));

	$always=ReactorComponent::make('always-validates')
		->rules(['name'=>'required'], [], true)
		->action('save', static fn(): array=>['saved'=>true]);
	$t->pathEquals('saved', true, $always->callAction('save', ['name'=>'Ada']));

	$updates=ReactorComponent::make('update-edges')
		->rules(['name'=>'required'], [], false)
		->validateOnUpdate(true);
	$effects=ReactorEffects::make();
	$t->same([], $updates->validateModelUpdates([], [['field'=>'bad field', 'value'=>'x']], $effects));
	$t->same([], $updates->validateModelUpdates([], [['field'=>'unruled', 'value'=>'x']], $effects));
	$t->same([], $updates->validate([], null, ['bad field']));

	$componentInternals=$t->nonPublic(ReactorComponent::class);
	$t->same(null, $componentInternals->invoke('pathValue', ['profile'=>[]], 'profile.missing'));

	$setPath=$componentInternals->capture('setPath', state: ['unchanged'=>true], path: '', value: 'ignored');
	$t->same(['unchanged'=>true], $setPath->argument('state'));
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor component session bindings hydrate stored fields and preserve incoming fields', static function(Context $t): void {
	if(session_status()===PHP_SESSION_ACTIVE){
		session_write_close();
	}
	@ini_set('session.use_cookies', '0');
	@session_id('dp-reactor-component-'.getmypid());
	$t->isTrue(@session_start());
	$t->globalMap('_SESSION')->put('dataphyre_reactor', [
		'edge.profile'=>'stored@example.test',
		'edge.locale'=>'en-CA',
	]);

	$component=ReactorComponent::make('session-edges')
		->state(['profile'=>['email'=>'default@example.test'], 'locale'=>'fr-CA'])
		->session([
			'profile.email'=>['key'=>'edge.profile'],
			'locale'=>['key'=>'edge.locale'],
		]);
	$hydrated=$component->initialState([]);
	$t->pathEquals('profile.email', 'stored@example.test', $hydrated);
	$t->same('en-CA', $hydrated['locale']);

	$incoming=$component->initialState([
		'profile'=>['email'=>'client@example.test'],
		'locale'=>'de-DE',
	]);
	$t->pathEquals('profile.email', 'client@example.test', $incoming);
	$t->same('de-DE', $incoming['locale']);
	@session_destroy();
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor component session startup records thrown handler failures', static function(Context $t): void {
	if(session_status()===PHP_SESSION_ACTIVE){
		session_write_close();
	}
	@ini_set('session.use_cookies', '0');
	$handler=new class implements SessionHandlerInterface {
		public function open(string $path, string $name): bool {
			throw new RuntimeException('session-open-failed');
		}
		public function close(): bool { return true; }
		public function read(string $id): string|false { return ''; }
		public function write(string $id, string $data): bool { return true; }
		public function destroy(string $id): bool { return true; }
		public function gc(int $max_lifetime): int|false { return 0; }
	};
	$t->isTrue(session_set_save_handler($handler, true));
	$component=ReactorComponent::make('session-failure')->state(['value'=>'default'])->session('value');
	$t->same(['value'=>'default'], $component->initialState([]));
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor component session startup declines after response headers are sent', static function(Context $t): void {
	if(session_status()===PHP_SESSION_ACTIVE){
		session_write_close();
	}
	while(ob_get_level()>0){
		ob_end_clean();
	}
	echo 'reactor-session-headers-sent';
	$component=ReactorComponent::make('session-headers')->state(['value'=>'default'])->session('value');
	$t->same(['value'=>'default'], $component->initialState([]));
})->tag('reactor', 'coverage')->group('framework-coverage');
