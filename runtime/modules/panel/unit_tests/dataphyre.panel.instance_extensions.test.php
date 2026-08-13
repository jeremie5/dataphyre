<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelComponentRegistry;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelInstanceExtensionRegistry;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemePreset;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

final class DpPanelInstanceExtensionPlugin implements PanelPlugin {
	public function __construct(
		private string $marker,
		private string $pluginId='instance-extension',
		private bool $failRegistration=false,
		private ?\ArrayObject $events=null,
	){}

	public function id(): string { return $this->pluginId; }

	/** @return list<string> */
	public function extensionPermissions(): array {
		return [
			'component.widget_types.register',
			'component.page_types.register',
			'component.relation_types.register',
			'extensible.macro.register',
			'extensible.configurator.register',
			'theme.theme.register',
		];
	}

	public function register(PanelInstance $panel): void {
		$marker=$this->marker;
		$this->events?->append('register:'.$marker);
		PanelComponentRegistry::registerWidgetType(
			'instance_extension_widget',
			static fn(array $widget): string=>'<article data-extension-widget="'.$marker.'">'.htmlspecialchars((string)($widget['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</article>',
			[
				'before_render'=>static fn(): string=>'<i data-extension-widget-before="'.$marker.'"></i>',
				'after_render'=>static fn(string $html): string=>'<div data-extension-widget-after="'.$marker.'">'.$html.'</div>',
			],
		);
		PanelComponentRegistry::registerPageType(
			'instance_extension_page',
			static fn(): array=>['title'=>'Extension '.$marker, 'content'=>'<main data-extension-page="'.$marker.'">page</main>'],
			['after_render'=>static function(mixed $result) use ($marker): mixed {
				if(is_array($result)){ $result['content']='<div data-extension-page-after="'.$marker.'">'.($result['content'] ?? '').'</div>'; }
				return $result;
			}],
		);
		PanelComponentRegistry::registerRelationType(
			'instance_extension_relation',
			static fn(): string=>'<section data-extension-relation="'.$marker.'">relation</section>',
			[
				'before_render'=>static fn(): string=>'<i data-extension-relation-before="'.$marker.'"></i>',
				'after_render'=>static fn(string $html): string=>'<div data-extension-relation-after="'.$marker.'">'.$html.'</div>',
			],
		);
		Field::macro('extensionOwner', static fn(): string=>$marker);
		Field::configureUsing(static fn(Field $field): Field=>$field->meta(['extension_owner'=>$marker]));
		PanelTheme::registerTheme(PanelTheme::make('instance-extension-theme')->color('primary', '#2255aa'));

		$panel->registerWidget(Widget::make('instance-extension-widget')->type('instance_extension_widget')->label('Extension widget'));
		$panel->registerPage(PanelPage::make('instance-extension-page')->meta(['page_type'=>'instance_extension_page']));
		$panel->register(
			Resource::make('instance-extension-orders')
				->recordKeyUsing('id')
				->relation(RelationManager::make('events')->meta(['relation_type'=>'instance_extension_relation']))
		);
		if($this->failRegistration){ throw new \RuntimeException('extension registration failed'); }
	}

	public function boot(PanelInstance $panel): void { $this->events?->append('boot:'.$this->marker); }
	public function unregister(PanelInstance $panel): void { $this->events?->append('unregister:'.$this->marker); }
}

final class DpPanelRestrictedExtensionPlugin implements PanelPlugin {
	public function __construct(private string $pluginId='restricted-extension'){}
	public function id(): string { return $this->pluginId; }
	/** @return list<string> */ public function extensionPermissions(): array { return ['component.widget_types.register']; }
	public function register(PanelInstance $panel): void {
		PanelComponentRegistry::registerWidgetType('restricted_extension_widget', static fn(): string=>'restricted');
		PanelTheme::registerTheme(PanelTheme::make('restricted-extension-theme'));
	}
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelConflictExtensionPlugin implements PanelPlugin {
	public function __construct(private string $pluginId, private string $marker){}
	public function id(): string { return $this->pluginId; }
	/** @return list<string> */ public function extensionPermissions(): array { return ['component.widget_types.register']; }
	public function register(PanelInstance $panel): void {
		$marker=$this->marker;
		PanelComponentRegistry::registerWidgetType('extension_conflict', static fn(): string=>$marker, ['marker'=>$marker]);
	}
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelNestedOwnerExtensionPlugin implements PanelPlugin {
	public function __construct(private PanelPlugin $child){}
	public function id(): string { return 'extension-parent'; }
	public function register(PanelInstance $panel): void { $panel->plugin($this->child); }
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelBootFailureExtensionPlugin implements PanelPlugin {
	public int $attempts=0;
	public function id(): string { return 'boot-failure-extension'; }
	/** @return list<string> */ public function extensionPermissions(): array { return ['component.widget_types.register','theme.theme.register']; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void {
		$this->attempts++;
		PanelComponentRegistry::registerWidgetType('boot_failure_widget', static fn(): string=>'ready');
		PanelTheme::registerTheme(PanelTheme::make('boot-failure-theme'));
		$panel->registerWidget(Widget::make('boot-failure-widget')->type('boot_failure_widget'));
		if($this->attempts===1){ throw new \RuntimeException('boot contribution failed'); }
	}
}

test('Panel extension registries isolate components macros configurators and themes per instance', static function(Context $t): void {
	$left=PanelInstance::make('extension-left');
	$right=PanelInstance::make('extension-right');
	$left->configureExtensions(static function(): void {
		PanelComponentRegistry::registerWidgetType('surface_only', static fn(): string=>'left', ['marker'=>'left']);
		Field::macro('surfaceOwner', static fn(): string=>'left');
		Field::configureUsing(static fn(Field $field): Field=>$field->meta(['surface'=>'left']));
		PanelTheme::registerTheme(PanelTheme::make('surface-theme')->color('primary', '#112233'));
	});

	$t->same('left', $left->extensions()->widgetTypes['surface_only']['marker'] ?? null);
	$t->isFalse(isset($right->extensions()->widgetTypes['surface_only']));
	$t->isFalse(PanelComponentRegistry::widgetTypeRegistered('surface_only'));
	$t->isTrue($left->namedTheme('surface-theme') instanceof PanelTheme);
	$t->same(null, $right->namedTheme('surface-theme'));
	$leftField=$left->field('left-field');
	$t->same('left', $leftField->surfaceOwner());
	$t->same('left', $leftField->toArray()['meta']['surface'] ?? null);
	$t->throws(static fn()=>$right->field('right-field')->surfaceOwner(), \BadMethodCallException::class);
})->tag('panel', 'extensions', 'instance', 'isolation')->maxMillis(1500);

test('plugin extension renderers are live for widgets pages and relations with provenance', static function(Context $t): void {
	$panel=PanelInstance::make('extension-renderers')->plugin(new DpPanelInstanceExtensionPlugin('alpha'));
	$dashboard=$panel->render();
	$page=$panel->render('instance-extension-page');
	$record=$panel->render('instance-extension-orders', 'show', ['record'=>['id'=>1]]);

	$t->contains('data-extension-widget="alpha"', $dashboard->content());
	$t->contains('data-extension-widget-before="alpha"', $dashboard->content());
	$t->contains('data-extension-widget-after="alpha"', $dashboard->content());
	$t->contains('data-extension-page="alpha"', $page->content());
	$t->contains('data-extension-page-after="alpha"', $page->content());
	$t->contains('data-extension-relation="alpha"', $record->content());
	$t->contains('data-extension-relation-before="alpha"', $record->content());
	$t->contains('data-extension-relation-after="alpha"', $record->content());
	$t->same('alpha', $panel->field('owner')->extensionOwner());
	$t->same('alpha', $panel->field('configured')->toArray()['meta']['extension_owner'] ?? null);
	$t->isTrue($panel->namedTheme('instance-extension-theme') instanceof PanelTheme);

	$provenance=$panel->extensionProvenance('instance-extension');
	$t->isTrue(count($provenance)>=6);
	$t->same(['instance-extension'], array_values(array_unique(array_column($provenance, 'owner'))));
	$t->isTrue(in_array('register', array_column(array_column($provenance, 'meta'), 'phase'), true));
	$manifest=$panel->pluginManifest('instance-extension');
	$t->contains('component.widget_types.register', $manifest['extensions']['permissions']);
	$t->same(count($provenance), count($manifest['extensions']['provenance']));
	$t->same($panel->extensions()->revision(), $manifest['extensions']['revision']);
	$t->same($panel->extensionDiagnostics(), $panel->describe()['extensions'] ?? null);
})->tag('panel', 'extensions', 'renderers', 'provenance', 'manifest')->maxMillis(2500);

test('extension permissions and host allowlists fail closed and roll registration back', static function(Context $t): void {
	$strict=PanelInstance::make('extension-strict', ['strict_extension_permissions'=>true]);
	$t->throws(static fn()=>$strict->plugin(new DpPanelRestrictedExtensionPlugin()), \LogicException::class);
	$t->same([], $strict->pluginIds());
	$t->isFalse(isset($strict->extensions()->widgetTypes['restricted_extension_widget']));
	$t->same(null, $strict->namedTheme('restricted-extension-theme'));

	$denyMap=PanelInstance::make('extension-deny-map', [
		'strict_extension_permissions'=>true,
		'plugin_extension_permissions'=>['restricted-extension'=>[]],
	]);
	$t->throws(static fn()=>$denyMap->plugin(new DpPanelRestrictedExtensionPlugin()), \LogicException::class);
	$t->same([], $denyMap->pluginIds());

	$noEscalation=PanelInstance::make('extension-no-escalation', [
		'strict_extension_permissions'=>true,
		'plugin_extension_permissions'=>static fn(): array=>['*'],
	]);
	$t->throws(static fn()=>$noEscalation->plugin(new class implements PanelPlugin {
		public function id(): string { return 'no-requested-permissions'; }
		/** @return list<string> */ public function extensionPermissions(): array { return []; }
		public function register(PanelInstance $panel): void { PanelTheme::registerTheme(PanelTheme::make('escalated-theme')); }
		public function boot(PanelInstance $panel): void {}
	}), \LogicException::class);
	$t->same(null, $noEscalation->namedTheme('escalated-theme'));

	$narrowed=PanelInstance::make('extension-narrowed', [
		'plugin_extension_permissions'=>['component.widget_types.register'],
	]);
	$narrowed->plugin(new class implements PanelPlugin {
		public function id(): string { return 'compatibility-wildcard-narrowed'; }
		public function register(PanelInstance $panel): void { PanelComponentRegistry::registerWidgetType('narrowed-widget', static fn(): string=>'ok'); }
		public function boot(PanelInstance $panel): void {}
	});
	$t->isTrue(isset($narrowed->extensions()->widgetTypes['narrowed-widget']));
	$t->same(['component.widget_types.register'], $narrowed->pluginManifest('compatibility-wildcard-narrowed')['extensions']['permissions'] ?? null);
})->tag('panel', 'extensions', 'permissions', 'rollback')->maxMillis(1500);

test('extension conflicts use deterministic reject replace and keep-first policies', static function(Context $t): void {
	$reject=PanelInstance::make('extension-reject');
	$reject->plugin(new DpPanelConflictExtensionPlugin('conflict-one', 'one'));
	$t->throws(static fn()=>$reject->plugin(new DpPanelConflictExtensionPlugin('conflict-two', 'two')), \LogicException::class);
	$t->same(['conflict-one'], $reject->pluginIds());
	$t->same('one', $reject->extensions()->widgetTypes['extension_conflict']['marker'] ?? null);

	$replace=PanelInstance::make('extension-replace', ['extension_conflict_policy'=>'replace'])
		->plugin(new DpPanelConflictExtensionPlugin('replace-one', 'one'))
		->plugin(new DpPanelConflictExtensionPlugin('replace-two', 'two'));
	$t->same('two', $replace->extensions()->widgetTypes['extension_conflict']['marker'] ?? null);
	$replace->unloadPlugin('replace-two');
	$t->same('one', $replace->extensions()->widgetTypes['extension_conflict']['marker'] ?? null);

	$keep=PanelInstance::make('extension-keep', ['extension_conflict_policy'=>'keep_first'])
		->plugin(new DpPanelConflictExtensionPlugin('keep-one', 'one'))
		->plugin(new DpPanelConflictExtensionPlugin('keep-two', 'two'));
	$t->same('one', $keep->extensions()->widgetTypes['extension_conflict']['marker'] ?? null);
})->tag('panel', 'extensions', 'conflicts', 'deterministic')->maxMillis(1500);

test('plugin unload and reload remove every owned contribution and rollback failures', static function(Context $t): void {
	$events=new \ArrayObject();
	$panel=PanelInstance::make('extension-hot-reload')
		->plugin(new DpPanelInstanceExtensionPlugin('one', 'instance-extension', false, $events));
	$panel->bootPlugins();
	$t->same(['register:one','boot:one'], iterator_to_array($events));

	$panel->reloadPlugin('instance-extension', new DpPanelInstanceExtensionPlugin('two', 'instance-extension', false, $events));
	$t->same('two', $panel->field('owner')->extensionOwner());
	$t->contains('data-extension-widget="two"', $panel->render()->content());
	$t->same(['register:one','boot:one','unregister:one','register:two','boot:two'], iterator_to_array($events));

	$t->throws(
		static fn()=>$panel->reloadPlugin('instance-extension', new DpPanelInstanceExtensionPlugin('broken', 'instance-extension', true, $events)),
		\RuntimeException::class,
	);
	$t->same('two', $panel->field('owner-after-failure')->extensionOwner());
	$t->contains('data-extension-widget="two"', $panel->render()->content());
	$t->same(['instance-extension'], $panel->pluginIds());
	$t->isTrue($panel->pluginsBooted());
	$t->throws(static fn()=>$panel->reloadPlugin('instance-extension', new DpPanelInstanceExtensionPlugin('other', 'other-id')), \LogicException::class);

	$panel->unloadPlugin('instance-extension');
	$t->same([], $panel->pluginIds());
	$t->isFalse(isset($panel->manager()->resources()['instance-extension-orders']));
	$t->isFalse(isset($panel->manager()->pages()['instance-extension-page']));
	$t->isFalse(in_array('instance-extension-widget', array_column($panel->manager()->widgets(), 'name'), true));
	$t->isFalse(isset($panel->extensions()->widgetTypes['instance_extension_widget']));
	$t->same(null, $panel->namedTheme('instance-extension-theme'));
	$t->same([], $panel->extensionProvenance('instance-extension'));
	$t->throws(static fn()=>$panel->field('after-unload')->extensionOwner(), \BadMethodCallException::class);
})->tag('panel', 'extensions', 'plugins', 'unload', 'reload', 'transaction')->maxMillis(3000);

test('nested plugin unload cannot silently reappear during transactional rebuild', static function(Context $t): void {
	$child=new DpPanelConflictExtensionPlugin('extension-child', 'child');
	$panel=PanelInstance::make('extension-nested')
		->plugin(new DpPanelNestedOwnerExtensionPlugin($child));
	$t->same(['extension-parent','extension-child'], $panel->pluginIds());
	$t->throws(static fn()=>$panel->unloadPlugin('extension-child'), \LogicException::class);
	$t->same(['extension-parent','extension-child'], $panel->pluginIds());
	$t->same('child', $panel->extensions()->widgetTypes['extension_conflict']['marker'] ?? null);
	$panel->unloadPlugin('extension-parent', true);
	$t->same([], $panel->pluginIds());
})->tag('panel', 'extensions', 'plugins', 'nested', 'transaction')->maxMillis(2000);

test('failed plugin boot rolls back manager and extension contributions before retry', static function(Context $t): void {
	$plugin=new DpPanelBootFailureExtensionPlugin();
	$panel=PanelInstance::make('extension-boot-transaction')->plugin($plugin);
	$t->throws(static fn()=>$panel->bootPlugins(), \RuntimeException::class);
	$t->same(1, $plugin->attempts);
	$t->isFalse($panel->pluginsBooted());
	$t->isFalse(isset($panel->extensions()->widgetTypes['boot_failure_widget']));
	$t->isFalse(in_array('boot-failure-widget', array_column($panel->manager()->widgets(), 'name'), true));
	$t->same(null, $panel->namedTheme('boot-failure-theme'));
	$t->same([], $panel->extensionProvenance('boot-failure-extension'));
	$panel->bootPlugins();
	$t->same(2, $plugin->attempts);
	$t->isTrue($panel->pluginsBooted());
	$t->isTrue(isset($panel->extensions()->widgetTypes['boot_failure_widget']));
	$t->contains('boot-failure-widget', array_column($panel->manager()->widgets(), 'name'));
	$t->isTrue($panel->namedTheme('boot-failure-theme') instanceof PanelTheme);
})->tag('panel', 'extensions', 'plugins', 'boot', 'transaction', 'rollback')->maxMillis(1500);

test('instance registry low-level permissions policies and migration adapter are deterministic', static function(Context $t): void {
	PanelInstanceExtensionRegistry::flushLegacy();
	$legacy=PanelInstanceExtensionRegistry::legacy();
	$t->isTrue($legacy->isLegacyAdapter());
	$t->same($legacy, PanelInstanceExtensionRegistry::legacy());
	$t->same('replace', $legacy->conflictPolicy());
	PanelInstanceExtensionRegistry::flushLegacy();
	$t->isFalse($legacy===PanelInstanceExtensionRegistry::legacy());

	$registry=new PanelInstanceExtensionRegistry('invalid-policy');
	$t->isFalse($registry->isLegacyAdapter());
	$t->same('reject', $registry->conflictPolicy());
	$t->same($registry, $registry->conflictPolicyUsing('keep first'));
	$t->same('keep_first', $registry->conflictPolicy());
	$registry->conflictPolicyUsing('nonsense');
	$t->same('reject', $registry->conflictPolicy());
	$t->same('application', $registry->contributorId());
	$t->same(['*'], $registry->contributorPermissions());
	$t->same([], $registry->contributorMeta());
	$registry->assertPermission('');
	$t->throws(static fn()=>$registry->runAs(' ', [], [], static fn()=>null), \InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->runAs('core', [], [], static fn()=>null), \InvalidArgumentException::class);

	$result=$registry->runAs('Plugin Name', [
		'component:widget_types:*',
		'theme.*'=>true,
		'ignored.*'=>false,
		new \stdClass(),
	], ['phase'=>'coverage'], static function() use ($registry): string {
		if($registry->contributorId()!=='plugin_name'){ throw new \RuntimeException('contributor normalization failed'); }
		if($registry->contributorMeta()!==['phase'=>'coverage']){ throw new \RuntimeException('contributor metadata failed'); }
		$registry->assertPermission('component.widget_types.register');
		$registry->assertPermission('theme.theme.register');
		return 'ok';
	});
	$t->same('ok', $result);
	$t->same('application', $registry->contributorId());
	$t->throws(static function() use ($registry): void {
		$registry->runAs('denied', ['component.page_types.register'], [], static function() use ($registry): void {
			$registry->assertPermission('theme.theme.register');
		});
	}, \LogicException::class);
	$t->same('application', $registry->contributorId());

	$registry->resetComponents(['widget_types'=>['core_widget'=>['marker'=>'core']]]);
	$t->isTrue(isset($registry->widgetTypes['core_widget']));
	$t->isFalse($registry->contributeComponent('unknown', 'name', []));
	$t->isFalse($registry->contributeComponent('widget_types', ' ', []));
	$t->isTrue($registry->contributeComponent('widget_types', 'application-widget', ['marker'=>'one']));
	$t->isTrue($registry->contributeComponent('widget_types', 'application-widget', ['marker'=>'two']));
	$t->same('two', $registry->widgetTypes['application-widget']['marker'] ?? null);
	$t->throws(static function() use ($registry): void {
		$registry->runAs('rejecting-plugin', ['component.widget_types.register'], [], static fn()=>$registry->contributeComponent('widget_types', 'application-widget', ['marker'=>'plugin']));
	}, \LogicException::class);

	$registry->conflictPolicyUsing('keep_first');
	$kept=$registry->runAs('kept-plugin', ['component.widget_types.register'], [], static fn()=>$registry->contributeComponent('widget_types', 'application-widget', ['marker'=>'plugin']));
	$t->isFalse($kept);
	$t->same('two', $registry->widgetTypes['application-widget']['marker'] ?? null);
	$registry->conflictPolicyUsing('replace');
	$t->isTrue($registry->runAs('replacement-plugin', ['component.widget_types.register'], ['phase'=>'replace'], static fn()=>$registry->contributeComponent('widget_types', 'application-widget', ['marker'=>'plugin'])));
	$t->same('plugin', $registry->widgetTypes['application-widget']['marker'] ?? null);
})->tag('panel', 'extensions', 'registry', 'permissions', 'migration')->maxMillis(1500);

test('instance registry owns macro configurator theme and checkpoint layers', static function(Context $t): void {
	$registry=new PanelInstanceExtensionRegistry('replace');
	$t->same(null, $registry->macro('CoverageMacroOwner', 'missing'));
	$registry->registerMacro('CoverageMacroOwner', ' ', static fn(): string=>'ignored');
	$t->isFalse($registry->hasMacro('CoverageMacroOwner', 'ignored'));
	$registry->registerMacro('CoverageMacroOwner', 'layered', static fn(): string=>'application-one');
	$registry->registerMacro('CoverageMacroOwner', 'layered', static fn(): string=>'application-two');
	$t->same('application-two', ($registry->macro('CoverageMacroOwner', 'layered'))());
	$registry->runAs('macro-plugin', ['extensible.macro.register'], ['phase'=>'register'], static fn()=>$registry->registerMacro('CoverageMacroOwner', 'layered', static fn(): string=>'plugin'));
	$t->same('plugin', ($registry->macro('CoverageMacroOwner', 'layered'))());
	$t->isTrue($registry->hasMacro('CoverageMacroOwner', 'layered'));
	$registry->conflictPolicyUsing('reject');
	$t->throws(static fn()=>$registry->runAs('macro-reject', ['extensible.macro.register'], [], static fn()=>$registry->registerMacro('CoverageMacroOwner', 'layered', static fn(): string=>'rejected')), \LogicException::class);
	$registry->conflictPolicyUsing('keep_first');
	$registry->runAs('macro-keep', ['extensible.macro.register'], [], static fn()=>$registry->registerMacro('CoverageMacroOwner', 'layered', static fn(): string=>'kept-out'));
	$t->same('plugin', ($registry->macro('CoverageMacroOwner', 'layered'))());
	$registry->conflictPolicyUsing('replace');

	$registry->registerConfigurator('CoverageConfiguratorOwner', static fn(object $value): object=>$value, true);
	$registry->registerConfigurator('CoverageConfiguratorOwner', static fn(object $value): object=>$value, false);
	$t->same(2, count($registry->configurators('CoverageConfiguratorOwner')));
	$t->same([], $registry->configurators('MissingConfiguratorOwner'));

	$preset=$registry->registerThemePreset(['name'=>'coverage-preset']);
	$t->isTrue($preset instanceof PanelThemePreset);
	$theme=$registry->registerTheme(['name'=>'coverage-theme','colors'=>['primary'=>'#123456']]);
	$t->isTrue($theme instanceof PanelTheme);
	$registry->registerTheme(['name'=>'coverage-theme','colors'=>['primary'=>'#654321']]);
	$registry->registerTheme(['name'=>'coverage-second-theme','colors'=>['primary'=>'#abcdef']]);
	$t->isTrue($registry->themeLibrary()->has('coverage-preset'));
	$t->isTrue($registry->themeLibrary()->hasTheme('coverage-theme'));
	$t->isTrue($registry->themeLibrary()->hasTheme('coverage-second-theme'));
	$registry->conflictPolicyUsing('reject');
	$t->throws(static fn()=>$registry->runAs('theme-reject', ['theme.theme.register'], [], static fn()=>$registry->registerTheme(['name'=>'coverage-theme','colors'=>['primary'=>'#000000']])), \LogicException::class);
	$registry->conflictPolicyUsing('keep_first');
	$registry->runAs('theme-keep', ['theme.theme.register'], [], static fn()=>$registry->registerTheme(['name'=>'coverage-theme','colors'=>['primary'=>'#000000']]));
	$t->isTrue($registry->themeLibrary()->hasTheme('coverage-theme'));
	$registry->conflictPolicyUsing('replace');
	$registry->loadThemes(__DIR__.'/fixtures/instance-extension-theme.json');
	$t->isTrue($registry->themeLibrary()->has('loaded-extension-preset'));
	$t->isTrue($registry->themeLibrary()->hasTheme('loaded-extension-theme'));

	$checkpoint=$registry->checkpoint();
	$revision=$registry->revision();
	$registry->flushMacros('CoverageMacroOwner');
	$registry->flushConfigurators('CoverageConfiguratorOwner');
	$t->same(null, $registry->macro('CoverageMacroOwner', 'layered'));
	$t->same([], $registry->configurators('CoverageConfiguratorOwner'));
	$t->same($registry, $registry->restore($checkpoint));
	$t->same($revision, $registry->revision());
	$t->same('plugin', ($registry->macro('CoverageMacroOwner', 'layered'))());
	$t->same(2, count($registry->configurators('CoverageConfiguratorOwner')));
	$t->throws(static fn()=>$registry->restore([]), \InvalidArgumentException::class);
	$invalid=$checkpoint;
	$invalid['maps']='invalid';
	$t->throws(static fn()=>$registry->restore($invalid), \InvalidArgumentException::class);

	$diagnostics=$registry->diagnostics();
	$t->same('panel_extension_registry', $diagnostics['type']);
	$t->isTrue(($diagnostics['macros'] ?? 0)>=1);
	$t->isTrue(($diagnostics['configurators'] ?? 0)>=2);
	$t->isTrue(count($registry->provenance())>count($registry->provenance('macro-plugin')));
})->tag('panel', 'extensions', 'registry', 'macros', 'themes', 'checkpoint')->maxMillis(2000);

test('instance registry contributor removal reveals lower layers and unscoped lookup rejects ambiguity', static function(Context $t): void {
	$first=new PanelInstanceExtensionRegistry('replace');
	$first->resetComponents(['widget_types'=>['layered'=>['marker'=>'core']]]);
	$first->runAs('layer-one', ['component.widget_types.register','extensible.macro.register','extensible.configurator.register','theme.*'], ['phase'=>'one'], static function() use ($first): void {
		$first->contributeComponent('widget_types', 'layered', ['marker'=>'one']);
		$first->contributeComponent('widget_types', 'ephemeral', ['marker'=>'one']);
		$first->registerMacro('UniqueCoverageMacro', 'owner', static fn(): string=>'one');
		$first->registerConfigurator('UniqueCoverageConfigurator', static fn(object $value): object=>$value);
		$first->registerThemePreset(['name'=>'layered-preset']);
		$first->registerTheme(['name'=>'layered-theme','colors'=>['primary'=>'#111111']]);
	});
	$first->runAs('layer-two', ['component.widget_types.register','extensible.macro.register','extensible.configurator.register','theme.*'], ['phase'=>'two'], static function() use ($first): void {
		$first->contributeComponent('widget_types', 'layered', ['marker'=>'two']);
		$first->registerMacro('UniqueCoverageMacro', 'owner', static fn(): string=>'two');
		$first->registerConfigurator('UniqueCoverageConfigurator', static fn(object $value): object=>$value, true);
		$first->registerThemePreset(['name'=>'layered-preset']);
		$first->registerTheme(['name'=>'layered-theme','colors'=>['primary'=>'#222222']]);
	});
	$t->same('two', $first->widgetTypes['layered']['marker'] ?? null);
	$t->same('two', ($first->macro('UniqueCoverageMacro', 'owner'))());
	$t->same(2, count($first->configurators('UniqueCoverageConfigurator')));
	$t->same($first, PanelInstanceExtensionRegistry::uniqueUnscopedMacro('UniqueCoverageMacro', 'owner')[0] ?? null);
	$t->same($first, PanelInstanceExtensionRegistry::uniqueUnscopedConfigurators('UniqueCoverageConfigurator')[0] ?? null);

	$second=new PanelInstanceExtensionRegistry('replace');
	$second->registerMacro('UniqueCoverageMacro', 'owner', static fn(): string=>'second');
	$second->registerConfigurator('UniqueCoverageConfigurator', static fn(object $value): object=>$value);
	$t->throws(static fn()=>PanelInstanceExtensionRegistry::uniqueUnscopedMacro('UniqueCoverageMacro', 'owner'), \LogicException::class);
	$t->throws(static fn()=>PanelInstanceExtensionRegistry::uniqueUnscopedConfigurators('UniqueCoverageConfigurator'), \LogicException::class);
	$second->flushMacros('UniqueCoverageMacro');
	$second->flushConfigurators('UniqueCoverageConfigurator');
	$t->same($first, PanelInstanceExtensionRegistry::uniqueUnscopedMacro('UniqueCoverageMacro', 'owner')[0] ?? null);
	$t->same($first, PanelInstanceExtensionRegistry::uniqueUnscopedConfigurators('UniqueCoverageConfigurator')[0] ?? null);

	$t->throws(static fn()=>$first->unregisterContributor(''), \InvalidArgumentException::class);
	$t->throws(static fn()=>$first->unregisterContributor('application'), \InvalidArgumentException::class);
	$t->same($first, $first->unregisterContributor('layer-two'));
	$t->same('one', $first->widgetTypes['layered']['marker'] ?? null);
	$t->same('one', ($first->macro('UniqueCoverageMacro', 'owner'))());
	$t->same(1, count($first->configurators('UniqueCoverageConfigurator')));
	$t->same([], $first->provenance('layer-two'));
	$t->isTrue($first->themeLibrary()->has('layered-preset'));
	$t->isTrue($first->themeLibrary()->hasTheme('layered-theme'));
	$first->unregisterContributor('layer-one');
	$t->same('core', $first->widgetTypes['layered']['marker'] ?? null);
	$t->same(null, $first->macro('UniqueCoverageMacro', 'owner'));
	$t->same([], $first->configurators('UniqueCoverageConfigurator'));
	$t->isFalse($first->themeLibrary()->has('layered-preset'));
	$t->isFalse($first->themeLibrary()->hasTheme('layered-theme'));
	$t->same(null, PanelInstanceExtensionRegistry::uniqueUnscopedMacro('UniqueCoverageMacro', 'owner'));
	$t->same(null, PanelInstanceExtensionRegistry::uniqueUnscopedConfigurators('UniqueCoverageConfigurator'));
})->tag('panel', 'extensions', 'registry', 'unload', 'ambiguity')->maxMillis(2000);
