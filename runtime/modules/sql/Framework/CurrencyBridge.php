<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class CurrencyBridge {

	private const CURRENCY_FACADE='Dataphyre\\Currency\\Currency';
	private const MONEY_CLASS='Dataphyre\\Currency\\Money';
	private const STORED_MONEY_CLASS='Dataphyre\\Currency\\StoredMoney';
	private const EXCHANGE_RATES_CLASS='Dataphyre\\Currency\\ExchangeRates';
	private const EXCHANGE_QUOTE_CLASS='Dataphyre\\Currency\\ExchangeQuote';

	public static function normalizeMoneyMapping(
		string $amount_column,
		?string $currency_column,
		?string $currency,
		?string $target_column,
		string $owner
	): array {
		$amount_column=self::normalizeColumn($amount_column, 'money amount', $owner);
		$target_column=$target_column===null
			? $amount_column
			: self::normalizeColumn($target_column, 'money target', $owner);
		if($currency!==null){
			return [
				'amount_column'=>$amount_column,
				'currency_column'=>null,
				'currency'=>self::normalizeCurrency($currency, $owner),
				'target_column'=>$target_column,
			];
		}
		if($currency_column===null){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				'Money mappings require either a currency column or a fixed currency.'
			);
		}
		return [
			'amount_column'=>$amount_column,
			'currency_column'=>self::normalizeColumn($currency_column, 'money currency', $owner),
			'currency'=>null,
			'target_column'=>$target_column,
		];
	}

	public static function applyMoneyMapping(array $row, array $mapping, string $owner): array {
		if(!array_key_exists($mapping['amount_column'], $row)){
			throw SqlError::missingMoneyColumn($owner, $mapping['amount_column'], 'amount', array_keys($row));
		}
		$target_column=$mapping['target_column'];
		$amount=$row[$mapping['amount_column']];
		if(self::isMoney($amount)){
			$row[$target_column]=$amount;
			return $row;
		}
		if($amount===null || (is_string($amount) && trim($amount)==='')){
			$row[$target_column]=null;
			return $row;
		}
		$currency=$mapping['currency'];
		if($currency===null){
			$currency_column=$mapping['currency_column'];
			if(!array_key_exists($currency_column, $row)){
				throw SqlError::missingMoneyColumn($owner, $currency_column, 'currency', array_keys($row));
			}
			$currency=$row[$currency_column];
		}
		if(!is_scalar($currency) || trim((string)$currency)===''){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Money hydration requires a non-empty currency value for '{$mapping['amount_column']}'."
			);
		}
		$row[$target_column]=self::money((float)$amount, (string)$currency);
		return $row;
	}

	public static function normalizeStoredMoneyMapping(array $definition, ?string $target_column, string $owner): array {
		$definition=self::expandStoredMoneyPrefixes($definition);
		$target_column=$target_column
			?? (isset($definition['target_column']) ? (string)$definition['target_column'] : null)
			?? (isset($definition['target']) ? (string)$definition['target'] : null)
			?? 'stored_money';
		return [
			'original_amount_column'=>self::normalizeColumn(
				isset($definition['original_amount_column']) ? (string)$definition['original_amount_column'] : 'original_amount',
				'stored money original amount',
				$owner
			),
			'original_currency_column'=>self::normalizeColumn(
				isset($definition['original_currency_column']) ? (string)$definition['original_currency_column'] : 'original_currency',
				'stored money original currency',
				$owner
			),
			'base_amount_column'=>self::normalizeColumn(
				isset($definition['base_amount_column']) ? (string)$definition['base_amount_column'] : 'base_amount',
				'stored money base amount',
				$owner
			),
			'base_currency_column'=>self::normalizeColumn(
				isset($definition['base_currency_column']) ? (string)$definition['base_currency_column'] : 'base_currency',
				'stored money base currency',
				$owner
			),
			'exchange_rate_column'=>self::normalizeColumn(
				isset($definition['exchange_rate_column']) ? (string)$definition['exchange_rate_column'] : 'exchange_rate',
				'stored money exchange rate',
				$owner
			),
			'exchange_source_column'=>self::normalizeColumn(
				isset($definition['exchange_source_column']) ? (string)$definition['exchange_source_column'] : 'exchange_source',
				'stored money exchange source',
				$owner
			),
			'exchange_time_column'=>self::normalizeColumn(
				isset($definition['exchange_time_column']) ? (string)$definition['exchange_time_column'] : 'exchange_time',
				'stored money exchange time',
				$owner
			),
			'exchange_base_currency_column'=>self::normalizeColumn(
				isset($definition['exchange_base_currency_column']) ? (string)$definition['exchange_base_currency_column'] : 'exchange_base_currency',
				'stored money exchange base currency',
				$owner
			),
			'base_currency'=>isset($definition['base_currency']) && trim((string)$definition['base_currency'])!==''
				? self::normalizeCurrency((string)$definition['base_currency'], $owner)
				: null,
			'target_column'=>self::normalizeColumn($target_column, 'stored money target', $owner),
		];
	}

	public static function applyStoredMoneyMapping(array $row, array $mapping, string $owner): array {
		$target_column=$mapping['target_column'];
		if(array_key_exists($target_column, $row) && self::isStoredMoney($row[$target_column])){
			return $row;
		}
		$required_columns=[
			'original amount'=>$mapping['original_amount_column'],
			'original currency'=>$mapping['original_currency_column'],
			'base amount'=>$mapping['base_amount_column'],
			'base currency'=>$mapping['base_currency_column'],
			'exchange rate'=>$mapping['exchange_rate_column'],
			'exchange source'=>$mapping['exchange_source_column'],
			'exchange time'=>$mapping['exchange_time_column'],
			'exchange base currency'=>$mapping['exchange_base_currency_column'],
		];
		foreach($required_columns as $role=>$column){
			if(!array_key_exists($column, $row)){
				throw SqlError::missingMoneyColumn($owner, $column, $role, array_keys($row));
			}
		}
		$original_amount=$row[$mapping['original_amount_column']];
		$base_amount=$row[$mapping['base_amount_column']];
		if(self::isBlankAmount($original_amount) || self::isBlankAmount($base_amount)){
			$row[$target_column]=null;
			return $row;
		}
		$original=self::moneyFromValue(
			$original_amount,
			$row[$mapping['original_currency_column']],
			$owner,
			$mapping['original_amount_column'],
			$mapping['original_currency_column'],
			'original'
		);
		$base=self::moneyFromValue(
			$base_amount,
			$row[$mapping['base_currency_column']],
			$owner,
			$mapping['base_amount_column'],
			$mapping['base_currency_column'],
			'base'
		);
		$row[$target_column]=self::storedMoney(
			$original,
			$base,
			self::normalizeStoredRate($row[$mapping['exchange_rate_column']], $owner, $mapping['exchange_rate_column']),
			self::normalizeStoredSource($row[$mapping['exchange_source_column']], $owner, $mapping['exchange_source_column']),
			self::normalizeStoredTimestamp($row[$mapping['exchange_time_column']], $owner, $mapping['exchange_time_column']),
			self::normalizeStoredCurrency(
				$row[$mapping['exchange_base_currency_column']],
				$owner,
				$mapping['exchange_base_currency_column']
			)
		);
		return $row;
	}

	public static function expandWriteFields(
		array $fields,
		array $money_mappings,
		array $stored_money_mappings,
		string $owner,
		bool $strict=true
	): array {
		foreach($stored_money_mappings as $mapping){
			$fields=self::expandStoredMoneyWriteField($fields, $mapping, $owner);
		}
		foreach($money_mappings as $mapping){
			$fields=self::expandMoneyWriteField($fields, $mapping, $owner);
		}
		if($strict===false){
			return $fields;
		}
		foreach($fields as $column=>$value){
			if(!self::isMoney($value) && !self::isStoredMoney($value)){
				continue;
			}
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Column '{$column}' received a ".($value::class)." object, but no matching SQL money mapping is defined for writes."
			);
		}
		return $fields;
	}

	public static function normalizeComparableValue(
		mixed $value,
		?string $fixed_currency,
		string $owner,
		string $amount_column
	): array {
		if($fixed_currency!==null){
			$fixed_currency=self::normalizeCurrency($fixed_currency, $owner);
		}
		if(self::isMoney($value)){
			if($fixed_currency!==null){
				$value=self::currencyFacade()::convertMoney($value, $fixed_currency);
			}
			return [
				'amount'=>(float)$value->amount(),
				'currency'=>$fixed_currency ?? (string)$value->currency(),
			];
		}
		if(is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))){
			if($fixed_currency===null){
				throw SqlError::invalidMoneyComparison(
					$owner,
					$amount_column,
					'Scalar comparisons need a fixed storage currency.',
					'Pass a Money object for same-currency row filtering, or use whereMoney...In(..., $currency) when the stored amount column is already normalized to one currency.'
				);
			}
			return [
				'amount'=>(float)$value,
				'currency'=>$fixed_currency,
			];
		}
		throw SqlError::invalidMoneyComparison(
			$owner,
			$amount_column,
			'Unsupported money comparison value.',
			'Pass a Dataphyre\\Currency\\Money object, or a scalar amount together with a fixed storage currency.'
		);
	}

	public static function money(float|int $amount, string $currency): object {
		return self::currencyFacade()::money((float)$amount, self::normalizeCurrency($currency, 'sql-currency-bridge'));
	}

	public static function isMoney(mixed $value): bool {
		$class=self::MONEY_CLASS;
		return is_object($value) && $value instanceof $class;
	}

	public static function isStoredMoney(mixed $value): bool {
		$class=self::STORED_MONEY_CLASS;
		return is_object($value) && $value instanceof $class;
	}

	private static function currencyFacade(): string {
		if(!class_exists(self::CURRENCY_FACADE, false)){
			if(class_exists(\dataphyre\core::class, false)){
				\dataphyre\core::load_framework_module('currency');
			}
		}
		if(!class_exists(self::CURRENCY_FACADE, false)){
			throw SqlError::missingFrameworkModule(
				'Dataphyre SQL',
				'currency',
				'SQL money hydration and money-aware query helpers'
			);
		}
		return self::CURRENCY_FACADE;
	}

	private static function normalizeColumn(string $column, string $scope, string $owner): string {
		$column=trim($column);
		if($column==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $column)!==1){
			throw SqlError::invalidIdentifier($scope, $column, $owner);
		}
		return $column;
	}

	private static function normalizeCurrency(string $currency, string $owner): string {
		$currency=mb_strtoupper(trim($currency));
		if($currency===''){
			throw SqlError::invalidMoneyDefinition($owner, 'Currency codes for SQL money helpers cannot be empty.');
		}
		return $currency;
	}

	private static function expandStoredMoneyPrefixes(array $definition): array {
		if(isset($definition['original_prefix']) && !isset($definition['original_amount_column'])){
			$prefix=(string)$definition['original_prefix'];
			$definition['original_amount_column']=$prefix.'amount';
			$definition['original_currency_column'] ??= $prefix.'currency';
		}
		if(isset($definition['base_prefix']) && !isset($definition['base_amount_column'])){
			$prefix=(string)$definition['base_prefix'];
			$definition['base_amount_column']=$prefix.'amount';
			$definition['base_currency_column'] ??= $prefix.'currency';
		}
		if(isset($definition['exchange_prefix'])){
			$prefix=(string)$definition['exchange_prefix'];
			$definition['exchange_rate_column'] ??= $prefix.'rate';
			$definition['exchange_source_column'] ??= $prefix.'source';
			$definition['exchange_time_column'] ??= $prefix.'time';
			$definition['exchange_base_currency_column'] ??= $prefix.'base_currency';
		}
		return $definition;
	}

	private static function expandMoneyWriteField(array $fields, array $mapping, string $owner): array {
		foreach(self::writeCandidateColumns($mapping['target_column'], $mapping['amount_column']) as $candidate_column){
			if(!array_key_exists($candidate_column, $fields) || !self::isMoney($fields[$candidate_column])){
				continue;
			}
			$money=$fields[$candidate_column];
			unset($fields[$candidate_column]);
			$fixed_currency=$mapping['currency'];
			if($fixed_currency!==null && (string)$money->currency()!==$fixed_currency){
				$money=self::currencyFacade()::convertMoney($money, $fixed_currency);
			}
			$fields[$mapping['amount_column']]=$money->amount();
			if($mapping['currency_column']!==null){
				$fields[$mapping['currency_column']]=$money->currency();
			}
			break;
		}
		return $fields;
	}

	private static function expandStoredMoneyWriteField(array $fields, array $mapping, string $owner): array {
		$candidate_column=$mapping['target_column'];
		if(!array_key_exists($candidate_column, $fields)){
			return $fields;
		}
		$value=$fields[$candidate_column];
		if(!self::isStoredMoney($value) && !self::isMoney($value)){
			return $fields;
		}
		unset($fields[$candidate_column]);
		$stored_money=$value;
		if(self::isMoney($stored_money)){
			$stored_money=self::currencyFacade()::storeMoney(
				$stored_money,
				$mapping['base_currency']
			);
		}
		$fields[$mapping['original_amount_column']]=$stored_money->originalAmount();
		$fields[$mapping['original_currency_column']]=$stored_money->originalCurrency();
		$fields[$mapping['base_amount_column']]=$stored_money->baseAmount();
		$fields[$mapping['base_currency_column']]=$stored_money->baseCurrency();
		$fields[$mapping['exchange_rate_column']]=$stored_money->exchangeRate();
		$fields[$mapping['exchange_source_column']]=$stored_money->exchangeSource();
		$fields[$mapping['exchange_time_column']]=$stored_money->exchangeTime();
		$fields[$mapping['exchange_base_currency_column']]=$stored_money->exchangeSnapshotBaseCurrency();
		return $fields;
	}

	private static function isBlankAmount(mixed $amount): bool {
		return $amount===null || (is_string($amount) && trim($amount)==='');
	}

	private static function writeCandidateColumns(string $target_column, string $amount_column): array {
		return array_values(array_unique([$target_column, $amount_column]));
	}

	private static function moneyFromValue(
		mixed $amount,
		mixed $currency,
		string $owner,
		string $amount_column,
		string $currency_column,
		string $scope
	): object {
		if(self::isMoney($amount)){
			return $amount;
		}
		if(!is_numeric($amount)){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money {$scope} amount '{$amount_column}' must be numeric or an existing Money object."
			);
		}
		if(!is_scalar($currency) || trim((string)$currency)===''){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money {$scope} currency '{$currency_column}' must be a non-empty currency code."
			);
		}
		return self::money((float)$amount, (string)$currency);
	}

	private static function storedMoney(
		object $original,
		object $base,
		float $rate,
		string $source,
		int $time,
		string $exchange_base_currency
	): object {
		$currency_facade=self::currencyFacade();
		$manager=$currency_facade::manager();
		$original_currency=(string)$original->currency();
		$base_currency=(string)$base->currency();
		$quote_rate=$original_currency===$base_currency ? 1.0 : $rate;
		$minor_units=[
			$exchange_base_currency=>$manager->minorUnits($exchange_base_currency),
			$original_currency=>$manager->minorUnits($original_currency),
			$base_currency=>$manager->minorUnits($base_currency),
		];
		$exchange_rates_class=self::EXCHANGE_RATES_CLASS;
		$snapshot=(new $exchange_rates_class(
			$exchange_base_currency,
			$source,
			$time,
			self::storedMoneyRateMap($exchange_base_currency, $original_currency, $base_currency, $quote_rate),
			$minor_units
		))->snapshot($manager);
		$exchange_quote_class=self::EXCHANGE_QUOTE_CLASS;
		$quote=new $exchange_quote_class(
			$exchange_base_currency,
			$original_currency,
			$base_currency,
			$manager->minorUnits($original_currency),
			$manager->minorUnits($base_currency),
			$quote_rate,
			$source,
			$time
		);
		$stored_money_class=self::STORED_MONEY_CLASS;
		return new $stored_money_class($original, $base, $snapshot, $quote);
	}

	private static function storedMoneyRateMap(
		string $exchange_base_currency,
		string $original_currency,
		string $base_currency,
		float $rate
	): array {
		$rates=[
			$exchange_base_currency=>1.0,
		];
		if($original_currency===$base_currency){
			$rates[$original_currency]=$original_currency===$exchange_base_currency ? 1.0 : 1.0;
			return $rates;
		}
		if($exchange_base_currency===$base_currency){
			$rates[$base_currency]=1.0;
			$rates[$original_currency]=1/$rate;
			return $rates;
		}
		if($exchange_base_currency===$original_currency){
			$rates[$original_currency]=1.0;
			$rates[$base_currency]=$rate;
			return $rates;
		}
		$rates[$original_currency]=1/$rate;
		$rates[$base_currency]=1.0;
		return $rates;
	}

	private static function normalizeStoredRate(mixed $rate, string $owner, string $column): float {
		if(!is_numeric($rate) || (float)$rate<=0.0){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money exchange rate '{$column}' must be a positive numeric value."
			);
		}
		return (float)$rate;
	}

	private static function normalizeStoredSource(mixed $source, string $owner, string $column): string {
		if(!is_scalar($source) || trim((string)$source)===''){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money exchange source '{$column}' must be a non-empty string."
			);
		}
		return trim((string)$source);
	}

	private static function normalizeStoredTimestamp(mixed $time, string $owner, string $column): int {
		if(is_int($time)){
			return $time>0 ? $time : time();
		}
		if(is_numeric($time)){
			$time=(int)$time;
			return $time>0 ? $time : time();
		}
		if(is_string($time) && trim($time)!==''){
			$parsed=strtotime($time);
			if($parsed!==false){
				return $parsed;
			}
		}
		throw SqlError::invalidMoneyDefinition(
			$owner,
			"Stored money exchange time '{$column}' must be a unix timestamp or parseable datetime string."
		);
	}

	private static function normalizeStoredCurrency(mixed $currency, string $owner, string $column): string {
		if(!is_scalar($currency) || trim((string)$currency)===''){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money exchange base currency '{$column}' must be a non-empty currency code."
			);
		}
		return self::normalizeCurrency((string)$currency, $owner);
	}
}
