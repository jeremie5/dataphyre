## Dataphyre Async Quick Examples

This file is a short practical companion to `documentation/Dataphyre_Async.md`.

### Kernel Examples

#### Async HTTP JSON

```php
$promise=\dataphyre\async::get_json('https://api.example.com/data');

$promise
	->then(function($data){
		echo json_encode($data);
	})
	->catch(function($error){
		echo $error->getMessage();
	});
```

#### Timeout

```php
$promise=\dataphyre\async::timeout(
	\dataphyre\async::get_json('https://api.example.com/data'),
	5000
);
```

#### Retry

```php
$promise=\dataphyre\async::retry(function(){
	return \dataphyre\async::get_json('https://api.example.com/data');
}, 3, 1000);
```

#### Timer

```php
$timer_id=\dataphyre\async::set_timeout(function(){
	echo 'Later';
}, 250);

\dataphyre\async::cancel($timer_id);
```

#### Event Loop

```php
\dataphyre\async::set_timeout(function(){
	echo "tick\n";
}, 100);

\dataphyre\async::run_event_loop();
```

### Framework Examples

Load the framework layer only when you need it:

```php
\dataphyre\core::load_framework_module('async');
```

#### Dispatch a Task

```php
use Dataphyre\Async\Async;

$task=Async::dispatch(function(){
	return 'done';
});

$task->then(function($value){
	echo $value;
});
```

#### Run Tasks in Parallel

```php
use Dataphyre\Async\Async;

Async::all([
	function(){ return 1; },
	function(){ return 2; },
	function(){ return 3; },
])->then(function(array $results){
	print_r($results);
});
```

#### Use a Pool

```php
use Dataphyre\Async\Async;

Async::pool(3)
	->map([10, 20, 30, 40], function(int $value){
		return $value / 10;
	})
	->then(function(array $results){
		print_r($results);
	});
```

#### Wrap a Kernel Promise

```php
use Dataphyre\Async\Async;

$task=Async::wrap(\dataphyre\async::get_json('https://api.example.com/data'));

if($task->pending()){
	// task is pending
}
```

### Notes

- Kernel async is the lowest-level, lowest-overhead path.
- Framework async adds nicer orchestration primitives but is optional.
- Background process jobs live primarily in `\dataphyre\async\process`.
