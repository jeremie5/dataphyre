<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Bridges SQL rows and Dataphyre Currency value objects.
 *
 * The bridge normalizes money column mappings, hydrates `Money` and
 * `StoredMoney` objects from SQL result rows, expands those objects back into
 * scalar write fields, and validates money-aware comparison values. It loads the
 * currency framework lazily and raises SQL-specific errors when mappings or row
 * data are incomplete.
 */
final class CurrencyBridge {

	private const CURRENCY_FACADE='Dataphyre\\Currency\\Currency';
	private const MONEY_CLASS='Dataphyre\\Currency\\Money';
	private const STORED_MONEY_CLASS='Dataphyre\\Currency\\StoredMoney';
	private const EXCHANGE_RATES_CLASS='Dataphyre\\Currency\\ExchangeRates';
	private const EXCHANGE_QUOTE_CLASS='Dataphyre\\Currency\\ExchangeQuote';
	private static ?array $lastStoredMoneyMappingInput=null;
	private static ?array $lastStoredMoneyMappingOutput=null;

	/**
	 * Normalizes a simple money mapping definition.
	 *
	 * A mapping needs an amount column and either a currency column or fixed
	 * currency. The target column is where hydrated `Money` objects appear in
	 * result rows and where write expansion looks for incoming money objects.
	 *
	 * @param string $amountColumn SQL column containing the numeric amount.
	 * @param ?string $currencyColumn SQL column containing the row currency.
	 * @param ?string $currency Fixed currency used when no currency column exists.
	 * @param ?string $targetColumn Hydrated object/write input column; defaults to amount column.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return array{amount_column: string, currency_column: ?string, currency: ?string, target_column: string} Normalized mapping.
	 */
	public static function normalizeMoneyMapping(
		string $amountColumn,
		?string $currencyColumn,
		?string $currency,
		?string $targetColumn,
		string $owner
	): array {
		$amountColumn=self::normalizeColumn($amountColumn, 'money amount', $owner);
		$targetColumn=$targetColumn===null
			? $amountColumn
			: self::normalizeColumn($targetColumn, 'money target', $owner);
		if($currency!==null){
			return [
				'amount_column'=>$amountColumn,
				'currency_column'=>null,
				'currency'=>self::normalizeCurrency($currency, $owner),
				'target_column'=>$targetColumn,
			];
		}
		if($currencyColumn===null){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				'Money mappings require either a currency column or a fixed currency.'
			);
		}
		return [
			'amount_column'=>$amountColumn,
			'currency_column'=>self::normalizeColumn($currencyColumn, 'money currency', $owner),
			'currency'=>null,
			'target_column'=>$targetColumn,
		];
	}

	/**
	 * Hydrates one SQL result row with a `Money` object.
	 *
	 * Null or blank amounts hydrate to null. Existing `Money` values are preserved.
	 * Missing amount/currency columns or blank currency values raise SQL mapping
	 * errors instead of silently producing incorrect money values.
	 *
	 * @param array<string, mixed> $row SQL result row.
	 * @param array{amount_column: string, currency_column: ?string, currency: ?string, target_column: string} $mapping Normalized money mapping.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return array<string, mixed> Row with the target column hydrated.
	 */
	public static function applyMoneyMapping(array $row, array $mapping, string $owner): array {
		if(!array_key_exists($mapping['amount_column'], $row)){
			throw SqlError::missingMoneyColumn($owner, $mapping['amount_column'], 'amount', array_keys($row));
		}
		$targetColumn=$mapping['target_column'];
		$amount=$row[$mapping['amount_column']];
		if(self::isMoney($amount)){
			$row[$targetColumn]=$amount;
			return $row;
		}
		if($amount===null || (is_string($amount) && trim($amount)==='')){
			$row[$targetColumn]=null;
			return $row;
		}
		$currency=$mapping['currency'];
		if($currency===null){
			$currencyColumn=$mapping['currency_column'];
			if(!array_key_exists($currencyColumn, $row)){
				throw SqlError::missingMoneyColumn($owner, $currencyColumn, 'currency', array_keys($row));
			}
			$currency=$row[$currencyColumn];
		}
		if(!is_scalar($currency) || trim((string)$currency)===''){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Money hydration requires a non-empty currency value for '{$mapping['amount_column']}'."
			);
		}
		$row[$targetColumn]=self::money((float)$amount, (string)$currency);
		return $row;
	}

	/**
	 * Normalizes a stored-money mapping definition.
	 *
	 * Stored-money mappings describe the original amount/currency, normalized
	 * base amount/currency, exchange rate/source/time/base-currency snapshot, and
	 * hydrated target column. Prefix aliases are expanded before validation.
	 *
	 * @param array<string, mixed> $definition Stored-money mapping definition.
	 * @param ?string $targetColumn Optional target override.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return array<string, string|null> Normalized stored-money mapping.
	 */
	public static function normalizeStoredMoneyMapping(array $definition, ?string $targetColumn, string $owner): array {
		$input=[
			'definition'=>$definition,
			'target_column'=>$targetColumn,
			'owner'=>$owner,
		];
		if(self::$lastStoredMoneyMappingInput===$input && self::$lastStoredMoneyMappingOutput!==null){
			return self::$lastStoredMoneyMappingOutput;
		}
		$definition=self::expandStoredMoneyPrefixes($definition);
		$targetColumn=$targetColumn
			?? (isset($definition['target_column']) ? (string)$definition['target_column'] : null)
			?? (isset($definition['target']) ? (string)$definition['target'] : null)
			?? 'stored_money';
		self::$lastStoredMoneyMappingInput=$input;
		return self::$lastStoredMoneyMappingOutput=[
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
			'target_column'=>self::normalizeColumn($targetColumn, 'stored money target', $owner),
		];
	}

	/**
	 * Hydrates one SQL result row with a `StoredMoney` object.
	 *
	 * The row must contain all stored-money columns. Blank original or base
	 * amounts hydrate to null. Existing `StoredMoney` values in the target column
	 * are preserved.
	 *
	 * @param array<string, mixed> $row SQL result row.
	 * @param array<string, string|null> $mapping Normalized stored-money mapping.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return array<string, mixed> Row with the target column hydrated.
	 */
	public static function applyStoredMoneyMapping(array $row, array $mapping, string $owner): array {
		$targetColumn=$mapping['target_column'];
		if(array_key_exists($targetColumn, $row) && self::isStoredMoney($row[$targetColumn])){
			return $row;
		}
		$requiredColumns=[
			'original amount'=>$mapping['original_amount_column'],
			'original currency'=>$mapping['original_currency_column'],
			'base amount'=>$mapping['base_amount_column'],
			'base currency'=>$mapping['base_currency_column'],
			'exchange rate'=>$mapping['exchange_rate_column'],
			'exchange source'=>$mapping['exchange_source_column'],
			'exchange time'=>$mapping['exchange_time_column'],
			'exchange base currency'=>$mapping['exchange_base_currency_column'],
		];
		foreach($requiredColumns as $role=>$column){
			if(!array_key_exists($column, $row)){
				throw SqlError::missingMoneyColumn($owner, $column, $role, array_keys($row));
			}
		}
		$originalAmount=$row[$mapping['original_amount_column']];
		$baseAmount=$row[$mapping['base_amount_column']];
		if(self::isBlankAmount($originalAmount) || self::isBlankAmount($baseAmount)){
			$row[$targetColumn]=null;
			return $row;
		}
		$original=self::moneyFromValue(
			$originalAmount,
			$row[$mapping['original_currency_column']],
			$owner,
			$mapping['original_amount_column'],
			$mapping['original_currency_column'],
			'original'
		);
		$base=self::moneyFromValue(
			$baseAmount,
			$row[$mapping['base_currency_column']],
			$owner,
			$mapping['base_amount_column'],
			$mapping['base_currency_column'],
			'base'
		);
		$row[$targetColumn]=self::storedMoney(
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

	/**
	 * Expands money objects in write fields into scalar SQL columns.
	 *
	 * Stored-money mappings expand before simple money mappings. In strict mode,
	 * any remaining `Money` or `StoredMoney` object without a matching mapping is
	 * rejected so object values are not written into scalar SQL columns.
	 *
	 * @param array<string, mixed> $fields Candidate SQL write fields.
	 * @param array<int, array<string, mixed>> $moneyMappings Simple money mappings.
	 * @param array<int, array<string, mixed>> $storedMoneyMappings Stored-money mappings.
	 * @param string $owner Query/table owner used in validation errors.
	 * @param bool $strict Whether unmapped money objects should raise an error.
	 * @return array<string, mixed> Scalar SQL write fields.
	 */
	public static function expandWriteFields(
		array $fields,
		array $moneyMappings,
		array $storedMoneyMappings,
		string $owner,
		bool $strict=true
	): array {
		foreach($storedMoneyMappings as $mapping){
			$fields=self::expandStoredMoneyWriteField($fields, $mapping, $owner);
		}
		foreach($moneyMappings as $mapping){
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

	/**
	 * Normalizes a money comparison value for SQL filtering.
	 *
	 * `Money` values are converted to the fixed currency when provided. Scalar
	 * amounts are only accepted with a fixed currency, since otherwise the stored
	 * amount column has no currency context.
	 *
	 * @param mixed $value Money object or scalar amount.
	 * @param ?string $fixedCurrency Fixed storage currency for scalar comparisons.
	 * @param string $owner Query/table owner used in validation errors.
	 * @param string $amountColumn Amount column being compared.
	 * @return array{amount: float, currency: string} Comparable amount and currency.
	 */
	public static function normalizeComparableValue(
		mixed $value,
		?string $fixedCurrency,
		string $owner,
		string $amountColumn
	): array {
		if($fixedCurrency!==null){
			$fixedCurrency=self::normalizeCurrency($fixedCurrency, $owner);
		}
		if(self::isMoney($value)){
			if($fixedCurrency!==null){
				$value=self::currencyFacade()::convertMoney($value, $fixedCurrency);
			}
			return [
				'amount'=>(float)$value->amount(),
				'currency'=>$fixedCurrency ?? (string)$value->currency(),
			];
		}
		if(is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))){
			if($fixedCurrency===null){
				throw SqlError::invalidMoneyComparison(
					$owner,
					$amountColumn,
					'Scalar comparisons need a fixed storage currency.',
					'Pass a Money object for same-currency row filtering, or use whereMoney...In(..., $currency) when the stored amount column is already normalized to one currency.'
				);
			}
			return [
				'amount'=>(float)$value,
				'currency'=>$fixedCurrency,
			];
		}
		throw SqlError::invalidMoneyComparison(
			$owner,
			$amountColumn,
			'Unsupported money comparison value.',
			'Pass a Dataphyre\\Currency\\Money object, or a scalar amount together with a fixed storage currency.'
		);
	}

	/**
	 * Creates a Currency framework `Money` object.
	 *
	 * @param float|int $amount Numeric amount.
	 * @param string $currency Currency code.
	 * @return object Currency framework Money instance.
	 */
	public static function money(float|int $amount, string $currency): object {
		return self::currencyFacade()::money((float)$amount, self::normalizeCurrency($currency, 'sql-currency-bridge'));
	}

	/**
	 * Checks whether a value is a Currency framework `Money` instance.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool True when the value is a Money object.
	 */
	public static function isMoney(mixed $value): bool {
		$class=self::MONEY_CLASS;
		return is_object($value) && $value instanceof $class;
	}

	/**
	 * Checks whether a value is a Currency framework `StoredMoney` instance.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool True when the value is a StoredMoney object.
	 */
	public static function isStoredMoney(mixed $value): bool {
		$class=self::STORED_MONEY_CLASS;
		return is_object($value) && $value instanceof $class;
	}

	/**
	 * Resolves the Currency facade class, loading the currency framework if needed.
	 *
	 * @return string Fully qualified Currency facade class name.
	 */
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

	/**
	 * Validates and normalizes a SQL column identifier used by money mappings.
	 *
	 * @param string $column Raw column name.
	 * @param string $scope Human-readable mapping role.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return string Valid column name.
	 */
	private static function normalizeColumn(string $column, string $scope, string $owner): string {
		$column=trim($column);
		if($column==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $column)!==1){
			throw SqlError::invalidIdentifier($scope, $column, $owner);
		}
		return $column;
	}

	/**
	 * Normalizes a currency code to uppercase and rejects blanks.
	 *
	 * @param string $currency Raw currency code.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return string Uppercase currency code.
	 */
	private static function normalizeCurrency(string $currency, string $owner): string {
		$currency=mb_strtoupper(trim($currency));
		if($currency===''){
			throw SqlError::invalidMoneyDefinition($owner, 'Currency codes for SQL money helpers cannot be empty.');
		}
		return $currency;
	}

	/**
	 * Expands stored-money prefix aliases into explicit column names.
	 *
	 * @param array<string, mixed> $definition Raw stored-money mapping definition.
	 * @return array<string, mixed> Definition with derived column names.
	 */
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

	/**
	 * Expands one `Money` write input into amount and currency SQL fields.
	 *
	 * @param array<string, mixed> $fields Candidate SQL write fields.
	 * @param array<string, mixed> $mapping Normalized money mapping.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return array<string, mixed> Updated write fields.
	 */
	private static function expandMoneyWriteField(array $fields, array $mapping, string $owner): array {
		foreach(self::writeCandidateColumns($mapping['target_column'], $mapping['amount_column']) as $candidateColumn){
			if(!array_key_exists($candidateColumn, $fields) || !self::isMoney($fields[$candidateColumn])){
				continue;
			}
			$money=$fields[$candidateColumn];
			unset($fields[$candidateColumn]);
			$fixedCurrency=$mapping['currency'];
			if($fixedCurrency!==null && (string)$money->currency()!==$fixedCurrency){
				$money=self::currencyFacade()::convertMoney($money, $fixedCurrency);
			}
			$fields[$mapping['amount_column']]=$money->amount();
			if($mapping['currency_column']!==null){
				$fields[$mapping['currency_column']]=$money->currency();
			}
			break;
		}
		return $fields;
	}

	/**
	 * Expands one `StoredMoney` or `Money` write input into stored-money columns.
	 *
	 * Plain Money values are first stored through the currency facade using the
	 * mapping's base currency policy.
	 *
	 * @param array<string, mixed> $fields Candidate SQL write fields.
	 * @param array<string, mixed> $mapping Normalized stored-money mapping.
	 * @param string $owner Query/table owner used in validation errors.
	 * @return array<string, mixed> Updated write fields.
	 */
	private static function expandStoredMoneyWriteField(array $fields, array $mapping, string $owner): array {
		$candidateColumn=$mapping['target_column'];
		if(!array_key_exists($candidateColumn, $fields)){
			return $fields;
		}
		$value=$fields[$candidateColumn];
		if(!self::isStoredMoney($value) && !self::isMoney($value)){
			return $fields;
		}
		unset($fields[$candidateColumn]);
		$storedMoney=$value;
		if(self::isMoney($storedMoney)){
			$storedMoney=self::currencyFacade()::storeMoney(
				$storedMoney,
				$mapping['base_currency']
			);
		}
		$fields[$mapping['original_amount_column']]=$storedMoney->originalAmount();
		$fields[$mapping['original_currency_column']]=$storedMoney->originalCurrency();
		$fields[$mapping['base_amount_column']]=$storedMoney->baseAmount();
		$fields[$mapping['base_currency_column']]=$storedMoney->baseCurrency();
		$fields[$mapping['exchange_rate_column']]=$storedMoney->exchangeRate();
		$fields[$mapping['exchange_source_column']]=$storedMoney->exchangeSource();
		$fields[$mapping['exchange_time_column']]=$storedMoney->exchangeTime();
		$fields[$mapping['exchange_base_currency_column']]=$storedMoney->exchangeSnapshotBaseCurrency();
		return $fields;
	}

	/**
	 * Checks whether a SQL amount should hydrate as null.
	 *
	 * @param mixed $amount Raw amount value.
	 * @return bool True for null or blank-string amounts.
	 */
	private static function isBlankAmount(mixed $amount): bool {
		return $amount===null || (is_string($amount) && trim($amount)==='');
	}

	/**
	 * Returns write-field columns that may contain a simple Money object.
	 *
	 * @param string $targetColumn Hydrated target column.
	 * @param string $amountColumn Raw amount column.
	 * @return array<int, string> Unique candidate input columns.
	 */
	private static function writeCandidateColumns(string $targetColumn, string $amountColumn): array {
		return array_values(array_unique([$targetColumn, $amountColumn]));
	}

	/**
	 * Creates a Money object from stored amount/currency row values.
	 *
	 * Existing Money objects are returned unchanged. Numeric amounts and non-empty
	 * currency codes are required for scalar hydration.
	 *
	 * @param mixed $amount Raw amount or Money object.
	 * @param mixed $currency Raw currency code.
	 * @param string $owner Query/table owner used in validation errors.
	 * @param string $amountColumn Source amount column.
	 * @param string $currencyColumn Source currency column.
	 * @param string $scope Original/base value role.
	 * @return object Currency framework Money instance.
	 */
	private static function moneyFromValue(
		mixed $amount,
		mixed $currency,
		string $owner,
		string $amountColumn,
		string $currencyColumn,
		string $scope
	): object {
		if(self::isMoney($amount)){
			return $amount;
		}
		if(!is_numeric($amount)){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money {$scope} amount '{$amountColumn}' must be numeric or an existing Money object."
			);
		}
		if(!is_scalar($currency) || trim((string)$currency)===''){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money {$scope} currency '{$currencyColumn}' must be a non-empty currency code."
			);
		}
		return self::money((float)$amount, (string)$currency);
	}

	/**
	 * Reconstructs a StoredMoney object from persisted original/base values.
	 *
	 * A synthetic exchange-rates snapshot and quote are recreated from the stored
	 * rate/source/time/base currency columns so the resulting object behaves like
	 * one produced by the currency framework at write time.
	 *
	 * @param object $original Original-currency Money object.
	 * @param object $base Base-currency Money object.
	 * @param float $rate Stored exchange rate.
	 * @param string $source Stored exchange source.
	 * @param int $time Stored exchange timestamp.
	 * @param string $exchangeBaseCurrency Exchange snapshot base currency.
	 * @return object Currency framework StoredMoney instance.
	 */
	private static function storedMoney(
		object $original,
		object $base,
		float $rate,
		string $source,
		int $time,
		string $exchangeBaseCurrency
	): object {
		$currencyFacade=self::currencyFacade();
		$manager=$currencyFacade::manager();
		$originalCurrency=(string)$original->currency();
		$baseCurrency=(string)$base->currency();
		$quoteRate=$originalCurrency===$baseCurrency ? 1.0 : $rate;
		$minorUnits=[
			$exchangeBaseCurrency=>$manager->minorUnits($exchangeBaseCurrency),
			$originalCurrency=>$manager->minorUnits($originalCurrency),
			$baseCurrency=>$manager->minorUnits($baseCurrency),
		];
		$exchangeRatesClass=self::EXCHANGE_RATES_CLASS;
		$snapshot=(new $exchangeRatesClass(
			$exchangeBaseCurrency,
			$source,
			$time,
			self::storedMoneyRateMap($exchangeBaseCurrency, $originalCurrency, $baseCurrency, $quoteRate),
			$minorUnits
		))->snapshot($manager);
		$exchangeQuoteClass=self::EXCHANGE_QUOTE_CLASS;
		$quote=new $exchangeQuoteClass(
			$exchangeBaseCurrency,
			$originalCurrency,
			$baseCurrency,
			$manager->minorUnits($originalCurrency),
			$manager->minorUnits($baseCurrency),
			$quoteRate,
			$source,
			$time
		);
		$storedMoneyClass=self::STORED_MONEY_CLASS;
		return new $storedMoneyClass($original, $base, $snapshot, $quote);
	}

	/**
	 * Builds the rate map needed to recreate a stored exchange snapshot.
	 *
	 * @param string $exchangeBaseCurrency Snapshot base currency.
	 * @param string $originalCurrency Original money currency.
	 * @param string $baseCurrency Stored base money currency.
	 * @param float $rate Original-to-base exchange rate.
	 * @return array<string, float> Exchange rates keyed by currency.
	 */
	private static function storedMoneyRateMap(
		string $exchangeBaseCurrency,
		string $originalCurrency,
		string $baseCurrency,
		float $rate
	): array {
		$rates=[
			$exchangeBaseCurrency=>1.0,
		];
		if($originalCurrency===$baseCurrency){
			$rates[$originalCurrency]=$originalCurrency===$exchangeBaseCurrency ? 1.0 : 1.0;
			return $rates;
		}
		if($exchangeBaseCurrency===$baseCurrency){
			$rates[$baseCurrency]=1.0;
			$rates[$originalCurrency]=1/$rate;
			return $rates;
		}
		if($exchangeBaseCurrency===$originalCurrency){
			$rates[$originalCurrency]=1.0;
			$rates[$baseCurrency]=$rate;
			return $rates;
		}
		$rates[$originalCurrency]=1/$rate;
		$rates[$baseCurrency]=1.0;
		return $rates;
	}

	/**
	 * Normalizes a stored exchange rate.
	 *
	 * @param mixed $rate Raw rate value.
	 * @param string $owner Query/table owner used in validation errors.
	 * @param string $column Source column name.
	 * @return float Positive exchange rate.
	 */
	private static function normalizeStoredRate(mixed $rate, string $owner, string $column): float {
		if(!is_numeric($rate) || (float)$rate<=0.0){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money exchange rate '{$column}' must be a positive numeric value."
			);
		}
		return (float)$rate;
	}

	/**
	 * Normalizes a stored exchange source label.
	 *
	 * @param mixed $source Raw source value.
	 * @param string $owner Query/table owner used in validation errors.
	 * @param string $column Source column name.
	 * @return string Non-empty source label.
	 */
	private static function normalizeStoredSource(mixed $source, string $owner, string $column): string {
		if(!is_scalar($source) || trim((string)$source)===''){
			throw SqlError::invalidMoneyDefinition(
				$owner,
				"Stored money exchange source '{$column}' must be a non-empty string."
			);
		}
		return trim((string)$source);
	}

	/**
	 * Normalizes a stored exchange timestamp.
	 *
	 * Positive numeric timestamps are used directly. Parseable datetime strings
	 * are converted with `strtotime()`; non-positive numeric values fall back to
	 * current time to preserve legacy stored-money behavior.
	 *
	 * @param mixed $time Raw timestamp or datetime value.
	 * @param string $owner Query/table owner used in validation errors.
	 * @param string $column Source column name.
	 * @return int Unix timestamp.
	 */
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

	/**
	 * Normalizes a stored exchange base currency.
	 *
	 * @param mixed $currency Raw currency value.
	 * @param string $owner Query/table owner used in validation errors.
	 * @param string $column Source column name.
	 * @return string Uppercase currency code.
	 */
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
