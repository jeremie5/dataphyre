

### Example 1: Performing Asynchronous HTTP Requests

#### GET Request

```php
// Perform an asynchronous GET request
$response = dataphyre\async::get_url('https://api.example.com/data', ['Accept: application/json'], true);

// Handle the response
$response->then(function($result) {
    echo "Response body: " . $result['body'];
    echo "Response headers: " . print_r($result['headers'], true);
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});
```

#### POST Request

```php
// Perform an asynchronous POST request
$response = dataphyre\async::post_url('https://api.example.com/submit', ['key' => 'value'], ['Content-Type: application/x-www-form-urlencoded'], true);

// Handle the response
$response->then(function($result) {
    echo "Response body: " . $result['body'];
    echo "Response headers: " . print_r($result['headers'], true);
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});
```

### Example 2: Working with JSON Data

#### GET JSON

```php
// Fetch JSON data asynchronously
$promise = dataphyre\async::get_json('https://api.example.com/data');

// Handle the JSON response
$promise->then(function($data) {
    echo "Data: " . print_r($data, true);
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});
```

#### POST JSON

```php
// Send JSON data asynchronously
$promise = dataphyre\async::post_json('https://api.example.com/submit', ['key' => 'value']);

// Handle the JSON response
$promise->then(function($data) {
    echo "Data: " . print_r($data, true);
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});
```

### Example 3: Working with Streams

#### Reading from a Stream

```php
// Open a file stream
$stream = fopen('path/to/file.txt', 'r');

// Read from the stream asynchronously
$promise = dataphyre\async::read_stream($stream);

// Handle the stream data
$promise->then(function($data) {
    echo "Stream data: " . $data;
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});

// Don't forget to close the stream when done
fclose($stream);
```

#### Writing to a Stream

```php
// Open a file stream
$stream = fopen('path/to/file.txt', 'w');

// Write to the stream asynchronously
$promise = dataphyre\async::write_stream($stream, "Hello, world!");

// Handle the result
$promise->then(function($result) {
    echo "Bytes written: " . $result;
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});

// Don't forget to close the stream when done
fclose($stream);
```

### Example 4: Handling Timeouts and Retries

#### Setting Timeout

```php
// Create a promise with a timeout
$promise = dataphyre\async::timeout(async::get_json('https://api.example.com/data'), 5000);

// Handle the response
$promise->then(function($data) {
    echo "Data: " . print_r($data, true);
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});
```

#### Retrying a Task

```php
// Define a task that might fail
$task = function() {
    // Simulate a task that fails
    throw new Exception("Task failed");
};

// Retry the task 3 times with a 1-second delay
$promise = dataphyre\async::retry($task, 3, 1000);

// Handle the result
$promise->then(function() {
    echo "Task succeeded";
})->catch(function($error) {
    echo "Error: " . $error->getMessage();
});
```

### Example 5: Using the Event Loop

#### Running the Event Loop

```php
// Add tasks to the event loop
async::add_to_event_loop(function() {
    echo "Task 1\n";
}, 1);

async::add_to_event_loop(function() {
    echo "Task 2\n";
}, 2);

// Run the event loop
async::run_event_loop();
```