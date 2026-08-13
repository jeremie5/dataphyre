<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelLocalMediaDisk;
use Dataphyre\Panel\PanelMediaCleanupPolicy;
use Dataphyre\Panel\PanelMediaDisk;
use Dataphyre\Panel\PanelMediaManager;
use Dataphyre\Panel\PanelMediaProcessingPipeline;
use Dataphyre\Panel\PanelMediaScanner;
use Dataphyre\Panel\PanelMediaTransformer;
use Dataphyre\Panel\PanelResumableUploadSession;
use Dataphyre\Panel\PanelSignedMediaDelivery;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** Focused disk decorator for storage-neutral failure contracts. */
final class DpPanelMediaDiskProbe implements PanelMediaDisk {
	public function __construct(private PanelMediaDisk $disk,private string $failure='') {}
	public function name(): string{return $this->disk->name();}
	public function normalizePath(string $path): string{return $this->disk->normalizePath($path);}
	public function write(string $path,string $contents,array $options=[]): array{return $this->disk->write($path,$contents,$options);}
	public function writeStream(string $path,mixed $stream,array $options=[]): array{
		$result=$this->disk->writeStream($path,$stream,$options);
		if($this->failure==='wrong_write_size'){$result['size']=(int)$result['size']+1;}
		return $result;
	}
	public function read(string $path,int $maxBytes=0): string{return $this->disk->read($path,$maxBytes);}
	public function readStream(string $path): mixed{return $this->disk->readStream($path);}
	public function exists(string $path): bool{return $this->disk->exists($path);}
	public function delete(string $path): bool{if($this->failure==='delete'){throw new RuntimeException('injected delete failure');}return $this->disk->delete($path);}
	public function move(string $from,string $to,bool $overwrite=false): array{return $this->disk->move($from,$to,$overwrite);}
	public function copy(string $from,string $to,bool $overwrite=false): array{return $this->disk->copy($from,$to,$overwrite);}
	public function size(string $path): int{return $this->disk->size($path);}
	public function checksum(string $path,string $algorithm='sha256'): string{return $this->disk->checksum($path,$algorithm);}
	public function modifiedAt(string $path): int{return $this->disk->modifiedAt($path);}
	public function list(string $prefix='',bool $recursive=true): array{return $this->disk->list($prefix,$recursive);}
	public function descriptor(string $path): array{return $this->disk->descriptor($path);}
	public function manifest(): array{return $this->disk->manifest();}
}

final class DpPanelCleanMediaScanner implements PanelMediaScanner {
	public function scan(PanelMediaDisk $disk,string $path,array $context=[]): array{return ['clean'=>true,'status'=>'clean'];}
}
final class DpPanelVariantMediaTransformer implements PanelMediaTransformer {
	public function transform(PanelMediaDisk $disk,string $path,array $variants=[],array $context=[]): array{
		$disk->write('variants/interface.txt','variant');
		return ['variants'=>['interface'=>'variants/interface.txt','ignored'=>false]];
	}
}

function dp_panel_define_media_io_symbols(Context $t): void {
	if(function_exists('Dataphyre\\Panel\\dp_panel_media_io_failure')){return;}
	$t->defineSymbols(<<<'PHP'
namespace Dataphyre\Panel;
function dp_panel_media_io_state(): ?\Dataphyre\Test\TestState {
	return \Dataphyre\Test\TestState::channelIfActive('panel.media.io');
}
function dp_panel_media_io_failure(): string {
	return (string)(dp_panel_media_io_state()?->get('failure','') ?? '');
}
function is_link(string $filename): bool {
	$failure=dp_panel_media_io_failure();
	if($failure==='root_symlink'){return true;}
	if($failure==='path_symlink' && str_contains($filename,'path-symlink')){return true;}
	if($failure==='target_symlink' && str_ends_with($filename,'target-link.txt')){
		return dp_panel_media_io_state()?->increment('target_link_calls')>1;
	}
	if($failure==='directory_symlink' && str_contains($filename,'symlink-parent')){
		return dp_panel_media_io_state()?->increment('directory_link_calls')>2;
	}
	return \is_link($filename);
}
function is_writable(string $filename): bool {
	if(dp_panel_media_io_failure()==='not_writable'){return false;}
	return \is_writable($filename);
}
function file_exists(string $filename): bool {
	if(dp_panel_media_io_failure()==='parent_race' && str_ends_with($filename,'race-parent')){
		return dp_panel_media_io_state()?->increment('parent_exists_calls')>1;
	}
	return \file_exists($filename);
}
function mkdir(string $directory,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {
	if(dp_panel_media_io_failure()==='mkdir' && str_contains($directory,'mkdir-parent')){return false;}
	return $context===null ? \mkdir($directory,$permissions,$recursive) : \mkdir($directory,$permissions,$recursive,$context);
}
function fopen(string $filename,string $mode,bool $use_include_path=false,mixed $context=null): mixed {
	$failure=dp_panel_media_io_failure();
	if($failure==='write_alloc' && str_starts_with($filename,'php://temp/maxmemory:2097152')){return false;}
	if($failure==='chunk_alloc' && str_starts_with($filename,'php://temp/maxmemory:2097152')){return false;}
	if($failure==='assembly_alloc' && str_starts_with($filename,'php://temp/maxmemory:8388608')){return false;}
	if($failure==='buffer_alloc' && str_starts_with($filename,'php://temp/maxmemory:8388608')){return false;}
	if($failure==='temp_open' && $mode==='xb' && str_ends_with($filename,'.tmp')){return false;}
	if($failure==='read_open' && $mode==='rb'){return false;}
	if($failure==='lock_open' && str_ends_with($filename,'.panel-media.lock')){return false;}
	return $context===null ? \fopen($filename,$mode,$use_include_path) : \fopen($filename,$mode,$use_include_path,$context);
}
function flock(mixed $stream,int $operation,?int &$would_block=null): bool {
	if(dp_panel_media_io_failure()==='lock'){return false;}
	return \flock($stream,$operation,$would_block);
}
function fread(mixed $stream,int $length): string|false {
	$failure=dp_panel_media_io_failure();
	if(in_array($failure,['source_read','upload_read'],true)){return false;}
	if($failure==='source_empty' && dp_panel_media_io_state()?->increment('empty_reads')===1){return '';}
	return \fread($stream,$length);
}
function fwrite(mixed $stream,string $data,?int $length=null): int|false {
	if(in_array(dp_panel_media_io_failure(),['disk_write','upload_buffer_write'],true)){return 0;}
	return $length===null ? \fwrite($stream,$data) : \fwrite($stream,$data,$length);
}
function fflush(mixed $stream): bool {
	if(dp_panel_media_io_failure()==='flush'){return false;}
	return \fflush($stream);
}
function file_get_contents(string $filename,bool $use_include_path=false,mixed $context=null,int $offset=0,?int $length=null): string|false {
	if(dp_panel_media_io_failure()==='read'){return false;}
	if($length===null){return $context===null ? \file_get_contents($filename,$use_include_path,null,$offset) : \file_get_contents($filename,$use_include_path,$context,$offset);}
	return $context===null ? \file_get_contents($filename,$use_include_path,null,$offset,$length) : \file_get_contents($filename,$use_include_path,$context,$offset,$length);
}
function unlink(string $filename,mixed $context=null): bool {
	if(dp_panel_media_io_failure()==='delete' && str_ends_with($filename,'delete-failure.txt')){return false;}
	return $context===null ? \unlink($filename) : \unlink($filename,$context);
}
function rename(string $from,string $to,mixed $context=null): bool {
	$failure=dp_panel_media_io_failure();
	if(in_array($failure,['move_stage','replace_stage'],true) && dp_panel_media_io_state()?->increment($failure.'_rename_calls')===1){return false;}
	if(in_array($failure,['move_commit','replace_commit'],true) && dp_panel_media_io_state()?->increment($failure.'_rename_calls')===2){return false;}
	return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
}
function copy(string $from,string $to,mixed $context=null): bool {
	if(dp_panel_media_io_failure()==='copy'){return false;}
	return $context===null ? \copy($from,$to) : \copy($from,$to,$context);
}
function hash_file(string $algorithm,string $filename,bool $binary=false): string|false {
	if(dp_panel_media_io_failure()==='hash'){return false;}
	return \hash_file($algorithm,$filename,$binary);
}
function stream_copy_to_stream(mixed $from,mixed $to,?int $length=null,int $offset=0): int|false {
	if(dp_panel_media_io_failure()==='stream_copy'){return 0;}
	return $length===null ? \stream_copy_to_stream($from,$to) : \stream_copy_to_stream($from,$to,$length,$offset);
}
PHP);
}

suite('Panel media storage and processing lifecycle')
	->contract('panel.media-lifecycle', 1)
	->layer('integration')
	->risk('high')
	->watches('module:panel')
	->through('upload-session', 'media-disk', 'scanner', 'transformer', 'delivery')
	->isolation('case')
	->tag('panel', 'media')
	->group('framework-coverage');

test('local media storage exposes descriptors path rules moves copies reads and deletion contracts', static function(Context $t): void {
	$t->throws(static fn()=>new PanelLocalMediaDisk(' '),InvalidArgumentException::class);
	$blocked=$t->tempDirectory('media-blocked-root').DIRECTORY_SEPARATOR.'file';
	file_put_contents($blocked,'block');
	$t->throws(static fn()=>new PanelLocalMediaDisk($blocked.DIRECTORY_SEPARATOR.'child'),RuntimeException::class);

	$root=$t->tempDirectory('media-disk-contract');
	$disk=new PanelLocalMediaDisk($root,'Contract Disk',16);
	$t->same((string)realpath($root),$disk->root());
	$t->throws(static fn()=>$disk->writeStream('invalid.txt','not-a-stream'),InvalidArgumentException::class);
	$stream=fopen('php://temp','r+');
	try{$t->throws(static fn()=>$disk->writeStream('invalid.txt',$stream,['checksum'=>'bad']),InvalidArgumentException::class);}
	finally{fclose($stream);}
	$disk->write('folder/source.txt','source');
	$t->same(6,$disk->size('folder/source.txt'));
	$t->isTrue($disk->modifiedAt('folder/source.txt')>0);
	$t->same($disk->descriptor('folder/source.txt'),$disk->move('folder/source.txt','folder/source.txt'));
	$t->throws(static fn()=>$disk->read('missing.txt'),RuntimeException::class);
	$t->throws(static fn()=>$disk->read('folder/source.txt',2),LengthException::class);
	$t->throws(static fn()=>$disk->readStream('missing.txt'),RuntimeException::class);
	$t->isFalse($disk->exists("bad\npath"));
	$t->throws(static fn()=>$disk->normalizePath("bad\npath"),InvalidArgumentException::class);
	$t->throws(static fn()=>$disk->normalizePath('./.'),InvalidArgumentException::class);
	$t->same('folder/source.txt',$disk->normalizePath('./folder//source.txt'));

	mkdir($root.DIRECTORY_SEPARATOR.'directory-only');
	$t->throws(static fn()=>$disk->delete('directory-only'),RuntimeException::class);
	$t->throws(static fn()=>$disk->move('missing.txt','moved.txt'),RuntimeException::class);
	$disk->write('folder/target.txt','target');
	$t->throws(static fn()=>$disk->move('folder/source.txt','folder/target.txt'),RuntimeException::class);
	$t->throws(static fn()=>$disk->copy('missing.txt','copy.txt'),RuntimeException::class);
	$t->throws(static fn()=>$disk->copy('folder/source.txt','folder/target.txt'),RuntimeException::class);
	$t->throws(static fn()=>$disk->checksum('folder/source.txt','not-a-hash'),InvalidArgumentException::class);
	$t->throws(static fn()=>$disk->checksum('missing.txt'),RuntimeException::class);
	$t->same([],$disk->list('missing-prefix'));
	$t->same(['folder/source.txt','folder/target.txt'],array_column($disk->list('folder',false),'path'));
	$t->throws(static fn()=>$disk->descriptor('missing.txt'),RuntimeException::class);
	$t->same($disk->manifest(),$disk->jsonSerialize());
})->tag('panel','media','disk','coverage')->group('panel-lane-c');

test('resumable uploads reject invalid sessions chunks manifests expiry and incomplete assembly', static function(Context $t): void {
	$disk=new PanelLocalMediaDisk($t->tempDirectory('media-upload-validation'));
	$t->throws(static fn()=>PanelResumableUploadSession::start($disk,'.panel_uploads/internal.bin',1,1024,null,[],'internal01'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelResumableUploadSession::start($disk,'target.bin',0,1024,null,[],'zero0000'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelResumableUploadSession::start($disk,'target.bin',1,100,null,[],'chunks001'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelResumableUploadSession::start($disk,'target.bin',1,1024,'bad',[],'check001'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelResumableUploadSession::start($disk,'target.bin',1,1024,null,[],'short'),InvalidArgumentException::class);
	$session=PanelResumableUploadSession::start($disk,'target.bin',1024,1024,null,[],'session-validation');
	$t->throws(static fn()=>PanelResumableUploadSession::start($disk,'other.bin',1024,1024,null,[],'session-validation'),RuntimeException::class);
	$t->throws(static fn()=>PanelResumableUploadSession::resume($disk,'bad'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelResumableUploadSession::resume($disk,'missing-session'),RuntimeException::class);
	$t->throws(static fn()=>$session->receiveChunkStream(0,'invalid',1024),InvalidArgumentException::class);
	$empty=fopen('php://temp','r+');
	try{
		$t->throws(static fn()=>$session->receiveChunkStream(1,$empty,1024),OutOfRangeException::class);
		$t->throws(static fn()=>$session->receiveChunkStream(0,$empty,1024,null,1),UnexpectedValueException::class);
		$t->throws(static fn()=>$session->receiveChunkStream(0,$empty,1),UnexpectedValueException::class);
		$t->throws(static fn()=>$session->receiveChunkStream(0,$empty,1024,'bad'),InvalidArgumentException::class);
		$t->throws(static fn()=>$session->receiveChunkStream(0,$empty,1024),UnexpectedValueException::class);
	}finally{fclose($empty);}
	$t->throws(static fn()=>$session->receiveChunk(0,str_repeat('x',1024),str_repeat('0',64)),UnexpectedValueException::class);
	$t->throws(static fn()=>$session->assemble(),RuntimeException::class);
	$t->same($session->manifest(),$session->jsonSerialize());

	$disk->write('.panel_uploads/corrupt01/manifest.json','{');
	$t->throws(static fn()=>PanelResumableUploadSession::resume($disk,'corrupt01'),RuntimeException::class);
	$disk->write('.panel_uploads/mismatch1/manifest.json',json_encode(['version'=>1,'id'=>'other','disk'=>$disk->name()],JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>PanelResumableUploadSession::resume($disk,'mismatch1'),RuntimeException::class);

	$expired=PanelResumableUploadSession::start($disk,'expired.bin',1024,1024,null,[],'expired-session');
	$manifestPath='.panel_uploads/expired-session/manifest.json';
	$manifest=json_decode($disk->read($manifestPath),true,128,JSON_THROW_ON_ERROR);
	$manifest['expires_at']='2000-01-01T00:00:00Z';
	$disk->write($manifestPath,json_encode($manifest,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>PanelResumableUploadSession::resume($disk,'expired-session')->receiveChunk(0,str_repeat('x',1024)),RuntimeException::class);
})->tag('panel','media','upload','validation','coverage')->group('panel-lane-c');

test('resumable uploads reject bad whole files existing targets and storage size discrepancies', static function(Context $t): void {
	$local=new PanelLocalMediaDisk($t->tempDirectory('media-upload-assembly'));
	$badChecksum=PanelResumableUploadSession::start($local,'bad-checksum.bin',1024,1024,str_repeat('0',64),[],'bad-checksum-session');
	$badChecksum->receiveChunk(0,str_repeat('x',1024));
	$t->throws(static fn()=>$badChecksum->assemble(),UnexpectedValueException::class);

	$existing=PanelResumableUploadSession::start($local,'existing.bin',1024,1024,null,[],'existing-target-session');
	$existing->receiveChunk(0,str_repeat('a',1024));
	$local->write('existing.bin',str_repeat('b',1024));
	$t->throws(static fn()=>$existing->assemble(),RuntimeException::class);

	$wrongDisk=new DpPanelMediaDiskProbe(new PanelLocalMediaDisk($t->tempDirectory('media-upload-wrong-size')),'wrong_write_size');
	$wrong=PanelResumableUploadSession::start($wrongDisk,'wrong.bin',1024,1024,null,[],'wrong-size-session');
	$wrong->receiveChunk(0,str_repeat('z',1024));
	$t->throws(static fn()=>$wrong->assemble(),UnexpectedValueException::class);
	$t->isFalse($wrongDisk->exists('wrong.bin'));
})->tag('panel','media','upload','assembly','coverage')->group('panel-lane-c');

test('media processing supports interface plugins string variants errors quarantine collisions images and open mode', static function(Context $t): void {
	$disk=new PanelLocalMediaDisk($t->tempDirectory('media-processing-closure'));
	$disk->write('source.txt','source');
	$pipeline=(new PanelMediaProcessingPipeline($disk))
		->scanner(new DpPanelCleanMediaScanner())
		->transformer(new DpPanelVariantMediaTransformer());
	$processed=$pipeline->process('source.txt');
	$t->isTrue($processed['ok']);
	$t->same('variants/interface.txt',$processed['variants']['interface']['path']);
	$t->same($pipeline->manifest(),$pipeline->jsonSerialize());

	$disk->write('invalid-transform.txt','source');
	$failed=(new PanelMediaProcessingPipeline($disk))->transformer(static fn()=>false,'invalid')->process('invalid-transform.txt');
	$t->same('transformation_failed',$failed['status']);
	$disk->write('missing-transform.txt','source');
	$missing=(new PanelMediaProcessingPipeline($disk))->transformer(static fn()=>['variants'=>['missing'=>'variants/missing.txt']],'missing')->process('missing-transform.txt');
	$t->same('transformation_failed',$missing['status']);
	$disk->write('open-transform.txt','source');
	$open=(new PanelMediaProcessingPipeline($disk,false))->transformer(static function(): never{throw new RuntimeException('optional transform failed');},'optional')->process('open-transform.txt');
	$t->isTrue($open['ok']);

	$disk->write('scanner-error.txt','source');
	$scanFailed=(new PanelMediaProcessingPipeline($disk))->scanner(static fn()=>false,'invalid')->process('scanner-error.txt');
	$t->same('rejected',$scanFailed['status']);
	$disk->write('scanner-open.txt','source');
	$scanOpen=(new PanelMediaProcessingPipeline($disk,false,null))->scanner(static function(): never{throw new RuntimeException('scanner unavailable');},'optional')->process('scanner-open.txt');
	$t->isTrue($scanOpen['ok']);

	$disk->write('collision.txt','infected');
	$source=$disk->descriptor('collision.txt');
	$collision='.panel-quarantine/'.gmdate('Y/m/d').'/'.substr($source['checksum'],0,16).'-collision.txt';
	$disk->write($collision,'occupied');
	$quarantined=(new PanelMediaProcessingPipeline($disk))->scanner(static fn()=>['clean'=>false],'reject')->process('collision.txt');
	$t->contains('-collision.txt',$quarantined['quarantine']['path']);

	$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nXcAAAAASUVORK5CYII=',true);
	$disk->write('pixel.png',(string)$png);
	$image=(new PanelMediaProcessingPipeline($disk))->process('pixel.png',[],['unsafe'=>fopen('php://temp','r+')]);
	$t->same(1,$image['metadata']['width']);
	$t->same([],$image['metadata']['context']);
})->tag('panel','media','processing','coverage')->group('panel-lane-c');

test('media cleanup ignores invalid references and distinguishes grace policy denial errors and deletion failures', static function(Context $t): void {
	$disk=new PanelLocalMediaDisk($t->tempDirectory('media-cleanup-closure'));
	$disk->write('fresh.txt','fresh');
	$grace=(new PanelMediaCleanupPolicy(3600))->plan($disk,["bad\npath"],'',time());
	$t->same('grace_period',$grace['protected'][0]['reason']);
	$t->same((new PanelMediaCleanupPolicy())->manifest(),(new PanelMediaCleanupPolicy())->jsonSerialize());

	$disk->write('throw.txt','throw');
	$policyError=(new PanelMediaCleanupPolicy(0))->authorizeUsing(static function(): never{throw new RuntimeException('policy failed');})->execute($disk,[],'',false,time()+1);
	$t->same('policy_error',$policyError['denied'][0]['reason']);
	$denied=(new PanelMediaCleanupPolicy(0))->authorizeUsing(static fn()=>false)->execute($disk,[],'',false,time()+1);
	$t->same('policy_denied',$denied['denied'][0]['reason']);
	$failed=(new PanelMediaCleanupPolicy(0))->execute(new DpPanelMediaDiskProbe($disk,'delete'),[],'',false,time()+1);
	$t->same('injected delete failure',$failed['failed'][0]['error']);
})->tag('panel','media','cleanup','coverage')->group('panel-lane-c');

test('signed media delivery validates configuration token payload disk ownership disappearance and serialization', static function(Context $t): void {
	$disk=new PanelLocalMediaDisk($t->tempDirectory('media-signing-closure'),'signed-disk');
	$t->throws(static fn()=>new PanelSignedMediaDelivery($disk,'short'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSignedMediaDelivery($disk,str_repeat('s',32),'ftp://invalid'),InvalidArgumentException::class);
	$secret=str_repeat('s',32);
	$delivery=new PanelSignedMediaDelivery($disk,$secret);
	$t->throws(static fn()=>$delivery->verify('malformed'),UnexpectedValueException::class);
	$token=static function(string $payload)use($secret): string{
		$encoded=rtrim(strtr(base64_encode($payload),'+/','-_'),'=');
		$signature=rtrim(strtr(base64_encode(hash_hmac('sha256',$encoded,$secret,true)),'+/','-_'),'=');
		return $encoded.'.'.$signature;
	};
	$t->throws(static fn()=>$delivery->verify($token('not-json')),UnexpectedValueException::class);
	$t->throws(static fn()=>$delivery->verify($token(json_encode(['v'=>1,'disk'=>'other'],JSON_THROW_ON_ERROR))),UnexpectedValueException::class);
	$invalidEncoded='%';
	$invalidSignature=rtrim(strtr(base64_encode(hash_hmac('sha256',$invalidEncoded,$secret,true)),'+/','-_'),'=');
	$t->throws(static fn()=>$delivery->verify($invalidEncoded.'.'.$invalidSignature),UnexpectedValueException::class);
	$disk->write('gone.txt','gone');
	$issued=$delivery->issue('gone.txt');
	$disk->delete('gone.txt');
	$t->throws(static fn()=>$delivery->verify($issued['token']),RuntimeException::class);
	$t->same($delivery->manifest(),$delivery->jsonSerialize());
})->tag('panel','media','delivery','coverage')->group('panel-lane-c');

test('media manager chains plugins cancels uploads cleans catalog references and describes itself', static function(Context $t): void {
	$root=$t->tempDirectory('media-manager-closure');
	$manager=PanelMediaManager::local($root,str_repeat('m',32),['cleanup_grace'=>0]);
	$t->same($manager,$manager->scanner(static fn()=>['clean'=>true],'clean'));
	$t->same($manager,$manager->transformer(static fn()=>['variants'=>[]],'none'));
	$upload=$manager->startUpload('cancel.bin',1024,['chunk_size'=>1024,'id'=>'manager-cancel-session']);
	$t->same('manager-cancel-session',$upload['id']);
	$t->isTrue($manager->cancelUpload('manager-cancel-session'));
	$t->same('panel_media_cleanup_result',$manager->cleanup()['type']);
	$t->same($manager->manifest(),$manager->jsonSerialize());

	$disk=new PanelLocalMediaDisk($t->tempDirectory('media-manager-unconfigured'));
	$plain=new PanelMediaManager($disk,new PanelMediaProcessingPipeline($disk),new PanelAtomicSnapshotStore($t->tempDirectory('media-manager-catalog'),'media.manager',['items'=>[],'uploads'=>[]]));
	$t->throws(static fn()=>$plain->cleanup(),LogicException::class);
})->tag('panel','media','manager','coverage')->group('panel-lane-c');

test('local media storage fault scenarios preserve atomic files and explain every filesystem failure', static function(Context $t): void {
	$io=$t->state('panel.media.io',['failure'=>'','target_link_calls'=>0,'directory_link_calls'=>0,'parent_exists_calls'=>0,'empty_reads'=>0]);
	dp_panel_define_media_io_symbols($t);
	$root=$t->tempDirectory('media-disk-faults');
	$io->put('failure','root_symlink');
	$t->throws(static fn()=>new PanelLocalMediaDisk($root),RuntimeException::class);
	$io->put('failure','not_writable');
	$t->throws(static fn()=>new PanelLocalMediaDisk($root),RuntimeException::class);
	$io->put('failure','');
	$disk=new PanelLocalMediaDisk($root,'fault-disk',8);
	$t->same($disk->manifest(),$disk->jsonSerialize());

	$io->put('failure','write_alloc');
	$t->throws(static fn()=>$disk->write('write-alloc.txt','x'),RuntimeException::class);
	$io->put('failure','target_symlink');
	$t->throws(static fn()=>$disk->write('target-link.txt','x'),RuntimeException::class);
	$io->put('failure','temp_open');
	$t->throws(static fn()=>$disk->write('temp-open.txt','x'),RuntimeException::class);
	$io->put('failure','');
	$source=fopen('php://temp','r+');fwrite($source,'123456789');rewind($source);
	$t->throws(static fn()=>$disk->writeStream('too-large.txt',$source,['max_bytes'=>8]),LengthException::class);fclose($source);
	foreach(['source_read','disk_write','flush'] as $failure){
		$source=fopen('php://temp','r+');fwrite($source,'body');rewind($source);
		$io->put('failure',$failure);
		$t->throws(static fn()=>$disk->writeStream($failure.'.txt',$source),RuntimeException::class);
		fclose($source);
	}
	$source=fopen('php://temp','r+');fwrite($source,'body');rewind($source);
	$io->put('failure','source_empty');
	$t->same('body',$disk->read($disk->writeStream('source-empty.txt',$source)['path']));
	fclose($source);
	$io->put('failure','');
	$disk->write('read-failure.txt','body');
	$io->put('failure','read');$t->throws(static fn()=>$disk->read('read-failure.txt'),RuntimeException::class);
	$io->put('failure','read_open');$t->throws(static fn()=>$disk->readStream('read-failure.txt'),RuntimeException::class);
	$io->put('failure','');$disk->write('delete-failure.txt','body');
	$io->put('failure','delete');$t->throws(static fn()=>$disk->delete('delete-failure.txt'),RuntimeException::class);

	$io->put('failure','');$disk->write('move-source.txt','source');$disk->write('move-target.txt','target');
	$io->put('failure','move_stage');$t->throws(static fn()=>$disk->move('move-source.txt','move-target.txt',true),RuntimeException::class);
	$io->put('failure','');$disk->write('move-source.txt','source');
	$io->put('failure','move_commit');$t->throws(static fn()=>$disk->move('move-source.txt','move-target.txt',true),RuntimeException::class);
	$io->put('failure','');$disk->write('copy-source.txt','source');
	$io->put('failure','copy');$t->throws(static fn()=>$disk->copy('copy-source.txt','copy-target.txt'),RuntimeException::class);
	$io->put('failure','hash');$t->throws(static fn()=>$disk->checksum('copy-source.txt'),RuntimeException::class);

	$io->put('failure','path_symlink');
	$t->throws(static fn()=>$disk->read('path-symlink/file.txt'),RuntimeException::class);
	$io->put('failure','');$disk->write('parent-file','parent');
	$t->throws(static fn()=>$disk->write('parent-file/child.txt','child'),RuntimeException::class);
	file_put_contents($root.DIRECTORY_SEPARATOR.'race-parent','parent');
	$io->put('failure','parent_race');$t->throws(static fn()=>$disk->write('race-parent/child.txt','child'),RuntimeException::class);
	$io->put('failure','directory_symlink');$t->throws(static fn()=>$disk->write('symlink-parent/child.txt','child'),RuntimeException::class);
	$io->put('failure','mkdir');$t->throws(static fn()=>$disk->write('mkdir-parent/child.txt','child'),RuntimeException::class);

	$io->put('failure','');mkdir($root.DIRECTORY_SEPARATOR.'replace-directory');
	$t->throws(static fn()=>$disk->write('replace-directory','body'),RuntimeException::class);
	$disk->write('replace-stage.txt','old');
	$io->put('failure','replace_stage');$t->throws(static fn()=>$disk->write('replace-stage.txt','new'),RuntimeException::class);
	$io->put('failure','');$disk->write('replace-commit.txt','old');
	$io->put('failure','replace_commit');$t->throws(static fn()=>$disk->write('replace-commit.txt','new'),RuntimeException::class);

	$io->put('failure','lock_open');$t->throws(static fn()=>$disk->list(),RuntimeException::class);
	$io->put('failure','lock');$t->throws(static fn()=>$disk->list(),RuntimeException::class);
	$io->put('failure','');
	file_put_contents($root.DIRECTORY_SEPARATOR.'recover.txt','current');
	file_put_contents($root.DIRECTORY_SEPARATOR.'.recover.txt.0123456789abcdef.bak','backup');
	file_put_contents($root.DIRECTORY_SEPARATOR.'.stale.txt.0123456789abcdef.tmp','temp');
	new PanelLocalMediaDisk($root);
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'.recover.txt.0123456789abcdef.bak'));
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'.stale.txt.0123456789abcdef.tmp'));
})->tag('panel','media','disk','fault-injection','coverage')->group('panel-lane-c');

test('resumable upload stream failures remain typed at allocation buffering reading and assembly boundaries', static function(Context $t): void {
	$io=$t->state('panel.media.io',['failure'=>'']);
	dp_panel_define_media_io_symbols($t);
	$disk=new PanelLocalMediaDisk($t->tempDirectory('media-upload-stream-faults'));
	$session=PanelResumableUploadSession::start($disk,'chunk-alloc.bin',1024,1024,null,[],'chunk-alloc-session');
	$io->put('failure','chunk_alloc');$t->throws(static fn()=>$session->receiveChunk(0,str_repeat('x',1024)),RuntimeException::class);

	foreach(['buffer_alloc','upload_read','upload_buffer_write'] as $failure){
		$io->put('failure','');
		$session=PanelResumableUploadSession::start($disk,$failure.'.bin',1024,1024,null,[],$failure.'-session');
		$stream=fopen('php://temp','r+');fwrite($stream,str_repeat('x',1024));rewind($stream);
		$io->put('failure',$failure);
		$t->throws(static fn()=>$session->receiveChunkStream(0,$stream,1024),RuntimeException::class);
		fclose($stream);
	}

	$io->put('failure','');
	$assembly=PanelResumableUploadSession::start($disk,'assembly.bin',1024,1024,null,[],'assembly-fault-session');
	$assembly->receiveChunk(0,str_repeat('a',1024));
	$io->put('failure','assembly_alloc');$t->throws(static fn()=>$assembly->assemble(),RuntimeException::class);
	$io->put('failure','stream_copy');$t->throws(static fn()=>$assembly->assemble(),RuntimeException::class);
})->tag('panel','media','upload','fault-injection','coverage')->group('panel-lane-c');
