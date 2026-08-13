<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/debugbar.php';

test('Flightdeck bootstrap authentication signs cookies and rotates CSRF tokens across compatible configurations', static function(Context $t): void {
	$auth=$t->nonPublic(dataphyre_flightdeck_auth::class);
	$config=$t->global('dataphyre_flightdeck_config');
	$server=$t->globalMap('_SERVER')->replace([
		'HTTP_USER_AGENT'=>'Dataphyre Test Browser',
		'REMOTE_ADDR'=>'198.51.100.10',
		'REQUEST_URI'=>'/dataphyre/modules?tab=tests',
		'HTTPS'=>'on',
	]);
	$cookie=$t->globalMap('_COOKIE')->replace([]);
	$config->replace([
		'enabled'=>true,
		'password'=>'correct horse battery staple',
		'session_ttl'=>600,
		'rate_limit'=>['window'=>1,'max_attempts'=>0],
		'debugbar'=>['enabled'=>true],
	]);

	$t->isFalse(dataphyre_flightdeck_auth::production_disabled());
	$t->isTrue(dataphyre_flightdeck_auth::enabled());
	$t->isTrue(dataphyre_flightdeck_auth::auth_required());
	$t->isFalse(dataphyre_flightdeck_auth::authenticated());
	$t->isFalse($auth->invoke('verify_password','wrong'));
	$t->isTrue($auth->invoke('verify_password','correct horse battery staple'));
	$t->same('correct horse battery staple',$auth->invoke('password_secret'));
	$t->same(30,$auth->invoke('rate_limit_window'));
	$t->same(1,$auth->invoke('rate_limit_max_attempts'));
	$t->same('/dataphyre/modules?tab=tests',dataphyre_flightdeck_auth::current_uri());
	$t->same('198.51.100.10',$auth->invoke('client_ip'));
	$t->isTrue($auth->invoke('secure_cookie'));
	$t->isTrue(dataphyre_flightdeck_auth::login('correct horse battery staple'));
	$t->isTrue(dataphyre_flightdeck_auth::authenticated());
	$t->isTrue(dataphyre_flightdeck_auth::debugbar_allowed());
	$t->notEmpty($cookie->get('dataphyre_flightdeck'));
	$t->same(null,dataphyre_flightdeck_auth::login_error());

	$token=dataphyre_flightdeck_auth::csrf_token();
	$t->same(64,strlen($token));
	$t->isTrue(dataphyre_flightdeck_auth::verify_csrf($token));
	$t->isFalse(dataphyre_flightdeck_auth::verify_csrf(null));
	$t->isFalse(dataphyre_flightdeck_auth::verify_csrf('wrong'));
	$t->count(4,$auth->invoke('csrf_token_candidates'));
	$t->contains('|flightdeck|',$auth->invoke('csrf_seed','2026071401'));
	$t->contains('198.51.100.10',$auth->invoke('legacy_csrf_seed','2026071401'));
	$t->same(64,strlen($auth->invoke('csrf_token_for_seed','seed')));

	$secret=$auth->invoke('cookie_secret');
	$data=$auth->invoke('base64url_encode',json_encode([
		'exp'=>time()+300,
		'ua'=>hash('sha256','Dataphyre Test Browser'),
	],JSON_UNESCAPED_SLASHES));
	$valid=$data.'.'.hash_hmac('sha256',$data,$secret);
	$t->isTrue($auth->invoke('verify_token',$valid));
	$t->isFalse($auth->invoke('verify_token','one-part'));
	$t->isFalse($auth->invoke('verify_token',$data.'.wrong'));
	$expiredData=$auth->invoke('base64url_encode',json_encode(['exp'=>time()-1,'ua'=>hash('sha256','Dataphyre Test Browser')]));
	$t->isFalse($auth->invoke('verify_token',$expiredData.'.'.hash_hmac('sha256',$expiredData,$secret)));
	$wrongAgentData=$auth->invoke('base64url_encode',json_encode(['exp'=>time()+300,'ua'=>'wrong']));
	$t->isFalse($auth->invoke('verify_token',$wrongAgentData.'.'.hash_hmac('sha256',$wrongAgentData,$secret)));
	$invalidJson=$auth->invoke('base64url_encode','not-json');
	$t->isFalse($auth->invoke('verify_token',$invalidJson.'.'.hash_hmac('sha256',$invalidJson,$secret)));
	$t->same('payload',$auth->invoke('base64url_decode',$auth->invoke('base64url_encode','payload')));
	$t->same('', $auth->invoke('base64url_decode','!'));
	$t->contains('/dataphyre/login?return=%2Forders',dataphyre_flightdeck_auth::login_url('/orders'));

	dataphyre_flightdeck_auth::logout();
	$t->isFalse($cookie->exists() && $cookie->get('dataphyre_flightdeck')!==null);
	$t->isFalse(dataphyre_flightdeck_auth::authenticated());

	$config->replace(['enabled'=>false,'password'=>'secret']);
	$t->isFalse(dataphyre_flightdeck_auth::enabled());
	$t->isFalse(dataphyre_flightdeck_auth::login('secret'));
	$config->replace(['enabled'=>true,'password'=>null,'password_hash'=>null,'developer_password'=>null,'developer_password_hash'=>null]);
	$t->isFalse(dataphyre_flightdeck_auth::auth_required());
	$t->same('Flightdeck console password is not configured.',dataphyre_flightdeck_auth::login_error());
	$config->replace(['enabled'=>true,'password_hash'=>password_hash('hashed secret',PASSWORD_DEFAULT)]);
	$t->isTrue($auth->invoke('verify_password','hashed secret'));
	$t->isFalse($auth->invoke('verify_password','wrong'));
	$t->notEmpty($auth->invoke('cookie_secrets'));
	$t->type('array',$auth->invoke('legacy_cookie_apps'));
})->tag('flightdeck','coverage','authentication')->group('framework-coverage');

test('Flightdeck support and asset probes normalize untrusted request metadata without fetching remote resources', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->globalMap('_SERVER')->replace([
		'HTTP_HOST'=>'example.test:443',
		'REQUEST_URI'=>'/orders?status=open',
		'REQUEST_METHOD'=>'POST',
		'CONTENT_TYPE'=>'application/json',
		'HTTP_X_REQUEST_ID'=>'request-1',
		'HTTP_AUTHORIZATION'=>'Bearer secret',
		'HTTPS'=>'off',
		'HTTP_X_FORWARDED_PROTO'=>'https',
	]);

	foreach(['debugbar.css','debugbar.js','debugbar-snapshot.css','debugbar-snapshot.js'] as $asset){
		$content=dataphyre_flightdeck_debugbar::asset_content($asset);
		$t->type('array',$content);
		$t->notEmpty($content['body']);
		$t->notEmpty(dataphyre_flightdeck_debugbar::asset_version($asset));
		$t->contains('/dataphyre/debugbar/assets/'.$asset,dataphyre_flightdeck_debugbar::asset_url($asset));
	}
	$t->same(null,dataphyre_flightdeck_debugbar::asset_content('../missing.bin'));
	$t->same('missing',dataphyre_flightdeck_debugbar::asset_version('../missing.bin'));
	$t->same('debugbar.css',$debugbar->invoke('asset_name','../debugbar.css'));
	$t->same('', $debugbar->invoke('asset_name','bad name.php'));
	$t->same("const ready=true;\n",$debugbar->invoke('script_body','<script>const ready=true;</script>'));
	$t->same('const ready=true;',$debugbar->invoke('script_body',' const ready=true; '));

	$severity=[E_ERROR=>'error',E_PARSE=>'error',E_WARNING=>'warning',E_NOTICE=>'info',E_DEPRECATED=>'info',123456=>'info'];
	foreach($severity as $code=>$expected){
		$t->same($expected,$debugbar->invoke('php_error_severity',$code));
	}
	$t->isTrue($debugbar->invoke('is_fatal_error',E_CORE_ERROR));
	$t->isFalse($debugbar->invoke('is_fatal_error',E_WARNING));
	foreach(['error'=>'bad','fatal'=>'bad','warning'=>'warn','deprecated'=>'','info'=>''] as $level=>$tone){
		$t->same($tone,$debugbar->invoke('level_tone',$level));
	}
	foreach(['-1'=>0,'2K'=>2048,'1.5M'=>1572864,'1G'=>1073741824,'64'=>64,''=>0] as $ini=>$bytes){
		$t->same($bytes,$debugbar->invoke('parse_ini_bytes',$ini));
	}
	$t->type('integer',$debugbar->invoke('memory_limit_bytes'));
	$t->type('integer',$debugbar->invoke('memory_remaining_bytes'));
	$t->isTrue($debugbar->invoke('has_memory_headroom',1,0));
	$t->type('boolean',$debugbar->invoke('memory_limit_is_tight'));
	$t->containsAll(['#dataphyre-flightdeck-debugbar','color-scheme'],$debugbar->invoke('toolbar_css'));
	$t->contains('dfd-snapshot',$debugbar->invoke('snapshot_css'));
	$t->contains('dfd-panel-nav',$debugbar->invoke('panel_nav_css','#scope'));
	$t->contains('dfd-ref',$debugbar->invoke('reference_css','#scope'));
	$t->same('42.25ms',$debugbar->invoke('format_ms',42.25));
	$t->same('1.5s',$debugbar->invoke('format_ms',1500.0));
	$t->same('1.5mb',$debugbar->invoke('format_bytes',1572864));
	$t->same(date('H:i:s',1250),$debugbar->invoke('client_time_label',1250.0));
	$t->same('none',$debugbar->invoke('client_time_label',0.0));
	$t->contains('"ok": true',$debugbar->invoke('json',['ok'=>true]));
	$t->same('alpha beta',$debugbar->invoke('shorten'," alpha\n beta ",20));
	$t->same('abcdefg...',$debugbar->invoke('shorten','abcdefghijklmnopqrstuvwxyz',10));

	$headers=$debugbar->invoke('request_headers');
	$t->hasPathValues(['Content-Type'=>'application/json','X-Request-Id'=>'request-1','Authorization'=>'Bearer secret'],$headers);
	$t->same('[redacted]',$debugbar->invoke('sanitize_context',$headers)['Authorization']);
	$sanitized=$debugbar->invoke('sanitize_context',[
		'password'=>'secret','nested'=>['api_token'=>'secret','count'=>2],
		'object'=>(object)['x'=>1],'callable'=>static fn(): bool=>true,
	]);
	$t->hasPathValues(['password'=>'[redacted]','nested.api_token'=>'[redacted]','nested.count'=>2],$sanitized);
	$t->contains('object',$sanitized['object']);
	$t->same('fallback',$debugbar->invoke('string_or',new stdClass(),'fallback'));
	$t->same(['a'],$debugbar->invoke('string_list',[' a ',2,'','a']));
	$t->same([], $debugbar->invoke('string_list','a'));
	$t->same('/orders',$debugbar->invoke('current_path'));
	$t->isFalse($debugbar->invoke('is_control_plane_request'));
	$t->isTrue($debugbar->invoke('is_control_plane_path','/dataphyre/debugbar/client'));
	$t->isFalse($debugbar->invoke('is_control_plane_path','/orders'));
	$t->same('&lt;tag&gt;',$debugbar->invoke('e','<tag>'));
	$t->isTrue($debugbar->invoke('secure_cookie'));

	$embedded=$debugbar->invoke('asset_probe','data:image/png;base64,AA==');
	$t->same('embedded',$embedded['status']);
	$t->same('remote',$debugbar->invoke('asset_probe','https://cdn.example.test/app.js')['status']);
	$t->same('empty',$debugbar->invoke('asset_probe','')['status']);
	$t->same('empty_path',$debugbar->invoke('asset_probe','https://example.test')['status']);
	$t->same('unsafe_path',$debugbar->invoke('asset_probe','/../secret.txt')['status']);
	$t->same('', $debugbar->invoke('asset_issue','javascript:alert(1)',[]));
	$t->same('insecure_on_https',$debugbar->invoke('asset_issue','http://example.test/app.js',['status'=>'remote']));
	$t->same('local_file_not_found',$debugbar->invoke('asset_issue','/missing.js',['status'=>'missing']));
	$t->same('', $debugbar->invoke('asset_issue','/app.js',['status'=>'found','mime'=>'text/html','expected_mime'=>'application/javascript']));
	$t->same('', $debugbar->invoke('asset_issue','/app.js',['status'=>'found','mime'=>'application/javascript','expected_mime'=>'application/javascript']));
	$t->notEmpty($debugbar->invoke('asset_candidate_paths','runtime/modules/flightdeck/version'));
	$t->contains('app.js',$debugbar->invoke('asset_relative_variants','assets/app.js'));
	$t->same('text/css',$debugbar->invoke('expected_asset_mime','styles/app.css'));
	$t->same('application/javascript',$debugbar->invoke('expected_asset_mime','scripts/app.mjs'));
	$t->same('', $debugbar->invoke('expected_asset_mime','image.unknown'));
	$t->isTrue($debugbar->invoke('path_has_parent_segment','a/../b'));
	$t->isFalse($debugbar->invoke('path_has_parent_segment','a/b'));
	$t->same(['same'=>2],$debugbar->invoke('duplicate_html_ids','<div id="same"></div><span id="same"></span><b id="unique"></b>'));
	$t->greaterThan(0,$debugbar->invoke('mojibake_count','broken Ã¢â‚¬ text'));
	$assets=$debugbar->invoke('response_assets','<link rel="stylesheet" href="/app.css"><script src="https://cdn.example.test/app.js"></script><img src="data:image/png;base64,AA=="><source src="/../unsafe.mp4">');
	$t->count(4,$assets);
	$t->hasPathValues(['0.kind'=>'stylesheet','1.kind'=>'script','2.kind'=>'image','3.kind'=>'source'],$assets);
})->tag('flightdeck','coverage','support','assets')->group('framework-coverage');
