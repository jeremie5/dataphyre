<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

\dp_module_required('geoposition', 'sql');
dp_define_module_config('geoposition', 'DP_GEOPOSITION_CFG', [
	'postal_codes_regex_table'=>'dataphyre.postal_codes_regex',
	'postal_codes_table'=>'dataphyre.postal_codes',
	'subdivision_positions_path'=>__DIR__.'/datasets/subdivision_positions.json',
]);

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

class geoposition{

	protected static ?array $subdivision_positions_cache=null;

	protected static function config(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return DP_GEOPOSITION_CFG;
	}

	protected static function postal_codes_regex_table(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return (string)self::config()['postal_codes_regex_table'];
	}

	protected static function postal_codes_table(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return (string)self::config()['postal_codes_table'];
	}

	protected static function subdivision_positions_path(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return (string)self::config()['subdivision_positions_path'];
	}

	protected static function normalize_country_code(string $country): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return strtoupper(trim($country));
	}

	protected static function normalize_subdivision_code(string $subdivision='*'): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		$subdivision=trim($subdivision);
		return $subdivision==='' ? '*' : strtoupper($subdivision);
	}

	protected static function normalize_postal_code(string $postal_code): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return trim($postal_code);
	}

	protected static function postal_code_rule_map(?string $rules): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		if($rules===null || trim($rules)===''){
			return [];
		}
		return array_fill_keys(
			array_filter(array_map('trim', explode(',', $rules)), static fn(string $rule): bool => $rule!==''),
			true
		);
	}

	protected static function regex_replace_pair(?string $encoded_regex): ?array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		if($encoded_regex===null || trim($encoded_regex)===''){
			return null;
		}
		$parts=preg_split('/␞|âž/u', $encoded_regex, 2);
		if(!is_array($parts) || count($parts)!==2){
			return null;
		}
		return [$parts[0], $parts[1]];
	}

	protected static function normalize_point(?array $position): array|false {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		if(!is_array($position)){
			return false;
		}
		$latitude=$position['latitude'] ?? $position['lat'] ?? null;
		$longitude=$position['longitude'] ?? $position['long'] ?? null;
		if(!is_numeric($latitude) || !is_numeric($longitude)){
			return false;
		}
		$normalized=[
			'latitude'=>(float)$latitude,
			'longitude'=>(float)$longitude,
			'lat'=>(float)$latitude,
			'long'=>(float)$longitude,
		];
		if(isset($position['subdivision'])){
			$normalized['subdivision']=(string)$position['subdivision'];
		}
		return $normalized;
	}

	protected static function point_components(array $position): array|false {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		if(false===($normalized=self::normalize_point($position))){
			return false;
		}
		return [
			'latitude'=>$normalized['latitude'],
			'longitude'=>$normalized['longitude'],
		];
	}

	protected static function subdivision_positions(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		if(self::$subdivision_positions_cache!==null){
			return self::$subdivision_positions_cache;
		}
		$positions=[];
		$path=self::subdivision_positions_path();
		if(file_exists($path)){
			$decoded=json_decode((string)file_get_contents($path), true);
			if(is_array($decoded)){
				$positions=$decoded;
			}
		}
		return self::$subdivision_positions_cache=$positions;
	}

	public static function reformat_postal_code(string $country, string $subdivision='*', string $postal_code): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$country=self::normalize_country_code($country);
		$subdivision=self::normalize_subdivision_code($subdivision);
		$postal_code=self::normalize_postal_code($postal_code);
		$result=$postal_code;
		if(false!==$row=sql_select(
			$S="*",
			$L=self::postal_codes_regex_table(),
			$P="WHERE country=? AND (subdivision=? OR subdivision='*')",
			$V=[$country, $subdivision],
			$F=false,
			$C=true
		)){
			if(null!==($regex=self::regex_replace_pair($row['reformatting_regex'] ?? null))){
				$replaced=preg_replace($regex[0], $regex[1], $result);
				if($replaced!==null){
					$result=$replaced;
				}
			}
			$rules=self::postal_code_rule_map($row['reformatting_rules'] ?? null);
			if(isset($rules['force_uppercase'])){
				$result=strtoupper($result);
			}
			elseif(isset($rules['force_lowercase'])){
				$result=strtolower($result);
			}
			if(isset($rules['digits_only'])){
				$result=(string)preg_replace('/\D+/', '', $result);
			}
			if(isset($rules['letters_only'])){
				$result=(string)preg_replace('/[^[:alpha:]]+/u', '', $result);
			}
			return $result;
		}
		return $postal_code;
	}

	public static function validate_postal_code(string $country, string $subdivision='*', string $postal_code): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$country=self::normalize_country_code($country);
		$subdivision=self::normalize_subdivision_code($subdivision);
		$postal_code=self::reformat_postal_code($country, $subdivision, $postal_code);
		if(false!==$row=sql_select(
			$S="*",
			$L=self::postal_codes_regex_table(),
			$P="WHERE country=? AND (subdivision=? OR subdivision='*')",
			$V=[$country, $subdivision],
			$F=false,
			$C=true
		)){
			$validation_regex=(string)($row['validation_regex'] ?? '');
			if($validation_regex!=='' && 1!==preg_match($validation_regex, $postal_code)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Postal code is not valid');
				return false;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Postal code is valid');
		return true;
	}

	public static function get_position_for_postal_code(string $country, string $postal_code=''): array|false {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$country=self::normalize_country_code($country);
		$postal_code=self::normalize_postal_code($postal_code);
		while(true){
			if(strlen($postal_code)<2 || (isset($row) && $row===false && is_numeric($postal_code))){
				break;
			}
			if(false!==$row=sql_select(
				$S="*",
				$L=self::postal_codes_table(),
				$P=[
					"mysql"=>"WHERE country=? AND postal_code LIKE CONCAT(?, '%')",
					"postgresql"=>"WHERE country=? AND postal_code LIKE (? || '%')",
				],
				$V=[$country, $postal_code],
				$F=false
			)){
				return self::normalize_point([
					'latitude'=>$row['latitude'] ?? null,
					'longitude'=>$row['longitude'] ?? null,
					'subdivision'=>$row['subdivision'] ?? null,
				]);
			}
			$postal_code=substr($postal_code, 0, -1);
		}
		return false;
	}

	public static function get_position_for_subdivision(string $country, string $subdivision): array|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$country=self::normalize_country_code($country);
		$subdivision=self::normalize_subdivision_code($subdivision);
		$subdivision_positions=self::subdivision_positions();
		if(isset($subdivision_positions[$country][$subdivision])){
			return self::normalize_point($subdivision_positions[$country][$subdivision]);
		}
		return false;
	}

	public static function distance_between_subdivisions(string $country1, string $subdivision1, string $country2, string $subdivision2, bool $better_precision=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$position1=geoposition::get_position_for_subdivision($country1, $subdivision1);
		$position2=geoposition::get_position_for_subdivision($country2, $subdivision2);
		if(!empty($position1) && !empty($position2)){
			if($better_precision){
				return geoposition::vincenty_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
			}
			return geoposition::haversine_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
		}
		return false;
	}

	public static function distance_between_postal_codes(string $country1, string $postal_code1, string $country2, string $postal_code2, bool $better_precision=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$position1=geoposition::get_position_for_postal_code($country1, $postal_code1);
		$position2=geoposition::get_position_for_postal_code($country2, $postal_code2);
		if(!empty($position1) && !empty($position2)){
			if($better_precision){
				return geoposition::vincenty_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
			}
			return geoposition::haversine_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
		}
		return false;
	}

	public static function distance_between_points(array $position1, array $position2, bool $better_precision=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$position1=self::point_components($position1);
		$position2=self::point_components($position2);
		if(!empty($position1) && !empty($position2)){
			if($better_precision){
				return geoposition::vincenty_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
			}
			return geoposition::haversine_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
		}
		return false;
	}

	public static function haversine_great_circle_distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2, int $earthRadius=6371){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$latFrom=deg2rad($latitude1);
		$lonFrom=deg2rad($longitude1);
		$latTo=deg2rad($latitude2);
		$lonTo=deg2rad($longitude2);
		$latDelta=$latTo-$latFrom;
		$lonDelta=$lonTo-$lonFrom;
		$angle=2*asin(sqrt(pow(sin($latDelta / 2), 2)+cos($latFrom)*cos($latTo)*pow(sin($lonDelta/2), 2)));
		return $angle*$earthRadius;
	}

	public static function vincenty_great_circle_distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2, int $a=6378137, float $f=1/298.257223563){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if($latitude1===$latitude2 && $longitude1===$longitude2){
			return 0.0;
		}
		$b=(1-$f)*$a;
		$L=deg2rad($longitude2-$longitude1);
		$U1=atan((1-$f)*tan(deg2rad($latitude1)));
		$U2=atan((1-$f)*tan(deg2rad($latitude2)));
		$sinU1=sin($U1);
		$cosU1=cos($U1);
		$sinU2=sin($U2);
		$cosU2=cos($U2);
		$lambda=$L;
		$lambdaP=0;
		$iterLimit=100;
		while(abs($lambda-$lambdaP)>1e-12 && --$iterLimit>0){
			$sinLambda=sin($lambda);
			$cosLambda=cos($lambda);
			$sinSigma=sqrt(($cosU2*$sinLambda)**2+($cosU1*$sinU2-$sinU1*$cosU2*$cosLambda)**2);
			if($sinSigma==0.0){
				return 0.0;
			}
			$cosSigma=$sinU1*$sinU2+$cosU1*$cosU2*$cosLambda;
			$sigma=atan2($sinSigma, $cosSigma);
			$sinAlpha=$cosU1*$cosU2*$sinLambda/$sinSigma;
			$cos2Alpha=1-$sinAlpha**2;
			$cos2SigmaM=$cos2Alpha!=0.0
				? $cosSigma-2*$sinU1*$sinU2/$cos2Alpha
				: 0.0;
			$C=$f/16*$cos2Alpha*(4+$f*(4-3*$cos2Alpha));
			$lambdaP=$lambda;
			$lambda=$L+(1-$C)*$f*$sinAlpha*($sigma+$C*$sinSigma*($cos2SigmaM+$C*$cosSigma*(-1+2*$cos2SigmaM**2)));
		}
		if($iterLimit==0){
			return 0.0;
		}
		$u2=$cos2Alpha*($a**2-$b**2)/($b**2);
		$A=1+$u2/16384*(4096+$u2*(-768+$u2*(320-175*$u2)));
		$B=$u2/1024*(256+$u2*(-128+$u2*(74-47*$u2)));
		$deltaSigma=$B*$sinSigma*($cos2SigmaM+$B/4*($cosSigma*(-1+2*$cos2SigmaM**2)-$B/6*$cos2SigmaM*(-3+4*$sinSigma**2)*(-3+4*$cos2SigmaM**2)));
		$s=$b*$A*($sigma-$deltaSigma);
		return $s/1000;
	}

}
