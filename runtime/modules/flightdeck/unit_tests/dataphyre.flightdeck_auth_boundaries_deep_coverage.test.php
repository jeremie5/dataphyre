<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Flightdeck authentication boundaries')
	->tag('flightdeck','authentication','bootstrap','coverage')
	->group('framework-coverage')
	->contract('flightdeck.auth.bootstrap-boundaries',1)
	->layer('integration')
	->risk('critical')
	->watches('module:flightdeck','path:runtime/modules/flightdeck/kernel/auth.php')
	->through('configuration precedence','rate-limit persistence','legacy secrets','redirect emission')
	->isolation('process');

$flightdeckAuthFile=dirname(__DIR__).'/kernel/auth.php';
require_once $flightdeckAuthFile;

test('authentication configuration accepts a complete bootstrap constant and repeated include',static function(Context $t)use($flightdeckAuthFile): void {
	if(!defined('DATAPHYRE_FLIGHTDECK_CONFIG')){
		define('DATAPHYRE_FLIGHTDECK_CONFIG',[
			'enabled'=>true,
			'password'=>'constant secret',
			'debugbar'=>['enabled'=>false],
		]);
	}
	$config=dataphyre_flightdeck_auth::config();
	$t->same('constant secret',$config['password']);
	$t->isFalse($config['debugbar']['enabled']);
	include $flightdeckAuthFile;
	$t->isTrue(class_exists(dataphyre_flightdeck_auth::class,false));
});

test('authentication defaults explain malformed bootstrap and debugbar configuration',static function(Context $t): void {
	$t->global('dataphyre_flightdeck_config')->replace(null);
	$t->global('dataphyre_bootstrap_config')->replace('malformed');
	$t->same(43200,dataphyre_flightdeck_auth::config()['session_ttl']);
	$t->global('dataphyre_bootstrap_config')->replace(['flightdeck'=>'malformed']);
	$t->same(5,dataphyre_flightdeck_auth::config()['rate_limit']['max_attempts']);

	$t->global('dataphyre_flightdeck_config')->replace([
		'enabled'=>true,
		'password'=>'secret',
		'debugbar'=>'malformed',
	]);
	$t->isFalse(dataphyre_flightdeck_auth::debugbar_allowed());
	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/settings']);
	$terminated=false;
	dataphyre_flightdeck_auth::redirect_to_login(static function()use(&$terminated): void {
		$terminated=true;
	});
	$t->isTrue($terminated);
	$t->same(302,http_response_code());
});

test('production always fails closed before cookie authentication',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/flightdeck_auth_production_probe.php',
		[dirname(__DIR__).'/kernel/auth.php'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'production_disabled'=>true,
		'auth_required'=>true,
		'enabled'=>false,
		'authenticated'=>false,
		'login_error'=>null,
	],$payload);
});

test('rate limiting persists malformed expired active failed and cleared states in an isolated cache',static function(Context $t): void {
	$workspace=$t->workspace('flightdeck-auth-rate-limit');
	$cache=$workspace->path('nested/cache');
	$t->environment(['DATAPHYRE_FLIGHTDECK_CACHE_DIR'=>$cache]);
	$t->globalMap('_SERVER')->replace([
		'REMOTE_ADDR'=>'203.0.113.77',
		'HTTP_USER_AGENT'=>'Flightdeck boundary browser',
		'REQUEST_URI'=>'/dataphyre/login',
	]);
	$t->globalMap('_COOKIE')->replace([]);
	$t->global('dataphyre_flightdeck_config')->replace([
		'enabled'=>true,
		'password'=>'correct password',
		'rate_limit'=>['window'=>30,'max_attempts'=>2],
	]);
	$auth=$t->nonPublic(dataphyre_flightdeck_auth::class);
	$file=$auth->invoke('rate_limit_file');
	$t->contains($cache,$file);
	$t->contains(hash('sha256','203.0.113.77'),$file);

	$auth->invoke('write_rate_limit_state',['attempts'=>1,'until'=>time()+30]);
	$t->isTrue(is_file($file));
	$t->hasPathValues(['attempts'=>1],$auth->invoke('rate_limit_state'));
	file_put_contents($file,'{malformed');
	$t->hasPathValues(['attempts'=>0,'until'=>0],$auth->invoke('rate_limit_state'));
	file_put_contents($file,json_encode(['attempts'=>9,'until'=>time()-1],JSON_THROW_ON_ERROR));
	$t->hasPathValues(['attempts'=>0,'until'=>0],$auth->invoke('rate_limit_state'));

	$auth->invoke('record_failed_attempt');
	$t->same(1,$auth->invoke('rate_limit_state')['attempts']);
	$t->isFalse(dataphyre_flightdeck_auth::login('wrong password'));
	$t->same('Too many failed attempts. Wait before trying again.',dataphyre_flightdeck_auth::login_error());
	$t->isFalse(dataphyre_flightdeck_auth::login('correct password'));
	$auth->invoke('clear_failed_attempts');
	$t->isFalse(is_file($file));
	$t->same(null,dataphyre_flightdeck_auth::login_error());
	$t->isTrue(dataphyre_flightdeck_auth::login('correct password'));
	$t->isFalse(is_file($file));

	$t->globalMap('_SERVER')->replace(['HTTP_CF_CONNECTING_IP'=>'198.51.100.8']);
	$t->same('198.51.100.8',$auth->invoke('client_ip'));
	$t->globalMap('_SERVER')->replace(['HTTP_X_FORWARDED_FOR'=>'192.0.2.4']);
	$t->same('192.0.2.4',$auth->invoke('client_ip'));
	$t->globalMap('_SERVER')->replace([]);
	$t->same('0.0.0.0',$auth->invoke('client_ip'));
});

test('cookie compatibility derives current app bootstrap and historical project-root secrets',static function(Context $t): void {
	if(!defined('APP')){
		define('APP','orders');
	}
	if(!defined('DATAPHYRE_PROJECT_ROOT')){
		define('DATAPHYRE_PROJECT_ROOT','/srv/current-project');
	}
	if(!defined('DATAPHYRE_BOOTSTRAP_CONFIG')){
		define('DATAPHYRE_BOOTSTRAP_CONFIG',['app'=>'legacy-orders']);
	}
	$t->global('dataphyre_flightdeck_config')->replace(['enabled'=>true,'password'=>'secret']);
	$auth=$t->nonPublic(dataphyre_flightdeck_auth::class);
	$t->same('/srv/current-project',$auth->invoke('project_root'));
	$t->same(['orders','legacy-orders'],$auth->invoke('legacy_cookie_apps'));
	$secrets=$auth->invoke('cookie_secrets');
	$t->greaterThanOrEqual(4,count($secrets));
	$t->same(count($secrets),count(array_unique($secrets)));
});
