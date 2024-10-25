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

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

class geoposition{
	
	public static function reformat_postal_code(string $country, string $subdivision='*', string $postal_code): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$postal_code=trim($postal_code);
		if(false!==$row=sql_select(
			$S="*", 
			$L="dataphyre.postal_codes_regex", 
			$P="WHERE country=? AND (subdivision=? OR subdivision='*')", 
			$V=array($country, $subdivision), 
			$F=false, 
			$C=true
		)){
			if(!empty($row['reformatting_regex'])){
				$regex=explode('␞', $row['reformatting_regex']);
				$result=preg_replace($regex[0], $regex[1], $postal_code);
			}
			$rules=array_flip(explode(',', $row['reformatting_rules']));
			if(isset($rules['force_uppercase'])){
				$result=strtoupper($result);
			}
			elseif(isset($rules['force_lowercase'])){
				$result=strtolower($result);
			}
			if(isset($rules['force_uppercase'])){
				$result=strtoupper($result);
			}
			if(isset($rules['digits_only'])){
				$result=preg_replace('/[a-zA-Z]+/', '', $result);
			}
			if(isset($rules['letters_only'])){
				$result=preg_replace('/\D/', '', $result);
			}
			return $result;
		}
		return $postal_code;
	}

	public static function validate_postal_code(string $country, string $subdivision='*', string $postal_code): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(false!==$row=sql_select(
			$S="*",
			$L="dataphyre.postal_codes_regex", 
			$P="WHERE country=? AND (subdivision=? OR subdivision='*')", 
			$V=array($country, $subdivision), 
			$F=false, 
			$C=true
		)){
			if(0===preg_match($row['validation_regex'], $postal_code)){
				echo 'here';
				return false;
			}
		}
		return true;
	}
	
	public static function get_position_for_postal_code(string $country, string $postal_code=''){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		while(true){
			if($row===false && is_numeric($postal_code) || strlen($postal_code)<2)break;
			if(false!==$row=sql_select(
				$S="*", 
				$L="dataphyre.postal_codes", 
				$P="WHERE country=? AND postal_code LIKE CONCAT(?, '%')", 
				$V=array($country, $postal_code), 
				$F=false
			)){
				return ['lat'=>$row['latitude'], 'long'=>$row['longitude']];
			}
			$postal_code=substr($postal_code, 0, -1);
		}
		return false;
	}
	/*
	public static function get_position_for_subdivision(string $country, string $subdivision='', $count=15){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(false!==$rows=sql_select(
			$S="latitude, longitude", 
			$L="dataphyre.postal_codes", 
			$P="WHERE country=? AND subdivision=? ORDER BY random() LIMIT ?", 
			$V=array($country, $subdivision, $count), 
			$F=true
		)){
			$totalLat=0;
			$totalLong=0;
			$count=count($rows); 
			foreach($rows as $row){
				$totalLat+=$row['latitude'];
				$totalLong+=$row['longitude'];
			}
			return['latitude'=>$totalLat/$count, 'longitude'=>$totalLong/$count];
		}
		return false;
	}
	*/

	public static function get_position_for_subdivision(string $country, string $subdivision): array|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		static $subdivision_positions;
		if(!isset($subdivision_positions)){
			$subdivision_positions=json_decode(file_get_contents(__DIR__.'/subdivision_positions.json'), true);
		}
		if(isset($subdivision_positions[$country][$subdivision])){
			return $subdivision_positions[$country][$subdivision];
		}
		return false;
	}
	
	public static function distance_between_subdivisions(string $country1, string $subdivision1, string $country2, string $subdivision2, bool $better_precision=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!empty($position1) && !empty($position2)){
			if($better_precision){
				return geoposition::vincenty_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
			}
			return geoposition::haversine_great_circle_distance($position1['latitude'], $position1['longitude'], $position2['latitude'], $position2['longitude']);
		}
		return false;
	}
	
	public static function haversine_great_circle_distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2, int $earthRadius=6371){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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
			$cosSigma=$sinU1*$sinU2+$cosU1*$cosU2*$cosLambda;
			$sigma=atan2($sinSigma, $cosSigma);
			$sinAlpha=$cosU1*$cosU2*$sinLambda/$sinSigma;
			$cos2Alpha=1-$sinAlpha**2;
			$cos2SigmaM=$cosSigma-2*$sinU1*$sinU2/$cos2Alpha;
			$C=$f/16*$cos2Alpha*(4+$f*(4-3*$cos2Alpha));
			$lambdaP=$lambda;
			$lambda=$L+(1-$C)*$f*$sinAlpha*($sigma+$C*$sinSigma*($cos2SigmaM+$C*$cosSigma*(-1+2*$cos2SigmaM**2)));
		}
		if($iterLimit==0)return 0;
		$u2=$cos2Alpha*($a**2-$b**2)/($b**2);
		$A=1+$u2/16384*(4096+$u2*(-768+$u2*(320-175*$u2)));
		$B=$u2/1024*(256+$u2*(-128+$u2*(74-47*$u2)));
		$deltaSigma=$B*$sinSigma*($cos2SigmaM+$B/4*($cosSigma*(-1+2*$cos2SigmaM**2)-$B/6*$cos2SigmaM*(-3+4*$sinSigma**2)*(-3+4*$cos2SigmaM**2)));
		$s=$b*$A*($sigma-$deltaSigma);
		return $s/1000;
	}

}