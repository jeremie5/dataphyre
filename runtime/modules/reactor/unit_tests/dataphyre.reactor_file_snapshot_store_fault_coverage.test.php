<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Reactor {
	final class ReactorFileStoreCoverageFaults {
		public static bool $mkdir=false;
		public static bool $time=false;
		public static int $timeOffset=0;
		public static bool $jsonEncode=false;
		public static bool $randomBytes=false;
		public static bool $rename=false;

		public static function reset(): void {
			self::$mkdir=false;
			self::$time=false;
			self::$timeOffset=0;
			self::$jsonEncode=false;
			self::$randomBytes=false;
			self::$rename=false;
		}
	}

	function mkdir(string $directory, int $permissions=0777, bool $recursive=false, mixed $context=null): bool {
		if(ReactorFileStoreCoverageFaults::$mkdir){ return false; }
		return $context===null ? \mkdir($directory, $permissions, $recursive) : \mkdir($directory, $permissions, $recursive, $context);
	}

	function time(): int {
		if(ReactorFileStoreCoverageFaults::$time){ throw new \RuntimeException('controlled clock fault'); }
		return \time()+ReactorFileStoreCoverageFaults::$timeOffset;
	}

	function json_encode(mixed $value, int $flags=0, int $depth=512): string|false {
		if(ReactorFileStoreCoverageFaults::$jsonEncode){ throw new \JsonException('controlled encoder fault'); }
		return \json_encode($value, $flags, $depth);
	}

	function random_bytes(int $length): string {
		if(ReactorFileStoreCoverageFaults::$randomBytes){ throw new \RuntimeException('controlled entropy fault'); }
		return \random_bytes($length);
	}

	function rename(string $from, string $to, mixed $context=null): bool {
		if(ReactorFileStoreCoverageFaults::$rename){ return false; }
		return $context===null ? \rename($from, $to) : \rename($from, $to, $context);
	}
}

namespace {
	use Dataphyre\Reactor\ReactorFileSnapshotVersionStore;
	use Dataphyre\Reactor\ReactorFileStoreCoverageFaults;
	use Dataphyre\Reactor\ReactorComponent;
	use Dataphyre\Reactor\ReactorInMemorySnapshotVersionStore;
	use Dataphyre\Reactor\ReactorEndpoint;
	use Dataphyre\Reactor\ReactorManager;
	use Dataphyre\Reactor\ReactorRequest;
	use Dataphyre\Reactor\ReactorSecurityContext;
	use Dataphyre\Reactor\ReactorSnapshot;
	use Dataphyre\Reactor\ReactorSnapshotVersionStore;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['reactor','mvc']);

	if(!defined('DP_REACTOR_THROWING_CLEANUP_STORE_LOADED') && !class_exists(DpReactorThrowingCleanupStore::class, false)){
	define('DP_REACTOR_THROWING_CLEANUP_STORE_LOADED', true);
	final class DpReactorThrowingCleanupStore implements ReactorSnapshotVersionStore {
		private ReactorInMemorySnapshotVersionStore $inner;
		public bool $failSecondRegister=false;
		public bool $throwAbort=false;
		public bool $throwRevoke=false;
		private int $registerCalls=0;
		public function __construct(){ $this->inner=new ReactorInMemorySnapshotVersionStore(); }
		public function register(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool {
			$this->registerCalls++;
			if($this->failSecondRegister && $this->registerCalls>=2){ return false; }
			return $this->inner->register($snapshotId, $scopeHash, $component, $version, $expiresAt);
		}
		public function reserve(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId, int $reservationExpiresAt): string { return $this->inner->reserve($snapshotId, $scopeHash, $component, $expectedVersion, $reservationId, $reservationExpiresAt); }
		public function finalize(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, int $nextVersion, int $nextExpiresAt, string $reservationId): string { return $this->inner->finalize($snapshotId, $scopeHash, $component, $expectedVersion, $nextVersion, $nextExpiresAt, $reservationId); }
		public function abort(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId): bool {
			if($this->throwAbort){ throw new RuntimeException('controlled abort failure'); }
			return $this->inner->abort($snapshotId, $scopeHash, $component, $expectedVersion, $reservationId);
		}
		public function revoke(string $snapshotId, string $scopeHash, string $component, int $version): bool {
			if($this->throwRevoke){ throw new RuntimeException('controlled revoke failure'); }
			return $this->inner->revoke($snapshotId, $scopeHash, $component, $version);
		}
		public function manifest(): array { return $this->inner->manifest(); }
	}
	}

	test('reactor file snapshot store fails closed across filesystem encoder clock and entropy faults', static function(Context $t): void {
		ReactorFileStoreCoverageFaults::reset();
		$t->defer(static function(): void { ReactorFileStoreCoverageFaults::reset(); });
		$root=$t->workspace('reactor-file-store-fault-coverage')->root();
		ReactorFileStoreCoverageFaults::$mkdir=true;
		$t->throws(static fn()=>new ReactorFileSnapshotVersionStore($root.DIRECTORY_SEPARATOR.'mkdir-failure'), RuntimeException::class);
		ReactorFileStoreCoverageFaults::reset();

		$directory=$root.DIRECTORY_SEPARATOR.'ledger';
		$store=new ReactorFileSnapshotVersionStore($directory);
		$scope=str_repeat('b', 64);
		$expires=time()+300;

		ReactorFileStoreCoverageFaults::$jsonEncode=true;
		$t->isFalse($store->register(str_repeat('1', 32), $scope, 'orders', 0, $expires));
		ReactorFileStoreCoverageFaults::reset();

		ReactorFileStoreCoverageFaults::$randomBytes=true;
		$t->isFalse($store->register(str_repeat('2', 32), $scope, 'orders', 0, $expires));
		ReactorFileStoreCoverageFaults::reset();

		ReactorFileStoreCoverageFaults::$rename=true;
		$t->isFalse($store->register(str_repeat('3', 32), $scope, 'orders', 0, $expires));
		$t->same([], glob($directory.DIRECTORY_SEPARATOR.'.tmp-*') ?: []);
		ReactorFileStoreCoverageFaults::reset();

		$invalidId=str_repeat('4', 32);
		$invalidFile=$directory.DIRECTORY_SEPARATOR.$invalidId.'.000000000001.'.str_repeat('5', 16).'.json';
		file_put_contents($invalidFile, '{');
		$t->same(
			ReactorSnapshotVersionStore::UNAVAILABLE,
			$store->reserve($invalidId, $scope, 'orders', 0, str_repeat('6', 32), time()+30)
		);

		$clockId=str_repeat('7', 32);
		$t->isTrue($store->register($clockId, $scope, 'orders', 0, $expires));
		ReactorFileStoreCoverageFaults::$time=true;
		$t->same(
			ReactorSnapshotVersionStore::UNAVAILABLE,
			$store->reserve($clockId, $scope, 'orders', 0, str_repeat('8', 32), time()+30)
		);

		ReactorFileStoreCoverageFaults::reset();
		$memory=new ReactorInMemorySnapshotVersionStore();
		$memoryId=str_repeat('9', 32);
		$memoryReservation=str_repeat('a', 32);
		$t->isTrue($memory->register($memoryId, $scope, 'orders', 0, time()+10));
		$t->same(ReactorSnapshotVersionStore::CLAIMED, $memory->reserve($memoryId, $scope, 'orders', 0, $memoryReservation, time()+5));
		ReactorFileStoreCoverageFaults::$timeOffset=11;
		$t->same(ReactorSnapshotVersionStore::EXPIRED, $memory->finalize($memoryId, $scope, 'orders', 0, 1, time()+300, $memoryReservation));

		ReactorFileStoreCoverageFaults::reset();
		$reservationExpiryMemory=new ReactorInMemorySnapshotVersionStore();
		$reservationExpiryId=str_repeat('b', 32);
		$reservationExpiryToken=str_repeat('c', 32);
		$t->isTrue($reservationExpiryMemory->register($reservationExpiryId, $scope, 'orders', 0, time()+100));
		$t->same(ReactorSnapshotVersionStore::CLAIMED, $reservationExpiryMemory->reserve($reservationExpiryId, $scope, 'orders', 0, $reservationExpiryToken, time()+5));
		ReactorFileStoreCoverageFaults::$timeOffset=6;
		$t->same(ReactorSnapshotVersionStore::RESERVATION_EXPIRED, $reservationExpiryMemory->finalize($reservationExpiryId, $scope, 'orders', 0, 1, time()+300, $reservationExpiryToken));

		ReactorFileStoreCoverageFaults::reset();
		$fileReservationId=str_repeat('d', 32);
		$fileReservationToken=str_repeat('e', 32);
		$t->isTrue($store->register($fileReservationId, $scope, 'orders', 0, time()+100));
		$t->same(ReactorSnapshotVersionStore::CLAIMED, $store->reserve($fileReservationId, $scope, 'orders', 0, $fileReservationToken, time()+5));
		ReactorFileStoreCoverageFaults::$timeOffset=6;
		$t->same(ReactorSnapshotVersionStore::RESERVATION_EXPIRED, $store->finalize($fileReservationId, $scope, 'orders', 0, 1, time()+300, $fileReservationToken));

		ReactorFileStoreCoverageFaults::reset();
		if(session_status()!==PHP_SESSION_ACTIVE){ @session_start(); }
		$session=$t->globalMap('_SESSION');
		$component=ReactorComponent::make('session_commit_fault')->session('value', 'session-commit-fault');
		ReactorFileStoreCoverageFaults::$randomBytes=true;
		$t->isFalse($component->commitSessionState(['value'=>'persisted-before-trace-fault']));
		$t->same('persisted-before-trace-fault', $session->getPath(['dataphyre_reactor','session-commit-fault']));

		ReactorFileStoreCoverageFaults::reset();
		$traceManager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))->trustInternalTransport('reactor:fault-coverage');
		$traceManager->register(
			ReactorComponent::make('manager_trace_fault')
				->action('success', static function(array $state): array { ReactorFileStoreCoverageFaults::$randomBytes=true; return $state; })
				->action('explode', static function(): never { ReactorFileStoreCoverageFaults::$randomBytes=true; throw new RuntimeException('controlled action failure'); })
				->render('safe')
		);
		$traceSnapshot=$traceManager->snapshot('manager_trace_fault');
		$traceSuccess=$traceManager->dispatch(['component'=>'manager_trace_fault','action'=>'success','snapshot'=>$traceSnapshot->jsonSerialize()]);
		$t->same(200, $traceSuccess->status());
		ReactorFileStoreCoverageFaults::reset();
		$traceSuccessor=ReactorSnapshot::from($traceSuccess->effects()['snapshot']);
		$traceFailure=$traceManager->dispatch(['component'=>'manager_trace_fault','action'=>'explode','snapshot'=>$traceSuccessor?->jsonSerialize()]);
		$t->same(500, $traceFailure->status());
		ReactorFileStoreCoverageFaults::reset();
		$traceManager->authorizeTransport(static function(): bool { ReactorFileStoreCoverageFaults::$randomBytes=true; return false; });
		$traceDenial=$traceManager->dispatch(['component'=>'manager_trace_fault']);
		$t->same(403, $traceDenial->status());

		ReactorFileStoreCoverageFaults::reset();
		$initial=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))->trustInternalTransport('reactor:initial-fault');
		$initial->register(ReactorComponent::make('initial_trace_fault')->render('safe'));
		$initial->authorizeTransport(static function(): never { ReactorFileStoreCoverageFaults::$randomBytes=true; throw new RuntimeException('controlled initial policy fault'); });
		$t->throws(static fn()=>$initial->snapshot('initial_trace_fault'), RuntimeException::class);
		ReactorFileStoreCoverageFaults::reset();
		$initial->authorizeTransport(static function(): bool { ReactorFileStoreCoverageFaults::$randomBytes=true; return false; });
		$t->throws(static fn()=>$initial->mount('initial_trace_fault'), RuntimeException::class);
		ReactorFileStoreCoverageFaults::reset();

		$cleanupStore=new DpReactorThrowingCleanupStore();
		$cleanupManager=(new ReactorManager($cleanupStore))->trustInternalTransport('reactor:cleanup-fault');
		$cleanupManager->register(
			ReactorComponent::make('abort_fault')
				->authorize(static fn(array $state, ?ReactorRequest $request, ReactorComponent $component, ?string $action): bool=>$action===null)
				->action('deny', static fn(array $state): array=>$state)
				->render('safe')
		);
		$abortSnapshot=$cleanupManager->snapshot('abort_fault');
		$cleanupStore->throwAbort=true;
		$t->same(403, $cleanupManager->dispatch(['component'=>'abort_fault','action'=>'deny','snapshot'=>$abortSnapshot->jsonSerialize()])->status());

		$revokeStore=new DpReactorThrowingCleanupStore();
		$revokeStore->failSecondRegister=true;
		$revokeStore->throwRevoke=true;
		$revokeManager=(new ReactorManager($revokeStore))->trustInternalTransport('reactor:revoke-fault');
		$revokeManager->register(
			ReactorComponent::make('revoke_parent')
				->child('body', ReactorComponent::make('revoke_child')->render('child'))
				->render(ReactorComponent::childSlot('body'))
		);
		$t->throws(static fn()=>$revokeManager->mount('revoke_parent'), RuntimeException::class);

		ReactorFileStoreCoverageFaults::reset();
		$t->same(403, ReactorEndpoint::handle(['component'=>'missing'], ['audience'=>[]])->status());
		$malformed=ReactorRequest::fromArray(['component'=>'missing','snapshot'=>['bad'=>'shape']], ReactorSecurityContext::forAudience('reactor:request-fault'));
		$t->isTrue($malformed->snapshotSupplied());
	})->tag('reactor','security','snapshot-store','fault-injection','exact-coverage')->maxMillis(2000);
}
