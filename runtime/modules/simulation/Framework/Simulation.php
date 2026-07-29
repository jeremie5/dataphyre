<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use LogicException;

/** Static facade for applications that prefer framework-managed registration. */
final class Simulation {
	private static ?SimulationRegistry $registry=null;
	private static ?SimulationStateStore $store=null;
	private static ?SimulationRuntimePolicy $policy=null;
	private static ?SimulationRuntime $runtime=null;

	public static function registry(): SimulationRegistry {
		return self::$registry ??=new SimulationRegistry();
	}

	public static function useStore(SimulationStateStore $store): void {
		self::$store=$store;
		self::$runtime=null;
	}

	public static function usePolicy(SimulationRuntimePolicy $policy): void {
		self::$policy=$policy;
		self::$runtime=null;
	}

	public static function register(string $domain, SimulationDomainAdapter $adapter, array $scenarios, ?string $defaultScenario=null): void {
		self::registry()->register($domain, $adapter, $scenarios, $defaultScenario);
		self::$runtime=null;
	}

	public static function runtime(): SimulationRuntime {
		if(self::$store===null) throw new LogicException('Dataphyre Simulation requires an explicit state store.');
		return self::$runtime ??=new SimulationRuntime(self::registry(), self::$store, self::$policy ?? SimulationRuntimePolicy::fromConfig());
	}

	public static function reset(): void {
		self::$registry=null;
		self::$store=null;
		self::$policy=null;
		self::$runtime=null;
	}
}
