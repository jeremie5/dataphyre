<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Access\AccessIdentityRepository;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DP_ACCESS_CFG')){
	define('DP_ACCESS_CFG',[
		'identity'=>[
			'users_table'=>'unsafe table',
			'id_column'=>null,
			'email_column'=>null,
			'password_hash_column'=>null,
			'password_column'=>null,
		],
	]);
}

require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/access/Framework/AccessIdentityRepository.php';

test('access identity invalid config coverage rejects unavailable SQL operations',static function(Context $t): void {
	AccessIdentityRepository::flush();
	$repository=AccessIdentityRepository::instance();
	$t->same(null,$repository->findByEmail('valid@example.com'));
	$t->same(null,$repository->findById(1));
	$t->same(null,$repository->create(['name'=>'No Store']));
	$t->isFalse($repository->setPassword(['id'=>1],'secret'));
	$t->isFalse($repository->markEmailVerified(['id'=>1]));
	$t->isFalse($repository->canRegister());
});
