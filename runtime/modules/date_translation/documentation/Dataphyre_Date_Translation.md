### Date Translation Module

The **Date Translation** module in Dataphyre enables localization of date strings based on the specified language and format. This module translates date components (like month names, day names, and ordinal suffixes) into the target language while preserving the intended date format.

#### Key Functionalities

1. **Language-Specific Date Translation**: Transforms date strings from English to the target language (e.g., French) based on language-specific configurations.
2. **Configurable Format Support**: Maintains the specified date format structure even after translation.
3. **Handling of Ordinal Suffixes**: Adjusts ordinal suffixes based on language rules (e.g., "1st" to "1er" in French).
4. **Localized Caching**: Loads and caches language configurations to ensure efficient translation operations.

---

#### Core Properties and Methods

1. **`$date_locales` (Private Static Property)**
   - **Description**: Stores the cached date translation configurations for each language to avoid reloading during multiple translation requests.
   - **Type**: `array`

2. **`translate_date(string $string, string $lang, string $format) : string|null`**
   - **Purpose**: Translates a date string into the specified language and formats it according to the provided structure.
   - **Parameters**:
     - `$string`: The date string to translate (e.g., "January 1st").
     - `$lang`: The target language code (e.g., "fr" for French).
     - `$format`: The desired date format to maintain during translation (e.g., "d M Y").
   - **Returns**: Translated date string or `null` if translation is not possible.

   **Translation Process**:
   - **Step 1**: Check if the target language is English. If so, return the input date string as no translation is required.
   - **Step 2**: Break the date string into individual components (e.g., "January 1st" becomes `["January", "1st"]`).
   - **Step 3**: Load the language-specific configuration file for the target language if it hasn't been loaded yet.
   - **Step 4**: Translate each component of the date string using the loaded configuration:
     - **Month Translation**: Converts full and abbreviated month names.
     - **Weekday Translation**: Translates weekdays based on the target language.
     - **Ordinal Suffix Handling**: Adjusts ordinal suffixes for days, such as "1st" to "1er" for French or removing them as needed.
   - **Step 5**: Reassemble the components to form the translated date string, modifying the format if required for certain languages (e.g., reversing day and month in French).
   - **Step 6**: Return the translated and formatted date string.

---

#### Example Usage

1. **Translate a Date to French**:
   ```php
   $translated_date = date_translation::translate_date("January 1st", "fr", "d M Y");
   // Output: "le 1 janvier"
   ```

2. **Translate with Format Reversal**:
   ```php
   $translated_date = date_translation::translate_date("March 15th", "fr", "F jS");
   // Output: "15 mars"
   ```

3. **Translate a Weekday with Ordinal Handling**:
   ```php
   $translated_date = date_translation::translate_date("Monday, January 2nd", "fr", "l, F jS");
   // Output: "lundi, 2 janvier"
   ```

---

#### Workflow and Usage

1. **Localization Configuration**: The module references pre-configured language files (PHP or JSON format) containing translations for months, weekdays, and ordinal suffix rules.
2. **Date Transformation**: Applies transformations on the date string based on the specified language and target format, preserving the intended structure.
3. **Caching for Efficiency**: Caches language configurations upon the first load to optimize performance for repeated translation requests.

---

The **Date Translation** module is essential for Dataphyre's support for internationalization, allowing dates to be displayed naturally across different languages and formats while adhering to language-specific conventions.