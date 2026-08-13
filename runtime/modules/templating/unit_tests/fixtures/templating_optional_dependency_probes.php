<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database {
	if(!class_exists(RepositoryQuery::class,false)){
		class RepositoryQuery {}
	}
}

namespace Dataphyre\FulltextEngine {
	if(!class_exists(Query::class,false)){
		class Query {}
	}
}

namespace dataphyre\async {
	if(!class_exists(promise::class,false)){
		class promise {
			public mixed $value=null;
			public mixed $reason=null;
			public function __construct(callable $executor) {
				$executor(
					function(mixed $value): void { $this->value=$value; },
					function(mixed $reason): void { $this->reason=$reason; },
				);
			}
		}
	}
}
