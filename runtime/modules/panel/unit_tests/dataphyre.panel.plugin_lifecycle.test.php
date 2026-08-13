<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\NavigationItem;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelTenant;
use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

final class DpPanelLifecyclePlugin implements PanelPlugin {
	public function __construct(
		private string $pluginId,
		private \ArrayObject $events,
		private ?string $requires=null,
		private ?PanelPlugin $registerDuringBoot=null,
	) {}
	public function id(): string { return $this->pluginId; }
	public function register(PanelInstance $panel): void {
		$this->events[]='register:'.$this->pluginId;
	}
	public function boot(PanelInstance $panel): void {
		$this->events[]='boot:'.$this->pluginId.':'.($this->requires===null || $panel->hasPlugin($this->requires) ? 'ready' : 'missing');
		if($this->registerDuringBoot!==null){
			$panel->plugin($this->registerDuringBoot);
			$this->registerDuringBoot=null;
		}
	}
}

final class DpPanelFailOncePlugin implements PanelPlugin {
	private bool $failed=false;
	public function __construct(private \ArrayObject $events) {}
	public function id(): string { return 'fail-once'; }
	public function register(PanelInstance $panel): void { $this->events[]='register:fail-once'; }
	public function boot(PanelInstance $panel): void {
		$this->events[]='boot:fail-once';
		if(!$this->failed){$this->failed=true;throw new \RuntimeException('repairable boot failure');}
	}
}

final class DpPanelNestedContributionPlugin implements PanelPlugin {
	public function id(): string { return 'nested-contribution'; }
	public function register(PanelInstance $panel): void { $panel->register(Resource::make('nested-residue')); }
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelTransactionalRegistrationPlugin implements PanelPlugin {
	public int $attempts=0;
	public function id(): string { return 'transactional-registration'; }
	public function register(PanelInstance $panel): void {
		$this->attempts++;
		$panel->register(Resource::make('stable-resource')->label('Replacement'));
		$panel->register(Resource::make('plugin-residue'));
		$panel->registerPage(PanelPage::make('plugin-residue')->content('temporary'));
		$panel->registerWidget(Widget::make('plugin-residue')->value(1));
		$panel->registerNavigationItem(NavigationItem::make('plugin-residue')->url('/temporary'));
		$panel->registerCommand(PanelCommand::make('plugin-residue')->url('/temporary'));
		$panel->registerSearchProvider(PanelSearchProvider::make('plugin-residue')->searchUsing(static fn(): array=>[]));
		$panel->registerTenant(PanelTenant::make('plugin-residue'));
		$panel->theme(PanelTheme::make('plugin-residue'));
		$panel->manager()->theme(PanelTheme::make('manager-plugin-residue'));
		$panel->usePlatform(PanelPlatform::make());
		$panel->plugin(new DpPanelNestedContributionPlugin());
		if($this->attempts===1){
			throw new \RuntimeException('registration failed after contributions');
		}
	}
	public function boot(PanelInstance $panel): void {}
}

final class DpPanelDependencyPlugin implements PanelPlugin {
	/** @param array<int|string,string> $dependencies */
	public function __construct(private string $pluginId, private array $dependencies, private \ArrayObject $events){}
	public function id(): string { return $this->pluginId; }
	/** @return list<string> */ public function dependencies(): array { return $this->dependencies; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void { $this->events[]='boot:'.$this->pluginId; }
}

final class DpPanelDependencyValuePlugin implements PanelPlugin {
	public function __construct(private string $pluginId, private mixed $dependencyValue, private \ArrayObject $events){}
	public function id(): string { return $this->pluginId; }
	public function dependencies(): mixed { return $this->dependencyValue; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void { $this->events[]='boot:'.$this->pluginId; }
}

final class DpPanelParameterizedRequiresPlugin implements PanelPlugin {
	public function __construct(private \ArrayObject $events){}
	public function id(): string { return 'parameterized-requires'; }
	public function requires(string $capability): bool { return $capability!==''; }
	public function register(PanelInstance $panel): void {}
	public function boot(PanelInstance $panel): void { $this->events[]='boot:parameterized-requires'; }
}

test('plugins register as one phase and boot once after the complete graph exists', static function(Context $t): void {
	$events=new \ArrayObject();
	$panel=PanelInstance::make('plugin-lifecycle');
	$panel->plugins([
		new DpPanelLifecyclePlugin('alpha', $events, 'beta'),
		new DpPanelLifecyclePlugin('beta', $events, 'alpha'),
	]);

	$t->same(['register:alpha','register:beta'], iterator_to_array($events));
	$t->isFalse($panel->pluginsBooted());
	$panel->bootPlugins()->bootPlugins();
	$t->same(['register:alpha','register:beta','boot:alpha:ready','boot:beta:ready'], iterator_to_array($events));
	$t->isTrue($panel->pluginsBooted());
})->tag('panel', 'plugins', 'lifecycle', 'two-phase')->maxMillis(1000);

test('plugins added during boot register immediately and join the pending boot queue', static function(Context $t): void {
	$events=new \ArrayObject();
	$late=new DpPanelLifecyclePlugin('late', $events, 'root');
	$panel=PanelInstance::make('plugin-dynamic')
		->plugin(new DpPanelLifecyclePlugin('root', $events, null, $late));

	$panel->bootPlugins();
	$t->same(['register:root','boot:root:ready','register:late','boot:late:ready'], iterator_to_array($events));
	$t->same(['root','late'], $panel->pluginIds());
	$t->isTrue($panel->pluginsBooted());
})->tag('panel', 'plugins', 'lifecycle', 'dynamic')->maxMillis(1000);

test('render boundaries complete pending plugin boot without double booting', static function(Context $t): void {
	$events=new \ArrayObject();
	$panel=PanelInstance::make('plugin-boundary')->plugin(new DpPanelLifecyclePlugin('boundary', $events));

	$panel->describe();
	$panel->panelManifest();
	$t->same(['register:boundary','boot:boundary:ready'], iterator_to_array($events));
	$t->isTrue($panel->pluginsBooted());
})->tag('panel', 'plugins', 'lifecycle', 'render')->maxMillis(1500);

test('a failed plugin remains pending and can be retried without re-registering', static function(Context $t): void {
	$events=new \ArrayObject();
	$panel=PanelInstance::make('plugin-retry')->plugin(new DpPanelFailOncePlugin($events));
	try{
		$panel->bootPlugins();
		$t->fail('Expected the first plugin boot to fail.');
	}
	catch(\RuntimeException $exception){
		$t->same('repairable boot failure', $exception->getMessage());
	}
	$t->isFalse($panel->pluginsBooted());
	$panel->bootPlugins();
	$t->same(['register:fail-once','boot:fail-once','boot:fail-once'], iterator_to_array($events));
	$t->isTrue($panel->pluginsBooted());
})->tag('panel', 'plugins', 'lifecycle', 'retry')->maxMillis(1000);

test('failed plugin registration rolls back every staged contribution and retry starts cleanly', static function(Context $t): void {
	$baseline=Resource::make('stable-resource')->label('Baseline');
	$panel=PanelInstance::make('plugin-registration-transaction');
	$panel->register($baseline);
	$panel->theme(PanelTheme::make('baseline'));
	$manager=$panel->manager();
	$manager->theme(PanelTheme::make('manager-baseline'));
	$plugin=new DpPanelTransactionalRegistrationPlugin();

	try{
		$panel->plugin($plugin, ['attempt'=>'first']);
		$t->fail('Expected plugin registration to fail.');
	}
	catch(\RuntimeException $exception){
		$t->same('registration failed after contributions', $exception->getMessage());
	}

	$t->same(1, $plugin->attempts);
	$t->same([], $panel->pluginIds());
	$t->isFalse($panel->hasPlugin('transactional-registration'));
	$t->isFalse($panel->hasPlugin('nested-contribution'));
	$t->same($baseline, $manager->resources()['stable-resource'] ?? null);
	$t->isFalse(isset($manager->resources()['plugin-residue'], $manager->resources()['nested-residue']));
	$t->isFalse(isset($manager->pages()['plugin-residue']));
	$t->isFalse(isset($manager->widgets()['plugin-residue']));
	$t->isFalse(isset($manager->navigationItems()['plugin-residue']));
	$t->isFalse(isset($manager->registeredCommands()['plugin-residue']));
	$t->isFalse($manager->hasSearchProvider('plugin-residue'));
	$t->same(null, $manager->tenant('plugin-residue'));
	$t->same('baseline', $panel->theme()->name());
	$t->same('manager-baseline', $manager->theme()->name());
	$t->isFalse($panel->hasPlatform());

	$panel->plugin($plugin, ['attempt'=>'retry']);
	$t->same(2, $plugin->attempts);
	$t->same(['transactional-registration','nested-contribution'], $panel->pluginIds());
	$t->same('retry', $panel->pluginConfig('transactional-registration')['attempt'] ?? null);
	$t->isTrue(isset($manager->resources()['plugin-residue'], $manager->resources()['nested-residue']));
	$t->isTrue($manager->hasSearchProvider('plugin-residue'));
	$t->isTrue($manager->tenant('plugin-residue') instanceof PanelTenant);
	$t->same('plugin-residue', $panel->theme()->name());
	$t->same('manager-plugin-residue', $manager->theme()->name());
	$t->isTrue($panel->hasPlatform());
})->tag('panel', 'plugins', 'lifecycle', 'transaction', 'rollback', 'wave0-contracts')->maxMillis(1500);

test('plugin dependency graph is validated before boot callbacks and boots dependency first', static function(Context $t): void {
	$events=new \ArrayObject();
	$panel=PanelInstance::make('plugin-dependencies')
		->plugin(new DpPanelDependencyPlugin('consumer', ['foundation'], $events))
		->plugin(new DpPanelDependencyPlugin('foundation', [], $events));
	$panel->bootPlugins();
	$t->same(['boot:foundation','boot:consumer'], iterator_to_array($events));

	$missingEvents=new \ArrayObject();
	$missing=PanelInstance::make('plugin-missing-dependency')
		->plugin(new DpPanelDependencyPlugin('consumer', ['absent'], $missingEvents));
	$t->throws(static fn()=>$missing->bootPlugins(), \LogicException::class);
	$t->same([], iterator_to_array($missingEvents));
	$t->isFalse($missing->pluginsBooted());

	$cycleEvents=new \ArrayObject();
	$cycle=PanelInstance::make('plugin-cycle')
		->plugin(new DpPanelDependencyPlugin('left', ['right'], $cycleEvents))
		->plugin(new DpPanelDependencyPlugin('right', ['left'], $cycleEvents));
	$t->throws(static fn()=>$cycle->bootPlugins(), \LogicException::class);
	$t->same([], iterator_to_array($cycleEvents));
})->tag('panel', 'plugins', 'lifecycle', 'dependencies', 'wave0-contracts')->maxMillis(1000);

test('plugin dependency conventions normalize strings maps and traversables and reject unsafe values', static function(Context $t): void {
	$events=new \ArrayObject();
	$panel=PanelInstance::make('plugin-dependency-shapes')
		->plugin(new DpPanelDependencyValuePlugin('string-consumer', 'foundation', $events))
		->plugin(new DpPanelDependencyValuePlugin('mapped-consumer', ['foundation'=>'^1'], $events))
		->plugin(new DpPanelDependencyValuePlugin('traversable-consumer', new \ArrayIterator(['foundation']), $events))
		->plugin(new DpPanelDependencyValuePlugin('foundation', null, $events))
		->plugin(new DpPanelParameterizedRequiresPlugin($events));
	$panel->bootPlugins();
	$t->same([
		'boot:foundation',
		'boot:string-consumer',
		'boot:mapped-consumer',
		'boot:traversable-consumer',
		'boot:parameterized-requires',
	], iterator_to_array($events));

	foreach([
		'true'=>true,
		'object-item'=>[new \stdClass()],
		'empty-item'=>['   '],
	] as $id=>$dependencyValue){
		$invalidEvents=new \ArrayObject();
		$invalid=PanelInstance::make('plugin-invalid-'.$id)
			->plugin(new DpPanelDependencyValuePlugin($id, $dependencyValue, $invalidEvents));
		$t->throws(static fn()=>$invalid->bootPlugins(), \UnexpectedValueException::class);
		$t->same([], iterator_to_array($invalidEvents));
	}
})->tag('panel', 'plugins', 'lifecycle', 'dependencies', 'validation', 'wave0-contracts')->maxMillis(1000);

test('manager contribution checkpoints validate their complete shape before restoring state', static function(Context $t): void {
	$manager=PanelInstance::make('manager-checkpoint-validation')->manager();
	$manager->authorize(static fn(): bool=>true);
	$checkpoint=$manager->contributionCheckpoint();
	$t->same($manager, $manager->restoreContributionCheckpoint($checkpoint));
	$t->throws(static fn()=>$manager->restoreContributionCheckpoint([]), \InvalidArgumentException::class);

	$invalidArrays=$checkpoint;
	$invalidArrays['resources']=null;
	$t->throws(static fn()=>$manager->restoreContributionCheckpoint($invalidArrays), \InvalidArgumentException::class);
	$invalidTenant=$checkpoint;
	$invalidTenant['tenant_registry']=new \stdClass();
	$t->throws(static fn()=>$manager->restoreContributionCheckpoint($invalidTenant), \InvalidArgumentException::class);
	$invalidTheme=$checkpoint;
	$invalidTheme['theme']=new \stdClass();
	$t->throws(static fn()=>$manager->restoreContributionCheckpoint($invalidTheme), \InvalidArgumentException::class);
	$invalidAuthorizer=$checkpoint;
	$invalidAuthorizer['authorizer']='not-a-closure';
	$t->throws(static fn()=>$manager->restoreContributionCheckpoint($invalidAuthorizer), \InvalidArgumentException::class);
})->tag('panel', 'plugins', 'lifecycle', 'checkpoint', 'validation', 'wave0-contracts')->maxMillis(500);
