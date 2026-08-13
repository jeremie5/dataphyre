<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Application;
use Dataphyre\ApplicationCatalog;
use Dataphyre\Config;
use Dataphyre\ConfigRepository;
use Dataphyre\ConfigSnapshot;
use Dataphyre\Env;
use Dataphyre\EnvRepository;
use Dataphyre\EnvSnapshot;
use Dataphyre\Url;
use Dataphyre\UrlValue;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\core', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static array $config=[];
	public static function get_config(string $key): mixed {
		if(array_key_exists($key, self::$config)){
			return self::$config[$key];
		}
		$current=self::$config;
		foreach(array_values(array_filter(explode('/', trim($key, '/')), static fn(string $part): bool=>$part!=='')) as $part){
			if(!is_array($current) || !array_key_exists($part, $current)){
				return null;
			}
			$current=$current[$part];
		}
		return $current;
	}
	public static function add_config(array|string $config, mixed $value=null): bool {
		if(is_string($config)){
			self::$config[$config]=$value;
			return true;
		}
		self::$config=array_replace_recursive(self::$config, $config);
		return true;
	}
	public static function config_all(): array {
		return self::$config;
	}
	public static function url_self(bool $full=false): string {
		return $full ? 'https://example.test/current?one=1' : 'https://example.test/base';
	}
	public static function url_updated_querystring(string $url, ?array $value=null, array|null|bool $remove=false): string {
		return $url.'?'.http_build_query($value ?? []);
	}
	public static function url_self_updated_querystring(?array $value=null, array|null|bool $remove=false): string {
		return 'https://example.test/current?'.http_build_query($value ?? []);
	}
}
PHP);
}

$dp_core_values_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core';
require_once $dp_core_values_root.'/kernel/application_definition.php';
require_once $dp_core_values_root.'/Framework/Application.php';
require_once $dp_core_values_root.'/Framework/ApplicationCatalog.php';
require_once $dp_core_values_root.'/Framework/ConfigRepository.php';
require_once $dp_core_values_root.'/Framework/ConfigSnapshot.php';
require_once $dp_core_values_root.'/Framework/Config.php';
require_once $dp_core_values_root.'/Framework/EnvRepository.php';
require_once $dp_core_values_root.'/Framework/EnvSnapshot.php';
require_once $dp_core_values_root.'/Framework/Env.php';
require_once $dp_core_values_root.'/Framework/UrlValue.php';
require_once $dp_core_values_root.'/Framework/Url.php';

test('application catalog filters keys sorts entries iterates and caches serialization', static function(Context $t): void {
	$alpha=new Application('alpha', '/apps/alpha');
	$zulu=new Application('zulu', '/apps/zulu');
	$catalog=new ApplicationCatalog('/project', [
		0=>$zulu,
		' alpha-alias '=>$alpha,
		'invalid'=>'not-an-application',
	]);
	$t->same('/project', $catalog->projectRoot());
	$t->same(['alpha-alias', 'zulu'], $catalog->names());
	$t->same([$alpha, $zulu], $catalog->all());
	$t->same($alpha, $catalog->first());
	$t->same($alpha, $catalog->get(' alpha-alias '));
	$t->same(null, $catalog->get(''));
	$t->same(null, $catalog->get('missing'));
	$t->isTrue($catalog->has('zulu'));
	$t->isFalse($catalog->has('missing'));
	$t->same(2, count($catalog));
	$t->same([$alpha, $zulu], iterator_to_array($catalog->getIterator()));
	$payload=$catalog->toArray();
	$t->same('/project', $payload['project_root']);
	$t->same(2, count($payload['entries']));
	$t->hasConsistentSerialization($catalog, $payload);
	$t->same(null, (new ApplicationCatalog())->first());
})->tag('core', 'coverage')->group('framework-coverage');

test('environment facade covers mutation selection scoping snapshots and prefix normalization', static function(Context $t): void {
	Env::forget(Env::keys());
	Env::set(['APP/NAME'=>'Dataphyre', 'APP/NULL'=>null, 'OTHER'=>'value']);
	Env::set('APP/DEBUG', true);
	Env::merge([7=>'numeric-key', 'APP/PORT'=>8080]);
	$t->same('Dataphyre', Env::get('APP/NAME'));
	$t->same('fallback', Env::get('MISSING', 'fallback'));
	$t->isTrue(Env::has('APP/NULL'));
	$t->same(['APP/NAME'=>'Dataphyre', 'APP/NULL'=>null], Env::only(['APP/NAME', 'MISSING', 'APP/NULL']));
	$t->isFalse(isset(Env::except(['OTHER'])['OTHER']));
	$t->contains('APP/DEBUG', Env::keys());
	$t->same(8080, Env::pull('APP/PORT'));
	$t->isFalse(Env::has('APP/PORT'));
	Env::forget('OTHER');
	$t->isFalse(Env::has('OTHER'));
	Env::forget(['7', 'MISSING']);
	$t->instanceOf(EnvRepository::class, Env::repository(null));
	$t->instanceOf(EnvRepository::class, Env::repository(' /APP/ '));
	$t->instanceOf(EnvRepository::class, Env::scope('APP'));
	$t->instanceOf(EnvSnapshot::class, Env::snapshot('APP'));
	$t->same('Dataphyre', Env::snapshot('APP')->get('NAME'));
})->tag('core', 'coverage')->group('framework-coverage');

test('configuration facade covers nested writes reads scopes selection removal and key lookup', static function(Context $t): void {
	\dataphyre\core::$config=[
		'app'=>[
			'name'=>'Dataphyre',
			'debug'=>false,
			'nested'=>['value'=>7],
			'scalar'=>'text',
		],
		'literal/key'=>'literal',
		'nullable'=>null,
	];
	$t->same('Dataphyre', Config::get('app/name'));
	$t->same('fallback', Config::get('nullable', 'fallback'));
	$t->same('fallback', Config::get('missing', 'fallback'));
	$t->isFalse(Config::has('  '));
	$t->isTrue(Config::has('literal/key'));
	$t->isTrue(Config::has('app/nested/value'));
	$t->isFalse(Config::has('app/missing'));
	$t->isTrue(Config::set('app/port', 8080));
	$t->isTrue(Config::set('single', 'value'));
	$t->isTrue(Config::set(['app'=>['locale'=>'en-CA']]));
	$t->isTrue(Config::merge(['feature'=>['enabled'=>true]]));
	$t->same(8080, Config::get('app/port'));
	$t->same('value', Config::get('single'));
	$t->same('en-CA', Config::all()['app']['locale']);
	$t->instanceOf(ConfigRepository::class, Config::repository(null));
	$t->instanceOf(ConfigRepository::class, Config::repository(' /app/ '));
	$t->instanceOf(ConfigRepository::class, Config::scope('app'));
	$t->instanceOf(ConfigSnapshot::class, Config::snapshot('app'));
	$t->same([
		'literal/key'=>'literal',
		'app/nested/value'=>7,
		'nullable'=>null,
	], Config::only(['literal/key', 'app/nested/value', '', 'missing', 'nullable']));
	$except=Config::except(['app/nested/value', '', 'missing', 'app/scalar/child']);
	$t->isFalse(isset($except['app']['nested']['value']));
	$t->same('text', $except['app']['scalar']);
	$t->contains('app', Config::keys());
	$t->contains('app', Config::keys(''));
	$t->contains('name', Config::keys('app'));
	$t->same([], Config::keys('missing'));
	$t->same([], Config::keys('app/name'));
	$t->instanceOf(ConfigRepository::class, Config::repository('  '));
})->tag('core', 'coverage')->group('framework-coverage');

test('environment snapshot covers access selection empty and nested scope composition', static function(Context $t): void {
	$snapshot=new EnvSnapshot(null, '/', [
		'APP/DB/HOST'=>'localhost',
		'APP/DB/PORT'=>3306,
		'APP/NULL'=>null,
		'OTHER'=>'value',
		7=>'numeric',
	]);
	$t->same(null, $snapshot->prefix());
	$t->same('/', $snapshot->separator());
	$t->same('localhost', $snapshot->get('APP/DB/HOST'));
	$t->same('fallback', $snapshot->get('', 'fallback'));
	$t->same('fallback', $snapshot->get('missing', 'fallback'));
	$t->isTrue($snapshot->has());
	$t->isTrue($snapshot->has('APP/NULL'));
	$t->same(['OTHER'=>'value', 'APP/NULL'=>null], $snapshot->only([' OTHER ', '', 'missing', 'APP/NULL']));
	$t->isFalse(isset($snapshot->except([' OTHER '])['OTHER']));
	$t->contains('APP/DB/HOST', $snapshot->keys());
	$t->isFalse($snapshot->isEmpty());
	$t->same($snapshot, $snapshot->scope(null));
	$t->same($snapshot, $snapshot->scope('  '));
	$app=$snapshot->scope('/APP/');
	$t->same('APP', $app->prefix());
	$t->same(['DB/HOST'=>'localhost', 'DB/PORT'=>3306, 'NULL'=>null], $app->all());
	$db=$app->scope('DB');
	$t->same('APP/DB', $db->prefix());
	$t->same(['HOST'=>'localhost', 'PORT'=>3306], $db->all());
	$payload=$db->toArray();
	$t->hasConsistentSerialization($db, $payload);
	$empty=new EnvSnapshot(null);
	$t->isTrue($empty->isEmpty());
	$t->isFalse($empty->has());
})->tag('core', 'coverage')->group('framework-coverage');

test('URL facade delegates base current full query and value object surfaces', static function(Context $t): void {
	$t->same('https://example.test/base', Url::base());
	$t->instanceOf(UrlValue::class, Url::baseValue());
	$t->same('https://example.test/base', Url::current());
	$t->instanceOf(UrlValue::class, Url::currentValue(true));
	$t->same('https://example.test/current?one=1', Url::full());
	$t->instanceOf(UrlValue::class, Url::fullValue());
	$t->same('https://example.test/path?a=1', Url::withQuery('https://example.test/path', ['a'=>1]));
	$t->same('https://example.test/current?a=1', Url::currentWithQuery(['a'=>1]));
	$t->same('https://explicit.test', (string)Url::value('https://explicit.test'));
})->tag('core', 'coverage')->group('framework-coverage');
