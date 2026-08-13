<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Mcp\Testing;

use Dataphyre\Mcp\Panel\PanelCapabilityCatalog;
use Dataphyre\Mcp\Panel\PanelCapabilitySource;
use Dataphyre\Mcp\Panel\SourcePanelCapabilityIndex;
use Dataphyre\Test\Contracts\TestContext;
use Dataphyre\Test\TempWorkspace;

require_once dirname(__DIR__).'/kernel/dataphyre_mcp.contract.source.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.contract.index.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.contract.catalog.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.panel.source.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.panel.index.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.panel.catalog.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.inspection.panel.php';

/**
 * A tiny Panel-shaped repository whose declarations explain every fixture seam.
 *
 * The executable test fixture deliberately writes a marker at load time. Panel
 * capability discovery must tokenize it, never require it, so markerExists()
 * is a direct proof that source indexing remains execution-free.
 */
final class McpPanelFixture {
	private TempWorkspace $workspace;

	public function __construct(TestContext $context) {
		$this->workspace=$context->workspace('mcp-panel-capabilities');
		$this->writeCorpus();
	}

	public function root(): string {return $this->workspace->path('dataphyre');}
	public function path(string $relative): string {return $this->workspace->path('dataphyre/'.ltrim($relative,'/'));}
	public function markerExists(): bool {return is_file($this->path('runtime/modules/panel/unit_tests/SOURCE_WAS_EXECUTED'));}

	public function source(int $maxFiles=5000): SourcePanelCapabilityIndex {return new SourcePanelCapabilityIndex($this->root(),$maxFiles);}
	public function catalog(int $maxFiles=5000): PanelCapabilityCatalog {return new PanelCapabilityCatalog($this->source($maxFiles));}

	/** Replaces the platform manifest so one test can name the parser contract it exercises. */
	public function manifest(string $source): void {$this->workspace->file('dataphyre/runtime/modules/panel/Framework/Platform/PanelPlatformManifest.php',$source);}

	private function writeCorpus(): void {
		$this->manifest(<<<'PHP'
<?php
declare(strict_types=1);
namespace Dataphyre\Panel;

final class PanelPlatformManifest {
	public static function catalogue(): array {
		$catalogue=[
			'alpha'=>[
				'prefix'=>'alpha',
				'required'=>['engine'],
				'features'=>[
					'engine'=>AlphaEngine::class,
					'contract'=>AlphaContract::class,
					'manifest'=>self::class,
					'callback'=>'callable',
				],
				'services'=>['alpha.engine'=>'class-string:'.AlphaContract::class],
				'metadata'=>['enabled'=>true,'optional'=>null,'rank'=>-2,'weight'=>1.5,'escaped'=>'alpha\'s'],
			],
			'beta'=>[
				'prefix'=>'beta',
				'required'=>['store'],
				'features'=>['store'=>BetaStore::class,'memory_store'=>BetaMemoryStore::class],
				'services'=>['beta.store'=>BetaStore::class],
				'metadata'=>['enabled'=>false],
			],
		];
		$catalogue['alpha']['features'] += ['conformance'=>\Dataphyre\Panel\Testing\AlphaConformance::class];
		return $catalogue;
	}
}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/panel/Framework/Alpha/AlphaEngine.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Dataphyre\Panel;
final class AlphaEngine implements AlphaContract {public function run(): array {return [];}}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/panel/Framework/Alpha/AlphaContract.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Dataphyre\Panel;
interface AlphaContract {public function run(): array;}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/panel/Framework/Beta/BetaStore.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Dataphyre\Panel;
interface BetaStore {public function snapshot(): array;}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/panel/Framework/Beta/BetaMemoryStore.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Dataphyre\Panel;
final class BetaMemoryStore implements BetaStore {
	public function snapshot(): array {return ['type'=>'beta_snapshot','version'=>1];}
}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/panel/testing/AlphaConformance.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Dataphyre\Panel\Testing;
final class AlphaConformance {public static function verify(object $subject): array {return ['passed'=>true];}}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md',<<<'MD'
# Fixture Panel

## Alpha engine

The alpha engine is an execution-free capability-index fixture.

## Beta snapshots

The beta store represents local snapshot persistence.
MD);
		$this->workspace->file('dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel_Beta.md',<<<'MD'
# Fixture Panel Beta

## Restart contract

Snapshots remain explicit and host-owned.
MD);
		$this->workspace->file('dataphyre/runtime/modules/panel/unit_tests/dataphyre.panel.alpha.test.php',<<<'PHP'
<?php
declare(strict_types=1);
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

file_put_contents(__DIR__.'/SOURCE_WAS_EXECUTED','unsafe');

suite('Panel alpha capability contract')
	->tag('panel','alpha')
	->contract('panel.alpha.behavior',1)
	->watches('type:Dataphyre\Panel\AlphaContract');

test('keeps alpha execution explicit',static function(): void {});
PHP);
		$this->workspace->file('dataphyre/runtime/modules/panel/unit_tests/dataphyre.panel.beta.json',(string)json_encode([
			['name'=>'beta snapshot compatibility','function'=>'fixture_panel_beta','expected'=>['type'=>'beta_snapshot']],
		],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
	}
}

/**
 * Compact semantic corpus for catalog branch coverage.
 *
 * Live inventory truth is proved separately through ordinary PHP. Keeping the
 * semantic corpus small prevents phpdbg's opcode log from growing with every
 * token in the real 1,000+ file Panel source tree.
 */
final class McpPanelSemanticSource implements PanelCapabilitySource {
	private const DOMAINS=[
		'operations_os','operations','distributed_operations','migrations','observability','data','data_surfaces','realtime',
		'workflows','automation','agent_workflows','authentication','iam','studio','notifications','media','localization',
		'preferences','collaboration','packages','relations','security','development','extensions','platform',
	];

	public function snapshot(): array {
		$domains=[];$areas=[];$contracts=[];$tests=[];$documents=[];
		foreach(self::DOMAINS as $name){
			$area=$this->areaName($name);$class='Dataphyre\\Panel\\'.$this->className($name).'Adapter';$path='runtime/modules/panel/Framework/'.$area.'/'.$this->className($name).'Adapter.php';
			$domains[]=[
				'id'=>'panel:domain:'.$name,'kind'=>'platform_domain','name'=>$name,'prefix'=>$name,
				'required_features'=>['adapter'],'feature_count'=>3,'required_feature_count'=>1,'service_count'=>1,
				'features'=>[
					['name'=>'adapter','class'=>$class,'required'=>true,'source_paths'=>[$path],'source_present'=>true,'contract_id'=>'php:'.$class],
					['name'=>'memory_store','class'=>'Dataphyre\\Panel\\'.$this->className($name).'MemoryStore','required'=>false,'source_paths'=>[$path],'source_present'=>true,'contract_id'=>null],
					['name'=>'redis_transport','class'=>'Dataphyre\\Panel\\'.$this->className($name).'RedisTransport','required'=>false,'source_paths'=>[$path],'source_present'=>true,'contract_id'=>null],
				],
				'services'=>[['name'=>$name.'.service','expected'=>$class,'source_paths'=>[$path]]],
				'framework_areas'=>[$area],
			];
			$areas[$area]=$this->area($area,[$name]);
			$contracts[]=['id'=>'php:'.$class,'kind'=>'php_type_contract','name'=>$class,'module'=>'panel','path'=>$path,'line'=>12,'version'=>1,'roles'=>['interface'],'producers'=>[$class]];
			$contracts[]=['id'=>'serialized:panel.'.$name.'.snapshot','kind'=>'serialized_contract','name'=>'panel.'.$name.'.snapshot','module'=>'panel','path'=>$path,'line'=>24,'version'=>1,'roles'=>[],'producers'=>[$class]];
			$tests[]=['path'=>'runtime/modules/panel/unit_tests/dataphyre.panel_'.$name.'.test.php','kind'=>'code','contract_ids'=>['panel.'.$name.'.behavior'],'contract_names'=>['panel.'.$name.'.behavior']];
			$documents[]=['name'=>'Dataphyre_Panel_'.$this->className($name).'.md','path'=>'runtime/modules/panel/documentation/Dataphyre_Panel_'.$this->className($name).'.md','title'=>$this->areaName($name),'headings'=>[['level'=>2,'title'=>$this->areaName($name).' contracts']],'sha256'=>hash('sha256',$name)];
		}
		foreach(['Resources','Forms','Tables','Relations','Widgets','Rendering','Navigation','Schemas','Editors','Testing'] as $area){$areas[$area]??=$this->area($area,[]);}
		$documents[]=['name'=>'Dataphyre_Panel.md','path'=>'runtime/modules/panel/documentation/Dataphyre_Panel.md','title'=>'Dataphyre Panel','headings'=>[['level'=>2,'title'=>'Panel contracts']],'sha256'=>hash('sha256','panel')];
		ksort($areas,SORT_STRING);
		return [
			'snapshot_type'=>'dataphyre_panel_capability_source','source_model_version'=>1,'write_policy'=>'read_only','execution'=>'not_executed','source_strategy'=>'semantic_fixture',
			'domains'=>$domains,'areas'=>array_values($areas),'documents'=>$documents,'tests'=>$tests,'contracts'=>$contracts,'declarations'=>[],
			'counts'=>['domains'=>count($domains),'areas'=>count($areas),'framework_files'=>count($areas),'documents'=>count($documents),'tests'=>count($tests),'indexed_tests'=>count($tests),'contracts'=>count($contracts)],
			'inventory_fingerprint'=>hash('sha256','mcp-panel-semantic-source-v1'),'diagnostics'=>['platform_parse_failures'=>[],'missing_feature_sources'=>[]],
		];
	}

	private function area(string $name,array $domains): array {return ['id'=>'panel:area:'.$this->slug($name),'kind'=>'framework_area','name'=>$name,'path'=>'runtime/modules/panel/Framework/'.$name,'file_count'=>2,'php_file_count'=>2,'extensions'=>['php'=>2],'sample_files'=>['runtime/modules/panel/Framework/'.$name.'/Contract.php','runtime/modules/panel/Framework/'.$name.'/Adapter.php'],'related_domains'=>$domains];}
	private function className(string $name): string {return str_replace(' ','',ucwords(str_replace('_',' ',$name)));}
	private function areaName(string $name): string {return $this->className($name);}
	private function slug(string $value): string {return trim(preg_replace('/[^a-z0-9]+/','_',strtolower($value))??'','_');}
}

/** Directly covers every inspection wrapper against a tiny source repository. */
final class McpPanelInspectionHarness {
	use \dataphyre_mcp_inspection_panel_surfaces;
	private string $common_root;

	public function __construct(string $dataphyreRoot) {$this->common_root=dirname($dataphyreRoot);}
	/** @return array<string,mixed> */
	public function invoke(string $surface,array $arguments=[]): array {return match($surface){
		'catalog'=>$this->panel_capability_catalog($arguments),
		'describe'=>$this->panel_capability_describe($arguments),
		'graph'=>$this->panel_surface_graph($arguments),
		'recipe'=>$this->panel_recipe_plan($arguments),
		'integration'=>$this->panel_integration_plan($arguments),
		'verification'=>$this->panel_verification_plan($arguments),
		'resource'=>$this->panel_resource_snapshot(),
		default=>throw new \InvalidArgumentException('Unknown Panel inspection surface: '.$surface),
	};}
	private function normalize_path(string $path): string {return rtrim(str_replace('\\','/',$path),'/');}
}

/** Intent-named selectors keep capability assertions about behavior, not array plumbing. */
final class McpPanelProbe {
	/** Runs the real source inventory outside debugger oplog capture. */
	public static function liveSnapshot(TestContext $context): array {
		$root=\Dataphyre\Test\dataphyre_path();
		$process=$context->phpProcess([__DIR__.'/fixtures/mcp_panel_live_snapshot.php',$root],'',dirname($root),[],180000);
		if(!$process->succeeded()){throw new \RuntimeException('Panel live source probe failed: '.$process->stderr());}
		$snapshot=json_decode($process->stdout(),true,512,JSON_THROW_ON_ERROR);
		if(!is_array($snapshot)){throw new \RuntimeException('Panel live source probe did not return an object.');}
		return $snapshot;
	}
	public static function semanticCatalog(): PanelCapabilityCatalog {return new PanelCapabilityCatalog(new McpPanelSemanticSource());}
	/** @return array<string,mixed> */
	public static function domain(array $snapshot,string $name): array {return self::named($snapshot['domains']??[],$name,'Panel domain');}
	/** @return array<string,mixed> */
	public static function feature(array $domain,string $name): array {return self::named($domain['features']??[],$name,'Panel feature');}
	/** @return array<string,mixed> */
	public static function record(array $catalog,string $id): array {
		foreach($catalog['records']??[] as $record){if(($record['id']??null)===$id){return $record;}}
		throw new \OutOfBoundsException('Panel catalog record was not returned: '.$id);
	}
	/** @return list<string> */
	public static function names(array $rows): array {return array_values(array_map(static fn(array $row): string=>(string)($row['name']??''),$rows));}

	/** @return array<string,mixed> */
	private static function named(array $rows,string $name,string $kind): array {
		foreach($rows as $row){if(($row['name']??null)===$name){return $row;}}
		throw new \OutOfBoundsException($kind.' was not found: '.$name);
	}
}
