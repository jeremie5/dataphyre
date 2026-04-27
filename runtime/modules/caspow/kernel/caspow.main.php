<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");
dp_define_module_config('caspow', 'DP_CASPOW_CFG', [
	'algorithm'=>'sha-256',
	'ttl_seconds'=>180,
	'desktop_base_bits'=>16,
	'mobile_base_bits'=>15,
	'minimum_desktop_bits'=>15,
	'minimum_mobile_bits'=>14,
	'maximum_bits'=>18,
	'chunk_size'=>256,
	'max_duration_ms'=>2500,
	'max_active_challenges'=>8,
	'max_used_challenges'=>32,
	'max_iterations_multiplier'=>16,
]);

class caspow{

	protected static string $algorithm='sha-256';
	protected static int $ttl_seconds=180;
	protected static int $desktop_base_bits=16;
	protected static int $mobile_base_bits=15;
	protected static int $minimum_desktop_bits=15;
	protected static int $minimum_mobile_bits=14;
	protected static int $maximum_bits=18;
	protected static int $chunk_size=256;
	protected static int $max_duration_ms=2500;
	protected static int $max_active_challenges=8;
	protected static int $max_used_challenges=32;
	protected static int $max_iterations_multiplier=16;
	protected static string $version='2';

	public static function create_challenge(?string $scope=null, ?array $capabilities=null) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		self::ensure_store();
		self::gc_store();
		$scope=self::normalize_scope($scope);
		$profile=self::select_profile($capabilities);
		$issued_at=time();
		$expires_at=$issued_at+self::ttl_seconds();
		$challenge_id=bin2hex(random_bytes(12));
		$nonce=bin2hex(random_bytes(16));
		$challenge=[
			'version'=>self::$version,
			'challenge_id'=>$challenge_id,
			'algorithm'=>self::client_algorithm_name(),
			'nonce'=>$nonce,
			'difficulty_bits'=>$profile['difficulty_bits'],
			'issued_at'=>$issued_at,
			'expires_at'=>$expires_at,
			'scope'=>$scope,
			'chunk_size'=>$profile['chunk_size'],
			'max_duration_ms'=>$profile['max_duration_ms'],
			'max_iterations'=>$profile['max_iterations'],
			'profile'=>$profile['profile'],
			'signature'=>'',
		];
		$challenge['signature']=self::sign_challenge($challenge);
		$_SESSION['dp_caspow']['active'][$challenge_id]=[
			'challenge'=>$challenge,
			'binding'=>self::binding_signature(),
			'used'=>false,
			'verified_at'=>null,
		];
		self::enforce_store_limits();
		return $challenge;
	}

	public static function verify_payload(mixed $payload) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		self::ensure_store();
		self::gc_store();
		$payload=self::decode_payload($payload);
		if(!is_array($payload)){
			return false;
		}
		$challenge_id=(string)($payload['challenge_id'] ?? '');
		if($challenge_id==='' || !isset($_SESSION['dp_caspow']['active'][$challenge_id])){
			return false;
		}
		$record=$_SESSION['dp_caspow']['active'][$challenge_id];
		$challenge=$record['challenge'] ?? null;
		if(!is_array($challenge)){
			unset($_SESSION['dp_caspow']['active'][$challenge_id]);
			return false;
		}
		if(!empty($record['used']) || isset($_SESSION['dp_caspow']['used'][$challenge_id])){
			return false;
		}
		if(($challenge['expires_at'] ?? 0)<time()){
			unset($_SESSION['dp_caspow']['active'][$challenge_id]);
			return false;
		}
		if(!hash_equals((string)($record['binding'] ?? ''), self::binding_signature())){
			return false;
		}
		if(!hash_equals((string)($challenge['signature'] ?? ''), (string)($payload['signature'] ?? ''))){
			return false;
		}
		if(hash_equals((string)($challenge['signature'] ?? ''), self::sign_challenge($challenge))!==true){
			return false;
		}
		$expected_scope=(string)($challenge['scope'] ?? '');
		$payload_scope=self::normalize_scope((string)($payload['scope'] ?? $expected_scope));
		if($payload_scope!==$expected_scope){
			return false;
		}
		if((string)($payload['algorithm'] ?? '')!==($challenge['algorithm'] ?? '')){
			return false;
		}
		if((string)($payload['nonce'] ?? '')!==($challenge['nonce'] ?? '')){
			return false;
		}
		$counter=self::normalize_counter($payload['counter'] ?? null);
		if($counter===null){
			return false;
		}
		$digest=self::proof_digest((string)$challenge['nonce'], $challenge_id, $counter);
		if($digest===false){
			return false;
		}
		$provided_digest=strtolower(trim((string)($payload['digest'] ?? '')));
		if($provided_digest!=='' && hash_equals($provided_digest, $digest)!==true){
			return false;
		}
		if(self::leading_zero_bits($digest)<(int)$challenge['difficulty_bits']){
			return false;
		}
		unset($_SESSION['dp_caspow']['active'][$challenge_id]);
		$_SESSION['dp_caspow']['used'][$challenge_id]=[
			'expires_at'=>(int)$challenge['expires_at'],
			'verified_at'=>time(),
		];
		self::enforce_store_limits();
		return true;
	}

	protected static function select_profile(?array $capabilities) : array {
		$capabilities=is_array($capabilities) ? $capabilities : [];
		$hardware_concurrency=max(1, min(32, (int)($capabilities['hardware_concurrency'] ?? 0)));
		$device_memory=max(0.0, min(32.0, (float)($capabilities['device_memory'] ?? 0.0)));
		$save_data=!empty($capabilities['save_data']) || (isset($_SERVER['HTTP_SAVE_DATA']) && strtolower((string)$_SERVER['HTTP_SAVE_DATA'])==='on');
		$reduced_motion=!empty($capabilities['reduced_motion']);
		$is_mobile=access::is_mobile();
		$base_bits=$is_mobile ? self::mobile_base_bits() : self::desktop_base_bits();
		$minimum_bits=$is_mobile ? self::minimum_mobile_bits() : self::minimum_desktop_bits();
		$profile='standard';
		$score=0;
		if($hardware_concurrency>=4){
			$score++;
		}
		if($hardware_concurrency>=8){
			$score++;
		}
		if($device_memory>=4){
			$score++;
		}
		if($device_memory>=8){
			$score++;
		}
		if($score>=3){
			$base_bits++;
			$profile='strong';
		}
		if($score>=4){
			$base_bits++;
		}
		if($save_data){
			$base_bits--;
			$profile='constrained';
		}
		if($reduced_motion){
			$base_bits--;
			if($profile==='standard'){
				$profile='accessible';
			}
		}
		$difficulty_bits=max($minimum_bits, min(self::maximum_bits(), $base_bits));
		$max_duration_ms=self::max_duration_ms();
		if($is_mobile){
			$max_duration_ms=(int)min($max_duration_ms, 2200);
		}
		if($save_data){
			$max_duration_ms=(int)min($max_duration_ms, 1800);
		}
		$chunk_size=self::chunk_size();
		if($hardware_concurrency>=8){
			$chunk_size=max($chunk_size, 384);
		}
		if($save_data || $reduced_motion){
			$chunk_size=min($chunk_size, 192);
		}
		$max_iterations=1 << min($difficulty_bits+self::max_iterations_multiplier(), 24);
		return [
			'profile'=>$profile,
			'difficulty_bits'=>$difficulty_bits,
			'chunk_size'=>$chunk_size,
			'max_duration_ms'=>$max_duration_ms,
			'max_iterations'=>$max_iterations,
		];
	}

	protected static function decode_payload(mixed $payload) : ?array {
		if(is_array($payload)){
			return $payload;
		}
		if(!is_string($payload) || trim($payload)===''){
			return null;
		}
		$decoded=base64_decode($payload, true);
		if($decoded===false){
			return null;
		}
		$json=json_decode($decoded, true);
		return is_array($json) ? $json : null;
	}

	protected static function proof_digest(string $nonce, string $challenge_id, int $counter) : string|false {
		return hash(self::server_algorithm_name(), $challenge_id.':'.$nonce.':'.$counter);
	}

	protected static function sign_challenge(array $challenge) : string {
		$fields=[
			'version'=>(string)($challenge['version'] ?? ''),
			'challenge_id'=>(string)($challenge['challenge_id'] ?? ''),
			'algorithm'=>(string)($challenge['algorithm'] ?? ''),
			'nonce'=>(string)($challenge['nonce'] ?? ''),
			'difficulty_bits'=>(string)($challenge['difficulty_bits'] ?? ''),
			'issued_at'=>(string)($challenge['issued_at'] ?? ''),
			'expires_at'=>(string)($challenge['expires_at'] ?? ''),
			'scope'=>(string)($challenge['scope'] ?? ''),
			'profile'=>(string)($challenge['profile'] ?? ''),
		];
		return hash_hmac(self::server_algorithm_name(), implode('|', $fields), dpvk());
	}

	protected static function normalize_counter(mixed $counter) : ?int {
		if(is_int($counter)){
			return $counter>=0 ? $counter : null;
		}
		if(is_string($counter) && preg_match('/^\d{1,10}$/', $counter)===1){
			$value=(int)$counter;
			return $value>=0 ? $value : null;
		}
		return null;
	}

	protected static function leading_zero_bits(string $hex_digest) : int {
		$bits=0;
		$hex_digest=strtolower(trim($hex_digest));
		$length=strlen($hex_digest);
		for($i=0; $i<$length; $i++){
			$nibble=hexdec($hex_digest[$i]);
			if($nibble===0){
				$bits+=4;
				continue;
			}
			if(($nibble & 0b1000)===0){
				$bits++;
			}
			else
			{
				return $bits;
			}
			if(($nibble & 0b0100)===0){
				$bits++;
			}
			else
			{
				return $bits;
			}
			if(($nibble & 0b0010)===0){
				$bits++;
			}
			else
			{
				return $bits;
			}
			if(($nibble & 0b0001)===0){
				$bits++;
			}
			return $bits;
		}
		return $bits;
	}

	protected static function normalize_scope(?string $scope) : string {
		$scope=trim((string)$scope);
		if($scope===''){
			$scope='default';
		}
		return substr(preg_replace('/[^a-zA-Z0-9:_\-\.\/]/', '_', $scope), 0, 120);
	}

	protected static function binding_signature() : string {
		$session_id=session_id();
		$ip=REQUEST_IP_ADDRESS ?? ($_SERVER['REMOTE_ADDR'] ?? '');
		$ip_subnet=self::ip_subnet((string)$ip);
		$user_agent=(string)($_SERVER['HTTP_USER_AGENT'] ?? '');
		return hash_hmac(self::server_algorithm_name(), $session_id.'|'.$ip_subnet.'|'.$user_agent, dpvk());
	}

	protected static function ip_subnet(string $ip) : string {
		$ip=trim($ip);
		if($ip==='' || filter_var($ip, FILTER_VALIDATE_IP)===false){
			return '';
		}
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
			$segments=explode(':', $ip);
			return implode(':', array_slice($segments, 0, 4));
		}
		$segments=explode('.', $ip);
		return count($segments)===4 ? implode('.', array_slice($segments, 0, 3)) : $ip;
	}

	protected static function ensure_store() : void {
		if(session_status()!==PHP_SESSION_ACTIVE){
			@session_start();
		}
		$_SESSION['dp_caspow']??=[
			'active'=>[],
			'used'=>[],
		];
		$_SESSION['dp_caspow']['active']??=[];
		$_SESSION['dp_caspow']['used']??=[];
	}

	protected static function gc_store() : void {
		$now=time();
		foreach($_SESSION['dp_caspow']['active'] as $challenge_id=>$record){
			$expires_at=(int)($record['challenge']['expires_at'] ?? 0);
			if($expires_at<$now){
				unset($_SESSION['dp_caspow']['active'][$challenge_id]);
			}
		}
		foreach($_SESSION['dp_caspow']['used'] as $challenge_id=>$record){
			$expires_at=(int)($record['expires_at'] ?? 0);
			if($expires_at<($now-60)){
				unset($_SESSION['dp_caspow']['used'][$challenge_id]);
			}
		}
	}

	protected static function enforce_store_limits() : void {
		if(count($_SESSION['dp_caspow']['active'])>self::max_active_challenges()){
			uasort($_SESSION['dp_caspow']['active'], static function(array $left, array $right): int{
				return ((int)($left['challenge']['issued_at'] ?? 0)) <=> ((int)($right['challenge']['issued_at'] ?? 0));
			});
			while(count($_SESSION['dp_caspow']['active'])>self::max_active_challenges()){
				array_shift($_SESSION['dp_caspow']['active']);
			}
		}
		if(count($_SESSION['dp_caspow']['used'])>self::max_used_challenges()){
			uasort($_SESSION['dp_caspow']['used'], static function(array $left, array $right): int{
				return ((int)($left['verified_at'] ?? 0)) <=> ((int)($right['verified_at'] ?? 0));
			});
			while(count($_SESSION['dp_caspow']['used'])>self::max_used_challenges()){
				array_shift($_SESSION['dp_caspow']['used']);
			}
		}
	}

	protected static function config_value(string $key, mixed $default) : mixed {
		$value=DP_CASPOW_CFG[$key] ?? null;
		return $value!==null ? $value : $default;
	}

	protected static function ttl_seconds() : int {
		return max(30, (int)self::config_value('ttl_seconds', self::$ttl_seconds));
	}

	protected static function desktop_base_bits() : int {
		return max(1, (int)self::config_value('desktop_base_bits', self::$desktop_base_bits));
	}

	protected static function mobile_base_bits() : int {
		return max(1, (int)self::config_value('mobile_base_bits', self::$mobile_base_bits));
	}

	protected static function minimum_desktop_bits() : int {
		return max(1, (int)self::config_value('minimum_desktop_bits', self::$minimum_desktop_bits));
	}

	protected static function minimum_mobile_bits() : int {
		return max(1, (int)self::config_value('minimum_mobile_bits', self::$minimum_mobile_bits));
	}

	protected static function maximum_bits() : int {
		return max(1, (int)self::config_value('maximum_bits', self::$maximum_bits));
	}

	protected static function chunk_size() : int {
		return max(32, (int)self::config_value('chunk_size', self::$chunk_size));
	}

	protected static function max_duration_ms() : int {
		return max(250, (int)self::config_value('max_duration_ms', self::$max_duration_ms));
	}

	protected static function max_active_challenges() : int {
		return max(1, (int)self::config_value('max_active_challenges', self::$max_active_challenges));
	}

	protected static function max_used_challenges() : int {
		return max(1, (int)self::config_value('max_used_challenges', self::$max_used_challenges));
	}

	protected static function max_iterations_multiplier() : int {
		return max(4, (int)self::config_value('max_iterations_multiplier', self::$max_iterations_multiplier));
	}

	protected static function server_algorithm_name() : string {
		return match(strtolower((string)self::config_value('algorithm', self::$algorithm))){
			'sha-256', 'sha256'=>'sha256',
			default=>'sha256',
		};
	}

	protected static function client_algorithm_name() : string {
		return 'SHA-256';
	}
}
