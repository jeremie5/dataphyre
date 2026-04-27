<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/**
 * Flightdeck runtime toolbar.
 *
 * Loaded only when the signed toolbar cookie is present or from the
 * Flightdeck control panel toggle.
 */

if(class_exists('dataphyre_flightdeck_debugbar', false)){
	return;
}

$flightdeck_auth_file=__DIR__.'/auth.php';
if(is_file($flightdeck_auth_file)){
	require_once($flightdeck_auth_file);
}

final class dataphyre_flightdeck_debugbar {

	private const COOKIE='dataphyre_flightdeck_debugbar';

	public static function enabled(): bool {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return false;
		}
		return dataphyre_flightdeck_auth::debugbar_allowed()===true
			&& self::verify_cookie((string)($_COOKIE[self::COOKIE] ?? ''))===true;
	}

	public static function enable(): void {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return;
		}
		if(dataphyre_flightdeck_auth::debugbar_allowed()!==true){
			return;
		}
		$payload=[
			'iat'=>time(),
			'exp'=>time() + 43200,
			'n'=>bin2hex(random_bytes(10)),
		];
		$data=self::base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
		$signature=hash_hmac('sha256', $data, self::secret());
		self::set_cookie($data.'.'.$signature, time() + 43200);
	}

	public static function disable(): void {
		setcookie(self::COOKIE, '', [
			'expires'=>time() - 3600,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>true,
			'samesite'=>'Strict',
		]);
		unset($_COOKIE[self::COOKIE]);
	}

	public static function inject(string $buffer): string {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return $buffer;
		}
		if(self::enabled()!==true){
			return $buffer;
		}
		if(stripos($buffer, '</body>')===false){
			return $buffer.self::markup();
		}
		return preg_replace('/<\/body>/i', self::markup().'</body>', $buffer, 1) ?? $buffer;
	}

	public static function state(): array {
		$started=defined('REQUEST_TIME_FLOAT') ? (float)REQUEST_TIME_FLOAT : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
		$duration=max(0, (microtime(true) - $started) * 1000);
		$included=get_included_files();
		$modules=[];
		foreach($included as $file){
			$normalized=str_replace('\\', '/', $file);
			if(preg_match('#/modules/([^/]+)/#', $normalized, $match)){
				$modules[$match[1]]=true;
			}
		}
		ksort($modules);
		return [
			'available'=>true,
			'enabled'=>self::enabled(),
			'request_id'=>defined('RQID') ? (string)RQID : '',
			'app'=>defined('APP') ? (string)APP : '',
			'method'=>$_SERVER['REQUEST_METHOD'] ?? 'GET',
			'uri'=>$_SERVER['REQUEST_URI'] ?? '',
			'duration_ms'=>round($duration, 3),
			'memory_mb'=>round(memory_get_usage(true) / 1048576, 3),
			'peak_mb'=>round(memory_get_peak_usage(true) / 1048576, 3),
			'files'=>count($included),
			'modules'=>array_keys($modules),
			'run_mode'=>defined('RUN_MODE') ? (string)RUN_MODE : '',
			'production'=>defined('IS_PRODUCTION') && IS_PRODUCTION===true,
		];
	}

	private static function markup(): string {
		$state=self::state();
		$modules=implode(', ', array_slice($state['modules'], 0, 12));
		if(count($state['modules'])>12){
			$modules.=' +'.(count($state['modules']) - 12);
		}
		$open_url='/dataphyre';
		$disable_url='/dataphyre/debugbar?action=disable';
		return '<style>
		#dataphyre-flightdeck-debugbar{position:fixed;left:18px;right:18px;bottom:16px;z-index:2147483000;background:#07111f;color:#e6f0ff;border:1px solid rgba(144,205,244,.35);box-shadow:0 18px 60px rgba(0,0,0,.35);border-radius:18px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;overflow:hidden}
		#dataphyre-flightdeck-debugbar a{color:#8bd3ff;text-decoration:none}
		#dataphyre-flightdeck-debugbar .dfd-row{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:12px 16px}
		#dataphyre-flightdeck-debugbar .dfd-brand{font-weight:800;letter-spacing:.04em;color:#fff}
		#dataphyre-flightdeck-debugbar .dfd-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);font-size:12px}
		#dataphyre-flightdeck-debugbar .dfd-actions{display:flex;gap:10px;font-size:12px}
		@media(max-width:760px){#dataphyre-flightdeck-debugbar{left:8px;right:8px;bottom:8px}.dfd-modules{display:none}}
		</style><aside id="dataphyre-flightdeck-debugbar"><div class="dfd-row"><div><span class="dfd-brand">Dataphyre Flightdeck</span> <span class="dfd-pill">'.htmlspecialchars((string)$state['duration_ms']).'ms</span> <span class="dfd-pill">'.htmlspecialchars((string)$state['memory_mb']).'mb</span> <span class="dfd-pill">'.htmlspecialchars((string)$state['files']).' files</span> <span class="dfd-pill">'.htmlspecialchars((string)$state['method']).' '.htmlspecialchars((string)$state['uri']).'</span></div><div class="dfd-actions"><a href="'.$open_url.'">Open</a><a href="'.$disable_url.'">Disable</a></div><div class="dfd-modules" style="flex-basis:100%;font-size:12px;color:#aec7e8">Modules: '.htmlspecialchars($modules ?: 'none').'</div></div></aside>';
	}

	private static function verify_cookie(string $token): bool {
		if($token===''){
			return false;
		}
		$parts=explode('.', $token, 2);
		if(count($parts)!==2){
			return false;
		}
		[$data, $signature]=$parts;
		$expected=hash_hmac('sha256', $data, self::secret());
		if(hash_equals($expected, $signature)!==true){
			return false;
		}
		$payload=json_decode(self::base64url_decode($data), true);
		return is_array($payload) && (int)($payload['exp'] ?? 0)>=time();
	}

	private static function secret(): string {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return hash('sha256', 'flightdeck-debugbar|missing-auth');
		}
		$config=dataphyre_flightdeck_auth::config();
		$material=[
			$config['password_hash'] ?? '',
			$config['developer_password_hash'] ?? '',
			$config['password'] ?? '',
			$config['developer_password'] ?? '',
			defined('APP') ? APP : '',
			defined('DATAPHYRE_PROJECT_ROOT') ? DATAPHYRE_PROJECT_ROOT : '',
		];
		return hash('sha256', 'flightdeck-debugbar|'.implode('|', array_map('strval', $material)));
	}

	private static function set_cookie(string $value, int $expires): void {
		setcookie(self::COOKIE, $value, [
			'expires'=>$expires,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>true,
			'samesite'=>'Strict',
		]);
		$_COOKIE[self::COOKIE]=$value;
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
