<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Reactor {
	final class DpMvcFallbackReactorResult implements \JsonSerializable {
		public function jsonSerialize(): mixed { return ['status'=>210,'ok'=>true]; }
		public function status(): int { return 210; }
	}
	final class Reactor {
		public static function dispatch(array|object|null $request=null): object { return new DpMvcFallbackReactorResult(); }
	}
}

namespace {
	use Dataphyre\Mvc\Mvc;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['http','routing','mvc']);

	test('mvc facade fallback deep coverage closes local normalization and mapping branches',static function(Context $t): void {
		$t->same('pe****@example.test',Mvc::anonymizeEmail('person@example.test',2,'*'));
		$t->same(210,Mvc::reactorDispatch(['event'=>'x'])->status);
		$internals=$t->nonPublic(Mvc::class);
		$t->same([],$internals->invoke('storageMetadata','missing.txt',null));
		$types=[
			'file.csv'=>'text/csv; charset=utf-8','file.gif'=>'image/gif','file.htm'=>'text/html; charset=utf-8','file.html'=>'text/html; charset=utf-8',
			'file.js'=>'application/javascript; charset=utf-8','file.json'=>'application/json; charset=utf-8','file.pdf'=>'application/pdf','file.png'=>'image/png',
			'file.svg'=>'image/svg+xml','file.txt'=>'text/plain; charset=utf-8','file.webp'=>'image/webp',
		];
		foreach($types as $path=>$expected){ $t->same($expected,$internals->invoke('storageMimeType',$path,[])); }
		$t->same("<one>\n<two>",$internals->invoke('assetHtml',['head_tags'=>['<one>','<two>']],'head'));
	})->tag('mvc','facade','fallback','deep-coverage')->group('framework-coverage');
}
