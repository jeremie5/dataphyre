<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class ClientAddress implements \JsonSerializable {

	public function __construct(
		private readonly string $ip,
		private readonly string $remote_addr,
		private readonly string $source='remote_addr',
		private readonly ?string $source_header=null,
		private readonly bool $trusted_proxy=false,
		private readonly array $trusted_headers=[],
		private readonly array $trusted_proxies=[]
	){}

	public static function current(): self {
		return static::fromArray(\dataphyre\core::get_client_ip_details());
	}

	public static function fromArray(array $details): self {
		return new self(
			(string)($details['ip'] ?? '0.0.0.0'),
			(string)($details['remote_addr'] ?? ($details['ip'] ?? '0.0.0.0')),
			(string)($details['source'] ?? 'remote_addr'),
			isset($details['source_header']) && $details['source_header']!=='' ? (string)$details['source_header'] : null,
			($details['trusted_proxy'] ?? false)===true,
			is_array($details['trusted_headers'] ?? null) ? array_values($details['trusted_headers']) : [],
			is_array($details['trusted_proxies'] ?? null) ? array_values($details['trusted_proxies']) : []
		);
	}

	public function ip(): string {
		return $this->ip;
	}

	public function remoteAddress(): string {
		return $this->remote_addr;
	}

	public function source(): string {
		return $this->source;
	}

	public function sourceHeader(): ?string {
		return $this->source_header;
	}

	public function trustedProxy(): bool {
		return $this->trusted_proxy;
	}

	public function forwarded(): bool {
		return $this->source==='header';
	}

	public function trustedHeaders(): array {
		return $this->trusted_headers;
	}

	public function trustedProxies(): array {
		return $this->trusted_proxies;
	}

	public function isIpv4(): bool {
		return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)!==false;
	}

	public function isIpv6(): bool {
		return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)!==false;
	}

	public function isLoopback(): bool {
		return in_array($this->ip, ['127.0.0.1', '::1'], true);
	}

	public function isPrivate(): bool {
		return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)===false
			&& filter_var($this->ip, FILTER_VALIDATE_IP)!==false;
	}

	public function toArray(): array {
		return [
			'ip'=>$this->ip,
			'remote_addr'=>$this->remote_addr,
			'source'=>$this->source,
			'source_header'=>$this->source_header,
			'trusted_proxy'=>$this->trusted_proxy,
			'forwarded'=>$this->forwarded(),
			'is_ipv4'=>$this->isIpv4(),
			'is_ipv6'=>$this->isIpv6(),
			'is_loopback'=>$this->isLoopback(),
			'is_private'=>$this->isPrivate(),
			'trusted_headers'=>$this->trusted_headers,
			'trusted_proxies'=>$this->trusted_proxies,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function __toString(): string {
		return $this->ip;
	}
}
