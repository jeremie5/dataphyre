<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

class access_qr_renderer{

	private const ERROR_CORRECTION_LEVEL_L=1;
	private const MASK_PATTERN_000=0;
	private const MODE_BYTE=0b0100;
	private const PAD_BYTES=[0xEC, 0x11];
	private const TOTAL_CODEWORDS=[
		1=>26,
		2=>44,
		3=>70,
		4=>100,
		5=>134,
		6=>172,
		7=>196,
		8=>242,
		9=>292,
		10=>346,
	];
	private const EC_BLOCKS_L=[
		1=>1,
		2=>1,
		3=>1,
		4=>1,
		5=>1,
		6=>2,
		7=>2,
		8=>2,
		9=>2,
		10=>4,
	];
	private const EC_CODEWORDS_L=[
		1=>7,
		2=>10,
		3=>15,
		4=>20,
		5=>26,
		6=>36,
		7=>40,
		8=>48,
		9=>60,
		10=>72,
	];
	private static ?array $gf_exp=null;
	private static ?array $gf_log=null;

	public static function svg_data_uri(string $text, int $width=200, int $margin=4): string|false {
		$text=(string)$text;
		if($text===''){
			return false;
		}
		$version=self::select_version(strlen($text));
		if($version===null){
			return false;
		}
		$codewords=self::create_codewords($text, $version);
		if($codewords===false){
			return false;
		}
		$matrix=self::build_matrix($codewords, $version);
		if($matrix===false){
			return false;
		}
		$svg=self::render_svg($matrix['data'], $matrix['size'], max(0, $margin), max(64, min(512, $width)));
		return 'data:image/svg+xml;base64,'.base64_encode($svg);
	}

	private static function select_version(int $byte_length): ?int {
		for($version=1; $version<=10; $version++){
			if($byte_length<=self::byte_capacity($version)){
				return $version;
			}
		}
		return null;
	}

	private static function byte_capacity(int $version): int {
		$total_codewords=self::TOTAL_CODEWORDS[$version];
		$ec_codewords=self::EC_CODEWORDS_L[$version];
		$data_bits=($total_codewords-$ec_codewords)*8;
		$reserved_bits=4+($version<=9 ? 8 : 16);
		return (int)floor(($data_bits-$reserved_bits)/8);
	}

	private static function create_codewords(string $text, int $version): array|false {
		$data_codewords=self::TOTAL_CODEWORDS[$version]-self::EC_CODEWORDS_L[$version];
		$buffer=[];
		$bit_length=0;
		self::put_bits($buffer, $bit_length, self::MODE_BYTE, 4);
		self::put_bits($buffer, $bit_length, strlen($text), $version<=9 ? 8 : 16);
		$bytes=array_values(unpack('C*', $text));
		foreach($bytes as $byte){
			self::put_bits($buffer, $bit_length, $byte, 8);
		}
		$total_data_bits=$data_codewords*8;
		$terminator=min(4, max(0, $total_data_bits-$bit_length));
		if($terminator>0){
			self::put_bits($buffer, $bit_length, 0, $terminator);
		}
		while($bit_length%8!==0){
			self::put_bit($buffer, $bit_length, false);
		}
		$pad_index=0;
		while(count($buffer)<$data_codewords){
			$buffer[]=self::PAD_BYTES[$pad_index%2];
			$pad_index++;
		}
		if(count($buffer)!==$data_codewords){
			return false;
		}
		return self::interleave_codewords($buffer, $version);
	}

	private static function put_bits(array &$buffer, int &$bit_length, int $value, int $length): void {
		for($i=0; $i<$length; $i++){
			self::put_bit($buffer, $bit_length, (($value >> ($length-$i-1)) & 1)===1);
		}
	}

	private static function put_bit(array &$buffer, int &$bit_length, bool $bit): void {
		$byte_index=intdiv($bit_length, 8);
		if(!isset($buffer[$byte_index])){
			$buffer[$byte_index]=0;
		}
		if($bit){
			$buffer[$byte_index]|=(0x80 >> ($bit_length%8));
		}
		$bit_length++;
	}

	private static function interleave_codewords(array $buffer, int $version): array|false {
		$total_codewords=self::TOTAL_CODEWORDS[$version];
		$ec_total_codewords=self::EC_CODEWORDS_L[$version];
		$data_total_codewords=$total_codewords-$ec_total_codewords;
		$ec_total_blocks=self::EC_BLOCKS_L[$version];
		$blocks_in_group_2=$total_codewords%$ec_total_blocks;
		$blocks_in_group_1=$ec_total_blocks-$blocks_in_group_2;
		$total_codewords_in_group_1=intdiv($total_codewords, $ec_total_blocks);
		$data_codewords_in_group_1=intdiv($data_total_codewords, $ec_total_blocks);
		$data_codewords_in_group_2=$data_codewords_in_group_1+1;
		$ec_count=$total_codewords_in_group_1-$data_codewords_in_group_1;
		$offset=0;
		$data_blocks=[];
		$ec_blocks=[];
		$max_data_size=0;
		for($block_index=0; $block_index<$ec_total_blocks; $block_index++){
			$data_size=$block_index<$blocks_in_group_1 ? $data_codewords_in_group_1 : $data_codewords_in_group_2;
			$data_blocks[$block_index]=array_slice($buffer, $offset, $data_size);
			$ec_blocks[$block_index]=self::reed_solomon_encode($data_blocks[$block_index], $ec_count);
			$offset+=$data_size;
			$max_data_size=max($max_data_size, $data_size);
		}
		$interleaved=[];
		for($i=0; $i<$max_data_size; $i++){
			for($block_index=0; $block_index<$ec_total_blocks; $block_index++){
				if(isset($data_blocks[$block_index][$i])){
					$interleaved[]=$data_blocks[$block_index][$i];
				}
			}
		}
		for($i=0; $i<$ec_count; $i++){
			for($block_index=0; $block_index<$ec_total_blocks; $block_index++){
				$interleaved[]=$ec_blocks[$block_index][$i];
			}
		}
		return count($interleaved)===$total_codewords ? $interleaved : false;
	}

	private static function reed_solomon_encode(array $data, int $degree): array {
		$generator=self::generate_ec_polynomial($degree);
		$padded=array_merge($data, array_fill(0, $degree, 0));
		$remainder=self::polynomial_mod($padded, $generator);
		$pad_count=$degree-count($remainder);
		if($pad_count>0){
			return array_merge(array_fill(0, $pad_count, 0), $remainder);
		}
		return $remainder;
	}

	private static function generate_ec_polynomial(int $degree): array {
		$poly=[1];
		for($i=0; $i<$degree; $i++){
			$poly=self::polynomial_mul($poly, [1, self::gf_exp($i)]);
		}
		return $poly;
	}

	private static function polynomial_mul(array $left, array $right): array {
		$result=array_fill(0, count($left)+count($right)-1, 0);
		foreach($left as $i=>$left_coeff){
			foreach($right as $j=>$right_coeff){
				$result[$i+$j]^=self::gf_mul($left_coeff, $right_coeff);
			}
		}
		return $result;
	}

	private static function polynomial_mod(array $dividend, array $divisor): array {
		$result=array_values($dividend);
		while((count($result)-count($divisor))>=0){
			$coefficient=$result[0];
			for($i=0; $i<count($divisor); $i++){
				$result[$i]^=self::gf_mul($divisor[$i], $coefficient);
			}
			while($result!==[] && $result[0]===0){
				array_shift($result);
			}
		}
		return $result;
	}

	private static function init_galois_tables(): void {
		if(self::$gf_exp!==null && self::$gf_log!==null){
			return;
		}
		self::$gf_exp=array_fill(0, 512, 0);
		self::$gf_log=array_fill(0, 256, 0);
		$x=1;
		for($i=0; $i<255; $i++){
			self::$gf_exp[$i]=$x;
			self::$gf_log[$x]=$i;
			$x<<=1;
			if(($x & 0x100)!==0){
				$x^=0x11D;
			}
		}
		for($i=255; $i<512; $i++){
			self::$gf_exp[$i]=self::$gf_exp[$i-255];
		}
	}

	private static function gf_exp(int $value): int {
		self::init_galois_tables();
		return self::$gf_exp[$value];
	}

	private static function gf_mul(int $left, int $right): int {
		if($left===0 || $right===0){
			return 0;
		}
		self::init_galois_tables();
		return self::$gf_exp[self::$gf_log[$left]+self::$gf_log[$right]];
	}

	private static function build_matrix(array $codewords, int $version): array|false {
		$size=self::symbol_size($version);
		$data=array_fill(0, $size*$size, 0);
		$reserved=array_fill(0, $size*$size, 0);
		self::setup_finder_patterns($data, $reserved, $size, $version);
		self::setup_timing_pattern($data, $reserved, $size);
		self::setup_alignment_patterns($data, $reserved, $size, $version);
		self::setup_format_info($data, $reserved, $size, self::ERROR_CORRECTION_LEVEL_L, self::MASK_PATTERN_000);
		if($version>=7){
			self::setup_version_info($data, $reserved, $size, $version);
		}
		self::setup_data($data, $reserved, $size, $codewords);
		self::apply_mask_pattern_000($data, $reserved, $size);
		return [
			'data'=>$data,
			'size'=>$size,
		];
	}

	private static function symbol_size(int $version): int {
		return ($version*4)+17;
	}

	private static function setup_finder_patterns(array &$data, array &$reserved, int $size, int $version): void {
		$positions=[
			[0, 0],
			[$size-7, 0],
			[0, $size-7],
		];
		foreach($positions as [$row, $col]){
			for($r=-1; $r<=7; $r++){
				if($row+$r<=-1 || $row+$r>=$size){
					continue;
				}
				for($c=-1; $c<=7; $c++){
					if($col+$c<=-1 || $col+$c>=$size){
						continue;
					}
					$is_dark=(
						($r>=0 && $r<=6 && ($c===0 || $c===6))
						|| ($c>=0 && $c<=6 && ($r===0 || $r===6))
						|| ($r>=2 && $r<=4 && $c>=2 && $c<=4)
					);
					self::set_module($data, $reserved, $size, $row+$r, $col+$c, $is_dark, true);
				}
			}
		}
	}

	private static function setup_timing_pattern(array &$data, array &$reserved, int $size): void {
		for($offset=8; $offset<$size-8; $offset++){
			$is_dark=$offset%2===0;
			self::set_module($data, $reserved, $size, $offset, 6, $is_dark, true);
			self::set_module($data, $reserved, $size, 6, $offset, $is_dark, true);
		}
	}

	private static function setup_alignment_patterns(array &$data, array &$reserved, int $size, int $version): void {
		foreach(self::alignment_positions($version) as [$row, $col]){
			for($r=-2; $r<=2; $r++){
				for($c=-2; $c<=2; $c++){
					$is_dark=$r===-2 || $r===2 || $c===-2 || $c===2 || ($r===0 && $c===0);
					self::set_module($data, $reserved, $size, $row+$r, $col+$c, $is_dark, true);
				}
			}
		}
	}

	private static function alignment_positions(int $version): array {
		if($version===1){
			return [];
		}
		$size=self::symbol_size($version);
		$position_count=(int)floor($version/7)+2;
		$interval=$size===145 ? 26 : (int)(ceil(($size-13)/(2*$position_count-2))*2);
		$positions=[$size-7];
		for($i=1; $i<$position_count-1; $i++){
			$positions[$i]=$positions[$i-1]-$interval;
		}
		$positions[]=6;
		$positions=array_reverse($positions);
		$coords=[];
		$last_index=count($positions)-1;
		foreach($positions as $i=>$row){
			foreach($positions as $j=>$col){
				if(($i===0 && $j===0) || ($i===0 && $j===$last_index) || ($i===$last_index && $j===0)){
					continue;
				}
				$coords[]=[$row, $col];
			}
		}
		return $coords;
	}

	private static function setup_version_info(array &$data, array &$reserved, int $size, int $version): void {
		$bits=self::version_bits($version);
		for($i=0; $i<18; $i++){
			$row=intdiv($i, 3);
			$col=($i%3)+$size-11;
			$is_dark=(($bits >> $i) & 1)===1;
			self::set_module($data, $reserved, $size, $row, $col, $is_dark, true);
			self::set_module($data, $reserved, $size, $col, $row, $is_dark, true);
		}
	}

	private static function setup_format_info(array &$data, array &$reserved, int $size, int $error_correction_level_bit, int $mask_pattern): void {
		$bits=self::format_bits($error_correction_level_bit, $mask_pattern);
		for($i=0; $i<15; $i++){
			$is_dark=(($bits >> $i) & 1)===1;
			if($i<6){
				self::set_module($data, $reserved, $size, $i, 8, $is_dark, true);
			}
			elseif($i<8){
				self::set_module($data, $reserved, $size, $i+1, 8, $is_dark, true);
			}
			else
			{
				self::set_module($data, $reserved, $size, $size-15+$i, 8, $is_dark, true);
			}
			if($i<8){
				self::set_module($data, $reserved, $size, 8, $size-$i-1, $is_dark, true);
			}
			elseif($i<9){
				self::set_module($data, $reserved, $size, 8, 15-$i, $is_dark, true);
			}
			else
			{
				self::set_module($data, $reserved, $size, 8, 14-$i, $is_dark, true);
			}
		}
		self::set_module($data, $reserved, $size, $size-8, 8, true, true);
	}

	private static function setup_data(array &$data, array &$reserved, int $size, array $codewords): void {
		$direction=-1;
		$row=$size-1;
		$bit_index=7;
		$byte_index=0;
		for($col=$size-1; $col>0; $col-=2){
			if($col===6){
				$col--;
			}
			while(true){
				for($c=0; $c<2; $c++){
					$target_col=$col-$c;
					if(self::is_reserved($reserved, $size, $row, $target_col)){
						continue;
					}
					$is_dark=false;
					if($byte_index<count($codewords)){
						$is_dark=((($codewords[$byte_index] >> $bit_index) & 1)===1);
					}
					self::set_module($data, $reserved, $size, $row, $target_col, $is_dark, false);
					$bit_index--;
					if($bit_index===-1){
						$byte_index++;
						$bit_index=7;
					}
				}
				$row+=$direction;
				if($row<0 || $row>=$size){
					$row-=$direction;
					$direction=-$direction;
					break;
				}
			}
		}
	}

	private static function apply_mask_pattern_000(array &$data, array $reserved, int $size): void {
		for($row=0; $row<$size; $row++){
			for($col=0; $col<$size; $col++){
				if(self::is_reserved($reserved, $size, $row, $col)){
					continue;
				}
				if((($row+$col)%2)===0){
					$index=($row*$size)+$col;
					$data[$index]=$data[$index] ^ 1;
				}
			}
		}
	}

	private static function set_module(array &$data, array &$reserved, int $size, int $row, int $col, bool $is_dark, bool $is_reserved): void {
		$index=($row*$size)+$col;
		$data[$index]=$is_dark ? 1 : 0;
		if($is_reserved){
			$reserved[$index]=1;
		}
	}

	private static function is_reserved(array $reserved, int $size, int $row, int $col): bool {
		return !empty($reserved[($row*$size)+$col]);
	}

	private static function format_bits(int $error_correction_level_bit, int $mask_pattern): int {
		$generator=(1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | 1;
		$generator_bch=self::bch_digit($generator);
		$data=(($error_correction_level_bit << 3) | $mask_pattern);
		$value=$data << 10;
		while((self::bch_digit($value)-$generator_bch)>=0){
			$value^=($generator << (self::bch_digit($value)-$generator_bch));
		}
		$mask=(1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1);
		return (($data << 10) | $value) ^ $mask;
	}

	private static function version_bits(int $version): int {
		$generator=(1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | 1;
		$generator_bch=self::bch_digit($generator);
		$value=$version << 12;
		while((self::bch_digit($value)-$generator_bch)>=0){
			$value^=($generator << (self::bch_digit($value)-$generator_bch));
		}
		return ($version << 12) | $value;
	}

	private static function bch_digit(int $value): int {
		$digit=0;
		while($value!==0){
			$digit++;
			$value>>=1;
		}
		return $digit;
	}

	private static function render_svg(array $data, int $size, int $margin, int $width): string {
		$total_size=$size+($margin*2);
		$path='';
		$move_by=0;
		$new_row=false;
		$line_length=0;
		$data_count=count($data);
		for($index=0; $index<$data_count; $index++){
			$col=$index%$size;
			$row=intdiv($index, $size);
			if($col===0 && $new_row===false){
				$new_row=true;
			}
			if(!empty($data[$index])){
				$line_length++;
				if(!($index>0 && $col>0 && !empty($data[$index-1]))){
					$path.=($new_row
						? 'M'.($col+$margin).' '.(0.5+$row+$margin)
						: 'm'.$move_by.' 0'
					);
					$move_by=0;
					$new_row=false;
				}
				if(!(($col+1)<$size && !empty($data[$index+1]))){
					$path.='h'.$line_length;
					$line_length=0;
				}
			}
			else
			{
				$move_by++;
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$width.'" viewBox="0 0 '.$total_size.' '.$total_size.'" shape-rendering="crispEdges"><path fill="#fff" d="M0 0h'.$total_size.'v'.$total_size.'H0z"/><path stroke="#000" d="'.$path.'"/></svg>';
	}
}
