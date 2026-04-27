## Geoposition Module

The `geoposition` module provides country-aware postal-code formatting and validation, coordinate lookup by postal code or subdivision, and distance calculations between points.

## Module Shape

- kernel-only module
- SQL-backed postal code regex and postal code coordinate lookup
- JSON-backed subdivision coordinate lookup

## Optional Configuration

The module reads `dataphyre/geoposition` config when present:

```php
[
	'postal_codes_regex_table'=>'dataphyre.postal_codes_regex',
	'postal_codes_table'=>'dataphyre.postal_codes',
	'subdivision_positions_path'=>ROOTPATH['common_dataphyre'].'modules/geoposition/subdivision_positions.json',
]
```

If no config is provided, those defaults are used.

## Core Methods

### `reformat_postal_code(string $country, string $subdivision='*', string $postal_code): string`

Formats a postal code using database-defined regex and rule sets for the given country and subdivision.

Supported rule names include:

- `force_uppercase`
- `force_lowercase`
- `digits_only`
- `letters_only`

### `validate_postal_code(string $country, string $subdivision='*', string $postal_code): bool`

Reformats the postal code first, then validates it against the configured regex for the country and subdivision.

### `get_position_for_postal_code(string $country, string $postal_code=''): array|false`

Looks up the best available coordinate match for a postal code. It progressively shortens the postal code when exact data is not available.

Returned positions are normalized and include both key styles:

```php
[
	'latitude'=>45.4215,
	'longitude'=>-75.6972,
	'lat'=>45.4215,
	'long'=>-75.6972,
	'subdivision'=>'CA-ON',
]
```

### `get_position_for_subdivision(string $country, string $subdivision): array|false`

Returns normalized subdivision coordinates from the subdivision JSON dataset, or `false` when unavailable.

### `distance_between_subdivisions(...)`

Calculates the distance between two subdivision centroids.

### `distance_between_postal_codes(...)`

Calculates the distance between two postal codes using postal-code-derived coordinates.

### `distance_between_points(array $position1, array $position2, bool $better_precision=false)`

Accepts either `latitude` / `longitude` or `lat` / `long` point arrays and calculates the distance between them.

### `haversine_great_circle_distance(...)`

Fast spherical distance approximation in kilometers.

### `vincenty_great_circle_distance(...)`

More precise ellipsoidal distance approximation in kilometers. Identical points return `0.0`, and degenerate cases are handled safely.

## Examples

```php
$formatted=\dataphyre\geoposition::reformat_postal_code('CA', 'CA-ON', 'k1a 0b1');
$valid=\dataphyre\geoposition::validate_postal_code('CA', 'CA-ON', $formatted);
$postal_position=\dataphyre\geoposition::get_position_for_postal_code('CA', 'K1A0B1');
$subdivision_position=\dataphyre\geoposition::get_position_for_subdivision('US', 'US-NY');
$distance=\dataphyre\geoposition::distance_between_postal_codes('CA', 'K1A0B1', 'US', '10001', true);
```

## Operational Notes

- Country and subdivision codes are normalized before lookup.
- Postal-code lookups use configurable SQL table names instead of hardcoded table assumptions.
- Subdivision coordinates are cached in memory after the first JSON load.
- Distance helpers now operate on a consistent normalized point shape, so postal-code and subdivision lookups can be used interchangeably.
