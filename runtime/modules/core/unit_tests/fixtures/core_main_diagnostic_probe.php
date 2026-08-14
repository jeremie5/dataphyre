<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

function tracelog(mixed ...$arguments): void {}
function log_error(mixed ...$arguments): void {}
function dataphyre_shutdown_log(mixed ...$arguments): void {}
function pre_init_error(?string $message=null, ?object $exception=null, ?bool $unavailable=false): never {
	throw new RuntimeException($message ?? 'Core diagnostic bootstrap failed.', previous:$exception instanceof Throwable ? $exception : null);
}
function heisenconstant(string $name, callable $provider): void {
	if(!defined($name)){
		define($name, $provider());
	}
}
function dataphyre_internal_application_release_preflight_context(): array {
	return [
		'state_root'=>__DIR__,
		'private_key'=>str_repeat('k', 32),
		'project_root'=>__DIR__,
		'token'=>'diagnostic-probe',
	];
}

$source=(string)($argv[1] ?? '');
$fixtureRoot=rtrim(__DIR__, '/\\').'/';
define('ROOTPATH', [
	'root'=>$fixtureRoot,
	'dataphyre'=>$fixtureRoot,
	'common_dataphyre'=>$fixtureRoot,
]);
define('APP', '_Diagnostic$Probe');
define('BS_VERSION', '2.0');
define('RUN_MODE', 'diagnostic');
define('IS_PRODUCTION', false);
define('CFG', new class implements ArrayAccess {
	/** @var array<string,mixed> */
	private array $data=[];
	public function &raw(): array { return $this->data; }
	public function offsetExists(mixed $offset): bool { return array_key_exists((string)$offset, $this->data); }
	public function offsetGet(mixed $offset): mixed { return $this->data[(string)$offset] ?? null; }
	public function offsetSet(mixed $offset, mixed $value): void { $this->data[(string)$offset]=$value; }
	public function offsetUnset(mixed $offset): void { unset($this->data[(string)$offset]); }
});
define('DP_CORE_CFG', [
	'private_key'=>[str_repeat('k', 32)],
	'timezone'=>'UTC',
	'max_execution_memory'=>'1G',
	'max_execution_time'=>30,
	'core'=>[
		'php_session'=>['enabled'=>false],
		'client_ip_identification'=>['default_ip'=>'127.0.0.1'],
	],
]);
$_SERVER['REMOTE_ADDR']='127.0.0.1'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Diagnostic entrypoint fixture must model the native client-address request boundary."
$_SERVER['HTTP_USER_AGENT']='Dataphyre diagnostic probe'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Diagnostic entrypoint fixture must model the native user-agent request boundary."

require $source;

echo json_encode([
	'core_loaded'=>defined('DP_CORE_LOADED') && DP_CORE_LOADED===true,
	'diagnostic_loaded'=>class_exists(\dataphyre\core\diagnostic::class, false),
	'run_mode'=>RUN_MODE,
], JSON_THROW_ON_ERROR);
