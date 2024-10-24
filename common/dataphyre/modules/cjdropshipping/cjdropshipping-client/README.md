# CJ PHP Client Library
The CJ PHP Client library provides a comprehensive and efficient way to interact with the CJDropshipping API. Designed for seamless integration, it offers a robust set of tools to handle authentication, communication, and interaction with various API endpoints.

## Features
- **Easy Authentication**: Automates the process of obtaining and managing access tokens for uninterrupted API interactions.
- **Efficient Communication**: Streamlines requests and responses, simplifying the process of sending and receiving data from the CJDropshipping API.
- **Highly Customizable**: Offers flexible methods to cater to a wide range of API operations, allowing for tailored solutions to meet diverse business needs.
- **Error Handling**: Incorporates advanced error handling mechanisms to manage and troubleshoot issues effectively.
- **Response Parsing**: Efficiently parses API responses, providing clear and actionable data structures for application use.

The library is designed with simplicity and efficiency in mind, making it suitable for both beginners and experienced developers who need to integrate CJDropshipping functionalities into their applications. Its modular structure allows for easy expansion and customization, adapting to the evolving needs of various e-commerce solutions.

## Requirements
*   PHP version 8.0 or higher.
*   Access to CJDropshipping API.

## Installation
You can install the CJDropshipping PHP Client Library via Composer:
```bash
composer require CJ/CJ-client
```

## Initialization
Create an instance of `CJClient` with your CJDropshipping email and password.
```php
<?php
$CJClient = new CJ\CJClient('your-email@example.com', 'yourPassword');
?>
```

## Authentication
Upon instantiation, the class attempts to authenticate with the CJ API to obtain an access token. 
The token is automatically managed and refreshed by the class.

## Usage
Use the `createRequest` method to send requests to the CJ API. Specify the endpoint, request method, payload, and optionally a queue or a callback function.
```php
<?php
$response = $CJClient->createRequest('endpoint/path', 'POST', ['param1' => 'value1']);
?>
```

## Error Handling
The class throws exceptions for critical failures like network issues, authentication problems, or invalid JSON responses.

## Response Handling
Responses from API requests are returned as associative arrays containing status, message, and data (if available).

## Note
This class requires an external HttpClient class for making HTTP requests, which is not provided in this snippet.

## API Reference
The CJDropshipping API documentation can be found at https://developers.cjdropshipping.cn/en/api/introduction.html

## Financial Contribution
For financial support that won't cost you a dime, you may use our CJ affiliate links:
https://cjdropshipping.com/register.html?token=6ecdb2e8-4a45-468b-8e9a-691db1525c72

## License
This library is licensed under the MIT License - see the LICENSE file for details.