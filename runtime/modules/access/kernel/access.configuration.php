<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

/** Normalizes the kernel-facing portion of Access configuration. */
final class access_configuration {
	/**
	 * @param array<string,mixed> $config
	 * @return array{sessions_table:string,token_table:string,auth_types:list<string>,default_auth_type:string}
	 */
	public static function normalize(array $config): array {
		$sessions_table=trim((string)($config['sessions_table_name'] ?? ''));
		if($sessions_table===''){
			$sessions_table='dataphyre.sessions';
		}
		$identity=is_array($config['identity'] ?? null) ? $config['identity'] : [];
		$token_table=trim((string)($identity['tokens_table'] ?? ''));
		if($token_table===''){
			$token_table='dataphyre.access_tokens';
		}
		$configured_auth_types=$config['auth_types'] ?? $config['enabled_auth_types'] ?? [];
		if(!is_array($configured_auth_types)){
			$configured_auth_types=[];
		}
		$auth_types=array_values(array_unique(array_filter(array_map(
			static fn(mixed $auth_type): string=>strtolower(trim((string)$auth_type)),
			$configured_auth_types
		), static fn(string $auth_type): bool=>$auth_type!=='')));
		if($auth_types===[]){
			$auth_types=['session'];
		}
		$default_auth_type=strtolower(trim((string)($config['default_auth_type'] ?? '')));
		if($default_auth_type===''){
			$default_auth_type='session';
		}
		if(!in_array($default_auth_type, $auth_types, true)){
			array_unshift($auth_types, $default_auth_type);
		}
		return [
			'sessions_table'=>$sessions_table,
			'token_table'=>$token_table,
			'auth_types'=>$auth_types,
			'default_auth_type'=>$default_auth_type,
		];
	}
}
