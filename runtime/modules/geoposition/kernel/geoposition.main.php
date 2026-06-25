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
if(function_exists('sql_define_table')){
	sql_define_table((string)DP_GEOPOSITION_CFG['postal_codes_regex_table'], __DIR__.'/geoposition.tables.php', 'postal_codes_regex');
	sql_define_table((string)DP_GEOPOSITION_CFG['postal_codes_table'], __DIR__.'/geoposition.tables.php', 'postal_codes');
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

/**
 * Kernel facade for postal-code normalization, geocoding lookups, and distance math.
 *
 * Geoposition combines SQL-backed postal-code datasets with a filesystem-backed
 * subdivision-position dataset. Postal-code regex rows define formatting and
 * validation behavior per country/subdivision, while postal-code rows provide
 * approximate coordinates. Distance helpers normalize these lookup results and
 * calculate either fast haversine or more precise Vincenty distances.
 */
class geoposition{

	protected static ?array $subdivision_positions_cache=null;

	/**
	 * Returns the module configuration currently bound to DP_GEOPOSITION_CFG.
	 *
	 * @return array{postal_codes_regex_table:string,postal_codes_table:string,subdivision_positions_path:string} Geoposition configuration.
	 */
	protected static function config(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return DP_GEOPOSITION_CFG;
	}

	/**
	 * Returns the SQL table that stores postal-code formatting and validation rules.
	 *
	 * @return string Fully qualified SQL table name.
	 */
	protected static function postal_codes_regex_table(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return (string)self::config()['postal_codes_regex_table'];
	}

	/**
	 * Returns the SQL table that stores postal-code coordinates.
	 *
	 * @return string Fully qualified SQL table name.
	 */
	protected static function postal_codes_table(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return (string)self::config()['postal_codes_table'];
	}

	/**
	 * Returns the JSON dataset path for country/subdivision coordinates.
	 *
	 * @return string Absolute dataset path.
	 */
	protected static function subdivision_positions_path(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return (string)self::config()['subdivision_positions_path'];
	}

	/**
	 * Normalizes a country code for dataset and SQL lookups.
	 *
	 * @param string $country Country code supplied by a caller.
	 * @return string Uppercase trimmed country code.
	 */
	protected static function normalize_country_code(string $country): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return strtoupper(trim($country));
	}

	/**
	 * Normalizes a subdivision code, using `*` as the wildcard rule key.
	 *
	 * @param string $subdivision Subdivision code or blank value.
	 * @return string Uppercase subdivision code, or `*` for empty input.
	 */
	protected static function normalize_subdivision_code(string $subdivision='*'): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		$subdivision=trim($subdivision);
		return $subdivision==='' ? '*' : strtoupper($subdivision);
	}

	/**
	 * Normalizes a postal code before formatting, validation, or lookup.
	 *
	 * @param string $postal_code Caller-supplied postal code.
	 * @return string Trimmed postal code.
	 */
	protected static function normalize_postal_code(string $postal_code): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		return trim($postal_code);
	}

	/**
	 * Converts a comma-separated postal-code rule list into a lookup map.
	 *
	 * Supported rule names are data-driven; current callers check flags such as
	 * `force_uppercase`, `force_lowercase`, `digits_only`, and `letters_only`.
	 *
	 * @param ?string $rules Comma-separated rule names from SQL.
	 * @return array<string, true> Rule lookup map.
	 */
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

	/**
	 * Decodes a stored regex replacement pair.
	 *
	 * Regex rows encode `pattern` and `replacement` with the historical record
	 * separator; this method accepts both the intended character and a mojibake
	 * variant found in older data.
	 *
	 * @param ?string $encoded_regex Encoded replacement pair from SQL.
	 * @return array{0:string,1:string}|null PCRE pattern/replacement pair.
	 */
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

	/**
	 * Normalizes a coordinate payload into Dataphyre's dual key shape.
	 *
	 * Input may use `latitude`/`longitude` or `lat`/`long`; the output includes
	 * both spellings for compatibility with legacy callers.
	 *
	 * @param array<string, mixed>|null $position Coordinate payload.
	 * @return array{latitude:float,longitude:float,lat:float,long:float,subdivision?:string}|false Normalized point, or false when coordinates are missing.
	 */
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

	/**
	 * Extracts latitude and longitude components from a point payload.
	 *
	 * @param array<string, mixed> $position Coordinate payload accepted by {@see self::normalize_point()}.
	 * @return array{latitude:float,longitude:float}|false Components usable by distance formulas.
	 */
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

	/**
	 * Loads and caches the subdivision coordinate dataset.
	 *
	 * @return array<string, array<string, array<string, mixed>>> Dataset keyed by country and subdivision.
	 */
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

	/**
	 * Reformats a postal code using country/subdivision SQL rules.
	 *
	 * The lookup prefers the requested subdivision but also accepts wildcard
	 * `*` rules. A stored regex replacement runs first, followed by simple rule
	 * flags such as upper/lowercase or digit/letter stripping. When no rule row
	 * exists, the trimmed postal code is returned unchanged.
	 *
	 * @param string $country Country code.
	 * @param string $subdivision Subdivision code, or blank for wildcard.
	 * @param string $postal_code Raw postal code.
	 * @return string Reformatted postal code.
	 */
	public static function reformat_postal_code(string $country, string $subdivision, string $postal_code): string {
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

	/**
	 * Validates a postal code against the configured country/subdivision regex.
	 *
	 * Validation always runs after reformatting. Missing regex rows are treated
	 * as permissive because the module cannot prove the code invalid without
	 * country-specific data.
	 *
	 * @param string $country Country code.
	 * @param string $subdivision Subdivision code, or blank for wildcard.
	 * @param string $postal_code Raw postal code.
	 * @return bool True when no validation rule rejects the formatted code.
	 */
	public static function validate_postal_code(string $country, string $subdivision, string $postal_code): bool {
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

	/**
	 * Resolves approximate coordinates for a postal code.
	 *
	 * The SQL lookup progressively truncates the postal code until it finds a
	 * prefix match or the value is too short to be meaningful. This supports
	 * datasets that store region-level prefixes rather than every full code.
	 *
	 * @param string $country Country code.
	 * @param string $postal_code Postal code or prefix.
	 * @return array{latitude:float,longitude:float,lat:float,long:float,subdivision?:string}|false Normalized coordinate payload.
	 */
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

	/**
	 * Resolves approximate coordinates for a country subdivision.
	 *
	 * @param string $country Country code.
	 * @param string $subdivision Subdivision code.
	 * @return array{latitude:float,longitude:float,lat:float,long:float,subdivision?:string}|false Normalized coordinate payload.
	 */
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

	/**
	 * Calculates distance in kilometers between two country subdivisions.
	 *
	 * @param string $country1 First country code.
	 * @param string $subdivision1 First subdivision code.
	 * @param string $country2 Second country code.
	 * @param string $subdivision2 Second subdivision code.
	 * @param bool $better_precision Use Vincenty instead of haversine when true.
	 * @return float|false Distance in kilometers, or false when either subdivision is unknown.
	 */
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

	/**
	 * Calculates distance in kilometers between two postal-code locations.
	 *
	 * Postal code coordinates may be prefix-level approximations depending on
	 * dataset granularity.
	 *
	 * @param string $country1 First country code.
	 * @param string $postal_code1 First postal code.
	 * @param string $country2 Second country code.
	 * @param string $postal_code2 Second postal code.
	 * @param bool $better_precision Use Vincenty instead of haversine when true.
	 * @return float|false Distance in kilometers, or false when either postal code cannot be located.
	 */
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

	/**
	 * Calculates distance in kilometers between two coordinate payloads.
	 *
	 * @param array<string, mixed> $position1 First point accepted by {@see self::normalize_point()}.
	 * @param array<string, mixed> $position2 Second point accepted by {@see self::normalize_point()}.
	 * @param bool $better_precision Use Vincenty instead of haversine when true.
	 * @return float|false Distance in kilometers, or false when either point is invalid.
	 */
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

	/**
	 * Calculates spherical great-circle distance with the haversine formula.
	 *
	 * This is fast and stable for normal application use, but assumes a sphere
	 * rather than an ellipsoid.
	 *
	 * @param float $latitude1 First latitude in decimal degrees.
	 * @param float $longitude1 First longitude in decimal degrees.
	 * @param float $latitude2 Second latitude in decimal degrees.
	 * @param float $longitude2 Second longitude in decimal degrees.
	 * @param int $earth_radius Earth radius in kilometers.
	 * @return float Distance in kilometers.
	 */
	public static function haversine_great_circle_distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2, int $earth_radius=6371){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		$lat_from=deg2rad($latitude1);
		$lon_from=deg2rad($longitude1);
		$lat_to=deg2rad($latitude2);
		$lon_to=deg2rad($longitude2);
		$lat_delta=$lat_to-$lat_from;
		$lon_delta=$lon_to-$lon_from;
		$angle=2*asin(sqrt(pow(sin($lat_delta / 2), 2)+cos($lat_from)*cos($lat_to)*pow(sin($lon_delta/2), 2)));
		return $angle*$earth_radius;
	}

	/**
	 * Calculates ellipsoidal distance with Vincenty's inverse formula.
	 *
	 * Uses WGS-84 defaults. If the iterative solution does not converge, the
	 * method returns 0.0 as the legacy failure value.
	 *
	 * @param float $latitude1 First latitude in decimal degrees.
	 * @param float $longitude1 First longitude in decimal degrees.
	 * @param float $latitude2 Second latitude in decimal degrees.
	 * @param float $longitude2 Second longitude in decimal degrees.
	 * @param int $a Semi-major axis in meters.
	 * @param float $f Ellipsoid flattening.
	 * @return float Distance in kilometers.
	 */
	public static function vincenty_great_circle_distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2, int $a=6378137, float $f=1/298.257223563){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if($latitude1===$latitude2 && $longitude1===$longitude2){
			return 0.0;
		}
		$b=(1-$f)*$a;
		$L=deg2rad($longitude2-$longitude1);
		$U1=atan((1-$f)*tan(deg2rad($latitude1)));
		$U2=atan((1-$f)*tan(deg2rad($latitude2)));
		$sin_u1=sin($U1);
		$cos_u1=cos($U1);
		$sin_u2=sin($U2);
		$cos_u2=cos($U2);
		$lambda=$L;
		$lambda_p=0;
		$iter_limit=100;
		while(abs($lambda-$lambda_p)>1e-12 && --$iter_limit>0){
			$sin_lambda=sin($lambda);
			$cos_lambda=cos($lambda);
			$sin_sigma=sqrt(($cos_u2*$sin_lambda)**2+($cos_u1*$sin_u2-$sin_u1*$cos_u2*$cos_lambda)**2);
			if($sin_sigma==0.0){
				return 0.0;
			}
			$cos_sigma=$sin_u1*$sin_u2+$cos_u1*$cos_u2*$cos_lambda;
			$sigma=atan2($sin_sigma, $cos_sigma);
			$sin_alpha=$cos_u1*$cos_u2*$sin_lambda/$sin_sigma;
			$cos2_alpha=1-$sin_alpha**2;
			$cos2_sigma_m=$cos2_alpha!=0.0
				? $cos_sigma-2*$sin_u1*$sin_u2/$cos2_alpha
				: 0.0;
			$C=$f/16*$cos2_alpha*(4+$f*(4-3*$cos2_alpha));
			$lambda_p=$lambda;
			$lambda=$L+(1-$C)*$f*$sin_alpha*($sigma+$C*$sin_sigma*($cos2_sigma_m+$C*$cos_sigma*(-1+2*$cos2_sigma_m**2)));
		}
		if($iter_limit==0){
			return 0.0;
		}
		$u2=$cos2_alpha*($a**2-$b**2)/($b**2);
		$A=1+$u2/16384*(4096+$u2*(-768+$u2*(320-175*$u2)));
		$B=$u2/1024*(256+$u2*(-128+$u2*(74-47*$u2)));
		$delta_sigma=$B*$sin_sigma*($cos2_sigma_m+$B/4*($cos_sigma*(-1+2*$cos2_sigma_m**2)-$B/6*$cos2_sigma_m*(-3+4*$sin_sigma**2)*(-3+4*$cos2_sigma_m**2)));
		$s=$b*$A*($sigma-$delta_sigma);
		return $s/1000;
	}

}
