<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelLocalMediaDisk;
use Dataphyre\Panel\PanelMediaCleanupPolicy;
use Dataphyre\Panel\PanelMediaDisk;
use Dataphyre\Panel\PanelMediaProcessingPipeline;
use Dataphyre\Panel\PanelResumableUploadSession;
use Dataphyre\Panel\PanelSignedMediaDelivery;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel local media disk enforces path checksum stream and atomic replacement contracts', static function(Context $t): void {
	$root=$t->tempDirectory('panel-local-media');
	$disk=new PanelLocalMediaDisk($root, 'private-files', 4096);
	$written=$disk->write('orders/91/note.txt', 'first payload', ['checksum'=>hash('sha256', 'first payload')]);
	$t->same('private-files', $written['disk']);
	$t->same('first payload', $disk->read('orders/91/note.txt'));
	$t->same(hash('sha256', 'first payload'), $disk->checksum('orders/91/note.txt'));
	$t->throws(static fn()=> $disk->write('../escape.txt', 'bad'), InvalidArgumentException::class);
	$t->throws(static fn()=> $disk->write('C:\\escape.txt', 'bad'), InvalidArgumentException::class);
	$t->throws(static fn()=> $disk->write('orders/91/note.txt', 'second', ['overwrite'=>false]), RuntimeException::class);
	$t->throws(static fn()=> $disk->write('orders/91/bad.txt', 'bad', ['checksum'=>str_repeat('0', 64)]), UnexpectedValueException::class);
	$replaced=$disk->write('orders/91/note.txt', 'second payload');
	$t->same('second payload', $disk->read($replaced['path']));
	$absolute=$root.DIRECTORY_SEPARATOR.'orders'.DIRECTORY_SEPARATOR.'91'.DIRECTORY_SEPARATOR.'note.txt';
	$backup=dirname($absolute).DIRECTORY_SEPARATOR.'.note.txt.0123456789abcdef.bak';
	rename($absolute, $backup);
	$recovered=new PanelLocalMediaDisk($root, 'private-files', 4096);
	$t->same('second payload', $recovered->read('orders/91/note.txt'));
	$t->isFalse(is_file($backup));
	$copy=$disk->copy('orders/91/note.txt', 'orders/91/copy.txt');
	$t->same('orders/91/copy.txt', $copy['path']);
	$move=$disk->move('orders/91/copy.txt', 'archive/copy.txt');
	$t->same('archive/copy.txt', $move['path']);
	$t->isFalse($disk->exists('orders/91/copy.txt'));
	$t->same(['archive/copy.txt', 'orders/91/note.txt'], array_column($disk->list(), 'path'));
	$stream=$disk->readStream('archive/copy.txt');
	try { $t->same('second payload', stream_get_contents($stream)); }
	finally { fclose($stream); }
	$t->isTrue($disk->delete('archive/copy.txt'));
	$t->isFalse($disk->delete('archive/copy.txt'));
	$t->isTrue($disk->manifest()['capabilities']['path_traversal_protection']);
})->tag('panel', 'media', 'storage', 'production')->group('panel-production-runtime');

test('panel resumable uploads accept idempotent out of order chunks resume and assemble with whole-file integrity', static function(Context $t): void {
	$root=$t->tempDirectory('panel-resumable-media');
	$disk=new PanelLocalMediaDisk($root);
	$payload=str_repeat('A', 1024).str_repeat('B', 1024).'CC';
	$session=PanelResumableUploadSession::start($disk, 'uploads/result.bin', strlen($payload), 1024, hash('sha256', $payload), ['owner'=>'operator'], 'upload-session-0001');
	$last=$session->receiveChunk(2, 'CC', hash('sha256', 'CC'), 2048);
	$t->isFalse($last['idempotent']);
	$t->isTrue($session->receiveChunk(2, 'CC', hash('sha256', 'CC'))['idempotent']);
	$t->throws(static fn()=> $session->receiveChunk(2, 'DD'), UnexpectedValueException::class);
	$session->receiveChunk(0, str_repeat('A', 1024));
	$resumed=PanelResumableUploadSession::resume($disk, 'upload-session-0001');
	$t->same([1], $resumed->status()['missing_chunks']);
	$resumed->receiveChunk(1, str_repeat('B', 1024));
	$t->isTrue($resumed->status()['ready']);
	$result=$resumed->assemble();
	$t->same(strlen($payload), $result['size']);
	$t->same($payload, $disk->read('uploads/result.bin'));
	$t->same('completed', $resumed->manifest()['state']);
	$t->throws(static fn()=> $resumed->receiveChunk(0, str_repeat('A', 1024)), LogicException::class);

	$cancel=PanelResumableUploadSession::start($disk, 'uploads/cancel.bin', 1024, 1024, null, [], 'upload-session-0002');
	$cancel->receiveChunk(0, str_repeat('X', 1024));
	$t->isTrue($cancel->cancel());
	$t->isFalse($cancel->cancel());

	$idempotent=PanelResumableUploadSession::start($disk, 'uploads/already.bin', 1024, 1024, null, [], 'upload-session-0003');
	$idempotent->receiveChunk(0, str_repeat('Z', 1024));
	$disk->write('uploads/already.bin', str_repeat('Z', 1024));
	$t->isTrue($idempotent->assemble()['idempotent']);
	$oversized=PanelResumableUploadSession::start($disk, 'uploads/oversized.bin', 1024, 1024, null, [], 'upload-session-0004');
	$oversizedStream=fopen('php://temp', 'w+b');
	fwrite($oversizedStream, str_repeat('Q', 1025));
	rewind($oversizedStream);
	try {
		$t->throws(static fn()=> $oversized->receiveChunkStream(0, $oversizedStream, 1024), UnexpectedValueException::class);
	}
	finally { fclose($oversizedStream); }
})->tag('panel', 'media', 'upload', 'production')->group('panel-production-runtime');

test('panel media processing validates scanners transformers variants metadata and quarantine', static function(Context $t): void {
	$root=$t->tempDirectory('panel-processing-media');
	$disk=new PanelLocalMediaDisk($root);
	$disk->write('source/report.txt', 'report body');
	$pipeline=(new PanelMediaProcessingPipeline($disk))
		->scanner(static fn(PanelMediaDisk $disk, string $path): array => ['clean'=>!str_contains($disk->read($path), 'virus'), 'engine'=>'fixture'], 'fixture-scanner')
		->transformer(static function(PanelMediaDisk $disk, string $path, array $variants): array {
			$target=(string)($variants['preview']['path'] ?? 'variants/preview.txt');
			$disk->write($target, strtoupper($disk->read($path)));
			return ['variants'=>['preview'=>['path'=>$target, 'role'=>'preview']], 'metadata'=>['uppercase'=>true]];
		}, 'uppercase');
	$result=$pipeline->process('source/report.txt', ['preview'=>['path'=>'variants/report-preview.txt']], ['record'=>91]);
	$t->isTrue($result['ok']);
	$t->same('REPORT BODY', $disk->read('variants/report-preview.txt'));
	$t->same('preview', $result['variants']['preview']['role']);
	$t->same('txt', $result['metadata']['extension']);
	$t->same('fixture-scanner', $result['scans']['fixture-scanner']['scanner']);
	$t->same(['unsupported'=>[]], $pipeline->process('source/report.txt', [], ['unsupported'=>static fn()=> true])['metadata']['context']);

	$disk->write('source/infected.txt', 'virus signature');
	$rejected=$pipeline->process('source/infected.txt');
	$t->isFalse($rejected['ok']);
	$t->same('rejected', $rejected['status']);
	$t->isFalse($disk->exists('source/infected.txt'));
	$t->contains('.panel-quarantine/', $rejected['quarantine']['path']);
	$t->isTrue($pipeline->manifest()['capabilities']['variant_validation']);
})->tag('panel', 'media', 'processing', 'production')->group('panel-production-runtime');

test('panel cleanup is dry run first policy gated and signed delivery binds path audience expiry and content', static function(Context $t): void {
	$root=$t->tempDirectory('panel-delivery-media');
	$disk=new PanelLocalMediaDisk($root);
	$disk->write('kept/reference.txt', 'reference');
	$disk->write('orphans/delete.txt', 'delete me');
	$disk->write('protected/keep.txt', 'protected');
	$cleanup=(new PanelMediaCleanupPolicy(0, ['protected'], 10))->authorizeUsing(static fn(array $candidate): bool => !str_contains((string)$candidate['path'], 'denied'));
	$plan=$cleanup->plan($disk, ['kept/reference.txt'], '', time()+1);
	$t->same(['orphans/delete.txt'], array_column($plan['candidates'], 'path'));
	$t->same(['protected/keep.txt'], array_column($plan['protected'], 'path'));
	$dry=$cleanup->execute($disk, ['kept/reference.txt'], '', true, time()+1);
	$t->same(0, $dry['deleted_count']);
	$t->isTrue($disk->exists('orphans/delete.txt'));
	$executed=$cleanup->execute($disk, ['kept/reference.txt'], '', false, time()+1);
	$t->same(1, $executed['deleted_count']);
	$t->isFalse($disk->exists('orphans/delete.txt'));

	$delivery=new PanelSignedMediaDelivery($disk, str_repeat('s', 32), '/private/media', 3600);
	$issued=$delivery->issue('kept/reference.txt', 600, 'attachment', 'reference.txt', 'operator-7');
	$verified=$delivery->verify($issued['token'], 'kept/reference.txt', 'operator-7');
	$t->isTrue($verified['valid']);
	$t->contains('attachment', $issued['headers']['Content-Disposition']);
	$t->throws(static fn()=> $delivery->verify($issued['token'].'x'), UnexpectedValueException::class);
	$t->throws(static fn()=> $delivery->verify($issued['token'], 'protected/keep.txt', 'operator-7'), UnexpectedValueException::class);
	$t->throws(static fn()=> $delivery->verify($issued['token'], null, 'wrong'), UnexpectedValueException::class);
	$expired=$delivery->issue('kept/reference.txt', 1);
	$t->throws(static fn()=> $delivery->verify($expired['token'], null, null, time()+2), UnexpectedValueException::class);
	$changed=$delivery->issue('kept/reference.txt');
	$disk->write('kept/reference.txt', 'changed');
	$t->throws(static fn()=> $delivery->verify($changed['token']), UnexpectedValueException::class);
	$t->isTrue($delivery->manifest()['capabilities']['content_binding']);
})->tag('panel', 'media', 'cleanup', 'security', 'production')->group('panel-production-runtime');
