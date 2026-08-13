<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\AssertionFailed;
use Dataphyre\Test\Context;
use Dataphyre\Test\FakeAuth;
use Dataphyre\Test\FakeClock;
use Dataphyre\Test\FakeDatabase;
use Dataphyre\Test\FakeHookBus;
use Dataphyre\Test\FakeHttp;
use Dataphyre\Test\FakeMailer;
use Dataphyre\Test\FakePermissions;
use Dataphyre\Test\FakeQueue;
use Dataphyre\Test\FakeReactor;
use Dataphyre\Test\FakeSql;
use Dataphyre\Test\FakeStorage;
use Dataphyre\Test\Fakes;
use Dataphyre\Test\FunctionPatches;
use Dataphyre\Test\MockObject;
use Dataphyre\Test\PdoDatabaseAssertions;
use Dataphyre\Test\ScriptedPdo;
use Dataphyre\Test\ScriptedPdoStatement;
use Dataphyre\Test\Spy;
use function Dataphyre\Test\test;

function dp_testkit_fakes_failure(callable $callback): AssertionFailed {
	try{
		$callback();
	}catch(AssertionFailed $failure){
		return $failure;
	}
	throw new RuntimeException('Expected an AssertionFailed exception.');
}

final class DpTestkitPdoStatement extends PDOStatement {
	public ?array $executed=null;

	public function __construct(private array $rows=[],private mixed $column=false) {}

	public function fetchColumn(int $column=0): mixed {
		return $this->column;
	}

	public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
		return $this->rows;
	}

	public function execute(?array $params=null): bool {
		$this->executed=$params;
		return true;
	}
}

final class DpTestkitPdo extends PDO {
	public bool $begun=false;
	public bool $rolledBack=false;
	public bool $committed=false;
	public bool $rowExists=true;

	public function __construct(public string $driver='sqlite') {}

	public function beginTransaction(): bool {
		$this->begun=true;
		return true;
	}

	public function rollBack(): bool {
		$this->rolledBack=true;
		return true;
	}

	public function commit(): bool {
		$this->committed=true;
		return true;
	}

	public function getAttribute(int $attribute): mixed {
		return $attribute===PDO::ATTR_DRIVER_NAME ? $this->driver : null;
	}

	public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
		if(str_starts_with($query,'PRAGMA')){
			return new DpTestkitPdoStatement([['name'=>'id'],['name'=>''],['name'=>'title']]);
		}
		if(str_contains($query,'COUNT(*)')){
			return new DpTestkitPdoStatement([],2);
		}
		return new DpTestkitPdoStatement([],$this->rowExists);
	}

	public function prepare(string $query,array $options=[]): PDOStatement|false {
		if(str_contains($query,'information_schema')){
			return new DpTestkitPdoStatement(['id','title']);
		}
		return new DpTestkitPdoStatement([],$this->rowExists);
	}
}

test('testing fakes clock storage mailer http auth and sql cover aliases and failures',static function(Context $t): void {
	$clock=Fakes::clock(100);
	$t->same(100,$clock->timestamp());
	$t->same(100,$clock->now()->getTimestamp());
	$clock->freeze(new DateTimeImmutable('@200'))->travelTo('1970-01-01 00:05:00 UTC')->travel(10)->rewind(5);
	$t->same(305,$clock->timestamp());
	$t->throws(static fn()=>new FakeClock('not a real date at all'),InvalidArgumentException::class);

	$storage=Fakes::storage();
	$storage->put('\\tenant\\b.txt','b');
	$storage->write('/tenant/a.txt','a');
	$t->isTrue($storage->has('tenant/a.txt'));
	$t->same(['tenant/a.txt','tenant/b.txt'],$storage->files());
	$t->same(['tenant/a.txt'=>'a','tenant/b.txt'=>'b'],$storage->all());
	$storage->delete('tenant/a.txt');
	$storage->remove('tenant/b.txt');
	$t->isFalse($storage->exists('tenant/a.txt'));
	$t->same('fallback',$storage->get('missing','fallback'));
	$t->same('fallback',$storage->read('missing','fallback'));
	$storage->put('present','value');
	$storage->assertStored($t,'present');
	dp_testkit_fakes_failure(static fn()=>$storage->assertExists($t,'missing'));

	$mailer=Fakes::mailer();
	$t->same(null,$mailer->last());
	$mailer->send('one@example.test','First',['id'=>1]);
	$mailer->send('two@example.test','Second',['id'=>2]);
	$t->same(2,count($mailer->sent()));
	$mailer->assertSent($t,'','Second');
	dp_testkit_fakes_failure(static fn()=>$mailer->assertSent($t,'missing@example.test'));
	dp_testkit_fakes_failure(static fn()=>$mailer->assertSent($t,'one@example.test','First',['id'=>2]));

	$http=Fakes::http();
	$t->same(404,$http->get('/missing')['status']);
	$http->respond('PUT','/items',204,null,['X'=>'y']);
	$t->same(204,$http->put('/items',['id'=>1])['status']);
	$http->delete('/items/1');
	$http->assertRequested($t,'PUT','/items',['id'=>1]);
	dp_testkit_fakes_failure(static fn()=>$http->assertRequested($t,'POST','/items'));
	dp_testkit_fakes_failure(static fn()=>$http->assertRequested($t,'PUT','/items',['id'=>2]));

	$auth=Fakes::auth();
	$t->same(null,$auth->user());
	$auth->login((object)['id'=>7]);
	$t->same(7,$auth->id());
	$auth->login('scalar-id');
	$t->same('scalar-id',$auth->id());
	$auth->login(new stdClass());
	$t->same(null,$auth->id());
	$auth->logout();

	$sql=Fakes::sql()->rejectUnboundWrites(false);
	$sql->query('select 1');
	$sql->query('update items set name="x"');
	$t->same(2,count($sql->queries()));
	dp_testkit_fakes_failure(static fn()=>$sql->assertQueried($t,'/missing/'));
	dp_testkit_fakes_failure(static fn()=>$sql->assertQueried($t,'/select/',['unexpected']));
	dp_testkit_fakes_failure(static fn()=>$sql->assertNoUnboundWrites($t));
})->tag('testing','fakes','coverage')->group('framework-coverage');

test('testing fake database covers commits transactions mutations diffs and assertion errors',static function(Context $t): void {
	$db=Fakes::database(['orders'=>['id'=>'int','status'=>'string','legacy'=>'string']]);
	$db->insert('orders',['id'=>1,'status'=>'open','legacy'=>'x']);
	$db->insert('orders',['id'=>2,'status'=>'closed','legacy'=>'y']);
	$t->same(1,$db->update('orders',['id'=>1],['status'=>'paid']));
	$t->same(0,$db->update('orders',['id'=>99],['status'=>'none']));
	$t->same('paid',$db->rows('orders')[0]['status']);
	$t->same(1,$db->delete('orders',['id'=>2]));
	$t->same(0,$db->delete('orders',['id'=>99]));
	$t->same(1,count($db->rows('orders')));
	$t->same(['id'=>'int','status'=>'string','legacy'=>'string'],$db->schema('orders'));
	$t->same([
		'missing'=>['new'],'extra'=>['legacy'],'changed'=>['status'],
	],$db->diffSchema('orders',['id'=>'int','status'=>'text','new'=>'int']));

	$db->begin()->insert('orders',['id'=>3])->commit();
	$t->same(2,count($db->rows('orders')));
	$result=$db->transaction(static function(FakeDatabase $database): string {
		$database->insert('orders',['id'=>4]);
		return 'committed';
	});
	$t->same('committed',$result);
	$t->same(3,count($db->rows('orders')));
	$t->throws(static fn()=>$db->transaction(static function(FakeDatabase $database): never {
		$database->insert('orders',['id'=>5]);
		throw new RuntimeException('rollback');
	}),RuntimeException::class);
	$t->same(3,count($db->rows('orders')));
	$db->rollback();

	dp_testkit_fakes_failure(static fn()=>$db->assertTableHas($t,'orders',['id'=>99]));
	dp_testkit_fakes_failure(static fn()=>$db->assertTableMissing($t,'orders',['id'=>1]));
	dp_testkit_fakes_failure(static fn()=>$db->assertTableCount($t,'orders',99));
	dp_testkit_fakes_failure(static fn()=>$db->assertSchemaHasColumn($t,'orders','missing'));
	$t->throws(static fn()=>$db->rows(' '),InvalidArgumentException::class);
})->tag('testing','fakes','coverage')->group('framework-coverage');

test('testing pdo assertions cover sqlite generic transactions rows schemas and safety',static function(Context $t): void {
	$sqlite=new DpTestkitPdo('sqlite');
	$database=$t->pdoDatabase($sqlite);
	$t->instanceOf(PdoDatabaseAssertions::class,$database);
	$database->begin()->begin()->rollback()->rollback();
	$t->isTrue($sqlite->begun);
	$t->isTrue($sqlite->rolledBack);
	$database->begin()->commit()->commit();
	$t->isTrue($sqlite->committed);
	$t->same('ok',$database->transaction(static fn(): string=>'ok'));
	$t->throws(static fn()=>$database->transaction(static fn()=>throw new RuntimeException('pdo rollback')),RuntimeException::class);

	$database->assertTableHas($t,'orders',['id'=>1]);
	$sqlite->rowExists=false;
	$database->assertTableMissing($t,'orders',['id'=>2]);
	$sqlite->rowExists=true;
	$database->assertTableHas($t,'orders',[]);
	$database->assertTableCount($t,'orders',2);
	$t->same(['id','title'],$database->columns('orders'));
	$t->same(['orders'=>['id','title']],$database->schemaSnapshot(['orders']));
	$t->same(['missing'=>['missing'],'extra'=>['title']],$database->diffSchema('orders',['id','missing']));
	$database->assertSchemaHasColumn($t,'orders','id');

	$generic=new PdoDatabaseAssertions(new DpTestkitPdo('mysql'));
	$t->same(['id','title'],$generic->columns('orders'));
	$t->throws(static fn()=>$generic->assertTableHas($t,'bad-name',[]),InvalidArgumentException::class);
})->tag('testing','fakes','coverage')->group('framework-coverage');

test('scripted PDO makes driver protocol success and failure paths portable',static function(Context $t): void {
	$pdo=$t->scriptedPdo('pgsql');
	$t->instanceOf(ScriptedPdo::class,$pdo);
	$t->same(null,$pdo->lastStatement());
	$t->same('pgsql',$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
	$t->same(PDO::ERRMODE_EXCEPTION,$pdo->getAttribute(PDO::ATTR_ERRMODE));
	$t->same(null,$pdo->getAttribute(PDO::ATTR_TIMEOUT));

	$pdo->queueRows([['id'=>1]])->queueScalar(7);
	$rows=$pdo->prepare('SELECT id FROM orders WHERE id=:p1');
	$t->instanceOf(ScriptedPdoStatement::class,$rows);
	$t->isTrue($rows->bindValue(':p1',1,PDO::PARAM_INT));
	$t->isTrue($rows->execute());
	$t->same(['id'=>1],$rows->fetch(PDO::FETCH_ASSOC));
	$t->isFalse($rows->fetch(PDO::FETCH_ASSOC));
	$t->same([['id'=>1]],$rows->fetchAll(PDO::FETCH_ASSOC));
	$t->same(1,$rows->rowCount());
	$t->isTrue($rows->closeCursor());
	$t->same([':p1'=>['value'=>1,'type'=>PDO::PARAM_INT]],$rows->bindings());
	$t->same([':p1'=>1],$rows->bindingValues());
	$t->same([':p1'=>PDO::PARAM_INT],$rows->bindingTypes());
	$t->same([null],$rows->executions());
	$t->isTrue($rows->closed());

	$scalar=$pdo->query('SELECT COUNT(*) FROM orders',PDO::FETCH_ASSOC,'extra');
	$t->same(7,$scalar->fetchColumn());
	$t->same(2,count($pdo->prepared()));
	$t->same(['operation'=>'query','sql'=>'SELECT COUNT(*) FROM orders','options'=>[PDO::FETCH_ASSOC,'extra']],$pdo->prepared()[1]);
	$t->same(['SELECT id FROM orders WHERE id=:p1','SELECT COUNT(*) FROM orders'],$pdo->preparedSql());
	$t->same(2,count($pdo->statements()));
	$t->same($scalar,$pdo->lastStatement());

	$default=$pdo->query('SELECT 1');
	$t->instanceOf(ScriptedPdoStatement::class,$default);
	$t->same([],$pdo->prepared()[2]['options']);
	$t->isFalse($default->fetchColumn());
	$t->same([],$default->fetchAll());

	$notExecuted=(new ScriptedPdoStatement())->returnExecuteResult(false)->returnRowCount(4);
	$t->isFalse($notExecuted->execute(['p1'=>1]));
	$t->same(4,$notExecuted->rowCount());
	$t->same([['p1'=>1]],$notExecuted->executions());

	$bindFailure=(new ScriptedPdoStatement())->failBindWith(new RuntimeException('bind'));
	$t->throws(static fn()=>$bindFailure->bindValue(':p1',1),RuntimeException::class);
	$executeFailure=(new ScriptedPdoStatement())->failExecuteWith(new RuntimeException('execute'));
	$t->throws(static fn()=>$executeFailure->execute(),RuntimeException::class);
	$rowsFailure=(new ScriptedPdoStatement())->failRowsWith(new RuntimeException('rows'));
	$t->throws(static fn()=>$rowsFailure->fetchAll(),RuntimeException::class);
	$scalarFailure=(new ScriptedPdoStatement())->failScalarWith(new RuntimeException('scalar'));
	$t->throws(static fn()=>$scalarFailure->fetchColumn(),RuntimeException::class);
	$closeFailure=(new ScriptedPdoStatement())->failCloseWith(new RuntimeException('close'));
	$t->throws(static fn()=>$closeFailure->closeCursor(),RuntimeException::class);
	$t->isTrue($closeFailure->closed());

	$pdo->queuePrepareMiss();
	$t->isFalse($pdo->prepare('SELECT missing'));
	$pdo->queuePrepareFailure(new RuntimeException('prepare'));
	$t->throws(static fn()=>$pdo->prepare('SELECT broken'),RuntimeException::class);

	$pdo->queueExecResult(2)->queueExecResult(false)->queueExecFailure(new RuntimeException('exec'));
	$t->same(2,$pdo->exec('UPDATE orders SET seen = 1'));
	$t->isFalse($pdo->exec('UPDATE orders SET seen = 0'));
	$t->throws(static fn()=>$pdo->exec('UPDATE broken'),RuntimeException::class);
	$t->isFalse($pdo->inTransaction());
	$t->isTrue($pdo->beginTransaction());
	$t->isTrue($pdo->inTransaction());
	$t->isTrue($pdo->commit());
	$t->isFalse($pdo->inTransaction());
	$t->isFalse($pdo->returnBeginResult(false)->beginTransaction());
	$t->isTrue($pdo->markTransactionActive()->rollBack());
	$t->isFalse($pdo->inTransaction());
	$t->same(7,count($pdo->operations()));
	$t->same(['exec','exec','exec','begin','commit','begin','rollback'],$pdo->operationNames());

	$rollbackFailure=$t->scriptedPdo()->markTransactionActive()->failRollbackWith(new RuntimeException('rollback'));
	$t->throws(static fn()=>$rollbackFailure->rollBack(),RuntimeException::class);
	$t->isTrue($rollbackFailure->inTransaction());
	$commitResultFailure=$t->scriptedPdo()->markTransactionActive()->returnCommitResult(false);
	$t->isFalse($commitResultFailure->commit());
	$t->isTrue($commitResultFailure->inTransaction());
	$rollbackResultFailure=$t->scriptedPdo()->markTransactionActive()->returnRollbackResult(false);
	$t->isFalse($rollbackResultFailure->rollBack());
	$t->isTrue($rollbackResultFailure->inTransaction());

	$driverFailure=Fakes::pdo('sqlite')->failDriverWith(new RuntimeException('driver'));
	$t->throws(static fn()=>$driverFailure->getAttribute(PDO::ATTR_DRIVER_NAME),RuntimeException::class);
})->tag('testing','fakes','pdo','portable')->group('framework-coverage');

test('testing queue hooks reactor permissions spies mocks and patches cover negative paths',static function(Context $t): void {
	$clock=new FakeClock(100);
	$queue=Fakes::queue($clock);
	$queue->later(-5,'plain',['id'=>1]);
	$queue->later(50,'future',['id'=>2]);
	$t->same(2,count($queue->jobs()));
	$t->same(['id'=>1],$queue->runNext());
	$t->same(0,$queue->runAll());
	$clock->advance(50);
	$t->same(1,$queue->runAll());
	$queue->push('mismatch',['id'=>1]);
	dp_testkit_fakes_failure(static fn()=>$queue->assertPushed($t,'other'));
	dp_testkit_fakes_failure(static fn()=>$queue->assertPushed($t,'mismatch',['id'=>2]));
	$queue->assertPushedCount($t,1);

	$hooks=new FakeHookBus('custom','');
	$hooks->on('event name',static fn(array $payload,string $name,string $scope): string=>$scope.'-'.$name.'-'.$payload['id'],' ');
	$t->same(['app-EVENT_NAME-1'],$hooks->dispatch('event name',['id'=>1],' '));
	$t->same(1,count($hooks->calls()));
	dp_testkit_fakes_failure(static fn()=>$hooks->assertCalled($t,'missing'));
	dp_testkit_fakes_failure(static fn()=>$hooks->assertCalled($t,'event name','app',['id'=>2]));
	dp_testkit_fakes_failure(static fn()=>$hooks->assertNotCalled($t,'event name','app'));
	dp_testkit_fakes_failure(static fn()=>$hooks->assertCalledTimes($t,'event name',2,'app'));

	$reactor=new FakeReactor();
	$reactor->dispatch('unhandled',['id'=>1]);
	$t->same(1,count($reactor->events()));
	dp_testkit_fakes_failure(static fn()=>$reactor->assertDispatched($t,'missing'));
	dp_testkit_fakes_failure(static fn()=>$reactor->assertDispatched($t,'unhandled',['id'=>2]));
	dp_testkit_fakes_failure(static fn()=>$reactor->assertListening($t,'missing'));

	$actor=(object)['id'=>7];
	$resource=(object)['key'=>'R1'];
	$permissions=new FakePermissions();
	$permissions
		->allow('*','*','*',static fn(): bool=>false)
		->allow('view',$resource,$actor,static fn(): bool=>true)
		->allow('edit',['id'=>1],['key'=>'actor'])
		->allow('scalar','document','user')
		->deny('delete','*','*');
	$t->isFalse($permissions->permits($actor,'missing',$resource));
	$t->isTrue($permissions->permits($actor,'view',$resource));
	$t->isFalse($permissions->permits($actor,'delete',$resource));
	$t->isFalse($permissions->permits((object)['id'=>8],'view',$resource));
	$t->isFalse($permissions->permits($actor,'view',(object)['key'=>'R2']));
	$t->isTrue($permissions->permits(['key'=>'actor'],'edit',['id'=>1]));
	$t->isTrue($permissions->permits('user','scalar','document'));
	$permissions->assertPermits($t,$actor,'view',$resource);
	$permissions->assertDenies($t,$actor,'delete',$resource);

	$spy=new Spy();
	$t->same(null,$spy('a'));
	$t->same([['a']],$spy->calls());
	dp_testkit_fakes_failure(static fn()=>(new Spy())->assertCalled($t));
	dp_testkit_fakes_failure(static fn()=>$spy->assertCalledWith($t,['missing']));
	$scripted=(new Spy())->willReturnInOrder('first','second');
	$t->same('first',$scripted(['id'=>1]));
	$t->same('second',$scripted(['id'=>2]));
	$t->same(null,$scripted(['id'=>3]));
	$t->same([['id'=>3]],$scripted->lastCall());
	$t->same([['id'=>1]],$scripted->call(0));
	$scripted->assertCalledWithSubset($t,[['id'=>2]]);
	dp_testkit_fakes_failure(static fn()=>$scripted->assertCalledWithSubset($t,[['id'=>9]]));
	$t->throws(static fn()=>(new Spy())->lastCall(),OutOfBoundsException::class);
	$t->throws(static fn()=>$scripted->call(9),OutOfBoundsException::class);
	$t->same('stable',$scripted->willReturn('stable')());
	$t->throws(static fn()=>$scripted->willThrow(new LogicException('scripted'))(),LogicException::class);
	$sequence=(new Spy())
		->thenReturn('queued')
		->thenCall(static fn(string $value): string=>strtoupper($value))
		->thenThrow(new RuntimeException('queued failure'));
	$t->same('queued',$sequence('first'));
	$t->same('SECOND',$sequence('second'));
	$t->throws(static fn()=>$sequence('third'),RuntimeException::class);
	$mock=new MockObject();
	$t->same(null,$mock->undefined('x'));
	$t->same(1,$mock->spy('undefined')->count());
	$t->same(null,$mock->spy('new-method')());

	$t->throws(static fn()=>FunctionPatches::define('global_function'),InvalidArgumentException::class);
	$t->throws(static fn()=>FunctionPatches::define('Bad\\bad-name'),InvalidArgumentException::class);
	$t->throws(static fn()=>FunctionPatches::define('bad-name\\valid'),InvalidArgumentException::class);
	$t->throws(static fn()=>FunctionPatches::define('Dataphyre\\Test\\test'),InvalidArgumentException::class);
	$t->throws(static fn()=>FunctionPatches::call('Missing\\patch',[]),BadFunctionCallException::class);
})->tag('testing','fakes','coverage')->group('framework-coverage');
