# Dpanel Module Documentation

## Overview
The `Dpanel` module in Dataphyre is a universal, flexible diagnostic and testing toolset tailored for both the core Dataphyre framework and any applications built on it. Designed with extensibility and robustness, `Dpanel` supports unit testing, structured output validation, error tracking, and diagnostics at a module level. It operates in `RUN_MODE` set to `dpanel`, dynamically adjusting its function to offer targeted diagnostics as specified.

This document provides a detailed explanation of each component, function, and diagnostic capability within the `Dpanel` module.

---

## Table of Contents
- [Getting Started](#getting-started)
- [Class Properties](#class-properties)
- [Core Functions](#core-functions)
  - [Constructor](#constructor)
  - [Unit Testing](#unit-testing)
  - [Module Diagnostics](#module-diagnostics)
  - [PHP Code Validation](#php-code-validation)
  - [Trace Log Capture](#trace-log-capture)
- [Usage Examples](#usage-examples)

---

## Getting Started

To initiate Dpanel diagnostics within Dataphyre:
1. Set the `RUN_MODE` to `dpanel` in your application entry file.
2. Include the module through `require(__DIR__.'/dpanel.diagnostic.php');`.

With `RUN_MODE` set to `dpanel`, Dpanel loads diagnostic tools, unit testing functions, and error tracking features that are essential for maintaining code quality and consistency.

## Class Properties

### `public static $catched_tracelog`
- Tracks diagnostic information, structured into `errors` and `info` categories, and accessible for external log capture.

### `private static $core_module_path`
- Contains the file path of Dataphyre's core module, needed to locate and run diagnostic procedures.

### `public static $external_verbose`
- Stores verbose outputs and error messages from executed test cases and diagnostic procedures.

---

## Core Functions

### Constructor

```php
function __construct()
```

Upon instantiation:
- Defines `RUN_MODE` as `"diagnostic"`.
- Loads the `tracelog_override.php` for overriding default Tracelog behavior.
- Initializes `$core_module_path` to the path of the core module.

---

### Unit Testing

```php
public static function unit_test(string $json_file_path, array &$verbose=[]): bool
```

The `unit_test` function is designed to run structured tests on individual functions based on predefined JSON test cases. This function supports advanced validation, including type checking, custom comparisons, and dynamic file path evaluations.

#### Parameters
- **`json_file_path`** *(string)*: The path to the JSON file containing test case definitions.
- **`verbose`** *(array, reference)*: An optional array to store detailed diagnostic outputs from each test case, such as errors and execution details.

#### Returns
- **`bool`**: Returns `true` if all tests pass; returns `false` if any test fails.

---

#### Test Case Structure in JSON

Each test case in the JSON file should be structured with the following properties:

- **`name`** *(string)*: Descriptive name of the test case for identification.
- **`function`** *(string)*: The function to test. This function should be defined within the specified file.
- **`file`** *(string, optional)*: Path to the file containing the function.
- **`file_dynamic`** *(string, optional)*: If specified, evaluates this string dynamically to generate the file path. Used if the file path is context-dependent.
- **`args`** *(array)*: Arguments to pass to the function during the test.
- **`expected`** *(mixed)*: Defines expected results, which can take several forms for flexible validation:
  - **Direct Value**: Exact match required (e.g., `12`).
  - **Range**: Specifies a `min` and `max` value (e.g., `{ "min": 10, "max": 15 }`).
  - **Regex**: Specifies a pattern using `regex:` (e.g., `"regex:^\\S+@\\S+\\.\\S+$"`).
  - **Type**: Ensures that the return type matches (e.g., `"int"`, `"string"`).
  - **Custom Script**: `custom_script` enables evaluation using PHP code with `$result` as the return value.

---

#### Internal Validation and Matching Logic

To handle diverse validation needs, `unit_test` includes various helper functions:

1. **Array Structure Validation (`validate_array_structure`)**:
   This recursive function checks nested array structures against a defined template, ensuring the actual data structure aligns with expected types.

2. **Expected Outcome Matching (`matches_expected`)**:
   This function checks the test result against the `expected` value using a series of checks, including type verification, regex matching, and custom script evaluation.

---

#### Execution Flow

1. **Load and Parse JSON**:
   The function reads the JSON file specified by `json_file_path` and parses it into an array. If parsing fails, an exception is raised.

2. **Loop Through Each Test Case**:
   For each test case:
   - **Locate Function File**:
     - If `file` is specified, the function attempts to include the file.
     - If `file_dynamic` is specified, it dynamically evaluates the file path using `eval()`.
   - **Execute Function**:
     - Calls the function using `call_user_func_array`, passing in the arguments from `args`.
   - **Validate Output**:
     - Compares the function result to `expected` values using `matches_expected`.

3. **Record and Report Results**:
   - If the result does not match any `expected` condition, the test case fails. Details are added to `verbose`, including function name, input arguments, expected outcomes, and execution time.
   - Any thrown exceptions are caught, and error details are stored in `verbose`.

4. **Return Final Status**:
   - If all tests pass, `unit_test` returns `true`. If any test fails, it returns `false`, with `verbose` containing detailed logs of failures.

---

### Example JSON Test Cases

```json
[
    {
        "name": "Basic Math Test",
        "function": "add",
        "file": "/math/basic_operations.php",
        "args": [5, 7],
        "expected": 12
    },
    {
        "name": "Range Test",
        "function": "calculate_discount",
        "file": "/sales/discounts.php",
        "args": [450, "WINTER30"],
        "expected": { "min": 20, "max": 50 }
    },
    {
        "name": "Regex Test",
        "function": "get_formatted_date",
        "file": "/utils/date.php",
        "args": ["2024-12-25"],
        "expected": "regex:^\\d{4}-\\d{2}-\\d{2}$"
    },
    {
        "name": "Custom Comparison Test",
        "function": "is_valid_email",
        "file": "/utils/validators.php",
        "args": ["test@example.com"],
        "expected": { 
            "custom_script": "$result && strpos($result, '@example.com') !== false;"
        }
    }
]
```

---

### Example Usage

```php
$json_file_path = '/path/to/tests.json';
$verbose = [];
if (dpanel::unit_test($json_file_path, $verbose)) {
    echo "All tests passed successfully!";
} else {
    print_r($verbose); // Outputs details of failed tests
}
```

The `unit_test` function in Dpanel provides Dataphyre with powerful, flexible testing capabilities, ensuring both unit-level and integration-level reliability through its structured testing approach.

### Module Diagnostics

```php
public static function diagnose_module(string $module, array &$verbose=[]): bool
```

Performs diagnostics on a specified module by:
1. Validating PHP syntax.
2. Including the module for execution to capture any runtime errors.

#### Parameters
- `module`: The module name to diagnose.
- `verbose`: A reference array capturing diagnostic results, such as file or PHP syntax errors.

#### Returns
- `bool`: Returns `true` if diagnostics succeed, otherwise `false`.

### PHP Code Validation

```php
public static function validate_php(string $code): bool|string
```

Validates PHP code syntax to ensure no errors exist before runtime.

#### Parameters
- `code`: The PHP code string to validate.

#### Returns
- `bool|string`: Returns `true` if code is valid; otherwise, returns a descriptive error message.

### Trace Log Capture

```php
public static function catch_tracelog(bool $clear=true): string
```

Retrieves the current diagnostic trace logs. Optionally clears logs after retrieval.

#### Parameters
- `clear`: Whether to clear trace logs after retrieval. Default is `true`.

#### Returns
- `string`: Diagnostic trace log content.

---

## Usage Examples

### Running Unit Tests

To execute a series of unit tests from a JSON file:
```php
use dataphyre\dpanel;
$json_file_path = '/path/to/tests.json';
$verbose = [];
if (dpanel::unit_test($json_file_path, $verbose)) {
    echo "All tests passed!";
} else {
    print_r($verbose);
}
```

### Diagnosing a Module

To run a diagnostic on a module, check if there are any missing files or syntax issues:
```php
$verbose = [];
if (dpanel::diagnose_module('my_module', $verbose)) {
    echo "Module diagnostics passed!";
} else {
    print_r($verbose);
}
```

### Validating PHP Code

For standalone PHP code validation:
```php
$code = '<?php echo "Hello World"; ?>';
$result = dpanel::validate_php($code);
if ($result === true) {
    echo "PHP code is valid.";
} else {
    echo "PHP validation error: $result";
}
```