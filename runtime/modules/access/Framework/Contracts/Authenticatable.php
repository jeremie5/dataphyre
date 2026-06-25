<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Contracts;

/**
 * Marks a value object as usable by Access authentication guards.
 *
 * Implementations expose the stable identifier used for sessions, remember
 * tokens, permission subject resolution, and audit context without forcing a
 * specific ORM or record base class.
 */
interface Authenticatable {

	/**
	 * Returns the stable authentication subject identifier.
	 *
	 * @return int|string|null Identifier stored in sessions or null when the object cannot be persisted as an authenticated subject.
	 */
	public function authIdentifier(): int|string|null;
}
