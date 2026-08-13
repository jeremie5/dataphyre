<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Test;

/** Failure seam for the architecture index's fail-closed source-read contract. */
function file_get_contents(string $filename,bool $useIncludePath=false,mixed $context=null,int $offset=0,?int $length=null): string|false {
	if(basename($filename)==='architecture-unreadable.php'){
		return false;
	}
	if($length!==null){
		return \file_get_contents($filename,$useIncludePath,$context,$offset,$length);
	}
	return \file_get_contents($filename,$useIncludePath,$context,$offset);
}
