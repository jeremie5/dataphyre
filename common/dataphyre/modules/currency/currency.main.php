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

use simplexml;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

dp_module_required('currency', 'sql');

if(file_exists($filepath=$rootpath['common_dataphyre']."config/currency.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/currency.php")){
	require_once($filepath);
}

currency::get_exchange_rates();

class currency{

	public static $base_currency='USD';
	public static $display_currency='USD';
	public static $display_language='en-CA';
	public static $display_country='CA';
	public static $available_currencies=["USD"=>"$"];
	public static $special_formatting=[
		'zh-HK'=>['HK'=>['.', ',', 2]],
		'zh-CN'=>['CN'=>['.', ',', 2]],
		'zh-TW'=>['TW'=>['.', ',', 2]],
		'en-AU'=>['AU'=>['.', ',', 2]],
		'en-CA'=>['CA'=>['.', ',', 2]],
		'en-IN'=>['IN'=>['.', ',', 2]],
		'en-NZ'=>['NZ'=>['.', ',', 2]],
		'en-ZA'=>['ZA'=>['.', ',', 2]],
		'en-GB'=>['GB'=>['.', ',', 2]],
		'en-US'=>['US'=>['.', ',', 2]],
		'de-AT'=>['AT'=>[',', '.', 2]],
		'de-DE'=>['DE'=>[',', '.', 2]],
		'de-LI'=>['LI'=>[',', '.', 2]],
		'de-CH'=>['CH'=>[',', '.', 2]],
		'fr-FR'=>['FR'=>['.', ' ', 2]],
		'fr-CH'=>['CH'=>['.', ' ', 2]],
		'it-IT'=>['IT'=>['.', ',', 2]],
		'it-CH'=>['CH'=>['.', ',', 2]],
		'ja'=>['JP'=>['.', ',', 0]],
		'ko'=>['KR'=>['.', ',', 0]],
		'pt-BR'=>['BR'=>['.', ',', 2]],
		'pt-PT'=>['PT'=>['.', ',', 2]],
		'es-AR'=>['AR'=>['.', ',', 2]],
		'es-419'=> ['419'=> ['.', ',', 2]], // Special case for Latin American region
		'es-MX'=>['MX'=>['.', ',', 2]],
		'es-ES'=>['ES'=>['.', ',', 2]],
		'es-US'=>['US'=>['.', ',', 2]],
		'th'=>['TH'=>['.', ',', 0]],
	];


    function __construct(string $base, string $currency, array $available, string $language, string $country){
        currency::$base_currency=$base;
        currency::$display_currency=$currency;
        currency::$available_currencies=$available;
        currency::$display_language=$language;
        currency::$display_country=$country;
    }

	public static function get_exchange_rates(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CURRENCY_GET_EXCHANGE_RATES",...func_get_args())) return $early_return;
		global $is_task;
		global $configurations;
		if(empty($_SESSION['exchange_rate_data']) || !in_array($_SESSION['exchange_rate_data']['source'], $configurations['dataphyre']['currency']['exchange_rate_sources']) || $_SESSION['exchange_rate_data']['time']>strtotime("+60 Minutes")){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cached exchange rates expired, invalid or missing", $S="warning");
			if(false!==$row=sql::db_select(
				$S="*", 
				$L="dataphyre.exchange_rates", 
				$P=[
					"mysql"=>"WHERE date>DATE_SUB(NOW(),INTERVAL 60 MINUTE) ORDER BY date DESC LIMIT 1", 
					"postgresql"=>"WHERE date>NOW() - INTERVAL '60 minutes' ORDER BY date DESC LIMIT 1"
				],
				$V=null, 
				$F=false, 
				$C=false
			)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Exchange rates loaded into cache");
				if(null!==$data=json_decode($row['data'], true)){
					$_SESSION['exchange_rate_data']['data']=$data;
					$_SESSION['exchange_rate_data']['time']=time();
					$_SESSION['exchange_rate_data']['source']=$row['source'];
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed to update exchange rates", $S="fatal");
					core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCurrency: Failed decoding JSON into usable rates.', 'safemode');
				}
			}
			else
			{
				if($is_task!=true){
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Rates expired. Getting new exchange rates..", $S="warning");
					$i=0;
					while(true){
						if(isset($configurations['dataphyre']['currency']['exchange_rate_sources'][$i])){
							$source=$configurations['dataphyre']['currency']['exchange_rate_sources'][$i];
							if(false!==currency::get_rates_data($source)){
								break;
							}
							$i++;
						}
						else
						{
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Every available rates sources broken", $S="fatal");
							core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCurrency: All rates sources are broken and no cached data is available.', 'safemode');
							break;
						}
					}
				}
			}
		}
		return $_SESSION['exchange_rate_data'];
	}
	
	public static function get_rates_data(string $source){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CURRENCY_FORMATTER",...func_get_args())) return $early_return;
		if($source==='exchangerate.host'){
			$exchange_data=file_get_contents('https://api.exchangerate.host/latest?base=USD');
			if(!empty($exchange_data) && mb_strlen($exchange_data)>200){
				if(null!==$exchange_data=json_decode($exchange_data, true)){
					if(!empty($exchange_data)){
						if(strtotime($exchange_data['date'])<=$_SESSION['exchange_rate_data']['time']){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Rates are older than already known", $S="fatal");
							return false;
						}
						unset($exchange_data['motd'], $exchange_data['success'], $exchange_data['base'], $exchange_data['date']); # Remove unnecessary data
						$_SESSION['exchange_rate_data']['data']=$exchange_data['rates'];
						$_SESSION['exchange_rate_data']['time']=time();
						$_SESSION['exchange_rate_data']['source']=$source;
						$exchange_data=json_encode($exchange_data);
						sql_insert(
							$L="dataphyre.exchange_rates", 
							$F="data,source", 
							$V=array($exchange_data, $source), 
							$CC=true
						);
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Exchange rates updated");
						return true;
					}
				}
			}
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="exchangerate.io returned invalid response", $S="warning");
			return false;
		}
		elseif($source==='europa.eu'){
			$exchange_data=file_get_contents('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');
			preg_match_all('/<Cube currency=[\'"]([^\'"]+)[\'"] rate=[\'"]([^\'"]+)[\'"]/', $exchange_data, $matches, PREG_SET_ORDER);
			$rates=[];
			$eur_to_usd_rate=0;
			foreach($matches as $match){
				$currency=$match[1];
				$rate=(float)$match[2];
				$rates[$currency]=$rate;
				if($currency==='USD'){
					$eur_to_usd_rate=$rate;
				}
			}
			if($eur_to_usd_rate==0){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="EUR to USD rate not found in ".$exchange_data, $S="fatal");
				return false;
			}
			foreach($rates as $currency=>&$rate){
				if($currency!=='USD'){
					$rate=$rate/$eur_to_usd_rate;
				}
			}
			$rates['EUR']=1/$eur_to_usd_rate;
			$rates['USD']=1;
			$_SESSION['exchange_rate_data']['data']=$rates;
			$_SESSION['exchange_rate_data']['time']=time();
			$_SESSION['exchange_rate_data']['source']=$source;
			$exchange_data_json=json_encode($rates);
			sql::db_insert(
				$L="dataphyre.exchange_rates", 
				$F=[
					"data"=>$exchange_data_json,
					"date"=>date('Y-m-d H:i:s'),
					"source"=>$source
				],
				$V=null,
				$CC=true
			);
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Exchange rates updated");
			return true;
		}
		else
		{
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown source", $S="fatal");
			return false;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown error", $S="fatal");
		return false;
	}

	public static function formatter(float|null $amount, bool|null $show_free=false, string|null $currency=null) : string {
		if(null!==$early_return=core::dialback("CALL_CURRENCY_FORMATTER",...func_get_args())) return $early_return;
		if($currency===null)$currency=currency::$display_currency;
		if($amount===0){
			if($show_free===true)return locale('global:FREE', 'Free');
			return 0.00;
		}
		if(isset(self::$special_formatting[self::$display_language][self::$display_country])){
			list($decimal_separator, $thousands_separator, $decimals)=self::$special_formatting[self::$display_language][self::$display_country];
		}
		else
		{
			list($decimal_separator, $thousands_separator, $decimals)=[',', ' ', 2];
		}
		return currency::$available_currencies[$currency].number_format($amount, $decimals, $decimal_separator, $thousands_separator);
	}
	
	public static function convert(float|null $amount, string $source_currency, string $target_currency, bool|null $formatted=false, bool|null $show_free=true): string|float {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CONVERT_TO_USER_CURRENCY",...func_get_args())) return $early_return;
		if(empty($_SESSION['exchange_rate_data']))core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCurrency: No cached rates available in session.', 'safemode');
		$amount=(float)$amount;
		$source_multiplier=$_SESSION['exchange_rate_data']['data'][$source_currency] ?? 1;
		$target_multiplier=$_SESSION['exchange_rate_data']['data'][$target_currency] ?? 1;
		$value=($amount/$source_multiplier)*$target_multiplier;
		if($amount===0){
			if($show_free===true)return locale('global:FREE', 'Free');
			return 0.00;
		}
		if($formatted===false)return number_format($value, 2, ".", "");
		return self::formatter($value, $show_free, $target_currency);
	}

	public static function convert_to_user_currency(float|null $amount, bool|null $formatted=false, bool|null $show_free=true, string|null $currency=null) : string|float {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if($currency===null)$currency=currency::$display_currency;
		return self::convert($amount, currency::$base_currency, $currency, $formatted, $show_free);
	}

	public static function convert_to_website_currency(float|null $amount, string $original_currency, bool|null $formatted=false, bool|null $show_free=true) : string|float {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return self::convert($amount, $original_currency, currency::$base_currency, $formatted, $show_free);
	}
	
}