<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One-time session bearer credential; JSON omits its token. */
final class PanelAuthenticationSessionCredential implements \JsonSerializable {
	public function __construct(private readonly PanelAuthenticationSession $session,private readonly string $token){}
	public function session():PanelAuthenticationSession{return $this->session;} public function token():string{return $this->token;}
	public function jsonSerialize():array{return ['type'=>'panel_authentication_session_credential','session'=>$this->session->jsonSerialize(),'contains_one_time_material'=>true];}
}
