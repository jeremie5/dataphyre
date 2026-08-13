<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Request-level decision for a return target and its signed intent. */
final class PanelNavigationResolution implements \JsonSerializable {
	public function __construct(
		private readonly ?string $target,
		private readonly PanelNavigationIntentVerification $verification,
		private readonly bool $blocked=false
	){}
	public function target(): ?string { return $this->target; }
	public function verification(): PanelNavigationIntentVerification { return $this->verification; }
	public function accepted(): bool { return $this->target!==null && $this->verification->valid(); }
	public function blocked(): bool { return $this->blocked; }
	public function jsonSerialize(): array {
		return ['type'=>'panel_navigation_resolution','target'=>$this->target,'accepted'=>$this->accepted(),'blocked'=>$this->blocked,'verification'=>$this->verification];
	}
}
