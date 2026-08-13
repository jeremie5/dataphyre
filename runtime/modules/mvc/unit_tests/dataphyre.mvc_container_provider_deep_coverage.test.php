<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mvc\CallbackServiceProvider;
use Dataphyre\Mvc\Container;
use Dataphyre\Mvc\ContainerException;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\ProviderRegistry;
use Dataphyre\Mvc\ServiceProvider;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

interface DpMvcContainerCoverageContract {}

final class DpMvcContainerCoverageService implements DpMvcContainerCoverageContract {}

final class DpMvcContainerCoverageDependency {
	public function __construct(public DpMvcContainerCoverageContract $service){}
}

final class DpMvcContainerCoverageNoConstructor {}

abstract class DpMvcContainerCoverageAbstract {}

interface DpMvcContainerCoverageUnboundContract {}

final class DpMvcContainerCoverageCircularOne {
	public function __construct(public DpMvcContainerCoverageCircularTwo $two){}
}

final class DpMvcContainerCoverageCircularTwo {
	public function __construct(public DpMvcContainerCoverageCircularOne $one){}
}

final class DpMvcContainerCoverageArguments {
	public function __construct(
		public string $first,
		public string $second,
		public string $third='third',
		public ?string $fourth=null
	){}
}

final class DpMvcContainerCoveragePair {
	public function __construct(public string $first, public string $second){}
}

final class DpMvcContainerCoverageRequired {
	public function __construct(public string $required){}
}

final class DpMvcContainerCoverageNullableRequired {
	public function __construct(public ?string $value){}
}

final class DpMvcContainerCoverageNestedScalarDependency {
	public function __construct(public string $value){}
}

final class DpMvcContainerCoverageOptionalNestedDependency {
	public function __construct(public ?DpMvcContainerCoverageNestedScalarDependency $dependency=null){}
}

final class DpMvcContainerCoverageRequiredNestedDependency {
	public function __construct(public DpMvcContainerCoverageNestedScalarDependency $dependency){}
}

final class DpMvcContainerCoverageCallable {
	public static function staticAction(DpMvcContainerCoverageContract $service, string $suffix='!'): string {
		return $service::class.$suffix;
	}

	public function instanceAction(DpMvcContainerCoverageContract $service, string $suffix='?'): string {
		return $service::class.$suffix;
	}
}

final class DpMvcContainerCoverageInvokable {
	public function __invoke(DpMvcContainerCoverageContract $service, string $suffix='~'): string {
		return $service::class.$suffix;
	}
}

final class DpMvcProviderCoverageOne extends ServiceProvider {
	public static int $registeredCount=0;
	public static int $bootedCount=0;

	public function register(MvcApplication $app, ProviderRegistry $providers): void {
		parent::register($app, $providers);
		self::$registeredCount++;
	}

	public function boot(MvcApplication $app, ProviderRegistry $providers): void {
		parent::boot($app, $providers);
		self::$bootedCount++;
	}
}

final class DpMvcProviderCoverageTwo extends ServiceProvider {
	public static int $registeredCount=0;
	public static int $bootedCount=0;

	public function register(MvcApplication $app, ProviderRegistry $providers): void {
		parent::register($app, $providers);
		self::$registeredCount++;
	}

	public function boot(MvcApplication $app, ProviderRegistry $providers): void {
		parent::boot($app, $providers);
		self::$bootedCount++;
	}
}

final class DpMvcProviderCoverageLate extends ServiceProvider {}

final class DpMvcProviderCoverageAfterBoot extends ServiceProvider {}

final class DpMvcProviderCoverageRegisterDuringBoot extends ServiceProvider {
	public static bool $caught=false;

	public function boot(MvcApplication $app, ProviderRegistry $providers): void {
		parent::boot($app, $providers);
		try{
			$providers->register(DpMvcProviderCoverageLate::class);
		}catch(LogicException){
			self::$caught=true;
		}
	}
}

final class DpMvcProviderCoverageStable extends ServiceProvider {
	public static int $bootedCount=0;

	public function boot(MvcApplication $app, ProviderRegistry $providers): void {
		parent::boot($app, $providers);
		self::$bootedCount++;
	}
}

final class DpMvcProviderCoverageFlaky extends ServiceProvider {
	public static int $bootedCount=0;

	public function boot(MvcApplication $app, ProviderRegistry $providers): void {
		parent::boot($app, $providers);
		self::$bootedCount++;
		if(self::$bootedCount===1){
			throw new RuntimeException('first boot fails');
		}
	}
}

final class DpMvcProviderCoverageCallableHost {
	public static int $staticCount=0;
	public int $objectCount=0;

	public static function registerStatic(MvcApplication $app, ProviderRegistry $providers, CallbackServiceProvider $provider): void {
		self::$staticCount++;
	}

	public function registerObject(MvcApplication $app, ProviderRegistry $providers, CallbackServiceProvider $provider): void {
		$this->objectCount++;
	}
}

final class DpMvcProviderCoverageInvokable {
	public int $count=0;

	public function __invoke(MvcApplication $app, ProviderRegistry $providers, CallbackServiceProvider $provider): void {
		$this->count++;
	}
}

function dp_mvc_provider_coverage_function(MvcApplication $app, ProviderRegistry $providers, CallbackServiceProvider $provider): void {
	TestState::channel('mvc.provider-callback')->increment('function_count');
}

test('mvc container deep coverage resolves registrations aliases and concrete chains', static function(Context $t): void {
	$container=new Container();
	$t->isFalse($container->has('missing-service'));
	$t->isTrue($container->has(DpMvcContainerCoverageNoConstructor::class));
	$t->isFalse($container->has(DpMvcContainerCoverageAbstract::class));

	$container->bind('factory', static function(Container $seen, array $parameters, array $typedValues) use ($container): array {
		return [$seen===$container, $parameters, $typedValues];
	});
	$t->isTrue($container->has('factory'));
	$t->same([true, ['value'=>7], ['typed'=>'yes']], $container->make('factory', ['value'=>7], ['typed'=>'yes']));

	$container->instance('replaceable', 'instance');
	$t->same('instance', $container->make('replaceable'));
	$container->bind('replaceable', static fn(): string=>'binding');
	$t->same('binding', $container->make('replaceable'));
	$container->bind('overwritten', static fn(): string=>'binding');
	$container->instance('overwritten', 'instance');
	$t->same('instance', $container->make('overwritten'));

	$container->singleton('shared', static fn(): object=>new stdClass());
	$shared=$container->make('shared');
	$t->isTrue($shared===$container->make('shared'));
	$container->bind(DpMvcContainerCoverageNoConstructor::class);
	$t->instanceOf(DpMvcContainerCoverageNoConstructor::class, $container->make(DpMvcContainerCoverageNoConstructor::class));

	$container->bind('target', static fn(): string=>'chained');
	$container->bind('source', 'target');
	$t->same('chained', $container->make('source'));

	$container->instance('canonical', 'aliased');
	$container->alias('canonical', 'first-alias')->alias('first-alias', 'second-alias');
	$t->isTrue($container->has('second-alias'));
	$t->same('aliased', $container->make('second-alias'));
	$t->throws(static fn()=>$container->alias('same', 'same'), ContainerException::class);

	$cyclicAliases=new Container();
	$cyclicAliases->alias('cycle-b', 'cycle-a')->alias('cycle-a', 'cycle-b');
	$t->throws(static fn()=>$cyclicAliases->has('cycle-a'), ContainerException::class);
	$t->throws(static fn()=>$container->bind('   '), ContainerException::class);
})->tag('mvc', 'container-provider-deep-coverage')->group('framework-coverage');

test('mvc container deep coverage builds typed positional default nullable and failure paths', static function(Context $t): void {
	$container=new Container();
	$container->bind(DpMvcContainerCoverageContract::class, DpMvcContainerCoverageService::class);
	$dependency=$container->make(DpMvcContainerCoverageDependency::class);
	$t->instanceOf(DpMvcContainerCoverageService::class, $dependency->service);

	$keyed=new DpMvcContainerCoverageService();
	$t->isTrue($keyed===$container->make(
		DpMvcContainerCoverageDependency::class,
		[],
		[DpMvcContainerCoverageContract::class=>$keyed]
	)->service);
	$listValue=new DpMvcContainerCoverageService();
	$t->isTrue($listValue===$container->make(
		DpMvcContainerCoverageDependency::class,
		[],
		[$listValue]
	)->service);

	$arguments=$container->make(DpMvcContainerCoverageArguments::class, ['one', 'two']);
	$t->same('one', $arguments->first);
	$t->same('two', $arguments->second);
	$t->same('third', $arguments->third);
	$t->same(null, $arguments->fourth);
	$pair=$container->make(DpMvcContainerCoveragePair::class, ['first'=>'named', 0=>'positional']);
	$t->same('named', $pair->first);
	$t->same('positional', $pair->second);
	$t->same(null, $container->make(DpMvcContainerCoverageNullableRequired::class)->value);
	$t->same(null, $container->make(DpMvcContainerCoverageOptionalNestedDependency::class)->dependency);
	$t->throws(static fn()=>$container->make(DpMvcContainerCoverageRequiredNestedDependency::class), ContainerException::class);
	$explicitOptional=new Container();
	$explicitOptional->bind(DpMvcContainerCoverageNestedScalarDependency::class);
	$t->throws(static fn()=>$explicitOptional->make(DpMvcContainerCoverageOptionalNestedDependency::class), ContainerException::class);

	$t->throws(static fn()=>$container->make(DpMvcContainerCoverageRequired::class), ContainerException::class);
	$t->throws(static fn()=>$container->make(DpMvcContainerCoverageUnboundContract::class), ContainerException::class);
	$t->throws(static fn()=>$container->make('DpMvcContainerCoverageMissingClass'), ContainerException::class);
	$t->throws(static fn()=>$container->make(DpMvcContainerCoverageAbstract::class), ContainerException::class);
	$t->throws(static fn()=>$container->make(DpMvcContainerCoverageCircularOne::class), ContainerException::class);
	$t->instanceOf(DpMvcContainerCoverageNoConstructor::class, $container->make(DpMvcContainerCoverageNoConstructor::class));
})->tag('mvc', 'container-provider-deep-coverage')->group('framework-coverage');

test('mvc container deep coverage normalizes every callable notation', static function(Context $t): void {
	$container=new Container();
	$container->bind(DpMvcContainerCoverageContract::class, DpMvcContainerCoverageService::class);
	$expected=DpMvcContainerCoverageService::class;
	$t->same($expected.'!', $container->call([DpMvcContainerCoverageCallable::class, 'staticAction']));
	$t->same($expected.'a', $container->call([DpMvcContainerCoverageCallable::class, 'instanceAction'], ['suffix'=>'a']));
	$t->same($expected.'b', $container->call([new DpMvcContainerCoverageCallable(), 'instanceAction'], ['suffix'=>'b']));
	$t->same($expected.'c', $container->call(DpMvcContainerCoverageCallable::class.'@instanceAction', ['suffix'=>'c']));
	$t->same($expected.'d', $container->call(DpMvcContainerCoverageCallable::class.'::staticAction', ['suffix'=>'d']));
	$t->same($expected.'e', $container->call(DpMvcContainerCoverageCallable::class.'::instanceAction', ['suffix'=>'e']));
	$t->same($expected.'~', $container->call(DpMvcContainerCoverageInvokable::class));
	$t->same($expected.'f', $container->call(new DpMvcContainerCoverageInvokable(), ['suffix'=>'f']));
	$t->same('closure', $container->call(static fn(string $value='closure'): string=>$value));
	$t->throws(static fn()=>$container->call('DpMvcContainerCoverageMissingCallable'), ContainerException::class);
})->tag('mvc', 'container-provider-deep-coverage')->group('framework-coverage');

test('mvc provider registry deep coverage resolves identities and registration forms', static function(Context $t): void {
	$state=$t->state('mvc.provider-callback',['function_count'=>0]);
	DpMvcProviderCoverageOne::$registeredCount=0;
	DpMvcProviderCoverageTwo::$registeredCount=0;
	DpMvcProviderCoverageCallableHost::$staticCount=0;
	$app=new MvcApplication('provider-identity-coverage');
	$registry=$app->providers();
	$t->isTrue($registry->app()===$app);
	$t->isFalse($registry->registered());
	$t->isFalse($registry->booted());
	$t->same([], $registry->providers());
	$t->isFalse($registry->has(DpMvcProviderCoverageOne::class));
	$t->same(null, $registry->get(DpMvcProviderCoverageOne::class));

	$one=new DpMvcProviderCoverageOne();
	$t->isTrue($one===$registry->register($one));
	$t->isTrue($one===$registry->register(new DpMvcProviderCoverageOne()));
	$t->same(1, DpMvcProviderCoverageOne::$registeredCount);
	$two=$registry->register(DpMvcProviderCoverageTwo::class);
	$t->instanceOf(DpMvcProviderCoverageTwo::class, $two);
	$t->isTrue($registry->has(DpMvcProviderCoverageTwo::class));
	$t->isTrue($two===$registry->get(DpMvcProviderCoverageTwo::class));

	$closureCount=0;
	$closure=static function() use (&$closureCount): void { $closureCount++; };
	$closureProvider=$registry->register($closure);
	$t->isTrue($closureProvider===$registry->register($closure));
	$t->same(1, $closureCount);

	$explicitCount=0;
	$explicit=new CallbackServiceProvider(static function() use (&$explicitCount): void { $explicitCount++; }, null, 'explicit-provider');
	$t->isTrue($explicit===$registry->register($explicit));
	$t->isTrue($explicit===$registry->register(new CallbackServiceProvider(static fn()=>null, null, 'explicit-provider')));
	$t->same(1, $explicitCount);

	$host=new DpMvcProviderCoverageCallableHost();
	$registry->register([$host, 'registerObject']);
	$registry->register([DpMvcProviderCoverageCallableHost::class, 'registerStatic']);
	$t->same(1, $host->objectCount);
	$t->same(1, DpMvcProviderCoverageCallableHost::$staticCount);
	$invokable=new DpMvcProviderCoverageInvokable();
	$registry->register($invokable);
	$t->same(1, $invokable->count);
	$registry->register('dp_mvc_provider_coverage_function');
	$t->same(1,$state->get('function_count'));

	$manyCount=0;
	$many=static function() use (&$manyCount): void { $manyCount++; };
	$registry->registerMany((static function() use ($many): Generator {
		yield null;
		yield false;
		yield $many;
	})());
	$t->same(1, $manyCount);
	$t->isTrue($registry->registered());
	$t->isTrue(count($registry->providers())>=8);

	$t->throws(static fn()=>$registry->register(stdClass::class), InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->register('DpMvcProviderCoverageMissingProvider'), InvalidArgumentException::class);
})->tag('mvc', 'container-provider-deep-coverage')->group('framework-coverage');

test('mvc provider registry deep coverage locks registration and retries partial boot', static function(Context $t): void {
	DpMvcProviderCoverageRegisterDuringBoot::$caught=false;
	$app=new MvcApplication('provider-lock-coverage');
	$registry=$app->providers();
	$registry->register(DpMvcProviderCoverageRegisterDuringBoot::class);
	$registry->boot();
	$t->isTrue(DpMvcProviderCoverageRegisterDuringBoot::$caught);
	$t->isTrue($registry->booted());
	$t->throws(static fn()=>$registry->register(DpMvcProviderCoverageAfterBoot::class), LogicException::class);

	DpMvcProviderCoverageStable::$bootedCount=0;
	DpMvcProviderCoverageFlaky::$bootedCount=0;
	$retryApp=new MvcApplication('provider-retry-coverage');
	$retry=$retryApp->providers();
	$retry->registerMany([
		DpMvcProviderCoverageStable::class,
		DpMvcProviderCoverageFlaky::class,
	]);
	$t->throws(static fn()=>$retry->boot(), RuntimeException::class);
	$t->isFalse($retry->booted());
	$retry->boot();
	$t->same(1, DpMvcProviderCoverageStable::$bootedCount);
	$t->same(2, DpMvcProviderCoverageFlaky::$bootedCount);
	$t->isTrue($retry->booted());
	$retry->boot();
	$t->same(1, DpMvcProviderCoverageStable::$bootedCount);
	$t->same(2, DpMvcProviderCoverageFlaky::$bootedCount);
})->tag('mvc', 'container-provider-deep-coverage')->group('framework-coverage');

test('mvc callback service provider invokes and skips optional boot callbacks safely', static function(Context $t): void {
	$app=new MvcApplication('callback-provider-boot-coverage');
	$registry=$app->providers();
	$seen=[];
	$provider=new CallbackServiceProvider(
		null,
		static function(MvcApplication $bootApp, ProviderRegistry $bootRegistry, CallbackServiceProvider $bootProvider) use (&$seen, $app, $registry): void {
			$seen=[
				'app'=>$bootApp===$app,
				'registry'=>$bootRegistry===$registry,
				'provider'=>$bootProvider->identity(),
			];
		},
		'boot-callback-provider'
	);
	$registry->register($provider);
	$registry->boot();
	$t->same(['app'=>true, 'registry'=>true, 'provider'=>'boot-callback-provider'], $seen);

	$noopApp=new MvcApplication('callback-provider-noop-boot-coverage');
	$noopRegistry=$noopApp->providers();
	$noopRegistry->register(new CallbackServiceProvider(null, 'not-callable', 'noop-boot-provider'));
	$noopRegistry->boot();
	$t->isTrue($noopRegistry->booted());
})->tag('mvc', 'container-provider-deep-coverage')->group('framework-coverage');
