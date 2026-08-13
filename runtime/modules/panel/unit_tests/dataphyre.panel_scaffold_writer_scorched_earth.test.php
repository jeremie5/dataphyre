<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel\TestFixtures {
	final class PanelScaffoldWriterProbe {
		public static ?string $failRenameFrom=null;
		public static ?string $failRenameTo=null;
		public static ?string $failUnlink=null;
		public static ?string $failRestoreTo=null;
		public static ?string $fakeLink=null;
		public static ?string $failRealpath=null;
		public static ?string $failRead=null;
		public static ?string $failHash=null;
		public static ?string $failMkdir=null;
		public static bool $failTransactionMkdir=false;
		public static bool $failStageWrite=false;
		public static ?\Closure $afterStage=null;

		public static function reset(): void {
			self::$failRenameFrom=null;
			self::$failRenameTo=null;
			self::$failUnlink=null;
			self::$failRestoreTo=null;
			self::$fakeLink=null;
			self::$failRealpath=null;
			self::$failRead=null;
			self::$failHash=null;
			self::$failMkdir=null;
			self::$failTransactionMkdir=false;
			self::$failStageWrite=false;
			self::$afterStage=null;
		}

		public static function rejectsRename(string $from,string $to): bool {
			$fromMatch=self::$failRenameFrom!==null && str_contains(str_replace('\\','/',$from),self::$failRenameFrom);
			$toMatch=self::$failRenameTo===null || str_contains(str_replace('\\','/',$to),self::$failRenameTo);
			if($fromMatch && $toMatch){ self::$failRenameFrom=null; self::$failRenameTo=null; return true; }
			return false;
		}
	}
}

namespace Dataphyre\Panel {
	use Dataphyre\Panel\TestFixtures\PanelScaffoldWriterProbe;

	function rename(string $from,string $to,mixed $context=null): bool {
		if(PanelScaffoldWriterProbe::rejectsRename($from,$to)){ return false; }
		if(PanelScaffoldWriterProbe::$failRestoreTo!==null && str_contains(str_replace('\\','/',$from),'backup-') && str_contains(str_replace('\\','/',$to),PanelScaffoldWriterProbe::$failRestoreTo)){
			PanelScaffoldWriterProbe::$failRestoreTo=null;
			return false;
		}
		return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
	}

	function is_link(string $filename): bool {
		if(PanelScaffoldWriterProbe::$fakeLink!==null && str_contains(str_replace('\\','/',$filename),PanelScaffoldWriterProbe::$fakeLink)){ return true; }
		return \is_link($filename);
	}

	function realpath(string $path): string|false {
		if(PanelScaffoldWriterProbe::$failRealpath!==null && str_contains(str_replace('\\','/',$path),PanelScaffoldWriterProbe::$failRealpath)){
			PanelScaffoldWriterProbe::$failRealpath=null;
			return false;
		}
		return \realpath($path);
	}

	function file_get_contents(string $filename,bool $useIncludePath=false,mixed $context=null,int $offset=0,?int $length=null): string|false {
		if(PanelScaffoldWriterProbe::$failRead!==null && str_contains(str_replace('\\','/',$filename),PanelScaffoldWriterProbe::$failRead)){
			PanelScaffoldWriterProbe::$failRead=null;
			return false;
		}
		if($length===null){ return $context===null ? \file_get_contents($filename,$useIncludePath,null,$offset) : \file_get_contents($filename,$useIncludePath,$context,$offset); }
		return $context===null ? \file_get_contents($filename,$useIncludePath,null,$offset,$length) : \file_get_contents($filename,$useIncludePath,$context,$offset,$length);
	}

	function hash_file(string $algo,string $filename,bool $binary=false,array $options=[]): string|false {
		if(PanelScaffoldWriterProbe::$failHash!==null && str_contains(str_replace('\\','/',$filename),PanelScaffoldWriterProbe::$failHash)){
			PanelScaffoldWriterProbe::$failHash=null;
			return false;
		}
		return \hash_file($algo,$filename,$binary,$options);
	}

	function unlink(string $filename,mixed $context=null): bool {
		if(PanelScaffoldWriterProbe::$failUnlink!==null && str_contains(str_replace('\\','/',$filename),PanelScaffoldWriterProbe::$failUnlink)){
			PanelScaffoldWriterProbe::$failUnlink=null;
			return false;
		}
		return $context===null ? \unlink($filename) : \unlink($filename,$context);
	}

	function mkdir(string $directory,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {
		if(PanelScaffoldWriterProbe::$failTransactionMkdir && str_contains($directory,'.dataphyre-panel-scaffold-')){
			PanelScaffoldWriterProbe::$failTransactionMkdir=false;
			return false;
		}
		if(PanelScaffoldWriterProbe::$failMkdir!==null && str_contains(str_replace('\\','/',$directory),PanelScaffoldWriterProbe::$failMkdir)){
			PanelScaffoldWriterProbe::$failMkdir=null;
			return false;
		}
		return $context===null ? \mkdir($directory,$permissions,$recursive) : \mkdir($directory,$permissions,$recursive,$context);
	}

	function file_put_contents(string $filename,mixed $data,int $flags=0,mixed $context=null): int|false {
		if(PanelScaffoldWriterProbe::$failStageWrite && str_contains($filename,'.dataphyre-panel-scaffold-')){
			PanelScaffoldWriterProbe::$failStageWrite=false;
			return false;
		}
		$result=$context===null ? \file_put_contents($filename,$data,$flags) : \file_put_contents($filename,$data,$flags,$context);
		if($result!==false && str_contains($filename,'.dataphyre-panel-scaffold-') && PanelScaffoldWriterProbe::$afterStage instanceof \Closure){
			$callback=PanelScaffoldWriterProbe::$afterStage;
			PanelScaffoldWriterProbe::$afterStage=null;
			$callback($filename);
		}
		return $result;
	}
}

namespace {
	use Dataphyre\Panel\PanelScaffoldResult;
	use Dataphyre\Panel\PanelScaffoldWriteResult;
	use Dataphyre\Panel\PanelScaffoldWriter;
	use Dataphyre\Panel\TestFixtures\PanelScaffoldWriterProbe;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['panel']);

	function dp_scaffold_artifact(string $path,string $contents,string $name='artifact'): PanelScaffoldResult {
		return PanelScaffoldResult::make('resource',$name,'App\\Panel\\Resources\\'.ucfirst($name),$path,$contents);
	}

	test('panel scaffold writer discovers composer namespaces with deterministic convention fallback',static function(Context $t): void {
		PanelScaffoldWriterProbe::reset();
		$root=$t->tempDirectory('panel-scaffold-namespace');
		file_put_contents($root.DIRECTORY_SEPARATOR.'composer.json',json_encode([
			'autoload'=>['psr-4'=>[
				'App\\'=>'app/',
				'Domain\\'=>['src/Domain/','legacy/domain/'],
				'Fallback\\'=>'vendor/fallback/',
				'Bad-\\'=>'bad/',
			]],
			'autoload-dev'=>['psr-4'=>['Tests\\'=>'tests/']],
		],JSON_THROW_ON_ERROR));
		$t->same(['namespace'=>'App\\Panel','base_path'=>'app/Panel','source'=>'composer'],PanelScaffoldWriter::discoverNamespace($root));
		$t->same(['namespace'=>'Domain\\Admin','base_path'=>'src/Domain/Admin','source'=>'composer'],PanelScaffoldWriter::discoverNamespace($root,'src\\Domain\\Admin'));
		$t->same(['namespace'=>'Domain\\AdminTools\\Generated2fa','base_path'=>'src/Domain/admin-tools/2fa','source'=>'composer'],PanelScaffoldWriter::discoverNamespace($root,'src/Domain/admin-tools/2fa'));
		$t->same(['namespace'=>'Tests\\Panel','base_path'=>'tests/Panel','source'=>'composer-dev'],PanelScaffoldWriter::discoverNamespace($root,'tests/Panel'));
		$t->same(['namespace'=>'Custom\\PanelArea','base_path'=>'custom/panel-area','source'=>'convention'],PanelScaffoldWriter::discoverNamespace($root,'custom/panel-area'));
		$t->same(['namespace'=>'Custom\\Generated123Tools','base_path'=>'custom/123-tools','source'=>'convention'],PanelScaffoldWriter::discoverNamespace($root,'custom/123-tools'));
		$t->throws(static fn()=>PanelScaffoldWriter::discoverNamespace($root,'bad/path'),InvalidArgumentException::class);
		$t->same(['namespace'=>'App\\Panel','base_path'=>'app/Panel','source'=>'composer'],PanelScaffoldWriter::discoverNamespace($root,'./app/Other/../Panel'));

		$t->throws(static fn()=>PanelScaffoldWriter::make(''),InvalidArgumentException::class);
		$t->throws(static fn()=>PanelScaffoldWriter::make($root.DIRECTORY_SEPARATOR.'missing'),InvalidArgumentException::class);
		$t->throws(static fn()=>PanelScaffoldWriter::discoverNamespace($root,''),InvalidArgumentException::class);
		$t->throws(static fn()=>PanelScaffoldWriter::discoverNamespace($root,'../outside'),InvalidArgumentException::class);
		file_put_contents($root.DIRECTORY_SEPARATOR.'composer.json','{broken');
		$t->throws(static fn()=>PanelScaffoldWriter::discoverNamespace($root),InvalidArgumentException::class);
		PanelScaffoldWriterProbe::$failRead='composer.json';
		$t->throws(static fn()=>PanelScaffoldWriter::discoverNamespace($root),RuntimeException::class);
		unlink($root.DIRECTORY_SEPARATOR.'composer.json');
		$t->same('convention',PanelScaffoldWriter::discoverNamespace($root,'app/Panel')['source']);
		PanelScaffoldWriterProbe::$fakeLink=str_replace('\\','/',basename($root));
		$t->throws(static fn()=>PanelScaffoldWriter::make($root),InvalidArgumentException::class);
		PanelScaffoldWriterProbe::$fakeLink=null;
		PanelScaffoldWriterProbe::$failRealpath=str_replace('\\','/',basename($root));
		$t->throws(static fn()=>PanelScaffoldWriter::make($root),RuntimeException::class);
	})->tag('panel','scaffolding','namespace','security')->group('framework-coverage');

	test('panel scaffold writer plans creates skips and replaces without leaking source contents',static function(Context $t): void {
		PanelScaffoldWriterProbe::reset();
		$root=$t->tempDirectory('panel-scaffold-writer');
		$writer=PanelScaffoldWriter::make($root);
		$t->same(realpath($root),$writer->root());
		$first=dp_scaffold_artifact('app/Panel/Resources/OrderResource.php','<?php // first','orders');
		$second=dp_scaffold_artifact('tests/Panel/OrderResourceTest.php','<?php // test','orders_test');
		$plan=$writer->apply([$first,$second],'error',true);
		$t->instanceOf(PanelScaffoldWriteResult::class,$plan);
		$t->isTrue($plan->dryRun());
		$t->same('error',$plan->policy());
		$t->same($writer->root(),$plan->root());
		$t->same(2,count($plan->created()));
		$t->same([],$plan->replaced());
		$t->same([],$plan->skipped());
		$t->isTrue($plan->changed());
		$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.$first->path()));
		$t->notContains('<?php // first',json_encode($plan,JSON_THROW_ON_ERROR));
		$t->same('planned',$plan->jsonSerialize()['status']);

		$applied=$writer->apply([$first,$second]);
		$t->isFalse($applied->dryRun());
		$t->same('applied',$applied->jsonSerialize()['status']);
		$t->same('<?php // first',file_get_contents($root.DIRECTORY_SEPARATOR.$first->path()));
		$t->same('<?php // test',file_get_contents($root.DIRECTORY_SEPARATOR.$second->path()));
		$t->same(2,$applied->jsonSerialize()['counts']['created']);
		$t->same(0,$applied->jsonSerialize()['counts']['skipped']);

		$identical=$writer->apply([$first,$second]);
		$t->isFalse($identical->changed());
		$t->same(2,count($identical->skipped()));
		$t->same('identical',$identical->entries()[0]['operation']);

		$changed=dp_scaffold_artifact($first->path(),'<?php // changed','orders');
		$t->throws(static fn()=>$writer->apply([$changed]),RuntimeException::class);
		$skipped=$writer->apply([$changed],'skip');
		$t->same('skip',$skipped->entries()[0]['operation']);
		$t->same('<?php // first',file_get_contents($root.DIRECTORY_SEPARATOR.$first->path()));
		$replaced=$writer->apply([$changed],'replace');
		$t->same(1,count($replaced->replaced()));
		$t->same('<?php // changed',file_get_contents($root.DIRECTORY_SEPARATOR.$first->path()));
		$t->same(1,$replaced->jsonSerialize()['counts']['replaced']);
	})->tag('panel','scaffolding','transaction','conflicts')->group('framework-coverage');

	test('panel scaffold writer rejects traversal collisions links and malformed transactions before mutation',static function(Context $t): void {
		PanelScaffoldWriterProbe::reset();
		$root=$t->tempDirectory('panel-scaffold-rejections');
		$writer=PanelScaffoldWriter::make($root);
		$t->throws(static fn()=>$writer->apply([]),InvalidArgumentException::class);
		$t->throws(static fn()=>$writer->apply([new stdClass()]),InvalidArgumentException::class);
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('valid.php','x')],'overwrite'),InvalidArgumentException::class);
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('','x')]),InvalidArgumentException::class);
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact("bad\0path.php",'x')]),InvalidArgumentException::class);
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact("bad\x01path.php",'x')]),InvalidArgumentException::class);
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('../outside.php','x')]),InvalidArgumentException::class);
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact(dirname($root).DIRECTORY_SEPARATOR.'outside.php','x')]),InvalidArgumentException::class);
		$t->same(realpath($root).DIRECTORY_SEPARATOR.'absolute.php',$writer->apply([dp_scaffold_artifact($root.DIRECTORY_SEPARATOR.'absolute.php','x')],'error',true)->entries()[0]['path']);
		if(DIRECTORY_SEPARATOR==='\\'){
			$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('bad?.php','x')]),InvalidArgumentException::class);
			$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('trailing./file.php','x')]),InvalidArgumentException::class);
			$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('CON.php','x')]),InvalidArgumentException::class);
			$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('LPT9.txt','x')]),InvalidArgumentException::class);
		}
		$t->throws(static fn()=>$writer->apply([
			dp_scaffold_artifact('Same.php','one','one'),dp_scaffold_artifact('same.php','two','two'),
		]),LogicException::class);

		mkdir($root.DIRECTORY_SEPARATOR.'directory-target');
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('directory-target','x')]),RuntimeException::class);
		file_put_contents($root.DIRECTORY_SEPARATOR.'parent-file','not a directory');
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('parent-file/child.php','x')]),RuntimeException::class);
		file_put_contents($root.DIRECTORY_SEPARATOR.'hash.php','hash me');
		PanelScaffoldWriterProbe::$failHash='hash.php';
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('hash.php','other')]),RuntimeException::class);
		mkdir($root.DIRECTORY_SEPARATOR.'unresolvable');
		PanelScaffoldWriterProbe::$failRealpath='unresolvable';
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('unresolvable/child.php','x')]),RuntimeException::class);
		PanelScaffoldWriterProbe::$fakeLink='fake-link';
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('fake-link/child.php','x')]),RuntimeException::class);
		PanelScaffoldWriterProbe::$fakeLink=null;

		$outside=$t->tempDirectory('panel-scaffold-outside');
		$link=$root.DIRECTORY_SEPARATOR.'linked';
		if(@symlink($outside,$link)){
			$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('linked/escape.php','x')]),RuntimeException::class);
			$t->throws(static fn()=>PanelScaffoldWriter::make($link),InvalidArgumentException::class);
		}
		else{
			$t->isFalse(is_link($link));
		}

		$writerType=$t->nonPublic(PanelScaffoldWriter::class);
		$synthetic=$writerType->withoutConstructor();
		$syntheticInternals=$t->nonPublic($synthetic)->writeProperty('root','\\\\server\\share\\project');
		$t->same($t->nativePath('//server/share/project/nested/file.php'),$syntheticInternals->invoke('target','nested/file.php'));
		$posix=$writerType->withoutConstructor();
		$posixInternals=$t->nonPublic($posix)->writeProperty('root','/srv/project');
		$t->same($t->nativePath('/srv/project/file.php'),$posixInternals->invoke('target','/srv/project/file.php'));
	})->tag('panel','scaffolding','security','confinement')->group('framework-coverage');

	test('panel scaffold writer rolls back staged and partially published failures',static function(Context $t): void {
		PanelScaffoldWriterProbe::reset();
		$root=$t->tempDirectory('panel-scaffold-rollback');
		$writer=PanelScaffoldWriter::make($root);
		$one=dp_scaffold_artifact('one.php','new-one','one');
		$two=dp_scaffold_artifact('two.php','new-two','two');

		PanelScaffoldWriterProbe::$failTransactionMkdir=true;
		$t->throws(static fn()=>$writer->apply([$one]),RuntimeException::class);
		PanelScaffoldWriterProbe::$failStageWrite=true;
		$t->throws(static fn()=>$writer->apply([$one]),RuntimeException::class);
		$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'one.php'));

		PanelScaffoldWriterProbe::$failRenameFrom='artifact-1';
		PanelScaffoldWriterProbe::$failRenameTo='two.php';
		$t->throws(static fn()=>$writer->apply([$one,$two]),RuntimeException::class);
		$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'one.php'));
		$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'two.php'));

		$nested=dp_scaffold_artifact('new/deep/one.php','nested-one','nested');
		PanelScaffoldWriterProbe::$failRenameFrom='artifact-1';
		PanelScaffoldWriterProbe::$failRenameTo='two.php';
		$t->throws(static fn()=>$writer->apply([$nested,$two]),RuntimeException::class);
		$t->isFalse(is_dir($root.DIRECTORY_SEPARATOR.'new'));

		file_put_contents($root.DIRECTORY_SEPARATOR.'one.php','old-one');
		file_put_contents($root.DIRECTORY_SEPARATOR.'two.php','old-two');
		PanelScaffoldWriterProbe::$failRenameFrom='artifact-1';
		PanelScaffoldWriterProbe::$failRenameTo='two.php';
		$t->throws(static fn()=>$writer->apply([$one,$two],'replace'),RuntimeException::class);
		$t->same('old-one',file_get_contents($root.DIRECTORY_SEPARATOR.'one.php'));
		$t->same('old-two',file_get_contents($root.DIRECTORY_SEPARATOR.'two.php'));

		PanelScaffoldWriterProbe::$failRenameFrom='one.php';
		PanelScaffoldWriterProbe::$failRenameTo='backup-0';
		$t->throws(static fn()=>$writer->apply([$one],'replace'),RuntimeException::class);
		$t->same('old-one',file_get_contents($root.DIRECTORY_SEPARATOR.'one.php'));

		PanelScaffoldWriterProbe::$failMkdir='new-parent';
		$t->throws(static fn()=>$writer->apply([dp_scaffold_artifact('new-parent/child.php','x')]),RuntimeException::class);

		$recoveryRoot=$t->tempDirectory('panel-scaffold-recovery');
		$recoveryWriter=PanelScaffoldWriter::make($recoveryRoot);
		PanelScaffoldWriterProbe::$failRenameFrom='artifact-1';
		PanelScaffoldWriterProbe::$failRenameTo='two.php';
		PanelScaffoldWriterProbe::$failUnlink='one.php';
		try{
			$recoveryWriter->apply([$one,$two]);
			$t->fail('Expected rollback preservation failure.');
		}
		catch(RuntimeException $exception){
			$t->contains('recovery artifacts were preserved',$exception->getMessage());
		}

		$restoreRoot=$t->tempDirectory('panel-scaffold-restore-recovery');
		file_put_contents($restoreRoot.DIRECTORY_SEPARATOR.'one.php','restore-old');
		file_put_contents($restoreRoot.DIRECTORY_SEPARATOR.'two.php','restore-two-old');
		$restoreWriter=PanelScaffoldWriter::make($restoreRoot);
		PanelScaffoldWriterProbe::$failRenameFrom='artifact-1';
		PanelScaffoldWriterProbe::$failRenameTo='two.php';
		PanelScaffoldWriterProbe::$failRestoreTo='one.php';
		try{
			$restoreWriter->apply([$one,$two],'replace');
			$t->fail('Expected backup preservation failure.');
		}
		catch(RuntimeException $exception){
			$t->contains('restore ',$exception->getMessage());
			$t->contains('one.php',$exception->getMessage());
		}
	})->tag('panel','scaffolding','transaction','rollback')->group('framework-coverage');

	test('panel scaffold writer detects create and replacement races after staging',static function(Context $t): void {
		PanelScaffoldWriterProbe::reset();
		$root=$t->tempDirectory('panel-scaffold-races');
		$writer=PanelScaffoldWriter::make($root);
		$target=$root.DIRECTORY_SEPARATOR.'race.php';
		$artifact=dp_scaffold_artifact('race.php','generated');
		PanelScaffoldWriterProbe::$afterStage=static function()use($target): void { file_put_contents($target,'racer'); };
		$t->throws(static fn()=>$writer->apply([$artifact]),RuntimeException::class);
		$t->same('racer',file_get_contents($target));

		file_put_contents($target,'original');
		PanelScaffoldWriterProbe::$afterStage=static function()use($target): void { file_put_contents($target,'changed-during-transaction'); };
		$t->throws(static fn()=>$writer->apply([$artifact],'replace'),RuntimeException::class);
		$t->same('changed-during-transaction',file_get_contents($target));

		$nestedRoot=$t->tempDirectory('panel-scaffold-cleanup-nested');
		$nestedWriter=PanelScaffoldWriter::make($nestedRoot);
		PanelScaffoldWriterProbe::$afterStage=static function(string $stage): void {
			$directory=dirname($stage).DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'deeper';
			mkdir($directory,0777,true);
			file_put_contents($directory.DIRECTORY_SEPARATOR.'marker','cleanup');
		};
		$nestedWriter->apply([dp_scaffold_artifact('nested-result.php','nested')]);
		$t->same([],glob($nestedRoot.DIRECTORY_SEPARATOR.'.dataphyre-panel-scaffold-*') ?: []);
	})->tag('panel','scaffolding','transaction','toctou')->group('framework-coverage');
}
