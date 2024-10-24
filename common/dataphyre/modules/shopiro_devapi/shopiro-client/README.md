# Shopiro PHP Client Library

## The Shopiro PHP Client Library is a powerful and easy-to-use PHP client for interacting with the Shopiro API. This comprehensive tool is designed to facilitate seamless integration with Shopiro, making it a go-to solution for developers looking to leverage the capabilities of Shopiro in their applications.

#### This library simplifies the process of making requests to the Shopiro platform, managing responses, and handling errors. It provides an intuitive interface for developers, allowing them to focus on building features without worrying about the underlying complexities of network communication. By abstracting the details of HTTP requests and responses, the Shopiro Client Library enables faster and more reliable development workflows.

Beyond basic request handling, the library offers advanced features like automatic network retries, request queuing, and the execution of chained API requests, ensuring robust and efficient interactions with the Shopiro API. Its design emphasizes ease of use and flexibility, accommodating a wide range of use cases from simple queries to complex transaction handling.
The inclusion of specialized classes, such as the Listing class and various listing object types, further enriches the library's utility, providing tailored solutions for specific aspects of the Shopiro ecosystem. These classes streamline tasks such as listing creation, modification, and retrieval, offering a structured and scalable approach to managing data.
With comprehensive documentation, clear usage examples, and adherence to modern coding standards, the Shopiro PHP Client Library is an indispensable tool for developers looking to harness the full potential of Shopiro.


## Requirements
- PHP 8.0 or higher
- cURL extension for PHP
- mbstring extension for PHP

## Installation
### Via Composer
Composer is a tool for dependency management in PHP, allowing you to declare the libraries your project depends on and it will manage (install/update) them for you.

To install the Shopiro PHP Client Library using Composer, run the following command in your project's root directory:
```bash
composer require shopiro/shopiro-client
```
After installing, you need to require Composer's autoloader:
```php
<?php
require_once 'vendor/autoload.php';
```
This command includes the Composer autoloader, which automatically loads the Shopiro PHP Client Library classes.
### Manually Without Composer
```php
<?php
require_once '/path/to/Shopiro-PHP-Client/Init.php'; // Replace with the actual path
```
Ensure you have downloaded the library and specified the correct path to Init.php.

## Usage

### Instantiation
```php
<?php
/*
Initialize Shopiro client
Replace 123456 with your application ID and 'your_private_key' with the private key obtained from Shopiro. These credentials, necessary for API authentication, can be acquired by registering your application at https://shopiro.ca/user/developer/applications
*/
$shopiro = new \Shopiro\ShopiroClient(123456, 'your_private_key');
```

### Low level usage
#### Here is a basic usage example of the ShopiroClient, requests can be done in a raw form or using scope specific methods or objects:
In the examples below it is assumed you have initialized \Shopiro\ShopiroClient as $shopiro with working credentials.
```php
<?php
// Example: Create a request
$response = $shopiro->createRequest(
    ['request_type' => 'get', 'request_scope' => '', 'request_action' => 'listing']
);

// Handle response
if ($response['status'] === 'success') {
    // Process successful response
} elseif ($response['status'] === 'failed') {
    // Handle failure
}
```

### Utilizing the Listing Class and its Methods
In the examples below it is assumed you have initialized \Shopiro\ShopiroClient as $shopiro with working credentials.
```php
<?php
// Create a listing in a given platform segment using a type and data array or object
$response = $shopiro->Listing->create('type', []); // 'marketplace_low_volume' specifies the listing type, [] is the listing data

// Retrieve multiple listings with a specified count and offset for pagination
$allListings = $shopiro->Listing->getAll(10, 0); // Get 10 listings starting from the first one

// Retrieve a single listing using a Shopiro Listing ID (SLID)
$singleListing = $shopiro->Listing->get('SLID12345678901'); // SLID is a unique identifier for each listing

// Modify a listing using a data array or object including the SLID
$modifiedListing = $shopiro->Listing->modify(['slid' => 'SLID12345678901', 'new_data' => 'value']); // Modify specific listing details

// Delete a listing immediately using its SLID
$deletedListing = $shopiro->Listing->delete('SLID12345678901'); // Deletes the specified listing

// Create a new empty listing object for a given type
$listing = $shopiro->Listing->create("marketplace_low_volume"); // 'marketplace_low_volume' specifies the listing type

// Retrieve an existing listing as an object using its SLID
$listing = $shopiro->Listing->get('SLID12345678901');

// Set various properties of the listing using helper methods
$listing->setTitle('en', 'Example Product');
$listing->setDescription('en', 'This is a detailed description of the product.');
$listing->setShippingData(['weight' => 2, 'x_dimension' => 10, 'y_dimension' => 10]);

// Save the changes to the existing listing, or create a new one if it did not previously exist
$createdListing = $listing->save(); // Either updates the existing listing or creates a new one
```

### Using the Address Class and Objects
In the examples below it is assumed you have initialized \Shopiro\ShopiroClient as $shopiro with working credentials.
#### Create an address
```php
<?php
// Create a new address using the specified type and data
$addressType = 'shipping'; // Example type
$addressData = [
    'first_name' => 'John',
    'last_name' => 'Doe',
    // ... other address data ...
];
$newAddress = $shopiro->Address->create($addressType, $addressData);
```
#### Retrieve All Addresses
```php
<?php
// Retrieve multiple addresses with a specified count and offset for pagination
$allAddresses = $shopiro->Address->getAll(10, 0); // Get 10 addresses starting from the first one
```
#### Retrieve a Single Address
```php
<?php
// Retrieve a single address by its ID
$singleAddress = $shopiro->Address->get(0123456789); // 0123456789 is an example address ID
```
#### Modify an Address
```php
<?php
// Modify an existing address using address data
$addressData = [
    'addressid' => '0123456789', // Example address ID
    // ... other updated address data ...
];
$modifiedAddress = $shopiro->Address->modify($addressData);
```
#### Set an Address as Primary
```php
<?php
// Set a specific address as the primary address
$addressData = ['addressid' => '0123456789', 'type'=>'shipping']; // 0123456789 is an example address ID
$primaryAddress = $shopiro->Address->set_primary($addressData);
```
#### Delete an Address
```php
<?php
// Delete an address immediately using its ID
$deletedAddress = $shopiro->Address->delete(0123456789); // 0123456789 is an example address ID
```
#### Using AddressObject for Detailed Manipulation
```php
<?php
// Create a new AddressObject with initial details
$addressObject = $shopiro->Address->create([
    'type' => 'billing',
    'first_name' => 'Jane',
    'last_name' => 'Smith',
    // ... other address details ...
]);

// Modify or add additional details to the address
$addressObject->setCity('New York');
$addressObject->setCountry('US');
$addressObject->setSubdivision('US-AZ');
$addressObject->setAddressLine1('123 Example Blvd');
$addressObject->setAddressLine2('Unit 123');
$addressObject->setPostalCode('12345');
$addressObject->setPhoneNumber('12345');

// Override initial details or add new ones
$addressObject->setType('shipping');
$addressObject->setFirstName('John');
$addressObject->setLastName('Doe');

// Save the new or modified address
// This method either updates the existing address or creates a new one if it didn't previously exist
$savedAddress = $addressObject->save();

// Delete the address
// This method removes the address from the system
$result = $addressObject->delete();
```

## Features
- Easy initialization with application ID and private key
- Support for PHP 8.0 and above
- Automatic handling of network retries
- Request queuing and execution
- Chained API requests with a maximum chain length of 64
- Detailed error handling and exceptions
- Comprehensive listing management through the Listing class and listing objects

## API Reference
The Shopiro API documentation can be found at [Shopiro API Documentation](https://shopiro.ca/developer/documentation/14/Getting-started).

## Contributing
Contributions to the Shopiro PHP Client Library are welcome. 
Please ensure that your code adheres to our existing coding standards.

## License
This library is licensed under the MIT License - see the LICENSE file for details.