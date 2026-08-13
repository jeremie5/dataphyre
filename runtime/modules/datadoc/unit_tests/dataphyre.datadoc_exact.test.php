<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use Dataphyre\Test\ProcessResult;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/datadoc_test_helpers.php';
require_once dirname(__DIR__,2).'/sql/Framework/TableDefinition.php';

if(!defined('DP_DATADOC_EXACT_SQL_PROBE_LOADED') && !class_exists(DatadocExactSqlProbe::class,false)){
	define('DP_DATADOC_EXACT_SQL_PROBE_LOADED', true);
	/** Queue-backed storage boundary for exact DataDoc branch contracts. */
	final class DatadocExactSqlProbe {
		private static array $selects=[];
		private static array $counts=[];
		private static array $queries=[];
		public static array $calls=[];

		public static function reset(): void {
			self::$selects=[];
			self::$counts=[];
			self::$queries=[];
			self::$calls=[];
		}

		public static function select(mixed ...$responses): void { array_push(self::$selects,...$responses); }
		public static function count(mixed ...$responses): void { array_push(self::$counts,...$responses); }
		public static function query(mixed ...$responses): void { array_push(self::$queries,...$responses); }
		public static function takeSelect(array $arguments): mixed {
			self::$calls[]=['select',$arguments];
			return self::$selects!==[] ? array_shift(self::$selects) : [];
		}
		public static function takeCount(array $arguments): mixed {
			self::$calls[]=['count',$arguments];
			return self::$counts!==[] ? array_shift(self::$counts) : 0;
		}
		public static function takeQuery(array $arguments): mixed {
			self::$calls[]=['query',$arguments];
			return self::$queries!==[] ? array_shift(self::$queries) : true;
		}
	}
}

if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed { return DatadocExactSqlProbe::takeSelect($arguments); }
}
if(!function_exists('sql_count')){
	function sql_count(mixed ...$arguments): mixed { return DatadocExactSqlProbe::takeCount($arguments); }
}
if(!function_exists('sql_query')){
	function sql_query(mixed ...$arguments): mixed { return DatadocExactSqlProbe::takeQuery($arguments); }
}
if(!function_exists('sql_insert')){
	function sql_insert(mixed ...$arguments): bool { return true; }
}
if(!function_exists('sql_update')){
	function sql_update(mixed ...$arguments): bool { return true; }
}
if(!function_exists('sql_delete')){
	function sql_delete(mixed ...$arguments): bool { return true; }
}

if(!defined('DP_DATADOC_EXACT_SQL_RUNTIME_LOADED') && !class_exists(DatadocExactSqlRuntime::class,false)){
	define('DP_DATADOC_EXACT_SQL_RUNTIME_LOADED', true);
	final class DatadocExactSqlRuntime {
		public static function hydrate_table_definition(string $table): bool { return true; }
		public static function last_query_error(): array { return []; }
	}
}
if(!class_exists('dataphyre\\sql',false)){
	class_alias(DatadocExactSqlRuntime::class,'dataphyre\\sql');
}

if(!defined('DP_DATADOC_UI_ENTRYPOINT_SCENARIO_LOADED') && !class_exists(DatadocUiEntrypointScenario::class,false)){
	define('DP_DATADOC_UI_ENTRYPOINT_SCENARIO_LOADED',true);
	/** Coverage-carrying request vocabulary for standalone DataDoc PHP entrypoints. */
	final class DatadocUiEntrypointScenario {
		private string $frameworkRoot;
		private string $uiDirectory;
		private string $fixture;
		private string $assetFixture;
		private string $loginWithoutFacadeFixture;
		private string $workingDirectory;

		public function __construct(private Context $test) {
			$this->frameworkRoot=dirname(__DIR__,4);
			$this->uiDirectory=dirname(__DIR__).'/ui';
			$this->fixture=__DIR__.'/fixtures/datadoc_ui_entrypoint_probe.php';
			$this->assetFixture=__DIR__.'/fixtures/datadoc_ui_asset_entrypoint_probe.php';
			$this->loginWithoutFacadeFixture=__DIR__.'/fixtures/datadoc_login_without_facade_probe.php';
			$this->workingDirectory=$test->workspace('datadoc-ui-entrypoints')->root();
		}

		public function render(string $view,string $scenario='default'): ProcessResult {
			return $this->test->processSucceeded($this->test->coveredPhpFixture(
				$this->fixture,
				[$this->uiDirectory,$view,$scenario,$this->workingDirectory],
				working_directory:$this->workingDirectory,
				framework_root:$this->frameworkRoot,
			));
		}

		public function asset(string $mode): ProcessResult {
			return $this->test->processSucceeded($this->test->coveredPhpFixture(
				$this->assetFixture,
				[$this->uiDirectory.'/assets.php',$mode],
				working_directory:$this->workingDirectory,
				framework_root:$this->frameworkRoot,
			));
		}

		public function loginWithoutFacade(): ProcessResult {
			return $this->test->processSucceeded($this->test->coveredPhpFixture(
				$this->loginWithoutFacadeFixture,
				[$this->uiDirectory.'/login.php'],
				working_directory:$this->workingDirectory,
				framework_root:$this->frameworkRoot,
			));
		}
	}
}

dp_datadoc_unit_load_facade();

suite('DataDoc exact boundary behavior')
	->contract('datadoc.exact-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:datadoc')
	->through('tokenizer I/O','asset resolution','menu scopes','filesystem failures','bounded synchronization')
	->isolation('case')
	->tag('datadoc','exact-coverage')
	->group('framework-coverage');

test('tokenizer and highlighter expose deterministic failure and URL fallback boundaries',static function(Context $t): void {
	$workspace=$t->workspace('datadoc-exact-source');
	$source=$workspace->file('Source.php','<?php final class Source {}');
	$t->isFalse(\dataphyre\datadoc\tokenizer::tokenize($source,static fn()=>false));

	$highlighter=$t->nonPublic(\dataphyre\datadoc\highlighter::class);
	$withoutAssets=['load_support'=>false,'content_loader'=>null,'url_loader'=>null];
	$t->same('',$highlighter->invoke('highlighter_asset_tag','missing.css','style',$withoutAssets));
	$t->same('',$highlighter->invoke('highlighter_asset_tag','missing.css','style',[
		'load_support'=>false,'content_loader'=>static fn()=>null,'url_loader'=>static fn()=>'',
	]));
	$t->same('<link rel="stylesheet" href="/assets/highlighter.css?x=1&amp;y=2">',$highlighter->invoke(
		'highlighter_asset_tag','highlighter.css','style',[
			'load_support'=>false,'content_loader'=>static fn()=>null,
			'url_loader'=>static fn()=>'/assets/highlighter.css?x=1&y=2',
		],
	));
	$t->same('<script data-datadoc-highlighter="1" src="/assets/highlighter.js" defer></script>',$highlighter->invoke(
		'highlighter_asset_tag','highlighter.js','script',[
			'load_support'=>false,'content_loader'=>static fn()=>null,
			'url_loader'=>static fn()=>'/assets/highlighter.js',
		],
	));
	$t->same('<style data-datadoc-highlighter="1">.exact{display:block}</style>',$highlighter->invoke(
		'highlighter_asset_tag','inline.css','style',[
			'load_support'=>false,
			'content_loader'=>static fn()=>['content'=>'.exact{display:block}'],
			'url_loader'=>null,
		],
	));
	$t->contains('/dataphyre/datadoc/assets/datadoc-highlighter.css',$highlighter->invoke(
		'highlighter_asset_tag','datadoc-highlighter.css','style',['content_loader'=>static fn()=>null],
	));
	$t->contains('php_token_operator',$highlighter->invoke('highlight_scalar_token','+'));
	$t->contains('php_token_other',$highlighter->invoke('highlight_scalar_token','word'));
});

test('variable navigation distinguishes globals namespaces properties and function-local implementation details',static function(Context $t): void {
	DatadocExactSqlProbe::reset();
	$facade=$t->nonPublic(\dataphyre\datadoc::class);

	$local=$facade->capture('append_dynadoc_variable_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'Acme','class'=>'Order','function'=>'approve','bucket'=>'',
	]);
	$t->same([],$local->argument('nodes'));
	$t->same([],DatadocExactSqlProbe::$calls);

	DatadocExactSqlProbe::select([['namespace'=>'Acme\\Domain']]);
	$namespaceRoot=$facade->capture('append_dynadoc_variable_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'','class'=>'','function'=>'','bucket'=>'scope:namespaces',
	])->argument('nodes');
	$t->hasKey('ns:Acme',$namespaceRoot);

	DatadocExactSqlProbe::select([['namespace'=>'Acme\\Domain']]);
	DatadocExactSqlProbe::count(1);
	$namespace=$facade->capture('append_dynadoc_variable_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'Acme','class'=>'','function'=>'','bucket'=>'scope:namespaces',
	])->argument('nodes');
	$t->hasKey('ns:Domain',$namespace);
	$t->hasKey('scope:classes',$namespace);

	DatadocExactSqlProbe::select([['id'=>10,'type'=>'variable','content'=>'global_state']]);
	$globals=$facade->capture('append_dynadoc_variable_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'','class'=>'','function'=>'','bucket'=>'scope:globals',
	])->argument('nodes');
	$t->hasKey('record:10',$globals);

	DatadocExactSqlProbe::select([['namespace'=>'Acme\\Domain']],[['class'=>'Order']],[['class'=>'Order']]);
	$classes=$facade->capture('append_dynadoc_variable_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'Acme','class'=>'','function'=>'','bucket'=>'scope:classes',
	])->argument('nodes');
	$t->hasKey('class:Order',$classes);

	DatadocExactSqlProbe::select([['id'=>11,'type'=>'variable','namespace'=>'Acme','class'=>'Order','content'=>'status']]);
	$property=$facade->capture('append_dynadoc_variable_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'Acme','class'=>'Order','function'=>'','bucket'=>'',
	])->argument('nodes');
	$t->hasKey('record:11',$property);

	DatadocExactSqlProbe::select([['id'=>12,'type'=>'variable','namespace'=>'','class'=>'Order','content'=>'status']]);
	$globalProperty=$facade->capture('append_dynadoc_variable_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'','class'=>'Order','function'=>'','bucket'=>'',
	])->argument('nodes');
	$t->hasKey('record:12',$globalProperty);
});

test('authentication manual files discovery synchronization and tokenizer failures fail closed by named boundary',static function(Context $t): void {
	DatadocExactSqlProbe::reset();
	$facade=$t->nonPublic(\dataphyre\datadoc::class);
	$t->globalMap('_SESSION')->replace(['dp_datadoc_attempts'=>2,'dp_datadoc_logged_in'=>true]);
	$t->isFalse(\dataphyre\datadoc::logout(static fn(): bool=>false));
	$t->isFalse(\dataphyre\datadoc::login('ignored',static fn(): bool=>false));

	$workspace=$t->workspace('datadoc-exact-boundaries');
	$manual=$workspace->file('manual.md.json','{"title":"Manual"}');
	$t->isNull(\dataphyre\datadoc::get_manudoc(
		'docs','manual',static fn()=>false,static fn()=>$manual,
	));
	$t->isNull($facade->invoke('manual_project_root','docs',static fn()=>false,static fn()=>true));

	$stats=['registered'=>0,'skipped'=>0,'failed'=>0,'scanned'=>0,'last_cursor'=>'','done'=>true];
	$walk=$facade->capture(
		'discover_files_to_project_walk',
		dirpath:$workspace->root(),project:'docs',limit:10,after:'',stats:$stats,
		directory_reader:static fn()=>false,
	);
	$t->isTrue($walk->result());
	$t->same(1,$walk->argument('stats')['failed']);

	$facade->writeProperty('index_storage_ready',true);
	DatadocExactSqlProbe::select([['filepath'=>'never-processed.php','is_stale'=>true]]);
	$times=[0.0,1.0];
	$clock=static function()use(&$times): float { return (float)array_shift($times); };
	$batch=\dataphyre\datadoc::sync_project_batch('docs',10,0.5,$clock);
	$t->hasPathValues(['processed'=>0,'stopped_by'=>'time','error'=>null],$batch);

	$source=$workspace->file('Source.php','<?php final class Source {}');
	$t->isFalse(\dataphyre\datadoc::sync_file($source,'docs',static fn()=>false));
});

test('DataDoc table factories publish complete project record and file index schemas',static function(Context $t): void {
	$factories=require dirname(__DIR__).'/kernel/datadoc.tables.php';
	$t->same(['projects','data','files'],array_keys($factories));
	$projects=$factories['projects']('tenant.datadoc_projects');
	$data=$factories['data']('tenant.datadoc_data');
	$files=$factories['files']('tenant.datadoc_files');
	$t->instanceOf(TableDefinition::class,$projects);
	$t->same(['id','name','title','path'],$projects->columns());
	$t->same(['id','time','checksum','type','content','file','project','function','namespace','class','line','phpdoc_description','phpdoc_tags'],$data->columns());
	$t->same(['id','filepath','checksum','project','last_synced','is_stale'],$files->columns());
	$t->same(['id'],$files->primaryColumns());
});

test('DataDoc bootstrap reports missing dependency helpers and core as distinct operator failures',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$main=dirname(__DIR__).'/kernel/datadoc.main.php';
	$missingHelpers=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/datadoc_missing_dependency_helpers_probe.php',
		[$main],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'threw'=>true,
		'message'=>'DataDoc requires Flightdeck, but Dataphyre module dependency helpers are unavailable.',
	],$missingHelpers);

	$workspace=$t->workspace('datadoc-missing-core');
	$missingCore=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/datadoc_missing_core_probe.php',
		[$main,$workspace->root()],
		working_directory:$workspace->root(),
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'threw'=>true,
		'message'=>'DataDoc configuration requires Dataphyre Core.',
	],$missingCore);
});

test('DataDoc compatibility wrapper bootstraps core config and delegates every legacy helper',static function(Context $t): void {
	$root=dirname(__DIR__,4);
	$wrapper=dirname(__DIR__).'/kernel/wrapper.php';
	$fixture=__DIR__.'/fixtures/datadoc_wrapper_probe.php';
	$missingRoot=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,
		['missing-root',$wrapper,'-'],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'threw'=>true,
		'message'=>'ROOTPATH must be defined before bootstrapping Dataphyre.',
	],$missingRoot);

	$workspace=$t->workspace('datadoc-wrapper-runtime');
	$runtime=$workspace->directory('runtime');
	$workspace->file('runtime/modules/core/kernel/core.main.php',<<<'PHP'
<?php
define('DP_CORE_LOADED',true);
define('DP_CORE_CFG',['timezone'=>'UTC']);
PHP);
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		$fixture,
		['normal',$wrapper,$runtime],
		working_directory:$workspace->root(),
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'core_loaded'=>true,'configured'=>true,
		'sanitize'=>['value','plain'],'array_count'=>2,'non_array_count'=>0,
		'cache'=>'cleared','bot'=>true,'limit'=>12,
		'anonymized'=>['person@example.test',2,'*'],'formatted'=>[10,'CAD'],
		'rounded'=>[10.5],'user_currency'=>[10,'USD','CAD',1.4],
		'website_currency'=>[10,'CAD','USD',0.7],
		'adapted'=>[['dark'=>'Dark'],'dark'],
	],$payload);
});

test('standalone DataDoc assets honor missing conditional HEAD route and body request semantics',static function(Context $t): void {
	$ui=new DatadocUiEntrypointScenario($t);
	$t->same('Not found',$ui->asset('missing')->stdout());
	$t->same('',$ui->asset('head')->stdout());
	$t->same('',$ui->asset('etag')->stdout());
	$t->same('',$ui->asset('modified')->stdout());
	$t->contains('.phyro-bold',$ui->asset('binding')->stdout());
	$t->contains('.phyro-bold',$ui->asset('uri')->stdout());
});

test('standalone DataDoc shell login sidebar and lazy menu requests describe every access state',static function(Context $t): void {
	$ui=new DatadocUiEntrypointScenario($t);
	$t->contains('main-row',$ui->render('index')->stdout());
	$t->same('',$ui->render('index','unauthenticated')->stdout());
	$t->same('',$ui->render('index','header-logout')->stdout());
	$t->containsAll(['Documentation','api','datadoc-menu-toggle'],$ui->render('left_sidebar')->stdout());
	$t->same('',$ui->render('left_sidebar','unauthenticated')->stdout());
	$t->contains('mainAccordion',$ui->render('left_sidebar','sidebar-unavailable')->stdout());

	$t->contains('Logout',$ui->render('header')->stdout());
	$t->contains('Company SSO',$ui->render('header','header-label')->stdout());
	$t->notContains('Logout',$ui->render('header','header-logged-out')->stdout());
	$t->same('',$ui->render('header','header-logout')->stdout());
	$t->same('',$ui->render('login')->stdout());
	$t->same('',$ui->render('login','login-flightdeck')->stdout());
	$t->same('',$ui->loginWithoutFacade()->stdout());
	$t->contains('MIT License',$ui->render('footer')->stdout());

	$t->same('menu:docs:dynamic:1:1',$ui->render('dynadoc_menu_processor')->stdout());
	$t->same('menu:docs:manual:1:1',$ui->render('dynadoc_menu_processor','menu-invalid-path')->stdout());
	$t->same('menu:docs:dynamic:1:1',$ui->render('dynadoc_menu_processor','menu-query-project')->stdout());
	$t->same('Invalid project.',$ui->render('dynadoc_menu_processor','missing-project')->stdout());
	$t->same('',$ui->render('dynadoc_menu_processor','unauthenticated')->stdout());
});

test('standalone DataDoc document views render rich empty malformed missing and refreshed states',static function(Context $t): void {
	$ui=new DatadocUiEntrypointScenario($t);
	$t->containsAll(['function','static','Approves an order','Dataphyre','Docs, Runtime','Be careful','Source'],$ui->render('dynamic_document')->stdout());
	$t->contains('function',$ui->render('dynamic_document','dynamic-refresh')->stdout());
	$t->contains('No matching dynamic documentation record',$ui->render('dynamic_document','dynamic-refresh-empty')->stdout());
	$t->contains('namespace',$ui->render('dynamic_document','dynamic-namespace')->stdout());
	$t->contains('Class',$ui->render('dynamic_document','dynamic-class-global')->stdout());
	$t->contains('variable',$ui->render('dynamic_document','dynamic-variable')->stdout());
	$t->contains('Source',$ui->render('dynamic_document','dynamic-default')->stdout());
	$t->contains('Class',$ui->render('dynamic_document','dynamic-invalid-tags')->stdout());
	$t->contains('private',$ui->render('dynamic_document','dynamic-private')->stdout());
	$t->contains('Package',$ui->render('dynamic_document','dynamic-package')->stdout());
	$t->contains('No matching dynamic documentation record',$ui->render('dynamic_document','dynamic-empty')->stdout());
	$t->contains('function',$ui->render('dynamic_document','dynamic-filters')->stdout());
	$t->contains('Project not found.',$ui->render('dynamic_document','missing-project')->stdout());
	$t->same('',$ui->render('dynamic_document','unauthenticated')->stdout());
	$t->same('',$ui->render('dynamic_document','header-logout')->stdout());

	$t->containsAll(['Getting started','Introduction','On this page'],$ui->render('manual_document')->stdout());
	$t->contains('Manual document not found',$ui->render('manual_document','manual-missing')->stdout());
	$t->contains('Nested HTML',$ui->render('manual_document','manual-array-html')->stdout());
	$t->contains('&quot;payload&quot;',$ui->render('manual_document','manual-array-json')->stdout());
	$t->contains('Manual document',$ui->render('manual_document','manual-root')->stdout());
	$t->contains('Project not found.',$ui->render('manual_document','missing-project')->stdout());
	$t->same('',$ui->render('manual_document','unauthenticated')->stdout());
	$t->same('',$ui->render('manual_document','header-logout')->stdout());

	$t->containsAll(['Project summary','Indexed files:</b> 3'],$ui->render('project_dashboard')->stdout());
	$t->contains('Indexed files:</b> 0',$ui->render('project_dashboard','dashboard-nonnumeric')->stdout());
	$t->contains('<b>Name:</b> docs',$ui->render('project_dashboard','untitled-project')->stdout());
	$t->contains('Project not found.',$ui->render('project_dashboard','missing-project')->stdout());
	$t->same('',$ui->render('project_dashboard','unauthenticated')->stdout());
	$t->same('',$ui->render('project_dashboard','header-logout')->stdout());

	$t->containsAll(['Project settings','/srv/docs','manudocs'],$ui->render('project_settings')->stdout());
	$t->contains('<b>Project:</b> docs',$ui->render('project_settings','untitled-project')->stdout());
	$t->contains('Project not found.',$ui->render('project_settings','missing-project')->stdout());
	$t->same('',$ui->render('project_settings','unauthenticated')->stdout());
	$t->same('',$ui->render('project_settings','header-logout')->stdout());
});
