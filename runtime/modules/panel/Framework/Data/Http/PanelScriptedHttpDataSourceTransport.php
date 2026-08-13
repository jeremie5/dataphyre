<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Deterministic no-network reference transport for conformance and host integration tests. */
final class PanelScriptedHttpDataSourceTransport implements PanelHttpDataSourceTransport, \JsonSerializable {
	/** @var list<PanelHttpDataSourceTransportResponse> */ private array $script=[];
	/** @var list<PanelHttpDataSourceTransportRequest> */ private array $requests=[];

	/** @param list<PanelHttpDataSourceTransportResponse> $script */
	public function __construct(array $script=[]){ foreach($script as $response){ $this->push($response); } }
	public function push(PanelHttpDataSourceTransportResponse $response): self { $this->script[]=$response; return $this; }
	public function send(PanelHttpDataSourceTransportRequest $request): PanelHttpDataSourceTransportResponse {
		$this->requests[]=$request;
		if($request->cancellationRequested()){ throw PanelHttpDataSourceException::cancelled(); }
		$response=array_shift($this->script);
		if(!$response instanceof PanelHttpDataSourceTransportResponse){ throw PanelHttpDataSourceException::transportUnavailable(); }
		return $response;
	}
	/** @return list<PanelHttpDataSourceTransportRequest> */ public function requests(): array { return $this->requests; }
	public function pending(): int { return count($this->script); }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return ['type'=>'panel_scripted_http_data_transport','version'=>1,'network'=>false,'calls'=>count($this->requests),'pending'=>count($this->script),'endpoints_serialized'=>false,'bodies_serialized'=>false]; }
}
