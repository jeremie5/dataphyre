### Dataphyre Routing Module Documentation

The **Routing Module** in Dataphyre handles incoming URL requests, routing them to the appropriate resources. It processes dynamic routes, validates URL patterns, and supports a range of parameter validation formats, enhancing URL handling and error management.

#### Key Components

1. **Initialization and Configuration**
   - Loads routing configurations and defines a `404 Not Found` fallback.
   - **`routing::not_found()`**: Redirects to a configured error page or displays a `404` error if no page is specified.

2. **Routing Mechanism**
   - **Routing Class (`routing`)**: Manages routing operations and validates URL paths based on pre-defined routes.
   - **Properties**:
     - **`$page`**: Holds the current page's route.
     - **`$realpage`**: Stores the actual file path for the current route.

3. **Methods**

   - **`not_found()`**: Called if the requested route doesn’t match any known routes. Redirects or returns a `404` error.
   - **`set_page($file)`**: Sets the current page and normalizes the file path. Returns the updated file path.
   - **`check_route($route, $file)`**: Core method for validating the route. Checks if the current request matches the given route.
     - Uses **route parameters** (enclosed in `{}`) to capture dynamic values in the URL.
     - If matched, calls **`set_page()`** to establish the current page.

4. **Parameter Processing and Validation**
   - **`process_format_params($param_matches, $request, $route)`**: Validates and processes dynamic parameters in the route. This method parses parameters embedded within the route and verifies them against various rules.
   - **Supported Format Rules**:
     - **Starts/Ends With**:
       - `starts_with_and_length_is`: Matches if the parameter starts with a specified string and meets a specific length.
       - `ends_with_and_length_is`: Matches if the parameter ends with a specific string and length.
       - `starts_with`, `ends_with`: Matches if the parameter starts or ends with a specified substring.
     - **Character-based Rules**:
       - `character_at_position_is`: Matches if a specified character exists at a given position in the parameter.
       - `length_is`: Ensures the parameter has an exact length.
     - **Data Type Rules**:
       - `is_integer`, `is_numeric`, `is_string`: Validates the parameter as an integer, numeric, or string.
       - `is_urlcoded_json`: Checks if the parameter is URL-encoded JSON.
       - `is_md5`: Matches if the parameter is a valid MD5 hash.
       - `is_uuid`: Matches if the parameter is a valid UUID.
   - **Multi-Segment Parameter** (`...`): Captures all remaining path segments after a given point in the route. Supports flexible parameter structures for dynamic routes.

5. **Global Parameter Storage**
   - **`$_PARAM`**: Global array to store validated parameters from the URL. Accessible throughout the application for retrieving dynamic route values.

#### Error Handling

- **404 Not Found Handling**:
  - Redirects to a specified error page or displays a custom `404` error message.

#### Example

Suppose a route is defined as `/user/{userId}/profile`, where `{userId}` is validated to be an integer:
- **Request URL**: `/user/123/profile`
- **Parameter Matching**:
  - `userId` is extracted and validated as an integer.
  - Sets `$page` to the resolved profile page path if matched, or calls `not_found()` for invalid or unmatched URLs.

#### Summary

The Routing Module in Dataphyre provides a robust mechanism for handling dynamic routes with comprehensive parameter validation, error handling, and format-specific rules. This ensures URLs are processed accurately and dynamically, enabling flexible routing while maintaining strict validation standards.