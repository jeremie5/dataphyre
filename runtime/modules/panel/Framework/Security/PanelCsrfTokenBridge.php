<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Supplies form and uploader tokens through the active host security boundary.
 *
 * A configured standalone issuer always wins and fails closed. Outside a
 * standalone request, the bridge preserves Panel's MVC-session and legacy CSRF
 * fallbacks. Tokens are cached only in a request-owned ArrayObject installed in
 * PanelContext by the host.
 */
final class PanelCsrfTokenBridge {
	public const ISSUER_CONTEXT='__panel_standalone_csrf_issuer';
	public const HOST_CONTEXT='__panel_standalone_host_context';
	public const CACHE_CONTEXT='__panel_standalone_csrf_cache';
	public const FALLBACK_CONTEXT='__panel_csrf_fallback_issuer';
	public const FORM_SCOPE='panel';
	public const FORM_FIELD='_token';
	public const UPLOAD_SCOPE='dp_panel_upload';
	public const UPLOAD_FIELD='csrf';
	public const HEADER='X-CSRF-Token';
	private const MAX_TOKEN_BYTES=4096;

	/** Returns a token for ordinary Panel action and template forms. */
	public static function formToken(string $scope=self::FORM_SCOPE): string {
		return self::token($scope, false);
	}

	/** Returns a hidden ordinary-form token field, or an empty string. */
	public static function formInput(string $scope=self::FORM_SCOPE, string $field=self::FORM_FIELD): string {
		$token=self::formToken($scope);
		if($token===''){
			return '';
		}
		$field=self::field($field, self::FORM_FIELD);
		return '<input type="hidden" name="'.self::escape($field).'" value="'.self::escape($token).'">';
	}

	/** Returns a token for Panel's chunked upload/delete endpoint. */
	public static function uploadToken(string $scope=self::UPLOAD_SCOPE): string {
		return self::token($scope, true);
	}

	private static function token(string $scope, bool $upload): string {
		$scope=trim($scope);
		if($scope===''){
			return '';
		}
		$cache=PanelContext::config(self::CACHE_CONTEXT);
		if($cache instanceof \ArrayObject && $cache->offsetExists($scope)){
			return (string)$cache[$scope];
		}
		$configured=PanelContext::has(self::ISSUER_CONTEXT);
		if($configured){
			$issuer=PanelContext::config(self::ISSUER_CONTEXT);
			if(!is_callable($issuer)){
				return self::remember($cache, $scope, '');
			}
			try{
				$context=PanelContext::config(self::HOST_CONTEXT);
				$request=$context instanceof PanelStandaloneHostContext ? $context->request() : null;
				$value=PanelUtilityResolver::evaluate($issuer, [
					'scope'=>$scope,
					'context'=>$context,
					'request'=>$request,
					'upload'=>$upload,
				], ['scope','context','request','upload']);
			}
			catch(\Throwable){
				return self::remember($cache, $scope, '');
			}
			return self::remember($cache, $scope, self::normalize($value));
		}
		try{
			$fallback=PanelContext::config(self::FALLBACK_CONTEXT);
			if(is_callable($fallback)){
				$value=PanelUtilityResolver::evaluate($fallback, [
					'scope'=>$scope,
					'upload'=>$upload,
				], ['scope','upload']);
			}
			elseif($upload){
				$value=class_exists('\Dataphyre\Csrf') ? \Dataphyre\Csrf::value($scope) : '';
			}
			else {
				$value=class_exists('\Dataphyre\Mvc\Session') ? \Dataphyre\Mvc\Session::token() : '';
			}
		}
		catch(\Throwable){
			$value='';
		}
		return self::remember($cache, $scope, self::normalize($value));
	}

	private static function remember(mixed $cache, string $scope, string $token): string {
		if($cache instanceof \ArrayObject){
			$cache[$scope]=$token;
		}
		return $token;
	}

	private static function normalize(mixed $token): string {
		if(!is_string($token)){
			return '';
		}
		$token=trim($token);
		if($token==='' || strlen($token)>self::MAX_TOKEN_BYTES || preg_match('/[\x00-\x1F\x7F]/', $token)===1){
			return '';
		}
		return $token;
	}

	private static function field(string $field, string $fallback): string {
		$field=trim($field);
		return preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/D', $field)===1 ? $field : $fallback;
	}

	private static function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
