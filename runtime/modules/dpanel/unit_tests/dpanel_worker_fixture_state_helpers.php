<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

final class DpDpanelWorkerPrivateFixture {
	private static function join(string $left,string $right): string {
		return $left.'-'.$right;
	}
}

function dp_dpanel_worker_fixture_error(callable $operation): string {
	try{
		$operation();
	}catch(InvalidArgumentException $exception){
		return $exception->getMessage();
	}
	return '';
}

function dp_dpanel_worker_fixture_state_contract_json(): string {
	dataphyre_dpanel_worker_fixture_state::resetSql();
	dataphyre_dpanel_worker_fixture_state::returnFromSql('select',['id'=>7]);
	$selected=sql_select('items',['id']);
	dataphyre_dpanel_worker_fixture_state::clearSqlResponse('select');
	$fallback=sql_select('items',['missing']);
	dataphyre_dpanel_worker_fixture_state::respondToSql(
		'insert',
		static fn(string $table,array $fields): array=>['id'=>(int)($fields['id'] ?? 0)+1]
	);
	$inserted=sql_insert('items',['id'=>8]);

	$application=dataphyre_dpanel_worker_application_state::class;
	$application::replaceQuery(['page'=>2]);
	$query=$application::query();
	$application::replaceSession(['keep'=>'yes']);
	$session=$application::sessionValue('keep');
	$application::putSession('added','ok');
	$sessionPut=$application::session()['added'] ?? null;
	$application::forgetSession('added');
	$sessionForgotten=!$application::sessionHas('added');
	$application::replaceServerValue('DP_WORKER_CONTRACT','server');
	$serverBefore=$application::hasServer('DP_WORKER_CONTRACT');
	$serverValue=$application::serverValue('DP_WORKER_CONTRACT');
	$application::forgetServer('DP_WORKER_CONTRACT');
	$serverAfter=$application::hasServer('DP_WORKER_CONTRACT');
	$serverSnapshot=$application::server();
	$application::replaceServer(['DP_WORKER_REPLACED'=>'whole']);
	$serverReplaced=$application::serverValue('DP_WORKER_REPLACED');
	$application::replaceServer($serverSnapshot);
	$application::replaceCookies(['keep'=>'yes']);
	$application::putCookie('added','ok');
	$cookies=$application::cookies();
	$application::forgetCookie('added');
	$cookieForgotten=!array_key_exists('added',$application::cookies());
	$application::replaceCookies([]);
	$application::replaceGlobal('dp_worker_contract','fixture');
	$globalBefore=$application::hasGlobal('dp_worker_contract');
	$globalValue=$application::globalValue('dp_worker_contract');
	$application::forgetGlobal('dp_worker_contract');
	$globalAfter=$application::hasGlobal('dp_worker_contract');
	$globalDefault=$application::globalValue('dp_worker_contract','default');
	$application::replaceQuery([]);
	$application::replaceSession([]);

	return json_encode([
		'selected'=>$selected,
		'inserted'=>$inserted,
		'select_calls'=>dataphyre_dpanel_worker_fixture_state::sqlCallCount('select'),
		'first_select_table'=>dataphyre_dpanel_worker_fixture_state::sqlCall('select')[0] ?? null,
		'missing_select_call'=>dataphyre_dpanel_worker_fixture_state::sqlCall('select',99),
		'fallback'=>$fallback,
		'private'=>dataphyre_dpanel_worker_fixture_state::invokeNonPublic(
			DpDpanelWorkerPrivateFixture::class,
			'join',
			['hidden','ok']
		),
		'query'=>$query,
		'session'=>$session,
		'session_put'=>$sessionPut,
		'session_forgotten'=>$sessionForgotten,
		'server_before'=>$serverBefore,
		'server_value'=>$serverValue,
		'server_after'=>$serverAfter,
		'server_replaced'=>$serverReplaced,
		'cookie_keep'=>$cookies['keep'] ?? null,
		'cookie_added'=>$cookies['added'] ?? null,
		'cookie_forgotten'=>$cookieForgotten,
		'global_before'=>$globalBefore,
		'global_value'=>$globalValue,
		'global_after'=>$globalAfter,
		'global_default'=>$globalDefault,
		'invalid_sql'=>dp_dpanel_worker_fixture_error(
			static fn()=>dataphyre_dpanel_worker_fixture_state::sqlCalls('drop')
		),
		'invalid_global'=>dp_dpanel_worker_fixture_error(
			static fn()=>$application::replaceGlobal('not valid','value')
		),
		'invalid_state_key'=>dp_dpanel_worker_fixture_error(
			static fn()=>$application::putSession(' ','value')
		),
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
