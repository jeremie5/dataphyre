<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One-time trusted-device credential; JSON omits its bearer token. */
final class PanelTrustedDeviceCredential implements \JsonSerializable {
	public function __construct(private readonly PanelTrustedDevice $device,private readonly string $token){}
	public function device():PanelTrustedDevice{return $this->device;} public function token():string{return $this->token;}
	public function jsonSerialize():array{return ['type'=>'panel_trusted_device_credential','device'=>$this->device->jsonSerialize(),'contains_one_time_material'=>true];}
}
