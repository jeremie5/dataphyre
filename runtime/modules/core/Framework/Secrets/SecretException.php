<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Secrets;

/**
 * A deliberately non-sensitive failure raised by Dataphyre secret primitives.
 *
 * Messages identify the failed invariant without embedding key material,
 * plaintext, ciphertext, associated data, or a provider response.
 */
final class SecretException extends \RuntimeException {}
