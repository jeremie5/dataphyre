<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Framework-neutral JSON endpoint adapter with stable, non-diagnostic public failures. */
final class PanelDataSurfaceEndpoint {
	public function __construct(private readonly PanelDataSurfaceRegistry $registry){}

	/** @param PanelSecurityContext|array<string,mixed> $trusted @return array{status:int,headers:array<string,string>,body:array<string,mixed>} */
	public function handle(string|array $payload, string $panel, PanelSecurityContext|array $trusted): array {
		$headers=['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff'];
		try{
			$context=PanelDataSurfaceContext::fromTrusted($panel, $trusted);
			$request=is_string($payload) ? PanelDataSurfaceWindowRequest::fromJson($payload) : PanelDataSurfaceWindowRequest::fromArray($payload);
			$result=$this->registry->execute($request, $context);
			return ['status'=>200,'headers'=>$headers,'body'=>$result->jsonSerialize()];
		}
		catch(PanelDataSurfaceException $exception){
			$correlation=isset($context) ? $context->correlationId() : '';
			return ['status'=>$exception->httpStatus(),'headers'=>$headers,'body'=>[
				'type'=>'panel_data_surface_error','code'=>$exception->publicCode(),'message'=>$exception->getMessage(),
				'correlation_id'=>$correlation!=='' ? $correlation : null,
			]];
		}
		catch(\Throwable){
			$correlation=isset($context) ? $context->correlationId() : '';
			return ['status'=>500,'headers'=>$headers,'body'=>['type'=>'panel_data_surface_error','code'=>'internal_error','message'=>'Panel DataSurface request failed.','correlation_id'=>$correlation!=='' ? $correlation : null]];
		}
	}
}
