<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!class_exists(core::class, false)){
		class core {
			public static string $displayLanguage='en';
			public static array $csrfValues=[];
			public static array $dialbacks=[];
			public static function get_client_ip_details(): array {
				return [
					'ip'=>'203.0.113.9',
					'remote_addr'=>'10.0.0.2',
					'source'=>'header',
					'source_header'=>'X-Forwarded-For',
					'trusted_proxy'=>true,
					'trusted_headers'=>['X-Forwarded-For'],
					'trusted_proxies'=>['10.0.0.2'],
				];
			}
			public static function get_config(string $key): mixed {
				return null;
			}
			public static function high_precision_server_date(string $format): string {
				return (new \DateTimeImmutable('2026-07-10 12:34:56.123456', new \DateTimeZone('UTC')))->format($format);
			}
			public static function format_date(string $date, string $format, bool $translation=true): string {
				return 'formatted:'.$date.':'.$format.':'.($translation ? 'yes' : 'no');
			}
			public static function convert_to_user_date(string|int $date, string $timezone, string $format, bool $translation=true): string {
				return 'user:'.$date.':'.$timezone.':'.$format.':'.($translation ? 'yes' : 'no');
			}
			public static function convert_to_server_date(string|int $date, string $timezone, string $format): string {
				return 'server:'.$date.':'.$timezone.':'.$format;
			}
			public static function csrf(string $formName, mixed $token=null): mixed {
				if(func_num_args()>1){
					return is_string($token) && $token===(self::$csrfValues[$formName] ?? 'token-'.$formName);
				}
				return self::$csrfValues[$formName] ??= 'token-'.$formName;
			}
			public static function load_framework_module(string $module): bool {
				return $module!=='missing';
			}
			public static function load_framework_modules(array|string $modules): array {
				$modules=is_array($modules) ? $modules : [$modules];
				return array_fill_keys($modules, true);
			}
			public static function register_dialback(string $eventName, callable $callback): bool {
				self::$dialbacks[$eventName][]=$callback;
				return true;
			}
			public static function has_dialback(string $eventName): bool {
				return (self::$dialbacks[$eventName] ?? [])!==[];
			}
			public static function dialback_callbacks(string $eventName): array {
				return self::$dialbacks[$eventName] ?? [];
			}
			public static function dialback_all(): array {
				return self::$dialbacks;
			}
			public static function dialback(string $eventName, mixed ...$data): mixed {
				$result=null;
				foreach(self::$dialbacks[$eventName] ?? [] as $callback){
					$result=$callback(...$data);
				}
				return $result;
			}
		}
	}

	if(!class_exists(date_translation::class, false)){
		final class date_translation {
			public static function translate_date(string $date, string $language, string $format): string {
				return 'translated:'.$language.':'.$format.':'.$date;
			}
		}
	}

}

namespace {
	use Dataphyre\Application;
	use Dataphyre\ApplicationCatalog;
	use Dataphyre\App;
	use Dataphyre\Bootstrap;
	use Dataphyre\BootstrapCatalog;
	use Dataphyre\BootstrapPlan;
	use Dataphyre\ClientAddress;
	use Dataphyre\Csrf;
	use Dataphyre\CsrfToken;
	use Dataphyre\Date;
	use Dataphyre\DateValue;
	use Dataphyre\Dialback;
	use Dataphyre\DialbackEvent;
	use Dataphyre\Module;
	use Dataphyre\ModuleCatalog;
	use Dataphyre\ModuleDefinition;
	use Dataphyre\RuntimeState;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	function dp_core_dialback_function(string $value='function'): string {
		return 'function:'.$value;
	}

	final class DpCoreDialbackTarget {
		public function instance(string $value='instance'): string { return 'instance:'.$value; }
		public static function staticMethod(string $value='static'): string { return 'static:'.$value; }
	}

	final class DpCoreDialbackInvoker {
		public function __invoke(string $value='invoke'): string { return 'invoke:'.$value; }
	}

	framework(['core']);

	test('core client address value covers normalization address families trust metadata and serialization cache', static function(Context $t): void {
		$current=ClientAddress::current();
		$t->same('203.0.113.9', $current->ip());
		$t->same('10.0.0.2', $current->remoteAddress());
		$t->same('header', $current->source());
		$t->same('X-Forwarded-For', $current->sourceHeader());
		$t->isTrue($current->trustedProxy());
		$t->isTrue($current->forwarded());
		$t->same(['X-Forwarded-For'], $current->trustedHeaders());
		$t->same(['10.0.0.2'], $current->trustedProxies());
		$t->isTrue($current->isIpv4());
		$t->isFalse($current->isIpv6());
		$t->isFalse($current->isLoopback());
		$t->isFalse($current->isPrivate());
		$payload=$current->toArray();
		$t->hasConsistentSerialization($current, $payload);
		$t->same('203.0.113.9', (string)$current);

		$defaults=ClientAddress::fromArray([
			'trusted_headers'=>'invalid',
			'trusted_proxies'=>'invalid',
			'source_header'=>'',
		]);
		$t->same('0.0.0.0', $defaults->ip());
		$t->same('0.0.0.0', $defaults->remoteAddress());
		$t->same(null, $defaults->sourceHeader());
		$t->same([], $defaults->trustedHeaders());
		$t->same([], $defaults->trustedProxies());

		$private=ClientAddress::fromArray(['ip'=>'10.1.2.3']);
		$t->isTrue($private->isPrivate());
		$loopback=ClientAddress::fromArray(['ip'=>'::1']);
		$t->isTrue($loopback->isIpv6());
		$t->isTrue($loopback->isLoopback());
		$invalid=ClientAddress::fromArray(['ip'=>'invalid']);
		$t->isFalse($invalid->isIpv4());
		$t->isFalse($invalid->isIpv6());
		$t->isFalse($invalid->isPrivate());
	})->tag('core', 'values', 'deep-coverage')->group('framework-coverage');

	test('core date facade and immutable values cover parsing timezone conversion formatting and serialization', static function(Context $t): void {
		$immutable=new DateTimeImmutable('2026-07-10 12:34:56.123456', new DateTimeZone('UTC'));
		$fromImmutable=DateValue::fromDateTime($immutable);
		$t->same($immutable, $fromImmutable->datetime());
		$mutable=new DateTime('2026-07-10 12:34:56', new DateTimeZone('UTC'));
		$fromMutable=DateValue::fromDateTime($mutable);
		$t->instanceOf(DateTimeImmutable::class, $fromMutable->datetime());

		$timestamp=DateValue::fromValue(0, 'UTC');
		$t->same(0, $timestamp->timestamp());
		$t->same(0, DateValue::fromValue('0', 'UTC')->timestamp());
		$value=DateValue::fromValue('2026-07-10 12:34:56.123456', 'UTC');
		$t->same('UTC', $value->timezone());
		$t->same('2026-07-10', $value->date());
		$t->same('12:34:56', $value->time());
		$t->same('12:34:56.123456', $value->time(true));
		$t->same('2026-07-10 12:34:56', $value->sql());
		$t->same('2026-07-10 12:34:56.123456', $value->sql(true));
		$t->contains('2026-07-10T12:34:56', $value->iso8601());
		$t->same('2026', $value->format('Y'));
		$t->contains('translated:en:Y-m-d:2026-07-10', $value->translated('Y-m-d', true));
		$t->same('2026-07-10', $value->translated('Y-m-d', false));
		$t->same('America/Toronto', $value->inTimezone('America/Toronto')->timezone());
		$t->same('America/Toronto', $value->toUser('America/Toronto')->timezone());
		$t->same(Date::serverTimezone(), $value->toServer()->timezone());
		$payload=$value->toArray();
		$t->hasConsistentSerialization($value, $payload);

		$t->same('2026-07-10', Date::now('Y-m-d'));
		$t->instanceOf(DateValue::class, Date::nowValue());
		$t->same('America/Toronto', Date::nowValue('America/Toronto')->timezone());
		$t->same('formatted:today:Y-m-d:no', Date::format('today', 'Y-m-d', false));
		$t->same('user:0:America/Toronto:Y:no', Date::toUser(0, 'America/Toronto', 'Y', false));
		$t->same('server:0:America/Toronto:Y', Date::toServer(0, 'America/Toronto', 'Y'));
		$t->notEmpty(Date::serverTimezone());
		$t->notEmpty(Date::defaultUserTimezone());
		$t->instanceOf(DateValue::class, Date::value('2026-07-10'));
		$t->instanceOf(DateValue::class, Date::serverValue('2026-07-10'));
		$t->same('America/Toronto', Date::userValue('2026-07-10', 'America/Toronto')->timezone());
		$t->same('UTC', Date::normalizeTimezone(' UTC '));
		$t->same(date_default_timezone_get(), Date::normalizeTimezone('Invalid/Zone'));
		$t->same('America/Toronto', Date::normalizeUserTimezone(' America/Toronto '));
		$t->same(Date::defaultUserTimezone(), Date::normalizeUserTimezone('Invalid/Zone'));
	})->tag('core', 'values', 'deep-coverage')->group('framework-coverage');

	test('core CSRF value and facade cover caching refresh validation equality escaping and serialization', static function(Context $t): void {
		\dataphyre\core::$csrfValues=['login'=>'token-login', 'empty'=>[]];
		$token=CsrfToken::for(' login ');
		$t->same('login', $token->formName());
		$t->same('token-login', $token->value());
		\dataphyre\core::$csrfValues['login']='changed';
		$t->same('token-login', $token->value());
		$t->same($token, $token->refresh());
		$t->same('changed', $token->value());
		$t->isTrue($token->validate('changed'));
		$t->isFalse($token->validate('wrong'));
		$t->isTrue($token->equals('changed'));
		$t->isFalse($token->equals(123));
		$t->contains('name="csrf"', $token->hiddenField(''));
		$t->contains('name="a&amp;quot;b"', $token->hiddenField('a&quot;b'));
		$t->same(['form_name'=>'login', 'token'=>'changed'], $token->toArray());
		$t->same($token->toArray(), $token->jsonSerialize());
		$t->same('changed', (string)$token);
		$t->same('', CsrfToken::for('empty')->value());
		$t->isFalse(CsrfToken::for('empty')->equals(''));

		$t->instanceOf(CsrfToken::class, Csrf::token('facade'));
		$t->same('token-facade', Csrf::value('facade'));
		$t->isTrue(Csrf::validate('facade', 'token-facade'));
		$t->contains('type="hidden"', Csrf::hiddenField('facade', 'csrf_token'));
	})->tag('core', 'values', 'deep-coverage')->group('framework-coverage');

	test('core module definition catalog facade and runtime state cover registry projections and source shapes', static function(Context $t): void {
		$alpha=ModuleDefinition::fromArray([
			'module'=>'Alpha', 'version'=>'1.2.3', 'enabled'=>true,
			'directory'=>'/modules/alpha', 'common_directory'=>'/common/alpha', 'app_directory'=>null,
			'kernel_entry'=>'/modules/alpha/kernel.php', 'framework_entry'=>'/modules/alpha/framework.php',
			'framework_directory'=>'/modules/alpha/Framework', 'framework_namespace'=>'Dataphyre\\Alpha',
		]);
		$beta=ModuleDefinition::fromArray([
			'module'=>'Beta', 'version'=>'2.0', 'enabled'=>false,
			'directory'=>'/modules/beta', 'common_directory'=>null, 'app_directory'=>'/app/beta',
		]);
		$hybrid=new ModuleDefinition('Hybrid', '3.0', true, '/modules/hybrid', '/common/hybrid', '/app/hybrid', null, null, '/framework/hybrid', 'Dataphyre\\Hybrid');
		$blank=ModuleDefinition::fromArray([
			'module'=>'Blank', 'directory'=>[], 'common_directory'=>' ', 'app_directory'=>null,
			'kernel_entry'=>'', 'framework_entry'=>false, 'framework_directory'=>' ', 'framework_namespace'=>[],
		]);
		$t->same('Alpha', $alpha->module());
		$t->same('Alpha', $alpha->name());
		$t->same('1.2.3', $alpha->version());
		$t->isTrue($alpha->enabled());
		$t->same('/modules/alpha', $alpha->directory());
		$t->same('/common/alpha', $alpha->commonDirectory());
		$t->same(null, $alpha->appDirectory());
		$t->isTrue($alpha->hasCommonSource());
		$t->isFalse($alpha->hasAppSource());
		$t->isTrue($alpha->isCommonOnly());
		$t->isFalse($alpha->isAppOnly());
		$t->isFalse($alpha->isHybrid());
		$t->isTrue($beta->isAppOnly());
		$t->isTrue($hybrid->isHybrid());
		$t->same('/modules/alpha/kernel.php', $alpha->kernelEntry());
		$t->same('/modules/alpha/framework.php', $alpha->frameworkEntry());
		$t->same('/modules/alpha/Framework', $alpha->frameworkDirectory());
		$t->same('Dataphyre\\Alpha', $alpha->frameworkNamespace());
		$t->isTrue($alpha->hasKernel());
		$t->isTrue($alpha->hasFramework());
		$t->isFalse($blank->hasKernel());
		$t->isFalse($blank->hasFramework());
		$alphaPayload=$alpha->toArray();
		$t->hasConsistentSerialization($alpha, $alphaPayload);

		$t->isTrue(count(Module::all())>=1);
		$t->contains('core', Module::enabled());
		$t->same([], Module::disabled());
		$t->isTrue(Module::has('core'));
		$t->isFalse(Module::has('missing'));
		$t->isTrue(Module::known('core'));
		$t->isFalse(Module::known('missing'));
		$t->isTrue(Module::enabledForApp('core'));
		$t->isFalse(Module::enabledForApp('missing'));
		$t->notEmpty(Module::metadata('core'));
		$t->same(null, Module::metadata('missing'));
		$t->instanceOf(ModuleDefinition::class, Module::definition('core'));
		$t->same(null, Module::definition('missing'));
		$t->isTrue(count(Module::definitions())>=1);
		$t->isTrue(count(Module::definitions(true))>=1);
		$t->same(0, count(Module::definitions(false)));
		$t->isTrue(Module::catalog()->count()>=1);
		$t->same(1, Module::enabledCatalog()->count());
		$t->same(0, Module::disabledCatalog()->count());
		$t->notEmpty(Module::kernelEntry('core'));
		$t->same(null, Module::kernelEntry('missing'));
		$t->notEmpty(Module::kernelVersion('core'));
		$t->same(null, Module::kernelVersion('missing'));
		$t->notEmpty(Module::frameworkEntry('core'));
		$t->same(null, Module::frameworkEntry('missing'));
		$t->notEmpty(Module::version('core'));
		$t->same(null, Module::version('missing'));
		$t->notEmpty(Module::directory('core'));
		$t->notEmpty(Module::commonDirectory('core'));
		$t->same(null, Module::appDirectory('core'));
		$t->same('Dataphyre', Module::frameworkNamespace('core'));
		$t->isTrue(Module::hasKernel('core'));
		$t->isFalse(Module::hasKernel('missing'));
		$t->isTrue(Module::hasFramework('core'));
		$t->isFalse(Module::hasFramework('missing'));
		$t->isTrue(Module::loadFramework('core'));
		$t->isFalse(Module::loadFramework('missing'));
		$t->same(['core'=>true, 'other'=>true], Module::loadFrameworkMany(['core', 'other']));

		$application=new Application('app', '/project/app');
		$applications=new ApplicationCatalog('/project', ['app'=>$application]);
		$modules=new ModuleCatalog(['alpha'=>$alpha, 'beta'=>$beta]);
		$state=new RuntimeState(true, '/project', $application, ['app'=>'/project/app'], $applications, $modules);
		$t->isTrue($state->tracingEnabled());
		$t->same('/project', $state->projectRoot());
		$t->isTrue($state->hasApplication());
		$t->same($application, $state->application());
		$t->same('app', $state->applicationId());
		$t->same(['app'=>'/project/app'], $state->applicationRoots());
		$t->same($applications, $state->applications());
		$t->same($modules, $state->modules());
		$t->same(1, $state->enabledModules()->count());
		$t->same(1, $state->disabledModules()->count());
		$summary=$state->summary();
		$t->same($summary, $state->summary());
		$t->same(2, $summary['module_count']);
		$t->same($state->toArray(), $state->jsonSerialize());
		$empty=new RuntimeState(false, null, null, [], new ApplicationCatalog(), new ModuleCatalog());
		$t->isFalse($empty->hasApplication());
		$t->same(null, $empty->applicationId());
		$t->same(null, $empty->toArray()['application']);
	})->tag('core', 'values', 'deep-coverage')->group('framework-coverage');

	test('core app facade covers absent discovery catalogs bootstrap options and framework loading bridges', static function(Context $t): void {
		$t->same(null, App::current('', 'missing'));
		$t->same(null, App::find('missing', ''));
		$t->isFalse(App::has('missing', ''));
		$t->same([], App::available(''));
		$t->instanceOf(ApplicationCatalog::class, App::catalog(''));
		$t->same(0, App::discoverMany(['missing'], '')->count());
		$t->same([], App::roots(''));
		$t->same(null, App::bootstrap('', 'missing'));
		$t->same(null, App::id());
		$t->same(null, App::root());
		$t->same('fallback', App::option('missing', 'fallback'));
		$t->isTrue(App::loadFrameworkModule('core'));
		$t->isFalse(App::loadFrameworkModule('missing'));
		$t->same(['core'=>true, 'other'=>true], App::loadFrameworkModules(['core', 'other']));
	})->tag('core', 'values', 'deep-coverage')->group('framework-coverage');

	test('core bootstrap catalog partitions plans and exposes deterministic collection serialization', static function(Context $t): void {
		$workspace=$t->workspace('core-bootstrap-catalog');
		$root=$workspace->root();
		$compiled=$workspace->file('compiled.php','<?php return [];');
		$bootableApplication=new Application('alpha', $root, null, null, $compiled);
		$unbootableApplication=new Application('beta', $root);
		$bootablePlan=new BootstrapPlan($root, $bootableApplication);
		$unbootablePlan=new BootstrapPlan($root, $unbootableApplication);
		$catalog=new BootstrapCatalog($root, [
			'beta'=>$unbootablePlan,
			0=>$bootablePlan,
			'invalid'=>'ignored',
		]);
		$t->same($root, $catalog->projectRoot());
		$t->same(['alpha', 'beta'], $catalog->names());
		$t->same([$bootablePlan, $unbootablePlan], $catalog->all());
		$t->same($bootablePlan, $catalog->first());
		$t->same($bootablePlan, $catalog->get(' alpha '));
		$t->same(null, $catalog->get(''));
		$t->isTrue($catalog->has('beta'));
		$t->isFalse($catalog->has('missing'));
		$t->same(1, $catalog->bootable()->count());
		$t->same(1, $catalog->unbootable()->count());
		$t->same(['alpha'], $catalog->bootableNames());
		$t->same(['beta'], $catalog->unbootableNames());
		$t->same(['alpha'], $catalog->bootableNames());
		$t->same(2, $catalog->count());
		$t->same([$bootablePlan, $unbootablePlan], iterator_to_array($catalog));
		$payload=$catalog->toArray();
		$t->hasConsistentSerialization($catalog, $payload);
		$t->same(null, (new BootstrapCatalog())->first());

		$t->instanceOf(BootstrapPlan::class, Bootstrap::for($bootableApplication, $root));
		$t->same(null, Bootstrap::for('missing', ''));
		$t->same(null, Bootstrap::current('', 'missing'));
		$t->same(null, Bootstrap::resolve('missing', ''));
		$t->instanceOf(BootstrapCatalog::class, Bootstrap::catalog(''));
		$t->throws(static fn()=>Bootstrap::boot('missing', ''), RuntimeException::class);
	})->tag('core', 'values', 'deep-coverage')->group('framework-coverage');

	test('core dialback event and facade cover callback shapes prefix filtering registry mutation and catalogs', static function(Context $t): void {
		\dataphyre\core::$dialbacks=[];
		$target=new DpCoreDialbackTarget();
		$invoker=new DpCoreDialbackInvoker();
		$closure=static fn(string $value='closure'): string=>'closure:'.$value;
		$event=DialbackEvent::fromCallbacks(' demo.event ', [
			'dp_core_dialback_function',
			$closure,
			[$target, 'instance'],
			[DpCoreDialbackTarget::class, 'staticMethod'],
			$invoker,
			'invalid-callback',
		]);
		$t->same('demo.event', $event->name());
		$t->same(5, $event->callbackCount());
		$t->same(5, count($event->callbacks()));
		$t->isTrue($event->hasCallbacks());
		$t->isFalse($event->isEmpty());
		$t->isTrue($event->matchesPrefix(null));
		$t->isTrue($event->matchesPrefix('  '));
		$t->isTrue($event->matchesPrefix(' demo. '));
		$t->isFalse($event->matchesPrefix('other'));
		$descriptions=$event->callbackDescriptions();
		$t->same(['function', 'closure', 'instance_method', 'static_method', 'invokable'], array_column($descriptions, 'type'));
		$t->same('dp_core_dialback_function', $descriptions[0]['label']);
		$t->contains('DpCoreDialbackTarget::instance', $descriptions[2]['label']);
		$t->same($event->toArray(), $event->jsonSerialize());
		$empty=DialbackEvent::fromCallbacks('empty', ['missing-function']);
		$t->isTrue($empty->isEmpty());
		$t->isFalse($empty->hasCallbacks());

		$t->isTrue($event->register(static fn(string $value): string=>'registered:'.$value));
		$t->same('registered:one', $event->fire('one'));
		$t->isTrue(Dialback::register('demo.second', static fn(string $value): string=>'second:'.$value));
		$t->isTrue(Dialback::has('demo.second'));
		$t->isFalse(Dialback::has('missing'));
		$t->same('second:two', Dialback::fire('demo.second', 'two'));
		$t->same(1, count(Dialback::callbacks('demo.second')));
		$t->same(['demo.event', 'demo.second'], Dialback::names('demo.'));
		$t->same(2, Dialback::count('demo.'));
		$t->same(2, Dialback::callbackCount('demo.'));
		$t->same('demo.second', Dialback::event('demo.second')->name());
		$t->same(['demo.second'], Dialback::events([' ', ' demo.second '])->names());
		$t->same(2, Dialback::catalog()->count());
		$t->same(2, Dialback::catalog('demo.')->count());
	})->tag('core', 'values', 'deep-coverage')->group('framework-coverage');
}
