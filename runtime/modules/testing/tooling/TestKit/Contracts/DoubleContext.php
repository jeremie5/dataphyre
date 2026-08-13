<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test\Contracts;

use Dataphyre\Test\FakeClock;
use Dataphyre\Test\FakeStorage;
use Dataphyre\Test\FakeMailer;
use Dataphyre\Test\FakeHttp;
use Dataphyre\Test\FakeAuth;
use Dataphyre\Test\FakeSql;
use Dataphyre\Test\FakeDatabase;
use Dataphyre\Test\ScriptedPdo;
use Dataphyre\Test\PdoDatabaseAssertions;
use Dataphyre\Test\FakeQueue;
use Dataphyre\Test\FakeHookBus;
use Dataphyre\Test\FakeReactor;
use Dataphyre\Test\FakePermissions;
use Dataphyre\Test\BrowserProbe;
use Dataphyre\Test\DataphyreModuleBridge;
use Dataphyre\Test\Spy;
use Dataphyre\Test\MockObject;
use Dataphyre\Test\StaticProxy;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TypeInventory;

/** Compile-time contract for this test-context capability family. */
interface DoubleContext {

	public function fakeClock(int|string|\DateTimeInterface $now='now'): FakeClock;

	public function fakeStorage(): FakeStorage;

	public function fakeMailer(): FakeMailer;

	public function fakeHttp(): FakeHttp;

	public function fakeAuth(mixed $user=null): FakeAuth;

	public function fakeSql(): FakeSql;

	public function fakeDatabase(array $schema=[]): FakeDatabase;

	public function scriptedPdo(string $driver='sqlite'): ScriptedPdo;

	public function pdoDatabase(\PDO $pdo): PdoDatabaseAssertions;

	public function fakeQueue(?FakeClock $clock=null): FakeQueue;

	public function fakeDialbacks(string $default_scope='framework'): FakeHookBus;

	public function fakeCallbacks(string $default_scope='app'): FakeHookBus;

	public function fakeReactor(): FakeReactor;

	public function fakePermissions(): FakePermissions;

	public function browser(array $options=[]): BrowserProbe;

	public function dataphyreModules(): DataphyreModuleBridge;

	public function spy(?callable $passthrough=null): Spy;

	public function mock(array $methods=[]): MockObject;

	public function functionPatch(string $qualified_function, ?callable $handler=null): Spy;

	public function staticProxy(string $class): StaticProxy;

	public function nonPublic(object|string $target): NonPublicAccess;

	public function inventory(object|string $target): TypeInventory;
}
