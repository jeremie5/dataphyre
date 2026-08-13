<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel\Bridges\Reactor;

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelWidgetInteractionException;
use Dataphyre\Panel\PanelWidgetInteractionRequest;
use Dataphyre\Panel\PanelWidgetInteractionResult;
use Dataphyre\Panel\PanelWidgetInteractionValue;
use Dataphyre\Panel\PanelWidgetRuntimeHttpAdapter;

/** Same-origin exact-JSON controller for Panel widget lifecycle requests. */
final class PanelReactorWidgetController {
	private const REQUEST_HEADER='DataphyrePanelWidget';
	private const ABILITY='panel.widget.interact';
	private const MAX_ACCEPT_BYTES=2048;
	private const MAX_CSRF_TOKEN_BYTES=4096;
	private ?\Closure $originValidator;
	private ?\Closure $csrfValidator;

	/**
	 * The origin validator receives (string $origin, PanelRequest $request).
	 * The CSRF validator receives (PanelRequest $request, string $headerToken,
	 * string $ability). Missing validators and thrown failures deny the request.
	 */
	public function __construct(
		private readonly PanelInstance $panel,
		?callable $originValidator,
		?callable $csrfValidator,
		private readonly int $maxBodyBytes=65536
	){
		if($maxBodyBytes<1024 || $maxBodyBytes>1048576){ throw new \InvalidArgumentException('Panel widget JSON body limits must be between 1 KiB and 1 MiB.'); }
		$this->originValidator=$originValidator===null ? null : \Closure::fromCallable($originValidator);
		$this->csrfValidator=$csrfValidator===null ? null : \Closure::fromCallable($csrfValidator);
	}

	/**
	 * Dispatches after the host has converted its HTTP request to PanelRequest and
	 * supplied the exact bounded raw body. Route identifiers are untrusted; adapter
	 * and binding resolution happens only after every transport guard passes.
	 */
	public function dispatch(PanelRequest $hostRequest, string $rawBody, string $adapterAlias, string $bindingKey, string $surface): PanelPageResult {
		if($hostRequest->method()!=='POST'){ return $this->transportError('widget_method_not_allowed', 'Widget interactions require POST.', 405, false, ['Allow'=>'POST']); }
		if(!self::jsonContentType((string)$hostRequest->header('content-type', ''))){ return $this->transportError('widget_content_type_invalid', 'Widget interactions require application/json.', 415); }
		if(!self::acceptsJson((string)$hostRequest->header('accept', ''))){ return $this->transportError('widget_accept_invalid', 'Widget interactions require an application/json response.', 406); }
		if(!hash_equals(self::REQUEST_HEADER, trim((string)$hostRequest->header('x-requested-with', '')))){ return $this->transportError('widget_transport_header_invalid', 'The widget transport header is missing or invalid.', 403); }
		if(!in_array(strtolower(trim((string)$hostRequest->header('content-encoding', ''))), ['', 'identity'], true)){ return $this->transportError('widget_content_encoding_invalid', 'Encoded widget request bodies are not accepted.', 415); }
		if($hostRequest->query()!==[] || $hostRequest->files()!==[]){ return $this->transportError('widget_transport_pollution', 'Widget interactions accept JSON body data only.', 400); }

		$origin=trim((string)$hostRequest->header('origin', ''));
		if(!self::validOrigin($origin)){ return $this->transportError('widget_origin_invalid', 'The widget request origin is missing or invalid.', 403); }
		if(!$this->originValidator instanceof \Closure){ return $this->transportError('widget_origin_validation_unavailable', 'Widget origin validation is unavailable.', 503, true); }
		try{ $originValid=($this->originValidator)($origin, $hostRequest)===true; }
		catch(\Throwable){ return $this->transportError('widget_origin_validation_unavailable', 'Widget origin validation is unavailable.', 503, true); }
		if(!$originValid){ return $this->transportError('widget_origin_forbidden', 'The widget request origin is not allowed.', 403); }

		if(!$this->csrfValidator instanceof \Closure){ return $this->transportError('widget_csrf_validation_unavailable', 'Widget request verification is unavailable.', 503, true); }
		$csrfHeader=(string)$hostRequest->header('x-csrf-token', '');
		if($csrfHeader==='' || strlen($csrfHeader)>self::MAX_CSRF_TOKEN_BYTES || str_contains($csrfHeader, "\r") || str_contains($csrfHeader, "\n")){
			return $this->transportError('widget_csrf_invalid', 'The widget security token is missing or invalid.', 419);
		}
		$csrfToken=trim($csrfHeader);
		if($csrfToken===''){ return $this->transportError('widget_csrf_invalid', 'The widget security token is missing or invalid.', 419); }
		try{ $csrfValid=($this->csrfValidator)($hostRequest, $csrfToken, self::ABILITY)===true; }
		catch(\Throwable){ return $this->transportError('widget_csrf_validation_unavailable', 'Widget request verification is unavailable.', 503, true); }
		if(!$csrfValid){ return $this->transportError('widget_csrf_invalid', 'The widget security token is missing or invalid.', 419); }

		$length=strlen($rawBody);
		$declared=trim((string)$hostRequest->header('content-length', ''));
		if($declared!=='' && (preg_match('/^(?:0|[1-9][0-9]*)$/D', $declared)!==1 || (int)$declared!==$length)){
			return $this->transportError('widget_content_length_invalid', 'The widget request length is invalid.', 400);
		}
		if($length===0){ return $this->transportError('widget_body_empty', 'The widget JSON body is required.', 400); }
		if($length>$this->maxBodyBytes){ return $this->transportError('widget_body_too_large', 'The widget request exceeds the JSON body limit.', 413); }
		try{ $decoded=json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
		catch(\Throwable){ return $this->transportError('widget_json_invalid', 'The widget request body is not valid JSON.', 400); }
		if(!is_array($decoded) || array_is_list($decoded)){ return $this->transportError('widget_json_object_required', 'The widget request body must be a JSON object.', 400); }
		if(($decoded['schema_version'] ?? null)!==PanelWidgetInteractionRequest::SCHEMA_VERSION){ return $this->transportError('widget_request_invalid', 'The widget interaction request is invalid.', 422); }
		try{ $interaction=PanelWidgetInteractionRequest::fromArray($decoded); }
		catch(\Throwable){ return $this->transportError('widget_request_invalid', 'The widget interaction request is invalid.', 422); }

		try{
			$adapterAlias=PanelWidgetInteractionValue::safeIdentifier($adapterAlias, 'Widget runtime adapter route', 64);
			$bindingKey=PanelWidgetInteractionValue::safeIdentifier($bindingKey, 'Widget runtime binding route', 96);
			$surface=PanelWidgetInteractionValue::safeIdentifier($surface, 'Widget runtime surface route', 128);
		}
		catch(\Throwable){ return $this->transportError('widget_route_unavailable', 'The widget runtime route is unavailable.', 404); }

		$adapter=$this->panel->widgetRuntime()->adapter($adapterAlias);
		if(!$adapter instanceof PanelWidgetRuntimeHttpAdapter){ return $this->transportError('widget_route_unavailable', 'The widget runtime route is unavailable.', 404); }
		try{ $adapterName=PanelWidgetInteractionValue::safeIdentifier($adapter->name(), 'Widget runtime adapter identity', 64); }
		catch(\Throwable){ return $this->transportError('widget_route_resolution_unavailable', 'The widget runtime route cannot be resolved.', 503, true); }
		if($adapterName!==$adapterAlias){ return $this->transportError('widget_route_unavailable', 'The widget runtime route is unavailable.', 404); }
		try{ $definition=$adapter->definitionForHttpRoute($bindingKey, $surface); }
		catch(\Throwable){ return $this->transportError('widget_route_resolution_unavailable', 'The widget runtime route cannot be resolved.', 503, true); }
		if($definition!==null && $definition->adapter()!==$adapterAlias){ return $this->transportError('widget_route_resolution_unavailable', 'The widget runtime route cannot be resolved.', 503, true); }
		if($definition===null){
			return $this->interactionFailure($adapterName, $interaction, 'widget_binding_unavailable', 'This widget interaction is unavailable.', 404);
		}
		try{ $context=$this->panel->widgetRuntime()->context($this->panel, $hostRequest, $surface); }
		catch(PanelWidgetInteractionException $failure){ return $this->result(PanelWidgetInteractionResult::failure($adapterName, $interaction->islandId(), $failure)); }
		catch(\Throwable){ return $this->interactionFailure($adapterName, $interaction, 'widget_context_unavailable', 'Interactive updates are unavailable.', 503, true); }
		return $this->result($this->panel->widgetRuntime()->dispatch($definition, $context, $interaction));
	}

	public function manifest(): array {
		return [
			'type'=>'panel_reactor_widget_controller',
			'contract_version'=>1,
			'method'=>'POST',
			'content_type'=>['application/json','application/json; charset=utf-8'],
			'accept'=>'standards_compatible_application_json_media_ranges; omitted_accept_means_any',
			'custom_header'=>['name'=>'X-Requested-With','exact_value'=>self::REQUEST_HEADER],
			'same_origin'=>'required_injected_exact_origin_validator',
			'csrf'=>'required_injected_fail_closed_validator',
			'csrf_header'=>'X-CSRF-Token',
			'max_csrf_token_bytes'=>self::MAX_CSRF_TOKEN_BYTES,
			'max_accept_bytes'=>self::MAX_ACCEPT_BYTES,
			'max_body_bytes'=>$this->maxBodyBytes,
			'query_parameters_allowed'=>false,
			'uploads_allowed'=>false,
			'body_selects_adapter_component_class_or_scope'=>false,
			'resolution_order'=>'method_content_type_accept_custom_header_encoding_pollution_origin_csrf_body_bounds_exact_json_then_host_registry',
			'response'=>'stable_exact_json_no_store',
		];
	}

	private function interactionFailure(string $adapter, PanelWidgetInteractionRequest $request, string $code, string $message, int $status, bool $retryable=false): PanelPageResult {
		return $this->result(PanelWidgetInteractionResult::failure($adapter, $request->islandId(), new PanelWidgetInteractionException($code, $message, $status, $retryable)));
	}

	private function result(PanelWidgetInteractionResult $result): PanelPageResult {
		return PanelPageResult::json($result->toArray(), $result->httpStatus(), self::responseHeaders());
	}

	/** @param array<string,string> $headers */
	private function transportError(string $code, string $message, int $status, bool $retryable=false, array $headers=[]): PanelPageResult {
		$payload=[
			'type'=>'panel_widget_runtime_error',
			'schema_version'=>1,
			'code'=>$code,
			'message'=>$message,
			'retryable'=>$retryable,
			'http_status'=>$status,
		];
		return PanelPageResult::json($payload, $status, array_replace(self::responseHeaders(), $headers));
	}

	/** @return array<string,string> */
	private static function responseHeaders(): array {
		return [
			'Cache-Control'=>'no-store, private, max-age=0',
			'Pragma'=>'no-cache',
			'Vary'=>'Origin',
			'X-Content-Type-Options'=>'nosniff',
			'Referrer-Policy'=>'same-origin',
		];
	}

	private static function jsonContentType(string $contentType): bool {
		return preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/iD', trim($contentType))===1;
	}

	/** Whether application/json is acceptable under RFC media-range precedence. */
	private static function acceptsJson(string $accept): bool {
		$accept=trim($accept);
		if($accept===''){ return true; }
		if(strlen($accept)>self::MAX_ACCEPT_BYTES || str_contains($accept, "\r") || str_contains($accept, "\n")){ return false; }
		$ranges=explode(',', $accept);
		if(count($ranges)>32){ return false; }
		$bestSpecificity=-1;
		$bestParameterCount=-1;
		$bestQuality=0.0;
		foreach($ranges as $range){
			$segments=array_map('trim', explode(';', trim($range)));
			$media=strtolower((string)array_shift($segments));
			$specificity=match($media){
				'application/json'=>2,
				'application/*'=>1,
				'*/*'=>0,
				default=>-1,
			};
			if($specificity<0){ continue; }
			$quality=1.0;
			$qualitySeen=false;
			$parameterCount=0;
			$valid=true;
			foreach($segments as $segment){
				if($segment==='' || preg_match('/\A([!#$%&\'*+.^_`|~0-9A-Za-z-]+)\s*=\s*(?:"([^"]*)"|([^\s;]+))\z/D', $segment, $match)!==1){ $valid=false; break; }
				$name=strtolower($match[1]);
				$value=strtolower($match[2]!=='' ? $match[2] : $match[3]);
				if($name==='q'){
					if($qualitySeen || preg_match('/\A(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/D', $value)!==1){ $valid=false; break; }
					$quality=(float)$value;
					$qualitySeen=true;
					continue;
				}
				if($qualitySeen){ continue; }
				if($media!=='application/json' || $name!=='charset' || $value!=='utf-8'){ $valid=false; break; }
				$parameterCount++;
			}
			if(!$valid){ continue; }
			if($specificity>$bestSpecificity || ($specificity===$bestSpecificity && $parameterCount>$bestParameterCount)){
				$bestSpecificity=$specificity;
				$bestParameterCount=$parameterCount;
				$bestQuality=$quality;
			}
		}
		return $bestSpecificity>=0 && $bestQuality>0.0;
	}

	private static function validOrigin(string $origin): bool {
		if($origin==='' || strlen($origin)>512 || str_contains($origin, "\r") || str_contains($origin, "\n")){ return false; }
		$parts=parse_url($origin);
		if(!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || trim((string)($parts['host'] ?? ''))===''){ return false; }
		if(isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])){ return false; }
		return !isset($parts['path']) || $parts['path']==='';
	}
}
