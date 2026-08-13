<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Raised when an authentication actor attempts to cross an owner boundary. */
final class PanelAuthenticationOwnershipViolation extends \DomainException {}
