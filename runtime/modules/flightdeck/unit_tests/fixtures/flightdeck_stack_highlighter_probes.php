<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

final class DpFlightdeckStackReadableHighlighter {
	public static function highlight_code(string $code,string $language,array $options=[]): string {
		return '<code data-language="'.$language.'">'.htmlspecialchars($code,ENT_QUOTES).'</code>';
	}
	public static function linkify_php(string $html,string $project,string $namespace,string $class,string $function): string {
		return '<a data-project="'.htmlspecialchars($project,ENT_QUOTES).'">'.$html.'</a>';
	}
}

final class DpFlightdeckStackThrowingHighlighter {
	public static function highlight_code(string $code,string $language,array $options=[]): never {
		throw new RuntimeException('Synthetic highlighter failure.');
	}
}
