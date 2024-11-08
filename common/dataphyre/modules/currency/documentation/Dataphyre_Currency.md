### Currency Module

The Currency module in Dataphyre is designed to handle currency conversion and formatting within applications, making it easier to display prices and amounts in different currencies, regions, and languages. This module interacts with exchange rate sources and caches exchange rates for efficient access, ensuring users see accurate, real-time prices in their local currency.

#### Key Properties

- **`$base_currency`**: The primary currency used by the application, defaulted to `USD`.
- **`$display_currency`**: The currency currently displayed to the user, defaulted to `USD`.
- **`$display_language`**: The language in which currency values are displayed, e.g., `en-CA` for English (Canada).
- **`$display_country`**: The country code used for display purposes, defaulted to `CA`.
- **`$available_currencies`**: An array of available currencies with their symbols, e.g., `["USD" => "$"]`.
- **`$special_formatting`**: Defines currency formatting options by region, including decimal and thousand separators and decimal precision. This property allows custom currency formatting based on regional conventions.

#### Public Methods

1. **`get_exchange_rates()`**

   Loads the latest exchange rates into the session. If cached rates are unavailable, invalid, or expired, this method retrieves rates from an external source specified in the configuration.

   - **Returns**: The exchange rate data stored in the session.
   - **Example Usage**:
     ```php
     $rates = currency::get_exchange_rates();
     ```

2. **`get_rates_data(string $source)`**

   Fetches exchange rates from a specified source. Currently supported sources include `exchangerate.host` and `europa.eu`. Each source requires different processing for parsing and storing the exchange rates.

   - **Parameters**:
     - `$source`: The exchange rate source to use (e.g., `exchangerate.host` or `europa.eu`).
   - **Returns**: `true` if rates were successfully retrieved and cached, `false` otherwise.
   - **Example Usage**:
     ```php
     $success = currency::get_rates_data('exchangerate.host');
     ```

3. **`formatter(float|null $amount, bool|null $show_free = false, string|null $currency = null): string`**

   Formats a given amount into the specified or default currency format. Regional formatting rules are applied based on the `display_language` and `display_country` properties.

   - **Parameters**:
     - `$amount`: The amount to format.
     - `$show_free`: If `true`, returns "Free" for a zero amount.
     - `$currency`: The currency code to format the amount in. Defaults to the `display_currency`.
   - **Returns**: A string representing the formatted currency amount.
   - **Example Usage**:
     ```php
     echo currency::formatter(100, true, 'USD');
     ```

4. **`convert(float|null $amount, string $source_currency, string $target_currency, bool|null $formatted = false, bool|null $show_free = true): string|float`**

   Converts an amount from one currency to another using cached exchange rates.

   - **Parameters**:
     - `$amount`: The amount to convert.
     - `$source_currency`: The currency code of the source currency.
     - `$target_currency`: The currency code of the target currency.
     - `$formatted`: If `true`, formats the result using `formatter`.
     - `$show_free`: If `true`, shows "Free" for zero amounts.
   - **Returns**: The converted amount, either formatted as a string or as a float.
   - **Example Usage**:
     ```php
     $convertedAmount = currency::convert(100, 'USD', 'EUR', true);
     ```

5. **`convert_to_user_currency(float|null $amount, bool|null $formatted = false, bool|null $show_free = true, string|null $currency = null): string|float`**

   Converts an amount from the base currency to the user’s display currency, applying regional formatting if specified.

   - **Parameters**:
     - `$amount`: The amount to convert.
     - `$formatted`: If `true`, formats the result using `formatter`.
     - `$show_free`: If `true`, shows "Free" for zero amounts.
     - `$currency`: The target currency code. Defaults to the user’s `display_currency`.
   - **Returns**: The converted amount in the user’s currency, either formatted or as a float.
   - **Example Usage**:
     ```php
     $userCurrencyAmount = currency::convert_to_user_currency(100);
     ```

6. **`convert_to_website_currency(float|null $amount, string $original_currency, bool|null $formatted = false, bool|null $show_free = true): string|float`**

   Converts an amount from a specified currency to the base currency used on the website.

   - **Parameters**:
     - `$amount`: The amount to convert.
     - `$original_currency`: The source currency code.
     - `$formatted`: If `true`, formats the result using `formatter`.
     - `$show_free`: If `true`, shows "Free" for zero amounts.
   - **Returns**: The converted amount in the base currency, either formatted or as a float.
   - **Example Usage**:
     ```php
     $websiteCurrencyAmount = currency::convert_to_website_currency(100, 'EUR');
     ```

---

### Workflow

1. **Initialization**: The class is instantiated with default currency, language, and country settings. The framework sets up base currency properties according to configuration.

2. **Exchange Rate Fetching**: 
   - **Cached rates**: Cached rates are used if available and within the time limit (60 minutes).
   - **External rates**: If cached rates are unavailable or expired, the module fetches data from configured external sources, such as `exchangerate.host` or `europa.eu`.

3. **Conversion and Formatting**:
   - **Conversion**: Converts amounts between currencies using the cached exchange rates.
   - **Formatting**: Formats the converted amounts according to regional display settings.

---

This Currency module provides flexible support for multiple currencies and regions, allowing Dataphyre applications to handle complex currency requirements seamlessly.