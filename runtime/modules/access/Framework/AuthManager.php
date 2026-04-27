<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

use Dataphyre\Access\Contracts\Guard;
use Dataphyre\Access\Contracts\UserProvider;
use Dataphyre\Access\Guards\AccessGuard;
use Dataphyre\Access\Guards\JwtGuard;
use Dataphyre\Access\Providers\CallbackUserProvider;

final class AuthManager {

	private static ?self $instance=null;
	private array $guards=[];
	private array $providers=[];
	private array $guard_drivers=[];
	private array $provider_overrides=[];
	private ?string $current_guard_override=null;

	private function __construct(){
		$this->guard_drivers['access']=function(string $name, array $config, ?UserProvider $provider): Guard {
			return new AccessGuard(
				$name,
				(string)($config['auth_type'] ?? $config['driver'] ?? \dataphyre\access::default_auth_type()),
				$provider
			);
		};
		$this->guard_drivers['session']=$this->guard_drivers['access'];
		$this->guard_drivers['jwt']=function(string $name, array $config, ?UserProvider $provider): Guard {
			return new JwtGuard($name, $config, $provider);
		};
	}

	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function defaultGuard(): string {
		if($this->current_guard_override!==null && $this->current_guard_override!==''){
			return $this->current_guard_override;
		}
		$default=(string)(DP_ACCESS_CFG['framework']['default_guard'] ?? '');
		if($default!==''){
			return $default;
		}
		return \dataphyre\access::default_auth_type();
	}

	public function guardNames(): array {
		return array_keys($this->guardConfigs());
	}

	public function hasGuard(string $name): bool {
		return isset($this->guardConfigs()[$name]);
	}

	public function shouldUse(string $name): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Access guard name cannot be empty.');
		}
		$this->current_guard_override=$name;
	}

	public function forgetGuardOverride(): void {
		$this->current_guard_override=null;
	}

	public function guard(?string $name=null): Guard {
		$name=$name!==null && trim($name)!=='' ? trim($name) : $this->defaultGuard();
		if(isset($this->guards[$name])){
			return $this->guards[$name];
		}
		$config=$this->guardConfig($name);
		$driver=strtolower(trim((string)($config['driver'] ?? 'access')));
		if(!isset($this->guard_drivers[$driver])){
			throw new \RuntimeException("Access guard driver '{$driver}' is not registered.");
		}
		$provider_name=$config['provider'] ?? null;
		$provider=is_string($provider_name) && $provider_name!==''
			? $this->provider($provider_name)
			: null;
		$factory=$this->guard_drivers[$driver];
		return $this->guards[$name]=$factory($name, $config, $provider);
	}

	public function provider(string $name): ?UserProvider {
		$name=trim($name);
		if($name===''){
			return null;
		}
		if(isset($this->providers[$name])){
			return $this->providers[$name];
		}
		$config=$this->provider_overrides[$name] ?? $this->providerConfigs()[$name] ?? null;
		if($config===null){
			return null;
		}
		return $this->providers[$name]=$this->makeProvider($config);
	}

	public function extendGuard(string $driver, callable $resolver): void {
		$driver=strtolower(trim($driver));
		if($driver===''){
			throw new \InvalidArgumentException('Access guard driver name cannot be empty.');
		}
		$this->guard_drivers[$driver]=$resolver;
	}

	public function extendProvider(string $name, mixed $config): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Access provider name cannot be empty.');
		}
		$this->provider_overrides[$name]=$config;
		unset($this->providers[$name]);
	}

	private function guardConfigs(): array {
		$config_guards=DP_ACCESS_CFG['framework']['guards'] ?? null;
		$guards=is_array($config_guards) ? $config_guards : [];
		foreach(\dataphyre\access::enabled_auth_types() as $auth_type){
			if(!isset($guards[$auth_type])){
				$guards[$auth_type]=[
					'driver'=>$this->defaultDriverForAuthType($auth_type),
					'auth_type'=>$auth_type,
				];
			}
		}
		if($guards===[]){
			$default_guard=$this->defaultGuard();
			$guards[$default_guard]=[
				'driver'=>$this->defaultDriverForAuthType(\dataphyre\access::default_auth_type()),
				'auth_type'=>\dataphyre\access::default_auth_type(),
			];
		}
		return $guards;
	}

	private function providerConfigs(): array {
		$providers=DP_ACCESS_CFG['framework']['providers'] ?? null;
		return is_array($providers) ? $providers : [];
	}

	private function guardConfig(string $name): array {
		$guards=$this->guardConfigs();
		if(isset($guards[$name]) && is_array($guards[$name])){
			return $guards[$name];
		}
		if(\dataphyre\access::auth_type_enabled($name)){
			return [
				'driver'=>$this->defaultDriverForAuthType($name),
				'auth_type'=>$name,
			];
		}
		throw new \RuntimeException("Access guard '{$name}' is not defined.");
	}

	private function makeProvider(mixed $config): UserProvider {
		if($config instanceof UserProvider){
			return $config;
		}
		if(is_string($config) && class_exists($config)){
			$provider=new $config();
			if($provider instanceof UserProvider){
				return $provider;
			}
		}
		if(is_array($config)){
			if(isset($config['instance']) && $config['instance'] instanceof UserProvider){
				return $config['instance'];
			}
			if(isset($config['factory']) && is_callable($config['factory'])){
				$provider=($config['factory'])();
				if($provider instanceof UserProvider){
					return $provider;
				}
			}
			if(isset($config['class']) && is_string($config['class']) && class_exists($config['class'])){
				$arguments=is_array($config['arguments'] ?? null) ? array_values($config['arguments']) : [];
				$provider=new $config['class'](...$arguments);
				if($provider instanceof UserProvider){
					return $provider;
				}
			}
			return CallbackUserProvider::fromConfig($config);
		}
		if(is_callable($config)){
			return new CallbackUserProvider($config);
		}
		throw new \RuntimeException('Access provider configuration is invalid.');
	}

	private function defaultDriverForAuthType(string $auth_type): string {
		$auth_type=strtolower(trim($auth_type));
		return match($auth_type){
			'jwt'=>'jwt',
			'session'=>'session',
			default=>'access',
		};
	}
}
