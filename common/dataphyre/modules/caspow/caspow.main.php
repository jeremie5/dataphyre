<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

class caspow{
	
    protected static string $algorithm='sha-256';
    protected static int $range_min=50000;
    protected static int $range_max=75000;

    public static function create_challenge(?string $salt=null, ?int $number=null) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        $salt=$salt??bin2hex(random_bytes(12));
		if(is_null($number)){
			if(access::is_mobile()===true){
				$number=random_int(self::$range_min/5, self::$range_max/5);
			}
			else
			{
				$number=random_int(self::$range_min, self::$range_max);
			}
		}
        $algorithm=match(strtolower(self::$algorithm)){
            'sha-256'=>'sha256',
            'sha-384'=>'sha384',
            'sha-512'=>'sha512',
            default=>throw new Exception('Algorithm must be set to SHA-256, SHA-384, or SHA-512.'),
        };
        $challenge=hash($algorithm, $salt.$number);
        $signature=hash_hmac($algorithm, $challenge, dpvk());
        return[
            'algorithm'=>self::$algorithm,
            'challenge'=>$challenge,
            'salt'=>$salt,
            'signature'=>$signature,
        ];
    }

    public static function verify_payload(mixed $payload) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(is_string($payload)){
			if(null!==$json=json_decode(base64_decode($payload), true)){
				$check=self::create_challenge($json['salt'], $json['number']);
				return $json['algorithm']===$check['algorithm'] && $json['challenge']===$check['challenge'] && $json['signature']===$check['signature'];
			}
        }
        return false;
    }
	
}