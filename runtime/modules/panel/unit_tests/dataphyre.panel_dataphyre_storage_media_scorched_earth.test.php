<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelAdapterPackContext;
use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelDataphyreAdapterPack;
use Dataphyre\Panel\PanelDataphyreStorageMediaDisk;
use Dataphyre\Panel\PanelMediaManager;
use Dataphyre\Panel\PanelMediaStorageException;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformManifest;
use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DP_STORAGE_CFG')){
	define('DP_STORAGE_CFG',[
		'default_disk'=>'memory',
		'disks'=>[
			'memory'=>['driver'=>'memory'],
			'probe'=>['driver'=>'panel_media_probe'],
		],
	]);
}

framework(['panel','storage']);

final class PanelStorageMediaProbeDriver implements StorageDriver {
	/** @var array<string,array{body:string,modified_at:int,mime:?string}> */
	public array $objects=[];
	/** @var list<string> */
	public array $writeModes=[];
	/** @var list<string> */
	public array $deleteModes=[];
	/** @var array<string,true> */
	public array $throwOperations=[];
	/** @var array<string,true> */
	public array $incompleteMetadata=[];
	/** @var array<int,mixed>|null */
	public ?array $listOverride=null;
	/** @var array<string,int> */
	public array $calls=[];

	public function exists(string $path):bool {
		$this->trip('exists');
		return isset($this->objects[$this->path($path)]);
	}

	public function read(string $path,array $options=[]):string|false {
		$this->trip('read');
		return $this->objects[$this->path($path)]['body']??false;
	}

	public function readStream(string $path,array $options=[]):mixed {
		$this->trip('read_stream');
		$body=$this->read($path,$options);
		if(!is_string($body)){return false;}
		$stream=fopen('php://temp','w+b');
		if(!is_resource($stream)){return false;}
		fwrite($stream,$body);
		rewind($stream);
		return $stream;
	}

	public function write(string $path,mixed $contents,array $options=[]):bool {
		$this->trip('write');
		$path=$this->path($path);
		$body=is_resource($contents)?stream_get_contents($contents):(string)$contents;
		if(!is_string($body)){return false;}
		$mode=array_shift($this->writeModes)??'ok';
		if($mode==='throw'){throw new RuntimeException('provider api_token=storage-secret path='.$path);}
		if($mode==='reject'){return false;}
		if($mode==='corrupt'){$body.='-corrupt';}
		$this->objects[$path]=[
			'body'=>$body,
			'modified_at'=>time(),
			'mime'=>is_string($options['content_type']??null)?$options['content_type']:null,
		];
		if($mode==='throw_after_write'){throw new RuntimeException('provider api_token=storage-secret path='.$path);}
		if($mode==='mutate_reject'){return false;}
		return true;
	}

	public function delete(string $path):bool {
		$this->trip('delete');
		$path=$this->path($path);
		$mode=array_shift($this->deleteModes)??'ok';
		if($mode==='throw'){throw new RuntimeException('provider api_token=storage-secret path='.$path);}
		if($mode==='reject'){return false;}
		if($mode!=='leave'){unset($this->objects[$path]);}
		return true;
	}

	public function metadata(string $path):FileMetadata|false {
		$this->trip('metadata');
		$path=$this->path($path);
		$item=$this->objects[$path]??null;
		if(!is_array($item)){return false;}
		if(isset($this->incompleteMetadata[$path])){return new FileMetadata($path,null,null,null);}
		return new FileMetadata($path,strlen($item['body']),$item['modified_at'],$item['mime']);
	}

	public function list(string $prefix='',array $options=[]):array {
		$this->trip('list');
		if($this->listOverride!==null){return $this->listOverride;}
		$prefix=$this->path($prefix);$items=[];
		foreach($this->objects as$path=>$item){
			if($prefix!==''&&$path!==$prefix&&!str_starts_with($path,$prefix.'/')){continue;}
			$items[]=new FileMetadata($path,strlen($item['body']),$item['modified_at'],$item['mime']);
		}
		usort($items,static fn(FileMetadata $left,FileMetadata $right):int=>strcmp($left->path(),$right->path()));
		return $items;
	}

	public function temporaryUrl(string $path,int|DateTimeInterface $expires,array $options=[]):string|false {
		return $this->exists($path)?'probe://'.rawurlencode($this->path($path)):false;
	}

	public function seed(string $path,string $body,?string $mime=null):void {
		$this->objects[$this->path($path)]=['body'=>$body,'modified_at'=>time(),'mime'=>$mime];
	}

	private function trip(string $operation):void {
		$this->calls[$operation]=($this->calls[$operation]??0)+1;
		if(isset($this->throwOperations[$operation])){
			throw new RuntimeException('provider api_token=storage-secret operation='.$operation);
		}
	}

	private function path(string $path):string {
		return trim(str_replace('\\','/',$path),'/');
	}
}

final class PanelStorageMediaStalledStream {
	public mixed $context;
	public function stream_open(string $path,string $mode,int $options,?string &$openedPath):bool{return true;}
	public function stream_read(int $count):string{return '';}
	public function stream_eof():bool{return false;}
	public function stream_stat():array{return [];}
}

suite('Panel Dataphyre Storage media adapter')
	->contract('panel.media.dataphyre-storage',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel','module:storage')
	->through('path-boundary','streaming','verification','compensation','manager','adapter-pack','rollback','privacy')
	->tag('panel','media','storage','adapter','scorched-earth')
	->group('panel-platform-contract');

/** @return array{0:StorageManager,1:PanelStorageMediaProbeDriver} */
function dp_panel_storage_media_probe():array {
	$driver=new PanelStorageMediaProbeDriver();
	$manager=new StorageManager();
	$manager->extend('panel_media_probe',static fn():StorageDriver=>$driver);
	return[$manager,$driver];
}

function dp_panel_storage_media_disk(StorageManager $manager,array $options=[]):PanelDataphyreStorageMediaDisk {
	return new PanelDataphyreStorageMediaDisk($manager,'probe','tenant/private',array_replace([
		'name'=>'storage-media',
		'default_max_bytes'=>4096,
		'read_options'=>['read_guard'=>'bounded'],
		'write_options'=>['visibility'=>'private'],
		'list_options'=>['page_size'=>100],
	],$options));
}

test('Dataphyre Storage media disk confines paths and exposes a complete verified lifecycle',static function(Context $t):void {
	[$manager,$driver]=dp_panel_storage_media_probe();
	$events=[];
	$manager->listen('*',static function(array $event)use(&$events):void{$events[]=$event;});
	$disk=dp_panel_storage_media_disk($manager);
	$t->same('storage-media',$disk->name());
	$t->same('probe',$disk->storageDisk());
	$t->same('orders/note.txt',$disk->normalizePath('orders/./note.txt'));
	foreach(['','/absolute.txt','C:\\absolute.txt','../escape.txt','folder/../../escape','http://host/file',"\0bad","bad\npath",'bad:segment']as$invalid){
		$t->throws(static fn()=>$disk->normalizePath($invalid),InvalidArgumentException::class);
	}
	$payload='verified payload';
	$descriptor=$disk->write('orders/note.txt',$payload,[
		'overwrite'=>false,
		'checksum'=>hash('sha256',$payload),
		'content_type'=>'text/plain',
	]);
	$t->same([
		'disk'=>'storage-media',
		'path'=>'orders/note.txt',
		'filename'=>'note.txt',
		'size'=>strlen($payload),
		'mime'=>'text/plain',
	],array_diff_key($descriptor,['modified_at'=>true,'checksum'=>true]));
	$t->same(hash('sha256',$payload),$descriptor['checksum']);
	$t->same($payload,$disk->read('orders/note.txt'));
	$t->same(strlen($payload),$disk->size('orders/note.txt'));
	$t->same(hash('md5',$payload),$disk->checksum('orders/note.txt','md5'));
	$t->isTrue($disk->modifiedAt('orders/note.txt')>0);
	$stream=$disk->readStream('orders/note.txt');
	try{$t->same($payload,stream_get_contents($stream));}finally{fclose($stream);}
	$t->throws(static fn()=>$disk->read('orders/note.txt',4),LengthException::class);
	$t->throws(static fn()=>$disk->writeStream('invalid',new stdClass()),InvalidArgumentException::class);
	$t->throws(static fn()=>$disk->write('orders/note.txt','duplicate',['overwrite'=>false]),RuntimeException::class);
	$t->throws(static fn()=>$disk->write('orders/bad.txt','bad',['checksum'=>'invalid']),InvalidArgumentException::class);
	$t->throws(static fn()=>$disk->write('orders/bad.txt','bad',['checksum'=>str_repeat('0',64)]),UnexpectedValueException::class);
	$t->throws(static fn()=>$disk->checksum('orders/note.txt','not-a-hash'),InvalidArgumentException::class);

	$t->same('orders/note.txt',$disk->copy('orders/note.txt','orders/note.txt')['path']);
	$t->throws(static fn()=>$disk->copy('orders/missing.txt','orders/copy.txt'),PanelMediaStorageException::class);
	$t->same('orders/copy.txt',$disk->copy('orders/note.txt','orders/copy.txt')['path']);
	$t->same('orders/copy.txt',$disk->move('orders/copy.txt','orders/copy.txt')['path']);
	$t->same('archive/copy.txt',$disk->move('orders/copy.txt','archive/copy.txt')['path']);
	$t->isFalse($disk->exists('orders/copy.txt'));
	$disk->write('orders/nested/child.txt','nested');
	$disk->write('.panel_uploads/internal/manifest.json','{}');
	$disk->write('.panel-quarantine/rejected.bin','bad');
	$t->same(['orders/nested/child.txt','orders/note.txt'],array_column($disk->list('orders'),'path'));
	$t->same(['orders/note.txt'],array_column($disk->list('orders',false),'path'));
	$t->notContains('.panel_uploads',json_encode($disk->list(),JSON_THROW_ON_ERROR));
	$t->notContains('.panel-quarantine',json_encode($disk->list(),JSON_THROW_ON_ERROR));
	$t->isTrue($disk->delete('archive/copy.txt'));
	$t->isFalse($disk->delete('archive/copy.txt'));
	$t->throws(static fn()=>$disk->descriptor('archive/copy.txt'),PanelMediaStorageException::class);
	$t->isTrue(($driver->calls['write']??0)>=6);
	$t->isTrue(in_array('tenant/private/orders/note.txt',array_column($events,'path'),true));

	$manifest=json_encode($disk,JSON_THROW_ON_ERROR);
	$t->contains('"adapter":"dataphyre_storage"',$manifest);
	$t->contains('"atomic_write":false',$manifest);
	$t->contains('"best_effort_compensation":true',$manifest);
	$t->contains('"prefix_serialized":false',$manifest);
	$t->notContains('tenant/private',$manifest);
	$t->notContains('bounded',$manifest);
	$t->notContains('private"',$manifest);
})->tag('panel','media','storage','lifecycle','streaming','security')->isolation('case')->maxMillis(5000);

test('Dataphyre Storage media disk compensates partial writes replacements and failed moves',static function(Context $t):void {
	[$manager,$driver]=dp_panel_storage_media_probe();
	$disk=dp_panel_storage_media_disk($manager);
	$driver->writeModes=['corrupt'];
	$error=$t->throws(static fn()=>$disk->write('new/corrupt.bin','clean'),PanelMediaStorageException::class);
	$t->same('write',$error->operation());
	$t->same('verification_failed',$error->reason());
	$t->isTrue($error->retryable());
	$t->isFalse($disk->exists('new/corrupt.bin'));

	$driver->seed('tenant/private/existing/file.bin','stable');
	$driver->writeModes=['corrupt'];
	$t->throws(static fn()=>$disk->write('existing/file.bin','replacement'),PanelMediaStorageException::class);
	$t->same('stable',$disk->read('existing/file.bin'));

	$driver->seed('tenant/private/existing/bad-restore.bin','stable');
	$driver->writeModes=['corrupt','corrupt'];
	$badRestore=$t->throws(static fn()=>$disk->write('existing/bad-restore.bin','replacement'),PanelMediaStorageException::class);
	$t->same('compensate',$badRestore->operation());
	$t->same('restore_verification_failed',$badRestore->reason());

	$driver->writeModes=['throw_after_write'];
	$backend=$t->throws(static fn()=>$disk->write('new/backend.bin','payload'),PanelMediaStorageException::class);
	$t->same('write',$backend->operation());
	$t->same('backend_failure',$backend->reason());
	$t->notContains('storage-secret',$backend->getMessage());
	$t->notContains('new/backend.bin',json_encode($backend,JSON_THROW_ON_ERROR));
	$t->isFalse($disk->exists('new/backend.bin'));

	$driver->writeModes=['reject'];
	$rejected=$t->throws(static fn()=>$disk->write('new/rejected.bin','payload'),PanelMediaStorageException::class);
	$t->same('backend_rejected',$rejected->reason());
	$t->isFalse($rejected->retryable()===false);

	$driver->seed('tenant/private/existing/unrestorable.bin','original');
	$driver->writeModes=['corrupt','reject'];
	$compensation=$t->throws(static fn()=>$disk->write('existing/unrestorable.bin','replacement'),PanelMediaStorageException::class);
	$t->same('compensate',$compensation->operation());
	$t->same('restore_rejected',$compensation->reason());
	$t->instanceOf(PanelMediaStorageException::class,$compensation->getPrevious());

	$driver->seed('tenant/private/moves/source.bin','source');
	$driver->deleteModes=['leave'];
	$move=$t->throws(static fn()=>$disk->move('moves/source.bin','moves/target.bin'),PanelMediaStorageException::class);
	$t->same('delete',$move->operation());
	$t->isTrue($disk->exists('moves/source.bin'));
	$t->isFalse($disk->exists('moves/target.bin'));

	$driver->seed('tenant/private/delete/retained.bin','retained');
	$driver->deleteModes=['leave'];
	$delete=$t->throws(static fn()=>$disk->delete('delete/retained.bin'),PanelMediaStorageException::class);
	$t->same('verification_failed',$delete->reason());
	$t->isTrue($disk->exists('delete/retained.bin'));

	$driver->seed('tenant/private/existing/restore-backend.bin','stable');
	$backup=fopen('php://temp','w+b');
	fwrite($backup,'stable');
	rewind($backup);
	$driver->throwOperations=['metadata'=>true];
	try{
		$wrapped=$t->throws(
			static fn()=>$t->nonPublic($disk)->invoke('restore','existing/restore-backend.bin',true,$backup,new RuntimeException('original')),
			PanelMediaStorageException::class
		);
		$t->same('compensate',$wrapped->operation());
		$t->same('backend_failure',$wrapped->reason());
	}finally{
		$driver->throwOperations=[];
		fclose($backup);
	}
})->tag('panel','media','storage','compensation','rollback','failure')->isolation('case')->maxMillis(5000);

test('Dataphyre Storage media disk validates construction metadata listings and backend errors without leaking providers',static function(Context $t):void {
	[$manager,$driver]=dp_panel_storage_media_probe();
	foreach([
		static fn()=>new PanelDataphyreStorageMediaDisk($manager,'','prefix'),
		static fn()=>new PanelDataphyreStorageMediaDisk($manager,'Not Canonical','prefix'),
		static fn()=>new PanelDataphyreStorageMediaDisk($manager,'probe','prefix',['unknown'=>true]),
		static fn()=>new PanelDataphyreStorageMediaDisk($manager,'probe','prefix',['read_options'=>'bad']),
		static fn()=>new PanelDataphyreStorageMediaDisk($manager,'probe','prefix',['write_options'=>'bad']),
		static fn()=>new PanelDataphyreStorageMediaDisk($manager,'probe','prefix',['list_options'=>'bad']),
	]as$invalid){
		$t->throws($invalid,InvalidArgumentException::class);
	}
	$missingManager=new StorageManager();
	$connect=$t->throws(static fn()=>new PanelDataphyreStorageMediaDisk($missingManager,'missing','prefix'),PanelMediaStorageException::class);
	$t->same('connect',$connect->operation());

	$disk=dp_panel_storage_media_disk($manager);
	$driver->seed('tenant/private/meta/incomplete.bin','body');
	$driver->incompleteMetadata['tenant/private/meta/incomplete.bin']=true;
	$incomplete=$t->throws(static fn()=>$disk->descriptor('meta/incomplete.bin'),PanelMediaStorageException::class);
	$t->same('metadata',$incomplete->operation());
	$t->same('incomplete',$incomplete->reason());
	$driver->listOverride=[new stdClass()];
	$invalidList=$t->throws(static fn()=>$disk->list(),PanelMediaStorageException::class);
	$t->same('list',$invalidList->operation());
	$t->same('invalid_metadata',$invalidList->reason());
	$driver->listOverride=[new FileMetadata('other-prefix/foreign.bin',1,time(),'text/plain')];
	$t->same([],$disk->list());
	$driver->listOverride=null;

	foreach(['exists','read_stream','metadata','list']as$operation){
		$driver->throwOperations=[$operation=>true];
		$error=$t->throws(static function()use($disk,$operation):mixed{
			return match($operation){
				'exists'=>$disk->exists('errors/file.bin'),
				'read_stream'=>$disk->readStream('errors/file.bin'),
				'metadata'=>$disk->descriptor('errors/file.bin'),
				'list'=>$disk->list(),
			};
		},PanelMediaStorageException::class);
		$t->same($operation==='read_stream'?'read':$operation,$error->operation());
		$t->same('backend_failure',$error->reason());
		$t->notContains('storage-secret',json_encode($error,JSON_THROW_ON_ERROR));
		$driver->throwOperations=[];
	}

	$t->throws(static fn()=>new PanelMediaStorageException('Bad Operation'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelMediaStorageException('read','Bad Reason'),InvalidArgumentException::class);
	$typed=new PanelMediaStorageException('read','not_found',false);
	$t->same('read',$typed->operation());
	$t->same('not_found',$typed->reason());
	$t->isFalse($typed->retryable());
	$t->contains('"provider_message_serialized":false',json_encode($typed,JSON_THROW_ON_ERROR));

	$scheme='panelmediastall';
	$t->isTrue(stream_wrapper_register($scheme,PanelStorageMediaStalledStream::class));
	$stalled=fopen($scheme.'://probe','rb');
	try{
		$stalledError=$t->throws(static fn()=>$t->nonPublic($disk)->invoke('buffer',$stalled,16),PanelMediaStorageException::class);
		$t->same('stream_stalled',$stalledError->reason());
	}finally{
		fclose($stalled);
		stream_wrapper_unregister($scheme);
	}
})->tag('panel','media','storage','validation','errors','privacy')->isolation('case')->maxMillis(5000);

test('Storage-backed media manager passes disk and manager conformance with resumable delivery and change feeds',static function(Context $t):void {
	[$storage]=dp_panel_storage_media_probe();
	$catalog=new PanelAtomicSnapshotStore($t->tempDirectory('panel-storage-media-catalog'),'panel.media.catalog',['items'=>[],'uploads'=>[]],64);
	$disk=dp_panel_storage_media_disk($storage);
	$manager=PanelMediaManager::forDisk($disk,$catalog,str_repeat('s',32),[
		'cleanup'=>false,
		'delivery_url'=>'/panel/storage-media',
		'maximum_ttl'=>3600,
	])
		->scanner(static fn():array=>['clean'=>true,'status'=>'clean'],'storage-probe')
		->transformer(static fn():array=>['variants'=>[],'metadata'=>['adapter'=>'storage']],'storage-probe');
	$t->same($disk,$manager->disk());
	$t->same($catalog,$manager->catalog());
	$t->contains('"storage-probe"',json_encode($manager,JSON_THROW_ON_ERROR));
	$mediaDomain=PanelPlatformManifest::inspect()->domain('media')??[];
	foreach(['snapshot_store','dataphyre_storage_disk','storage_exception']as$feature){
		$t->isTrue($mediaDomain['features'][$feature]??false);
	}
	$runner=new PanelAdapterConformanceRunner();
	$diskReport=$runner->run(PanelAdapterConformanceCatalog::mediaDisk(),$disk,[
		'allow_destructive'=>true,
		'namespace'=>'storage_disk_'.bin2hex(random_bytes(4)),
	]);
	$t->isTrue($diskReport->passed());
	$t->same(2,$diskReport->summary()['passed']);
	$managerReport=$runner->run(PanelAdapterConformanceCatalog::mediaManager(),$manager,[
		'allow_destructive'=>true,
		'namespace'=>'storage_manager_'.bin2hex(random_bytes(4)),
	]);
	$t->isTrue($managerReport->passed());
	$t->same(2,$managerReport->summary()['passed']);
	$t->isTrue($catalog->cursor()>=4);
	$t->notContains('tenant/private',json_encode([$diskReport,$managerReport],JSON_THROW_ON_ERROR));

	$withoutOptional=PanelMediaManager::forDisk(
		$disk,
		new PanelAtomicSnapshotStore($t->tempDirectory('panel-storage-media-minimal'),'panel.media.catalog',['items'=>[],'uploads'=>[]]),
		null,
		['cleanup'=>false]
	);
	$t->throws(static fn()=>$withoutOptional->issue('missing'),LogicException::class);
	$t->throws(static fn()=>$withoutOptional->cleanup(),LogicException::class);
	$cancel=$withoutOptional->startUpload('cancel/file.bin',1024,['id'=>'cancel_upload_01','chunk_size'=>1024]);
	$t->same('open',$cancel['state']);
	$t->isTrue($withoutOptional->cancelUpload('cancel_upload_01'));
	$t->same([],$withoutOptional->items('ready'));
	$t->isFalse($withoutOptional->delete('missing'));
})->tag('panel','media','storage','manager','conformance','delivery')->isolation('case')->maxMillis(10000);

test('first-party Storage media binding is previewable secret-free conformant and rollback-safe',static function(Context $t):void {
	[$storage,$driver]=dp_panel_storage_media_probe();
	$old=PanelMediaManager::local($t->tempDirectory('panel-storage-old-media'),str_repeat('o',32));
	$panel=Panel::make('storage_media_adapter_pack')->usePlatform(PanelPlatform::make(['media.manager'=>$old]));
	$pack=PanelDataphyreAdapterPack::make();
	$secret='storage-media-signing-secret-'.str_repeat('s',32);
	$directory=$t->tempDirectory('panel-storage-adapter-catalog');
	$config=[
		'adapters'=>[
			'storage_media'=>[
				'storage_manager'=>$storage,
				'disk'=>'probe',
				'prefix'=>'customer/private-assets',
				'catalog_directory'=>$directory,
				'signing_key'=>$secret,
				'options'=>[
					'disk'=>[
						'name'=>'dataphyre-private',
						'default_max_bytes'=>8192,
						'write_options'=>['api_token'=>'must-not-serialize'],
					],
					'manager'=>[
						'cleanup'=>false,
						'delivery_url'=>'/panel/private-assets',
					],
					'retention'=>64,
				],
			],
		],
		'conformance'=>[
			'storage_media'=>['namespace'=>'pack_media_'.bin2hex(random_bytes(4))],
		],
		'allow_destructive_conformance'=>true,
	];
	$plan=$pack->plan($panel,$config);
	$t->isTrue($plan->ready(),implode(' ',$plan->errors()));
	$t->same('1.1.0',$pack->version());
	$t->same(['storage_media'],$plan->order());
	$preview=json_encode($plan,JSON_THROW_ON_ERROR);
	$t->notContains($secret,$preview);
	$t->notContains($directory,$preview);
	$t->notContains('customer/private-assets',$preview);
	$activation=$plan->apply();
	$current=$panel->platform()->get('media.manager');
	$t->instanceOf(PanelMediaManager::class,$current);
	$t->instanceOf(PanelDataphyreStorageMediaDisk::class,$current->disk());
	$t->same('dataphyre-private',$current->disk()->name());
	$t->same(2,$activation->conformance()['storage_media']->summary()['passed']);
	$activated=json_encode($activation,JSON_THROW_ON_ERROR);
	$t->notContains($secret,$activated);
	$t->notContains($directory,$activated);
	$t->notContains('customer/private-assets',$activated);
	$t->notContains('must-not-serialize',$activated);

	$panel->unregisterPlugin('dataphyre_framework_adapters',true);
	$t->same($old,$panel->platform()->get('media.manager'));
	$t->isFalse($panel->hasPlugin('dataphyre_framework_adapters'));

	$driver->writeModes=['reject'];
	$failedConfig=$config;
	$failedConfig['adapters']['storage_media']['catalog_directory']=$t->tempDirectory('panel-storage-failed-adapter-catalog');
	$failedConfig['conformance']['storage_media']['namespace']='pack_failure_'.bin2hex(random_bytes(4));
	$t->throws(static fn()=>$panel->installAdapterPack($pack,$failedConfig),RuntimeException::class);
	$t->same($old,$panel->platform()->get('media.manager'));
	$t->isFalse($panel->hasPlugin('dataphyre_framework_adapters'));
})->tag('panel','media','storage','adapter-pack','conformance','rollback','privacy')->isolation('case')->maxMillis(15000);

test('Storage media binding accepts injected managers and rejects malformed factory configuration',static function(Context $t):void {
	[$storage]=dp_panel_storage_media_probe();
	$panel=Panel::make('storage_media_factory_contract')->usePlatform(PanelPlatform::make());
	$pack=PanelDataphyreAdapterPack::make();
	$binding=$pack->bindings()['storage_media'];
	$context=new PanelAdapterPackContext($panel,$pack,[]);
	$injected=PanelMediaManager::local($t->tempDirectory('panel-storage-injected-manager'),str_repeat('i',32));
	$t->same($injected,$binding->create($context,['media_manager'=>$injected]));
	foreach([
		['storage_manager'=>new stdClass(),'disk'=>'probe','catalog'=>$injected->catalog()],
		['storage_manager'=>$storage,'catalog'=>$injected->catalog()],
		['storage_manager'=>$storage,'disk'=>'probe','prefix'=>[],'catalog'=>$injected->catalog()],
		['storage_manager'=>$storage,'disk'=>'probe','options'=>'bad','catalog'=>$injected->catalog()],
		['storage_manager'=>$storage,'disk'=>'probe'],
		['storage_manager'=>$storage,'disk'=>'probe','catalog'=>$injected->catalog(),'signing_key'=>[]],
	]as$config){
		$t->throws(static fn()=>$binding->create($context,$config),InvalidArgumentException::class);
	}
	$catalog=new PanelAtomicSnapshotStore($t->tempDirectory('panel-storage-factory-catalog'),'panel.media.catalog',['items'=>[],'uploads'=>[]]);
	$created=$binding->create($context,[
		'storage_manager'=>$storage,
		'disk'=>'probe',
		'prefix'=>'factory',
		'catalog'=>$catalog,
		'signing_key'=>'',
		'options'=>['cleanup'=>false],
	]);
	$t->instanceOf(PanelDataphyreStorageMediaDisk::class,$created->disk());
	$t->same($catalog,$created->catalog());
	$t->isFalse($created->manifest()['capabilities']['private_delivery']);
})->tag('panel','media','storage','adapter-pack','factory','validation')->isolation('case')->maxMillis(5000);
