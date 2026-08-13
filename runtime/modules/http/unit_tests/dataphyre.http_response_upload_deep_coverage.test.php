<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Response;
use Dataphyre\Http\UploadedFile;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\core', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; class core { public static function dialback(...$arguments): mixed { return null; } public static function load_framework_module(string $module): void {} public static function load_framework_modules(array $modules): void {} }');
}

framework(['http']);

test('HTTP uploaded file covers metadata moves detector branches directory failures and errors', static function(Context $t): void {
	$workspace=$t->workspace('http-upload');
	$tmp=$workspace->file('incoming/report.tmp', 'fixture');
	$native=$workspace->path('native');
	$blocked=$workspace->file('blocked', 'not-a-directory');
	$nested=$native.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'moved.txt';
	$t->defer(static fn()=>UploadedFile::useUploadedFileDetector(null));

	$file=new UploadedFile('Report.PDF', 'application/pdf', $tmp, UPLOAD_ERR_OK, 7);
	$t->same('Report.PDF', $file->clientOriginalName());
	$t->same('pdf', $file->clientExtension());
	$t->same('pdf', $file->clientExtension());
	$t->same('application/pdf', $file->mimeType());
	$t->same($tmp, $file->path());
	$t->same(7, $file->size());
	$t->same(UPLOAD_ERR_OK, $file->error());
	$t->isTrue($file->isValid());

	UploadedFile::useUploadedFileDetector(static fn(string $path): bool=>true);
	$t->isFalse($file->moveTo($native));
	UploadedFile::useUploadedFileDetector(null);
	$t->isTrue($file->moveTo($nested));
	$t->isTrue(is_file($nested));
	$t->isFalse($file->isValid());

	$directoryFailure=new UploadedFile(
		'file.txt',
		'text/plain',
		$workspace->file('directory-failure.tmp', 'fixture'),
		UPLOAD_ERR_OK,
		7,
	);
	$t->isFalse($directoryFailure->moveTo($blocked.DIRECTORY_SEPARATOR.'child.txt'));

	$missing=UploadedFile::fromArray([]);
	$t->same('', $missing->clientOriginalName());
	$t->isFalse($missing->isValid());
	$t->same(UPLOAD_ERR_NO_FILE, $missing->error());
	$t->isFalse($missing->moveTo($native));
	$t->isFalse($missing->jsonSerialize()['valid']);

	$messages=[
		UPLOAD_ERR_OK=>'successfully',
		UPLOAD_ERR_INI_SIZE=>'server upload limit',
		UPLOAD_ERR_FORM_SIZE=>'form upload limit',
		UPLOAD_ERR_PARTIAL=>'partially',
		UPLOAD_ERR_NO_FILE=>'No file',
		UPLOAD_ERR_NO_TMP_DIR=>'directory is missing',
		UPLOAD_ERR_CANT_WRITE=>'written to disk',
		UPLOAD_ERR_EXTENSION=>'extension stopped',
		99=>'Unknown upload error',
	];
	foreach($messages as $code=>$needle){
		$message=(new UploadedFile('', '', '', $code, 0))->errorMessage();
		$t->contains($needle, $message);
	}
})->tag('http', 'coverage')->group('framework-coverage');

test('HTTP response covers expired cookies legacy cookie headers file read failures and MIME fallbacks', static function(Context $t): void {
	$expired=Response::cookieHeader('session', '', -1);
	$t->contains('Expires=', $expired);
	$t->contains('Max-Age=0', $expired);
	$legacy=(new Response('', 200, ['Set-Cookie'=>'legacy=1']))->withCookie('next', '2');
	$t->same(2, count($legacy->headers['Set-Cookie']));
	$t->same('legacy=1', $legacy->headers['Set-Cookie'][0]);

	$tmp=$t->tempFile('fixture', 'http-response');
	$t->defer(static fn()=>Response::useFileReader(null));
	Response::useFileReader(static fn(string $path): bool=>false);
	$t->throws(static fn()=>Response::file($tmp), RuntimeException::class);
	Response::useFileReader(null);
	$css=$tmp.'.css';
	file_put_contents($css, 'body{color:#123}');
	$t->defer(static fn()=>is_file($css) ? unlink($css) : null);
	$t->same('text/css; charset=utf-8', Response::file($css)->headers['Content-Type'] ?? null);

	$types=[
		'css'=>'text/css; charset=utf-8',
		'csv'=>'text/csv; charset=utf-8',
		'gif'=>'image/gif',
		'html'=>'text/html; charset=utf-8',
		'jpeg'=>'image/jpeg',
		'js'=>'application/javascript; charset=utf-8',
		'json'=>'application/json; charset=utf-8',
		'pdf'=>'application/pdf',
		'png'=>'image/png',
		'svg'=>'image/svg+xml',
		'txt'=>'text/plain; charset=utf-8',
		'webp'=>'image/webp',
		'unknown'=>'application/octet-stream',
	];
	$responseInternals=$t->nonPublic(Response::class);
	foreach($types as $extension=>$expected){
		$t->same($expected, $responseInternals->invoke('mimeType', 'missing.'.$extension));
	}
})->tag('http', 'coverage')->group('framework-coverage');
