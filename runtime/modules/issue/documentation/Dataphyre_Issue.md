# Dataphyre Issue Module

The issue module is Dataphyre's kernel issue-reporting path. It is intentionally small and synchronous because it is used in failure handling, queue fallbacks, and wrapper compatibility code.

## What It Does

- creates deduplicated pending issues in the `issues` table
- encrypts stored issue context with Dataphyre core encryption
- emits severity-based notification emails through an application-provided callback
- supports legacy wrapper calls through the global `\issue` alias

## Bootstrap

Applications typically initialize the module once during wrapper/bootstrap setup:

```php
new dataphyre\issue(
	function(string $subject, string $body){
		\email::create(config('app/webmaster_email'), 'plain', [
			'subject'=>$subject,
			'body'=>$body,
		]);
	},
	config('app/version'),
	config('app/base_timezone'),
	[
		'userid'=>\dataphyre\access::userid(),
	]
);
```

The constructor stores:

- an optional email notification callback
- the current application version
- the timezone label used in notifications
- additional static context that should be merged into every issue

## Creating Issues

Kernel path:

```php
\dataphyre\issue::create(
	'FAILED_QUEUING_EMAIL',
	['recipient'=>$recipient],
	'Failed queuing an email for processing.',
	5
);
```

Legacy compatibility path:

```php
\issue::create(
	'SERVER_FATAL_ERROR',
	['error_data'=>$last_error],
	'Server error.',
	5
);
```

Method signature:

```php
\dataphyre\issue::create(
	string $type,
	array $context=[],
	string $description='',
	int $severity=0
): bool|int
```

Return value:

- returns the existing pending `issueid` when the same pending issue is already known
- returns the newly created `issueid` when a new issue is inserted
- returns `false` when the issue could not be stored

## Deduplication

Pending issues are deduplicated by:

- issue type
- merged static and per-call context
- severity

Dynamic runtime values like current server load are stored with the issue context, but they are not part of the deduplication key.

## Stored Context

Stored issue context is:

- merged from constructor-provided static context and per-call context
- enriched with `app_version`
- enriched with `load_level`
- JSON-encoded safely before encryption

## Recrypt

`recrypt(int $issueid)` schedules a deferred rewrite of legacy encrypted context through the SQL queue instead of doing the work inline on the current page load.

## Notes

- The module depends on `sql`.
- The module is kernel-only. It is not part of the optional Dataphyre framework layer.
- Notification delivery is optional. If no callback is configured, issues still persist normally.
