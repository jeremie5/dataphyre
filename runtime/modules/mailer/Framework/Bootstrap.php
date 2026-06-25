<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer;

/**
 * Marks the mailer framework as loaded for kernel and scheduler callers.
 *
 * The bootstrap file intentionally performs no provider registration or I/O.
 * MailerManager constructs providers lazily from DP_MAILER_CFG, so including this
 * file is a cheap lifecycle signal that the framework classes are available.
 */
if(!defined('DATAPHYRE_MAILER_FRAMEWORK_BOOTSTRAPPED')){
	define('DATAPHYRE_MAILER_FRAMEWORK_BOOTSTRAPPED', true);
}
