### Geoposition Module

The **Geoposition** module in Dataphyre provides a set of tools for handling geographical data, primarily focused on validating, formatting, and retrieving geographical coordinates based on postal codes or subdivisions. It also offers distance calculation methods between geographical points, postal codes, and subdivisions.

#### Key Functionalities

1. **Postal Code Formatting and Validation**: Ensures postal codes are correctly formatted and valid for a specified country and subdivision.
2. **Geographical Position Retrieval**: Retrieves latitude and longitude data for given postal codes or subdivisions.
3. **Distance Calculations**: Computes distances between two points using the Haversine or Vincenty formula for better precision.

---

#### Core Methods

1. **`reformat_postal_code(string $country, string $subdivision='*', string $postal_code): string`**

   - **Purpose**: Formats a postal code according to specified rules based on the country and subdivision.
   - **Parameters**:
     - `$country`: ISO country code.
     - `$subdivision`: Subdivision (region/state) code (optional, defaults to '*').
     - `$postal_code`: The postal code to be formatted.
   - **Returns**: Formatted postal code as a string.

   - **Functionality**:
     - Applies a series of formatting rules (e.g., `force_uppercase`, `digits_only`) retrieved from the database to the postal code.
     - Returns the reformatted postal code or the original code if no formatting rules apply.

2. **`validate_postal_code(string $country, string $subdivision='*', string $postal_code): bool`**

   - **Purpose**: Validates the postal code against a regex pattern specific to the country and subdivision.
   - **Parameters**:
     - `$country`: ISO country code.
     - `$subdivision`: Subdivision (optional, defaults to '*').
     - `$postal_code`: The postal code to be validated.
   - **Returns**: `true` if the postal code is valid, `false` otherwise.

3. **`get_position_for_postal_code(string $country, string $postal_code='')`**

   - **Purpose**: Retrieves the latitude and longitude for a given postal code.
   - **Parameters**:
     - `$country`: ISO country code.
     - `$postal_code`: Postal code to retrieve the position for.
   - **Returns**: An associative array with keys `'lat'` and `'long'` for latitude and longitude, or `false` if no data is found.

4. **`get_position_for_subdivision(string $country, string $subdivision): array|bool`**

   - **Purpose**: Retrieves the geographical position (latitude and longitude) for a specified country subdivision.
   - **Parameters**:
     - `$country`: ISO country code.
     - `$subdivision`: Subdivision code.
   - **Returns**: An associative array with keys `'latitude'` and `'longitude'`, or `false` if not found.

5. **`distance_between_subdivisions(string $country1, string $subdivision1, string $country2, string $subdivision2, bool $better_precision=false)`**

   - **Purpose**: Calculates the distance between two subdivisions, using either the Haversine or Vincenty formula.
   - **Parameters**:
     - `$country1`: ISO code of the first country.
     - `$subdivision1`: Subdivision of the first location.
     - `$country2`: ISO code of the second country.
     - `$subdivision2`: Subdivision of the second location.
     - `$better_precision`: Set `true` for higher accuracy using the Vincenty formula.
   - **Returns**: Distance in kilometers, or `false` if coordinates are unavailable.

6. **`distance_between_postal_codes(string $country1, string $postal_code1, string $country2, string $postal_code2, bool $better_precision=false)`**

   - **Purpose**: Calculates the distance between two postal codes.
   - **Parameters**:
     - `$country1`, `$country2`: ISO codes of the countries.
     - `$postal_code1`, `$postal_code2`: Postal codes to calculate the distance between.
     - `$better_precision`: Set `true` for higher accuracy using the Vincenty formula.
   - **Returns**: Distance in kilometers, or `false` if coordinates are unavailable.

7. **`distance_between_points(array $position1, array $position2, bool $better_precision=false)`**

   - **Purpose**: Calculates the distance between two sets of coordinates.
   - **Parameters**:
     - `$position1`, `$position2`: Associative arrays with keys `'latitude'` and `'longitude'`.
     - `$better_precision`: Set `true` to use the Vincenty formula.
   - **Returns**: Distance in kilometers.

8. **`haversine_great_circle_distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2, int $earthRadius=6371)`**

   - **Purpose**: Calculates the great-circle distance between two points using the Haversine formula.
   - **Parameters**:
     - `$latitude1`, `$longitude1`, `$latitude2`, `$longitude2`: Coordinates of the two points.
     - `$earthRadius`: Radius of the Earth (default is 6371 km).
   - **Returns**: Distance in kilometers.

9. **`vincenty_great_circle_distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2, int $a=6378137, float $f=1/298.257223563)`**

   - **Purpose**: Calculates the distance between two points using the more accurate Vincenty formula.
   - **Parameters**:
     - `$latitude1`, `$longitude1`, `$latitude2`, `$longitude2`: Coordinates of the two points.
     - `$a`: Major semi-axis of the Earth in meters (default is 6378137 m).
     - `$f`: Flattening factor of the Earth (default is 1/298.257223563).
   - **Returns**: Distance in kilometers.

---

#### Example Usage

1. **Format a Postal Code**:
   ```php
   $formatted_postal_code = geoposition::reformat_postal_code('US', 'CA', '90001');
   ```

2. **Validate a Postal Code**:
   ```php
   $is_valid = geoposition::validate_postal_code('US', 'CA', '90001');
   ```

3. **Retrieve Position for a Postal Code**:
   ```php
   $position = geoposition::get_position_for_postal_code('US', '90001');
   ```

4. **Calculate Distance Between Postal Codes**:
   ```php
   $distance = geoposition::distance_between_postal_codes('US', '90001', 'US', '90210', true);
   ```

---

### Workflow

1. **Postal Code Management**: The `reformat_postal_code` and `validate_postal_code` methods ensure that postal codes conform to specific country and subdivision formats, helping with data consistency and validation.
2. **Geographical Data Retrieval**: The module retrieves geographical coordinates for postal codes and subdivisions, which can then be used for spatial analysis, proximity calculations, and service eligibility based on location.
3. **Distance Calculations**: Using either the Haversine or Vincenty formula, this module accurately calculates the distance between two points, subdivisions, or postal codes. The Vincenty formula offers better precision for closer distances.

The **Geoposition** module enhances Dataphyre’s geographical capabilities, enabling developers to manage location-based data, perform validations, and calculate distances, all of which are essential for location-based services and applications.