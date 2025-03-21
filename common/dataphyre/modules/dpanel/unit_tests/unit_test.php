[
  {
    "name": "Should fail with missing JSON file",
    "function": "dataphyre\\dpanel::unit_test",
    "args": ["/nonexistent/file.json"],
    "expected": [
      {
        "custom_script": "return strpos(\$e = isset(\$GLOBALS['e']) ? \$GLOBALS['e']->getMessage() : '', 'not found') !== false;"
      }
    ],
    "dependencies": {
      "function": ["dataphyre\\dpanel::unit_test"]
    }
  },
  {
    "name": "Should detect a failed dependency",
    "function": "dataphyre\\dpanel::unit_test",
    "args": ["dpanel_mock_with_missing_dependency.json"],
    "expected": [false],
    "dependencies": {
      "function": ["dataphyre\\dpanel::unit_test"]
    }
  },
  {
    "name": "Should return true on valid test file",
    "function": "dataphyre\\dpanel::unit_test",
    "args": ["dpanel_mock_allpass.json"],
    "expected": [true],
    "dependencies": {
      "function": ["dataphyre\\dpanel::unit_test"]
    }
  },
  {
    "name": "Should validate execution time respect",
    "function": "dataphyre\\dpanel::unit_test",
    "args": ["dpanel_mock_slowtest.json"],
    "expected": [false],
    "dependencies": {
      "function": ["dataphyre\\dpanel::unit_test"]
    }
  }
]
