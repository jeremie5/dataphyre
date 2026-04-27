## Async Module - Dataphyre

### Mental Model

Dataphyre Async exposes two layers:

- **Kernel async** under `\dataphyre\async\*`
  - promises, coroutines, process tasks, event emitters, websocket server helpers, and async HTTP utilities
- **Framework async** under `\Dataphyre\Async\*`
  - application-facing dispatch, task wrappers, batches, pools, and dispatcher registration

Use the kernel surface when you want the lowest-level primitive directly.

Use the framework surface when you want application code to read as task orchestration instead of promise plumbing.

### At A Glance

| Task | Start Here | Why |
| --- | --- | --- |
| Dispatch one application task | `Dataphyre\Async\Async::dispatch(...)` | clean framework entrypoint |
| Wrap an existing kernel promise | `Dataphyre\Async\Async::wrap(...)` | keep kernel work but expose framework semantics |
| Run many tasks and wait for all | `Dataphyre\Async\Async::all(...)` or `\dataphyre\async\promise::all(...)` | choose framework or kernel style |
| Schedule lightweight in-process work | `\dataphyre\async\coroutine` | cooperative execution in the current process |
| Run file-backed background work | `\dataphyre\async\process` | out-of-band execution via generated task files |
| Build event-driven async flows | `\dataphyre\async\event_emitter` | listeners, wildcards, throttling, async mode |
| Limit concurrency across many items | `Dataphyre\Async\Pool` | concurrency-capped mapping |
| Add a custom framework driver | `Dataphyre\Async\AsyncManager::extend(...)` | register a dispatcher once and reuse it |

### Choosing A Surface

Choose **kernel async** when:

- you already work inside kernel-first code
- you want direct promise or coroutine primitives
- you want file-backed process tasks
- you do not need framework wrappers

Choose **framework async** when:

- you want a clearer application-facing API
- you want `PendingTask`, `Batch`, and `Pool`
- you want named dispatcher drivers
- you want one consistent task orchestration surface across app code

### Module Loading

Kernel loading follows normal Dataphyre module boot.

Framework loading is explicit:

```php
\dataphyre\core::load_framework_module('async');
```

### Configuration

Kernel config is loaded from:

- `common/dataphyre/config/async.php`
- `applications/<app>/backend/dataphyre/config/async.php`

The owning kernel exposes the merged readonly config as `DP_ASYNC_CFG`.

The process runner uses these keys:

- `DP_ASYNC_CFG['dependencies']`
- `DP_ASYNC_CFG['included_vars']`
- `DP_ASYNC_CFG['excluded_vars']`

Example:

```php
return [
	'dependencies'=>[
		ROOTPATH['root'].'rootpaths.php',
		ROOTPATH['common_backend'].'wrapper.php',
	],
	'included_vars'=>[
		'lang'=>$lang,
	],
	'excluded_vars'=>[
		'db_connection'=>true,
	],
];
```

Framework config is read from `DP_ASYNC_CFG['framework']` and includes:

- `default_dispatcher`
- `pool_concurrency`

Example:

```php
return [
	'framework'=>[
		'default_dispatcher'=>'coroutine',
		'pool_concurrency'=>8,
	],
];
```

### Recommended Flow

For application code, the usual path is:

1. load the framework module
2. dispatch with `Dataphyre\Async\Async`
3. compose with `PendingTask`, `Batch`, or `Pool`
4. drop to kernel promises or coroutines only when you need lower-level control

Example:

```php
\dataphyre\core::load_framework_module('async');

use Dataphyre\Async\Async;

$task=Async::dispatch(function(int $order_id){
	return ['order_id'=>$order_id, 'status'=>'queued'];
}, [42]);

$task
	->then(function(array $payload){
		// use async result
	})
	->catch(function($reason){
		// handle failure
	});
```

## Kernel Surface

### `\dataphyre\async`

` \dataphyre\async` is the kernel facade for common async operations.

Main methods include:

- `get_url(...)`
- `post_url(...)`
- `get_json(...)`
- `post_json(...)`
- `read_stream(...)`
- `write_stream(...)`
- `throttle(...)`
- `debounce(...)`
- `queue(...)`
- `set_logger(...)`
- `create_cancellation_token()`
- `cancel_token(...)`
- `is_cancelled(...)`
- `process_batches()`
- `run_event_loop()`
- `parallel(...)`
- `with_timeout(...)`
- `set_timeout(...)`
- `set_interval(...)`
- `cancel(...)`
- `defer(...)`
- `await(...)`
- `set_context(...)`
- `get_context(...)`
- `timeout(...)`
- `retry(...)`
- `set_batch_size(...)`

HTTP example:

```php
$request=\dataphyre\async::get_json('https://api.example.com/orders/42');

$request
	->then(function($json){
		// decoded JSON payload
	})
	->catch(function($reason){
		// request failure
	});
```

Timeout and retry example:

```php
$task=\dataphyre\async::retry(function(){
	return expensive_call();
}, 3, 250);

$guarded=\dataphyre\async::timeout($task, 5000);
```

Cancellation and queue example:

```php
$token=\dataphyre\async::create_cancellation_token();

\dataphyre\async::queue(function()use($token){
	if(\dataphyre\async::is_cancelled($token)){
		return;
	}
	do_work();
});

\dataphyre\async::cancel_token($token);
```

Context example:

```php
\dataphyre\async::set_context('request_id', 'rq_123');

\dataphyre\async::defer(function(){
	$request_id=\dataphyre\async::get_context('request_id');
	log_async_step($request_id);
});
```

### `\dataphyre\async\promise`

` \dataphyre\async\promise` is the core primitive for composable async work.

Important methods include:

- `promise::all(array $promises): self`
- `promise::race(array $promises): self`
- `promise::allSettled(array $promises): self`
- `promise::with_timeout(callable $executor, int $timeout): self`
- `promise::timeout(self $promise, int $timeout): self`
- `promise::retry(callable $task, int $retries, int $delay=0): self`
- `then(...)`
- `catch(...)`
- `finally(...)`
- `cancel()`
- `on_cancel(...)`
- `state(): string`
- `settled(): bool`
- `value(): mixed`
- `is_cancelled(): bool`

Example:

```php
$user=new \dataphyre\async\promise(function($resolve, $reject){
	try{
		$resolve(fetch_user(42));
	}catch(\Throwable $throwable){
		$reject($throwable);
	}
});

$orders=new \dataphyre\async\promise(function($resolve){
	$resolve(fetch_orders(42));
});

\dataphyre\async\promise::all([$user, $orders])
	->then(function(array $results){
		[$user, $orders]=$results;
	});
```

### `\dataphyre\async\coroutine`

` \dataphyre\async\coroutine` is the cooperative in-process scheduler.

Important methods include:

- `create(callable $callable, int $priority=0): int`
- `run(): void`
- `sleep(int $seconds): void`
- `async(callable $callable): object`
- `set_timeout(callable $callable, int $milliseconds): int|void`
- `set_interval(callable $callable, int $milliseconds): int|void`
- `cancel(int $id): void`
- `defer(callable $callable): int`
- `await(callable $callable): mixed`
- `set_context(mixed $key, mixed $value): void`
- `get_context(mixed $key): mixed`

Example:

```php
\dataphyre\async\coroutine::create(function(){
	\dataphyre\async\coroutine::sleep(0.05);
	log_step('first');
}, 10);

\dataphyre\async\coroutine::create(function(){
	log_step('second');
}, 1);

\dataphyre\async\coroutine::run();
```

Promise-style coroutine example:

```php
$task=\dataphyre\async\coroutine::async(function(){
	return compute_report();
});

$task->then(function($report){
	// report is ready
});
```

### `\dataphyre\async\process`

` \dataphyre\async\process` is the file-backed background runner.

Important methods include:

- `create(int $start_line, string $file, array|null $variables=array(), $logging=false): string`
- `waitfor(string|null $taskid)`
- `waitfor_all()`
- `result(string|null $taskid, $wipe=true)`

This surface is different from promises and coroutines:

- it writes a generated task file into async cache
- it shells out to `php`
- it is useful when work must continue outside the current in-process coroutine loop

Task file example:

```php
$taskid=\dataphyre\async\process::create(__LINE__ + 1, __FILE__, [
	'user_id'=>42,
]);

return;

function task($vars){
	$user_id=$vars['user_id'];
	rebuild_user_cache($user_id);
	return ['ok'=>true];
}
// TASK-END
```

Later result example:

```php
\dataphyre\async\process::waitfor($taskid);
$result=\dataphyre\async\process::result($taskid);
```

### `\dataphyre\async\event_emitter`

` \dataphyre\async\event_emitter` provides event-driven async orchestration.

Capabilities include:

- prioritized listeners
- one-shot listeners
- wildcard listeners
- namespace listeners
- listener groups
- listener metadata
- payload transformers
- throttling and debouncing
- optional async listener execution
- propagation control
- default listener and event aliases

Example:

```php
$events=new \dataphyre\async\event_emitter();

$events->on('order.created', function(array $payload){
	index_order($payload['order_id']);
}, 10);

$events->once('order.created', function(array $payload){
	notify_once($payload['order_id']);
});

$events->add_wildcard_listener('order.*', function($event, ...$args){
	audit_async_event($event, $args);
});

$events->emit('order.created', ['order_id'=>42]);
```

Payload transformer and async mode example:

```php
$events->set_payload_transformer('inventory.changed', function(array $payload){
	$payload['normalized']=true;
	return $payload;
});

$events->enable_async_mode();
$events->emit('inventory.changed', ['sku'=>'ABC-123']);
```

### `\dataphyre\async\web_socket_server`

` \dataphyre\async\web_socket_server` is the kernel websocket server helper.

Main methods include:

- `__construct($address, $port)`
- `on($event, $callback)`
- `start()`

Example:

```php
$server=new \dataphyre\async\web_socket_server('127.0.0.1', 9001);

$server->on('message', function($client, string $message){
	handle_socket_message($client, $message);
});

$server->start();
```

## Framework Surface

### `Dataphyre\Async\Async`

`Async` is the main framework facade.

Methods include:

- `manager()`
- `dispatch(...)`
- `run(...)`
- `inline(...)`
- `coroutine(...)`
- `wrap(...)`
- `all(...)`
- `race(...)`
- `settled(...)`
- `pool(...)`
- `timeout(...)`
- `retry(...)`
- `after(...)`
- `every(...)`
- `cancel(...)`

Example:

```php
\dataphyre\core::load_framework_module('async');

use Dataphyre\Async\Async;

$image_resize=Async::coroutine(function(string $path){
	return resize_image($path);
}, ['/tmp/avatar.jpg']);

$image_resize->then(function($result){
	store_image_result($result);
});
```

### `Dataphyre\Async\Bootstrap`

`Bootstrap.php` is the framework bootstrap shim for the async module. It marks the framework layer as loaded without changing kernel behavior by itself.

Example:

```php
\dataphyre\core::load_framework_module('async');

if(defined('DATAPHYRE_ASYNC_FRAMEWORK_BOOTSTRAPPED')){
	// framework async surface is available
}
```

### `Dataphyre\Async\AsyncManager`

`AsyncManager` is the framework coordinator.

Methods include:

- `instance()`
- `flush()`
- `defaultDispatcher(): string`
- `poolConcurrency(): int`
- `extend(string $driver, callable $resolver): void`
- `dispatcher(?string $driver=null): Dispatcher`
- `dispatch(...)`
- `batch(...)`
- `pool(...)`

Built-in drivers:

- `inline`
- `sync`
- `coroutine`

Example:

```php
use Dataphyre\Async\AsyncManager;
use Dataphyre\Async\Contracts\Dispatcher;

$manager=AsyncManager::instance();

$manager->extend('custom', function(): Dispatcher {
	return new MyDispatcher();
});

$task=$manager->dispatch(function(){
	return 'ok';
}, [], 'custom');
```

### `Dataphyre\Async\PendingTask`

`PendingTask` wraps a kernel promise with framework-friendly semantics.

Methods include:

- `fromPromise(...)`
- `rawPromise()`
- `then(...)`
- `catch(...)`
- `finally(...)`
- `cancel()`
- `state(): string`
- `settled(): bool`
- `pending(): bool`
- `fulfilled(): bool`
- `rejected(): bool`
- `value(mixed $default=null): mixed`
- `reason(mixed $default=null): mixed`

Example:

```php
$task=Dataphyre\Async\Async::inline(function(){
	return ['ok'=>true];
});

if($task->fulfilled()){
	$payload=$task->value([]);
}
```

### `Dataphyre\Async\Batch`

`Batch` groups pending tasks and exposes promise combinators at the framework layer.

Methods include:

- `tasks(): array`
- `count(): int`
- `all(): PendingTask`
- `race(): PendingTask`
- `settled(): PendingTask`

Example:

```php
use Dataphyre\Async\AsyncManager;

$batch=AsyncManager::instance()->batch([
	fn()=>load_profile(42),
	fn()=>load_orders(42),
	fn()=>load_notifications(42),
]);

$all=$batch->all();
```

### `Dataphyre\Async\Pool`

`Pool` provides concurrency-limited mapping.

Methods include:

- `map(array $items, mixed $task): PendingTask`
- `each(array $items, mixed $task): PendingTask`

Example:

```php
use Dataphyre\Async\Async;

$pool=Async::pool(4, 'coroutine');

$pool
	->map([1, 2, 3, 4], function(int $value){
		return $value * 2;
	})
	->then(function(array $results){
		// [2, 4, 6, 8]
	});
```

### `Dataphyre\Async\Contracts\Dispatcher`

The dispatcher contract defines one method:

- `dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise`

Use it when you want a custom framework driver.

Example:

```php
use Dataphyre\Async\Contracts\Dispatcher;

final class MyDispatcher implements Dispatcher {
	public function dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise {
		return new \dataphyre\async\promise(function($resolve, $reject)use($task, $arguments){
			try{
				$resolve($task(...$arguments));
			}catch(\Throwable $throwable){
				$reject($throwable);
			}
		});
	}
}
```

### Built-In Dispatchers

#### `Dataphyre\Async\Dispatchers\InlineDispatcher`

Executes the task immediately and wraps the result in a kernel promise.

Example:

```php
$dispatcher=new Dataphyre\Async\Dispatchers\InlineDispatcher();
$promise=$dispatcher->dispatch(fn()=>42);
```

#### `Dataphyre\Async\Dispatchers\CoroutineDispatcher`

Dispatches through `\dataphyre\async\coroutine::async(...)`.

Example:

```php
$dispatcher=new Dataphyre\Async\Dispatchers\CoroutineDispatcher();
$promise=$dispatcher->dispatch(fn()=>heavy_async_step());
```

### `Dataphyre\Async\Support\TaskInvoker`

`TaskInvoker` is the common framework helper that turns task inputs into real calls.

It accepts:

- callables
- callable arrays
- class strings that resolve to invokable objects

Example:

```php
$result=Dataphyre\Async\Support\TaskInvoker::invoke(function(string $name){
	return strtoupper($name);
}, ['shopiro']);
```

## Common Workflows

### Fire One Task And Chain The Result

```php
use Dataphyre\Async\Async;

Async::dispatch(function(int $listing_id){
	return load_listing($listing_id);
}, [55])->then(function(array $listing){
	cache_listing_payload($listing);
});
```

### Run Several Reads In Parallel

```php
use Dataphyre\Async\Async;

Async::all([
	fn()=>load_profile(42),
	fn()=>load_orders(42),
	fn()=>load_messages(42),
])->then(function(array $results){
	[$profile, $orders, $messages]=$results;
});
```

### Use A Pool To Limit Concurrency

```php
use Dataphyre\Async\Async;

Async::pool(3)->each($listing_ids, function(int $listing_id){
	rebuild_listing_cache($listing_id);
});
```

### Use The Process Runner For Background File Tasks

```php
$taskid=\dataphyre\async\process::create(__LINE__ + 1, __FILE__, [
	'tenant_id'=>7,
]);

return;

function task($vars){
	rebuild_tenant_exports($vars['tenant_id']);
	return ['status'=>'done'];
}
// TASK-END
```

### Add A Custom Dispatcher Once

```php
use Dataphyre\Async\AsyncManager;

$manager=AsyncManager::instance();

$manager->extend('burst', function(){
	return new BurstDispatcher();
});

$task=$manager->dispatch(fn()=>reindex_search(), [], 'burst');
```

## Kernel To Framework Mapping

| Kernel Habit | Framework Equivalent | Use When |
| --- | --- | --- |
| `\dataphyre\async\promise::all(...)` | `Dataphyre\Async\Async::all(...)` | you want `PendingTask` instead of raw promise |
| `\dataphyre\async\coroutine::async(...)` | `Dataphyre\Async\Async::coroutine(...)` | you want coroutine driver through framework |
| raw `\dataphyre\async\promise` | `Dataphyre\Async\PendingTask` | you want state helpers and framework chaining |
| manual concurrency loop | `Dataphyre\Async\Pool` | you want concurrency caps without custom orchestration |
| custom promise executor | custom `Dispatcher` | you want a reusable framework driver |

## Common Pitfalls

### Async Does Not Mean Distributed Queue

The coroutine and framework dispatcher flow is in-process. It is useful for cooperative execution and orchestration, not as a durable worker queue.

If work must survive the current process, use `\dataphyre\async\process` or build a dedicated queue/worker layer.

### Process Tasks Need Explicit Dependencies

The process runner generates a standalone PHP file and executes it through `php`.

If a background task needs application bootstrap, rootpaths, wrappers, or globals, include those through:

- `dataphyre.async.dependencies`
- `dataphyre.async.included_vars`

### PendingTask Reads Do Not Block

`PendingTask::value()` returns the fulfilled value only when the wrapped promise is already fulfilled. It does not wait.

If you need composition, chain with `then(...)` or combine through `Batch` / `Async::all(...)`.

### Inline And Coroutine Drivers Have Different Costs

`inline` resolves immediately in the current call stack.

`coroutine` routes through the coroutine scheduler and is a better default when you want async composition semantics in framework code.

## Troubleshooting

### A Process Task Never Finishes

Check:

- `dataphyre.async.dependencies`
- filesystem write permissions for async cache tasks
- whether `php` is available to the spawned process
- whether the generated task body reaches `// TASK-END`

### A Framework Driver Is Not Registered

`AsyncManager::dispatcher(...)` throws when the requested driver name has no registered resolver.

Check:

- spelling of the driver name
- whether `extend(...)` runs before dispatch
- whether config points to the intended default dispatcher

### A Task Looks Successful But `value()` Is Empty

Check `state()` first.

`value()` returns the default unless the task is fulfilled. For rejected tasks, inspect `reason(...)`.

## Common Recipes

### Wrap A Kernel Promise In Framework Semantics

```php
\dataphyre\core::load_framework_module('async');

$kernel=\dataphyre\async::get_json('https://api.example.com/status');
$task=Dataphyre\Async\Async::wrap($kernel);

$task->then(function($payload){
	store_status($payload);
});
```

### Schedule Lightweight Work For Later In The Request

```php
\dataphyre\async::defer(function(){
	record_noncritical_metric();
});
```

### Use Batch Records With Explicit Drivers

```php
use Dataphyre\Async\AsyncManager;

$batch=AsyncManager::instance()->batch([
	['task'=>fn()=>load_a(), 'driver'=>'inline'],
	['task'=>fn()=>load_b(), 'driver'=>'coroutine'],
]);

$batch->settled()->then(function(array $results){
	// settled results
});
```

### Use A Class-Based Invokable Task

```php
final class BuildExport {
	public function __invoke(int $tenant_id): array {
		return ['tenant_id'=>$tenant_id, 'ok'=>true];
	}
}

$task=Dataphyre\Async\Async::dispatch(BuildExport::class, [7]);
```
