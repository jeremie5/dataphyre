## Async Module - Dataphyre

### Overview

The **Async Module** enables asynchronous programming, event management, and rate-limited HTTP requests, allowing tasks to be executed concurrently with optional timeouts, retries, and batch processing. This module incorporates **Promises**, **Coroutines**, **Event Emitters**, and WebSocket support, making it highly versatile for handling various asynchronous operations.

### Dependencies

- **Promise**: Manages asynchronous operations with `then`, `catch`, and `finally` chaining.
- **Coroutine**: Manages cooperative multitasking with event loops, timeouts, and deferred executions.
- **WebSocket Server**: Enables real-time communication through WebSockets.
- **Event Emitter**: Manages event listeners and asynchronous events within the application.

### Configuration

The Async Module relies on configurations set in `async.php` within the Dataphyre configuration directory. Key configurations include:
- **`concurrency_limit`**: Sets the maximum number of concurrent tasks.
- **`rate_limit`**: Configures the maximum rate of task execution within a given period.
  
### Core Functionalities

1. **send_curl_request**
   - Sends an asynchronous HTTP request using cURL.
   - **Parameters**:
     - `$url` (string): The URL to which the request is sent.
     - `$options` (array): An array of cURL options.
   - **Returns**: `promise` - A promise resolving with the response data or rejecting on error.

2. **get_url**
   - Executes a `GET` request with optional headers and priority.
   - **Parameters**:
     - `$url` (string): The URL to request.
     - `$headers` (array): Optional headers.
     - `$return_headers` (bool): Whether to return headers in the response.
     - `$priority` (int): Task priority within the event loop.
   - **Returns**: `object` - A coroutine object for the request.

3. **post_url**
   - Executes a `POST` request with optional headers, priority, and data.
   - **Parameters**:
     - `$url` (string): The URL to request.
     - `$data` (array): Data to send in the request.
     - `$headers` (array): Optional headers.
     - `$return_headers` (bool): Whether to return headers in the response.
     - `$priority` (int): Task priority within the event loop.
   - **Returns**: `object` - A coroutine object for the request.

4. **get_json**
   - Sends a `GET` request and parses the response as JSON.
   - **Parameters**:
     - `$url` (string): URL of the JSON endpoint.
   - **Returns**: `promise` - A promise resolving with the decoded JSON data.

5. **post_json**
   - Sends a `POST` request with JSON-encoded data.
   - **Parameters**:
     - `$url` (string): URL of the JSON endpoint.
     - `$data` (array): Data to be sent as JSON.
   - **Returns**: `promise` - A promise resolving with the decoded JSON data.

6. **read_stream**
   - Asynchronously reads data from a stream.
   - **Parameters**:
     - `$stream`: The stream resource to read.
   - **Returns**: `promise` - Resolves with the data read or rejects on error.

7. **write_stream**
   - Asynchronously writes data to a stream.
   - **Parameters**:
     - `$stream`: The stream resource to write to.
     - `$data`: The data to write.
   - **Returns**: `promise` - Resolves if successful, rejects on error.

8. **throttle**
   - Throttles the execution of a task, ensuring it only runs once per specified interval.
   - **Parameters**:
     - `$key` (string): Unique key to identify the throttled task.
     - `$task` (callable): Task to execute.
     - `$interval` (int): Minimum time between executions in milliseconds.

9. **debounce**
   - Delays the execution of a task until a specified interval has passed without another call.
   - **Parameters**:
     - `$key` (string): Unique key to identify the debounced task.
     - `$task` (callable): Task to execute.
     - `$interval` (int): Time to wait before executing the task.

10. **queue**
    - Adds a task to the queue, processing them sequentially.
    - **Parameters**:
      - `$task` (callable): The task to be queued.

11. **set_logger**
    - Assigns a custom logger for async operations.
    - **Parameters**:
      - `$logger` (callable): The logging function.

12. **create_cancellation_token**
    - Generates a unique cancellation token for managing task cancellations.
    - **Returns**: `string` - The generated token.

13. **cancel_token**
    - Cancels tasks associated with a specific token.
    - **Parameters**:
      - `$token` (string): The token to cancel.

14. **is_cancelled**
    - Checks if a specific cancellation token has been canceled.
    - **Parameters**:
      - `$token` (string): The token to check.
    - **Returns**: `bool` - True if canceled, false otherwise.

15. **process_batches**
    - Processes all tasks in the current batch, managing concurrency and rate limiting.

16. **run_event_loop**
    - Runs the event loop, executing tasks based on priority and batching.

17. **with_timeout**
    - Wraps an executor function in a promise with a timeout.
    - **Parameters**:
      - `$executor` (callable): The task to execute.
      - `$timeout` (int): The timeout in milliseconds.
    - **Returns**: `promise` - Resolves if completed before the timeout, otherwise rejects.

18. **parallel**
    - Executes multiple tasks in parallel and waits for all to complete.
    - **Parameters**:
      - `$tasks` (array): Array of tasks to execute.
    - **Returns**: `promise` - Resolves with results of all tasks.

19. **on_event**
    - Registers an event listener.
    - **Parameters**:
      - `$event` (string): Event name.
      - `$listener` (callable): Listener function.
      - `$priority` (int): Listener priority.

20. **set_context / get_context**
    - Sets or retrieves a value in the coroutine's context.
    - **Parameters**:
      - `$key`: Key to set or retrieve.
      - `$value` (optional): Value to set for `set_context`.
    - **Returns**: The value associated with `$key` in `get_context`.

### Promise Class

The **Promise** class provides a mechanism for handling asynchronous operations in a structured way, allowing tasks to be completed at some point in the future with resolution, rejection, or cancellation options. This class allows for complex asynchronous workflows by chaining actions and grouping promises.

#### Properties

- **`$state`**: Tracks the state of the promise (`pending`, `fulfilled`, or `rejected`).
- **`$value`**: Holds the result of a resolved promise.
- **`$handlers`**: Stores callbacks for `then`, `catch`, and `finally` handlers.
- **`$on_cancel`**: Optional callback to be invoked if the promise is canceled.
- **`$is_cancelled`**: Boolean flag indicating if the promise has been canceled.

#### Core Methods

1. **`__construct(callable $executor, callable $on_cancel = null)`**
   - Initializes the promise and executes the given `executor` function. If provided, the `on_cancel` function is registered for potential cancellation handling.
   - **Parameters**:
     - `$executor`: Function defining the async task and handling resolution or rejection.
     - `$on_cancel`: Optional function to handle cancellation logic.

2. **`then(?callable $on_fulfilled = null, ?callable $on_rejected = null): self`**
   - Registers fulfillment and rejection handlers.
   - **Parameters**:
     - `$on_fulfilled`: Function to call on resolution.
     - `$on_rejected`: Function to call on rejection.
   - **Returns**: `self` - The promise itself, allowing chaining.

3. **`catch(callable $on_rejected): self`**
   - Registers a handler for rejection only.
   - **Parameters**:
     - `$on_rejected`: Function to call if the promise is rejected.
   - **Returns**: `self`

4. **`finally(callable $on_finally): self`**
   - Registers a handler to be executed regardless of promise resolution or rejection.
   - **Parameters**:
     - `$on_finally`: Function to execute after resolution or rejection.
   - **Returns**: `self`

5. **`cancel(): void`**
   - Cancels the promise if possible and triggers the `on_cancel` handler if defined.

6. **`on_cancel(callable $callback): self`**
   - Registers a custom callback to be executed upon cancellation.
   - **Parameters**:
     - `$callback`: Function to call if the promise is canceled.
   - **Returns**: `self`

7. **`all(array $promises): self` (Static)**
   - Takes an array of promises and resolves when all promises are fulfilled, or rejects if any promise fails.
   - **Parameters**:
     - `$promises`: Array of promises to wait for.
   - **Returns**: A new promise that resolves with an array of results or rejects if any promise fails.

8. **`race(array $promises): self` (Static)**
   - Resolves or rejects as soon as the first promise in the array resolves or rejects.
   - **Parameters**:
     - `$promises`: Array of promises to race.
   - **Returns**: A new promise that follows the state of the first settled promise in the array.

9. **`allSettled(array $promises): self` (Static)**
   - Resolves with the results of all promises, regardless of their states (fulfilled or rejected).
   - **Parameters**:
     - `$promises`: Array of promises to settle.
   - **Returns**: A new promise that resolves when all input promises are settled.

10. **`with_timeout(callable $executor, int $timeout): self` (Static)**
    - Wraps a promise and rejects it if it does not settle within the specified timeout.
    - **Parameters**:
      - `$executor`: The function defining the async task.
      - `$timeout`: Time in milliseconds before the promise times out.
    - **Returns**: A new promise that resolves or rejects based on the timeout constraint.

11. **`retry(callable $task, int $retries, int $delay = 0): self` (Static)**
    - Attempts to execute a task, retrying it on failure for the specified number of times.
    - **Parameters**:
      - `$task`: Function to execute and potentially retry.
      - `$retries`: Number of retry attempts.
      - `$delay`: Optional delay (in milliseconds) between attempts.
    - **Returns**: A promise that resolves on success or rejects after all retries are exhausted.

#### Private Methods

- **`handle(callable $on_fulfilled, callable $on_rejected): void`**
  - Manages the execution of `then` handlers based on promise state.
  
- **`resolve(mixed $value): void`**
  - Sets the promise to `fulfilled` and executes the `on_fulfilled` handlers.
  
- **`reject(string|object $reason): void`**
  - Sets the promise to `rejected` and executes the `on_rejected` handlers.

### Promise Usage Example

To create a promise with a retry mechanism and handle success or failure:

```php
$task = function() {
    // Define task logic here
};

$promise = promise::retry($task, 3, 1000); // 3 retries, 1 second delay

$promise
    ->then(function($result) {
        echo "Task succeeded: ", $result;
    })
    ->catch(function($error) {
        echo "Task failed after retries: ", $error->getMessage();
    });
```

The **Promise** class offers a robust interface for handling async operations with chaining, timeout control, retries, and batch management capabilities. It is integral for building non-blocking applications within Dataphyre.

### Coroutine Class

The **Coroutine** class in Dataphyre enables asynchronous task scheduling and cooperative multitasking. It facilitates managing tasks with priority-based scheduling, deferred execution, context management, and sleep functionality. Coroutines provide a robust, non-blocking approach to handle asynchronous operations effectively within a single-threaded environment.

#### Properties

- **`$tasks`**: Stores all scheduled tasks.
- **`$id`**: Counter for generating unique IDs for tasks.
- **`$waiting`**: Holds tasks that are in a waiting state.
- **`$fibers`**: Manages fiber-like structures, allowing for non-blocking multitasking.
- **`$event_loop_running`**: Flag to indicate if the event loop is active.
- **`$deferred`**: Tasks scheduled to run later.
- **`$context`**: Stores values that persist across coroutine tasks.
- **`$prioritized_tasks`**: Holds tasks based on their priority for prioritized execution.

#### Core Methods

1. **`create(callable $callable, int $priority = 0): int`**
   - Adds a new coroutine to the task list with an optional priority.
   - **Parameters**:
     - `$callable`: The function or task to be executed.
     - `$priority`: Optional priority level for task execution.
   - **Returns**: `int` - Unique task ID for the created coroutine.

2. **`run(): void`**
   - Starts the coroutine event loop, processing tasks based on their priority and handling deferred or asynchronous execution.

3. **`sleep(int $seconds): void`**
   - Pauses the coroutine for a specified number of seconds.
   - **Parameters**:
     - `$seconds`: Duration of the sleep in seconds.

4. **`async(callable $callable): object`**
   - Schedules a callable for asynchronous execution within the coroutine loop.
   - **Parameters**:
     - `$callable`: The function to be executed asynchronously.
   - **Returns**: `object` - The coroutine object managing the async execution.

5. **`set_timeout(callable $callable, int $milliseconds): void`**
   - Schedules a task to run once after a specified timeout.
   - **Parameters**:
     - `$callable`: The function to execute after the delay.
     - `$milliseconds`: Delay time in milliseconds.

6. **`set_interval(callable $callable, int $milliseconds): void`**
   - Repeatedly schedules a task to run at the specified interval.
   - **Parameters**:
     - `$callable`: The function to execute repeatedly.
     - `$milliseconds`: Interval time in milliseconds between executions.

7. **`cancel(int $id): void`**
   - Cancels a task based on its unique ID, removing it from the task queue.
   - **Parameters**:
     - `$id`: The ID of the task to cancel.

8. **`defer(callable $callable): int`**
   - Defers the execution of a task, adding it to the list of deferred tasks to be run later.
   - **Parameters**:
     - `$callable`: The function to defer.
   - **Returns**: `int` - ID of the deferred task.

9. **`await(callable $callable): mixed`**
   - Waits for the result of an asynchronous task, pausing the coroutine until the task completes.
   - **Parameters**:
     - `$callable`: The asynchronous function to wait for.
   - **Returns**: Result of the awaited callable.

10. **`set_context(mixed $key, mixed $value): void`**
    - Stores a value in the coroutine’s context, accessible across different coroutine tasks.
    - **Parameters**:
      - `$key`: The identifier for the context value.
      - `$value`: The value to store.

11. **`get_context(mixed $key): mixed`**
    - Retrieves a value from the coroutine’s context by its key.
    - **Parameters**:
      - `$key`: The identifier for the context value.
    - **Returns**: The stored value associated with the key.

#### Coroutine Usage Example

To schedule and manage asynchronous tasks with `Coroutine`:

```php
$task_id = coroutine::create(function() {
    // Task logic
}, priority: 1);

coroutine::run(); // Start the event loop
```

For setting a timeout and a repeated interval:

```php
coroutine::set_timeout(function() {
    echo "This runs after 500 milliseconds.";
}, 500);

coroutine::set_interval(function() {
    echo "This runs every 1 second.";
}, 1000);
```

To defer a task:

```php
coroutine::defer(function() {
    echo "Deferred task executed.";
});
```

The **Coroutine** class provides fine control over task scheduling, prioritization, and concurrency, essential for applications needing high levels of asynchronous execution.

### WebSocket Server Class

The **web_socket_server** class provides a WebSocket server implementation within the Dataphyre framework. It enables real-time communication between the server and connected clients, allowing asynchronous, event-driven data exchange. This class is ideal for chat applications, live notifications, and other real-time features.

#### Properties

- **`$address`**: The IP address or hostname where the server listens.
- **`$port`**: The port on which the server listens for WebSocket connections.
- **`$clients`**: Stores active client connections.
- **`$sockets`**: Tracks socket resources for the server and connected clients.
- **`$callbacks`**: Contains event callbacks for handling different WebSocket events.

#### Core Methods

1. **`__construct($address, $port)`**
   - Initializes a new WebSocket server instance at the specified address and port.
   - **Parameters**:
     - `$address`: The IP address or hostname for the WebSocket server.
     - `$port`: The port number for the WebSocket server to listen on.

2. **`on($event, $callback)`**
   - Registers an event listener for WebSocket events, such as `message`, `open`, or `close`.
   - **Parameters**:
     - `$event`: The name of the WebSocket event to listen for (e.g., `message`, `connection`, `disconnect`).
     - `$callback`: The function to execute when the event is triggered.

3. **`start()`**
   - Starts the WebSocket server, listening for incoming connections and handling events based on registered callbacks.
   - This method enters the event loop, managing client connections, messages, and disconnections.

#### Private Helper Methods

1. **`handshake($client)`**
   - Performs the WebSocket handshake protocol, upgrading an HTTP connection to a WebSocket connection.
   - **Parameters**:
     - `$client`: The client connection resource to establish as a WebSocket connection.

2. **`broadcast($client, $msg)`**
   - Sends a message to all connected clients, optionally excluding the originating client.
   - **Parameters**:
     - `$client`: The client resource that sent the message.
     - `$msg`: The message to broadcast to all connected clients.

3. **`unmask($payload)`**
   - Decodes (unmasks) incoming WebSocket frames according to the WebSocket protocol.
   - **Parameters**:
     - `$payload`: The raw data received from a WebSocket client.
   - **Returns**: The decoded message.

4. **`mask($text)`**
   - Encodes (masks) outgoing WebSocket frames for sending data to clients.
   - **Parameters**:
     - `$text`: The message to encode.
   - **Returns**: The masked data ready for transmission over the WebSocket.

#### Web Socket Server Usage Example

To start a WebSocket server and register a message handler:

```php
$server = new web_socket_server("127.0.0.1", 8080);

$server->on("message", function($client, $message) use ($server) {
    echo "Received message: $message\n";
    $server->broadcast($client, "Echo: $message");
});

$server->start();
```

In this example, the WebSocket server listens on `127.0.0.1:8080`, echoes received messages back to all connected clients, and handles WebSocket messages through a registered callback.

The **web_socket_server** class provides the tools to build WebSocket-based, real-time communication channels within the Dataphyre framework, supporting a variety of interactive applications.

### Event Emitter Class

The **event_emitter** class in Dataphyre is designed to manage events and listeners, allowing asynchronous and synchronous event-driven programming. This class provides extensive functionality, including support for listener priority, event throttling, and namespaces, making it versatile for managing complex event-driven interactions.

#### Properties

- **`$listeners`**: Stores registered listeners for each event.
- **`$max_listeners`**: Sets the maximum number of listeners allowed per event (default: 10).
- **`$default_listeners`**: Default listeners that are triggered if no other listener is registered for an event.
- **`$listener_groups`**: Organizes listeners into groups for easier management.
- **`$event_aliases`**: Allows aliasing of events.
- **`$logging_enabled`**: Indicates if event logging is enabled.
- **`$logger`**: Custom logger function to log events.
- **`$payload_transformers`**: Transforms the payload before passing it to listeners.
- **`$async_mode`**: Boolean indicating if the emitter operates asynchronously.
- **`$wildcard_handlers`**: Allows listeners to respond to events matching a pattern.
- **`$namespace_handlers`**: Supports grouping events by namespaces.
- **`$propagation_stopped`**: Tracks events where propagation has been stopped.

#### Core Methods

1. **`on(string $event, callable $listener, int $priority = 0, string $group = null): void`**
   - Registers an event listener with optional priority and grouping.
   - **Parameters**:
     - `$event`: Event name.
     - `$listener`: Callback function for the event.
     - `$priority`: Priority level (higher values indicate higher priority).
     - `$group`: Optional group to which this listener belongs.

2. **`emit(string $event, ...$args): void`**
   - Triggers the event, executing all registered listeners for it.
   - **Parameters**:
     - `$event`: Event name to emit.
     - `$args`: Arguments passed to each listener function.

3. **`remove_listener(string $event, callable $listener): void`**
   - Removes a specific listener from an event.
   - **Parameters**:
     - `$event`: The event name.
     - `$listener`: The listener function to remove.

4. **`once(string $event, callable $listener, int $priority = 0): void`**
   - Registers a listener that is executed only once, then removed.
   - **Parameters**:
     - `$event`: The event name.
     - `$listener`: The one-time listener function.
     - `$priority`: Optional priority level.

5. **`set_max_listeners(int $max_listeners): void`**
   - Sets the maximum number of listeners for each event.
   - **Parameters**:
     - `$max_listeners`: Maximum listeners allowed per event.

6. **`get_listener_count(string $event): int`**
   - Returns the count of listeners for a specific event.
   - **Parameters**:
     - `$event`: The event name.
   - **Returns**: `int` - The count of listeners.

7. **`remove_all_listeners(string $event = null): void`**
   - Removes all listeners for a specified event, or all events if no event is specified.
   - **Parameters**:
     - `$event`: Optional event name.

8. **`set_default_listener(callable $listener): void`**
   - Sets a default listener to handle events without specific listeners.
   - **Parameters**:
     - `$listener`: The function to set as default.

9. **`set_event_alias(string $event, string $alias): void`**
   - Creates an alias for an event name, allowing it to be triggered by either name.
   - **Parameters**:
     - `$event`: Original event name.
     - `$alias`: Alias name for the event.

10. **`enable_logging(callable $logger): void`**
    - Enables logging for events with a custom logger function.
    - **Parameters**:
      - `$logger`: The logging function.

11. **`disable_logging(): void`**
    - Disables event logging.

12. **`inspect_listeners(string $event): array`**
    - Returns an array of listeners registered for a specific event.
    - **Parameters**:
      - `$event`: The event name.
    - **Returns**: `array` - Listeners for the event.

13. **`throttle(string $event, int $interval): void`**
    - Limits the rate at which an event can be triggered.
    - **Parameters**:
      - `$event`: The event name.
      - `$interval`: Minimum time (in milliseconds) between consecutive triggers.

14. **`debounce(string $event, int $interval): void`**
    - Delays the execution of an event until a specified time has passed without it being triggered again.
    - **Parameters**:
      - `$event`: The event name.
      - `$interval`: Delay time in milliseconds.

15. **`set_payload_transformer(string $event, callable $transformer): void`**
    - Registers a transformer function to modify the payload before passing it to listeners.
    - **Parameters**:
      - `$event`: Event name.
      - `$transformer`: Transformer function.

16. **`enable_async_mode(): void`**
    - Enables asynchronous execution mode for listeners.

17. **`disable_async_mode(): void`**
    - Disables asynchronous execution mode for listeners.

18. **`get_group_listeners(string $group): array`**
    - Retrieves listeners associated with a specific group.
    - **Parameters**:
      - `$group`: Group name.
    - **Returns**: `array` - Listeners in the specified group.

19. **`remove_group_listeners(string $group): void`**
    - Removes all listeners associated with a specific group.
    - **Parameters**:
      - `$group`: Group name.

20. **`stop_propagation(string $event): void`**
    - Stops the propagation of an event, preventing further listeners from being executed.
    - **Parameters**:
      - `$event`: The event name.

21. **`continue_propagation(string $event): void`**
    - Resumes event propagation if it was previously stopped.
    - **Parameters**:
      - `$event`: The event name.

22. **`add_wildcard_listener(string $pattern, callable $listener): void`**
    - Registers a listener for all events matching a specified pattern.
    - **Parameters**:
      - `$pattern`: Pattern for matching events.
      - `$listener`: The listener function.

23. **`emit_to_namespace(string $namespace, ...$args): void`**
    - Emits an event within a specific namespace, calling all listeners within that namespace.
    - **Parameters**:
      - `$namespace`: Namespace for the event.
      - `$args`: Arguments to pass to the listeners.

24. **`on_namespace(string $namespace, callable $listener): void`**
    - Registers a listener for events within a specific namespace.
    - **Parameters**:
      - `$namespace`: Namespace for events.
      - `$listener`: Listener function.

25. **`add_listener_with_metadata(string $event, callable $listener, array $metadata): void`**
    - Registers a listener with additional metadata for advanced handling.
    - **Parameters**:
      - `$event`: Event name.
      - `$listener`: Listener function.
      - `$metadata`: Array of metadata for the listener.

26. **`get_listener_metadata(string $event): array`**
    - Retrieves metadata for listeners of a specific event.
    - **Parameters**:
      - `$event`: Event name.
    - **Returns**: `array` - Metadata for the event's listeners.

27. **`add_conditional_listener(string $event, callable $listener, callable $condition): void`**
    - Registers a listener that only triggers if a specified condition is met.
    - **Parameters**:
      - `$event`: Event name.
      - `$listener`: Listener function.
      - `$condition`: Condition function to evaluate.

28. **`intercept_event(string $event, callable $interceptor): void`**
    - Adds an interceptor function that can modify or stop an event before listeners are triggered.
    - **Parameters**:
      - `$event`: Event name.
      - `$interceptor`: Interceptor function.

#### Event Emitter Usage Example

To register and trigger events using **event_emitter**:

```php
$emitter = new event_emitter();

// Register a listener
$emitter->on("user_login", function($user) {
    echo "User logged in: ", $user->name;
});

// Trigger the event
$emitter->emit("user_login", $user);
```

To use a one-time listener and throttling:

```php
$emitter->once("user_logout", function($user) {
    echo "User logged out: ", $user->name;
});

$emitter->throttle("update_stats", 1000); // Throttle to once per second
```

The **event_emitter** class offers flexible and powerful event management, supporting async execution, throttling, and conditional listeners for scalable, event-driven applications.

### Process Class

The **Process** class in Dataphyre handles asynchronous task creation and management. This class enables background task execution with timeout controls, task result retrieval, and resource cleanup, making it suitable for offloading time-consuming operations.

#### Properties

- **`$task_kill_list`**: Tracks task IDs and associated process IDs for cleanup and termination.
- **`$queued_tasks`**: Holds a list of currently queued tasks.
- **`$execution_timeout`**: Defines the timeout duration (in seconds) for each task before it is considered timed out.
- **`$waitfor_loop_time`**: The time interval (in microseconds) between checks for task completion.

#### Core Methods

1. **`waitfor_all()`**
   - Waits for all queued tasks to complete or time out. This method iterates through the list of queued tasks, calling `waitfor` on each.
   - **Behavior**: Checks each task in `queued_tasks` until completion or timeout.

2. **`waitfor(string|null $taskid)`**
   - Waits for a specific task to finish, with periodic checks to determine task completion or timeout.
   - **Parameters**:
     - `$taskid`: The unique identifier of the task to wait for.
   - **Behavior**:
     - If the task does not complete within `execution_timeout`, it is marked as timed out.
     - If a process ID is associated with the task, it kills the process and performs cleanup.

3. **`result(string|null $taskid, $wipe = true)`**
   - Retrieves the result of a completed task, optionally removing task data files.
   - **Parameters**:
     - `$taskid`: The task ID to retrieve the result for.
     - `$wipe`: Boolean indicating whether to delete the task result file after retrieval.
   - **Returns**: Decoded JSON result if the task is complete, or `"task_unfinished"` if the task has not finished.

4. **`create(int $start_line, string $file, array|null $variables = array(), $logging = false): string`**
   - Creates a new asynchronous task from a specified file and line range, injecting any provided variables.
   - **Parameters**:
     - `$start_line`: The starting line in the file where the task code begins.
     - `$file`: Path to the PHP file containing the task code.
     - `$variables`: Array of variables to inject into the task.
     - `$logging`: Boolean indicating whether to enable task-specific logging.
   - **Returns**: `string` - A unique task ID for tracking the task.

#### Task Workflow

1. **Task ID Generation**:
   - Each task is given a unique ID based on the current time and a large random number to avoid conflicts.

2. **Code Extraction and Execution**:
   - Reads code from the specified starting line until it encounters the `TASK-END` marker, forming the task's main logic.
   - Wraps the code with setup lines to define the task environment, load dependencies, and handle variables.

3. **Task Execution**:
   - Saves the task to a PHP file in the cache and runs it as a background process.
   - If `logging` is enabled, logging is initiated for the task.

4. **Result Handling and Cleanup**:
   - Each task writes its result to a file upon completion.
   - The `result` method retrieves this output and optionally deletes the task’s result file and source code from the cache.

#### Process Usage Example

To create and manage a background task:

```php
$taskid = process::create($start_line = 10, $file = "/path/to/task.php", $variables = ["var1" => "value1"], $logging = true);

// Wait for the task to complete
process::waitfor($taskid);

// Retrieve and handle the result
$result = process::result($taskid);
if ($result !== "task_unfinished") {
    // Process the task result
    print_r($result);
} else {
    echo "The task did not complete.";
}
```

This example creates a background task, waits for its completion, and retrieves the result for further processing.

The **Process** class in Dataphyre facilitates efficient background processing, enabling tasks to run concurrently without blocking the main execution flow. This class supports creating, monitoring, and retrieving results from asynchronous tasks, enhancing application responsiveness and scalability.

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