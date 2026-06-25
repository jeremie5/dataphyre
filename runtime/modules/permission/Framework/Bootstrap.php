<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

$kernelEntry=dirname(__DIR__).'/kernel/permission.main.php';
if(is_file($kernelEntry)){
	require_once($kernelEntry);
}

if(class_exists('\dataphyre\core', false)){
	\dataphyre\core::load_framework_modules(['access', 'panel']);
}

if(class_exists('\dataphyre\core', false) && !defined('DATAPHYRE_PERMISSION_FRAMEWORK_BOOTSTRAPPED')){
	define('DATAPHYRE_PERMISSION_FRAMEWORK_BOOTSTRAPPED', true);

	\dataphyre\core::register_dialback('CALL_PERMISSION_SUBJECT_ID', static function(mixed $subject=null): mixed {
		return SubjectResolver::id($subject);
	});

	\dataphyre\core::register_dialback('CALL_PERMISSION_SUBJECT_PERMISSIONS', static function(mixed $subject=null, array $context=[]): mixed {
		return SubjectResolver::permissions($subject, $context);
	});

	\dataphyre\core::register_dialback('CALL_PERMISSION_SUBJECT_ROLES', static function(mixed $subject=null, array $context=[]): mixed {
		return SubjectResolver::roles($subject, $context);
	});
}
