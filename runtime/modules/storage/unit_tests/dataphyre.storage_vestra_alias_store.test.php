<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Storage\Contracts\VestraAliasStore;
use Dataphyre\Storage\Drivers\VestraDriver;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$modules=rtrim((string)ROOTPATH['common_dataphyre_runtime'],'/').'/modules';
require_once $modules.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modules);
\dataphyre\autoloader::register_framework_modules(['storage']);

test('Vestra aliases survive driver replacement without a local manifest', static function(Context $t): void {
	$store=new class implements VestraAliasStore {
		public array $rows=[];
		public bool $failWrites=false;
		public bool $failReads=false;
		public int $writes=0;
		public function lookup(string $path): ?array {
			if($this->failReads) throw new RuntimeException('Store unavailable.');
			return $this->rows[$path] ?? null;
		}
		public function put(string $path,array $reference): bool {
			$this->writes++;
			if($this->failWrites) return false;
			$this->rows[$path]=$reference;return true;
		}
		public function delete(string $path): bool {unset($this->rows[$path]);return true;}
		public function list(string $prefix=''): array {return $this->rows;}
	};
	$uploaded=false;$objects=[];
	$client=static function(string $operation,array $arguments)use(&$uploaded,&$objects): mixed {
		if($operation==='propagate'){
			if(!$uploaded) return false;
			$id=count($objects)+1;$objects[$id]=(string)file_get_contents($arguments[0]);
			return ['object_id'=>$id,'tenant'=>'example'];
		}
		if($operation==='asset_url') return (string)$arguments[0]['object_id'];
		if($operation==='download') return $objects[(int)$arguments[0]] ?? false;
		return true;
	};
	$config=['alias_store'=>$store,'client_handler'=>$client];
	$first=new VestraDriver($config);
	$t->isFalse($first->write('docs/a.txt','one'));
	$t->same(0,$store->writes);
	$uploaded=true;
	$t->isTrue($first->write('docs/a.txt','one'));
	$next=new VestraDriver($config);
	$t->same('one',$next->read('docs/a.txt'));
	$t->isTrue($next->write('docs/b.txt','two'));
	$t->same(2,count($first->list('docs/')));
	$store->failWrites=true;
	$t->isFalse($next->write('docs/a.txt','replacement'));
	$t->same('one',$first->read('docs/a.txt'));
	$store->failReads=true;
	$t->throws(static fn()=>$next->exists('docs/a.txt'),RuntimeException::class);
	$store->failReads=false;
	$t->isTrue($next->delete('docs/a.txt'));
	$t->isFalse($first->exists('docs/a.txt'));
	$t->same('two',$first->read('docs/b.txt'));
	$t->throws(static fn()=>new VestraDriver(['alias_store'=>new stdClass()]),InvalidArgumentException::class);
})->tag('storage','vestra','durability');
