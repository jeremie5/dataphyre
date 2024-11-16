### Dataphyre Routing Module Documentation

The Routing Module in Dataphyre processes incoming URL requests, routing them to the appropriate resources. It manages dynamic routes, validates URL patterns, and supports extensive parameter validation formats, ensuring robust URL handling and error management.

---

### **Key Components**

#### **Initialization and Configuration**
- Loads routing configurations from pre-defined files and initializes required settings.
- Ensures fallback to a 404 Not Found page when no route matches:
  - `routing::not_found()`: Redirects to a configured error page or displays a custom 404 error if no page is specified.

---

### **Routing Mechanism**

#### **Routing Class**
The `routing` class manages URL routing operations, validates paths against routes, and handles unmatched requests.

**Properties:**
- `$page`: Holds the current route's resolved page path.
- `$realpage`: Stores the actual file path for the matched route.
- `$route_non_match_count`: Tracks the number of routes evaluated before finding a match.
- `$verbose_non_match`: Enables detailed logging for non-matching routes.

**Methods:**
1. **`not_found()`**: Handles unmatched routes by redirecting to a configured error page or displaying a 404 message.
2. **`set_page($file)`**: Normalizes and sets the current page. Returns the resolved file path.
3. **`check_route($route, $file)`**: Validates a route against the current request URI.
   - **Features:**
     - Captures dynamic route parameters using `{param}` notation.
     - Calls `set_page()` upon successful matching.
     - Logs details for matched and unmatched routes (when verbose mode is enabled).

---

### **Parameter Processing and Validation**

#### **`process_format_params($param_matches, $request, $route)`**
Validates and processes dynamic parameters embedded in routes. Each parameter can follow strict validation rules, ensuring routes are robust and secure.

**Supported Format Rules:**
- **Starts/Ends With:**
  - `starts_with_and_length_is`: Matches parameters starting with a specific string and of a specific length.
  - `ends_with_and_length_is`: Matches parameters ending with a specific string and of a specific length.
  - `starts_with`, `ends_with`: Matches parameters based on substrings.

- **Character-based Rules:**
  - `character_at_position_is`: Matches a character at a specified position in the parameter.
  - `length_is`: Ensures the parameter has an exact length.

- **Data Type Rules:**
  - `is_integer`, `is_numeric`, `is_string`: Validates parameters as integers, numeric values, or strings.
  - `is_urlcoded_json`: Checks if the parameter is valid URL-encoded JSON.
  - `is_md5`: Matches parameters against valid MD5 hashes.
  - `is_uuid`: Matches parameters against valid UUIDs.

- **Multi-Segment Parameter (`...`):**
  Captures all remaining path segments after a specific point in the route. Useful for flexible, dynamic routes.

#### **Global Parameter Storage**
- **`$_PARAM`**: A global array that stores all validated route parameters, making them accessible throughout the application.

---

### **Error Handling**

- **404 Not Found Handling:**
  - Redirects unmatched routes to a configured error page.
  - Displays a custom 404 message if no error page is configured.

---

### **Detailed Logging**
When `$verbose_non_match` is enabled, detailed logs for route matching are generated:
- Logs non-matching routes with reasons for failure.
- Provides verbose match information for debugging.

---

### **Example**

#### **Defined Route**
Suppose a route is defined as `/user/{userId}/profile`, where `{userId}` must be validated as an integer.

#### **Incoming Request**
- **URL:** `/user/123/profile`

#### **Parameter Matching**
1. `userId` is extracted from the route and validated as an integer.
2. If valid, `$page` is set to the resolved profile page path.
3. If invalid, `not_found()` is called to handle the unmatched route.

---

### **Usage Example**

#### **Route Configuration**
```php
routing::check_route('/user/{userId}/profile', '/views/user_profile.php');
```

#### **Request Handling**
- Request: `/user/123/profile`
- Matches `/user/{userId}/profile`
- Parameter extracted: `userId = 123`
- Resolved file: `/views/user_profile.php`

---

### **Summary**
The Dataphyre Routing Module is a powerful tool for managing dynamic routes with strict parameter validation and robust error handling. By supporting a wide range of validation rules and flexible configurations, it ensures accurate and secure URL processing, adaptable to any web application's needs.