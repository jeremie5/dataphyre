### Currency Module

The Currency module handles:
- exchange-rate caching in session and SQL
- currency conversion
- regional formatting
- application-owned rate retrieval callbacks

Dataphyre no longer ships built-in HTTP rate providers. Applications provide retrieval callbacks, and the module normalizes, caches, and persists the returned rates.

## Kernel Configuration

The owning kernel exposes the merged readonly config as `DP_CURRENCY_CFG`.

Common Dataphyre config keeps the retrieval surface empty by default:

```php
return [
	'exchange_rate_sources'=>[],
	'exchange_rate_callbacks'=>[],
	'minor_units'=>[],
	'cash_rounding_increments'=>[],
];
```

An application can then provide real sources:

```php
return [
	'exchange_rate_sources'=>['exchangerate.host', 'europa.eu'],
	'exchange_rate_callbacks'=>[
		'exchangerate.host'=>static function(string $source, string $base_currency): array|false {
			$response=@file_get_contents(
				'https://api.exchangerate.host/latest?base='.rawurlencode($base_currency)
			);
			$data=json_decode((string)$response, true);
			if(!is_array($data) || empty($data['rates'])){
				return false;
			}
			$data['rates'][$base_currency]=1.0;
			return [
				'rates'=>$data['rates'],
				'time'=>$data['date'] ?? time(),
				'source'=>$source,
			];
		},
	],
];
```

Supported callback return shapes:

```php
return [
	'USD'=>1.0,
	'EUR'=>0.92,
	'CAD'=>1.35,
];
```

or

```php
return [
	'rates'=>[
		'USD'=>1.0,
		'EUR'=>0.92,
		'CAD'=>1.35,
	],
	'time'=>time(),
	'source'=>'my-provider',
];
```

Optional metadata overrides:

```php
return [
	'minor_units'=>[
		'USDC'=>6,
	],
	'cash_rounding_increments'=>[
		'CHF'=>0.05,
	],
];
```

## Kernel API

### `register_exchange_rate_source(string $source, callable $callback): void`

Registers one callback at runtime.

### `register_exchange_rate_sources(array $callbacks): void`

Registers multiple callbacks at runtime.

### `exchange_rate_sources(): array`

Returns the normalized source order the module will try.

### `get_exchange_rates(): array`

Loads exchange rates in this order:
- valid session cache
- fresh SQL cache
- configured application callbacks

### `get_rates_data(string $source): bool`

Runs one application callback, normalizes the result, stores it in session, and persists it to SQL when the SQL module is loaded.

### `formatter(float|null $amount, bool|null $show_free=false, string|null $currency=null): string`

Formats an amount using the active display locale and currency symbol map.

### `convert(float|null $amount, string $source_currency, string $target_currency, bool|null $formatted=false, bool|null $show_free=true): string|float`

Converts between two currencies using cached rates. Rates are loaded lazily when needed.

### `convert_to_user_currency(...)`

Converts from the application base currency to the active display currency.

### `convert_to_website_currency(...)`

Converts from an arbitrary currency back to the application base currency.

## Framework Layer

Load the framework surface explicitly:

```php
\dataphyre\core::load_framework_module('currency');
```

Main classes:
- `Dataphyre\Currency\Currency`
- `Dataphyre\Currency\CurrencyManager`
- `Dataphyre\Currency\CurrencyContext`
- `Dataphyre\Currency\CurrencyState`
- `Dataphyre\Currency\ExchangeRates`
- `Dataphyre\Currency\ExchangeSnapshot`
- `Dataphyre\Currency\ExchangeQuote`
- `Dataphyre\Currency\Money`
- `Dataphyre\Currency\StoredMoney`

Typed exceptions:
- `Dataphyre\Currency\Exceptions\UnknownExchangeRateException`
- `Dataphyre\Currency\Exceptions\CurrencyMismatchException`
- `Dataphyre\Currency\Exceptions\StaleExchangeRatesException`

### Facade Examples

```php
use Dataphyre\Currency\Currency;

$rates=Currency::rates();
$eur_rate=Currency::rate('EUR');
$quote=Currency::quoteOrFail('USD', 'CAD');
$minor_units=Currency::minorUnits('JPY');

$formatted=Currency::format(149.99, false, 'USD');
$converted=Currency::convert(149.99, 'USD', 'CAD');

$money=Currency::money(149.99, 'USD')->inDisplayCurrency();
echo $money->format();

$snapshot=Currency::snapshot();
```

### Context Example

```php
use Dataphyre\Currency\Currency;

$fr_ca=Currency::context(
	display_currency: 'CAD',
	display_language: 'fr-CA',
	display_country: 'CA'
);

echo $fr_ca->format(149.99, false, 'CAD');
echo $fr_ca->convertToDisplay(149.99, true);
```

### Quote And Money Examples

```php
use Dataphyre\Currency\Currency;

$quote=Currency::quoteOrFail('USD', 'EUR');
$converted=$quote->convert(149.99);

$subtotal=Currency::money(149.99, 'USD');
$tax=Currency::money(22.50, 'USD');
$total=$subtotal->add($tax);

if($total->greaterThan(100)){
	echo $total->display();
}
```

Money arithmetic is explicit about currency mismatches. If you try to add or compare different currencies without converting first, the framework throws a typed `CurrencyMismatchException`. Missing strict quotes throw `UnknownExchangeRateException`.

### Exchange Snapshots

`ExchangeSnapshot` freezes a rate set at a specific source and timestamp so pricing and conversions can be replayed deterministically later.

```php
use Dataphyre\Currency\Currency;

$snapshot=Currency::snapshot();

$quote=$snapshot->quoteOrFail('USD', 'CAD');
$converted=$snapshot->convert(149.99, 'USD', 'CAD');

$money=Currency::money(149.99, 'USD');
$priced=$money->convertedWith($snapshot, 'CAD');
```

Snapshots keep the captured:
- base currency
- rate source
- timestamp
- rate map
- minor-unit map

Useful methods include:
- `isStale(...)`
- `assertFresh(...)`
- `quote(...)`
- `quoteOrFail(...)`
- `quoteOrFailFresh(...)`
- `convert(...)`
- `convertOrFailFresh(...)`
- `convertMoney(...)`
- `convertMoneyOrFailFresh(...)`
- `money(...)`

You can also create them from scoped contexts:

```php
$ca_snapshot=Currency::context(display_currency: 'CAD', display_country: 'CA')->snapshot();
```

When stale rates should be rejected explicitly, use the strict helpers:

```php
use Dataphyre\Currency\Currency;

$snapshot=Currency::snapshotOrFail(3600);
$quote=Currency::quoteOrFailFresh('USD', 'CAD', 3600);
$converted=Currency::convertOrFailFresh(149.99, 'USD', 'CAD', 3600);

$money=Currency::money(149.99, 'USD');
$priced=Currency::convertMoneyOrFailFresh($money, 'CAD', 3600);
```

Snapshots and quotes also support freshness checks directly:

```php
$snapshot->assertFresh(3600);
$quote->assertFresh(3600);
$money->convertedWithFresh($snapshot, 'CAD', 3600);
```

### Storage Helpers

`StoredMoney` gives one canonical persisted shape for original money, normalized base money, and the snapshot metadata that produced it.

```php
use Dataphyre\Currency\Currency;

$stored=Currency::storeMoney(
	Currency::money(149.99, 'USD'),
	'CAD'
);

$row=$stored->toArray();
```

Default storage keys are:
- `original_amount`
- `original_currency`
- `base_amount`
- `base_currency`
- `exchange_rate`
- `exchange_source`
- `exchange_time`
- `exchange_base_currency`

You can also customize the prefixes:

```php
$row=$stored->toArray(
	'price_',
	'normalized_',
	'fx_'
);
```

The same helper is available from snapshots, contexts, and money objects:

```php
$snapshot=Currency::snapshot();
$stored=$snapshot->storeMoney(Currency::money(149.99, 'USD'), 'CAD');

$money=Currency::money(149.99, 'USD');
$stored=$money->stored('CAD');
$strict=$money->storedFresh(3600, 'CAD');
```

### SQL Integration

The SQL framework can hydrate stored amount-and-currency pairs into `Money` objects explicitly:

```php
\dataphyre\core::load_framework_module('sql');
\dataphyre\core::load_framework_module('currency');

$orders=OrderRepository::query()
	->asMoney('total_amount', 'currency')
	->getRecords();

$first_total=$orders[0]->total_amount ?? null;
```

Money-aware query helpers also exist on SQL query builders:

```php
use Dataphyre\Currency\Currency;

$cap=Currency::money(100, 'USD');

$matching=OrderRepository::query()
	->whereMoneyLte('total_amount', $cap, 'currency')
	->get();
```

For tables that already store normalized base-currency amounts, use the fixed-currency helpers such as `whereMoneyLteIn(...)`.

If a repository always carries the same money fields, it can also declare them once through `protected static function moneyColumns(): array`, and record hydration will apply the money casting automatically.

### Precision, Rounding, And Allocation

Kernel and framework both use currency-specific minor units instead of hardcoded `2` decimals. That means currencies like `JPY` and `KRW` round to `0` decimals, while currencies like `KWD` round to `3`.

```php
use Dataphyre\Currency\Currency;

$rounded=Currency::roundAmount(10.127, 'USD');
$yen=Currency::roundAmount(10.9, 'JPY');

$parts=Currency::splitAmount(10, 'USD', 3);
$allocated=Currency::allocateAmount(10, 'USD', [
	'seller'=>70,
	'platform'=>20,
	'tax'=>10,
]);

$money=Currency::money(10, 'CHF');
$cash_rounded=$money->rounded(true);
$shares=$money->split(3);
```

Cash rounding is separate from normal precision rounding. If a currency has a cash increment configured, passing `true` to `roundAmount(..., $cash=true)` or `Money::rounded(true)` applies that increment.

### Runtime Source Registration

```php
use Dataphyre\Currency\Currency;

Currency::registerSource('internal-api', static function(string $source, string $base_currency){
	return [
		'rates'=>fetch_internal_rates($base_currency),
		'time'=>time(),
		'source'=>$source,
	];
});
```

## Workflow

1. The application defines exchange-rate sources and callbacks.
2. Currency conversion asks for rates only when needed.
3. The module prefers session cache, then SQL cache, then callback retrieval.
4. Callback results are normalized to a single rate map and stored in `$_SESSION['exchange_rate_data']`.
5. The framework layer adds typed state, contexts, money objects, and rate snapshots on top of the same kernel path.
