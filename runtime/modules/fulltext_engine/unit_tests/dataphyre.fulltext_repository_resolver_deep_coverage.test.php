<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database {
	if(!class_exists(TableRepository::class, false)){
		abstract class TableRepository {}
	}
}

namespace DataphyreFulltextResolverTests {
	use Dataphyre\Database\TableRepository;

	final class ValidRepository extends TableRepository {
		public static mixed $result=[];
		public static array $arguments=[];
		public static function findKeyedByIds(array $ids, string $primaryKey, array|string $columns='*', bool|array|string|null $caching=null): mixed {
			self::$arguments=[$ids, $primaryKey, $columns, $caching];
			return self::$result;
		}
	}

	final class NoMethodRepository extends TableRepository {}
	final class NotARepository {}
}

namespace {
	use Dataphyre\FulltextEngine\IndexDefinition;
	use Dataphyre\FulltextEngine\Resolvers\RepositoryDocumentResolver;
	use Dataphyre\Test\Context;
	use DataphyreFulltextResolverTests\NoMethodRepository;
	use DataphyreFulltextResolverTests\NotARepository;
	use DataphyreFulltextResolverTests\ValidRepository;
	use function Dataphyre\Test\test;

	$dp_fulltext_repository_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/fulltext_engine/Framework';
	require_once $dp_fulltext_repository_root.'/Contracts/DocumentResolver.php';
	require_once $dp_fulltext_repository_root.'/IndexDefinition.php';
	require_once $dp_fulltext_repository_root.'/Resolvers/RepositoryDocumentResolver.php';

	test('fulltext repository resolver validates classes methods result shapes and optional mapping', static function(Context $t): void {
		$t->throws(static fn()=>(new RepositoryDocumentResolver('', 'id'))->resolve(['one']), RuntimeException::class);
		$t->throws(static fn()=>(new RepositoryDocumentResolver('MissingRepository', 'id'))->resolve(['one']), RuntimeException::class);
		$t->throws(static fn()=>(new RepositoryDocumentResolver(NotARepository::class, 'id'))->resolve(['one']), RuntimeException::class);
		$t->throws(static fn()=>(new RepositoryDocumentResolver(NoMethodRepository::class, 'id'))->resolve(['one']), RuntimeException::class);

		ValidRepository::$result=false;
		$plain=new RepositoryDocumentResolver(ValidRepository::class, 'uuid', ['uuid', 'name'], ['cache']);
		$t->same([], $plain->resolve(['one']));
		$t->same([['one'], 'uuid', ['uuid', 'name'], ['cache']], ValidRepository::$arguments);
		ValidRepository::$result=['one'=>['uuid'=>'one', 'name'=>'One']];
		$t->same(ValidRepository::$result, $plain->resolve(['one']));

		$definition=new IndexDefinition('products', 'json', 'uuid');
		$mapped=new RepositoryDocumentResolver(
			ValidRepository::class,
			'uuid',
			'*',
			false,
			static fn(array $document, ?IndexDefinition $active): array=>[
				'uuid'=>$document['uuid'], 'index'=>$active?->name(),
			]
		);
		$t->same(['one'=>['uuid'=>'one', 'index'=>'products']], $mapped->resolve(['one'], $definition));
	})->tag('fulltext', 'repository-resolver', 'deep-coverage')->group('framework-coverage');
}
