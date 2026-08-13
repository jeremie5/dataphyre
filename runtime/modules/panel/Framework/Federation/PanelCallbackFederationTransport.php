<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Callable bridge for HTTP, queue, service-mesh, and in-process federation transports. */
final class PanelCallbackFederationTransport implements PanelFederationTransport {
	private readonly \Closure $transport;
	public function __construct(private readonly string $name,callable $transport){PanelOperationsGuard::name($name,'federation transport');$this->transport=\Closure::fromCallable($transport);}
	public function deliver(array $wire):array {$result=($this->transport)($wire);if(!is_array($result)){throw new \UnexpectedValueException('Federation transports must return a signed wire acknowledgement.');}return$result;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_callback_federation_transport','version'=>1,'name'=>$this->name,'request_response'=>true,'payload_encryption_required'=>true,'callback_exposed'=>false]);}
}
