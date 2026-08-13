<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

if(!class_exists(routing::class, false)){
	/** Minimal route binding seam used only by the built-in-server showroom. */
	class routing {
		/** @var array<string,string> */
		public static array $bindings=[];
	}
}
