<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Access\Exceptions\OAuthException;

final class Manager {

	private static ?self $instance=null;
	private array $providers=[];
	private array $provider_overrides=[];

	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function providerNames(): array {
		return array_keys($this->provider_configs());
	}

	public function hasProvider(string $name): bool {
		return isset($this->provider_configs()[trim($name)]);
	}

	public function provider(string $name): Provider {
		\dataphyre\core::load_framework_module('http');
		$name=trim($name);
		if($name===''){
			throw new OAuthException('OAuth provider name cannot be empty.');
		}
		if(isset($this->providers[$name])){
			return clone $this->providers[$name];
		}
		$config=$this->provider_configs()[$name] ?? null;
		if(!is_array($config)){
			throw new OAuthException("OAuth provider '{$name}' is not defined.");
		}
		$provider=$this->make_provider($name, $config);
		$this->providers[$name]=$provider;
		return clone $provider;
	}

	public function extendProvider(string $name, mixed $config): void {
		$name=trim($name);
		if($name===''){
			throw new OAuthException('OAuth provider name cannot be empty.');
		}
		$this->provider_overrides[$name]=$config;
		unset($this->providers[$name]);
	}

	private function make_provider(string $name, array $config): Provider {
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

	private function provider_configs(): array {
		$oauth_config=DP_ACCESS_CFG['framework']['oauth'] ?? null;
		$oauth_config=is_array($oauth_config) ? $oauth_config : [];
		$defaults=$oauth_config;
		unset($defaults['providers']);
		$configured=is_array($oauth_config['providers'] ?? null) ? $oauth_config['providers'] : [];
		foreach($this->provider_overrides as $name=>$override){
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
