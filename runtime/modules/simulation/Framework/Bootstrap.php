<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Simulation;

$kernelEntry=dirname(__DIR__).'/kernel/simulation.main.php';
if(is_file($kernelEntry)) require_once $kernelEntry;

foreach([
	'SimulationScope.php',
	'SimulationPerspective.php',
	'SimulationRandom.php',
	'SimulationIntent.php',
	'SimulationOutcome.php',
	'SimulationContext.php',
	'SimulationDomainAdapter.php',
	'SimulationStateStore.php',
	'InMemorySimulationStateStore.php',
	'SimulationRule.php',
	'SimulationScenario.php',
	'SimulationRegistry.php',
	'SimulationSession.php',
	'SimulationRuntimePolicy.php',
	'SimulationTickResult.php',
	'SimulationRuntime.php',
	'Simulation.php',
] as $frameworkFile){
	$path=__DIR__.'/'.$frameworkFile;
	if(is_file($path)) require_once $path;
}
