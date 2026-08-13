<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Minimal untrusted transport request. Tenant, principal, query, and range are never accepted here. */
final class PanelDataSurfaceWindowRequest implements \JsonSerializable {
	private function __construct(private readonly string $intent,private readonly ?PanelDataSurfaceInteraction $interaction=null){}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload): self {
		$keys=array_keys($payload);sort($keys,SORT_STRING);if($keys!==['intent']&&$keys!==['intent','interaction']){ throw new PanelDataSurfaceException('request_invalid', 422, 'Panel DataSurface request must contain an intent and an optional bounded interaction.'); }
		if(!is_string($payload['intent'])){ throw new PanelDataSurfaceException('request_invalid', 422, 'Panel DataSurface request intent is invalid.'); }
		try{ $intent=PanelDataSurfaceGuard::boundedString($payload['intent'], 'intent', 16384); }
		catch(\Throwable){ throw new PanelDataSurfaceException('request_invalid', 422, 'Panel DataSurface request intent is invalid.'); }
		$interaction=null;if(array_key_exists('interaction',$payload)){if(!is_array($payload['interaction'])||array_is_list($payload['interaction'])){throw new PanelDataSurfaceException('interaction_invalid',422,'Panel DataSurface interaction is invalid.');}$interaction=PanelDataSurfaceInteraction::fromArray($payload['interaction']);}
		return new self($intent,$interaction);
	}

	public static function fromJson(string $json): self {
		if($json==='' || strlen($json)>20000){ throw new PanelDataSurfaceException('request_invalid', 422, 'Panel DataSurface request body is invalid.'); }
		try{ $payload=json_decode($json, true, 4, JSON_THROW_ON_ERROR); }
		catch(\JsonException){ throw new PanelDataSurfaceException('request_invalid', 422, 'Panel DataSurface request body is invalid JSON.'); }
		if(!is_array($payload) || array_is_list($payload)){ throw new PanelDataSurfaceException('request_invalid', 422, 'Panel DataSurface request body must be an object.'); }
		return self::fromArray($payload);
	}

	public function intent(): string { return $this->intent; }
	public function interaction():?PanelDataSurfaceInteraction{return$this->interaction;}
	public function jsonSerialize(): array {$payload=['intent'=>$this->intent];if($this->interaction!==null){$payload['interaction']=$this->interaction->jsonSerialize();}return$payload;}
}
