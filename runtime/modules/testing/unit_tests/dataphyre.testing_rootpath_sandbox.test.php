<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\RootpathSandbox;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

final class DpManagedStreamWrapper {
	public mixed $context=null;
	public function url_stat(string $path,int $flags): array {
		return ['mode'=>0040777,2=>0040777];
	}
}

if(!function_exists('Dataphyre\\Test\\unlink')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Test;
final class DpRootpathSandboxSeams {
	private const CHANNEL='testing.rootpath-sandbox';
	public static function value(string $key,mixed $default=null): mixed {
		return TestState::channelIfActive(self::CHANNEL)?->get($key,$default) ?? $default;
	}
	public static function matches(string $key,string $path): bool {
		$target=self::value($key);
		return is_string($target) && str_replace('\\','/',rtrim($target,'/\\'))===str_replace('\\','/',rtrim($path,'/\\'));
	}
}
function unlink(string $filename,mixed $context=null): bool {
	if(DpRootpathSandboxSeams::matches('unlink_failure',$filename)){return false;}
	return $context===null ? \unlink($filename) : \unlink($filename,$context);
}
function rmdir(string $directory,mixed $context=null): bool {
	if(DpRootpathSandboxSeams::matches('rmdir_failure',$directory)){return false;}
	return $context===null ? \rmdir($directory) : \rmdir($directory,$context);
}
function is_link(string $filename): bool {
	return DpRootpathSandboxSeams::matches('symlink',$filename) || \is_link($filename);
}
function realpath(string $path): string|false {
	return DpRootpathSandboxSeams::matches('realpath_failure',$path) ? false : \realpath($path);
}
function mkdir(string $directory,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {
	if(DpRootpathSandboxSeams::matches('mkdir_failure',$directory)){return false;}
	return $context===null ? \mkdir($directory,$permissions,$recursive) : \mkdir($directory,$permissions,$recursive,$context);
}
function file_put_contents(string $filename,mixed $data,int $flags=0,mixed $context=null): int|false {
	if(DpRootpathSandboxSeams::matches('write_failure',$filename)){return false;}
	return $context===null ? \file_put_contents($filename,$data,$flags) : \file_put_contents($filename,$data,$flags,$context);
}
function stream_wrapper_register(string $protocol,string $class,int $flags=0): bool {
	if(DpRootpathSandboxSeams::value('stream_wrapper_failure')===$protocol){return false;}
	return \stream_wrapper_register($protocol,$class,$flags);
}
PHP);
}

function dp_rootpath_sandbox_write_marker(string $root,string $key='sandbox'): void {
	$marker=[
		'format'=>RootpathSandbox::FORMAT,
		'rootpath_key'=>$key,
		'root'=>str_replace('\\','/',rtrim((string)realpath($root),'/\\')),
		'run_id'=>'dataphyre-unit-tests-a1b2c3d4',
		'token'=>str_repeat('a',64),
	];
	file_put_contents($root.'/'.RootpathSandbox::MARKER,json_encode($marker,JSON_THROW_ON_ERROR));
}

suite('Runner-owned ROOTPATH sandbox contracts')
	->tag('testing','filesystem','sandbox','safety')
	->group('framework-coverage')
	->contract('testing.rootpath-sandbox',1)
	->layer('unit')
	->risk('critical')
	->watches('module:testing','path:runtime/modules/testing/tooling/bootstrap.php','path:runtime/modules/testing/tooling/Runner.php')
	->through('declaration validation','ownership marker','path containment','recursive reset')
	->sandboxesRootpath('testing_runtime')
	->isolation('case');

test('a declared sandbox provides readable traversal-safe filesystem vocabulary',static function(Context $t): void {
	$root=RootpathSandbox::root('testing_runtime');
	$workspace=$t->rootpathWorkspace('testing_runtime')->reset();
	$t->same(rtrim($root,'/'),$workspace->root());
	$t->same(rtrim($root,'/'),$workspace->path());
	$t->same(rtrim($root,'/'),$workspace->directory('.'));
	$t->same(rtrim($root,'/').'/fixtures',$workspace->directory('fixtures'));
	$file=$workspace->file('fixtures/example.txt','owned fixture');
	$t->same('owned fixture',file_get_contents($file));
	$t->throws(static fn()=>$workspace->file(''),InvalidArgumentException::class);
	$t->throws(static fn()=>$workspace->file(RootpathSandbox::MARKER,'invalid'),InvalidArgumentException::class);
	$t->throws(static fn()=>$workspace->path('../../escape'),InvalidArgumentException::class);
	$environment=$t->globalMap('_ENV');
	$server=$t->globalMap('_SERVER');
	$t->same($t,$t->environment(['DATAPHYRE_TEST_MANAGED_ENV'=>'ready','DATAPHYRE_TEST_BOOL_ENV'=>true]));
	$t->same('ready',getenv('DATAPHYRE_TEST_MANAGED_ENV'));
	$t->same('1',$environment->get('DATAPHYRE_TEST_BOOL_ENV'));
	$t->same('1',$server->get('DATAPHYRE_TEST_BOOL_ENV'));
	$server->put('DATAPHYRE_TEST_MANAGED_ENV','stale-server-value');
	$t->environment(['DATAPHYRE_TEST_MANAGED_ENV'=>null]);
	$t->same(false,getenv('DATAPHYRE_TEST_MANAGED_ENV'));
	$t->isFalse($environment->has('DATAPHYRE_TEST_MANAGED_ENV'));
	$t->isFalse($server->has('DATAPHYRE_TEST_MANAGED_ENV'));
	$t->throws(static fn()=>$t->environment(['not-valid!'=>'value']),InvalidArgumentException::class);
	$t->throws(static fn()=>$t->environment(['VALID_NAME'=>[]]),InvalidArgumentException::class);
	$t->same($t,$t->streamWrapper('dpmanaged',DpManagedStreamWrapper::class));
	$t->isTrue(in_array('dpmanaged',stream_get_wrappers(),true));
	$t->throws(static fn()=>$t->streamWrapper('not valid',DpManagedStreamWrapper::class),InvalidArgumentException::class);
	$t->throws(static fn()=>$t->streamWrapper('dpmissing','DpMissingStreamWrapper'),InvalidArgumentException::class);
	$t->throws(static fn()=>$t->streamWrapper('file',DpManagedStreamWrapper::class),LogicException::class);
	$failures=$t->state('testing.rootpath-sandbox');
	$failedDirectory=$workspace->path('cannot-create');
	$failures->put('mkdir_failure',$failedDirectory);
	$t->throws(static fn()=>$workspace->directory('cannot-create'),RuntimeException::class);
	$failures->put('mkdir_failure',null);
	$failedFile=$workspace->path('cannot-write.txt');
	$failures->put('write_failure',$failedFile);
	$t->throws(static fn()=>$workspace->file('cannot-write.txt','blocked'),RuntimeException::class);
	$failures->put('write_failure',null)->put('stream_wrapper_failure','dpfail');
	$t->throws(static fn()=>$t->streamWrapper('dpfail',DpManagedStreamWrapper::class),RuntimeException::class);
	$t->same($root,RootpathSandbox::path('testing_runtime'));
	$t->same($root,RootpathSandbox::path('testing_runtime','.'));
	$t->same($root.'nested/file.txt',RootpathSandbox::path('testing_runtime','nested/./child/../file.txt'));
	foreach(['/absolute.txt','C:\\absolute.txt',"unsafe\0path"] as $unsafe){
		$t->throws(static fn()=>RootpathSandbox::path('testing_runtime',$unsafe),InvalidArgumentException::class,$unsafe);
	}
	$t->throws(static fn()=>RootpathSandbox::path('testing_runtime','../../escape.txt'),InvalidArgumentException::class);
	$t->same(['cache','logs'],RootpathSandbox::normalizeDeclaredKeys([' cache ','logs','cache']));
	$t->throws(static fn()=>RootpathSandbox::normalizeDeclaredKeys([7]),InvalidArgumentException::class);
	$t->throws(static fn()=>RootpathSandbox::normalizeDeclaredKeys(['not valid']),InvalidArgumentException::class);
	$t->throws(static fn()=>RootpathSandbox::normalizeDeclaredKeys(['common_dataphyre']),InvalidArgumentException::class);
	$t->throwsLike(static fn()=>RootpathSandbox::root('undeclared'),LogicException::class,'did not provide');

	$nested=$workspace->directory('nested/deep');
	$workspace->file('nested/deep/victim.txt','disposable');
	$t->same($root,RootpathSandbox::reset('testing_runtime'));
	$t->isFalse(file_exists($nested));
	$t->isTrue(is_file($root.RootpathSandbox::MARKER));

	$private=$t->nonPublic(RootpathSandbox::class);
	$t->same('/',$private->invoke('normalizeAbsolute','/'));
	$t->same('C:',$private->invoke('normalizeAbsolute',' C:/ '));
	$t->isTrue($private->invoke('isFilesystemRoot','/'));
	$t->isTrue($private->invoke('isFilesystemRoot','C:'));
	$t->isTrue($private->invoke('isFilesystemRoot','//server/share'));
	$t->isFalse($private->invoke('isFilesystemRoot','/srv/project'));
	$t->isTrue($private->invoke('containsPath','/srv/project','/srv/project'));
	$t->isTrue($private->invoke('containsPath','/srv/project','/srv/project/cache'));
	$t->isFalse($private->invoke('containsPath','/srv/project','/srv/project-two'));
	$t->isTrue($private->invoke('samePath','C:/Project','c:\\project'));
	$private->invoke('remove',$root.'already-absent');
	$t->isFalse(file_exists($root.'already-absent'));
});

test('ownership verification rejects changed roots repositories and marker corruption',static function(Context $t): void {
	$root=RootpathSandbox::root('testing_runtime');
	$raw=rtrim($root,'/');
	$marker=$raw.'/'.RootpathSandbox::MARKER;
	$valid=(string)file_get_contents($marker);
	$seams=$t->state('testing.rootpath-sandbox');

	$seams->put('symlink',$raw);
	$t->throwsLike(static fn()=>RootpathSandbox::root('testing_runtime'),LogicException::class,'not a safe disposable directory');
	$seams->forget('symlink');
	$seams->put('realpath_failure',$raw);
	$t->throwsLike(static fn()=>RootpathSandbox::root('testing_runtime'),LogicException::class,'does not exist');
	$seams->forget('realpath_failure');

	mkdir($raw.'/.git');
	$t->throwsLike(static fn()=>RootpathSandbox::root('testing_runtime'),LogicException::class,'Git repository');
	rmdir($raw.'/.git');
	unlink($marker);
	$t->throwsLike(static fn()=>RootpathSandbox::root('testing_runtime'),LogicException::class,'missing the runner ownership marker');
	file_put_contents($marker,'{invalid');
	$t->throwsLike(static fn()=>RootpathSandbox::root('testing_runtime'),LogicException::class,'invalid runner ownership marker');
	file_put_contents($marker,json_encode(['format'=>RootpathSandbox::FORMAT],JSON_THROW_ON_ERROR));
	$t->throwsLike(static fn()=>RootpathSandbox::root('testing_runtime'),LogicException::class,'does not match this sandbox');
	file_put_contents($marker,$valid);
	$t->same($root,RootpathSandbox::root('testing_runtime'));

	$victim=$raw.'/cannot-remove.txt';
	file_put_contents($victim,'guarded');
	$seams->put('unlink_failure',$victim);
	$t->throwsLike(static fn()=>RootpathSandbox::reset('testing_runtime'),RuntimeException::class,'Unable to remove ROOTPATH sandbox file');
	$seams->forget('unlink_failure');
	unlink($victim);
	$directory=$raw.'/cannot-remove';
	mkdir($directory);
	$seams->put('rmdir_failure',$directory);
	$t->throwsLike(static fn()=>RootpathSandbox::reset('testing_runtime'),RuntimeException::class,'Unable to remove ROOTPATH sandbox directory');
	$seams->forget('rmdir_failure');
	RootpathSandbox::reset('testing_runtime');
});

test('isolated PHP scenarios prove undefined root filesystem-root and protected-tree failures',static function(Context $t): void {
	$workspace=$t->workspace('rootpath-sandbox-boundaries');
	$target=$workspace->file('rootpath_contract.php',<<<'PHP'
<?php
declare(strict_types=1);
$mode=(string)($argv[1] ?? 'undefined');
$tooling=(string)($argv[2] ?? '');
if($mode!=='undefined'){
	$rootpath=json_decode(base64_decode((string)($argv[3] ?? ''),true),true,512,JSON_THROW_ON_ERROR);
	define('ROOTPATH',$rootpath);
}
require $tooling;
try{
	$root=\Dataphyre\Test\RootpathSandbox::root('sandbox');
	echo json_encode(['root'=>$root],JSON_THROW_ON_ERROR);
}catch(Throwable $failure){
	echo json_encode(['exception'=>$failure::class,'message'=>$failure->getMessage()],JSON_THROW_ON_ERROR);
}
PHP);
	$tooling=dataphyre_path().'/runtime/modules/testing/tooling/bootstrap.php';
	$framework=dataphyre_path();
	$invoke=static function(string $mode,array $rootpath=[])use($t,$target,$tooling,$workspace,$framework): array {
		$payload=base64_encode(json_encode($rootpath,JSON_THROW_ON_ERROR));
		$result=$t->processSucceeded($t->coveredPhpFixture(
			$target,
			[$mode,$tooling,$payload],
			working_directory:$workspace->root(),
			framework_root:$framework,
		));
		return $result->json();
	};

	$t->contains('outside a Dataphyre test worker',$invoke('undefined')['message']);
	$t->contains('filesystem root',$invoke('filesystem-root',['sandbox'=>'/'])['message']);
	$owned=$workspace->directory('protected/owned');
	dp_rootpath_sandbox_write_marker($owned);
	$t->contains("inside immutable ROOTPATH['common_root']",$invoke('inside-protected',[
		'sandbox'=>$owned,
		'common_root'=>$workspace->path('protected'),
	])['message']);
	$unprotected=$workspace->directory('unprotected');
	dp_rootpath_sandbox_write_marker($unprotected);
	$t->same(rtrim(str_replace('\\','/',(string)realpath($unprotected)),'/').'/', $invoke('valid-no-protected',[
		'sandbox'=>$unprotected,
	])['root']);
});
