<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database;

final class ApplicationEnvironmentIdentifier {
	public static function valid(string $value): bool {return false;}
}

final class InternalApplicationBootstrapOnly {}

class InvalidArgumentException extends \InvalidArgumentException {}
class RuntimeException extends \RuntimeException {}
interface Throwable {}

function array_is_list(array $value): bool {return false;}
function class_exists(string $class,bool $autoload=true): bool {return true;}
function count(mixed $value,int $mode=COUNT_NORMAL): int {return 999;}
function fwrite(mixed $stream,string $value,?int $length=null): int|false {
	throw new \RuntimeException('Namespaced fwrite override must not run.');
}
function hash(string $algorithm,string $value,bool $binary=false): string {return 'namespaced-hash';}
function hash_equals(string $known,string $user): bool {return false;}
function is_callable(mixed $value,bool $syntaxOnly=false,?string &$callableName=null): bool {return false;}
function is_int(mixed $value): bool {return false;}
function json_encode(mixed $value,int $flags=0,int $depth=512): string|false {return '{"namespaced":true}';}
function ksort(array &$array,int $flags=SORT_REGULAR): bool {return false;}
function method_exists(object|string $objectOrClass,string $method): bool {return false;}
function preg_match(string $pattern,string $subject,?array &$matches=null,int $flags=0,int $offset=0): int|false {return 0;}
function realpath(string $path): string|false {return false;}
function sort(array &$array,int $flags=SORT_REGULAR): true {return true;}
function strlen(string $value): int {return 1;}
