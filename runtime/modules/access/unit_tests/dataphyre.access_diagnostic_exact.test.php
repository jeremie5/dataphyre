<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace {
	use Dataphyre\Database\TableDefinition;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	if(!defined('DATAPHYRE_ACCESS_DIAGNOSTIC_NO_DISPATCH')){
		define('DATAPHYRE_ACCESS_DIAGNOSTIC_NO_DISPATCH', true);
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(mixed ...$arguments): bool { return true; }
	}
	require_once dirname(__DIR__).'/kernel/access.diagnostic.php';
	require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

	suite('Access observable diagnostics')
		->contract('access.observable-diagnostics', 1)
		->layer('integration')
		->risk('high')
		->watches('module:access')
		->through('configuration', 'runtime-state', 'oauth', 'totp', 'schemas')
		->isolation('case')
		->tag('access', 'exact-coverage', 'diagnostics')
		->group('framework-coverage');

	test('diagnostics describe missing host capabilities from explicit observations', static function(Context $t): void {
		$required=$t->spy();
		$publish=$t->spy();
		$findings=\dataphyre\access\diagnostic::tests([
			'config'=>[
				'default_auth_type'=>'',
				'auth_types'=>'session',
				'framework'=>['oauth'=>['providers'=>'invalid']],
				'sessions_table_name'=>'',
			],
			'module_required'=>$required,
			'extension_loaded'=>static fn(): bool=>false,
			'php_version'=>'8.0.30',
			'clock'=>static fn(): int=>1_700_000_000,
			'access_runtime_available'=>false,
			'sql_query'=>null,
			'session_active'=>true,
			'session'=>['dp_access'=>['auth_type'=>[]]],
			'dpid_defined'=>true,
			'dpid'=>'forged',
			'publish'=>$publish,
		]);
		$errors=array_column($findings, 'error');
		$t->contains('PHP version 8.1.0 or higher is required.', $errors);
		$t->contains("PHP extension 'openssl' is not loaded.", $errors);
		$t->contains('Missing default auth type in configuration.', $errors);
		$t->contains('Enabled auth types are missing or invalid.', $errors);
		$t->contains('OAuth providers configuration must be an array.', $errors);
		$t->contains('dp_access entry in session missing or malformed (dpid).', $errors);
		$t->contains('dp_access entry in session missing userid.', $errors);
		$t->contains('dp_access entry in session has malformed auth_type.', $errors);
		$t->contains('DPID constant is defined but does not match expected format.', $errors);
		$t->contains('Missing session table name in configuration.', $errors);
		$t->same('warning', $findings[8]['level']);
		$t->same(1_700_000_000, $findings[0]['time']);
		$required->assertCalledTimes($t, 2);
		$publish->assertCalledTimes($t, 1);
	});

	test('diagnostics validate cookies provider metadata SQL schemas and failed TOTP entropy', static function(Context $t): void {
		$query=$t->spy();
		$findings=\dataphyre\access\diagnostic::tests([
			'config'=>[
				'sessions_cookie_name'=>'ACCESS',
				'default_auth_type'=>'session',
				'auth_types'=>['session'],
				'sessions_table_name'=>'custom.access_sessions',
				'framework'=>['oauth'=>['providers'=>[
					'scalar'=>'invalid',
					'incomplete'=>[],
					'discovered'=>['client_id'=>'client', 'issuer'=>'https://issuer.example.test'],
				]]],
			],
			'access_runtime_available'=>true,
			'session_cookie_name'=>'__Secure-WRONG',
			'sql_query'=>$query,
			'session_active'=>false,
			'dpid_defined'=>true,
			'dpid'=>42,
			'create_totp_secret'=>false,
			'publish'=>null,
		]);
		$errors=array_column($findings, 'error');
		$t->contains('Session cookie name does not match configuration.', $errors);
		$t->contains("OAuth provider 'scalar' configuration must be an array.", $errors);
		$t->contains("OAuth provider 'incomplete' is missing 'client_id'.", $errors);
		$t->contains("OAuth provider 'incomplete' is missing 'authorization_url'.", $errors);
		$t->contains("OAuth provider 'incomplete' is missing 'token_url'.", $errors);
		$t->contains('Unable to generate a TOTP secret.', $errors);
		$query->assertCalledTimes($t, 1);
		$schemas=$query->lastCall()[0];
		$t->sameKeys(['mysql','postgresql','sqlite'], $schemas);
		$t->contains('custom.access_sessions', $schemas['postgresql']);
		$t->contains('custom_access_sessions', $schemas['postgresql']);
	});

	test('diagnostics distinguish unavailable SQL malformed pairing images and healthy runtime observations', static function(Context $t): void {
		$config=[
			'default_auth_type'=>'session',
			'enabled_auth_types'=>['session'],
			'sessions_table_name'=>'dataphyre.sessions',
			'framework'=>['oauth'=>['providers'=>[
				'complete'=>[
					'client_id'=>'client',
					'authorization_url'=>'https://oauth.example.test/authorize',
					'token_url'=>'https://oauth.example.test/token',
				],
			]]],
		];
		$malformed=\dataphyre\access\diagnostic::tests([
			'config'=>$config,
			'access_runtime_available'=>true,
			'sql_query'=>null,
			'create_totp_secret'=>static fn(): string=>'JBSWY3DPEHPK3PXP',
			'totp_pairing_image'=>false,
			'publish'=>null,
		]);
		$t->same('warning', $malformed[0]['level']);
		$t->same('TOTP pairing image generation is not returning a local SVG data URI.', $malformed[1]['error']);

		$healthy=\dataphyre\access\diagnostic::tests([
			'config'=>$config,
			'module_required'=>null,
			'access_runtime_available'=>true,
			'session_active'=>true,
			'session'=>['dp_access'=>['dpid'=>'id', 'userid'=>7, 'auth_type'=>'session']],
			'dpid_defined'=>false,
			'sql_query'=>static fn(array $schemas): bool=>$schemas!==[],
			'create_totp_secret'=>static fn(): string=>'JBSWY3DPEHPK3PXP',
			'totp_pairing_image'=>static fn(string $secret): string=>'data:image/svg+xml;base64,'.base64_encode($secret),
			'publish'=>null,
		]);
		$t->same([], $healthy);
	});

	test('Access table manifest publishes session and one-time token invariants', static function(Context $t): void {
		$manifest=require dirname(__DIR__).'/kernel/access.tables.php';
		$t->sameKeys(['sessions','tokens'], $manifest);
		$sessions=$manifest['sessions']('fixture.sessions');
		$tokens=$manifest['tokens']('fixture.tokens');
		$t->instanceOf(TableDefinition::class, $sessions);
		$t->instanceOf(TableDefinition::class, $tokens);
		$t->same(['id'], $sessions->primaryColumns());
		$t->same(['id'], $tokens->primaryColumns());
	});
}
