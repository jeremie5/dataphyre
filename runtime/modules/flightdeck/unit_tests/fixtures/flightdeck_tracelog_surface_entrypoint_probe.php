<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$root=rtrim((string)($argv[1] ?? ''),'/\\');
$surface=(string)($argv[2] ?? '');

define('DATAPHYRE_FLIGHTDECK_VIEW_LOADED',true);
define('ROOTPATH',[
	'dataphyre'=>$root,
	'common_dataphyre'=>$root,
]);

final class dataphyre_flightdeck_view {
	public static function card(string $title, string $body, array $options=[]): string {
		return '<article data-title="'.self::e($title).'">'.$body.($options['subtitle'] ?? '').'</article>';
	}

	public static function module_page(string $module, string $title, string $description, string $content): string {
		return '<main data-module="'.self::e($module).'"><h1>'.self::e($title).'</h1>'.$description.$content.'</main>';
	}

	public static function e(string $value): string {
		return htmlspecialchars($value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
	}
}

$_SERVER=['REQUEST_URI'=>'/dataphyre/tracelog']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must model the native Tracelog request boundary."
$_GET=[]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must clear the native query-string boundary."
$_SESSION=[]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Entrypoint fixture must model an empty native session boundary."

ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Entrypoint fixture captures pages emitted by repeated real surface includes."
require $surface;
require $surface;
$body=(string)ob_get_clean();

echo json_encode([
	'pages'=>substr_count($body,'data-module="Tracelog"'),
	'viewer'=>str_contains($body,'Runtime Trace Viewer'),
],JSON_THROW_ON_ERROR);
