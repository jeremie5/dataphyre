### Sanitation Module

The **Sanitation** module in Dataphyre provides functionality to clean, validate, and anonymize various types of input data to prevent security risks such as XSS and SQL injection and ensure data integrity across applications. It offers specific methods for sanitizing URLs, email addresses, phone numbers, and more, as well as a general-purpose text sanitization method.

#### Key Functionalities

1. **Email Anonymization**: Masks part of the email for privacy.
2. **Data Type-Based Sanitization**: Handles different data types like URLs, phone numbers, emails, names, and general text.
3. **XSS Protection**: Removes or escapes potential XSS content in text.
4. **Customizable HTML Levels**: Offers "basic" and "unrestricted" HTML levels to control which HTML tags and attributes are permitted.
5. **Pattern-Based Validation**: Enforces specific patterns for data types, such as phone numbers and email formats.

---

#### Core Methods

1. **`anonymize_email(string $str, int $count=2, string $char='*') : string`**
   - **Purpose**: Masks part of an email address for privacy by replacing a portion of the local part (before `@`) with asterisks or a specified character.
   - **Parameters**:
     - `$str`: The email address to anonymize.
     - `$count`: The number of characters to display from the start of the email’s local part.
     - `$char`: The character to use for masking.
   - **Returns**: An anonymized email address.
   - **Example**:
     ```php
     $email = sanitation::anonymize_email("example@domain.com");
     // Output: "ex******@domain.com"
     ```

2. **`sanitize(mixed $string, string $datatype="default") : string|bool`**
   - **Purpose**: Sanitizes and validates input based on the specified data type to ensure safety and proper format.
   - **Parameters**:
     - `$string`: The string or mixed data to sanitize.
     - `$datatype`: The data type to sanitize. Options include `"url"`, `"phone_number"`, `"basic_html"`, `"unrestricted"`, `"text_nospecial"`, `"person_name"`, `"email"`, and `"default"`.
   - **Returns**: Sanitized string or `false` if validation fails.
   
   **Sanitization Based on Data Type**:
   
   - **URL (`"url"`)**: Decodes and validates the URL, ensuring it is properly formatted and contains no unwanted HTML tags.
   - **Phone Number (`"phone_number"`)**: Ensures only valid characters for phone numbers are included (numbers, spaces, `+`, `-`, `.`, `()`).
   - **Basic HTML (`"basic_html"`)**: Allows a restricted set of HTML tags and removes potentially harmful attributes, such as `on*` event handlers.
   - **Unrestricted HTML (`"unrestricted"`)**: Permits all HTML tags without filtering.
   - **Text Without Special Characters (`"text_nospecial"`)**: Removes all special characters except for a predefined set of accented letters and punctuation.
   - **Person Name (`"person_name"`)**: Removes unwanted characters, lowercases the string, and capitalizes each word.
   - **Email (`"email"`)**: Validates email format against a pattern.
   - **Default (`"default"`)**: No specific sanitization is applied other than basic XSS protection.

   **Additional Processing for XSS Protection**:
   - **XSS Filtering**: Applies sanitization to remove or escape potential XSS vulnerabilities by filtering special characters and encoding HTML entities.
   - **HTML Levels**:
     - **Basic**: Removes unsafe HTML elements and attributes, such as JavaScript events (`onclick`), and scripts.
     - **Unrestricted**: Allows all HTML tags without restriction.
   
   - **Example**:
     ```php
     $safe_text = sanitation::sanitize("<script>alert('hack');</script>", "basic_html");
     // Output: ""
     
     $phone = sanitation::sanitize("+1 (555) 123-4567", "phone_number");
     // Output: "+1 (555) 123-4567" (valid) or false (invalid)
     ```

---

#### Usage Workflow and Key Considerations

1. **Input Validation**: Each data type has specific validation rules, and any deviation results in `false`, indicating that the input does not meet the expected format.
2. **XSS Protection**: For text and basic HTML, this module performs extensive filtering to prevent injection attacks.
3. **Error Handling**: Logs warning messages for incorrect data types and sanitization attempts on non-string inputs.

The **Sanitation** module is vital in ensuring secure and clean data, allowing Dataphyre applications to handle user inputs safely and reliably.