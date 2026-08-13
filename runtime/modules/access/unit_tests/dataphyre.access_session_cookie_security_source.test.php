<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

test('Access session cookies are host-only and remove every legacy scope on rotation and expiry', static function(Context $t): void {
	$source=(string)file_get_contents(dirname(__DIR__).'/kernel/access.main.php');
	$t->contains("private static \$session_cookie='__Host-DPID'", $source);
	$t->contains("self::\$session_cookie='__Host-'.DP_ACCESS_CFG['sessions_cookie_name']", $source);
	$t->contains("'path'=>'/'", $source);
	$t->contains("'secure'=>true", $source);
	$t->contains("'httponly'=>true", $source);
	$t->contains("'samesite'=>'Lax'", $source);
	$options_start=strpos($source, 'private static function session_cookie_options(');
	$options_end=strpos($source, 'private static function legacy_session_cookie_options(', (int)$options_start);
	$t->isTrue(is_int($options_start) && is_int($options_end) && $options_start<$options_end);
	$t->notContains("'domain'=>", substr($source, (int)$options_start, (int)$options_end-(int)$options_start));
	$t->contains("'__Secure-'.\$configured_name", $source);
	$t->contains("'__Secure-DPID'", $source);
	$t->contains('self::session_cookie_expiry_variants(time()-3600, false)', $source);
	$t->contains('$cookie_expires=$keepalive ? time()+(86400*7) : 0', $source);
	$t->contains('setcookie(self::$session_cookie, $dpid, self::session_cookie_options($cookie_expires))', $source);
	$t->contains('self::session_cookie_expiry_variants(time()-3600)', $source);
	$t->contains("\$options['domain']=\$domain", $source);
	$t->contains("unset(\$_COOKIE[\$variant['name']])", $source);
	$t->contains('$php_session_options[\'samesite\']=\'Strict\'', $source);
})->tag('access', 'authentication', 'cookie', 'security', 'source-contract');
