<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

final class FakeAuth {

	private mixed $user;

	public function __construct(mixed $user=null) {
		$this->user=$user;
	}

	public function login(mixed $user): void {
		$this->user=$user;
	}

	public function logout(): void {
		$this->user=null;
	}

	public function check(): bool {
		return $this->user!==null;
	}

	public function user(): mixed {
		return $this->user;
	}

	public function id(): mixed {
		if(is_array($this->user)){
			return $this->user['id'] ?? null;
		}
		if(is_object($this->user) && isset($this->user->id)){
			return $this->user->id;
		}
		return is_scalar($this->user) ? $this->user : null;
	}

	public function assertAuthenticated(AssertionContext $t): void {
		$t->isTrue($this->check(), 'Expected fake auth to be authenticated.');
	}

	public function assertGuest(AssertionContext $t): void {
		$t->isFalse($this->check(), 'Expected fake auth to be a guest.');
	}

	public function assertAuthenticatedAs(AssertionContext $t, mixed $id): void {
		$t->same($id, $this->id(), 'Expected fake auth user id to match.');
	}
}
