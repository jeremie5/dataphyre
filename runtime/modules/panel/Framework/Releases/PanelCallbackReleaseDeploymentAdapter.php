<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Callable bridge for Kubernetes, Nomad, container, package, and host-specific release adapters. */
final class PanelCallbackReleaseDeploymentAdapter implements PanelReleaseDeploymentAdapter {
	private readonly \Closure $executor;

	public function __construct(private readonly string $name,callable $executor) {
		PanelOperationsGuard::name($name,'release deployment adapter');
		$this->executor=\Closure::fromCallable($executor);
	}

	public function execute(string $phase,array $context):array {
		$phase=strtolower(trim($phase));
		if(!in_array($phase,['prepare','activate','verify','rollback'],true)){
			throw new \InvalidArgumentException('Release deployment phase is invalid.');
		}
		$result=($this->executor)($phase,$context);
		if(!is_array($result)||!is_bool($result['ok']??null)){
			throw new \UnexpectedValueException('Release deployment adapters must return a map with a boolean ok value.');
		}
		return$result;
	}

	public function jsonSerialize():array {
		return PanelManifestContract::stamp([
			'type'=>'panel_callback_release_deployment_adapter',
			'version'=>1,
			'name'=>$this->name,
			'phases'=>['prepare','activate','verify','rollback'],
			'idempotency_required'=>true,
			'callback_exposed'=>false,
		]);
	}
}
