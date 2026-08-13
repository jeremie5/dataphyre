<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Owns the Context capabilities described by its name. */
trait CreatesTestDoubles {

	public function fakeClock(int|string|\DateTimeInterface $now='now'): FakeClock {
		return Fakes::clock($now);
	}

	public function fakeStorage(): FakeStorage {
		return Fakes::storage();
	}

	public function fakeMailer(): FakeMailer {
		return Fakes::mailer();
	}

	public function fakeHttp(): FakeHttp {
		return Fakes::http();
	}

	public function fakeAuth(mixed $user=null): FakeAuth {
		return Fakes::auth($user);
	}

	public function fakeSql(): FakeSql {
		return Fakes::sql();
	}

	public function fakeDatabase(array $schema=[]): FakeDatabase {
		return Fakes::database($schema);
	}

	public function scriptedPdo(string $driver='sqlite'): ScriptedPdo {
		return Fakes::pdo($driver);
	}

	public function pdoDatabase(\PDO $pdo): PdoDatabaseAssertions {
		return new PdoDatabaseAssertions($pdo);
	}

	public function fakeQueue(?FakeClock $clock=null): FakeQueue {
		return Fakes::queue($clock);
	}

	public function fakeDialbacks(string $default_scope='framework'): FakeHookBus {
		return Fakes::dialbacks($default_scope);
	}

	public function fakeCallbacks(string $default_scope='app'): FakeHookBus {
		return Fakes::callbacks($default_scope);
	}

	public function fakeReactor(): FakeReactor {
		return Fakes::reactor();
	}

	public function fakePermissions(): FakePermissions {
		return Fakes::permissions();
	}

	public function browser(array $options=[]): BrowserProbe {
		$root=defined('ROOTPATH') && is_array(ROOTPATH) ? (string)(ROOTPATH['common_root'] ?? ROOTPATH['root'] ?? '') : '';
		return new BrowserProbe($root, $options);
	}

	public function dataphyreModules(): DataphyreModuleBridge {
		$root=defined('ROOTPATH') && is_array(ROOTPATH) ? (string)(ROOTPATH['common_dataphyre_runtime'] ?? '') : '';
		return new DataphyreModuleBridge($root, $this->workspace('dataphyre-module-bridge')->root());
	}

	public function spy(?callable $passthrough=null): Spy {
		return new Spy($passthrough);
	}

	public function mock(array $methods=[]): MockObject {
		return new MockObject($methods);
	}

	public function functionPatch(string $qualified_function, ?callable $handler=null): Spy {
		return FunctionPatches::define($qualified_function, $handler);
	}

	public function staticProxy(string $class): StaticProxy {
		return new StaticProxy($class);
	}

	public function nonPublic(object|string $target): NonPublicAccess {
		return new NonPublicAccess($this, $target);
	}

	public function inventory(object|string $target): TypeInventory {
		return TypeInventory::of($target);
	}
}
