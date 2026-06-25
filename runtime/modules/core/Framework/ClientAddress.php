<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable description of the client address selected for the current request.
 *
 * ClientAddress carries both the effective client IP and the raw remote address so security-sensitive code can distinguish
 * a direct connection from a trusted-proxy/header-derived address. The class does not decide which proxy headers are safe;
 * that trust decision is made by core::get_client_ip_details() and captured here for diagnostics, logging, rate limiting,
 * and request audit surfaces.
 *
 * Treat ip() as the address Dataphyre will use for request identity decisions, and remoteAddress() as the transport peer
 * observed by PHP. When forwarded() is true, sourceHeader() names the trusted header that supplied the effective address.
 */
final class ClientAddress implements \JsonSerializable {

	private ?array $arrayPayload=null;

	/**
	 * Creates a client address value from already-resolved request details.
	 *
	 * Constructor arguments are stored without revalidation so callers can preserve the exact decision made by the runtime
	 * resolver. Use fromArray() for normalized construction from the associative payload returned by
	 * core::get_client_ip_details().
	 *
	 * @param string $ip Effective client IP selected by the resolver.
	 * @param string $remoteAddr Raw transport peer address from server state.
	 * @param string $source Resolver source, normally remote_addr or header.
	 * @param ?string $sourceHeader Trusted proxy header used when source is header.
	 * @param bool $trustedProxy Whether the remote peer matched the configured trusted proxy list.
	 * @param array<int, string> $trustedHeaders Header names eligible for client IP extraction.
	 * @param array<int, string> $trustedProxies Proxy addresses or ranges trusted by the resolver.
	 */
	public function __construct(
		private readonly string $ip,
		private readonly string $remoteAddr,
		private readonly string $source='remote_addr',
		private readonly ?string $sourceHeader=null,
		private readonly bool $trustedProxy=false,
		private readonly array $trustedHeaders=[],
		private readonly array $trustedProxies=[]
	){}

	/**
	 * Resolves the current request's client address through the Dataphyre core runtime.
	 *
	 * @return self Address value reflecting the active request environment and trust configuration.
	 */
	public static function current(): self {
		return static::fromArray(\dataphyre\core::get_client_ip_details());
	}

	/**
	 * Builds a client address value from the resolver detail payload.
	 *
	 * Missing values fall back to 0.0.0.0 or remote_addr defaults so diagnostics always receive a complete structure.
	 * trusted_headers and trusted_proxies are normalized to numeric arrays for stable JSON output.
	 *
	 * @param array{ip?:string, remote_addr?:string, source?:string, source_header?:?string, trusted_proxy?:bool, trusted_headers?:array<int, string>, trusted_proxies?:array<int, string>} $details Resolver details from core::get_client_ip_details().
	 * @return self Normalized client address value.
	 */
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

	/**
	 * Returns the effective client IP selected by the resolver.
	 *
	 * This is the address used by application logic for audit logs, throttling, access checks, and location lookups.
	 *
	 * @return string Effective IPv4 or IPv6 address, or the resolver fallback value.
	 */
	public function ip(): string {
		return $this->ip;
	}

	/**
	 * Returns the raw transport peer address.
	 *
	 * This value normally comes from server REMOTE_ADDR and may be a proxy address when Dataphyre is deployed behind a load
	 * balancer or reverse proxy.
	 *
	 * @return string Raw remote address observed by PHP.
	 */
	public function remoteAddress(): string {
		return $this->remoteAddr;
	}

	/**
	 * Returns the resolver source used for the effective IP.
	 *
	 * @return string Source label such as remote_addr or header.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Returns the trusted header that supplied the effective IP, when applicable.
	 *
	 * @return ?string Header name used for forwarded address extraction, or null for direct remote address resolution.
	 */
	public function sourceHeader(): ?string {
		return $this->sourceHeader;
	}

	/**
	 * Reports whether the raw remote address was accepted as a trusted proxy.
	 *
	 * @return bool Whether proxy headers were eligible to influence the effective IP.
	 */
	public function trustedProxy(): bool {
		return $this->trustedProxy;
	}

	/**
	 * Reports whether the effective IP came from a trusted forwarding header.
	 *
	 * @return bool Whether source() is header.
	 */
	public function forwarded(): bool {
		return $this->source==='header';
	}

	/**
	 * Returns the configured proxy headers considered by the resolver.
	 *
	 * @return array<int, string> Header names accepted for forwarded client IP extraction.
	 */
	public function trustedHeaders(): array {
		return $this->trustedHeaders;
	}

	/**
	 * Returns the configured proxy addresses trusted by the resolver.
	 *
	 * @return array<int, string> Trusted proxy addresses, names, or ranges as configured.
	 */
	public function trustedProxies(): array {
		return $this->trustedProxies;
	}

	/**
	 * Reports whether the effective IP is a valid IPv4 address.
	 *
	 * @return bool Whether ip() passes PHP's IPv4 validator.
	 */
	public function isIpv4(): bool {
		return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)!==false;
	}

	/**
	 * Reports whether the effective IP is a valid IPv6 address.
	 *
	 * @return bool Whether ip() passes PHP's IPv6 validator.
	 */
	public function isIpv6(): bool {
		return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)!==false;
	}

	/**
	 * Reports whether the effective IP is a loopback address.
	 *
	 * @return bool Whether ip() is 127.0.0.1 or ::1.
	 */
	public function isLoopback(): bool {
		return in_array($this->ip, ['127.0.0.1', '::1'], true);
	}

	/**
	 * Reports whether the effective IP is valid and belongs to a private range.
	 *
	 * @return bool Whether ip() is valid and fails PHP's public-range validation.
	 */
	public function isPrivate(): bool {
		return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)===false
			&& filter_var($this->ip, FILTER_VALIDATE_IP)!==false;
	}

	/**
	 * Serializes the address decision for diagnostics, logs, and JSON output.
	 *
	 * The payload intentionally includes both address values and the trust metadata needed to explain why Dataphyre selected
	 * the effective IP.
	 *
	 * @return array{ip:string, remote_addr:string, source:string, source_header:?string, trusted_proxy:bool, forwarded:bool, is_ipv4:bool, is_ipv6:bool, is_loopback:bool, is_private:bool, trusted_headers:array<int, string>, trusted_proxies:array<int, string>} Address diagnostic payload.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$isIpv4=filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)!==false;
		$isIpv6=filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)!==false;
		$isValid=$isIpv4 || $isIpv6;
		return $this->arrayPayload=[
			'ip'=>$this->ip,
			'remote_addr'=>$this->remoteAddr,
			'source'=>$this->source,
			'source_header'=>$this->sourceHeader,
			'trusted_proxy'=>$this->trustedProxy,
			'forwarded'=>$this->source==='header',
			'is_ipv4'=>$isIpv4,
			'is_ipv6'=>$isIpv6,
			'is_loopback'=>in_array($this->ip, ['127.0.0.1', '::1'], true),
			'is_private'=>$isValid && filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)===false,
			'trusted_headers'=>$this->trustedHeaders,
			'trusted_proxies'=>$this->trustedProxies,
		];
	}

	/**
	 * Serializes the address decision for json_encode().
	 *
	 * @return array<string, mixed> Address diagnostic payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Returns the effective client IP as the string form.
	 *
	 * @return string Effective client IP.
	 */
	public function __toString(): string {
		return $this->ip;
	}
}
