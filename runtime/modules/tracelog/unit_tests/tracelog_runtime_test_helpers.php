<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\GlobalState;
use Dataphyre\Test\TempWorkspace;

foreach([
	'DATAPHYRE_TRACELOG_NO_DISPATCH',
	'DATAPHYRE_TRACELOG_ASSET_NO_DISPATCH',
	'DATAPHYRE_TRACELOG_PLOTTER_NO_DISPATCH',
	'DATAPHYRE_TRACELOG_VIEWER_NO_DISPATCH',
	'DATAPHYRE_TRACELOG_DIAGNOSTIC_NO_DISPATCH',
] as $constant){
	if(!defined($constant)){
		define($constant, true);
	}
}

require_once dirname(__DIR__).'/kernel/tracelog.main.php';
require_once dirname(__DIR__).'/kernel/assets.php';
require_once dirname(__DIR__).'/kernel/plotter.php';
require_once dirname(__DIR__).'/kernel/viewer.php';
require_once dirname(__DIR__).'/kernel/tracelog.diagnostic.php';

/** Process-hook probe used only when the full framework bootstrap is absent. */
final class DpTracelogSuppressionProbe {
	public static bool $suppressed=false;
}

if(!function_exists('dataphyre_debug_logging_suppressed')){
	function dataphyre_debug_logging_suppressed(): bool {
		return DpTracelogSuppressionProbe::$suppressed;
	}
}

if(!function_exists('dataphyre_tracelog_plot_frame')){
	function dataphyre_tracelog_plot_frame(): array {
		return [[
			'file'=>'/srv/runtime-hook.php',
			'line'=>12,
			'function'=>'capture',
			'class'=>'RuntimeHook',
			'type'=>'trace',
			'args'=>[],
			'time'=>'0.125',
		]];
	}
}

/** Intent-level state and filesystem vocabulary for Tracelog tests. */
final class DpTracelogRuntimeScenario {
	private TempWorkspace $workspace;
	/** @var list<callable> */
	private array $installedHandlers=[];
	/** @var list<array<int,mixed>> */
	private array $unavailable=[];
	/** @var list<string> */
	private array $fatalLogs=[];
	/** @var list<array<int,mixed>> */
	private array $generatedTests=[];
	/** @var list<array{path:string,contents:string,append:bool}> */
	private array $fileWrites=[];

	private function __construct(private Context $context) {
		$this->workspace=$context->workspace('tracelog-runtime');
		$this->workspace->directory('project/cache');
		$this->reset();
	}

	public static function open(Context $context): self {
		return new self($context);
	}

	public function reset(array $overrides=[]): self {
		$this->installedHandlers=[];
		$this->unavailable=[];
		$this->fatalLogs=[];
		$this->generatedTests=[];
		$this->fileWrites=[];
		$runtime=[
			'roots'=>[
				'dataphyre'=>$this->workspace->path('project').'/',
				'common_dataphyre'=>$this->workspace->path('common').'/',
				'root'=>$this->workspace->root(),
			],
			'server'=>['REQUEST_TIME_FLOAT'=>1000.0,'SERVER_ADDR'=>'127.0.0.1'],
			'cookies'=>['DPSESSID'=>'cookie-session','dataphyre_flightdeck'=>'flightdeck-session'],
			'session_name'=>'DPSESSID',
			'session_id'=>'runtime-session',
			'session'=>[],
			'retroactive'=>[],
			'rqid'=>'rq-test',
			'time'=>1700000000,
			'app'=>'test-app',
			'license_key'=>'test-license',
			'project_root'=>$this->workspace->root(),
			'initial_memory'=>memory_get_usage(),
			'suppressed'=>false,
			'dialback'=>static fn(string $name, mixed ...$arguments): null=>null,
			'random_color'=>static fn(): string=>'#123456',
			'convert_storage'=>static fn(int|float $bytes): string=>$bytes.' bytes',
			'set_error_handler'=>function(callable $handler): void {
				$this->installedHandlers[]=$handler;
			},
			'unavailable'=>function(mixed ...$arguments): void {
				$this->unavailable[]=$arguments;
			},
			'log_error'=>function(string $message): void {
				$this->fatalLogs[]=$message;
			},
			'generate_test'=>function(mixed ...$arguments): void {
				$this->generatedTests[]=$arguments;
			},
		];
		\dataphyre\tracelog::reset(array_replace($runtime, $overrides));
		return $this;
	}

	public function configure(array $runtime): self {
		\dataphyre\tracelog::configureRuntime($runtime);
		return $this;
	}

	/** @return array<string,mixed> */
	public function runtime(): array {
		return \dataphyre\tracelog::runtimeState();
	}

	public function activate(bool $defer=false): self {
		\dataphyre\tracelog::$enable=true;
		\dataphyre\tracelog::$defer=$defer;
		return $this;
	}

	public function openViewer(bool $plotting=false): self {
		\dataphyre\tracelog::$open=true;
		\dataphyre\tracelog::set_plotting($plotting);
		return $this;
	}

	public function trace(
		string $message,
		?string $type='info',
		mixed $arguments=null,
		?float $time=null,
		?int $memory=null,
		?array $plotFrame=null,
		string $class='Example',
		string $function='run'
	): bool {
		return \dataphyre\tracelog::tracelog(
			'/srv/example.php', '42', $class, $function, $message, $type,
			$arguments, $time, $memory, $plotFrame
		);
	}

	public function traceBuffer(): string {
		return (string)\dataphyre\tracelog::$tracelog;
	}

	public function replaceTraceBuffer(string $trace): self {
		\dataphyre\tracelog::$tracelog=$trace;
		return $this;
	}

	public function saveToSql(bool $enabled=true): self {
		\dataphyre\tracelog::$save_to_sql=$enabled;
		return $this;
	}

	public function persist(): self {
		\dataphyre\tracelog::persist_to_session();
		return $this;
	}

	/** Persists once while deferral is active, exercising the normal request handoff. */
	public function persistDeferred(string $trace=''): self {
		$this->activate(true)->replaceTraceBuffer($trace)->persist();
		return $this;
	}

	public function buffer(mixed $value): mixed {
		return \dataphyre\tracelog::buffer_callback($value);
	}

	/** @return array<string,mixed> */
	public function session(): array {
		return is_array(\dataphyre\tracelog::runtimeState()['session'] ?? null)
			? \dataphyre\tracelog::runtimeState()['session']
			: [];
	}

	/** @return array<int,mixed> */
	public function retroactive(): array {
		return is_array(\dataphyre\tracelog::runtimeState()['retroactive'] ?? null)
			? \dataphyre\tracelog::runtimeState()['retroactive']
			: [];
	}

	public function plottingPath(): string {
		return $this->workspace->path('project/cache/tracelog_plotting.dat');
	}

	public function handoffDirectory(): string {
		return $this->workspace->path('project/cache/tracelog_handoff');
	}

	/** Replaces generated handoffs with one unkeyed fallback trace. */
	public function onlyRecentHandoff(string $trace): string {
		$directory=$this->directory('project/cache/tracelog_handoff');
		foreach(glob($directory.'/*.dat') ?: [] as $file){
			@unlink($file);
		}
		return $this->file('project/cache/tracelog_handoff/recent.dat', $trace);
	}

	/** Reads the newest session handoff after one candidate disappears. */
	public function readNewestSessionHandoffAfterCandidateLoss(): string {
		$candidates=$this->handoffCandidates();
		if(isset($candidates[0])){
			@unlink($candidates[0]);
		}
		return \dataphyre\tracelog::last_handoff_trace();
	}

	/** Routes handoff storage through the secondary embedded-framework root. */
	public function useCommonDataphyreRoot(): self {
		$this->directory('common/cache');
		return $this->configure(['roots'=>[
			'dataphyre'=>'',
			'common_dataphyre'=>$this->workspace->path('common').'/',
			'root'=>$this->workspace->root(),
		]]);
	}

	/** Persists through a malformed injected session and returns its normalized state. */
	public function persistWithMalformedRuntimeSession(string $trace): array {
		$this->reset(['session'=>'malformed'])->activate(false)->replaceTraceBuffer($trace)->persist();
		return $this->session();
	}

	/** Persists through PHP's process session while containing global-state mechanics here. */
	public function persistThroughProcessSession(string $trace): array {
		return $this->context->withGlobal('_SESSION', [], function(GlobalState $session) use($trace): array {
			$runtime=$this->runtime();
			unset($runtime['session']);
			\dataphyre\tracelog::reset($runtime);
			$this->activate(false)->replaceTraceBuffer($trace)->persist();
			$value=$session->value();
			return is_array($value) ? $value : [];
		});
	}

	/** Defers through a malformed injected retroactive buffer and returns its normalized rows. */
	public function deferWithMalformedRuntimeBuffer(string $message): array {
		$this->reset(['retroactive'=>'malformed'])->activate(true)->trace($message);
		return $this->retroactive();
	}

	/** Defers through the legacy process buffer while containing its global lifecycle. */
	public function deferThroughProcessBuffer(string $message): array {
		return $this->context->withoutGlobal('retroactive_tracelog', function(GlobalState $buffer) use($message): array {
			$runtime=$this->runtime();
			unset($runtime['retroactive']);
			\dataphyre\tracelog::reset($runtime);
			$this->activate(true)->trace($message);
			$value=$buffer->value();
			return is_array($value) ? $value : [];
		});
	}

	public function isSuppressedByRuntimePolicy(string $message): bool {
		$this->reset(['suppressed'=>static fn(): bool=>true])->activate(false);
		return !$this->trace($message);
	}

	/** Exercises the framework-wide suppression hook without leaking its global flag. */
	public function isSuppressedByProcessPolicy(string $message): bool {
		$previousProbe=DpTracelogSuppressionProbe::$suppressed;
		try{
			return $this->context->withGlobal('dataphyre_debug_logging_suppressed', true, function() use($message): bool {
				DpTracelogSuppressionProbe::$suppressed=true;
				$runtime=$this->runtime();
				unset($runtime['suppressed']);
				\dataphyre\tracelog::reset($runtime);
				$this->activate(false);
				return !$this->trace($message);
			});
		}finally{
			DpTracelogSuppressionProbe::$suppressed=$previousProbe;
		}
	}

	/** Observes writes without exposing filesystem callback plumbing to test cases. */
	public function recordFileWrites(): self {
		$this->fileWrites=[];
		return $this->configure(['write_file'=>function(string $path, string $contents, bool $append): void {
			$this->fileWrites[]=['path'=>$path,'contents'=>$contents,'append'=>$append];
		}]);
	}

	/** @return list<array{path:string,contents:string,append:bool}> */
	public function recordedFileWrites(): array {
		return $this->fileWrites;
	}

	public function sessionPayload(string $trace): string {
		return (string)$this->context->nonPublic(\dataphyre\tracelog::class)->invoke('session_trace_payload', $trace);
	}

	/** @return list<string> */
	public function handoffCandidates(): array {
		$result=$this->context->nonPublic(\dataphyre\tracelog::class)->invoke('handoff_files');
		return is_array($result) ? $result : [];
	}

	public function signHandoffId(string $id): string {
		return (string)$this->context->nonPublic(\dataphyre\tracelog::class)->invoke('sign_handoff_id', $id);
	}

	public function handoffFileFromToken(string $token): ?string {
		$result=$this->context->nonPublic(\dataphyre\tracelog::class)->invoke('handoff_file_from_token', $token);
		return is_string($result) ? $result : null;
	}

	public function primaryHandoffToken(): ?string {
		$result=$this->context->nonPublic(\dataphyre\tracelog::class)->invoke('sign_primary_handoff_id');
		return is_string($result) ? $result : null;
	}

	/** @param array<int,mixed> $row @return list<array<string,mixed>> */
	public function plotFrame(array $row): array {
		$result=$this->context->nonPublic(\dataphyre\tracelog::class)->invoke('plot_frame_from_trace_row', $row);
		return is_array($result) ? $result : [];
	}

	public function file(string $relative, string $contents=''): string {
		return $this->workspace->file($relative, $contents);
	}

	public function directory(string $relative): string {
		return $this->workspace->directory($relative);
	}

	public function path(string $relative=''): string {
		return $this->workspace->path($relative);
	}

	/** @return list<callable> */
	public function installedHandlers(): array {
		return $this->installedHandlers;
	}

	/** @return list<array<int,mixed>> */
	public function unavailableCalls(): array {
		return $this->unavailable;
	}

	/** @return list<string> */
	public function fatalLogs(): array {
		return $this->fatalLogs;
	}

	/** @return list<array<int,mixed>> */
	public function generatedTests(): array {
		return $this->generatedTests;
	}
}

/** @return array<int,mixed> */
function dp_tracelog_unencodable_plot_fixture(): array {
	$recursive=[];
	$recursive[]=&$recursive;
	return $recursive;
}
