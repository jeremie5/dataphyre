<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	require_once __DIR__.'/panel_test_probes.php';

	if(!function_exists(__NAMESPACE__.'\\mkdir')){
		function mkdir(string $directory,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {
			if(\Dataphyre\Panel\TestFixtures\ScaffoldFilesystemScenario::directoryCreationShouldFail()){
				return false;
			}
			return $context===null
				? \mkdir($directory,$permissions,$recursive)
				: \mkdir($directory,$permissions,$recursive,$context);
		}
		function file_put_contents(string $filename,mixed $data,int $flags=0,mixed $context=null): int|false {
			if(\Dataphyre\Panel\TestFixtures\ScaffoldFilesystemScenario::writeShouldFail()){
				return false;
			}
			return $context===null
				? \file_put_contents($filename,$data,$flags)
				: \file_put_contents($filename,$data,$flags,$context);
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelBrowserRegressionManifest;
	use Dataphyre\Panel\PanelDataJobResult;
	use Dataphyre\Panel\PanelDocumentationCatalog;
	use Dataphyre\Panel\PanelRegressionSuite;
	use Dataphyre\Panel\PanelScaffoldResult;
	use Dataphyre\Panel\TestFixtures\ScaffoldFilesystemScenario;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['panel']);

	test('panel data job results cover identity progress terminal state and complete serialization',static function(Context $t): void {
		$result=PanelDataJobResult::make([
			'id'=>'job-42','type'=>' Bulk Export ','name'=>' Customer Orders ','status'=>'Completed With Failures',
			'total'=>4,'processed'=>6,'succeeded'=>3,'failed'=>1,
			'failures'=>[['row'=>4]],'artifacts'=>[['path'=>'orders.csv']],
			'events'=>[['type'=>'finished']],'metadata'=>['tenant'=>'north'],
		]);
		$t->same('job-42',$result->id());
		$t->same('bulk_export',$result->type());
		$t->same('customer_orders',$result->name());
		$t->same('completed_with_failures',$result->status());
		$t->same(4,$result->total());
		$t->same(6,$result->processed());
		$t->same(3,$result->succeeded());
		$t->same(1,$result->failed());
		$t->same([['row'=>4]],$result->failures());
		$t->same([['path'=>'orders.csv']],$result->artifacts());
		$t->same([['type'=>'finished']],$result->events());
		$t->same(['tenant'=>'north'],$result->metadata());
		$t->same(100,$result->percent());
		$t->same(true,$result->ok());
		$array=$result->jsonSerialize();
		$t->same('job-42',$array['id']);
		$t->same(100,$array['percent']);
		$t->same(['tenant'=>'north'],$array['metadata']);

		$unknown=PanelDataJobResult::make(['total'=>0,'processed'=>0]);
		$t->same(100,$unknown->percent());
		$t->same(false,$unknown->ok());
	})->tag('panel','data-job','result','coverage')->group('framework-coverage');

	test('panel documentation catalog covers keyed hydration builders filters search metadata and lookups',static function(Context $t): void {
		$catalog=PanelDocumentationCatalog::make([
			'getting-started'=>[
				'title'=>'Getting Started','category'=>'Guides','status'=>'Published','summary'=>'Install the panel',
				'api'=>['Panel::make'],'examples'=>[['title'=>'Boot','code'=>'Panel::make();']],
				'links'=>[['label'=>'Guide','target'=>'/docs/guide']],'tags'=>['install'],
			],
			'api-reference'=>[
				'title'=>'API Reference','category'=>'Reference','status'=>'Draft','summary'=>'Methods and types',
			],
		]);
		$blank=$catalog->entry(' ','Fallback title');
		$t->same('documentation_entry',$blank->id());
		$t->same($blank,$catalog->entry('documentation entry','Ignored title'));
		$t->same(true,$catalog->has('getting-started'));
		$t->same('Getting Started',$catalog->get('getting-started')?->title());
		$t->same(null,$catalog->get('missing'));
		$t->same(3,count($catalog->entries()));
		$t->same(['getting-started'],array_map(static fn($entry): string=>$entry->id(),$catalog->entries(' Guides ')));
		$t->same(['getting-started'],array_map(static fn($entry): string=>$entry->id(),$catalog->entries(null,' Published ')));
		$t->same(['getting-started'],array_map(static fn($entry): string=>$entry->id(),$catalog->search('install')));
		$catalog->meta(' owner ','docs')->meta('   ','ignored');
		$manifest=$catalog->manifest(['request'=>'coverage']);
		$t->same('docs',$manifest['meta']['owner']);
		$t->same('coverage',$manifest['meta']['request']);
		$t->same(1,$manifest['example_count']);
		$t->same(1,$manifest['api_reference_count']);
		$t->same(1,$manifest['link_count']);
		$t->same($catalog->toArray(),$catalog->jsonSerialize());
	})->tag('panel','documentation','catalog','coverage')->group('framework-coverage');

	test('panel scaffold results cover guarded filesystem writes serialization and string previews',static function(Context $t): void {
		$filesystem=ScaffoldFilesystemScenario::reset($t);
		$blank=PanelScaffoldResult::make('','','','','source');
		$t->throws(static fn()=>$blank->write(),InvalidArgumentException::class);

		$root=$t->workspace('panel-scaffold-result')->root();
		$target=$root.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'Artifact.php';
		$result=PanelScaffoldResult::make(' Page ',' Reports ',' \\App\\Panel\\ReportsPage ',$target,'<?php echo "report";',['source'=>'test']);
		$t->same($result,$result->write());
		$t->same('<?php echo "report";',file_get_contents($target));
		$t->throws(static fn()=>$result->write(),RuntimeException::class);
		$t->same($result,$result->write(null,true));

		$filesystem->failDirectoryCreation();
		$t->throws(static fn()=>$result->write($root.DIRECTORY_SEPARATOR.'missing'.DIRECTORY_SEPARATOR.'mkdir.php'),RuntimeException::class);
		$filesystem->failDirectoryCreation(false)->failWrites();
		$t->throws(static fn()=>$result->write($root.DIRECTORY_SEPARATOR.'write-failure.php'),RuntimeException::class);
		$filesystem->failWrites(false);
		$array=$result->jsonSerialize();
		$t->same('page',$array['kind']);
		$t->same('reports',$array['name']);
		$t->same('App\\Panel\\ReportsPage',$array['class']);
		$t->same(strlen('<?php echo "report";'),$array['bytes']);
		$t->same(['source'=>'test'],$array['metadata']);
		$t->same('<?php echo "report";',(string)$result);
	})->tag('panel','scaffold','result','coverage')->group('framework-coverage');

	test('panel regression suites cover ignored registrations object browsers invalid URLs and captured false checks',static function(Context $t): void {
		$suite=PanelRegressionSuite::make(' Remaining Suite ');
		$t->same($suite,$suite->check(' ',static fn()=>true));
		$t->same($suite,$suite->skip(' '));
		$suite->meta(' owner ','quality')->meta('   ','ignored');
		$browser=PanelBrowserRegressionManifest::make('orders','/panel/orders');
		$t->same($suite,$suite->browser($browser));
		$t->throws(static fn()=>$suite->browser('missing-url'),InvalidArgumentException::class);
		$suite
			->check('false result',static fn()=>false,['kind'=>'assertion'])
			->check('exception result',static fn()=>throw new RuntimeException('explicit failure'));
		$t->same(2,$suite->count());
		$t->same('remaining_suite',$suite->name());
		$t->producesStableResult(static fn()=>$suite->harness());
		$report=$suite->run(['run'=>'coverage']);
		$rows=$report->results();
		$t->same('failed',$rows[0]['status']);
		$t->same('Check returned false.',$rows[0]['message']);
		$t->same(AssertionError::class,$rows[0]['details']['exception']);
		$t->same('failed',$rows[1]['status']);
		$t->same('explicit failure',$rows[1]['message']);
		$t->same(RuntimeException::class,$rows[1]['details']['exception']);
		$t->same($report,$suite->report());
		$manifest=$suite->manifest();
		$t->same(1,$manifest['browser_count']);
		$t->same('quality',$manifest['meta']['owner']);
		$t->same($suite->toArray(),$suite->jsonSerialize());
	})->tag('panel','regression','suite','coverage')->group('framework-coverage');
}
