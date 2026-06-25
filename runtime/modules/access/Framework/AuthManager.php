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

/**
 * Resolves and caches access guards and user providers for the current process.
 *
 * AuthManager is the framework registry that turns DP_ACCESS_CFG guard/provider definitions into Guard and UserProvider
 * instances. Guards are cached by name, providers are cached by provider name, and custom guard drivers or provider
 * overrides can be registered at runtime. The singleton can be flushed between tests or long-running worker contexts when
 * configuration changes must be re-read.
 *
 * Guard selection is explicit: an override set with shouldUse() wins, then the configured default_guard, then the legacy
 * access default auth type. Missing guard definitions throw, while missing providers return null so guards can decide
 * whether providerless operation is acceptable.
 */
final class AuthManager {

	private static ?self $instance=null;
	/** @var array<string, Guard> Guard instances cached by guard name. */
	private array $guards=[];
	/** @var array<string, UserProvider> Provider instances cached by provider name. */
	private array $providers=[];
	/** @var array<string, callable(string, array, ?UserProvider): Guard> Guard driver factories keyed by driver name. */
	private array $guardDrivers=[];
	/** @var array<string, mixed> Runtime provider configs that override DP_ACCESS_CFG providers. */
	private array $providerOverrides=[];
	/** @var ?string Request/process-local default guard override. */
	private ?string $currentGuardOverride=null;

	/**
	 * Registers built-in guard drivers.
	 *
	 * access and session share AccessGuard with different auth_type configuration, while jwt creates JwtGuard instances.
	 */
	private function __construct(){
		$this->guardDrivers['access']=function(string $name, array $config, ?UserProvider $provider): Guard {
			return new AccessGuard(
				$name,
				(string)($config['auth_type'] ?? $config['driver'] ?? \dataphyre\access::default_auth_type()),
				$provider
			);
		};
		$this->guardDrivers['session']=$this->guardDrivers['access'];
		$this->guardDrivers['jwt']=function(string $name, array $config, ?UserProvider $provider): Guard {
			return new JwtGuard($name, $config, $provider);
		};
	}

	/**
	 * Returns the singleton authentication manager.
	 *
	 * @return self Process-local manager instance.
	 */
	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	/**
	 * Clears the singleton and all cached guard/provider instances.
	 *
	 * Use this in tests, hot-reload contexts, or workers after changing access configuration or runtime extensions.
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Resolves the guard name used when callers do not request one explicitly.
	 *
	 * @return string Current override, configured default guard, or legacy default auth type.
	 */
	public function defaultGuard(): string {
		if($this->currentGuardOverride!==null && $this->currentGuardOverride!==''){
			return $this->currentGuardOverride;
		}
		$default=(string)(DP_ACCESS_CFG['framework']['default_guard'] ?? '');
		if($default!==''){
			return $default;
		}
		return \dataphyre\access::default_auth_type();
	}

	/**
	 * Lists configured and inferred guard names.
	 *
	 * @return array<int, string> Guard names available through guard().
	 */
	public function guardNames(): array {
		return array_keys($this->guardConfigs());
	}

	/**
	 * Reports whether a guard name has a definition.
	 *
	 * @param string $name Guard name.
	 * @return bool Whether guardConfig() can resolve a configured guard by exact name.
	 */
	public function hasGuard(string $name): bool {
		return isset($this->guardConfigs()[$name]);
	}

	/**
	 * Sets the default guard override for subsequent guard() calls.
	 *
	 * The override does not validate that the guard exists until guard() is called. Blank names are rejected immediately to
	 * avoid silently falling back to a different authentication surface.
	 *
	 * @param string $name Guard name to use as the default.
	 */
	public function shouldUse(string $name): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Access guard name cannot be empty.');
		}
		$this->currentGuardOverride=$name;
	}

	/**
	 * Clears the guard override set by shouldUse().
	 */
	public function forgetGuardOverride(): void {
		$this->currentGuardOverride=null;
	}

	/**
	 * Resolves a guard by name and caches the instance.
	 *
	 * The guard config selects a driver and optional provider. Unknown drivers throw because a guard cannot be safely
	 * constructed without an authentication strategy.
	 *
	 * @param ?string $name Guard name, or null/blank for defaultGuard().
	 * @return Guard Cached or newly constructed guard.
	 */
	public function guard(?string $name=null): Guard {
		$name=$name!==null && trim($name)!=='' ? trim($name) : $this->defaultGuard();
		if(isset($this->guards[$name])){
			return $this->guards[$name];
		}
		$config=$this->guardConfig($name);
		$driver=strtolower(trim((string)($config['driver'] ?? 'access')));
		if(!isset($this->guardDrivers[$driver])){
			throw new \RuntimeException("Access guard driver '{$driver}' is not registered.");
		}
		$providerName=$config['provider'] ?? null;
		$provider=is_string($providerName) && $providerName!==''
			? $this->provider($providerName)
			: null;
		$factory=$this->guardDrivers[$driver];
		return $this->guards[$name]=$factory($name, $config, $provider);
	}

	/**
	 * Resolves a user provider by name and caches the instance.
	 *
	 * Provider overrides registered through extendProvider() take precedence over configured providers. Unknown names return
	 * null rather than throwing so optional guard providers can be modeled explicitly.
	 *
	 * @param string $name Provider name.
	 * @return ?UserProvider Cached or newly constructed provider, or null when undefined.
	 */
	public function provider(string $name): ?UserProvider {
		$name=trim($name);
		if($name===''){
			return null;
		}
		if(isset($this->providers[$name])){
			return $this->providers[$name];
		}
		$config=$this->providerOverrides[$name] ?? $this->providerConfigs()[$name] ?? null;
		if($config===null){
			return null;
		}
		return $this->providers[$name]=$this->makeProvider($config);
	}

	/**
	 * Registers or replaces a guard driver factory.
	 *
	 * The resolver receives guard name, guard config, and optional provider, and must return a Guard instance. Existing
	 * cached guards are not flushed automatically; call flush() when replacing a driver should affect already-resolved guards.
	 *
	 * @param string $driver Driver name used in guard config.
	 * @param callable(string, array<string, mixed>, ?UserProvider): Guard $resolver Guard factory.
	 */
	public function extendGuard(string $driver, callable $resolver): void {
		$driver=strtolower(trim($driver));
		if($driver===''){
			throw new \InvalidArgumentException('Access guard driver name cannot be empty.');
		}
		$this->guardDrivers[$driver]=$resolver;
	}

	/**
	 * Registers or replaces one provider configuration at runtime.
	 *
	 * The named provider cache entry is cleared so the next provider() call uses the new config.
	 *
	 * @param string $name Provider name.
	 * @param mixed $config Provider config accepted by makeProvider().
	 */
	public function extendProvider(string $name, mixed $config): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Access provider name cannot be empty.');
		}
		$this->providerOverrides[$name]=$config;
		unset($this->providers[$name]);
	}

	/**
	 * Builds the guard configuration map from DP_ACCESS_CFG and enabled auth types.
	 *
	 * @return array<string, array<string, mixed>> Guard configs keyed by guard name.
	 */
	private function guardConfigs(): array {
		$configGuards=DP_ACCESS_CFG['framework']['guards'] ?? null;
		$guards=is_array($configGuards) ? $configGuards : [];
		foreach(\dataphyre\access::enabled_auth_types() as $authType){
			if(!isset($guards[$authType])){
				$guards[$authType]=[
					'driver'=>$this->defaultDriverForAuthType($authType),
					'auth_type'=>$authType,
				];
			}
		}
		if($guards===[]){
			$defaultGuard=$this->defaultGuard();
			$guards[$defaultGuard]=[
				'driver'=>$this->defaultDriverForAuthType(\dataphyre\access::default_auth_type()),
				'auth_type'=>\dataphyre\access::default_auth_type(),
			];
		}
		return $guards;
	}

	/**
	 * Returns provider configs from DP_ACCESS_CFG.
	 *
	 * @return array<string, mixed> Provider configs keyed by provider name.
	 */
	private function providerConfigs(): array {
		$providers=DP_ACCESS_CFG['framework']['providers'] ?? null;
		return is_array($providers) ? $providers : [];
	}

	/**
	 * Resolves one guard config or infers one from an enabled auth type.
	 *
	 * @param string $name Guard name or enabled auth type.
	 * @return array<string, mixed> Guard configuration.
	 */
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

	/**
	 * Converts provider configuration into a UserProvider instance.
	 *
	 * Accepted shapes include an existing UserProvider, provider class-string, array with instance, factory, class/arguments,
	 * callback-provider config, or any callable accepted by CallbackUserProvider.
	 *
	 * @param mixed $config Provider configuration.
	 * @return UserProvider Constructed provider.
	 */
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

	/**
	 * Selects the built-in guard driver for a legacy auth type.
	 *
	 * @param string $authType Legacy access auth type.
	 * @return string Built-in guard driver name.
	 */
	private function defaultDriverForAuthType(string $authType): string {
		$authType=strtolower(trim($authType));
		return match($authType){
			'jwt'=>'jwt',
			'session'=>'session',
			default=>'access',
		};
	}
}
