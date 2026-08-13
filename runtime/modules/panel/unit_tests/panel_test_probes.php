<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel\TestFixtures;

use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;

trait UsesPanelTestState {
	private function __construct(protected TestState $state) {}

	protected static function state(string $channel): ?TestState {
		return TestState::channelIfActive($channel);
	}
}

final class AccessibilityEnvironment {
	use UsesPanelTestState;
	private const CHANNEL='panel.accessibility.environment';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,['dom_available'=>true]));
	}

	public function withoutDom(): self {
		$this->state->put('dom_available',false);
		return $this;
	}

	public static function domAvailable(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('dom_available',true) ?? true);
	}
}

final class NativeResponseProbe {
	use UsesPanelTestState;
	private const CHANNEL='panel.native-response';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,[
			'headers_sent'=>false,
			'status'=>200,
			'headers'=>[],
		]));
	}

	public function markHeadersSent(bool $sent=true): self {
		$this->state->put('headers_sent',$sent);
		return $this;
	}

	public function status(): int {
		return (int)$this->state->get('status',200);
	}

	/** @return list<array{0:string,1:bool,2:int}> */
	public function headers(): array {
		return $this->state->get('headers',[]);
	}

	public static function headersAlreadySent(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('headers_sent',false) ?? false);
	}

	public static function responseStatus(?int $status=null): int {
		$state=self::state(self::CHANNEL);
		if($status!==null){ $state?->put('status',$status); }
		return (int)($state?->get('status',200) ?? 200);
	}

	public static function recordHeader(string $header,bool $replace,int $responseCode): void {
		self::state(self::CHANNEL)?->append('headers',[$header,$replace,$responseCode]);
	}
}

final class PanelControllerEnvironment {
	use UsesPanelTestState;
	private const CHANNEL='panel.controller.environment';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,['missing_classes'=>[]]));
	}

	public function withoutClass(string $class): self {
		$missing=$this->state->get('missing_classes',[]);
		$missing[]=ltrim($class,'\\');
		$this->state->put('missing_classes',array_values(array_unique($missing)));
		return $this;
	}

	public function restoreClass(string $class): self {
		$class=ltrim($class,'\\');
		$this->state->put('missing_classes',array_values(array_filter(
			$this->state->get('missing_classes',[]),
			static fn(string $missing): bool=>$missing!==$class
		)));
		return $this;
	}

	public static function classAvailable(string $class): bool {
		return !in_array(ltrim($class,'\\'),self::state(self::CHANNEL)?->get('missing_classes',[]) ?? [],true);
	}
}

final class PackageFilesystemScenario {
	use UsesPanelTestState;
	private const CHANNEL='panel.package.filesystem';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,[
			'realpath_hook'=>null,
			'force_missing'=>false,
			'short_writes'=>false,
		]));
	}

	public static function active(): self {
		return new self(TestState::channel(self::CHANNEL));
	}

	public function realpathUsing(callable $resolver): self {
		$this->state->put('realpath_hook',$resolver);
		return $this;
	}

	public function useNativeRealpath(): self {
		$this->state->put('realpath_hook',null);
		return $this;
	}

	public function hideFilesystemEntries(bool $missing=true): self {
		$this->state->put('force_missing',$missing);
		return $this;
	}

	public function shortWrites(bool $short=true): self {
		$this->state->put('short_writes',$short);
		return $this;
	}

	public static function realpathOverride(string $path): string|false|null {
		$resolver=self::state(self::CHANNEL)?->get('realpath_hook');
		return is_callable($resolver) ? $resolver($path) : null;
	}

	public static function entriesAreHidden(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('force_missing',false) ?? false);
	}

	public static function writeShouldBeShort(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('short_writes',false) ?? false);
	}
}

final class RendererStreamScenario {
	use UsesPanelTestState;
	private const CHANNEL='panel.renderer.stream';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,['fail_open'=>false]));
	}

	public function failOpens(bool $fail=true): self {
		$this->state->put('fail_open',$fail);
		return $this;
	}

	public static function openShouldFail(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('fail_open',false) ?? false);
	}
}

final class RendererEntropyScenario {
	use UsesPanelTestState;
	private const CHANNEL='panel.renderer.entropy';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,['fail_random_bytes'=>false]));
	}

	public function failRandomBytes(bool $fail=true): self {
		$this->state->put('fail_random_bytes',$fail);
		return $this;
	}

	public static function randomBytesShouldFail(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('fail_random_bytes',false) ?? false);
	}
}

final class PanelRequestHeadersProbe {
	use UsesPanelTestState;
	private const CHANNEL='panel.request.headers';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,['headers'=>[]]));
	}

	/** @param array<string,mixed> $headers */
	public function returnHeaders(array $headers): self {
		$this->state->put('headers',$headers);
		return $this;
	}

	public static function capturedHeaders(): array {
		return self::state(self::CHANNEL)?->get('headers',[]) ?? [];
	}
}

final class PanelCsrfProbe {
	use UsesPanelTestState;
	private const CHANNEL='panel.csrf';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,['tokens'=>[]]));
	}

	public function token(string $form,string $token): self {
		$tokens=$this->state->get('tokens',[]);
		$tokens[$form]=$token;
		$this->state->put('tokens',$tokens);
		return $this;
	}

	public static function tokenFor(string $form): string {
		return (string)(self::state(self::CHANNEL)?->get('tokens',[])[$form] ?? '');
	}
}

final class ScaffoldFilesystemScenario {
	use UsesPanelTestState;
	private const CHANNEL='panel.scaffold.filesystem';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,['fail_mkdir'=>false,'fail_write'=>false]));
	}

	public function failDirectoryCreation(bool $fail=true): self {
		$this->state->put('fail_mkdir',$fail);
		return $this;
	}

	public function failWrites(bool $fail=true): self {
		$this->state->put('fail_write',$fail);
		return $this;
	}

	public static function directoryCreationShouldFail(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('fail_mkdir',false) ?? false);
	}

	public static function writeShouldFail(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('fail_write',false) ?? false);
	}
}

final class UploadFilesystemScenario {
	use UsesPanelTestState;
	private const CHANNEL='panel.upload.filesystem';

	public static function reset(Context $context,string $tempRoot): self {
		return new self($context->state(self::CHANNEL,[
			'missing_classes'=>[],
			'uploaded_files'=>[],
			'filesize_failures'=>[],
			'fail_locks'=>false,
			'write_failure_suffix'=>'',
			'fail_stream_copies'=>false,
			'temp_root'=>$tempRoot,
		]));
	}

	public static function active(): self {
		return new self(TestState::channel(self::CHANNEL));
	}

	public function withoutClass(string $class): self {
		$missing=$this->state->get('missing_classes',[]);
		$missing[]=ltrim($class,'\\');
		$this->state->put('missing_classes',array_values(array_unique($missing)));
		return $this;
	}

	public function restoreClass(string $class): self {
		$class=ltrim($class,'\\');
		$this->state->put('missing_classes',array_values(array_filter(
			$this->state->get('missing_classes',[]),
			static fn(string $missing): bool=>$missing!==$class
		)));
		return $this;
	}

	public function uploadedFile(string $path): self {
		$this->state->put('uploaded_files',[$path]);
		return $this;
	}

	public function clearUploadedFiles(): self {
		$this->state->put('uploaded_files',[]);
		return $this;
	}

	public function failFilesizeFor(string $path): self {
		$this->state->put('filesize_failures',[$path]);
		return $this;
	}

	public function allowFilesizes(): self {
		$this->state->put('filesize_failures',[]);
		return $this;
	}

	public function failLocks(bool $fail=true): self {
		$this->state->put('fail_locks',$fail);
		return $this;
	}

	public function failWritesEnding(string $suffix): self {
		$this->state->put('write_failure_suffix',$suffix);
		return $this;
	}

	public function allowWrites(): self {
		$this->state->put('write_failure_suffix','');
		return $this;
	}

	public function failStreamCopies(bool $fail=true): self {
		$this->state->put('fail_stream_copies',$fail);
		return $this;
	}

	public static function classAvailable(string $class): bool {
		return !in_array(ltrim($class,'\\'),self::state(self::CHANNEL)?->get('missing_classes',[]) ?? [],true);
	}

	public static function isUploadedFile(string $path): bool {
		return in_array($path,self::state(self::CHANNEL)?->get('uploaded_files',[]) ?? [],true);
	}

	public static function filesizeShouldFail(string $path): bool {
		return in_array($path,self::state(self::CHANNEL)?->get('filesize_failures',[]) ?? [],true);
	}

	public static function lockShouldFail(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('fail_locks',false) ?? false);
	}

	public static function writeFailureSuffix(): string {
		return (string)(self::state(self::CHANNEL)?->get('write_failure_suffix','') ?? '');
	}

	public static function streamCopyShouldFail(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('fail_stream_copies',false) ?? false);
	}

	public static function tempRoot(): string {
		$tempRoot=self::state(self::CHANNEL)?->get('temp_root');
		if(!is_string($tempRoot) || trim($tempRoot)===''){
			throw new \RuntimeException('Panel upload filesystem scenario is not active.');
		}
		return $tempRoot;
	}
}

final class PanelTraceProbe {
	use UsesPanelTestState;
	private const CHANNEL='panel.trace.probe';

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,[
			'trace'=>[],
			'fail_random_bytes'=>false,
			'session_status'=>null,
		]));
	}

	public static function active(): self {
		return new self(TestState::channel(self::CHANNEL));
	}

	public function withSession(): self {
		$this->state->put('session_status',PHP_SESSION_ACTIVE);
		return $this;
	}

	public function withoutSession(): self {
		$this->state->put('session_status',PHP_SESSION_NONE);
		return $this;
	}

	public function failRandomBytes(bool $fail=true): self {
		$this->state->put('fail_random_bytes',$fail);
		return $this;
	}

	/** @return list<array<int,mixed>> */
	public function trace(): array {
		return $this->state->get('trace',[]);
	}

	/** @param array<int,mixed> $arguments */
	public static function recordTrace(array $arguments): void {
		self::state(self::CHANNEL)?->append('trace',$arguments);
	}

	public static function randomBytesShouldFail(): bool {
		return (bool)(self::state(self::CHANNEL)?->get('fail_random_bytes',false) ?? false);
	}

	public static function sessionStatus(): int {
		$status=self::state(self::CHANNEL)?->get('session_status');
		return is_int($status) ? $status : \session_status();
	}
}
