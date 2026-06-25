<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Access\Exceptions\OAuthException;

/**
 * Resolves configured OAuth providers for the Access framework.
 *
 * Manager owns the process-local provider cache, runtime provider overrides,
 * and conversion from DP_ACCESS_CFG OAuth configuration into Provider
 * instances. Providers are cached internally but returned as clones so callers
 * can adjust request-specific state without mutating the shared prototype.
 */
final class Manager {

	/** Singleton manager used by facade-style OAuth helpers. */
	private static ?self $instance=null;

	/** @var array<string, Provider> Cached provider prototypes keyed by name. */
	private array $providers=[];

	/** @var array<string, mixed> Runtime provider configs that override DP_ACCESS_CFG. */
	private array $providerOverrides=[];

	/**
	 * Returns the process-local OAuth manager singleton.
	 *
	 * @return self Shared manager for the current PHP process.
	 */
	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	/**
	 * Clears the singleton manager and all cached provider prototypes.
	 *
	 * This is useful for tests, configuration reloads, and long-running workers
	 * that need to rebuild providers from fresh configuration.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Lists configured provider names after applying runtime overrides.
	 *
	 * @return array<int, string> Provider names available to provider().
	 */
	public function providerNames(): array {
		return array_keys($this->providerConfigs());
	}

	/**
	 * Reports whether a provider is configured.
	 *
	 *
	 * @param string $name Provider identifier to check.
	 * @return bool True when configuration or an override exists for the provider.
	 */
	public function hasProvider(string $name): bool {
		return isset($this->providerConfigs()[trim($name)]);
	}

	/**
	 * Resolves an OAuth provider by name.
	 *
	 * The HTTP framework module is loaded before provider construction because
	 * token exchange and redirect helpers depend on HTTP primitives. Providers
	 * are cloned on return to isolate request-level mutation from the cached
	 * prototype.
	 *
	 * @param string $name Configured provider identifier.
	 * @return Provider Provider instance cloned from the cached prototype.
	 * @throws OAuthException When the name is blank or no provider config exists.
	 */
	public function provider(string $name): Provider {
		\dataphyre\core::load_framework_module('http');
		$name=trim($name);
		if($name===''){
			throw new OAuthException('OAuth provider name cannot be empty.');
		}
		if(isset($this->providers[$name])){
			return clone $this->providers[$name];
		}
		$config=$this->providerConfigs()[$name] ?? null;
		if(!is_array($config)){
			throw new OAuthException("OAuth provider '{$name}' is not defined.");
		}
		$provider=$this->makeProvider($name, $config);
		$this->providers[$name]=$provider;
		return clone $provider;
	}

	/**
	 * Registers or replaces a provider configuration at runtime.
	 *
	 * Runtime overrides are merged into the next providerConfigs() result and
	 * invalidate the cached prototype for the provider name.
	 *
	 * @param string $name Provider identifier to override.
	 * @param mixed $config Provider config array or custom provider config accepted by providerConfigs().
	 * @return void
	 * @throws OAuthException When the provider name is blank.
	 */
	public function extendProvider(string $name, mixed $config): void {
		$name=trim($name);
		if($name===''){
			throw new OAuthException('OAuth provider name cannot be empty.');
		}
		$this->providerOverrides[$name]=$config;
		unset($this->providers[$name]);
	}

	/**
	 * Constructs a provider from normalized configuration.
	 *
	 * Config may supply a factory callable, a custom provider class plus
	 * optional constructor arguments, or plain Provider configuration. Factories
	 * and classes must produce Provider instances to preserve the OAuth client
	 * contract.
	 *
	 * @param string $name Provider identifier.
	 * @param array<string, mixed> $config Merged provider configuration.
	 * @return Provider Provider prototype to cache.
	 * @throws OAuthException When factory or class config produces an invalid provider.
	 */
	private function makeProvider(string $name, array $config): Provider {
		if(isset($config['factory']) && is_callable($config['factory'])){
			$provider=($config['factory'])($name, $config, $this);
			if($provider instanceof Provider){
				return $provider;
			}
			throw new OAuthException("OAuth provider factory for '{$name}' must return a Provider instance.");
		}
		if(isset($config['class']) && is_string($config['class']) && class_exists($config['class'])){
			$arguments=is_array($config['arguments'] ?? null) ? array_values($config['arguments']) : [];
			$provider=new $config['class']($name, $config, $this, ...$arguments);
			if($provider instanceof Provider){
				return $provider;
			}
			throw new OAuthException("OAuth provider class for '{$name}' must extend Provider.");
		}
		return new Provider($name, $config, $this);
	}

	/**
	 * Builds the provider configuration map.
	 *
	 * Global OAuth defaults from DP_ACCESS_CFG.framework.oauth are merged into
	 * each array provider config after removing the providers list itself.
	 * Runtime overrides replace configured providers by name before merging.
	 * Non-array configs are preserved for callers that intentionally inspect or
	 * reject custom shapes later.
	 *
	 * @return array<string, mixed> Provider config map keyed by provider name.
	 */
	private function providerConfigs(): array {
		$oauthConfig=DP_ACCESS_CFG['framework']['oauth'] ?? null;
		$oauthConfig=is_array($oauthConfig) ? $oauthConfig : [];
		$defaults=$oauthConfig;
		unset($defaults['providers']);
		$configured=is_array($oauthConfig['providers'] ?? null) ? $oauthConfig['providers'] : [];
		foreach($this->providerOverrides as $name=>$override){
			$configured[$name]=$override;
		}
		$providers=[];
		foreach($configured as $name=>$config){
			if(is_array($config)){
				$providers[(string)$name]=array_replace_recursive($defaults, $config);
				continue;
			}
			$providers[(string)$name]=$config;
		}
		return $providers;
	}
}
