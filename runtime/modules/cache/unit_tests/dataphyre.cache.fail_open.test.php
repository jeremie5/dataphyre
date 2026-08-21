<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\NonPublicAccess;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/cache.main.php';

if(!defined('ROOTPATH')){
	$dataphyreCacheTestRoot=dirname(__DIR__, 4);
	define('ROOTPATH', [
		'root'=>$dataphyreCacheTestRoot,
		'common_root'=>$dataphyreCacheTestRoot,
		'common_dataphyre'=>$dataphyreCacheTestRoot.'/',
		'common_dataphyre_runtime'=>$dataphyreCacheTestRoot.'/runtime/',
		'dataphyre'=>$dataphyreCacheTestRoot.'/cache/unit-test-application/dataphyre/',
	]);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', ['enabled'=>['cache'=>true], 'disabled'=>[]]);
}
require_once dirname(__DIR__, 2).'/core/kernel/module_registry.php';

class DpCacheUnavailableBackend {
	public function get(string $key): bool { return false; }
	public function set(string $key, mixed $value, int $expiration=0): bool { return false; }
	public function delete(string $key): bool { return false; }
	public function flush(): bool { return false; }
	public function increment(string $key, int $offset=1): bool { return false; }
	public function decrement(string $key, int $offset=1): bool { return false; }
	public function add(string $key, mixed $value, int $expiration=0): bool { return false; }
	public function getResultCode(): int { return 47; }
}

final class DpCacheThrowingBackend extends DpCacheUnavailableBackend {
	public function get(string $key): bool {
		throw new RuntimeException('simulated backend outage');
	}
}

final class DpCacheHealthyBackend {
	/** @var array<string,mixed> */
	private array $values=[];
	/** @var array<string,int> */
	public array $expirations=[];
	private int $resultCode=0;

	public function get(string $key): mixed {
		if(!array_key_exists($key, $this->values)){
			$this->resultCode=16;
			return false;
		}
		$this->resultCode=0;
		return $this->values[$key];
	}

	public function set(string $key, mixed $value, int $expiration=0): bool {
		$this->values[$key]=$value;
		$this->expirations[$key]=$expiration;
		$this->resultCode=0;
		return true;
	}

	public function delete(string $key): bool {
		if(!array_key_exists($key, $this->values)){
			$this->resultCode=16;
			return false;
		}
		unset($this->values[$key], $this->expirations[$key]);
		$this->resultCode=0;
		return true;
	}

	public function flush(): bool {
		$this->values=[];
		$this->expirations=[];
		$this->resultCode=0;
		return true;
	}

	public function increment(string $key, int $offset=1): int|false {
		if(!array_key_exists($key, $this->values)){
			$this->resultCode=16;
			return false;
		}
		$this->resultCode=0;
		return $this->values[$key]=(int)$this->values[$key]+$offset;
	}

	public function decrement(string $key, int $offset=1): int|false {
		if(!array_key_exists($key, $this->values)){
			$this->resultCode=16;
			return false;
		}
		$this->resultCode=0;
		return $this->values[$key]=max(0, (int)$this->values[$key]-$offset);
	}

	public function add(string $key, mixed $value, int $expiration=0): bool {
		if(array_key_exists($key, $this->values)){
			$this->resultCode=12;
			return false;
		}
		$this->values[$key]=$value;
		$this->expirations[$key]=$expiration;
		$this->resultCode=0;
		return true;
	}

	public function getResultCode(): int {
		return $this->resultCode;
	}
}

final class DpCacheRacingCounterBackend {
	private bool $firstIncrement=true;
	private int $value=7;
	private int $resultCode=0;

	public function increment(string $key, int $offset=1): int|false {
		if($this->firstIncrement){
			$this->firstIncrement=false;
			$this->resultCode=16;
			return false;
		}
		$this->resultCode=0;
		return $this->value+=$offset;
	}

	public function add(string $key, mixed $value, int $expiration=0): bool {
		$this->resultCode=14;
		return false;
	}

	public function getResultCode(): int {
		return $this->resultCode;
	}
}

/** @param object|null $backend */
function dp_cache_test_reset(Context $t, ?object $backend=null, bool $memoryFallback=true, bool $started=true): NonPublicAccess {
	$access=$t->nonPublic(\dataphyre\cache::class);
	foreach([
		'memcached'=>$backend,
		'memory_cache'=>[],
		'memory_fallback'=>$memoryFallback,
		'started'=>$started,
	] as $property=>$value){
		$access->writeProperty($property, $value);
	}
	return $access;
}

/** @return array{0:string,1:int} */
function dp_cache_test_server_address(Context $t): array {
	return $t->nonPublic(\dataphyre\cache::class)->invoke('server_address');
}

test('cache resolves through the configured module registry', static function(Context $t): void {
	$definition=\dataphyre\module_registry::module_definition('cache');
	$t->type('array', $definition);
	$t->isTrue($definition['enabled'] ?? false);
	$t->same('2.0', $definition['version'] ?? null);
	$t->same(
		realpath(dirname(__DIR__).'/kernel/cache.main.php'),
		realpath((string)($definition['kernel_entry'] ?? '')),
	);
})->tag('cache', 'module-registry', 'compatibility')->group('framework-coverage');

test('cache identifies a healthy shared backend and preserves SQL expiration semantics', static function(Context $t): void {
	$backend=new DpCacheHealthyBackend();
	$access=dp_cache_test_reset($t, $backend, false, true);
	$absoluteExpiration=time()+120;
	$t->isTrue(\dataphyre\cache::isShared());
	$t->isTrue(\dataphyre\cache::set('cache:test:shared', ['backend'=>'memcached'], $absoluteExpiration));
	$t->same(['backend'=>'memcached'], \dataphyre\cache::get('cache:test:shared'));
	$t->same($absoluteExpiration, $backend->expirations['cache:test:shared'] ?? null);
	$t->same(3, \dataphyre\cache::increment('cache:test:new-counter', 3));
	$t->same(1, \dataphyre\cache::decrement('cache:test:new-counter', 2));
	$t->same(4, \dataphyre\cache::incrementShared('cache:test:shared-counter', 4, 90));
	$t->same(90, $backend->expirations['cache:test:shared-counter'] ?? null);
	$t->same(6, \dataphyre\cache::incrementShared('cache:test:shared-counter', 2, 300));
	$t->same(90, $backend->expirations['cache:test:shared-counter'] ?? null);
	$t->isTrue(\dataphyre\cache::delete('cache:test:shared'));
	$t->isNull(\dataphyre\cache::get('cache:test:shared'));
	$t->isFalse($access->readProperty('memory_fallback'));
})->tag('cache', 'memcached', 'sql', 'compatibility')->group('framework-coverage');

test('cache shared counters preserve every increment when creators race', static function(Context $t): void {
	$access=dp_cache_test_reset($t, new DpCacheRacingCounterBackend(), false, true);
	$t->same(10, \dataphyre\cache::incrementShared('cache:test:racing-counter', 3, 60));
	$t->isFalse($access->readProperty('memory_fallback'));
})->tag('cache', 'memcached', 'counter', 'concurrency')->group('framework-coverage');

test('cache resolves bounded container endpoints without exposing credentials', static function(Context $t): void {
	$names=[
		'DATAPHYRE_CACHE_MEMCACHED_HOST',
		'DATAPHYRE_CACHE_MEMCACHED_PORT',
		'MEMCACHED_HOST',
		'MEMCACHED_PORT',
	];
	$original=[];
	foreach($names as $name){
		$original[$name]=getenv($name);
		putenv($name);
	}
	try{
		putenv('MEMCACHED_HOST=platform-cache');
		putenv('MEMCACHED_PORT=11212');
		$t->same(['platform-cache',11212], dp_cache_test_server_address($t));

		putenv('DATAPHYRE_CACHE_MEMCACHED_HOST=dataphyre-cache.internal');
		putenv('DATAPHYRE_CACHE_MEMCACHED_PORT=22122');
		$t->same(['dataphyre-cache.internal',22122], dp_cache_test_server_address($t));

		putenv('DATAPHYRE_CACHE_MEMCACHED_HOST=invalid cache host');
		putenv('DATAPHYRE_CACHE_MEMCACHED_PORT=70000');
		$t->same(['127.0.0.1',11211], dp_cache_test_server_address($t));
	}finally{
		foreach($original as $name=>$value){
			putenv($value===false ? $name : $name.'='.$value);
		}
	}
})->tag('cache', 'memcached', 'configuration', 'containers')->group('framework-coverage');

test('cache memory fallback supports the complete facade contract without claiming shared state', static function(Context $t): void {
	dp_cache_test_reset($t);
	$t->isFalse(\dataphyre\cache::isShared());
	$t->isTrue(\dataphyre\cache::set('cache:test:payload', ['id'=>42]));
	$t->same(['id'=>42], \dataphyre\cache::get('cache:test:payload'));
	$t->isTrue(\dataphyre\cache::set('cache:test:false', false));
	$t->same(false, \dataphyre\cache::get('cache:test:false'));
	$t->isTrue(\dataphyre\cache::delete('cache:test:payload'));
	$t->isNull(\dataphyre\cache::get('cache:test:payload'));
	$t->isTrue(\dataphyre\cache::flush());
	$t->isNull(\dataphyre\cache::get('cache:test:false'));
	$oversizedKey=str_repeat('cache-key-', 40);
	$t->isTrue(\dataphyre\cache::set($oversizedKey, 'bounded'));
	$t->same('bounded', \dataphyre\cache::get($oversizedKey));
})->tag('cache', 'fallback')->group('framework-coverage');

test('cache memory fallback preserves counter and expiration semantics', static function(Context $t): void {
	$access=dp_cache_test_reset($t);
	$t->same(3, \dataphyre\cache::increment('cache:test:counter', 3));
	$t->same(1, \dataphyre\cache::decrement('cache:test:counter', 2));
	$t->same(0, \dataphyre\cache::decrement('cache:test:counter', 5));
	$t->isFalse(\dataphyre\cache::incrementShared('cache:test:local-policy-counter', 1, 60));
	\dataphyre\cache::set('cache:test:expired', 'stale', 10);
	$entries=$access->readProperty('memory_cache');
	$entries['cache:test:expired']['expires']=time()-1;
	$access->writeProperty('memory_cache', $entries);
	$t->isNull(\dataphyre\cache::get('cache:test:expired'));
	$t->isFalse(array_key_exists('cache:test:expired', $access->readProperty('memory_cache')));
})->tag('cache', 'fallback', 'expiration')->group('framework-coverage');

test('cache backend failures degrade to memory and stop claiming shared state', static function(Context $t): void {
	$access=dp_cache_test_reset($t, new DpCacheUnavailableBackend(), false, true);
	$t->isTrue(\dataphyre\cache::isShared());
	$t->isTrue(\dataphyre\cache::set('cache:test:degraded', 'available'));
	$t->same('available', \dataphyre\cache::get('cache:test:degraded'));
	$t->isFalse(\dataphyre\cache::isShared());
	$t->isTrue($access->readProperty('memory_fallback'));
	$t->isTrue(\dataphyre\cache::$started);

	dp_cache_test_reset($t, new DpCacheThrowingBackend(), false, true);
	$t->isNull(\dataphyre\cache::get('cache:test:throws'));
	$t->isFalse(\dataphyre\cache::isShared());

	$access=dp_cache_test_reset($t, new DpCacheUnavailableBackend(), false, true);
	$t->isFalse(\dataphyre\cache::incrementShared('cache:test:strict-counter', 1, 60));
	$t->isFalse(\dataphyre\cache::isShared());
	$t->same([], $access->readProperty('memory_cache'));
})->tag('cache', 'fallback', 'availability')->group('framework-coverage');

test('cache cold start fails open when the Memcached extension is absent', static function(Context $t): void {
	dp_cache_test_reset($t, null, false, false);
	$t->isFalse(\dataphyre\cache::isShared());
	$t->isTrue(\dataphyre\cache::set('cache:test:cold-start', 'available'));
	$t->same('available', \dataphyre\cache::get('cache:test:cold-start'));
	$t->isTrue(\dataphyre\cache::$started);
})
	->skipIf(class_exists('\\Memcached', false), 'The Memcached extension is installed in this test worker.')
	->tag('cache', 'fallback', 'availability')
	->group('framework-coverage');
