<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/**
 * Bootstrap-safe Flightdeck authentication.
 *
 * This file intentionally has no Dataphyre core dependency. It may be loaded
 * from /dataphyre routes after core is available, or from pre_init_error before
 * a full runtime exists.
 */

if(class_exists('dataphyre_flightdeck_auth', false)){
	return;
}

final class dataphyre_flightdeck_auth {

	private const COOKIE='dataphyre_flightdeck';

	public static function production_disabled(): bool {
		return defined('IS_PRODUCTION') && IS_PRODUCTION===true;
	}

	public static function config(): array {
		$bootstrap=defined('DATAPHYRE_BOOTSTRAP_CONFIG') ? DATAPHYRE_BOOTSTRAP_CONFIG : ($GLOBALS['dataphyre_bootstrap_config'] ?? []);
		if(!is_array($bootstrap)){
			$bootstrap=[];
		}
		$config=$bootstrap['flightdeck'] ?? [];
		if(!is_array($config)){
			$config=[];
		}
		return array_replace_recursive([
			'enabled'=>true,
			'password'=>null,
			'password_hash'=>null,
			'developer_password'=>null,
			'developer_password_hash'=>null,
			'session_ttl'=>43200,
			'rate_limit'=>[
				'window'=>300,
				'max_attempts'=>5,
			],
			'debugbar'=>[
				'enabled'=>true,
			],
		], $config);
	}

	public static function enabled(): bool {
		$config=self::config();
		return self::production_disabled()===false && ($config['enabled'] ?? true)!==false;
	}

	public static function auth_required(): bool {
		if(self::production_disabled()===true){
			return true;
		}
		return self::password_secret()!=='';
	}

	public static function authenticated(): bool {
		if(self::enabled()!==true){
			return false;
		}
		if(self::auth_required()===false){
			return false;
		}
		$token=(string)($_COOKIE[self::COOKIE] ?? '');
		if($token===''){
			return false;
		}
		return self::verify_token($token);
	}

	public static function login(string $password): bool {
		if(self::enabled()!==true || self::auth_required()!==true){
			return false;
		}
		if(self::rate_limited()){
			return false;
		}
		if(self::verify_password($password)!==true){
			self::record_failed_attempt();
			return false;
		}
		self::clear_failed_attempts();
		self::set_session_cookie();
		return true;
	}

	public static function logout(): void {
		self::expire_cookie(self::COOKIE);
	}

	public static function login_error(): ?string {
		if(self::auth_required()===false){
			return 'Flightdeck console password is not configured.';
		}
		if(self::rate_limited()){
			return 'Too many failed attempts. Wait before trying again.';
		}
		return null;
	}

	public static function login_url(?string $return_to=null): string {
		$return_to=$return_to ?? self::current_uri();
		return '/dataphyre/login?'.http_build_query(['return'=>$return_to]);
	}

	public static function redirect_to_login(): never {
		header('Location: '.self::login_url());
		exit;
	}

	public static function debugbar_allowed(): bool {
		$config=self::config();
		$debugbar=$config['debugbar'] ?? [];
		if(!is_array($debugbar)){
			$debugbar=[];
		}
		return self::enabled()===true
			&& self::authenticated()===true
			&& (($debugbar['enabled'] ?? true)!==false);
	}

	public static function csrf_token(): string {
		$seed=self::cookie_secret().'|'.self::client_ip().'|'.date('YmdH');
		return hash_hmac('sha256', 'csrf', $seed);
	}

	public static function verify_csrf(?string $token): bool {
		if(!is_string($token) || $token===''){
			return false;
		}
		return hash_equals(self::csrf_token(), $token);
	}

	private static function verify_password(string $password): bool {
		$config=self::config();
		$hashes=array_filter([
			$config['password_hash'] ?? null,
			$config['developer_password_hash'] ?? null,
		], static fn($value)=>is_string($value) && trim($value)!=='');
		foreach($hashes as $hash){
			if(password_verify($password, $hash)){
				return true;
			}
		}
		$passwords=array_filter([
			$config['password'] ?? null,
			$config['developer_password'] ?? null,
		], static fn($value)=>is_string($value) && $value!=='');
		foreach($passwords as $configured_password){
			if(hash_equals((string)$configured_password, $password)){
				return true;
			}
		}
		return false;
	}

	private static function password_secret(): string {
		$config=self::config();
		foreach(['password_hash', 'developer_password_hash', 'password', 'developer_password'] as $key){
			$value=$config[$key] ?? null;
			if(is_string($value) && trim($value)!==''){
				return trim($value);
			}
		}
		return '';
	}

	private static function cookie_secret(): string {
		$material=[
			self::password_secret(),
			defined('LICENSE') && is_array(LICENSE) ? (LICENSE['key'] ?? '') : '',
			defined('APP') ? APP : '',
			self::project_root(),
		];
		return hash('sha256', implode('|', array_map('strval', $material)));
	}

	private static function set_session_cookie(): void {
		$ttl=max(300, (int)(self::config()['session_ttl'] ?? 43200));
		$payload=[
			'iat'=>time(),
			'exp'=>time() + $ttl,
			'ua'=>hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
			'n'=>bin2hex(random_bytes(12)),
		];
		$data=self::base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
		$signature=hash_hmac('sha256', $data, self::cookie_secret());
		self::set_cookie(self::COOKIE, $data.'.'.$signature, time() + $ttl, true);
	}

	private static function verify_token(string $token): bool {
		$parts=explode('.', $token, 2);
		if(count($parts)!==2){
			return false;
		}
		[$data, $signature]=$parts;
		$valid_signature=false;
		foreach(self::cookie_secrets() as $secret){
			$expected=hash_hmac('sha256', $data, $secret);
			if(hash_equals($expected, $signature)===true){
				$valid_signature=true;
				break;
			}
		}
		if($valid_signature!==true){
			return false;
		}
		$json=self::base64url_decode($data);
		$payload=json_decode($json, true);
		if(!is_array($payload)){
			return false;
		}
		if((int)($payload['exp'] ?? 0)<time()){
			return false;
		}
		$user_agent_hash=hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
		return hash_equals((string)($payload['ua'] ?? ''), $user_agent_hash);
	}

	private static function rate_limited(): bool {
		$state=self::rate_limit_state();
		return ($state['attempts'] ?? 0) >= self::rate_limit_max_attempts()
			&& ($state['until'] ?? 0)>time();
	}

	private static function record_failed_attempt(): void {
		$state=self::rate_limit_state();
		$now=time();
		$until=(int)($state['until'] ?? 0);
		if($until<=$now){
			$state=['attempts'=>0, 'until'=>$now + self::rate_limit_window()];
		}
		$state['attempts']=(int)($state['attempts'] ?? 0) + 1;
		$state['until']=(int)($state['until'] ?? ($now + self::rate_limit_window()));
		self::write_rate_limit_state($state);
	}

	private static function clear_failed_attempts(): void {
		$file=self::rate_limit_file();
		if($file!==null && is_file($file)){
			@unlink($file);
		}
	}

	private static function rate_limit_state(): array {
		$file=self::rate_limit_file();
		if($file===null || !is_file($file)){
			return ['attempts'=>0, 'until'=>0];
		}
		$state=json_decode((string)@file_get_contents($file), true);
		if(!is_array($state)){
			return ['attempts'=>0, 'until'=>0];
		}
		if((int)($state['until'] ?? 0)<=time()){
			return ['attempts'=>0, 'until'=>0];
		}
		return $state;
	}

	private static function write_rate_limit_state(array $state): void {
		$file=self::rate_limit_file();
		if($file===null){
			return;
		}
		$directory=dirname($file);
		if(!is_dir($directory)){
			@mkdir($directory, 0777, true);
		}
		@file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
	}

	private static function rate_limit_file(): ?string {
		$directory=self::cache_directory();
		if($directory===null){
			return null;
		}
		return $directory.'login_'.hash('sha256', self::client_ip()).'.json';
	}

	private static function cache_directory(): ?string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/flightdeck/';
		}
		$install_root=dirname(__DIR__, 4);
		if($install_root!=='' && is_dir($install_root)){
			return rtrim($install_root, '/\\').'/cache/flightdeck/';
		}
		return null;
	}

	private static function rate_limit_window(): int {
		$config=self::config();
		$rate_limit=$config['rate_limit'] ?? [];
		return max(30, (int)(is_array($rate_limit) ? ($rate_limit['window'] ?? 300) : 300));
	}

	private static function rate_limit_max_attempts(): int {
		$config=self::config();
		$rate_limit=$config['rate_limit'] ?? [];
		return max(1, (int)(is_array($rate_limit) ? ($rate_limit['max_attempts'] ?? 5) : 5));
	}

	public static function current_uri(): string {
		return (string)($_SERVER['REQUEST_URI'] ?? '/dataphyre');
	}

	private static function client_ip(): string {
		return (string)($_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0');
	}

	private static function project_root(): string {
		if(defined('DATAPHYRE_PROJECT_ROOT')){
			return (string)DATAPHYRE_PROJECT_ROOT;
		}
		return dirname(__DIR__, 5);
	}

	private static function cookie_secrets(): array {
		$secrets=[self::cookie_secret()];
		if(defined('ROOTPATH') && defined('DATAPHYRE_PROJECT_ROOT') && !empty(ROOTPATH['root']) && (string)ROOTPATH['root']!==(string)DATAPHYRE_PROJECT_ROOT){
			$material=[
				self::password_secret(),
				defined('LICENSE') && is_array(LICENSE) ? (LICENSE['key'] ?? '') : '',
				defined('APP') ? APP : '',
				(string)ROOTPATH['root'],
			];
			$secrets[]=hash('sha256', implode('|', array_map('strval', $material)));
		}
		return array_values(array_unique($secrets));
	}

	private static function set_cookie(string $name, string $value, int $expires, bool $http_only): void {
		setcookie($name, $value, [
			'expires'=>$expires,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>$http_only,
			'samesite'=>'Strict',
		]);
		$_COOKIE[$name]=$value;
	}

	private static function expire_cookie(string $name): void {
		setcookie($name, '', [
			'expires'=>time() - 3600,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>true,
			'samesite'=>'Strict',
		]);
		unset($_COOKIE[$name]);
	}

	private static function secure_cookie(): bool {
		return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off')
			|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https');
	}

	private static function base64url_encode(string|false $value): string {
		return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
	}

	private static function base64url_decode(string $value): string {
		$padding=strlen($value) % 4;
		if($padding>0){
			$value.=str_repeat('=', 4 - $padding);
		}
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		return is_string($decoded) ? $decoded : '';
	}
}
