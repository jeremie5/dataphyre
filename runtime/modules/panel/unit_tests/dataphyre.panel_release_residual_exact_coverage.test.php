<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel\TestFixtures {
	final class PanelReleaseResidualProbe {
		/** @var array<string,\Closure> */
		private static array $hooks=[];

		public static function reset(): void { self::$hooks=[]; }
		public static function hook(string $name, \Closure $hook): void { self::$hooks[$name]=$hook; }
		public static function call(string $name, array $arguments, \Closure $native): mixed {
			$hook=self::$hooks[$name] ?? null;
			return $hook instanceof \Closure ? $hook(...$arguments) : $native();
		}
	}
}

namespace Dataphyre\Panel {
	use Dataphyre\Panel\TestFixtures\PanelReleaseResidualProbe as Probe;

	function random_bytes(int $length): string {
		return (string)Probe::call('random_bytes', func_get_args(), static fn(): string=>\random_bytes($length));
	}
}

namespace {
	use Dataphyre\Panel\Action;
	use Dataphyre\Panel\AutomationRegistry;
	use Dataphyre\Panel\Bridges\Reactor\PanelReactorWidgetController;
	use Dataphyre\Panel\Field;
	use Dataphyre\Panel\InMemoryWorkflowStore;
	use Dataphyre\Panel\Panel;
	use Dataphyre\Panel\PanelArrayDataSource;
	use Dataphyre\Panel\PanelAtomicAgentWorkflowStore;
	use Dataphyre\Panel\PanelComponentRegistry;
	use Dataphyre\Panel\PanelConfig;
	use Dataphyre\Panel\PanelContext;
	use Dataphyre\Panel\PanelCoverageGate;
	use Dataphyre\Panel\PanelDataQuery;
	use Dataphyre\Panel\PanelDataSourceResourceBridge;
	use Dataphyre\Panel\PanelDataSourceResourceQuery;
	use Dataphyre\Panel\PanelEditorHtmlSanitizer;
	use Dataphyre\Panel\PanelErrorEnvelope;
	use Dataphyre\Panel\PanelFilesystemPath;
	use Dataphyre\Panel\PanelHttpDataSourceRuntime;
	use Dataphyre\Panel\PanelHttpDataSourceTransportRequest;
	use Dataphyre\Panel\PanelInMemoryPreferenceStore;
	use Dataphyre\Panel\PanelInMemoryStudioStore;
	use Dataphyre\Panel\PanelInstance;
	use Dataphyre\Panel\PanelInstanceExtensionRegistry;
	use Dataphyre\Panel\PanelMigrationRegistry;
	use Dataphyre\Panel\PanelNavigationIntentManager;
	use Dataphyre\Panel\PanelNavigationKeyProvider;
	use Dataphyre\Panel\PanelNavigationSigningKey;
	use Dataphyre\Panel\PanelOperationStatus;
	use Dataphyre\Panel\PanelPage;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Panel\PanelPlatform;
	use Dataphyre\Panel\PanelPlatformManifest;
	use Dataphyre\Panel\PanelPlugin;
	use Dataphyre\Panel\PanelQualityMatrix;
	use Dataphyre\Panel\PanelQueryBetween;
	use Dataphyre\Panel\PanelQueryCapabilities;
	use Dataphyre\Panel\PanelQueryComparison;
	use Dataphyre\Panel\PanelQueryGroup;
	use Dataphyre\Panel\PanelQueryIn;
	use Dataphyre\Panel\PanelQueryNull;
	use Dataphyre\Panel\PanelQueryRelation;
	use Dataphyre\Panel\PanelRealtimeSseResponse;
	use Dataphyre\Panel\PanelRenderer;
	use Dataphyre\Panel\PanelRequest;
	use Dataphyre\Panel\PanelScaffoldWriter;
	use Dataphyre\Panel\PanelStudioDefinition;
	use Dataphyre\Panel\PanelStudioDocument;
	use Dataphyre\Panel\PanelStudioEditorOptions;
	use Dataphyre\Panel\PanelStudioManager;
	use Dataphyre\Panel\PanelStudioPolicy;
	use Dataphyre\Panel\PanelTenantStorageScope;
	use Dataphyre\Panel\PanelThemePreview;
	use Dataphyre\Panel\PanelWorkspacePreferencesFactory;
	use Dataphyre\Panel\Resource;
	use Dataphyre\Panel\TestFixtures\PanelReleaseResidualProbe as Probe;
	use Dataphyre\Panel\Widget;
	use Dataphyre\Panel\WorkflowActor;
	use Dataphyre\Panel\WorkflowDefinition;
	use Dataphyre\Panel\WorkflowEngine;
	use Dataphyre\Panel\WorkflowRecord;
	use Dataphyre\Panel\WorkflowStore;
	use Dataphyre\Panel\WorkflowTransition;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	require_once __DIR__.'/panel_test_harness_helpers.php';
	dp_panel_unit_test_bootstrap();

	final class DpPanelReleaseResidualCallable {
		public static function invoke(Field $field, string $suffix): string { return $field->name().$suffix; }
	}

	function dp_panel_release_residual_plugin(string $id, array $dependencies=[]): PanelPlugin {
		return new class($id, $dependencies) implements PanelPlugin {
			/** @param list<string> $dependencies */
			public function __construct(private string $pluginId, private array $dependencies){}
			public function id(): string { return $this->pluginId; }
			/** @return list<string> */ public function dependencies(): array { return $this->dependencies; }
			public function register(PanelInstance $panel): void {}
			public function boot(PanelInstance $panel): void {}
		};
	}

	test('release residual value objects expose every canonical accessor and default catalogue', static function(Context $t): void {
		$atomic=new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-release-residual-agent'));
		$initial=$t->nonPublic($atomic)->invoke('initialState');
		$t->same(0, $initial['revision']);
		$t->same('dataphyre_panel_agent_workflow_state', $initial['type']);

		$t->same(0, (new AutomationRegistry())->revision());
		$t->notEmpty($t->nonPublic(PanelReactorWidgetController::class)->invoke('responseHeaders'));
		$t->same('application/json; charset=utf-8', (new PanelHttpDataSourceTransportRequest(
			'https://private.test', '{}', 1000, 100, 1,
			new class implements PanelHttpDataSourceRuntime {
				public function nowMilliseconds(): int { return 0; }
				public function requestId(): string { return 'residual'; }
				public function cancellationRequested(): bool { return false; }
				public function cancellationReason(): ?string { return null; }
				public function waitMilliseconds(int $milliseconds, int $deadlineUnixMilliseconds): bool { return true; }
			}
		))->contentType());

		$t->same('between', PanelQueryBetween::make('amount', 1, 2)->type());
		$t->same('comparison', PanelQueryComparison::make('status', 'eq', 'open')->type());
		$group=PanelQueryGroup::any(
			PanelQueryComparison::make('status', 'eq', 'open'),
			PanelQueryComparison::make('status', 'eq', 'closed'),
		);
		$t->same('group', $group->type());
		$t->same('in', PanelQueryIn::make('id', [1, 2])->type());
		$t->same('null', PanelQueryNull::make('deleted_at')->type());
		$t->same('relation', PanelQueryRelation::make('items', PanelQueryComparison::make('sku', 'eq', 'A'))->type());
		PanelQueryCapabilities::fromArray(PanelQueryCapabilities::full('residual'))->assertSupports(
			PanelDataQuery::make()->replaceExpression($group)
		);

		$t->notEmpty($t->nonPublic(PanelQualityMatrix::class)->invoke('defaults'));
		$t->same('dom_allow_list', (new PanelEditorHtmlSanitizer())->name());
		$t->same(0, (new PanelMigrationRegistry())->revision());
		$t->same(PanelOperationStatus::QUEUED, PanelOperationStatus::all()[0]);
		$t->notEmpty($t->nonPublic(PanelPlatformManifest::class)->invoke('catalogue'));
		$preferences=new PanelWorkspacePreferencesFactory(new PanelInMemoryPreferenceStore());
		$t->same('panel_workspace_preferences_factory', $preferences->jsonSerialize()['type']);
		$t->notEmpty($t->nonPublic(PanelRealtimeSseResponse::class)->invoke('baseHeaders'));
		$t->notEmpty($t->nonPublic(PanelComponentRegistry::class)->invoke('defaultSchemaKinds'));
		$t->notEmpty($t->nonPublic(PanelInstanceExtensionRegistry::class)->invoke('componentProperties'));
		$t->contains('.dp-theme-preview', $t->nonPublic(PanelThemePreview::class)->invoke('css'));
		$t->same(10, count(PanelOperationStatus::all()));
	})->tag('panel', 'coverage', 'release', 'residual', 'accessors')->maxMillis(3000);

	test('release residual query forms fields and parser branches reject malformed input deterministically', static function(Context $t): void {
		$query=PanelDataQuery::fromArray(['filters'=>[
			['field'=>'status', 'operator'=>'eq', 'value'=>'open', 'boolean'=>'or'],
		]]);
		$t->same('status', $query->filterList()[0]['field']);
		$t->throws(static fn()=>PanelDataQuery::fromArray(['filters'=>['invalid']]), InvalidArgumentException::class);

		$bridge=PanelDataSourceResourceBridge::using(new PanelArrayDataSource([]));
		$resource=Resource::make('residual-query');
		$resourceQuery=new PanelDataSourceResourceQuery($bridge, null, $resource);
		$scoped=$resourceQuery->where('amount', 'gte', 10);
		$t->same('gte', $t->nonPublic($scoped)->readProperty('scopeFilters')[0]['operator']);

		$field=Field::make('required')->rules(['required', 'max:10'])->required(false);
		$t->same(['max:10'], $field->toArray()['rules']);
		$t->contains('CA', $t->nonPublic(Field::class)->invoke('knownCountryCodes'));
		$t->contains('CAD', $t->nonPublic(Field::class)->invoke('knownCurrencyCodes'));
		$t->contains('en', $t->nonPublic(Field::class)->invoke('knownLanguageCodes'));
		$t->same([], $t->nonPublic(PanelRenderer::class)->invoke('selectedValues', new stdClass()));

		$tokens=token_get_all('<?php get => value([1, 2]); set => value({3});');
		array_shift($tokens);
		$extractor=$t->nonPublic(\Dataphyre\Panel\PanelApiReferenceExtractor::class)->withoutConstructor();
		$hooks=$t->nonPublic($extractor)->invoke('propertyHooks', $tokens);
		$t->same(['get', 'set'], array_column($hooks, 'name'));
	})->tag('panel', 'coverage', 'release', 'residual', 'query', 'forms', 'parser')->maxMillis(2500);

	test('navigation configuration and Studio facades close every fail-closed branch', static function(Context $t): void {
		$valid=PanelContext::run(['navigation_intents'=>[
			'keys'=>['primary'=>str_repeat('K', 32)],
			'current_key_id'=>'primary',
			'unsigned_migration'=>'not-a-policy',
		]], static fn(): PanelNavigationIntentManager=>PanelConfig::navigationIntentManager());
		$t->isTrue($valid->canIssue());
		$t->same(PanelNavigationIntentManager::MIGRATION_DISABLED, $valid->migrationPolicy());

		$invalidKeys=PanelContext::run(['navigation_intents'=>[
			'keys'=>['primary'=>'short'], 'current_key_id'=>'primary', 'enabled'=>true,
		]], static fn(): PanelNavigationIntentManager=>PanelConfig::navigationIntentManager());
		$t->isFalse($invalidKeys->canIssue());

		$throwingProvider=new class implements PanelNavigationKeyProvider {
			public function current(int $timestamp): ?PanelNavigationSigningKey { return null; }
			public function find(string $keyId): ?PanelNavigationSigningKey { return null; }
			public function manifest(): array { throw new RuntimeException('provider manifest unavailable'); }
		};
		$invalidProvider=PanelContext::run(['navigation_intents'=>[
			'key_provider'=>$throwingProvider, 'enabled'=>true,
		]], static fn(): PanelNavigationIntentManager=>PanelConfig::navigationIntentManager());
		$t->isFalse($invalidProvider->canIssue());

		$t->throws(static fn()=>new PanelInstance('invalid-widget-bindings', null, [
			'widget_runtime_binding_keys'=>new stdClass(),
		]), InvalidArgumentException::class);
		$navigation=PanelInstance::make('navigation-residual')
			->navigationIntents(['one'=>str_repeat('1', 32)], 'one')
			->navigationIntents(['key'=>str_repeat('2', 32), 'key_id'=>'two']);
		$t->isTrue(is_array($t->nonPublic($navigation)->readProperty('config')['navigation_intents'] ?? null));
		$t->throws(static fn()=>$navigation->navigationIntentMigration('invalid'), InvalidArgumentException::class);

		Panel::flush();
		$allowedHome=PanelPage::make('coverage_home_allowed')->content('<p>Coverage home</p>');
		Panel::default()->registerPage($allowedHome);
		Panel::homePage($allowedHome);
		$t->same('custom_page', Panel::default()->dispatch(PanelRequest::fromArray(['method'=>'GET']))->data()['kind'] ?? null);
		$t->same('custom_page', Panel::default()->render(null, 'index', ['method'=>'GET'])->data()['kind'] ?? null);
		$deniedHome=PanelPage::make('coverage_home_denied')->authorize(static fn(): bool=>false);
		Panel::default()->registerPage($deniedHome);
		Panel::homePage('coverage_home_denied');
		$t->same(403, Panel::default()->dispatch(PanelRequest::fromArray(['method'=>'GET']))->status());
		$t->same(403, Panel::default()->render(null, 'index', ['method'=>'GET'])->status());
		Panel::flush();

		$studioStore=new PanelInMemoryStudioStore();
		$studioManager=new PanelStudioManager(
			$studioStore,
			PanelStudioPolicy::permit(static fn(): bool=>true),
			clock: static fn(): string=>'2026-07-14T12:00:00+00:00',
		);
		$platform=PanelPlatform::make()->register('studio.manager', $studioManager);
		$document=PanelStudioDocument::make('coverage', 'facade', 'Facade');
		$definition=PanelStudioDefinition::from([
			'kind'=>'page', 'key'=>'facade', 'properties'=>['label'=>'Facade'], 'children'=>[],
		]);
		Panel::flush();
		Panel::default()->usePlatform($platform);
		$session=Panel::openStudioEditor($document, 'editor', $definition);
		$resumed=Panel::resumeStudioEditor($document, 'editor', $session->checkpoint());
		$options=PanelStudioEditorOptions::make([
			'action_url'=>'/studio', 'preview_url'=>'/studio/preview',
			'csrf_token'=>str_repeat('C', 32), 'inline_assets'=>false,
		]);
		$t->contains('data-dp-studio-editor', Panel::renderStudioEditor($resumed, $options));
		$t->same('panel_studio_editor', Panel::studioEditorManifest($resumed)['type']);
		Panel::flush();
	})->tag('panel', 'coverage', 'release', 'residual', 'navigation', 'studio')->maxMillis(5000);

	test('plugin lifecycle residuals preserve ownership dependencies rollback and permission narrowing', static function(Context $t): void {
		$child=dp_panel_release_residual_plugin('residual-child');
		$parent=new class($child) implements PanelPlugin {
			public function __construct(private PanelPlugin $child){}
			public function id(): string { return 'residual-parent'; }
			public function register(PanelInstance $panel): void { $panel->plugin($this->child); }
			public function boot(PanelInstance $panel): void {}
		};
		$owned=PanelInstance::make('owned-residual')->plugin($parent);
		$t->throws(static fn()=>$owned->unloadPlugin('residual-parent'), LogicException::class);
		$t->throws(static fn()=>$owned->reloadPlugin('residual-child'), LogicException::class);

		$dependent=PanelInstance::make('dependency-residual')
			->plugin(dp_panel_release_residual_plugin('dependency-base'))
			->plugin(dp_panel_release_residual_plugin('dependency-leaf', ['dependency-base']));
		$t->throws(static fn()=>$dependent->unloadPlugin('dependency-base'), LogicException::class);
		$dependent->unloadPlugin('dependency-base', true);
		$t->same([], $dependent->pluginIds());

		$reload=PanelInstance::make('reload-residual')
			->plugin(dp_panel_release_residual_plugin('reload-one'))
			->plugin(dp_panel_release_residual_plugin('reload-two'));
		$reload->reloadPlugin('reload-one');
		$t->same(['reload-one', 'reload-two'], $reload->pluginIds());
		$t->throws(static fn()=>$reload->reloadPlugin('missing'), InvalidArgumentException::class);

		$fresh=PanelInstance::make('missing-baseline');
		$t->throws(static fn()=>$t->nonPublic($fresh)->invoke('rebuildPlugins', [], false), LogicException::class);

		$privateUnregister=new class implements PanelPlugin {
			public function id(): string { return 'private-unregister'; }
			public function register(PanelInstance $panel): void {}
			public function boot(PanelInstance $panel): void {}
			private function unregister(PanelInstance $panel): void {}
		};
		$privatePanel=PanelInstance::make('private-unregister')->plugin($privateUnregister);
		$t->nonPublic($privatePanel)->invoke('invokePluginUnregister', 'private-unregister');
		$t->same(['private-unregister'], $privatePanel->pluginIds());

		$throwingUnregister=new class implements PanelPlugin {
			public function id(): string { return 'throwing-unregister'; }
			public function register(PanelInstance $panel): void {}
			public function boot(PanelInstance $panel): void {}
			public function unregister(PanelInstance $panel): void { throw new RuntimeException('unregister failed'); }
		};
		$throwingPanel=PanelInstance::make('throwing-unregister')->plugin($throwingUnregister);
		$t->throws(static fn()=>$throwingPanel->unloadPlugin('throwing-unregister'), RuntimeException::class);
		$t->same(['throwing-unregister'], $throwingPanel->pluginIds());

		$permissions=$t->nonPublic($fresh)->invoke(
			'intersectExtensionPermissions',
			['component.widget_types.register'],
			['component.*'],
		);
		$t->same(['component.widget_types.register'], $permissions);
	})->tag('panel', 'coverage', 'release', 'residual', 'plugins')->maxMillis(5000);

	test('extensible dispatch covers scoped bound callable static and flush paths', static function(Context $t): void {
		$panel=PanelInstance::make('extensible-residual');
		$field=null;
		$panel->configureExtensions(static function() use ($t, &$field): void {
			Field::configureUsing(static fn(Field $candidate): Field=>$candidate->meta(['configured'=>true]));
			Field::flushConfigurators();
			Field::macro('bound residual', function(string $suffix): string { return $this->name().$suffix; });
			Field::macro('callable residual', [DpPanelReleaseResidualCallable::class, 'invoke']);
			Field::macro('static residual', static fn(string $value): string=>'scoped:'.$value);
			$t->isTrue(Field::hasMacro('bound residual'));
			$t->same('scoped:value', Field::static_residual('value'));
			$field=Field::make('owner');
		});
		$t->instanceOf(Field::class, $field);
		$t->isTrue(Field::hasMacro('bound residual'));
		$t->same('owner!', $field->bound_residual('!'));
		$t->same('owner?', $field->callable_residual('?'));

		Field::macro('global static residual', static fn(string $value): string=>'global:'.$value);
		$t->same('global:value', Field::make('plain')->global_static_residual('value'));
		Field::flushMacros();
		$panel->configureExtensions(static function(): void { Field::flushMacros(); });
	})->tag('panel', 'coverage', 'release', 'residual', 'extensions')->maxMillis(2500);

	test('renderer residuals cover custom hooks fallback assets widgets and null return navigation', static function(Context $t): void {
		$asset=PanelRenderer::assetContent('panel.js', ['studio-editor']);
		$t->notNull($asset);
		$t->contains('dpPanelListen(document', (string)$asset['content']);

		PanelComponentRegistry::registerPageType('residual_forbidden', null, [
			'authorize'=>static fn(): bool=>false,
		]);
		$forbidden=PanelRenderer::customPage(
			PanelPage::make('residual-forbidden')->meta(['page_type'=>'residual_forbidden']),
			PanelRequest::fromArray([]),
		);
		$t->same(403, $forbidden->status());

		PanelComponentRegistry::registerPageType('residual_before', null, [
			'before_render'=>static fn(): array=>['title'=>'Before', 'content'=>'Before content'],
		]);
		$before=PanelRenderer::customPage(
			PanelPage::make('residual-before')->meta(['page_type'=>'residual_before']),
			PanelRequest::fromArray([]),
		);
		$t->contains('Before content', $before->content());

		PanelComponentRegistry::registerWidgetType(
			'residual_widget',
			static fn(array $widget): string=>'<strong>'.htmlspecialchars((string)($widget['label'] ?? ''), ENT_QUOTES).'</strong>',
			[
				'data'=>static fn(): array=>['label'=>'Resolved'],
				'before_render'=>static fn(): string=>'<i>Before</i>',
				'after_render'=>static fn(string $html): string=>'<section>'.$html.'</section>',
			],
		);
		$widgets=$t->nonPublic(PanelRenderer::class)->invoke('widgetsHtml', [[
			'name'=>'residual-widget', 'type'=>'residual_widget', 'label'=>'Original',
		]]);
		$t->contains('<section>', $widgets);
		$t->contains('Resolved', $widgets);
		PanelComponentRegistry::registerWidgetType(
			'residual_widget_no_after',
			static fn(): string=>'<strong>No after hook</strong>',
		);
		$t->contains('No after hook', $t->nonPublic(PanelRenderer::class)->invoke('widgetsHtml', [[
			'name'=>'residual-widget-no-after', 'type'=>'residual_widget_no_after',
		]]));
		PanelComponentRegistry::registerWidgetType('residual_widget_forbidden', null, [
			'authorize'=>static fn(): bool=>false,
		]);
		$forbiddenWidgets=$t->nonPublic(PanelRenderer::class)->invoke('widgetsHtml', [[
			'name'=>'forbidden-widget', 'type'=>'residual_widget_forbidden', 'label'=>'Must not render',
		]]);
		$t->contains('dp-panel-widgets', $forbiddenWidgets);
		$t->notContains('Must not render', $forbiddenWidgets, 'A widget-type authorization hook removes the widget before rendering.');
		$t->same('<span>fallback</span>', $t->nonPublic(PanelRenderer::class)->invoke(
			'widgetIslandHtml', '<span>fallback</span>', ['interaction'=>['adapter'=>'']], null, 'coverage', 0,
		));

		$oneShotIntegrity=new class implements Stringable {
			private int $calls=0;
			public function __toString(): string {
				if(++$this->calls===1){ throw new RuntimeException('asset option probe'); }
				return '0';
			}
		};
		$shell=PanelContext::run([
			'__panel_manager'=>new \Dataphyre\Panel\PanelManager(),
			'navigation_layout'=>'none',
		], static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
			'page', 'Residual shell', '<main data-dp-reactor="1">Body</main>', [
				'kind'=>'page', 'asset_mode'=>'capability', 'asset_nonce'=>'residual-nonce',
				'asset_integrity'=>$oneShotIntegrity,
			], 200, [],
		));
		$t->same('full', $shell->data()['asset_manifest']['mode']);
		$t->contains('reactor', $shell->data()['asset_manifest']['missing_capabilities']);

		$button=$t->nonPublic(PanelRenderer::class)->invoke(
			'actionButton', Resource::make('residual-actions'), Action::make('run'),
		);
		$t->notContains('name="return_to"', $button);
		$t->throws(static fn()=>Action::make('invalid-audience')->navigationAudience('bad audience!'), InvalidArgumentException::class);
		$t->same(['run'=>'Run'], Widget::make('interactive')->interactive('reactor')->interactionActions(['run'=>'Run'])->toArray()['interaction']['actions']);
		PanelComponentRegistry::flush();
	})->tag('panel', 'coverage', 'release', 'residual', 'renderer')->maxMillis(8000);

	test('filesystem semantics remain path-driven across Linux and Windows-shaped inputs', static function(Context $t): void {
		$t->isFalse(PanelFilesystemPath::prefixMatches('', '/root'));
		$t->same('c:/root/file', $t->nonPublic(PanelTenantStorageScope::class)->invoke('normalizedPath', 'C:\\ROOT\\FILE'));

		$writer=$t->nonPublic(PanelScaffoldWriter::class)->withoutConstructor();
		$writerPrivate=$t->nonPublic($writer)->writeProperty('root','C:/Workspace');
		$t->same('C:/Workspace/File.php', $writerPrivate->invoke('target', 'C:/Workspace/File.php'));
		$t->isTrue($writerPrivate->invoke('withinRoot', 'c:/workspace/Nested/File.php'));
		$t->throws(static fn()=>$writerPrivate->invoke('target', 'D:/Outside/File.php'), InvalidArgumentException::class);
		$t->throws(static fn()=>$t->nonPublic(PanelScaffoldWriter::class)->invoke('assertSafeSegment', 'bad:name', true), InvalidArgumentException::class);
		$t->throws(static fn()=>$t->nonPublic(PanelScaffoldWriter::class)->invoke('assertSafeSegment', 'CON.txt', true), InvalidArgumentException::class);

		$gate=$t->nonPublic(PanelCoverageGate::class)->withoutConstructor();
		$gatePrivate=$t->nonPublic($gate);
		$t->isTrue($gatePrivate->invoke('pathStartsWith', 'C:/ROOT/File.php', 'c:/root/'));
		$t->same('c:/root/file.php', $gatePrivate->invoke('pathKey', 'C:/ROOT/File.php'));
	})->tag('panel', 'coverage', 'release', 'residual', 'filesystem')->maxMillis(2500);

	test('error fallback and workflow compensation conflicts remain observable results', static function(Context $t): void {
		Probe::reset();
		Probe::hook('random_bytes', static fn(int $length): string=>throw new RuntimeException('entropy unavailable'));
		$correlation=PanelErrorEnvelope::correlationId();
		$t->same(32, strlen($correlation));
		Probe::reset();

		$definition=WorkflowDefinition::make('residual-workflow')
			->state('draft')
			->state('done')
			->transition(WorkflowTransition::make('finish', 'draft', 'done')->reversible(true, static fn(): bool=>false));
		$engine=new WorkflowEngine(new InMemoryWorkflowStore(), [$definition]);
		$t->same(1, $engine->revision());
		$actor=WorkflowActor::from('operator');
		$t->isTrue($engine->start('residual-workflow', 'refused', [], $actor)->ok());
		$t->isTrue($engine->transition('residual-workflow', 'refused', 'finish', [], $actor)->ok());
		$t->same('compensation_refused', $engine->rollback('residual-workflow', 'refused', $actor)->code());

		$conflictStore=new class implements WorkflowStore {
			private InMemoryWorkflowStore $inner;
			public function __construct(){ $this->inner=new InMemoryWorkflowStore(); }
			public function create(WorkflowRecord $record): bool { return $this->inner->create($record); }
			public function load(string $definition, string $id): ?WorkflowRecord { return $this->inner->load($definition, $id); }
			public function compareAndSwap(WorkflowRecord $record, int $expectedVersion): bool { return false; }
			public function all(?string $definition=null): array { return $this->inner->all($definition); }
		};
		$conflictEngine=new WorkflowEngine($conflictStore, [$definition]);
		$t->isTrue($conflictEngine->start('residual-workflow', 'conflict', [], $actor)->ok());
		$t->same('version_conflict', $conflictEngine->transition('residual-workflow', 'conflict', 'finish', [], $actor)->code());
	})->tag('panel', 'coverage', 'release', 'residual', 'errors', 'workflow')->maxMillis(3000);
}
