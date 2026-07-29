<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use LogicException;

/** Static facade for applications that install one recovery manager per runtime. */
final class Recovery {
	private static ?RecoveryManager $manager=null;

	public static function use(RecoveryManager $manager): void {
		self::$manager=$manager;
	}

	public static function manager(): RecoveryManager {
		if(self::$manager===null) throw new LogicException('Dataphyre Recovery requires an application registry.');
		return self::$manager;
	}

	public static function problem(string $code, RecoveryContext $context, array $overrides=[], array $evidence=[]): Problem {
		return self::manager()->problem($code, $context, $overrides, $evidence);
	}

	public static function reset(): void {
		self::$manager=null;
	}
}
