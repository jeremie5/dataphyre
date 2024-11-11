# Dpanel Module Documentation

## Overview

The `Dpanel` module in Dataphyre is a fully-fledged diagnostic and testing environment, designed as a powerful tool for both project and framework-level analysis. Far beyond a standard unit testing tool, `Dpanel` serves as an all-encompassing diagnostic suite with both web and in the future, CLI interfaces, capable of executing tests and diagnostics across entire applications, specific modules, or individual files. It provides a meticulous and formatted view of all system diagnostics, making it a cornerstone for any development environment built with Dataphyre.

With `Dpanel`, developers can:

1. **Run Comprehensive Unit Tests and Diagnostics**: `Dpanel` can execute unit tests across files, individual applications or parts thereof, or the framework or parts thereof, allowing for granular or broad-based testing as needed. This flexibility ensures that both targeted and global integrity checks can be performed seamlessly.

2. **Adaptive Environmental Diagnostics**: `Dpanel` includes a suite of environment diagnostic tools that inspect both the project and framework contexts. These diagnostics check system variables, configurations, dependencies, and runtime settings. When triggered, environment diagnostics automatically integrate with `Dpanel`’s unit tests, generating a complete, formatted report that highlights any environmental inconsistencies or potential issues impacting the system.

3. **Dependency and Configuration Verification**: With dynamic dependency checks for classes, functions, constants, and global variables, `Dpanel` ensures that all required resources are present and configured correctly, flagging any missing dependencies with descriptive error messages. This verification is invaluable for managing dependencies across complex applications with modular structures.

4. **Dynamic and Conditional File Handling**: `Dpanel` supports condition-based path resolution, enabling it to adaptively select file paths depending on environment-specific configurations. This feature allows for smooth transitions across different setups, ensuring consistent testing and diagnostics without manual intervention.

5. **Advanced Assertion and Validation Capabilities**: `Dpanel` offers flexible, condition-based assertions, allowing for regex patterns, custom scripts, and even conditional logic within validation routines. This is ideal for testing structured outputs, hierarchical data, and large nested arrays, where simple assertions fall short.

6. **Performance and Resource Monitoring**: With support for performance constraints (`max_millis`), `Dpanel` can track function execution times, helping identify potential bottlenecks. This ensures that any intensive operations or resource-heavy functions remain efficient and optimized.

7. **Recursive, Nested, and Granular Data Validation**: `Dpanel` enables recursive validation for nested arrays and hierarchical data structures. This is essential for applications that handle layered data, reducing the risk of data structure errors and supporting in-depth validation at every level.

8. **Robust Exception and Error Management**: By capturing exceptions and tracking dependency issues, `Dpanel` flags critical errors in real-time, providing detailed feedback across tests and environmental diagnostics. This saves time on debugging by catching and contextualizing unexpected errors early in the process.

9. **Extensible, Modular Architecture**: Built for extensibility, `Dpanel` allows for the addition of new diagnostic and testing features without impacting existing configurations. As projects grow or evolve, `Dpanel` can adapt to changing requirements, making it a scalable solution for long-term application maintenance.

Operating in `RUN_MODE` set to `dpanel`, the module dynamically adjusts its behavior based on the chosen parameters, offering precise, tailored diagnostics for each scenario. Whether used for a quick sanity check on individual modules or for a full-scale diagnostic sweep across an entire framework, `Dpanel` provides unmatched insights and control for Dataphyre-powered PHP projects, establishing it as a critical tool for ensuring project integrity and performance. 

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
    "name": "Test Array Structure and Regex",
    "function": "myComplexFunction",
    "args": [["hello", "world"]],
    "expected": [
      {
        "0": "array",
        "1": ["string", {"0": "regex:/[a-zA-Z]+/"}]
      }
    ],
    "dependencies": {
      "class": [
        {"SomeRequiredClass": "Dependency on SomeRequiredClass is missing."}
      ],
      "function": [
        {"helperFunction": "Function helperFunction is required but not found."}
      ],
      "global_variable": [
        {"some_global_var": "Global variable some_global_var is required but not defined."}
      ]
    },
    "file": "path/to/dependencies.php"
  },
  {
    "name": "Performance Test with Custom Script",
    "function": "calculateIntensiveOperation",
    "args": [10000],
    "expected": [
      {
        "min": 5000,
        "max": 10000,
        "custom_script": "return $result % 2 === 0;"
      }
    ],
    "max_millis": 200,
    "dependencies": {
      "function": [
        {"timeConsumingHelper": "Dependency on timeConsumingHelper function is missing."}
      ],
      "constant": [
        {"SOME_CONSTANT": "Constant SOME_CONSTANT is required for this test."}
      ]
    },
    "file": "path/to/time_dependent_file.php"
  },
  {
    "name": "Exception Handling and Array Structure",
    "function": "processData",
    "class": "DataProcessor",
    "args": [["data1", {"key": "value"}]],
    "expected": [
      ["array", {"0": "string", "1": {"0": "array", "1": ["string", "array"]}}]
    ],
    "dependencies": {
      "class": [
        {"DataValidator": "The DataValidator class is required but not found."}
      ],
      "function": [
        {"prepareData": "The helper function prepareData is needed for processing."}
      ],
      "global_variable": [
        {"data_cache": "Global variable data_cache must be defined."}
      ]
    },
    "static_method": false,
    "file": "path/to/processor.php"
  },
  {
    "name": "Conditional Dependency with Dynamic File",
    "function": "conditionalProcess",
    "args": [42],
    "expected": [
      {
        "custom_script": "$result > 40 && $result < 50;"
      }
    ],
    "dependencies": {
      "function": [
        {"alternativeFunction": "alternativeFunction must be defined for fallback processing."}
      ],
      "constant": [
        {"FALLBACK_MODE": "Constant FALLBACK_MODE must be set to choose the correct file."}
      ]
    },
    "file_dynamic": "'path/to/' . (defined('FALLBACK_MODE') ? 'fallback.php' : 'default.php')"
  },
  {
    "name": "Recursive Array Validation with Dependency Errors",
    "function": "complexArrayProcessor",
    "args": [[1, [2, [3, [4]]]]],
    "expected": [
      {
        "0": "array",
        "1": [
          {
            "0": "array",
            "1": [
              {
                "0": "array",
                "1": ["int", "array"]
              }
            ]
          }
        ]
      }
    ],
    "dependencies": {
      "function": [
        {"recursiveHelperFunction": "Function recursiveHelperFunction must be defined for recursive processing."}
      ],
      "constant": [
        {"RECURSION_DEPTH": "Constant RECURSION_DEPTH is needed for setting the recursion depth."}
      ]
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
