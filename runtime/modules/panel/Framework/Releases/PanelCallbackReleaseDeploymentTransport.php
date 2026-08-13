<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Process-local bridge to an application HTTP, RPC, SDK, or worker transport. */
final class PanelCallbackReleaseDeploymentTransport implements PanelReleaseDeploymentTransport {
	private readonly \Closure $dispatcher;
	public function __construct(private readonly string $name,callable $dispatcher){PanelOperationsGuard::name($name,'release deployment transport');$this->dispatcher=\Closure::fromCallable($dispatcher);}
	public function dispatch(array $request):array{$result=($this->dispatcher)($request);if(!is_array($result)){throw new \UnexpectedValueException('Release deployment transports must return a receipt map.');}return$result;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_callback_release_deployment_transport','version'=>1,'name'=>$this->name,'callback_exposed'=>false,'credentials_exposed'=>false]);}
}
