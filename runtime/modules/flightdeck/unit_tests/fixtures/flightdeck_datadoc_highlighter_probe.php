<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre\datadoc;

/** Predictable rich-code renderer for DataDoc surface integration tests. */
final class highlighter {
	public static bool $throw=false;
	public static array $calls=[];

	public static function highlight_code(string $code, string $language, array $options=[]): string {
		self::$calls[]=['highlight',$code,$language,$options];
		if(self::$throw){
			throw new \RuntimeException('highlighter failed');
		}
		return '<mark>'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'</mark>';
	}

	public static function linkify_php(string $html, string $project='', string $namespace='', string $class='', string $function=''): string {
		self::$calls[]=['linkify',$html,$project,$namespace,$class,$function];
		return '<linked data-project="'.htmlspecialchars($project, ENT_QUOTES, 'UTF-8').'">'.$html.'</linked>';
	}
}
