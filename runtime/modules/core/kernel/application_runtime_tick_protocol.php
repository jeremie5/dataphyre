<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** One-time signed cadence claims shared by the trusted supervisor and router. */
final class DataphyreApplicationRuntimeTickProtocol
{
    public const CONTRACT='dataphyre.application_runtime_tick.v1';

    /** @return array{timestamp:string,nonce:string,application:string,environment:string,counter:string,signature:string} */
    public static function issue(
        string $application,
        string $environment,
        int $counter,
        string $secretKey,
        ?int $timestamp=null,
        ?string $nonce=null
    ): array {
        if ($counter<1 || strlen($secretKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('Runtime tick signing inputs are invalid.');
        }
        $timestampValue=(string)($timestamp ?? time());
        $nonceValue=$nonce ?? bin2hex(random_bytes(16));
        if (preg_match('/^[0-9]{10}$/D',$timestampValue)!==1 || preg_match('/^[a-f0-9]{32}$/D',$nonceValue)!==1) {
            throw new InvalidArgumentException('Runtime tick timestamp or nonce is invalid.');
        }
        $counterValue=(string)$counter;
        $signature=sodium_bin2base64(
            sodium_crypto_sign_detached(self::canonical($timestampValue,$nonceValue,$application,$environment,$counterValue),$secretKey),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
        return [
            'timestamp'=>$timestampValue,
            'nonce'=>$nonceValue,
            'application'=>$application,
            'environment'=>$environment,
            'counter'=>$counterValue,
            'signature'=>$signature,
        ];
    }

    public static function verify(array $candidate, string $publicKey, ?int $now=null): bool
    {
        if (array_keys($candidate)!==['timestamp','nonce','application','environment','counter','signature']
            || strlen($publicKey)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
        foreach ($candidate as $value) if (!is_string($value)) return false;
        if (preg_match('/^[0-9]{10}$/D',$candidate['timestamp'])!==1
            || abs(($now ?? time())-(int)$candidate['timestamp'])>30
            || preg_match('/^[a-f0-9]{32}$/D',$candidate['nonce'])!==1
            || preg_match('/^[1-9][0-9]{0,18}$/D',$candidate['counter'])!==1) return false;
        try {
            $signature=sodium_base642bin($candidate['signature'],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');
        } catch (Throwable) {
            return false;
        }
        return strlen($signature)===SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached(
                $signature,
                self::canonical(
                    $candidate['timestamp'],$candidate['nonce'],$candidate['application'],
                    $candidate['environment'],$candidate['counter'],
                ),
                $publicKey,
            );
    }

    /** Atomically removes the exact supervisor-issued claim after one verification. */
    public static function consume(array &$pending, array $candidate, string $publicKey, ?int $now=null): bool
    {
        if (!self::verify($candidate,$publicKey,$now)) return false;
        $counter=$candidate['counter'];
        $issued=$pending[$counter] ?? null;
        if (!is_array($issued)) return false;
        $issuedJson=json_encode($issued,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $candidateJson=json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (!hash_equals($issuedJson,$candidateJson)) return false;
        unset($pending[$counter]);
        return true;
    }

    private static function canonical(
        string $timestamp,
        string $nonce,
        string $application,
        string $environment,
        string $counter
    ): string {
        return self::CONTRACT."\n{$timestamp}\n{$nonce}\n{$application}\n{$environment}\n{$counter}";
    }
}
