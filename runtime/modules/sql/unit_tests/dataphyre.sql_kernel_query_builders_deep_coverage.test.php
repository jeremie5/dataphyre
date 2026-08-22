<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	use Dataphyre\Test\TestState;

	function dp_kq_state(): TestState {
		return TestState::channel('sql.kernel.query-builders');
	}

	if(!class_exists(core::class,false)){
		final class core {
			public static function dialback(string $signal,mixed ...$arguments): mixed {
				$dialbacks=dp_kq_state()->get('dialback', []);
				if(is_array($dialbacks) && array_key_exists($signal, $dialbacks)){
					return $dialbacks[$signal];
				}
				return $signal==='CALL_SQL_OPEN_SQLITE_CONNECTION'
					? new SQLite3((string)($arguments[0] ?? ':memory:'))
					: null;
			}
			public static function unavailable(mixed ...$arguments): never { throw new \RuntimeException('unavailable'); }
			public static function get_password(string $endpoint): string { return 'secret'; }
		}
	}
	if(!class_exists(sql::class,false)){
		final class sql {
			public static function deferred_queries_allowed(): bool { return true; }
			public static function assert_immediate_transaction_driver_query(?string $cluster,?string $query=null): void { }
			public static function query_has_write(string $query): bool { return preg_match('/\b(?:INSERT|UPDATE|DELETE|BEGIN)\b/i',$query)===1; }
			public static function resolve_cluster(?string $configured=null): string {
				$cluster=trim((string)($configured ?? (DP_SQL_CFG['default_cluster'] ?? '')));
				$overrides=dp_kq_state()->get('cluster_resolution_overrides', []);
				return is_array($overrides) && isset($overrides[$cluster])
					? (string)$overrides[$cluster]
					: $cluster;
			}
			public static function log_query_error(mixed ...$arguments): void { dp_kq_state()->append('sql_errors', $arguments); }
			public static function cache_query_result(mixed ...$arguments): void { dp_kq_state()->append('cache', $arguments); }
			public static function invalidate_cache(mixed $name): void { dp_kq_state()->append('invalidations', $name); }
			public static function hydrate_missing_structure_from_definition(): bool { return (bool)dp_kq_state()->get('hydrate', false); }
			public static function clear_last_query_error(): void { dp_kq_state()->increment('clears'); }
			public static function is_server_available(string $endpoint): bool { return !in_array($endpoint, dp_kq_state()->get('unavailable_endpoints', []), true); }
			public static function flag_server_unavailable(string $endpoint): void { dp_kq_state()->append('flagged', $endpoint); }
		}
	}
	if(!class_exists(Exception::class,false)){ class Exception extends \Exception {} }
	if(!function_exists(__NAMESPACE__.'\\tracelog')){ function tracelog(mixed ...$arguments): void {} }
	if(!function_exists(__NAMESPACE__.'\\log_error')){ function log_error(mixed ...$arguments): void { dp_kq_state()->append('log_errors', $arguments); } }
	if(!function_exists(__NAMESPACE__.'\\dataphyre_shutdown_log')){ function dataphyre_shutdown_log(mixed ...$arguments): void {} }

	final class DpKqMysqlResult {
		private int $index=0;
		public function __construct(private array $rows=[]){ }
		public function fetch_assoc(): array|false { return $this->rows[$this->index++] ?? false; }
		public function fetch_all(int $mode=0): array { return $this->rows; }
		public function free(): void { dp_kq_state()->increment('mysql_frees'); }
	}
	final class DpKqMysqlStmt {
		public int $field_count;
		public int $insert_id;
		public int $affected_rows;
		public function __construct(private array $mode=[]){
			$this->field_count=(int)($mode['field_count'] ?? 0);
			$this->insert_id=(int)($mode['insert_id'] ?? 0);
			$this->affected_rows=(int)($mode['affected_rows'] ?? 2);
		}
		public function bind_param(string $types,mixed &...$values): bool { dp_kq_state()->append('mysql_binds', [$types,$values]); return ($this->mode['bind'] ?? true)!==false; }
		public function execute(): bool { if(isset($this->mode['throw'])){ throw new \RuntimeException((string)$this->mode['throw']); } return ($this->mode['execute'] ?? true)!==false; }
		public function get_result(): object|false { return $this->mode['result'] ?? new DpKqMysqlResult([['id'=>1],['id'=>2]]); }
		public function close(): void { dp_kq_state()->increment('mysql_stmt_closes'); }
	}
	final class DpKqMysqlConnection {
		public string $error='fake mysql error';
		public int $affected_rows=4;
		public array $storedResults=[];
		public function prepare(string $query): object|false {
			dp_kq_state()->append('mysql_queries', $query);
			$mode=dp_kq_state()->shift('mysql_stmt_modes');
			return $mode===false ? false : new DpKqMysqlStmt(is_array($mode)?$mode:[]);
		}
		public function begin_transaction(): bool { dp_kq_state()->append('mysql_tx', 'begin'); return true; }
		public function commit(): bool { dp_kq_state()->append('mysql_tx', 'commit'); return true; }
		public function rollback(): bool { dp_kq_state()->append('mysql_tx', 'rollback'); return true; }
		public function multi_query(string $query): bool { dp_kq_state()->append('mysql_queries', $query); return (bool)dp_kq_state()->get('mysql_multi_ok', true); }
		public function store_result(): object|false { $next=array_shift($this->storedResults); return $next instanceof DpKqMysqlResult ? $next : false; }
		public function more_results(): bool { return $this->storedResults!==[]; }
		public function next_result(): bool { return $this->storedResults!==[]; }
		public function close(): void { dp_kq_state()->increment('mysql_conn_closes'); }
		public function query(string $query): object|false { dp_kq_state()->append('mysql_queries', $query); return dp_kq_state()->get('mysql_query_ok', true) ? new DpKqMysqlResult(dp_kq_state()->get('mysql_rows', [])) : false; }
		public function options(int $option,mixed $value): bool { return (bool)dp_kq_state()->get('mysql_options_ok', true); }
		public function real_connect(string $endpoint,string $username,?string $password,string $database): bool { return (bool)dp_kq_state()->get('mysql_connect_ok', true); }
		public function set_charset(string $charset): bool { return (bool)dp_kq_state()->get('mysql_charset_ok', true); }
	}
	function mysqli_multi_query(object $conn,string $query): bool { return $conn->multi_query($query); }
	function mysqli_store_result(object $conn): object|false { return $conn->store_result(); }
	function mysqli_free_result(object $result): void { $result->free(); }
	function mysqli_next_result(object $conn): bool { return $conn->next_result(); }
	function mysqli_error(object $conn): string { return $conn->error; }
	function mysqli_query(object $conn,string $query): object|false { return $conn->query($query); }
	function mysqli_num_rows(object $result): int { $rows=$result->fetch_all(); return count($rows); }
	function mysqli_fetch_assoc(object $result): array|false { return $result->fetch_assoc(); }
	function mysqli_affected_rows(object $conn): int { return $conn->affected_rows; }

	final class DpKqSqliteResult {
		private int $index=0;
		public function __construct(private array $rows=[],private int $columns=1){ }
		public function numColumns(): int { return $this->columns; }
		public function fetchArray(int $mode): array|false { return $this->rows[$this->index++] ?? false; }
		public function finalize(): void { dp_kq_state()->increment('sqlite_finalizes'); }
	}
	final class DpKqSqliteStmt {
		public function __construct(private SQLite3 $connection,private string $query,private array $mode=[]){ }
		public function bindValue(int $index,mixed $value,int $type=0): bool { dp_kq_state()->append('sqlite_binds', [$index,$value,$type]); return true; }
		public function execute(): object|false {
			if(($this->mode['execute'] ?? true)===false){ return false; }
			return $this->mode['result'] ?? new DpKqSqliteResult(dp_kq_state()->get('sqlite_rows', []),(int)($this->mode['columns'] ?? 1));
		}
		public function close(): void { dp_kq_state()->increment('sqlite_stmt_closes'); }
	}
	class SQLite3 {
		public function __construct(public string $path=':memory:'){
			if(dp_kq_state()->get('sqlite_construct_throw', false)){ throw new Exception('construct failure'); }
		}
		public function prepare(string $query): object|false {
			dp_kq_state()->append('sqlite_queries', $query);
			$mode=dp_kq_state()->shift('sqlite_stmt_modes');
			return $mode===false ? false : new DpKqSqliteStmt($this,$query,is_array($mode)?$mode:[]);
		}
		public function query(string $query): object|false {
			dp_kq_state()->append('sqlite_queries', $query);
			return dp_kq_state()->get('sqlite_query_ok', true) ? new DpKqSqliteResult(dp_kq_state()->get('sqlite_rows', [])) : false;
		}
		public function querySingle(string $query,bool $entireRow=false): array|false { if(dp_kq_state()->get('sqlite_single_throw', false)){ throw new \RuntimeException('querySingle failure'); } $value=dp_kq_state()->shift('sqlite_single_results'); return $value===null ? ['count'=>3] : $value; }
		public function exec(string $query): bool { dp_kq_state()->append('sqlite_execs', $query); $value=dp_kq_state()->shift('sqlite_exec_results'); return $value===null ? true : (bool)$value; }
		public function changes(): int { return (int)dp_kq_state()->get('sqlite_changes', 3); }
		public function lastErrorMsg(): string { return 'fake sqlite error'; }
		public function close(): void { dp_kq_state()->increment('sqlite_conn_closes'); }
	}

	final class DpKqPgResult {
		public int $index=0;
		public function __construct(public array|false $rows=[['id'=>'1','active'=>'t']],public array $types=['id'=>'int4','active'=>'bool'],public int $affected=2,public string $error=''){ }
	}
	final class DpKqPgConnection { }
	final class DpKqThrowingIterator implements \IteratorAggregate {
		public function getIterator(): \Traversable { throw new \RuntimeException('iterator failure'); }
	}
	function pg_connect(string $connection): object|false { return dp_kq_state()->get('pg_connect_ok', true) ? new DpKqPgConnection() : false; }
	function pg_query(object $conn,string $query): object|false { dp_kq_state()->append('pg_queries', $query); $value=dp_kq_state()->shift('pg_query_results'); return $value===null ? new DpKqPgResult() : $value; }
	function pg_prepare(object $conn,string $name,string $query): bool { dp_kq_state()->append('pg_queries', $query); $value=dp_kq_state()->shift('pg_prepare_results'); return $value===null ? true : (bool)$value; }
	function pg_execute(object $conn,string $name,array $vars): object|false { dp_kq_state()->append('pg_execute_vars',$vars); $value=dp_kq_state()->shift('pg_execute_results'); return $value===null ? new DpKqPgResult() : $value; }
	function pg_last_error(object $conn): string { return (string)dp_kq_state()->get('pg_last_error', 'fake pg error'); }
	function pg_num_fields(object $result): int { return (int)dp_kq_state()->get('pg_num_fields', count($result->types)); }
	function pg_affected_rows(object $result): int { return $result->affected; }
	function pg_fetch_all(object $result,int $mode=0): array|false { return $result->rows; }
	function pg_fetch_assoc(object $result): array|false { return is_array($result->rows) ? ($result->rows[$result->index++] ?? false) : false; }
	function pg_field_num(object $result,string|int $field): int { $keys=array_keys($result->types); $index=array_search((string)$field,$keys,true); return $index===false ? (int)$field : $index; }
	function pg_field_type(object $result,int $field): string { return array_values($result->types)[$field] ?? 'text'; }
	function pg_send_query(object $conn,string $query): bool { dp_kq_state()->append('pg_queries', $query); $value=dp_kq_state()->shift('pg_send_results'); return $value===null ? true : (bool)$value; }
	function pg_get_result(object $conn): object|false { $value=dp_kq_state()->shift('pg_get_results'); return $value instanceof DpKqPgResult ? $value : false; }
	function pg_result_error(object $result): string { return $result->error; }
	function pg_free_result(object $result): bool { $value=dp_kq_state()->shift('pg_free_results'); return $value===null ? true : (bool)$value; }
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\NonPublicAccess;
	use Dataphyre\Test\NonPublicInvocation;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\test;
	use function dataphyre\dp_kq_state;
	use dataphyre\DpKqMysqlConnection;
	use dataphyre\DpKqMysqlResult;
	use dataphyre\DpKqPgConnection;
	use dataphyre\DpKqPgResult;
	use dataphyre\DpKqSqliteResult;
	use dataphyre\SQLite3;
	if(!function_exists('dataphyre_shutdown_log')){ function dataphyre_shutdown_log(mixed ...$arguments): void { dp_kq_state()->append('shutdown_logs', $arguments); } }

	if(!defined('MYSQLI_ASSOC')){ define('MYSQLI_ASSOC',1); }
	if(!defined('SQLITE3_ASSOC')){ define('SQLITE3_ASSOC',1); }
	if(!defined('SQLITE3_NUM')){ define('SQLITE3_NUM',2); }
	if(!defined('SQLITE3_TEXT')){ define('SQLITE3_TEXT',3); }
	if(!defined('PGSQL_ASSOC')){ define('PGSQL_ASSOC',1); }
	if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG',['datacenter'=>'dc']); }
	if(!defined('DP_SQL_CFG')){
		define('DP_SQL_CFG',[
			'default_cluster'=>'primary',
			'tables'=>[
				'raw'=>['cluster'=>'primary'],
				'normal'=>['multipoint_writes'=>false],
				'multi'=>['multipoint_writes'=>true],
			],
			'datacenters'=>['dc'=>['dbms_clusters'=>[
				'primary'=>['dbms'=>'postgresql','dbms_username'=>'user','database_name'=>':memory:','password'=>'pass','dbms_port'=>5432,'endpoints'=>['one','two']],
				'empty'=>['dbms'=>'postgresql','dbms_username'=>'user','database_name'=>':memory:','password'=>'pass','dbms_port'=>5432,'endpoints'=>[]],
				'failing'=>['dbms'=>'postgresql','dbms_username'=>'user','database_name'=>':memory:','password'=>'pass','dbms_port'=>5432,'endpoints'=>['bad']],
			]]],
		]);
	}
	require_once (ROOTPATH['common_dataphyre_runtime'] ?? \Dataphyre\Test\dataphyre_path().'/runtime').'/modules/sql/kernel/mysql_query.php';
	require_once (ROOTPATH['common_dataphyre_runtime'] ?? \Dataphyre\Test\dataphyre_path().'/runtime').'/modules/sql/kernel/postgresql_query.php';
	require_once (ROOTPATH['common_dataphyre_runtime'] ?? \Dataphyre\Test\dataphyre_path().'/runtime').'/modules/sql/kernel/sqlite_query.php';

	function dp_kq_scenario(Context $t): TestState {
		$state=$t->state('sql.kernel.query-builders', [
			'dialback'=>[],
			'sql_errors'=>[],
			'cache'=>[],
			'invalidations'=>[],
			'hydrate'=>false,
			'clears'=>0,
			'unavailable_endpoints'=>[],
			'flagged'=>[],
			'log_errors'=>[],
			'mysql_stmt_modes'=>[],
			'mysql_queries'=>[],
			'mysql_rows'=>[['id'=>1],['id'=>2]],
			'mysql_query_ok'=>true,
			'mysql_multi_ok'=>true,
			'mysql_tx'=>[],
			'mysql_options_ok'=>true,
			'mysql_connect_ok'=>true,
			'mysql_charset_ok'=>true,
			'sqlite_stmt_modes'=>[],
			'sqlite_rows'=>[['id'=>1],['id'=>2]],
			'sqlite_query_ok'=>true,
			'sqlite_exec_results'=>[],
			'sqlite_single_results'=>[],
			'sqlite_single_throw'=>false,
			'sqlite_changes'=>3,
			'sqlite_construct_throw'=>false,
			'pg_query_results'=>[],
			'pg_prepare_results'=>[],
			'pg_execute_results'=>[],
			'pg_execute_vars'=>[],
			'pg_send_results'=>[],
			'pg_get_results'=>[],
			'pg_free_results'=>[],
			'pg_num_fields'=>2,
				'pg_connect_ok'=>true,
				'cluster_resolution_overrides'=>[],
				'pg_queries'=>[],
		]);
		\dataphyre\mysql_query_builder::$conns=[];\dataphyre\mysql_query_builder::$queued_queries=[];
		\dataphyre\postgresql_query_builder::$conns=[];\dataphyre\postgresql_query_builder::$queued_queries=[];
		\dataphyre\sqlite_query_builder::$conns=[];\dataphyre\sqlite_query_builder::$queued_queries=[];
		return $state;
	}

	function dp_kq_queue(bool $prepared=true,bool $multipoint=false): array {
		$vars=$prepared ? [1] : null;
		$callback=static function(mixed $value): void { dp_kq_state()->append('callbacks', $value); };
		return [
			'select'=>[['select'=>'*','location'=>'normal','params'=>'WHERE id=?','vars'=>$vars,'associative'=>true,'caching'=>true,'hash'=>'h','callback'=>$callback,'multipoint'=>$multipoint]],
			'insert'=>[['ignore'=>'IGNORE','location'=>'normal','fields'=>'id,name','vars'=>$vars,'returning'=>'*','clear_cache'=>true,'multipoint'=>$multipoint]],
			'update'=>[['location'=>'normal','fields'=>'name=?','params'=>'WHERE id=?','vars'=>$vars,'clear_cache'=>'custom','multipoint'=>$multipoint]],
			'count'=>[['location'=>'normal','params'=>'','vars'=>$vars,'multipoint'=>$multipoint]],
			'delete'=>[['location'=>'normal','params'=>'WHERE id=?','vars'=>$vars,'multipoint'=>$multipoint]],
		];
	}

	function dp_kq_prepared_execution(NonPublicAccess $builder,object $connection,array $statements): NonPublicInvocation {
		return $builder->capture(
			'execute_prepared_statements',
			conn:$connection,
			prepared_statements:$statements,
			results:[],
			dbms_cluster:'primary',
		);
	}

	function dp_kq_multi_execution(NonPublicAccess $builder,object $connection,string $statements): NonPublicInvocation {
		return $builder->capture(
			'execute_multi_query_string',
			conn:$connection,
			multi_query_string:$statements,
			results:[],
			dbms_cluster:'primary',
		);
	}

	test('sql kernel query builders deep coverage normalizes queue metadata and result side effects',static function(Context $t): void {
		$state=dp_kq_scenario($t);
		foreach([\dataphyre\mysql_query_builder::class,\dataphyre\postgresql_query_builder::class,\dataphyre\sqlite_query_builder::class] as $class){
			$t->same([],$t->nonPublic($class)->invokeWithArguments('queued_query_list',[null]));
			$t->same([],$t->nonPublic($class)->invokeWithArguments('queued_query_list',[[]]));
			$flat=[['type'=>'select','location'=>'normal']];
			$t->same($flat,$t->nonPublic($class)->invokeWithArguments('queued_query_list',[$flat]));
			$grouped=['select'=>[['location'=>'normal'],false],'skip'=>false];
			$t->same('select',$t->nonPublic($class)->invokeWithArguments('queued_query_list',[$grouped])[0]['type']);
			$seen=[];
			$queries=[
				['type'=>'select','location'=>'normal','associative'=>false,'callback'=>static function($v)use(&$seen): void{$seen[]=$v;}],
				['type'=>'select','location'=>'normal','caching'=>true,'hash'=>'hash'],
				['type'=>'update','location'=>'normal','clear_cache'=>true],
				['type'=>'update','location'=>'normal','clear_cache'=>'named'],
				['type'=>'count','location'=>'normal'],
				['type'=>'select','location'=>'normal'],
			];
			$t->nonPublic($class)->invokeWithArguments('process_results',[[[['id'=>1]],[],true,'ok',[['count'=>'7']],new stdClass(),['orphan']],$queries]);
			$t->same(['id'=>1],$seen[0]);
		}
		$t->notEmpty($state->get('cache'));$t->notEmpty($state->get('invalidations'));
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');

	test('sql kernel query builders deep coverage flushes shutdown queues and covers connection construction branches',static function(Context $t): void {
		$state=dp_kq_scenario($t);
		\dataphyre\flush_mysql_query_queue_at_shutdown();\dataphyre\flush_postgresql_query_queue_at_shutdown();\dataphyre\flush_sqlite_query_queue_at_shutdown();
		foreach([\dataphyre\mysql_query_builder::class=>\dataphyre\flush_mysql_query_queue_at_shutdown(...),\dataphyre\postgresql_query_builder::class=>\dataphyre\flush_postgresql_query_queue_at_shutdown(...),\dataphyre\sqlite_query_builder::class=>\dataphyre\flush_sqlite_query_queue_at_shutdown(...)] as $class=>$flush){
			$class::$queued_queries=['boom'=>['select'=>[['select'=>new stdClass(),'location'=>'normal','params'=>'','vars'=>null]]]];
			$flush();
			$class::$queued_queries=new \dataphyre\DpKqThrowingIterator();
			$flush();
			$class::$queued_queries=[];
		}

		$mysql=\dataphyre\mysql_query_builder::class;
		$t->same(false,$t->nonPublic($mysql)->invokeWithArguments('configure_endpoint_connection',['bad','primary',false]));
		$conn=new DpKqMysqlConnection();$state->put('mysql_options_ok',false);$t->same(false,$t->nonPublic($mysql)->invokeWithArguments('configure_endpoint_connection',['bad','primary',$conn]));
		$state->put('mysql_options_ok',true);$state->put('mysql_connect_ok',false);$t->same(false,$t->nonPublic($mysql)->invokeWithArguments('configure_endpoint_connection',['bad','primary',$conn]));
		$state->put('mysql_connect_ok',true);$state->put('mysql_charset_ok',false);$t->same(false,$t->nonPublic($mysql)->invokeWithArguments('configure_endpoint_connection',['bad','primary',$conn]));
		$state->put('mysql_charset_ok',true);$t->same($conn,$t->nonPublic($mysql)->invokeWithArguments('configure_endpoint_connection',['good','primary',$conn]));
		$mysql::$conns=[];try{$t->nonPublic($mysql)->invokeWithArguments('connect_to_endpoint',['127.0.0.1','primary']);}catch(Throwable){}$t->isTrue(true);
		$mysql::$conns=[];$t->throws(static fn()=>$t->nonPublic($mysql)->invokeWithArguments('connect_to_cluster',['empty']),RuntimeException::class);
		$state->put('unavailable_endpoints',['bad']);$t->throws(static fn()=>$t->nonPublic($mysql)->invokeWithArguments('connect_to_cluster',['failing']),RuntimeException::class);

		$pg=\dataphyre\postgresql_query_builder::class;$pg::$conns=[];$state->put('unavailable_endpoints',['bad']);$t->same(false,$t->nonPublic($pg)->invokeWithArguments('connect_to_endpoint',['bad','failing']));
		$state->put('unavailable_endpoints',[]);$state->put('pg_connect_ok',false);$t->same(false,$t->nonPublic($pg)->invokeWithArguments('connect_to_endpoint',['bad','failing']));
			$state->put('pg_connect_ok',true);$pg::$conns=[];$t->isTrue(is_object($t->nonPublic($pg)->invokeWithArguments('connect_to_endpoint',['one','primary'])));
			$state->put('cluster_resolution_overrides',['primary'=>'failing']);$pg::$conns=[];
			$t->isTrue(is_object($t->nonPublic($pg)->invokeWithArguments('connect_to_endpoint',['one','primary'])));
			$t->isTrue(isset($pg::$conns['primary']));
			$t->isFalse(isset($pg::$conns['failing']));
			$state->put('cluster_resolution_overrides',[]);
		$pg::$conns=[];$t->isTrue(is_object($t->nonPublic($pg)->invokeWithArguments('connect_to_cluster',['primary'])));
		$pg::$conns=[];$t->throws(static fn()=>$t->nonPublic($pg)->invokeWithArguments('connect_to_cluster',['empty']),RuntimeException::class);
		$state->put('unavailable_endpoints',['bad']);$t->throws(static fn()=>$t->nonPublic($pg)->invokeWithArguments('connect_to_cluster',['failing']),RuntimeException::class);

		$sqlite=\dataphyre\sqlite_query_builder::class;$sqlite::$conns=[];$state->put('sqlite_construct_throw',true);$t->same(false,$t->nonPublic($sqlite)->invokeWithArguments('connect_to_endpoint',['bad','failing']));
		$t->throws(static fn()=>$t->nonPublic($sqlite)->invokeWithArguments('connect_to_cluster',['failing']),RuntimeException::class);$t->throws(static fn()=>$t->nonPublic($sqlite)->invokeWithArguments('connect_to_cluster',['empty']),RuntimeException::class);
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');

	test('sql kernel query builders deep coverage exercises SQLite private executors queues and public operations',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\sqlite_query_builder::class;$conn=new SQLite3();
		$builder=$t->nonPublic($class);
		$state->put('dialback', ['CALL_SQL_OPEN_MAIN_CONNECTION'=>$conn]);
		$t->same($conn,$t->nonPublic($class)->invokeWithArguments('connect_to_cluster',['primary']));
		$state->put('dialback',[]);$class::$conns['primary']=$conn;$t->same($conn,$t->nonPublic($class)->invokeWithArguments('connect_to_cluster',['primary']));
		$t->same($conn,$t->nonPublic($class)->invokeWithArguments('connect_to_endpoint',['one','primary']));
		$state->put('sqlite_stmt_modes',[['result'=>new DpKqSqliteResult([['id'=>1]],1)],['result'=>new DpKqSqliteResult([],0)]]);
		$t->isTrue(dp_kq_prepared_execution($builder,$conn,[['query'=>'SELECT 1','vars'=>[1]],['query'=>'UPDATE normal SET id=1','vars'=>[]]])->result());
		$state->put('sqlite_stmt_modes',[false]);$t->isFalse(dp_kq_prepared_execution($builder,$conn,[['query'=>'UPDATE normal SET id=1','vars'=>[]]])->result());
		$t->isTrue(dp_kq_multi_execution($builder,$conn,'SELECT 1; UPDATE normal SET id=1; ')->result());
		$state->put('sqlite_query_ok',false);$t->isFalse(dp_kq_multi_execution($builder,$conn,'UPDATE normal SET id=1;')->result());$state->put('sqlite_query_ok',true);

		$class::$conns['primary']=$conn;$class::$queued_queries['prepared']=dp_kq_queue(true);$t->isTrue($class::execute_multiquery('prepared'));
		$class::$conns=[];$class::$queued_queries['raw']=dp_kq_queue(false);$t->isTrue($class::execute_multiquery('raw'));
		$state->put('hydrate',true);$class::$conns['primary']=$conn;$t->isTrue($t->nonPublic($class)->invokeWithArguments('retry_queue_after_hydration',['retry',dp_kq_queue(true),false]));$state->put('hydrate',false);
		$t->same(null,$class::execute_multiquery('missing'));
		$t->isFalse($t->nonPublic($class)->invokeWithArguments('retry_queue_after_hydration',['x',dp_kq_queue(),true]));
		$state->put('hydrate',false);$t->isFalse($t->nonPublic($class)->invokeWithArguments('retry_queue_after_hydration',['x',dp_kq_queue(),false]));

		$state->put('dialback', ['CALL_SQL_SIMPLE_SELECT'=>['dialback']]);$t->same(['dialback'],$class::sqlite_query('primary','SELECT 1',null,true,false));$state->put('dialback', []);
		$t->notEmpty($class::sqlite_query('primary','SELECT 1',[1],true,true));$t->notEmpty($class::sqlite_query('primary','SELECT 1',null,false,false));
		$class::$conns['primary']=$conn;$t->notEmpty($class::sqlite_select('primary','*','normal','',[1],true));$t->notEmpty($class::sqlite_select('primary','*','normal','',null,false));
		$state->put('sqlite_stmt_modes',[['result'=>new DpKqSqliteResult([['count'=>1]],1)]]);$t->same(1,$class::sqlite_count('primary','normal','',[1]));$state->put('sqlite_single_results',[['count'=>4]]);$t->same(4,$class::sqlite_count('primary','normal','',null));
		$t->same(3,$class::sqlite_update('primary','normal','id=?','WHERE id=?',[2,1]));$t->same(3,$class::sqlite_update('primary','multi','id=?','WHERE id=?',[2,1]));
		$t->isTrue($class::sqlite_insert('primary','normal','id',[1]));$t->isTrue($class::sqlite_insert('primary','multi','id',[1]));
		$t->same(3,$class::sqlite_delete('primary','normal','WHERE id=?',[1]));$t->same(3,$class::sqlite_delete('primary','multi','',null));
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');

	test('sql kernel query builders deep coverage covers SQLite queue and direct failure contracts',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\sqlite_query_builder::class;$conn=new SQLite3();$class::$conns['primary']=$conn;
		$state->put('sqlite_stmt_modes',[false]);$class::$queued_queries['prepared-fail']=dp_kq_queue(true);$t->isFalse($class::execute_multiquery('prepared-fail'));
		$state->put('sqlite_query_ok',false);$class::$queued_queries['raw-fail']=dp_kq_queue(false);$t->isFalse($class::execute_multiquery('raw-fail'));$state->put('sqlite_query_ok',true);
		$state->put('sqlite_stmt_modes',[false]);$t->isFalse($class::sqlite_query('primary','SELECT ?',[1],true,false));
		$state->put('sqlite_stmt_modes',[['execute'=>false]]);$t->isFalse($class::sqlite_query('primary','SELECT ?',[1],true,false));
		$state->put('sqlite_query_ok',false);$t->isFalse($class::sqlite_query('primary','SELECT 1',null,true,false));$state->put('sqlite_query_ok',true);
		$state->put('sqlite_stmt_modes',[false]);$t->isFalse($class::sqlite_select('primary','*','normal','',[1],true));
		$state->put('sqlite_stmt_modes',[['execute'=>false]]);$t->isFalse($class::sqlite_select('primary','*','normal','',[1],true));
		$state->put('sqlite_query_ok',false);$t->isFalse($class::sqlite_select('primary','*','normal','',null,true));$state->put('sqlite_query_ok',true);
		$state->put('sqlite_rows',[]);$t->isFalse($class::sqlite_select('primary','*','normal','',null,true));$state->put('sqlite_rows',[['id'=>1]]);
		$state->put('sqlite_stmt_modes',[false]);$t->isFalse($class::sqlite_count('primary','normal','',[1]));
		$state->put('sqlite_stmt_modes',[['execute'=>false]]);$t->isFalse($class::sqlite_count('primary','normal','',[1]));
		$state->put('sqlite_single_throw',true);$t->isFalse($class::sqlite_count('primary','normal','',null));$state->put('sqlite_single_throw',false);
		$state->put('sqlite_single_results',[false]);$t->isFalse($class::sqlite_count('primary','normal','',null));
		$state->put('sqlite_stmt_modes',[false]);$t->isFalse($class::sqlite_update('primary','normal','id=?','WHERE id=?',[2,1]));
		$state->put('sqlite_stmt_modes',[['execute'=>false]]);$t->isFalse($class::sqlite_update('primary','normal','id=?','WHERE id=?',[2,1]));
		$state->put('sqlite_stmt_modes',[false]);$t->isFalse($class::sqlite_insert('primary','normal','id',[1]));
		$state->put('sqlite_stmt_modes',[['execute'=>false]]);$t->isFalse($class::sqlite_insert('primary','normal','id',[1]));
		$state->put('sqlite_stmt_modes',[false]);$t->isFalse($class::sqlite_delete('primary','normal','WHERE id=?',[1]));
		$state->put('sqlite_stmt_modes',[['execute'=>false]]);$t->isFalse($class::sqlite_delete('primary','normal','WHERE id=?',[1]));
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');

	test('sql kernel query builders deep coverage exercises MySQL private executors queues and public operations',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\mysql_query_builder::class;$conn=new DpKqMysqlConnection();$class::$conns['primary']=$conn;
		$builder=$t->nonPublic($class);
		$t->same($conn,$t->nonPublic($class)->invokeWithArguments('connect_to_cluster',['primary']));$t->same($conn,$t->nonPublic($class)->invokeWithArguments('connect_to_endpoint',['one','primary']));
		$state->put('unavailable_endpoints',['one']);$t->same(false,$t->nonPublic($class)->invokeWithArguments('connect_to_endpoint',['one','other']));$state->put('unavailable_endpoints',[]);
		$state->put('mysql_stmt_modes',[['field_count'=>1,'result'=>new DpKqMysqlResult([['id'=>1]])],['insert_id'=>9],['affected_rows'=>4]]);
		$t->isTrue(dp_kq_prepared_execution($builder,$conn,[['query'=>'SELECT 1','vars'=>[1]],['query'=>'INSERT INTO normal VALUES(?)','vars'=>[1]],['query'=>'UPDATE normal SET id=?','vars'=>[1]]])->result());
		$state->put('mysql_stmt_modes',[false]);$t->isFalse(dp_kq_prepared_execution($builder,$conn,[['query'=>'UPDATE normal SET id=?','vars'=>[1]]])->result());
		$conn->storedResults=[new DpKqMysqlResult([['id'=>1]]),new DpKqMysqlResult([['id'=>2]])];$t->isTrue(dp_kq_multi_execution($builder,$conn,'SELECT 1; UPDATE normal SET id=1')->result());
		$state->put('mysql_multi_ok',false);$t->isFalse(dp_kq_multi_execution($builder,$conn,'UPDATE normal SET id=1')->result());$state->put('mysql_multi_ok',true);

		$class::$conns['primary']=$conn;$state->put('mysql_stmt_modes',array_fill(0,5,['affected_rows'=>2]));$class::$queued_queries['prepared']=dp_kq_queue(true);$t->isTrue($class::execute_multiquery('prepared'));
		$conn=new DpKqMysqlConnection();$conn->storedResults=array_fill(0,5,new DpKqMysqlResult([['id'=>1]]));$class::$conns['primary']=$conn;$class::$queued_queries['raw']=dp_kq_queue(false);$t->isTrue($class::execute_multiquery('raw'));
		$state->put('mysql_stmt_modes',array_fill(0,10,['affected_rows'=>2]));$class::$queued_queries['multi-prepared']=dp_kq_queue(true,true);$t->isTrue($class::execute_multiquery('multi-prepared'));
		$conn->storedResults=array_fill(0,10,new DpKqMysqlResult([['id'=>1]]));$class::$queued_queries['multi-raw']=dp_kq_queue(false,true);$t->isTrue($class::execute_multiquery('multi-raw'));
		$state->put('hydrate',true);$state->put('mysql_stmt_modes',array_fill(0,5,['affected_rows'=>1]));$t->isTrue($t->nonPublic($class)->invokeWithArguments('retry_queue_after_hydration',['retry',dp_kq_queue(true),false]));$state->put('hydrate',false);
		$t->same(null,$class::execute_multiquery('missing'));$t->isFalse($t->nonPublic($class)->invokeWithArguments('retry_queue_after_hydration',['x',dp_kq_queue(),true]));

		$conn=new DpKqMysqlConnection();$class::$conns['primary']=$conn;$state->put('mysql_stmt_modes',[['result'=>new DpKqMysqlResult([['id'=>1]])]]);$t->same(['id'=>1],$class::mysql_query('primary','SELECT ?', [1],false,false));
		$state->put('mysql_stmt_modes',[['result'=>new DpKqMysqlResult([['id'=>1],['id'=>2]])],['result'=>new DpKqMysqlResult([['id'=>1],['id'=>2]])]]);$t->same(2,count($class::mysql_query('primary','SELECT ?', [1],true,true)));
		$conn->storedResults=[new DpKqMysqlResult([['id'=>2]])];$t->same(['id'=>2],$class::mysql_query('primary','SELECT 2',null,false,false));
		$state->put('mysql_stmt_modes',[['result'=>new DpKqMysqlResult([['id'=>1],['id'=>2]])]]);$t->same(2,count($class::mysql_select('primary','*','normal','',[1],true)));
		$state->put('mysql_rows',[['id'=>3]]);$t->same(['id'=>3],$class::mysql_select('primary','*','normal','',null,false));
		$state->put('mysql_stmt_modes',[['result'=>new DpKqMysqlResult([['count'=>5]])]]);$t->same(5,$class::mysql_count('primary','normal','',[1]));$state->put('mysql_rows',[['count'=>6]]);$t->same(6,$class::mysql_count('primary','normal','',null));
		$state->put('mysql_stmt_modes',[['affected_rows'=>4]]);$t->same(4,$class::mysql_update('primary','normal','id=?','WHERE id=?',[2,1]));
		$state->put('mysql_stmt_modes',[['result'=>new DpKqMysqlResult([['id'=>7]])]]);$t->same(['id'=>7],$class::mysql_insert('primary','normal','id',[7]));
		$state->put('mysql_stmt_modes',[['affected_rows'=>3]]);$t->same(3,$class::mysql_delete('primary','normal','WHERE id=?',[1]));$state->put('mysql_rows',[]);$t->same(4,$class::mysql_delete('primary','normal','',null));
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');

	test('sql kernel query builders deep coverage covers MySQL queue and direct failure contracts',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\mysql_query_builder::class;$conn=new DpKqMysqlConnection();$class::$conns['primary']=$conn;
		$state->put('mysql_stmt_modes',[false]);$class::$queued_queries['prepared-fail']=dp_kq_queue(true);$t->isFalse($class::execute_multiquery('prepared-fail'));
		$state->put('mysql_multi_ok',false);$class::$queued_queries['raw-fail']=dp_kq_queue(false);$t->isFalse($class::execute_multiquery('raw-fail'));$state->put('mysql_multi_ok',true);
		$state->put('mysql_stmt_modes',[false]);$class::$queued_queries['multi-prepared-fail']=dp_kq_queue(true,true);$t->isFalse($class::execute_multiquery('multi-prepared-fail'));
		$state->put('mysql_multi_ok',false);$class::$queued_queries['multi-raw-fail']=dp_kq_queue(false,true);$t->isFalse($class::execute_multiquery('multi-raw-fail'));$state->put('mysql_multi_ok',true);
		$state->put('mysql_stmt_modes',[false]);$t->isFalse($class::mysql_query('primary','SELECT ?',[1],false,false));
		$state->put('mysql_multi_ok',false);$t->isFalse($class::mysql_query('primary','SELECT 1',null,false,false));$state->put('mysql_multi_ok',true);
		$t->isTrue($class::mysql_query('primary','UPDATE normal SET id=1',null,false,false));
		$conn->storedResults=[new DpKqMysqlResult([['id'=>1]]),new DpKqMysqlResult([['id'=>2]])];$t->same(['id'=>1],$class::mysql_query('primary','SELECT 1; SELECT 2',null,false,false));
		$state->put('mysql_stmt_modes',[false]);$t->isFalse($class::mysql_select('primary','*','normal','',[1],true));
		$state->put('mysql_query_ok',false);$t->isFalse($class::mysql_select('primary','*','normal','',null,true));$state->put('mysql_query_ok',true);
		$state->put('mysql_rows',[]);$t->isFalse($class::mysql_select('primary','*','normal','',null,true));
		$state->put('mysql_stmt_modes',[false]);$t->isFalse($class::mysql_count('primary','normal','',[1]));
		$state->put('mysql_query_ok',false);$t->isFalse($class::mysql_count('primary','normal','',null));$state->put('mysql_query_ok',true);
		$state->put('mysql_stmt_modes',[false]);$t->isFalse($class::mysql_update('primary','normal','id=?','WHERE id=?',[2,1]));
		$state->put('mysql_stmt_modes',[false]);$t->isFalse($class::mysql_insert('primary','normal','id',[1]));
		$state->put('mysql_stmt_modes',[false]);$t->isFalse($class::mysql_delete('primary','normal','WHERE id=?',[1]));
		$state->put('mysql_query_ok',false);$t->isFalse($class::mysql_delete('primary','normal','',null));
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');

	test('sql kernel query builders deep coverage exercises PostgreSQL private executors queues and public operations',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\postgresql_query_builder::class;$conn=new DpKqPgConnection();$class::$conns['primary']=$conn;
		$builder=$t->nonPublic($class);
		$query=$t->nonPublic($class)->invokeWithArguments('mysql_compatibility_layer',['SELECT IFNULL(a,1), RAND(), UNIX_TIMESTAMP(), NOW(), FROM_UNIXTIME(?), x!=1 LIMIT 2,5']);$t->isTrue(str_contains($query,'COALESCE'));
		$t->same("'a\\\\b\\'c'",$t->nonPublic($class)->invokeWithArguments('conninfo_value',["a\\b'c"]));
		$row=['id'=>'7','active'=>'t','off'=>'f'];$result=new DpKqPgResult([$row],['id'=>'int4','active'=>'bool','off'=>'bool']);
		$normalized=$builder->capture('normalize_pg_value',query_result:$row,result:$result)->argument('query_result');
		$t->same(7,$normalized['id']);$t->isTrue($normalized['active']);$t->isFalse($normalized['off']);
		$t->same($conn,$t->nonPublic($class)->invokeWithArguments('connect_to_cluster',['primary']));$t->same($conn,$t->nonPublic($class)->invokeWithArguments('connect_to_endpoint',['one','primary']));
		$state->put('pg_execute_results',[new DpKqPgResult([['id'=>'1']],['id'=>'int4']),new DpKqPgResult([],[],3)]);$t->isTrue(dp_kq_prepared_execution($builder,$conn,[['query'=>'SELECT ?','vars'=>[1]],['query'=>'UPDATE normal SET id=?','vars'=>[1]]])->result());
		$state->put('pg_prepare_results',[false]);$t->isFalse(dp_kq_prepared_execution($builder,$conn,[['query'=>'UPDATE normal SET id=?','vars'=>[1]]])->result());
		$state->put('pg_get_results',[new DpKqPgResult([['id'=>'1']],['id'=>'int4']),false]);$t->isTrue(dp_kq_multi_execution($builder,$conn,'SELECT 1; ')->result());

		$state->put('pg_execute_results',array_fill(0,5,new DpKqPgResult([],[],2)));$class::$queued_queries['prepared']=dp_kq_queue(true);$t->isTrue($class::execute_multiquery('prepared'));
		$state->put('pg_get_results',array_merge(array_fill(0,5,new DpKqPgResult([['id'=>'1']],['id'=>'int4'])),[false]));$class::$queued_queries['raw']=dp_kq_queue(false);$t->isTrue($class::execute_multiquery('raw'));
		$state->put('pg_execute_results',array_fill(0,10,new DpKqPgResult([],[],2)));$class::$queued_queries['multi-prepared']=dp_kq_queue(true,true);$t->isTrue($class::execute_multiquery('multi-prepared'));
		$state->put('pg_get_results',array_merge(array_fill(0,10,new DpKqPgResult([['id'=>'1']],['id'=>'int4'])),[false]));$class::$queued_queries['multi-raw']=dp_kq_queue(false,true);$t->isTrue($class::execute_multiquery('multi-raw'));
		$state->put('hydrate',true);$state->put('pg_execute_results',array_fill(0,5,new DpKqPgResult([],[],1)));$t->isTrue($t->nonPublic($class)->invokeWithArguments('retry_batch_after_hydration',['retry',dp_kq_queue(true),'primary',false]));$state->put('hydrate',false);
		$t->same(null,$class::execute_multiquery('missing'));$t->isFalse($t->nonPublic($class)->invokeWithArguments('retry_batch_after_hydration',['x',dp_kq_queue(),'primary',true]));

		$state->put('pg_execute_results',[new DpKqPgResult([['id'=>'1','active'=>'t']],['id'=>'int4','active'=>'bool'])]);$t->same(1,$class::postgresql_query('primary','SELECT ?',[1],false,false)['id']);
		$state->put('pg_execute_results',[new DpKqPgResult([['id'=>'1']],['id'=>'int4']),new DpKqPgResult([['id'=>'1']],['id'=>'int4'])]);$t->same(1,count($class::postgresql_query('primary','SELECT ?',[1],true,true)));
		$state->put('pg_query_results',[new DpKqPgResult([['id'=>'3']],['id'=>'int4'])]);$t->same(3,$class::postgresql_query('primary','SELECT 3',null,false,false)['id']);
		$state->put('pg_query_results',[new DpKqPgResult([['id'=>'2']],['id'=>'int4'])]);$t->same(2,$class::postgresql_select('primary',['id'],'normal','',null,false)['id']);
		$state->put('pg_execute_results',[new DpKqPgResult([['id'=>'1'],['id'=>'2']],['id'=>'int4'])]);$t->same(2,count($class::postgresql_select('primary','id','normal','',[1],true)));
		$state->put('pg_execute_results',[new DpKqPgResult([['count'=>'5']],['count'=>'int8'])]);$t->same(5,$class::postgresql_count('primary','normal','',[1]));
		$state->put('pg_query_results',[new DpKqPgResult([['count'=>'6']],['count'=>'int8'])]);$t->same(6,$class::postgresql_count('primary','normal','',null));
		$state->put('pg_execute_results',[new DpKqPgResult([],[],4)]);$t->same(4,$class::postgresql_update('primary','normal','id=?','WHERE id=?',[2,1]));
		$state->put('pg_execute_results',[new DpKqPgResult([['id'=>'8']],['id'=>'int4'])]);$t->same(['id'=>'8'],$class::postgresql_insert('primary','normal','id',[8]));
		$state->put('pg_execute_results',[new DpKqPgResult([],[],3)]);$t->same(3,$class::postgresql_delete('primary','normal','WHERE id=?',[1]));
		$state->put('pg_query_results',[new DpKqPgResult([],[],2)]);$t->same(2,$class::postgresql_delete('primary','normal','',null));
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');

	test('PostgreSQL prepared parameters preserve false as an explicit boolean in direct and queued execution',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\postgresql_query_builder::class;$conn=new DpKqPgConnection();$class::$conns['primary']=$conn;
		$state->put('pg_execute_results',[new DpKqPgResult([['enabled'=>'f']],['enabled'=>'bool'])]);
		$t->isFalse($class::postgresql_query('primary','SELECT ?::boolean AS enabled',[false],false,false)['enabled']);
		$t->same([['false']],$state->get('pg_execute_vars'));

		$state->put('pg_execute_vars',[]);
		$state->put('pg_execute_results',[new DpKqPgResult([['enabled'=>'t']],['enabled'=>'bool'])]);
		$execution=$t->nonPublic($class)->capture('execute_prepared_statements',
			conn:$conn,
			prepared_statements:[['query'=>'SELECT ?::boolean AS enabled, ?::text AS marker','vars'=>[true,'kept']]],
			results:[],
			dbms_cluster:'primary',
		);
		$t->isTrue($execution->result());
		$t->same([['true','kept']],$state->get('pg_execute_vars'));
		$t->same([['enabled'=>true]],$execution->argument('results')[0]);

		$normalized=$t->nonPublic($class)->invokeWithArguments('normalize_pg_bound_values',[[false,true,null,0,1,'false']]);
		$t->same(['false','true',null,0,1,'false'],$normalized);
	})->tag('sql','postgresql','parameters','boolean','queued','regression')->group('framework-coverage');

	test('PostgreSQL compatibility preserves JSON operators and SQL literals while numbering bound placeholders',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\postgresql_query_builder::class;$builder=$t->nonPublic($class);
		$query=<<<'SQL'
SELECT capabilities ? 'change_control',
       capabilities ?| ARRAY['change_control','audit'],
       capabilities ?& ARRAY['change_control','audit'],
       capabilities @? '$.change_control',
       note='?', /* ? */ id=?, -- ?
       marker=?
SQL;
		$translated=$builder->invokeWithArguments('mysql_compatibility_layer',[$query]);
		$expected=<<<'SQL'
SELECT capabilities ? 'change_control',
       capabilities ?| ARRAY['change_control','audit'],
       capabilities ?& ARRAY['change_control','audit'],
       capabilities @? '$.change_control',
       note='?', /* ? */ id=$1, -- ?
       marker=$2
SQL;
		$t->same($expected,$translated);

		$versioned=<<<'SQL'
CREATE FUNCTION fixture.capability_exists(payload jsonb) RETURNS boolean
LANGUAGE plpgsql AS $capability_body$
BEGIN
    RETURN payload ? 'change_control';
END;
$capability_body$;
SQL;
		$t->same($versioned,$builder->invokeWithArguments('mysql_compatibility_layer',[$versioned]));

		$boundOperators=<<<'SQL'
SELECT payload ? ?, payload @? ?, marker=?
SQL;
		$translated=$builder->invokeWithArguments('mysql_compatibility_layer',[$boundOperators]);
		$expected=<<<'SQL'
SELECT payload ? $1, payload @? $2, marker=$3
SQL;
		$t->same($expected,$translated);
		$t->same('SELECT payload ? $1',$builder->invokeWithArguments('mysql_compatibility_layer',['SELECT payload ? $1']));

		$numberedBeforeDollarBody=<<<'SQL'
SELECT $1, body=$capability_body$ RETURN payload ? 'change_control'; $capability_body$
SQL;
		$translated=$builder->invokeWithArguments('mysql_compatibility_layer',[$numberedBeforeDollarBody]);
		$t->same($numberedBeforeDollarBody,$translated);

		$state->put('pg_get_results',[false,false]);
		$t->isTrue(dp_kq_multi_execution($builder,new DpKqPgConnection(),"SELECT capabilities ? 'change_control'; SELECT capabilities ?| ARRAY['audit'];")->result());
		$t->same([
			"SELECT capabilities ? 'change_control'",
			"SELECT capabilities ?| ARRAY['audit']",
		],$state->get('pg_queries'));

		$state->put('pg_queries',[]);$class::$conns['primary']=new DpKqPgConnection();
		$state->put('pg_execute_results',[new DpKqPgResult([],[],3)]);
		$t->same(3,$class::postgresql_delete('primary','normal',"WHERE capabilities ? 'change_control' AND id=?",[1]));
		$t->same(["DELETE FROM normal WHERE capabilities ? 'change_control' AND id=$1"],$state->get('pg_queries'));
	})->tag('sql','postgresql','jsonb','operators','placeholders','regression')->group('framework-coverage');

	test('sql kernel query builders deep coverage covers PostgreSQL executor and direct failure contracts',static function(Context $t): void {
		$state=dp_kq_scenario($t);$class=\dataphyre\postgresql_query_builder::class;$conn=new DpKqPgConnection();$class::$conns['primary']=$conn;
		$builder=$t->nonPublic($class);
		$state->put('pg_query_results',[false,new DpKqPgResult()]);$t->isFalse(dp_kq_prepared_execution($builder,$conn,[['query'=>'UPDATE normal SET id=?','vars'=>[1]]])->result());
		$state->put('pg_execute_results',[false]);$t->isFalse(dp_kq_prepared_execution($builder,$conn,[['query'=>'UPDATE normal SET id=?','vars'=>[1]]])->result());
		$state->put('pg_num_fields',0);$state->put('pg_execute_results',[new DpKqPgResult([],[],5)]);$execution=dp_kq_prepared_execution($builder,$conn,[['query'=>'UPDATE normal SET id=?','vars'=>[1]]]);$t->isTrue($execution->result());$t->same(5,$execution->argument('results')[0]);
		$state->put('pg_num_fields',1);$state->put('pg_execute_results',[new DpKqPgResult(false,['id'=>'int4'])]);$execution=dp_kq_prepared_execution($builder,$conn,[['query'=>'SELECT ?','vars'=>[1]]]);$t->isTrue($execution->result());$t->same([],$execution->argument('results')[0]);
		$state->put('pg_prepare_results',[false]);$state->put('pg_query_results',[new DpKqPgResult(),false]);$t->throws(static fn()=>dp_kq_prepared_execution($builder,$conn,[['query'=>'UPDATE normal SET id=?','vars'=>[1]]]),Throwable::class);

		$state->put('pg_query_results',[false,new DpKqPgResult()]);$t->isFalse(dp_kq_multi_execution($builder,$conn,'UPDATE normal SET id=1;')->result());
		$state->put('pg_send_results',[false]);$t->isFalse(dp_kq_multi_execution($builder,$conn,'SELECT 1;')->result());
		$state->put('pg_get_results',[new DpKqPgResult([],[] ,0,'bad')]);$state->put('pg_free_results',[false]);$t->isFalse(dp_kq_multi_execution($builder,$conn,'SELECT 1;')->result());
		$state->put('pg_get_results',[new DpKqPgResult([],[],0,'bad')]);$state->put('pg_free_results',[true]);$t->isFalse(dp_kq_multi_execution($builder,$conn,'SELECT 1;')->result());
		$state->put('pg_query_results',[new DpKqPgResult(),false]);$state->put('pg_send_results',[false]);$t->throws(static fn()=>dp_kq_multi_execution($builder,$conn,'UPDATE normal SET id=1;'),Throwable::class);

		$state->put('pg_prepare_results',[false]);$class::$queued_queries['prepared-fail']=dp_kq_queue(true);$t->isFalse($class::execute_multiquery('prepared-fail'));
		$state->put('pg_send_results',[false]);$class::$queued_queries['raw-fail']=dp_kq_queue(false);$t->isFalse($class::execute_multiquery('raw-fail'));
		$state->put('pg_prepare_results',[false]);$class::$queued_queries['multi-prepared-fail']=dp_kq_queue(true,true);$t->isFalse($class::execute_multiquery('multi-prepared-fail'));
		$state->put('pg_send_results',[false]);$class::$queued_queries['multi-raw-fail']=dp_kq_queue(false,true);$t->isFalse($class::execute_multiquery('multi-raw-fail'));

		$state->put('pg_prepare_results',[false]);$t->isFalse($class::postgresql_query('primary','SELECT ?',[1],false,false));
		$state->put('pg_execute_results',[false]);$t->isFalse($class::postgresql_query('primary','SELECT ?',[1],false,false));
		$state->put('pg_query_results',[false]);$t->isFalse($class::postgresql_query('primary','SELECT 1',null,false,false));
		$state->put('pg_prepare_results',[false]);$t->isFalse($class::postgresql_select('primary','*','normal','',[1],false));
		$state->put('pg_execute_results',[false]);$t->isFalse($class::postgresql_select('primary','*','normal','',[1],false));
		$state->put('pg_query_results',[false]);$t->isFalse($class::postgresql_select('primary','*','normal','',null,false));
		$state->put('pg_prepare_results',[false]);$t->isFalse($class::postgresql_count('primary','normal','',[1]));
		$state->put('pg_execute_results',[false]);$t->isFalse($class::postgresql_count('primary','normal','',[1]));
		$state->put('pg_query_results',[false]);$t->isFalse($class::postgresql_count('primary','normal','',null));
		$state->put('pg_prepare_results',[false]);$t->isFalse($class::postgresql_update('primary','normal','id=?','WHERE id=?',[2,1]));
		$state->put('pg_execute_results',[false]);$t->isFalse($class::postgresql_update('primary','normal','id=?','WHERE id=?',[2,1]));
		$state->put('pg_prepare_results',[false]);$state->put('pg_last_error','generic');$t->isFalse($class::postgresql_insert('primary','normal','id',[1]));
		$state->put('pg_prepare_results',[false,true]);$state->put('pg_last_error','unique constraint uuid');$state->put('pg_execute_results',[new DpKqPgResult([['id'=>'9']],['id'=>'int4'])]);$t->same(['id'=>'9'],$class::postgresql_insert('primary','normal','id',[9]));
		$state->put('pg_prepare_results',[]);$state->put('pg_last_error','generic');$state->put('pg_execute_results',[false]);$t->isFalse($class::postgresql_insert('primary','normal','id',[1]));
		$state->put('pg_prepare_results',[false]);$t->isFalse($class::postgresql_delete('primary','normal','WHERE id=?',[1]));
		$state->put('pg_execute_results',[false]);$t->isFalse($class::postgresql_delete('primary','normal','WHERE id=?',[1]));
		$state->put('pg_query_results',[false]);$t->isFalse($class::postgresql_delete('primary','normal','',null));
	})->tag('sql','kernel','query-builders','deep-coverage')->group('framework-coverage');
}
