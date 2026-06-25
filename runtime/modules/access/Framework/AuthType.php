<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

/**
 * Defines supported authentication transport identifiers.
 *
 * These constants are used by guards and configuration to distinguish
 * session-backed browser authentication from token-backed JWT authentication.
 * The class is not instantiable.
 */
final class AuthType {

	public const SESSION='session';
	public const JWT='jwt';

	/**
	 * Prevents construction of the auth-type constant holder.
	 */
	private function __construct(){
	}
}
