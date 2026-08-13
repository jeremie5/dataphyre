<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class Fakes {

	public static function clock(int|string|\DateTimeInterface $now='now'): FakeClock {
		return new FakeClock($now);
	}

	public static function storage(): FakeStorage {
		return new FakeStorage();
	}

	public static function mailer(): FakeMailer {
		return new FakeMailer();
	}

	public static function http(): FakeHttp {
		return new FakeHttp();
	}

	public static function auth(mixed $user=null): FakeAuth {
		return new FakeAuth($user);
	}

	public static function sql(): FakeSql {
		return new FakeSql();
	}

	public static function database(array $schema=[]): FakeDatabase {
		return new FakeDatabase($schema);
	}

	public static function pdo(string $driver='sqlite'): ScriptedPdo {
		return new ScriptedPdo($driver);
	}

	public static function queue(?FakeClock $clock=null): FakeQueue {
		return new FakeQueue($clock ?? new FakeClock());
	}

	public static function dialbacks(string $default_scope='framework'): FakeHookBus {
		return new FakeHookBus('dialback', $default_scope);
	}

	public static function callbacks(string $default_scope='app'): FakeHookBus {
		return new FakeHookBus('callback', $default_scope);
	}

	public static function reactor(): FakeReactor {
		return new FakeReactor();
	}

	public static function permissions(): FakePermissions {
		return new FakePermissions();
	}
}
