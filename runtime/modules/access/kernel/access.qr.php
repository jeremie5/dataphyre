<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Renders small byte-mode QR codes as inline SVG data URIs.
 *
 * The renderer supports QR versions 1 through 10 at error-correction level L,
 * encodes raw bytes, generates Reed-Solomon error correction, lays out finder,
 * timing, alignment, format, version, and data modules, applies mask pattern
 * 000, and returns an embeddable SVG image without external QR dependencies.
 */
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

	/**
	 * Encodes text into a QR-code SVG data URI.
	 *
	 * Empty text, oversized byte payloads, or internal encoding failures return
	 * false so callers can fall back to a non-QR enrollment path.
	 *
	 * @param string $text Byte payload to encode in QR byte mode.
	 * @param int $width SVG width and height clamped to a practical display range.
	 * @param int $margin Quiet-zone modules around the QR symbol.
	 * @return string|false Base64 SVG data URI, or false when the payload cannot be encoded.
	 */
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

	/**
	 * Selects the smallest supported QR version for a byte payload.
	 *
	 * Versions are limited to 1 through 10 at error-correction level L. A null
	 * result means the payload exceeds the renderer's intentionally small inline
	 * QR scope.
	 *
	 * @param int $byte_length Payload length in bytes.
	 * @return ?int QR version number, or null when unsupported.
	 */
	private static function select_version(int $byte_length): ?int {
		for($version=1; $version<=10; $version++){
			if($byte_length<=self::byte_capacity($version)){
				return $version;
			}
		}
		return null;
	}

	/**
	 * Calculates byte-mode payload capacity for a supported version.
	 *
	 * Capacity is derived from total codewords minus level-L error correction
	 * codewords, then subtracts byte-mode and character-count header bits.
	 *
	 * @param int $version QR version 1 through 10.
	 * @return int Maximum raw byte payload length.
	 */
	private static function byte_capacity(int $version): int {
		$total_codewords=self::TOTAL_CODEWORDS[$version];
		$ec_codewords=self::EC_CODEWORDS_L[$version];
		$data_bits=($total_codewords-$ec_codewords)*8;
		$reserved_bits=4+($version<=9 ? 8 : 16);
		return (int)floor(($data_bits-$reserved_bits)/8);
	}

	/**
	 * Encodes raw text into interleaved QR data and error-correction codewords.
	 *
	 * The bit stream uses byte mode, writes the version-appropriate character
	 * count, appends terminator and pad bits, then alternates the standard pad
	 * bytes until the data capacity is filled.
	 *
	 * @param string $text Raw byte payload.
	 * @param int $version Selected QR version.
	 * @return array<int,int>|false Interleaved codewords, or false if packing fails.
	 */
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

	/**
	 * Appends the high-order bits of an integer to the codeword buffer.
	 *
	 * Bits are emitted most-significant first, matching QR segment header and byte
	 * payload ordering.
	 *
	 * @param array<int,int> $buffer Mutable codeword byte buffer.
	 * @param int $bit_length Current bit length, advanced in place.
	 * @param int $value Integer value to write.
	 * @param int $length Number of bits to append.
	 */
	private static function put_bits(array &$buffer, int &$bit_length, int $value, int $length): void {
		for($i=0; $i<$length; $i++){
			self::put_bit($buffer, $bit_length, (($value >> ($length-$i-1)) & 1)===1);
		}
	}

	/**
	 * Appends one bit to the codeword byte buffer.
	 *
	 * Bytes are allocated on demand and filled from bit 7 down to bit 0, which
	 * preserves the QR codeword bit order expected by matrix placement.
	 *
	 * @param array<int,int> $buffer Mutable codeword byte buffer.
	 * @param int $bit_length Current bit length, advanced in place.
	 * @param bool $bit Whether the appended bit is dark/one.
	 */
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

	/**
	 * Splits data codewords into QR blocks and interleaves data and EC bytes.
	 *
	 * Version metadata determines block count and unequal group sizes. Each block
	 * receives Reed-Solomon bytes, then data bytes and error-correction bytes are
	 * interleaved according to QR symbol ordering.
	 *
	 * @param array<int,int> $buffer Data codewords for the selected version.
	 * @param int $version QR version.
	 * @return array<int,int>|false Complete symbol codeword stream, or false on size mismatch.
	 */
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

	/**
	 * Produces Reed-Solomon error-correction bytes for one data block.
	 *
	 * Data is shifted by the generator degree, divided by the generator
	 * polynomial, and left-padded when the remainder is shorter than the required
	 * EC byte count.
	 *
	 * @param array<int,int> $data Data codewords in one block.
	 * @param int $degree Number of error-correction codewords required.
	 * @return array<int,int> Error-correction codewords.
	 */
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

	/**
	 * Builds the Reed-Solomon generator polynomial for a QR EC block.
	 *
	 * The polynomial is the product of degree terms over GF(256), using the QR
	 * primitive exponent sequence.
	 *
	 * @param int $degree Number of error-correction codewords required.
	 * @return array<int,int> Generator polynomial coefficients.
	 */
	private static function generate_ec_polynomial(int $degree): array {
		$poly=[1];
		for($i=0; $i<$degree; $i++){
			$poly=self::polynomial_mul($poly, [1, self::gf_exp($i)]);
		}
		return $poly;
	}

	/**
	 * Multiplies two polynomials over the QR GF(256) field.
	 *
	 * Coefficients are combined with field multiplication and XOR addition, which
	 * is the arithmetic used by QR Reed-Solomon encoding.
	 *
	 * @param array<int,int> $left Left polynomial coefficients.
	 * @param array<int,int> $right Right polynomial coefficients.
	 * @return array<int,int> Product polynomial coefficients.
	 */
	private static function polynomial_mul(array $left, array $right): array {
		$result=array_fill(0, count($left)+count($right)-1, 0);
		foreach($left as $i=>$left_coeff){
			foreach($right as $j=>$right_coeff){
				$result[$i+$j]^=self::gf_mul($left_coeff, $right_coeff);
			}
		}
		return $result;
	}

	/**
	 * Computes the Reed-Solomon polynomial remainder over GF(256).
	 *
	 * Leading zero coefficients are discarded after each subtraction step so the
	 * loop terminates when the dividend degree is lower than the divisor degree.
	 *
	 * @param array<int,int> $dividend Dividend polynomial coefficients.
	 * @param array<int,int> $divisor Divisor polynomial coefficients.
	 * @return array<int,int> Remainder polynomial coefficients.
	 */
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

	/**
	 * Initializes GF(256) lookup tables used by QR Reed-Solomon math.
	 *
	 * Tables are built lazily with primitive polynomial 0x11D and duplicated
	 * exponent entries so multiplication can avoid explicit modulo operations.
	 */
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

	/**
	 * Reads a value from the GF(256) exponent table.
	 *
	 * The table is lazily initialized and accepts indexes within the duplicated
	 * exponent range used by polynomial generation and multiplication.
	 *
	 * @param int $value Exponent table index.
	 * @return int GF(256) field value.
	 */
	private static function gf_exp(int $value): int {
		self::init_galois_tables();
		return self::$gf_exp[$value];
	}

	/**
	 * Multiplies two GF(256) field values.
	 *
	 * Zero operands short-circuit to zero. Non-zero values are multiplied through
	 * log/exp tables using XOR addition semantics in caller polynomial code.
	 *
	 * @param int $left Left field value.
	 * @param int $right Right field value.
	 * @return int Product field value.
	 */
	private static function gf_mul(int $left, int $right): int {
		if($left===0 || $right===0){
			return 0;
		}
		self::init_galois_tables();
		return self::$gf_exp[self::$gf_log[$left]+self::$gf_log[$right]];
	}

	/**
	 * Places QR function patterns and data codewords into a symbol matrix.
	 *
	 * Reserved modules are tracked separately so finder, timing, alignment, format,
	 * and version patterns are protected while data is written and mask pattern 000
	 * is applied only to data modules.
	 *
	 * @param array<int,int> $codewords Complete interleaved codeword stream.
	 * @param int $version QR version.
	 * @return array{data:array<int,int>,size:int}|false Matrix payload, or false on failure.
	 */
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

	/**
	 * Calculates the module width and height for a QR version.
	 *
	 * QR symbols grow by four modules per version from the version-1 base size.
	 *
	 * @param int $version QR version.
	 * @return int Symbol size in modules.
	 */
	private static function symbol_size(int $version): int {
		return ($version*4)+17;
	}

	/**
	 * Writes the three finder patterns and their quiet separators.
	 *
	 * The one-module separator around each finder is reserved with light modules,
	 * preventing later data placement and masking from modifying finder regions.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Mutable reservation map.
	 * @param int $size Symbol size in modules.
	 * @param int $version QR version.
	 */
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

	/**
	 * Writes the horizontal and vertical QR timing patterns.
	 *
	 * Alternating modules are placed between finder regions and reserved before
	 * payload bits are written.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Mutable reservation map.
	 * @param int $size Symbol size in modules.
	 */
	private static function setup_timing_pattern(array &$data, array &$reserved, int $size): void {
		for($offset=8; $offset<$size-8; $offset++){
			$is_dark=$offset%2===0;
			self::set_module($data, $reserved, $size, $offset, 6, $is_dark, true);
			self::set_module($data, $reserved, $size, 6, $offset, $is_dark, true);
		}
	}

	/**
	 * Writes alignment patterns required by the selected QR version.
	 *
	 * Version 1 has no alignment patterns. Higher versions use the computed
	 * coordinate grid with finder-overlapping coordinates removed.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Mutable reservation map.
	 * @param int $size Symbol size in modules.
	 * @param int $version QR version.
	 */
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

	/**
	 * Computes alignment pattern centers for the selected QR version.
	 *
	 * Coordinates follow the QR spacing formula and exclude the three finder
	 * corners. Returned pairs are matrix row and column module indexes.
	 *
	 * @param int $version QR version.
	 * @return array<int,array{0:int,1:int}> Alignment pattern center coordinates.
	 */
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

	/**
	 * Writes duplicated version information blocks for versions 7 and higher.
	 *
	 * The encoded BCH bits are mirrored into the top-right and bottom-left
	 * version information regions and reserved before data placement.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Mutable reservation map.
	 * @param int $size Symbol size in modules.
	 * @param int $version QR version.
	 */
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

	/**
	 * Writes duplicated QR format information and the fixed dark module.
	 *
	 * Format bits encode error-correction level and mask pattern, then occupy the
	 * reserved modules around finder/timing regions required by the QR layout.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Mutable reservation map.
	 * @param int $size Symbol size in modules.
	 * @param int $error_correction_level_bit Encoded QR error-correction level bits.
	 * @param int $mask_pattern QR mask pattern id.
	 */
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

	/**
	 * Places data and error-correction bits into the matrix zig-zag path.
	 *
	 * Reserved modules are skipped, column 6 is bypassed for the timing pattern,
	 * and bits are consumed from each codeword most-significant first.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Reservation map.
	 * @param int $size Symbol size in modules.
	 * @param array<int,int> $codewords Complete interleaved codeword stream.
	 */
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

	/**
	 * Applies QR mask pattern 000 to non-reserved data modules.
	 *
	 * Pattern 000 flips modules where row plus column is even. Function and
	 * metadata modules are protected by the reservation map.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Reservation map.
	 * @param int $size Symbol size in modules.
	 */
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

	/**
	 * Writes one module and optionally marks it reserved.
	 *
	 * Matrix arrays are one-dimensional row-major buffers, so row and column are
	 * converted to a stable index shared by data and reservation maps.
	 *
	 * @param array<int,int> $data Mutable matrix module values.
	 * @param array<int,int> $reserved Mutable reservation map.
	 * @param int $size Symbol size in modules.
	 * @param int $row Row index.
	 * @param int $col Column index.
	 * @param bool $is_dark Whether the module is dark.
	 * @param bool $is_reserved Whether later data/masking steps must skip it.
	 */
	private static function set_module(array &$data, array &$reserved, int $size, int $row, int $col, bool $is_dark, bool $is_reserved): void {
		$index=($row*$size)+$col;
		$data[$index]=$is_dark ? 1 : 0;
		if($is_reserved){
			$reserved[$index]=1;
		}
	}

	/**
	 * Checks whether a matrix module is reserved for QR function metadata.
	 *
	 * Reserved modules are excluded from data placement and mask application.
	 *
	 * @param array<int,int> $reserved Reservation map.
	 * @param int $size Symbol size in modules.
	 * @param int $row Row index.
	 * @param int $col Column index.
	 * @return bool Whether the module is reserved.
	 */
	private static function is_reserved(array $reserved, int $size, int $row, int $col): bool {
		return !empty($reserved[($row*$size)+$col]);
	}

	/**
	 * Encodes QR format bits with BCH correction and the standard format mask.
	 *
	 * The result carries error-correction level and mask pattern information for
	 * placement by setup_format_info().
	 *
	 * @param int $error_correction_level_bit Encoded QR error-correction level bits.
	 * @param int $mask_pattern QR mask pattern id.
	 * @return int Fifteen-bit QR format information value.
	 */
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

	/**
	 * Encodes QR version information with BCH correction.
	 *
	 * Version information is only placed for versions 7 and higher, but this
	 * helper is version-agnostic and returns the 18-bit encoded payload.
	 *
	 * @param int $version QR version.
	 * @return int Eighteen-bit QR version information value.
	 */
	private static function version_bits(int $version): int {
		$generator=(1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | 1;
		$generator_bch=self::bch_digit($generator);
		$value=$version << 12;
		while((self::bch_digit($value)-$generator_bch)>=0){
			$value^=($generator << (self::bch_digit($value)-$generator_bch));
		}
		return ($version << 12) | $value;
	}

	/**
	 * Counts the significant bit length of a BCH working value.
	 *
	 * The value is used to align generator polynomials during QR format and
	 * version information division.
	 *
	 * @param int $value BCH working value.
	 * @return int Number of significant bits.
	 */
	private static function bch_digit(int $value): int {
		$digit=0;
		while($value!==0){
			$digit++;
			$value>>=1;
		}
		return $digit;
	}

	/**
	 * Renders the QR matrix as a compact inline SVG.
	 *
	 * Dark module runs are compressed into one stroke path, the quiet-zone margin
	 * is included in the viewBox, and crispEdges preserves scanner-friendly module
	 * boundaries at small display sizes.
	 *
	 * @param array<int,int> $data Matrix module values in row-major order.
	 * @param int $size Symbol size in modules.
	 * @param int $margin Quiet-zone margin in modules.
	 * @param int $width SVG display width and height in pixels.
	 * @return string SVG document markup.
	 */
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
