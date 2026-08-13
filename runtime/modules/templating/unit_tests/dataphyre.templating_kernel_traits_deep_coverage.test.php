<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class DpTemplatingKernelTrace {
		/** @var list<array<int,mixed>> */
		public static array $calls=[];
	}

	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {
			DpTemplatingKernelTrace::$calls[]=$arguments;
		}
	}

	if(!class_exists(web_socket_server::class, false)){
		final class web_socket_server {
			public static bool $throw=false;
			public static array $broadcasts=[];

			public static function broadcast(string $event, string $payload): void {
				if(self::$throw){
					throw new \Exception('socket unavailable');
				}
				self::$broadcasts[]=[$event, $payload];
			}
		}
	}
}

namespace dataphyre\async {
	if(!class_exists(promise::class, false)){
		final class promise {
			public mixed $value=null;
			public mixed $reason=null;

			public function __construct(callable $executor){
				$executor(
					function(mixed $value): void { $this->value=$value; },
					function(mixed $reason): void { $this->reason=$reason; }
				);
			}
		}
	}

	if(!class_exists(coroutine::class, false)){
		final class coroutine {
			public static array $intervals=[];

			public static function set_interval(callable $callback, int $milliseconds): void {
				self::$intervals[]=[$callback, $milliseconds];
				$callback();
			}
		}
	}
}

namespace dataphyre {
	$dp_templating_kernel_root=rtrim((string)(\ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/templating/kernel';
	require_once $dp_templating_kernel_root.'/debugging.php';
	require_once $dp_templating_kernel_root.'/event_system.php';
	require_once $dp_templating_kernel_root.'/form_handling.php';
	require_once $dp_templating_kernel_root.'/seo_accessibility.php';

	final class templating_kernel_trait_coverage_harness {
		use debugging;
		use event_system;
		use form_handling;
		use seo_accessibility;

		public static bool $throwRender=false;
		public static bool $is_dev_mode=true;
		public static string $cache_dir='';
		public static array $debug_logs=[];

		private static function full_render(string $template, array $data=[]): string {
			if(self::$throwRender){
				throw new \RuntimeException('render failed');
			}
			self::$debug_logs[]='rendered '.$template;
			return 'rendered:'.$template.':'.(string)($data['name'] ?? '');
		}

		public static function runDebug(string $template, array $data=[]): string { return self::debug($template, $data); }
		public static function runProfile(string $template, float $start): void { self::profile_render($template, $start); }
		public static function runDebugRender(string $template, array $data=[]): string { return self::debug_render($template, $data); }
		public static function runMetrics(): void { self::render_performance_metrics(); }
		public static function runForm(string $template, array $data=[]): string { return self::parse_form($template, $data); }
		public static function runSeo(string $template, array $data=[]): string { return self::parse_seo_tags($template, $data); }
		public static function runTrigger(string $event, mixed ...$arguments): void { self::trigger_event($event, ...$arguments); }
		public static function runWatch(string $template): void { self::enable_watch_mode($template); }
	}
}

namespace {
	use Dataphyre\Test\Context;
	use dataphyre\async\coroutine;
	use dataphyre\async\promise;
	use dataphyre\templating_kernel_trait_coverage_harness as Harness;
	use dataphyre\web_socket_server;
	use function Dataphyre\Test\test;

	test('templating debugging form and seo traits cover success failure and replacement flows', static function(Context $t): void {
		\dataphyre\DpTemplatingKernelTrace::$calls=[];
		Harness::$throwRender=false;
		$t->same('rendered:page.tpl:Ada', Harness::runDebug('page.tpl', ['name'=>'Ada']));
		Harness::$throwRender=true;
		$t->contains('Template Error: render failed', Harness::runDebug('broken.tpl'));
		Harness::$throwRender=false;
		Harness::runProfile('page.tpl', microtime(true)-0.01);
		Harness::runMetrics();
		$t->isTrue(count(\dataphyre\DpTemplatingKernelTrace::$calls)>=3);

		Harness::$cache_dir=$t->workspace('templating-kernel')->directory('nested');
		$t->same('rendered:debug.tpl:Grace', Harness::runDebugRender('debug.tpl', ['name'=>'Grace']));
		$t->contains('rendered debug.tpl', (string)file_get_contents(Harness::$cache_dir.'/debug_logs.log'));

		$form=Harness::runForm(
			'{{form "profile"}}{{field "name" type="text" required}}{{field "missing" type="email"}}{{endForm}}',
			['profile'=>['name'=>'Ada & Grace']]
		);
		$t->contains("<form name='profile'>", $form);
		$t->contains('Ada &amp; Grace', $form);
		$t->contains("name='missing'", $form);
		$t->same('plain', Harness::runForm('plain'));

		$seo=Harness::runSeo('{{seo "description"}}{{accessibleImage "/hero.png" alt="Hero image"}}', ['description'=>'Ready']);
		$t->contains("<meta name='description' content='Ready'>", $seo);
		$t->contains("<img src='/hero.png' alt='Hero image'>", $seo);
	})->tag('templating', 'kernel-traits', 'deep-coverage')->group('framework-coverage');

	test('templating event trait covers hook dispatch promises failures and watch mode', static function(Context $t): void {
		$received=[];
		Harness::register_event_hook('before_render', static function(string $template) use (&$received): void {
			$received[]=$template;
		});
		Harness::register_event_hook('unknown', static function(): void {});
		Harness::runTrigger('before_render', 'page.tpl');
		Harness::runTrigger('unknown', 'ignored');
		$t->same(['page.tpl'], $received);

		web_socket_server::$throw=false;
		web_socket_server::$broadcasts=[];
		$success=Harness::enable_event_system('updated', ['id'=>7]);
		$t->instanceOf(promise::class, $success);
		$t->same('Event broadcasted: updated', $success->value);
		$t->same('updated', web_socket_server::$broadcasts[0][0] ?? null);

		web_socket_server::$throw=true;
		$failure=Harness::enable_event_system('failed', []);
		$t->contains('socket unavailable', (string)$failure->reason);
		web_socket_server::$throw=false;

		Harness::$is_dev_mode=true;
		Harness::runWatch('watch.tpl');
		$t->same(1000, coroutine::$intervals[0][1] ?? null);
		$t->same('reload_template', web_socket_server::$broadcasts[array_key_last(web_socket_server::$broadcasts)][0] ?? null);
		$count=count(web_socket_server::$broadcasts);
		Harness::$is_dev_mode=false;
		Harness::runWatch('ignored.tpl');
		$t->same($count, count(web_socket_server::$broadcasts));
	})->tag('templating', 'kernel-traits', 'deep-coverage')->group('framework-coverage');
}
