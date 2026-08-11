<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
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
if(!defined('APP_MODULES')){
	define('APP_MODULES', ['enabled'=>['cache']]);
}
require_once dirname(__DIR__, 2).'/core/kernel/module_registry.php';

class DpCacheUnavailableBackend {
	public function get(string $key): bool { return false; }
	public function set(string $key, mixed $value, int $expiration=0): bool { return false; }
	public function delete(string $key): bool { return false; }
	public function flush(): bool { return false; }
	public function increment(string $key, int $offset=1): bool { return false; }
	public function decrement(string $key, int $offset=1): bool { return false; }
	public function add(string $key, mixed $value): bool { return false; }
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
		$this->resultCode=0;
		return true;
	}

	public function delete(string $key): bool {
		if(!array_key_exists($key, $this->values)){
			$this->resultCode=16;
			return false;
		}
		unset($this->values[$key]);
		$this->resultCode=0;
		return true;
	}

	public function flush(): bool {
		$this->values=[];
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

	public function add(string $key, mixed $value): bool {
		if(array_key_exists($key, $this->values)){
			$this->resultCode=12;
			return false;
		}
		$this->values[$key]=$value;
		$this->resultCode=0;
		return true;
	}

	public function getResultCode(): int {
		return $this->resultCode;
	}
}

/** @param object|null $backend */
function dp_cache_test_reset(?object $backend=null, bool $memoryFallback=true, bool $started=true): ReflectionClass {
	$reflection=new ReflectionClass(\dataphyre\cache::class);
	foreach([
		'memcached'=>$backend,
		'memory_cache'=>[],
		'memory_fallback'=>$memoryFallback,
		'started'=>$started,
	] as $property=>$value){
		$reflected=$reflection->getProperty($property);
		$reflected->setAccessible(true);
		$reflected->setValue(null, $value);
	}
	return $reflection;
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

test('cache keeps a healthy shared backend selected', static function(Context $t): void {
	$reflection=dp_cache_test_reset(new DpCacheHealthyBackend(), false, true);
	$t->isTrue(\dataphyre\cache::set('cache:test:shared', ['backend'=>'memcached']));
	$t->same(['backend'=>'memcached'], \dataphyre\cache::get('cache:test:shared'));
	$t->same(3, \dataphyre\cache::increment('cache:test:new-counter', 3));
	$t->same(1, \dataphyre\cache::decrement('cache:test:new-counter', 2));
	$t->isTrue(\dataphyre\cache::delete('cache:test:shared'));
	$t->isNull(\dataphyre\cache::get('cache:test:shared'));
	$fallback=$reflection->getProperty('memory_fallback');
	$fallback->setAccessible(true);
	$t->isFalse($fallback->getValue());
})->tag('cache', 'memcached', 'compatibility')->group('framework-coverage');

test('cache memory fallback supports the complete facade contract', static function(Context $t): void {
	dp_cache_test_reset();
	$t->isTrue(\dataphyre\cache::set('cache:test:payload', ['id'=>42]));
	$t->same(['id'=>42], \dataphyre\cache::get('cache:test:payload'));
	$t->isTrue(\dataphyre\cache::set('cache:test:false', false));
	$t->same(false, \dataphyre\cache::get('cache:test:false'));
	$t->isTrue(\dataphyre\cache::delete('cache:test:payload'));
	$t->isNull(\dataphyre\cache::get('cache:test:payload'));
	$t->isTrue(\dataphyre\cache::flush());
	$t->isNull(\dataphyre\cache::get('cache:test:false'));
})->tag('cache', 'fallback')->group('framework-coverage');

test('cache memory fallback preserves counter and expiration semantics', static function(Context $t): void {
	$reflection=dp_cache_test_reset();
	$t->same(3, \dataphyre\cache::increment('cache:test:counter', 3));
	$t->same(1, \dataphyre\cache::decrement('cache:test:counter', 2));
	$t->same(0, \dataphyre\cache::decrement('cache:test:counter', 5));
	\dataphyre\cache::set('cache:test:expired', 'stale', 10);
	$memory=$reflection->getProperty('memory_cache');
	$memory->setAccessible(true);
	$entries=$memory->getValue();
	$entries['cache:test:expired']['expires']=time()-1;
	$memory->setValue(null, $entries);
	$t->isNull(\dataphyre\cache::get('cache:test:expired'));
	$t->isFalse(array_key_exists('cache:test:expired', $memory->getValue()));
})->tag('cache', 'fallback', 'expiration')->group('framework-coverage');

test('cache backend failures degrade to memory without surfacing availability errors', static function(Context $t): void {
	$reflection=dp_cache_test_reset(new DpCacheUnavailableBackend(), false, true);
	$t->isTrue(\dataphyre\cache::set('cache:test:degraded', 'available'));
	$t->same('available', \dataphyre\cache::get('cache:test:degraded'));
	$fallback=$reflection->getProperty('memory_fallback');
	$fallback->setAccessible(true);
	$t->isTrue($fallback->getValue());
	$t->isTrue(\dataphyre\cache::$started);

	dp_cache_test_reset(new DpCacheThrowingBackend(), false, true);
	$t->isNull(\dataphyre\cache::get('cache:test:throws'));
})->tag('cache', 'fallback', 'availability')->group('framework-coverage');

test('cache cold start fails open when the Memcached extension is absent', static function(Context $t): void {
	dp_cache_test_reset(null, false, false);
	$t->isTrue(\dataphyre\cache::set('cache:test:cold-start', 'available'));
	$t->same('available', \dataphyre\cache::get('cache:test:cold-start'));
	$t->isTrue(\dataphyre\cache::$started);
})
	->skipIf(class_exists('\\Memcached', false), 'The Memcached extension is installed in this test worker.')
	->tag('cache', 'fallback', 'availability')
	->group('framework-coverage');
