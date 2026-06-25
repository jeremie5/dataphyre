<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Exceptions;

/**
 * Reports failed authentication or missing authenticated identity.
 *
 * This exception represents the login/session/token boundary rather than an
 * authorization decision. Policy denials should use their own authorization
 * failure types when available.
 */
final class AuthenticationException extends \RuntimeException {
}
