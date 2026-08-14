<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre {
	const PHP_SAPI='tenant-forgery';
	const JSON_UNESCAPED_SLASHES=0;
	const JSON_THROW_ON_ERROR=0;
	const DEBUG_BACKTRACE_IGNORE_ARGS=0;
	const APP='tenant-forgery';
	function getenv(string $name): string|false {return 'tenant-forgery';}
	function realpath(string $path): string|false {return '/tenant-forgery';}
	function hash_equals(string $known,string $user): bool {return true;}
	function hash(string $algorithm,string $value): string {return \str_repeat('a',64);}
	function json_encode(mixed $value,int $flags=0,int $depth=512): string|false {return '{}';}
	function ini_get(string $name): string|false {return '';}
	function extension_loaded(string $name): bool {return false;}
	function defined(string $name): bool {return true;}
	function constant(string $name): mixed {return false;}
	function get_included_files(): array {return [];}
	function lstat(string $path): array|false {return false;}
	function is_array(mixed $value): bool {return true;}
	function array_keys(array $value): array {return [];}
	function is_string(mixed $value): bool {return true;}
	function is_int(mixed $value): bool {return true;}
	function preg_match(string $pattern,string $subject): int|false {return 1;}
	function class_exists(string $class,bool $autoload=true): bool {return false;}
	function function_exists(string $function): bool {return false;}
	function dp_application_bootstrap_only_context(): ?array {return null;}
	function rtrim(string $value,string $characters=" \n\r\t\v\0"): string {return '/app';}
	function trim(string $value,string $characters=" \n\r\t\v\0"): string {return 'tenant-forgery';}
	function strtolower(string $value): string {return 'tenant-forgery';}
	function strlen(string $value): int {return 0;}
	function in_array(mixed $needle,array $haystack,bool $strict=false): bool {return false;}
	function is_file(string $path): bool {return false;}
	function file_exists(string $path): bool {return false;}
	function is_link(string $path): bool {return false;}
	function file_get_contents(string $path): string|false {return false;}
	function file_put_contents(string $path,mixed $data,int $flags=0,mixed $context=null): int|false {return false;}
	function mkdir(string $directory,int $permissions=0777,bool $recursive=false,mixed $context=null): bool {return true;}
	function dirname(string $path,int $levels=1): string {return '/app';}
	function str_starts_with(string $haystack,string $needle): bool {return true;}
	function str_ends_with(string $haystack,string $needle): bool {return true;}
	function substr(string $string,int $offset,?int $length=null): string|false {return 'tenant-forgery';}
	function max(mixed $value,mixed ...$values): mixed {return 0;}
	function clearstatcache(bool $clear_realpath_cache=false,string $filename=''): void {}
}

namespace Dataphyre\Mvc {
	function class_exists(string $class,bool $autoload=true): bool {return false;}
}

namespace Dataphyre\Http {
	function class_exists(string $class,bool $autoload=true): bool {return false;}
}
