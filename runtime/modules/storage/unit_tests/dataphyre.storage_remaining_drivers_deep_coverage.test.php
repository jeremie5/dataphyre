<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\Drivers\AuditDriver;
use Dataphyre\Storage\Drivers\EventedDriver;
use Dataphyre\Storage\Drivers\FailoverDriver;
use Dataphyre\Storage\Drivers\LocalDriver;
use Dataphyre\Storage\Drivers\MemoryDriver;
use Dataphyre\Storage\Drivers\MirrorDriver;
use Dataphyre\Storage\Drivers\PolicyDriver;
use Dataphyre\Storage\Drivers\QuotaDriver;
use Dataphyre\Storage\Drivers\RateLimitedDriver;
use Dataphyre\Storage\Drivers\ReadOnlyDriver;
use Dataphyre\Storage\Drivers\ScopedDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Storage\Support\Path;
use Dataphyre\Storage\Support\Stream;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['storage']);

if(!function_exists('Dataphyre\Storage\Drivers\fopen')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Storage\Drivers;
function dp_storage_remaining_failure(string $operation): bool {
	return \Dataphyre\Test\TestState::channelIfActive('storage.remaining-functions')?->get($operation, false)===true;
}
function fopen(string $filename, string $mode, bool $use_include_path=false, mixed $context=null): mixed {
	if(dp_storage_remaining_failure('fopen') && str_contains($filename, '.tmp.')){
		return false;
	}
	return $context===null
		? \fopen($filename, $mode, $use_include_path)
		: \fopen($filename, $mode, $use_include_path, $context);
}
function fwrite(mixed $stream, string $data, ?int $length=null): int|false {
	if(dp_storage_remaining_failure('fwrite')){
		return false;
	}
	return $length===null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
}
function rename(string $from, string $to, mixed $context=null): bool {
	if(dp_storage_remaining_failure('rename')){
		return false;
	}
	return $context===null ? \rename($from, $to) : \rename($from, $to, $context);
}
function function_exists(string $function): bool {
	if($function==='mime_content_type' && dp_storage_remaining_failure('hide_mime')){
		return false;
	}
	return \function_exists($function);
}
function mime_content_type(string $filename): string|false {
	if(dp_storage_remaining_failure('mime')){
		return false;
	}
	return \mime_content_type($filename);
}
PHP);
}

if(!function_exists('Dataphyre\Storage\Support\stream_get_contents')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Storage\Support;
function stream_get_contents(mixed $stream): string|false {
	if(\Dataphyre\Storage\Drivers\dp_storage_remaining_failure('stream')){
		return false;
	}
	return \stream_get_contents($stream);
}
PHP);
}

final class DpStorageRemainingFakeDriver implements StorageDriver {
	/** @var array<string,string> */
	public array $objects=[];
	public bool $writeOk=true;
	public bool $deleteOk=true;
	public bool $readOk=true;
	public bool $metadataOk=true;
	public bool $urlOk=true;
	/** @var list<string> */
	public array $failWrites=[];
	/** @var list<string> */
	public array $failDeletes=[];
	/** @var ?array<int|string,mixed> */
	public ?array $listOverride=null;
	/** @var array<string,int> */
	public array $calls=[];

	private function called(string $operation): void {
		$this->calls[$operation]=($this->calls[$operation] ?? 0)+1;
	}

	public function exists(string $path): bool {
		$this->called('exists');
		return array_key_exists(Path::normalize($path), $this->objects);
	}

	public function read(string $path, array $options=[]): string|false {
		$this->called('read');
		$path=Path::normalize($path);
		return $this->readOk && array_key_exists($path, $this->objects) ? $this->objects[$path] : false;
	}

	public function readStream(string $path, array $options=[]): mixed {
		$this->called('read_stream');
		$path=Path::normalize($path);
		return $this->readOk && array_key_exists($path, $this->objects) ? Stream::fromString($this->objects[$path]) : false;
	}

	public function write(string $path, mixed $contents, array $options=[]): bool {
		$this->called('write');
		$path=Path::normalize($path);
		if(!$this->writeOk || in_array($path, $this->failWrites, true)){
			return false;
		}
		$body=is_resource($contents) ? (string)Stream::contents($contents) : (string)$contents;
		$this->objects[$path]=$body;
		return true;
	}

	public function delete(string $path): bool {
		$this->called('delete');
		$path=Path::normalize($path);
		if(!$this->deleteOk || in_array($path, $this->failDeletes, true)){
			return false;
		}
		unset($this->objects[$path]);
		return true;
	}

	public function metadata(string $path): FileMetadata|false {
		$this->called('metadata');
		$path=Path::normalize($path);
		if(!$this->metadataOk || !array_key_exists($path, $this->objects)){
			return false;
		}
		return new FileMetadata($path, strlen($this->objects[$path]), 123, 'text/plain', ['fake'=>true]);
	}

	public function list(string $prefix='', array $options=[]): array {
		$this->called('list');
		if($this->listOverride!==null){
			return $this->listOverride;
		}
		$prefix=Path::normalize($prefix);
		$out=[];
		foreach(array_keys($this->objects) as $path){
			if($prefix!=='' && $path!==$prefix && !str_starts_with($path, $prefix.'/')){
				continue;
			}
			$metadata=$this->metadata($path);
			if($metadata instanceof FileMetadata){
				$out[]=$metadata;
			}
		}
		return $out;
	}

	public function temporaryUrl(string $path, int|DateTimeInterface $expires, array $options=[]): string|false {
		$this->called('temporary_url');
		return $this->urlOk ? 'fake://'.Path::normalize($path) : false;
	}
}

function dp_storage_remaining_named_listener(array $event): void {
	\Dataphyre\Test\TestState::channel('storage.remaining-events')->append('named', $event['event'] ?? '');
}

test('storage remaining drivers deep coverage exercises memory and local filesystem backends', static function(Context $t): void {
	$workspace=$t->workspace('storage-remaining-local');
	$root=$workspace->root();
	$functions=$t->state('storage.remaining-functions', [
		'fopen'=>false,
		'fwrite'=>false,
		'rename'=>false,
		'hide_mime'=>false,
		'mime'=>false,
		'stream'=>false,
	]);
		MemoryDriver::flush();
		$t->cleanup(static fn()=>MemoryDriver::flush());
		$memory=new MemoryDriver();
		$prefixed=new MemoryDriver(['prefix'=>' tenant//one ']);
		$t->isFalse($memory->exists('missing.txt'));
		$t->same(false, $memory->read('missing.txt'));
		$t->same(false, $memory->readStream('missing.txt'));
		$t->isFalse($memory->write('', 'empty-path'));
		$t->isTrue($memory->write('/b.txt', 'beta', ['content_type'=>'text/plain', 'custom'=>'yes']));
		$t->isTrue($memory->write('a.txt', Stream::fromString('alpha'), ['content_type'=>3, 'flag'=>true]));
		$t->isTrue($memory->write('empty.txt', ''));
		$t->isTrue($prefixed->write('/docs/item.txt', 'tenant'));
		$helperTarget=new DpStorageRemainingFakeDriver();
		$helperTarget->objects=['present.txt'=>'present'];
		$t->same('present', $helperTarget->read('present.txt'));
		$t->same(false, $helperTarget->read('missing.txt'));
		$t->same('beta', $memory->read('b.txt'));
		$t->same('', Stream::contents($memory->readStream('empty.txt')));
		$t->same('text/plain', $memory->metadata('b.txt')->mimeType());
		$t->same('yes', $memory->metadata('b.txt')->extra()['custom']);
		$t->same(null, $memory->metadata('a.txt')->mimeType());
		$t->same(false, $memory->metadata('no.txt'));
		$t->same(['a.txt', 'b.txt', 'empty.txt', 'tenant/one/docs/item.txt'], array_map(static fn(FileMetadata $m): string=>$m->path(), $memory->list()));
		$t->same([], $memory->list('missing'));
		$t->same(['tenant/one/docs/item.txt'], array_map(static fn(FileMetadata $m): string=>$m->path(), $prefixed->list('docs')));
		$t->same(false, $memory->temporaryUrl('missing.txt', time()+60));
		$t->contains('expires=', (string)$memory->temporaryUrl('a.txt', time()+60));
		$t->contains('tenant%2Fone%2Fdocs%2Fitem.txt', (string)$prefixed->temporaryUrl('docs/item.txt', new DateTimeImmutable('+1 minute')));
		$t->isTrue($memory->delete('a.txt'));
		$t->notEmpty(MemoryDriver::snapshot());
		MemoryDriver::flush();
		$t->same([], MemoryDriver::snapshot());

		$defaultLocal=new LocalDriver(['root'=>$root.'/default']);
		$t->instanceOf(LocalDriver::class, $defaultLocal);
		$local=new LocalDriver(['root'=>$root.'/disk', 'url'=>'https://storage.test/base/', 'signing_key'=>'secret']);
		$t->isFalse($local->exists('docs/missing.txt'));
		$t->same(false, $local->read('docs/missing.txt'));
		$t->same(false, $local->readStream('docs/missing.txt'));
		$t->same(false, $local->metadata('docs/missing.txt'));
		$t->same([], $local->list('missing'));

		$functions->put('fopen', true);
		$t->isFalse($local->write('docs/open-fail.txt', 'body'));
		$functions->put('fopen', false)->put('fwrite', true);
		$t->isFalse($local->write('docs/write-fail.txt', 'body'));
		$functions->put('fwrite', false)->put('rename', true);
		$t->isFalse($local->write('docs/rename-fail.txt', 'body'));
		$functions->put('rename', false);

		$t->isTrue($local->write('/docs/public.txt', Stream::fromString('public'), [
			'visibility'=>'public', 'content_type'=>'text/custom', 'cache_control'=>'max-age=60',
			'content_disposition'=>'inline', 'original_name'=>'source.txt', 'ignored'=>'x',
		]));
		$t->isTrue($local->write('docs/private.txt', 'private', ['visibility'=>'private']));
		$t->isTrue($local->write('docs/plain.txt', 'plain', ['visibility'=>'other', 'content_type'=>' ']));
		$t->isTrue($local->write('docs/empty.txt', ''));
		$t->isTrue($local->exists('docs/public.txt'));
		$t->same('public', $local->read('docs/public.txt'));
		$t->same('public', Stream::contents($local->readStream('docs/public.txt')));
		$metadata=$local->metadata('/docs/public.txt');
		$t->instanceOf(FileMetadata::class, $metadata);
		$t->same('text/custom', $metadata->mimeType());
		$t->same('source.txt', $metadata->extra()['original_name']);
		$t->same(0, $local->metadata('docs/empty.txt')->size());
		$workspace->directory('disk/docs/non-file-directory');
		$t->same(4, count($local->list('docs')));
		$escapeProbe=new LocalDriver(['root'=>$root.'/escape-probe']);
		$t->nonPublic($escapeProbe)->writeProperty('root', $root.'/escape-probe/');
		$t->throws(static fn()=>$escapeProbe->exists('x.txt'), InvalidArgumentException::class);

		$workspace->file('disk/docs/plain.txt.dataphyre-storage.json', '{broken');
		$functions->put('hide_mime', true);
		$t->same(null, $local->metadata('docs/plain.txt')->mimeType());
		$functions->put('hide_mime', false)->put('mime', true);
		$t->same(null, $local->metadata('docs/plain.txt')->mimeType());
		$functions->put('mime', false);

		$t->isTrue($local->write('docs/public.txt', 'replaced'));
		$t->isFalse(is_file($root.'/disk/docs/public.txt.dataphyre-storage.json'));
		$unsigned=new LocalDriver(['root'=>$root.'/unsigned', 'url'=>'https://storage.test/files']);
		$t->same('https://storage.test/files/a%20b.txt', $unsigned->temporaryUrl('a b.txt', time()+60));
		$noUrl=new LocalDriver(['root'=>$root.'/no-url']);
		$t->same(false, $noUrl->temporaryUrl('a.txt', time()+60));
		$signed=$local->temporaryUrl('docs/public.txt', new DateTimeImmutable('+1 minute'));
		$t->contains('signature=', (string)$signed);
		$queryUrl=new LocalDriver(['root'=>$root.'/query', 'url'=>'https://storage.test/files?token=x', 'signing_key'=>'key']);
		$t->contains('&expires=', (string)$queryUrl->temporaryUrl('a.txt', time()+60));
		$expires=time()+60;
		$signature=hash_hmac('sha256', 'docs/public.txt|'.$expires, 'secret');
		$t->isTrue(LocalDriver::verifyTemporaryUrl('/docs/public.txt', $expires, $signature, 'secret'));
		$t->isFalse(LocalDriver::verifyTemporaryUrl('docs/public.txt', time()-1, $signature, 'secret'));
		$t->isFalse(LocalDriver::verifyTemporaryUrl('docs/public.txt', $expires, $signature, ''));
		$t->isFalse(LocalDriver::verifyTemporaryUrl('docs/public.txt', $expires, 'bad', 'secret'));
		$t->isTrue($local->delete('docs/private.txt'));
		$t->isTrue($local->delete('docs/missing.txt'));
})->tag('storage', 'remaining-drivers', 'memory', 'local', 'deep-coverage')->group('framework-coverage');

test('storage remaining drivers deep coverage exercises readonly scoped mirror and failover topology', static function(Context $t): void {
	$one=new DpStorageRemainingFakeDriver();
	$two=new DpStorageRemainingFakeDriver();
	$three=new DpStorageRemainingFakeDriver();
	$one->objects=['scope/docs/a.txt'=>'alpha', 'shared.txt'=>'first'];
	$two->objects=['shared.txt'=>'second', 'fallback.txt'=>'fallback'];
	$functions=$t->state('storage.remaining-functions', ['stream'=>false]);
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', [
		'disk_one'=>$one,
		'disk_two'=>$two,
		'disk_three'=>$three,
	]);

	$t->throws(static fn()=>new ReadOnlyDriver([], $manager), RuntimeException::class);
	$t->instanceOf(ReadOnlyDriver::class, new ReadOnlyDriver(['target'=>'disk-one'], null));
	$readonly=new ReadOnlyDriver(['disk'=>'disk-one'], $manager);
	$t->isTrue($readonly->exists('shared.txt'));
	$t->same('first', $readonly->read('shared.txt'));
	$t->isTrue(is_resource($readonly->readStream('shared.txt')));
	$t->isFalse($readonly->write('x.txt', 'x'));
	$t->isFalse($readonly->delete('shared.txt'));
	$t->instanceOf(FileMetadata::class, $readonly->metadata('shared.txt'));
	$t->same(2, count($readonly->list()));
	$t->same('fake://shared.txt', $readonly->temporaryUrl('shared.txt', time()+60));

	$t->throws(static fn()=>new ScopedDriver([], $manager), RuntimeException::class);
	$t->instanceOf(ScopedDriver::class, new ScopedDriver(['target'=>'disk-one'], null));
	$scoped=new ScopedDriver(['disk'=>'disk-one', 'prefix'=>'/scope/'], $manager);
	$t->isTrue($scoped->exists('/docs/a.txt'));
	$t->same('alpha', $scoped->read('docs/a.txt'));
	$t->isTrue(is_resource($scoped->readStream('docs/a.txt')));
	$t->isTrue($scoped->write('docs/b.txt', 'beta'));
	$t->instanceOf(FileMetadata::class, $scoped->metadata('docs/a.txt'));
	$t->same('docs/a.txt', $scoped->metadata('docs/a.txt')->path());
	$t->same(false, $scoped->metadata('missing.txt'));
	$t->same(['docs/a.txt', 'docs/b.txt'], array_map(static fn(FileMetadata $m): string=>$m->path(), $scoped->list('docs')));
	$t->same('fake://scope/docs/a.txt', $scoped->temporaryUrl('docs/a.txt', time()+60));
	$t->isTrue($scoped->delete('docs/b.txt'));
	$transparent=new ScopedDriver(['disk'=>'disk-one', 'prefix'=>''], $manager);
	$t->same('shared.txt', $t->nonPublic($transparent)->invoke('path', '/shared.txt'));
	$outside=new FileMetadata('outside.txt', 1, 2, 'text/plain', ['x'=>1]);
	$t->same('outside.txt', $t->nonPublic($scoped)->invoke('unscopedMetadata', $outside)->path());

	$t->throws(static fn()=>new MirrorDriver([], $manager), RuntimeException::class);
	$t->instanceOf(MirrorDriver::class, new MirrorDriver(['disks'=>['disk-one']], null));
	$mirror=new MirrorDriver(['writes'=>['disk-one', '', 'disk-two'], 'read'=>'disk-one'], $manager);
	$functions->put('stream', true);
	$t->isFalse($mirror->write('stream-failure.txt', Stream::fromString('body')));
	$functions->put('stream', false);
	$t->isTrue($mirror->exists('shared.txt'));
	$t->same('first', $mirror->read('shared.txt'));
	$t->isTrue(is_resource($mirror->readStream('shared.txt')));
	$t->isTrue($mirror->write('mirror.txt', Stream::fromString('mirror')));
	$t->same('mirror', $two->objects['mirror.txt']);
	$two->failWrites=['partial.txt'];
	$t->isFalse($mirror->write('partial.txt', 'partial'));
	$t->same('partial', $one->objects['partial.txt']);
	$two->failDeletes=['partial.txt'];
	$t->isFalse($mirror->delete('partial.txt'));
	$two->failDeletes=[];
	$t->isTrue($mirror->delete('mirror.txt'));
	$t->instanceOf(FileMetadata::class, $mirror->metadata('shared.txt'));
	$t->same(count($one->objects), count($mirror->list()));
	$t->same('fake://shared.txt', $mirror->temporaryUrl('shared.txt', time()+60));

	$t->throws(static fn()=>new FailoverDriver([], $manager), RuntimeException::class);
	$t->instanceOf(FailoverDriver::class, new FailoverDriver(['disks'=>['disk-one']], null));
	$failover=new FailoverDriver(['reads'=>['disk-three', '', 'disk-one', 'disk-two'], 'write'=>'disk-three'], $manager);
	$t->isTrue($failover->exists('shared.txt'));
	$t->isFalse($failover->exists('missing.txt'));
	$t->same('first', $failover->read('shared.txt'));
	$one->objects['empty.txt']='';
	$t->same('', $failover->read('empty.txt'));
	$t->same('fallback', $failover->read('fallback.txt'));
	$t->same(false, $failover->read('missing.txt'));
	$t->isTrue(is_resource($failover->readStream('fallback.txt')));
	$t->same(false, $failover->readStream('missing.txt'));
	$t->isTrue($failover->write('written.txt', 'written'));
	$t->same('written', $three->objects['written.txt']);
	$t->instanceOf(FileMetadata::class, $failover->metadata('shared.txt'));
	$t->same(false, $failover->metadata('missing.txt'));
	$one->listOverride=['invalid', new FileMetadata('same.txt'), new FileMetadata('one.txt')];
	$two->listOverride=[new FileMetadata('same.txt'), new FileMetadata('two.txt')];
	$three->listOverride=[];
	$t->same(['same.txt', 'one.txt', 'two.txt'], array_map(static fn(FileMetadata $m): string=>$m->path(), $failover->list()));
	$t->same('fake://fallback.txt', $failover->temporaryUrl('fallback.txt', time()+60));
	$t->same(false, $failover->temporaryUrl('missing.txt', time()+60));
	$one->deleteOk=false;
	$t->isFalse($failover->delete('shared.txt'));
	$t->isTrue(($two->calls['delete'] ?? 0)>0);
	$one->deleteOk=true;
	$t->isTrue($failover->delete('fallback.txt'));
})->tag('storage', 'remaining-drivers', 'topology', 'deep-coverage')->group('framework-coverage');

test('storage remaining drivers deep coverage exercises quota accounting replacements and limits', static function(Context $t): void {
	$target=new DpStorageRemainingFakeDriver();
	$target->objects=['scope/a.txt'=>'1234', 'outside.txt'=>'outside'];
	$functions=$t->state('storage.remaining-functions', ['stream'=>false]);
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['quota_target'=>$target]);
	$t->throws(static fn()=>new QuotaDriver([], $manager), RuntimeException::class);
	$t->instanceOf(QuotaDriver::class, new QuotaDriver(['target'=>'quota-target'], null));
	$quota=new QuotaDriver([
		'disk'=>'quota-target', 'prefix'=>'scope', 'max_bytes'=>10, 'max_objects'=>2,
	], $manager);
	$functions->put('stream', true);
	$t->isFalse($quota->write('scope/stream-failure.txt', Stream::fromString('body')));
	$functions->put('stream', false);
	$t->isTrue($quota->exists('scope/a.txt'));
	$t->same('1234', $quota->read('scope/a.txt'));
	$t->isTrue(is_resource($quota->readStream('scope/a.txt')));
	$t->isTrue($quota->write('outside/new.txt', 'outside-scope'));
	$t->isTrue($quota->write('scope/b.txt', Stream::fromString('123')));
	$t->isFalse($quota->write('scope/c.txt', '1'));
	$t->isTrue($quota->write('scope/a.txt', '12345'));
	$t->isFalse($quota->write('scope/a.txt', '12345678'));
	$target->writeOk=false;
	$t->isFalse($quota->write('outside/fail.txt', 'x'));
	$target->writeOk=true;
	$metadata=$quota->metadata('scope/a.txt');
	$t->instanceOf(FileMetadata::class, $metadata);
	$t->same('scope', $metadata->extra()['quota']['scope']);
	$target->metadataOk=false;
	$t->same(false, $quota->metadata('scope/a.txt'));
	$target->metadataOk=true;
	$target->listOverride=['invalid', new FileMetadata('scope/a.txt', null), new FileMetadata('scope/b.txt', 3)];
	$report=$quota->quotaReport('scope', ['probe'=>true]);
	$t->same(2, $report['objects']);
	$t->same(3, $report['bytes']);
	$t->same(7, $report['bytes_remaining']);
	$t->same(0, $report['objects_remaining']);
	$t->isFalse($t->nonPublic($quota)->invoke('withinQuota', 'scope/probe.txt', 1));
	$target->listOverride=null;
	$unlimited=new QuotaDriver(['disk'=>'quota-target', 'scope'=>'', 'max_bytes'=>0, 'max_objects'=>-1], $manager);
	$report=$unlimited->quotaReport();
	$t->same(null, $report['bytes_remaining']);
	$t->same(null, $report['objects_remaining']);
	$t->isTrue($report['ok']);
	$t->same(count($target->objects), count($quota->list()));
	$t->same('fake://scope/a.txt', $quota->temporaryUrl('scope/a.txt', time()+60));
	$t->isTrue($quota->delete('scope/b.txt'));
})->tag('storage', 'remaining-drivers', 'quota', 'deep-coverage')->group('framework-coverage');

test('storage remaining drivers deep coverage exercises ordered policy rules and diagnostics', static function(Context $t): void {
	$target=new DpStorageRemainingFakeDriver();
	$target->objects=['public/a.txt'=>'alpha', 'private/secret.txt'=>'secret', 'public/image.jpg'=>'jpg'];
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['policy_target'=>$target]);
	$t->throws(static fn()=>new PolicyDriver([], $manager), RuntimeException::class);
	$t->instanceOf(PolicyDriver::class, new PolicyDriver(['target'=>'policy-target', 'rules'=>'invalid'], null));
	$policy=new PolicyDriver([
		'disk'=>'policy-target', 'default_allow'=>false,
		'rules'=>[
			'invalid',
			['actions'=>['*'], 'prefix'=>'public', 'effect'=>'allow'],
			['action'=>'read', 'prefix'=>'public', 'extensions'=>['.txt'], 'actors'=>['alice'], 'type'=>'deny'],
			['actions'=>['read'], 'prefix'=>'public/a.txt', 'extensions'=>['txt'], 'actors'=>['alice'], 'effect'=>'allow'],
			['actions'=>['*'], 'prefix'=>'private', 'effect'=>'deny'],
		],
	], $manager);
	$t->isTrue($policy->exists('public/a.txt'));
	$t->isFalse($policy->exists('private/secret.txt'));
	$t->same('alpha', $policy->read('public/a.txt'));
	$t->same('alpha', $policy->read('public/a.txt', ['actor'=>'alice']));
	$t->same(false, $policy->read('private/secret.txt'));
	$t->isTrue(is_resource($policy->readStream('public/a.txt')));
	$t->same(false, $policy->readStream('private/secret.txt'));
	$t->isTrue($policy->write('public/new.txt', 'new'));
	$t->isFalse($policy->write('private/new.txt', 'new'));
	$t->isFalse($policy->delete('private/secret.txt'));
	$t->isTrue($policy->delete('public/new.txt'));
	$t->same(false, $policy->metadata('private/secret.txt'));
	$t->same(false, $policy->metadata('public/missing.txt'));
	$metadata=$policy->metadata('public/a.txt');
	$t->instanceOf(FileMetadata::class, $metadata);
	$t->same('policy-target', $metadata->extra()['policy']['disk']);
	$t->same([], $policy->list('private'));
	$t->same(2, count($policy->list('public')));
	$t->same(false, $policy->temporaryUrl('private/secret.txt', time()+60));
	$t->same('fake://public/a.txt', $policy->temporaryUrl('public/a.txt', time()+60));
	$report=$policy->policyReport('public/a');
	$t->isTrue($report['ok']);
	$t->isTrue(count($report['rules'])>=2);
	$t->same(0, count($policy->policyReport('unrelated')['rules']));

	$policyInternals=$t->nonPublic($policy);
	$t->isFalse($policyInternals->invoke('matches', ['actions'=>['write']], 'read', 'x.txt', []));
	$t->isFalse($policyInternals->invoke('matches', ['prefix'=>'docs'], 'read', 'other/a.txt', []));
	$t->isFalse($policyInternals->invoke('matches', ['extensions'=>['txt']], 'read', 'image.jpg', []));
	$t->isFalse($policyInternals->invoke('matches', ['actors'=>['alice']], 'read', 'a.txt', []));
	$t->isTrue($policyInternals->invoke('matches', [], 'read', 'a.txt', []));
	$t->same([], $policyInternals->invoke('normalizeRules', 'invalid'));
	$t->same([['x'=>1]], $policyInternals->invoke('normalizeRules', ['bad', ['x'=>1]]));
	$allowAll=new PolicyDriver(['disk'=>'policy-target', 'default_allow'=>true], $manager);
	$t->same('secret', $allowAll->read('private/secret.txt'));
})->tag('storage', 'remaining-drivers', 'policy', 'deep-coverage')->group('framework-coverage');

test('storage remaining drivers deep coverage exercises rate limit windows identities state and reset', static function(Context $t): void {
	$workspace=$t->workspace('storage-remaining-rate-limit');
	$state=$workspace->path('nested/state.json');
	$target=new DpStorageRemainingFakeDriver();
	$target->objects=['read.txt'=>'read'];
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['rate_target'=>$target]);
		$t->throws(static fn()=>new RateLimitedDriver([], $manager), RuntimeException::class);
		$t->instanceOf(RateLimitedDriver::class, new RateLimitedDriver(['target'=>'rate-target', 'limits'=>'invalid'], null));
		$unlimited=new RateLimitedDriver(['disk'=>'rate-target', 'state'=>$workspace->path('unlimited.json')], $manager);
		$t->same('read', $unlimited->read('read.txt'));
		$driver=new RateLimitedDriver([
			'disk'=>'rate-target', 'manifest'=>$state,
			'limits'=>[
				['action'=>'read', 'limit'=>1, 'window'=>60],
				'write'=>['max'=>1, 'per'=>'1 minute'],
				'delete'=>['limit'=>1, 'window'=>60],
				'metadata'=>['limit'=>1, 'window'=>60],
				'list'=>['limit'=>1, 'window'=>60],
				'temporary_url'=>['limit'=>1, 'window'=>60],
				'*'=>['limit'=>2, 'window'=>60],
				5=>'invalid',
			],
		], $manager);

		$t->isTrue($driver->exists('read.txt'));
		$t->isFalse($driver->exists('read.txt'));
		$t->same('read', $driver->read('read.txt', ['rate_limit_key'=>'reader']));
		$t->same(false, $driver->read('read.txt', ['rate_limit_key'=>'reader']));
		$t->isTrue(is_resource($driver->readStream('read.txt', ['actor'=>'streamer'])));
		$t->same(false, $driver->readStream('read.txt', ['actor'=>'streamer']));
		$t->isTrue($driver->write('write.txt', 'write', ['actor'=>'writer']));
		$t->isFalse($driver->write('write2.txt', 'write', ['actor'=>'writer']));
		$t->instanceOf(FileMetadata::class, $driver->metadata('read.txt'));
		$t->same(false, $driver->metadata('read.txt'));
		$t->same(2, count($driver->list('', ['actor'=>'lister'])));
		$t->same([], $driver->list('', ['actor'=>'lister']));
		$t->same('fake://read.txt', $driver->temporaryUrl('read.txt', time()+60, ['actor'=>'url'])) ;
		$t->same(false, $driver->temporaryUrl('read.txt', time()+60, ['actor'=>'url']));
		$t->isTrue($driver->delete('write.txt'));
		$t->isFalse($driver->delete('read.txt'));

		$report=$driver->rateLimitReport('ignored', ['ignored'=>true]);
		$t->isTrue($report['ok']);
		$t->notEmpty($report['buckets']);
		$internals=$t->nonPublic($driver);
		$t->same('read:key', $internals->invoke('bucketKey', 'read', ['rate_limit_key'=>'key', 'actor'=>'actor']));
		$t->same('read:actor', $internals->invoke('bucketKey', 'read', ['actor'=>'actor']));
		$server=$t->globalMap('_SERVER')->put('REMOTE_ADDR', '127.0.0.9');
		$t->same('read:127.0.0.9', $internals->invoke('bucketKey', 'read', []));
		$server->forget('REMOTE_ADDR');
		$t->same('read:global', $internals->invoke('bucketKey', 'read', []));
		$t->same([], $internals->invoke('normalizeLimits', 'invalid'));
		$t->same(5, $internals->invoke('durationSeconds', 5));
		$t->same(7, $internals->invoke('durationSeconds', '7'));
		$t->isTrue($internals->invoke('durationSeconds', '1 hour')>0);
		$t->same(60, $internals->invoke('durationSeconds', 'bad-duration-value'));
		$t->same(60, $internals->invoke('durationSeconds', []));

		$workspace->file('nested/state.json', (string)json_encode(['read:expired'=>['count'=>99, 'reset_at'=>time()-1]]));
		$t->same('read', $driver->read('read.txt', ['rate_limit_key'=>'expired']));
		$workspace->file('nested/state.json', '{invalid');
		$t->same([], $driver->rateLimitReport()['buckets']);
		$t->same(['ok'=>true, 'reset'=>true], $driver->resetRateLimits());
		$t->same([], $driver->rateLimitReport()['buckets']);
})->tag('storage', 'remaining-drivers', 'rate-limit', 'deep-coverage')->group('framework-coverage');

test('storage remaining drivers deep coverage exercises audit and event dispatch trails', static function(Context $t): void {
	$workspace=$t->workspace('storage-remaining-events');
	$auditLog=$workspace->path('audit/nested/audit.jsonl');
	$eventLog=$workspace->path('events/nested/events.jsonl');
	$target=new DpStorageRemainingFakeDriver();
	$target->objects=['a.txt'=>'alpha'];
	$manager=new StorageManager();
	$t->nonPublic($manager)->writeProperty('disks', ['event_target'=>$target]);
		$t->throws(static fn()=>new AuditDriver([], $manager), RuntimeException::class);
		$t->instanceOf(AuditDriver::class, new AuditDriver(['target'=>'event-target'], null));
		$audit=new AuditDriver(['disk'=>'event-target', 'log'=>$auditLog], $manager);
		$t->same([], $audit->auditTrail());
		$auditInternals=$t->nonPublic($audit);
		$t->same(null, $auditInternals->invoke('actor'));
		$t->same(null, $auditInternals->invoke('requestId'));
		define('ACCOUNT_ID', 'account-7');
		$t->globalMap('_SERVER')->merge([
			'HTTP_X_REQUEST_ID'=>' ',
			'REQUEST_ID'=>'request-8',
			'REMOTE_ADDR'=>'127.0.0.1',
		]);
		$t->isTrue($audit->exists('a.txt'));
		$t->same('alpha', $audit->read('/a.txt', ['reason'=>'inspect']));
		$t->same(false, $audit->read('missing.txt'));
		$t->isTrue(is_resource($audit->readStream('a.txt', ['actor'=>'override'])));
		$t->same(false, $audit->readStream('missing.txt'));
		$t->isTrue($audit->write('b.txt', 'beta', ['request_id'=>'override-request', 'ip'=>'10.0.0.1']));
		$target->writeOk=false;
		$t->isFalse($audit->write('fail.txt', 'fail'));
		$target->writeOk=true;
		$t->instanceOf(FileMetadata::class, $audit->metadata('a.txt'));
		$t->same(false, $audit->metadata('missing.txt'));
		$t->same(2, count($audit->list('', ['reason'=>'list'])));
		$t->same('fake://a.txt', $audit->temporaryUrl('a.txt', time()+60));
		$target->urlOk=false;
		$t->same(false, $audit->temporaryUrl('a.txt', time()+60));
		$target->urlOk=true;
		$target->deleteOk=false;
		$t->isFalse($audit->delete('a.txt'));
		$target->deleteOk=true;
		$t->isTrue($audit->delete('b.txt'));
		$workspace->file(
			'audit/nested/audit.jsonl',
			(string)file_get_contents($auditLog)."\nnot-json\n".json_encode(['path'=>'other.txt', 'operation'=>'read'])."\n",
		);
		$t->same(1, count($audit->auditTrail('a.txt', ['operation'=>'read', 'limit'=>0])));
		$t->same([], $audit->auditTrail('a.txt', ['operation'=>'nope']));
		$t->isTrue(count($audit->auditTrail(null, ['limit'=>10000]))>=10);

		$t->throws(static fn()=>new EventedDriver([], $manager), RuntimeException::class);
		$t->instanceOf(EventedDriver::class, new EventedDriver(['target'=>'event-target', 'listeners'=>'invalid'], null));
		$events=$t->state('storage.remaining-events', ['named'=>[], 'closure'=>[], 'manager'=>[]]);
		$manager->listen('storage.write', static function(array $event) use ($events): void { $events->append('manager', $event['event']); });
		$manager->listen('*', static function(array $event) use ($events): void { $events->append('manager', 'wild:'.$event['event']); });
		$evented=new EventedDriver([
			'disk'=>'event-target', 'log'=>$eventLog,
			'listeners'=>[
				'write'=>['dp_storage_remaining_named_listener', static function(array $event) use ($events): void { $events->append('closure', $event['event']); }, 'missing_listener'],
				'*'=>[static function(array $event) use ($events): void { $events->append('closure', 'wild:'.$event['event']); }],
			],
		], $manager);
		$t->same([], $evented->eventTrail());
		$t->isTrue($evented->exists('a.txt'));
		$t->same('alpha', $evented->read('a.txt', ['read'=>true]));
		$t->same(false, $evented->read('missing.txt'));
		$t->isTrue(is_resource($evented->readStream('a.txt')));
		$t->same(false, $evented->readStream('missing.txt'));
		$t->isTrue($evented->write('event.txt', 'event', ['write'=>true]));
		$target->writeOk=false;
		$t->isFalse($evented->write('event-fail.txt', 'event'));
		$target->writeOk=true;
		$t->instanceOf(FileMetadata::class, $evented->metadata('a.txt'));
		$t->same(2, count($evented->list('', ['list'=>true])));
		$t->same('fake://a.txt', $evented->temporaryUrl('a.txt', time()+60));
		$target->urlOk=false;
		$t->same(false, $evented->temporaryUrl('a.txt', time()+60));
		$target->urlOk=true;
		$target->deleteOk=false;
		$t->isFalse($evented->delete('event.txt'));
		$target->deleteOk=true;
		$t->isTrue($evented->delete('event.txt'));
		$t->contains('write', $events->get('named'));
		$t->contains('write', $events->get('closure'));
		$t->contains('wild:before_write', $events->get('closure'));
		$t->contains('storage.write', $events->get('manager'));
		$workspace->file(
			'events/nested/events.jsonl',
			(string)file_get_contents($eventLog)."\ninvalid\n".json_encode(['path'=>'other.txt'])."\n",
		);
		$t->same(1, count($evented->eventTrail('a.txt', ['limit'=>0])));
		$t->isTrue(count($evented->eventTrail('missing.txt'))>=2);
		$noLog=new EventedDriver(['disk'=>'event-target'], $manager);
		$t->same([], $noLog->eventTrail());
})->tag('storage', 'remaining-drivers', 'audit', 'evented', 'deep-coverage')->group('framework-coverage');
