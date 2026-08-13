<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\CapturedExecution;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true,'routing'=>true,'api'=>true,'sql'=>true,'fulltext_engine'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DATAPHYRE_ROUTING_NO_DISPATCH')){
	define('DATAPHYRE_ROUTING_NO_DISPATCH', true);
}
if(!defined('DATAPHYRE_ROUTE_COMPILER_NO_DISPATCH')){
	define('DATAPHYRE_ROUTE_COMPILER_NO_DISPATCH', true);
}
require_once dirname(__DIR__).'/kernel/routing.main.php';
require_once dirname(__DIR__).'/kernel/compile_app_routes.php';

/** Intent-level harness for the legacy view router. */
final class DpLegacyRoutingScenario {
	private TempWorkspace $workspace;

	private function __construct(private Context $context) {
		$this->workspace=$context->workspace('legacy-routing');
		\dataphyre\routing::reset();
		\dataphyre\routing::configure(['views_root'=>$this->workspace->directory('views')]);
	}

	public static function open(Context $context): self {
		return new self($context);
	}

	public function view(string $name): string {
		return $this->workspace->file('views/'.$name.'.php', '<?php');
	}

	public function route(string $pattern, string $request, string $file=''): string|bool {
		return \dataphyre\routing::check_route($pattern, $file, $request);
	}

	public function verboseNonMatches(bool $enabled): self {
		\dataphyre\routing::verboseNonMatches($enabled);
		return $this;
	}

	public function binding(string $name): mixed {
		return \dataphyre\routing::$bindings[$name] ?? null;
	}

	public function formatted(string $definition, string $request, string $binding): mixed {
		\dataphyre\routing::$bindings=[];
		$matched=$this->route('/value/{'.$definition.'}', '/value/'.$request);
		if($matched!==true){
			throw new RuntimeException('Expected formatted route to match: '.$definition);
		}
		return $this->binding($binding);
	}

	public function rejectsFormatted(string $definition, string $request): bool {
		return $this->route('/value/{'.$definition.'}', '/value/'.$request)===false;
	}

	/** @param array<string,mixed> $server @return array<string,mixed> */
	public function notFound(string $errorPage, array $server=[]): array {
		\dataphyre\routing::configure(['not_found_errorpage'=>$errorPage]);
		return $this->currentNotFound($server);
	}

	/** @param array<string,mixed> $server @return array<string,mixed> */
	public function currentNotFound(array $server=[]): array {
		$result=\dataphyre\routing::not_found([
			'server'=>$server,
			'emit'=>static fn(array $response): array=>$response,
		]);
		return is_array($result) ? $result : [];
	}

	/** @param array<string,mixed> $query @param array<string,mixed> $server */
	public function currentRequestUri(array $query, array $server): string {
		return (string)$this->context->nonPublic(\dataphyre\routing::class)->invoke('currentRequestUri', $query, $server);
	}

	/** @param array<string,mixed> $config */
	public function configurationRoot(string $name, array $config): string {
		$root=$this->workspace->directory($name);
		$this->workspace->file(
			$name.'/config/routing.php',
			'<?php \\dataphyre\\routing::configure('.var_export($config, true).');'
		);
		return $root;
	}

	public function emptyRoot(string $name): string {
		return $this->workspace->directory($name);
	}

	public function path(string $relative): string {
		return $this->workspace->path($relative);
	}
}

/** Captures compiler output, errors, and termination without process exits. */
final class DpRouteCompilerScenario {
	private TempWorkspace $workspace;
	/** @var list<string> */
	private array $errors=[];
	/** @var list<int> */
	private array $terminations=[];

	private function __construct(private Context $context) {
		$this->workspace=$context->workspace('route-compiler-entrypoint');
	}

	public static function open(Context $context): self {
		return new self($context);
	}

	/** @param list<string> $arguments @param array<string,mixed> $runtime */
	public function run(array $arguments, array $runtime=[]): CapturedExecution {
		$runtime['error'] ??= function(string $message): void {
			$this->errors[]=$message;
		};
		return $this->context->captureOutput(
			fn(): int=>dp_route_compiler_run(['compile_app_routes.php', ...$arguments], $runtime)
		);
	}

	/** @param list<string> $arguments @param array<string,mixed> $runtime */
	public function dispatch(array $arguments, array $runtime=[]): CapturedExecution {
		$runtime['error'] ??= function(string $message): void {
			$this->errors[]=$message;
		};
		$runtime['terminate'] ??= function(int $status): void {
			$this->terminations[]=$status;
		};
		return $this->context->captureOutput(
			fn(): ?int=>dp_route_compiler_entrypoint(['compile_app_routes.php', ...$arguments], true, $runtime)
		);
	}

	public function directory(string $relative): string {
		return $this->workspace->directory($relative);
	}

	public function file(string $relative, string $contents=''): string {
		return $this->workspace->file($relative, $contents);
	}

	public function path(string $relative=''): string {
		return $this->workspace->path($relative);
	}

	/** @return list<string> */
	public function errors(): array {
		return $this->errors;
	}

	/** @return list<int> */
	public function terminations(): array {
		return $this->terminations;
	}
}
