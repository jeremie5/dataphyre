<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Jwt;

/**
 * Exposes the decoded parts of a JSON Web Token.
 *
 * JwtPayload is a transport value, not a verifier. It preserves the original
 * token string alongside decoded header and claim arrays after another access
 * component has performed parsing and, when required, signature validation.
 * Callers should not treat the presence of this object as proof of trust unless
 * it came from a verifier that enforces algorithm, key, issuer, audience, and
 * expiry policy.
 */
final class JwtPayload {

	/** Original compact JWT string received from the caller or token store. */
	private string $token;

	/** Decoded JOSE header fields such as alg, typ, kid, or cty. */
	private array $headers;

	/** Decoded claim set such as sub, iss, aud, exp, nbf, iat, and custom claims. */
	private array $claims;

	/**
	 * Stores the token and decoded payload sections.
	 *
	 * The constructor does not normalize or validate claims so the object can
	 * represent both trusted verifier output and diagnostic decode results. That
	 * boundary keeps policy decisions in dedicated access services instead of
	 * hiding them inside a simple data carrier.
	 *
	 * @param string $token Original compact JWT string.
	 * @param array<string, mixed> $headers Decoded JOSE header map.
	 * @param array<string, mixed> $claims Decoded JWT claim map.
	 */
	public function __construct(string $token, array $headers, array $claims){
		$this->token=$token;
		$this->headers=$headers;
		$this->claims=$claims;
	}

	/**
	 * Returns the original compact token string.
	 *
	 * @return string JWT as received before decoding.
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Returns all decoded JOSE header fields.
	 *
	 * @return array<string, mixed> Header values, preserving verifier/parser output.
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Returns all decoded token claims.
	 *
	 * @return array<string, mixed> Registered and custom claims from the JWT body.
	 */
	public function claims(): array {
		return $this->claims;
	}

	/**
	 * Returns one decoded JOSE header field.
	 *
	 * Header lookup is a convenience accessor only. Security-sensitive callers
	 * should still let verifier services decide whether algorithm and key
	 * metadata are acceptable.
	 *
	 * @param string $key Header key such as alg, typ, or kid.
	 * @param mixed $default Value returned when the header is absent.
	 * @return mixed decoded header value for the exact key, or the caller default when absent.
	 */
	public function header(string $key, mixed $default=null): mixed {
		return $this->headers[$key] ?? $default;
	}

	/**
	 * Returns one decoded claim.
	 *
	 * Claim lookup does not perform expiry, audience, issuer, or subject policy
	 * checks; those remain the responsibility of the access component that
	 * accepted the token.
	 *
	 * @param string $key Claim key such as sub, iss, aud, exp, or a custom claim.
	 * @param mixed $default Value returned when the claim is absent.
	 * @return mixed decoded claim value for the exact key, or the caller default when absent.
	 */
	public function claim(string $key, mixed $default=null): mixed {
		return $this->claims[$key] ?? $default;
	}
}
