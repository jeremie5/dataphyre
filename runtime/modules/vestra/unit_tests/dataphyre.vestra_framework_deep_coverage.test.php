<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class vestra {
		public static array $calls=[];

		public static function configured(): bool {
			self::$calls[]=['configured'];
			return true;
		}

		public static function object_url(array $reference, array $parameters=[]): string|false {
			self::$calls[]=['object_url', $reference, $parameters];
			return 'https://objects.test/object';
		}

		public static function asset_url(array $reference, string $extension='', array $parameters=[]): string|false {
			self::$calls[]=['asset_url', $reference, $extension, $parameters];
			return 'https://objects.test/asset'.($extension!=='' ? '.'.$extension : '');
		}

		public static function update_use_count(array $reference, int $amount): int|false {
			self::$calls[]=['update_use_count', $reference, $amount];
			return 4+$amount;
		}

		public static function ingest_resources(string $html, ?int $resourceLimit=null, array $knownChanges=[]): array {
			self::$calls[]=['ingest_resources', $html, $resourceLimit, $knownChanges];
			return ['new_html'=>str_replace('old', 'new', $html), 'changes'=>[['from'=>'old', 'to'=>'new']]];
		}

		public static function propagate(string $file, bool $encryption=false, array $options=[]): array|false {
			self::$calls[]=['propagate', $file, $encryption, $options];
			return ['path'=>$file, 'encrypted'=>$encryption, 'options'=>$options];
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Vestra\Client;
	use Dataphyre\Vestra\IngestionResult;
	use Dataphyre\Vestra\VestraManager;
	use function Dataphyre\Test\test;

	if(!defined('DP_VESTRA_CFG')){
		define('DP_VESTRA_CFG', [
			'base_url'=>' https://api.vestra.test ',
			'object_url'=>' https://objects.vestra.test ',
		]);
	}
	$dp_vestra_framework_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/vestra/Framework';
	require_once $dp_vestra_framework_root.'/IngestionResult.php';
	require_once $dp_vestra_framework_root.'/VestraManager.php';
	require_once $dp_vestra_framework_root.'/Client.php';

	test('vestra ingestion result normalizes payloads and exposes immutable state', static function(Context $t): void {
		$direct=new IngestionResult('<p>ready</p>', [['asset'=>'one']]);
		$t->same('<p>ready</p>', $direct->html());
		$t->same([['asset'=>'one']], $direct->changes());
		$t->isTrue($direct->changed());
		$t->same(['new_html'=>'<p>ready</p>', 'changes'=>[['asset'=>'one']]], $direct->toArray());

		$empty=IngestionResult::fromArray(['new_html'=>42, 'changes'=>'invalid']);
		$t->same('42', $empty->html());
		$t->same([], $empty->changes());
		$t->isFalse($empty->changed());
		$t->same(['new_html'=>'', 'changes'=>[]], IngestionResult::fromArray([])->toArray());
	})->tag('vestra', 'framework', 'deep-coverage')->group('framework-coverage');

	test('vestra manager and client delegate every framework operation to the kernel', static function(Context $t): void {
		VestraManager::flush();
		$manager=VestraManager::instance();
		$t->same($manager, VestraManager::instance());
		$t->isTrue($manager->configured());
		$t->same('https://api.vestra.test', $manager->baseUrl());
		$t->same('https://objects.vestra.test', $manager->objectUrl());
		$reference=['id'=>'asset-one'];
		$t->same('https://objects.test/object', $manager->objectUrlFor($reference, ['tenant'=>'one']));
		$t->same('https://objects.test/asset.png', $manager->assetUrl($reference, 'png', ['quality'=>80]));
		$t->same(2, $manager->updateUseCount($reference, -2));
		$ingested=$manager->ingest('<img src="old">', 3, ['old'=>['cached'=>true]]);
		$t->instanceOf(IngestionResult::class, $ingested);
		$t->same('<img src="new">', $ingested->html());
		$t->same(['path'=>'fixture.png', 'encrypted'=>true, 'options'=>['object_expires_in_secs'=>60]], $manager->propagate('fixture.png', true, ['object_expires_in_secs'=>60]));

		$t->same($manager, Client::manager());
		$t->isTrue(Client::configured());
		$t->same('https://api.vestra.test', Client::baseUrl());
		$t->same('https://objects.vestra.test', Client::objectUrl());
		$t->same('https://objects.test/object', Client::objectUrlFor($reference));
		$t->same('https://objects.test/asset.jpg', Client::assetUrl($reference, 'jpg'));
		$t->same('https://objects.test/asset.webp', Client::asset_url($reference, 'webp'));
		$t->same(5, Client::updateUseCount($reference, 1));
		$t->instanceOf(IngestionResult::class, Client::ingest('old'));
		$t->same(['path'=>'client.png', 'encrypted'=>false, 'options'=>[]], Client::propagate('client.png'));

		VestraManager::flush();
		$t->isFalse($manager===VestraManager::instance());
	})->tag('vestra', 'framework', 'deep-coverage')->group('framework-coverage');
}
