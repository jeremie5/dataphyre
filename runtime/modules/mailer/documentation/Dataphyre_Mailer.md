# Dataphyre Mailer Module

The Mailer module is Dataphyre's native delivery layer for application email.
It has a small kernel facade for low-level sending and queue flushing, plus a
framework layer for provider abstraction, message normalization, templates,
localized subjects, scheduled outbox delivery, and async dispatch.

```php
\dataphyre\mailer::send([
	'to'=>'customer@example.com',
	'subject'=>'Welcome',
	'html'=>'<p>Hello {{ name }}</p>',
]);
```

Framework loading is explicit:

```php
\dataphyre\core::load_framework_module('mailer');

use Dataphyre\Mailer\Mailer;

Mailer::send([
	'to'=>['customer@example.com'=>'Avery Stone'],
	'template'=>'welcome',
	'data'=>['name'=>'Avery'],
	'template_options'=>[
		'subject_key'=>'mail.welcome.subject',
		'subject_fallback'=>'Welcome to {{ app }}',
	],
]);
```

## Providers

Mailer ships with first-party providers:

- `log` writes normalized messages to a local JSON-lines log.
- `cloudflare` posts the normalized message to a configured Cloudflare Worker or endpoint.
- `sendgrid` sends through SendGrid's Mail Send API.
- `smtp` sends through any SMTP server with optional TLS and LOGIN/PLAIN auth.
- `mailgun` sends through Mailgun's Messages API, including attachments.
- `postmark` sends through Postmark's Email API.
- `resend` sends through Resend's Emails API.
- `brevo` and `sendinblue` send through Brevo's transactional email API.
- `aws`, `aws_ses`, and `ses` send through AWS SES v2 with Signature V4.

```php
return [
	'dataphyre'=>[
		'mailer'=>[
			'default_provider'=>'sendgrid',
			'failover_providers'=>['aws', 'log'],
			'from'=>['email'=>'no-reply@example.com', 'name'=>'Example'],
			'providers'=>[
				'sendgrid'=>[
					'driver'=>'sendgrid',
					'api_key'=>env('SENDGRID_API_KEY'),
				],
				'smtp'=>[
					'driver'=>'smtp',
					'host'=>'smtp.example.com',
					'port'=>587,
					'secure'=>'tls',
					'username'=>env('SMTP_USERNAME'),
					'password'=>env('SMTP_PASSWORD'),
				],
				'mailgun'=>[
					'driver'=>'mailgun',
					'api_key'=>env('MAILGUN_API_KEY'),
					'domain'=>'mg.example.com',
				],
				'postmark'=>[
					'driver'=>'postmark',
					'server_token'=>env('POSTMARK_SERVER_TOKEN'),
				],
				'resend'=>[
					'driver'=>'resend',
					'api_key'=>env('RESEND_API_KEY'),
				],
				'brevo'=>[
					'driver'=>'brevo',
					'api_key'=>env('BREVO_API_KEY'),
				],
				'aws'=>[
					'driver'=>'aws_ses',
					'region'=>'us-east-1',
					'access_key'=>env('AWS_ACCESS_KEY_ID'),
					'secret_key'=>env('AWS_SECRET_ACCESS_KEY'),
				],
				'cloudflare'=>[
					'driver'=>'cloudflare',
					'endpoint'=>'https://mail.example.workers.dev/send',
					'api_token'=>env('CLOUDFLARE_MAIL_TOKEN'),
				],
			],
		],
	],
];
```

Cloudflare does not expose one universal mail-send API, so the Cloudflare
provider intentionally targets a Worker or private HTTP endpoint. Dataphyre
sends a consistent normalized payload and the Worker owns the final transport.

## Message Shape

Messages accept scalar addresses, address arrays, or `email => name` maps.

```php
Mailer::message([
	'from'=>['email'=>'support@example.com', 'name'=>'Support'],
	'reply_to'=>'care@example.com',
	'to'=>[
		'customer@example.com'=>'Customer Name',
	],
	'cc'=>[],
	'bcc'=>[],
	'subject'=>'Receipt',
	'html'=>'<p>Your receipt is attached.</p>',
	'text'=>'Your receipt is attached.',
	'attachments'=>[
		['filename'=>'receipt.txt', 'content'=>'Receipt body', 'type'=>'text/plain'],
	],
	'tags'=>['receipt'],
	'metadata'=>['order_id'=>123],
]);
```

## Templates And Localization

When Dataphyre Templating is available, Mailer renders template files and inline
templates through the templating engine. Without it, Mailer falls back to simple
`{{ key }}` replacement.

Template bundles can be stored as:

- `welcome.subject.tpl`
- `welcome.html.tpl`
- `welcome.text.tpl`

`subject_key` uses Dataphyre Localization when available:

```php
$rendered=Mailer::render('welcome', ['name'=>'Avery'], [
	'subject_key'=>'mail.welcome.subject',
	'subject_fallback'=>'Welcome, {{ name }}',
	'language'=>'en-CA',
]);
```

## Outbox, Scheduling, And Async

`Mailer::queue()` writes to `dataphyre.mailer_outbox`. The SQL module hydrates
the outbox and event tables from Mailer table definitions when they are missing.

```php
Mailer::queue([
	'to'=>'customer@example.com',
	'subject'=>'Queued message',
	'text'=>'This will be sent by the outbox.',
	'metadata'=>['priority'=>'high'],
]);

Mailer::flush(25);
```

Queued mail is processed by `priority DESC, created_at ASC`. Pass
`priority` in queue options or `metadata.priority` / `metadata.queue_priority`
on the message. Numeric priorities are clamped from `-1000` to `1000`; friendly
values include `critical`, `urgent`, `high`, `normal`, `low`, and `bulk`.

Enable scheduled flushing through Mailer config:

```php
return [
	'dataphyre'=>[
		'mailer'=>[
			'scheduler'=>[
				'enabled'=>true,
				'frequency'=>60.0,
				'batch_size'=>25,
				'prune'=>[
					'enabled'=>true,
					'options'=>[
						'events_days'=>90,
					],
				],
			],
		],
	],
];
```

Scheduler pruning is opt-in. When enabled, the same scheduler run flushes queued
mail and then calls `Mailer::prune()` with the configured retention overrides.

Outbox flushes can also throttle delivery per provider. This is useful during
domain warmup, SMTP pool protection, or when a provider plan has a strict burst
limit. Limits are disabled by default; when a provider reaches its cap for the
current flush run, remaining queued messages are deferred and a `rate_limited`
event is recorded.

```php
'outbox'=>[
	'rate_limits'=>[
		'enabled'=>true,
		'default_per_flush'=>100,
		'defer_seconds'=>60,
		'providers'=>[
			'smtp'=>20,
			'postmark'=>250,
			'resend'=>250,
		],
	],
],
```

Each flush also recovers stale `sending` rows by default. If a worker exits
after claiming a row but before writing a final status, another flush moves that
row back to `queued` after the configured timeout and records a
`stale_sending_recovered` event.

```php
'outbox'=>[
	'recover_stale_sending'=>[
		'enabled'=>true,
		'timeout_seconds'=>900,
		'batch_size'=>50,
	],
],
```

`Mailer::sendAsync()` dispatches through Dataphyre Async when the async framework
is available. If it is not available, the message is queued instead.

## Extending Providers

Applications can register custom providers without changing the kernel:

```php
Mailer::extend('postmark', static function(array $config) {
	return new App\PostmarkProvider($config);
});

Mailer::send($message, 'postmark');
```

## Batch Sending

`Mailer::sendBatch()` sends an array of messages and returns one `SendResult`
per input message. Providers with native batch APIs, currently Postmark and
Resend, use their batch endpoints when there is no failover chain. Other
providers fall back to ordered single-message sends.

```php
$results=Mailer::sendBatch([
	['to'=>'a@example.com', 'subject'=>'One', 'text'=>'Hello A'],
	['to'=>'b@example.com', 'subject'=>'Two', 'text'=>'Hello B'],
], 'resend');
```

## Failover And Delivery Visibility

Mailer can try a provider chain before returning failure. The primary provider
is attempted first, followed by `failover_providers`; duplicates are removed.

```php
Mailer::send([
	'to'=>'customer@example.com',
	'subject'=>'Receipt',
	'text'=>'Thanks for your order.',
], 'sendgrid', [
	'failover_providers'=>['aws', 'log'],
]);
```

The returned `meta.attempts` array records each provider attempt, and
`meta.failover_used` is true when a later provider succeeds. Queued messages now
also use configurable retry backoff:

```php
return [
	'dataphyre'=>[
		'mailer'=>[
			'outbox'=>[
				'retry_backoff_seconds'=>[60, 300, 900, 1800, 3600],
				'track_events'=>true,
			],
		],
	],
];
```

`Mailer::outboxSummary()` and `dataphyre\mailer::outboxSummary()` return counts
by outbox status for dashboards, health checks, and operator tools.

`Mailer::campaignSummary()` returns a bounded, database-portable rollup for
campaign, tag, template, or metadata filters by decoding recent outbox message
payloads and aggregating matching statuses, providers, and events.

```php
$summary=Mailer::campaignSummary([
	'campaign'=>'spring_launch',
	'tag'=>'newsletter',
	'since_days'=>30,
	'limit'=>1000,
]);
```

Use `Mailer::prune()` to clean old operational data with the configured
retention policy. It removes old terminal outbox rows, event logs, webhook
dedupe rows, and expired suppressions.

```php
Mailer::prune();

Mailer::prune([
	'outbox_sent_days'=>14,
	'events_days'=>90,
]);
```

Default retention keeps sent outbox rows for 30 days, suppressed rows for 90
days, failed rows for 180 days, and event/webhook rows for 180 days. Expired
suppression rows are pruned immediately.

Outbound sends also receive an idempotency key. Provide one through
`metadata.idempotency_key`, or Mailer will derive a stable key from the
normalized message. Providers that support outbound idempotency, such as Resend,
receive the key as their expected request header.

HTTP providers accept provider-level `headers` config and per-send
`headers` options. Brevo also supports `sandbox=>true`, which sends the
`X-Sib-Sandbox: drop` header for request validation without delivery.

Messages can declare unsubscribe metadata once and Mailer will emit standard
headers for every provider that supports message headers:

```php
Mailer::send([
	'to'=>'customer@example.com',
	'subject'=>'April update',
	'text'=>'...',
	'unsubscribe'=>[
		'url'=>'https://example.com/unsubscribe/token',
		'email'=>'unsubscribe@example.com',
		'one_click'=>true,
	],
]);
```

This generates `List-Unsubscribe` and, when requested, `List-Unsubscribe-Post`.
An explicitly supplied header value is preserved.

Delivery safety can protect non-production and warmup environments from sending
to real users. When enabled, Mailer checks every `to`, `cc`, and `bcc`
recipient before direct send, queue, batch send, or outbox flush.

```php
'delivery_safety'=>[
	'enabled'=>true,
	'allowed_domains'=>['example.test'],
	'allowed_emails'=>['founder@example.com'],
	'rewrite_to'=>'mail-sink@example.test',
	'block_unmatched'=>true,
],
```

Allowed recipients pass through unchanged. Unmatched recipients are rewritten to
`rewrite_to` when configured, with `X-Dataphyre-Original-TO/CC/BCC` audit
headers added. Without a rewrite target, unmatched recipients are blocked with a
delivery safety result before any provider is called.

Provider-native templates can be selected through metadata:

- Postmark: `template_id`, `template_alias`, `postmark_template_id`, or `postmark_template_alias`; data comes from `template_model`, `template_data`, or metadata.
- Brevo: `template_id` or `brevo_template_id`; params come from `template_params`, `template_data`, or metadata.
- Mailgun: `mailgun_template` or `template_name`; variables come from `template_variables` or `template_data`.

## Suppression List

Mailer can maintain a hashed suppression list for unsubscribes, bounces, manual
blocks, and abuse controls. Suppressions are enforced before direct sending,
queueing, and outbox retries. A queued message that becomes suppressed is moved
to `suppressed` instead of being retried until max attempts.

```php
Mailer::suppress('customer@example.com', 'unsubscribe', [
	'source'=>'preferences',
	'metadata'=>['campaign'=>'spring_launch'],
]);

Mailer::isSuppressed('customer@example.com'); // true
Mailer::unsuppress('customer@example.com');
```

By default, suppression rows store an email hash only. Enable `store_email` only
for operator workflows that need the address in the suppression table.

```php
return [
	'dataphyre'=>[
		'mailer'=>[
			'suppression'=>[
				'enabled'=>true,
				'enforce'=>true,
				'table'=>'dataphyre.mailer_suppressions',
				'store_email'=>false,
				'hash_salt'=>env('MAILER_SUPPRESSION_HASH_SALT'),
				'list'=>[
					['email'=>'blocked@example.com', 'reason'=>'manual'],
				],
			],
		],
	],
];
```

Pass `ignore_suppression=>true` only for exceptional operational mail that is
legally and product-wise allowed to bypass preference and bounce controls.

## Delivery Event Ingestion

Provider webhooks can feed back into Mailer so bounces, spam complaints, and
unsubscribes update suppression automatically.

```php
Mailer::ingestDeliveryEvent('sendgrid', [
	'event'=>'bounce',
	'email'=>'customer@example.com',
	'sg_message_id'=>'sendgrid-message-id',
]);

Mailer::ingestDeliveryEvent('aws', [
	'notificationType'=>'Complaint',
	'mail'=>['messageId'=>'ses-message-id'],
	'complaint'=>[
		'complainedRecipients'=>[
			['emailAddress'=>'customer@example.com'],
		],
	],
]);
```

Mailer also normalizes native webhook payload shapes for SendGrid, AWS SES,
Mailgun, Postmark, Resend, and Brevo. For example, Mailgun `event-data`,
Postmark `RecordType`/`Type`, Resend `type` plus `data`, and Brevo
`event`/`message-id` fields are flattened into the same event, recipient, and
message id fields used by tracing and suppression.

Normalized events `bounce`, `complaint`, and `unsubscribe` create suppression
rows with source metadata. Other events are recorded in the mailer event stream
without suppressing recipients.

Webhook endpoints can hand raw JSON to Mailer directly. A single object, a JSON
array, or an object with an `events` array are all accepted.

```php
$result=Mailer::ingestDeliveryWebhook(
	'sendgrid',
	file_get_contents('php://input'),
	getallheaders() ?: []
);
```

Optional HMAC verification is available for first-party or proxy webhooks:

```php
return [
	'dataphyre'=>[
		'mailer'=>[
			'webhooks'=>[
				'require_signature'=>true,
				'default_hmac_secret'=>env('MAILER_WEBHOOK_SECRET'),
				'signature_header'=>'x-dataphyre-mailer-signature',
				'dedupe_enabled'=>true,
				'events_table'=>'dataphyre.mailer_webhook_events',
				'providers'=>[
					'sendgrid'=>[
						'hmac_secret'=>env('SENDGRID_WEBHOOK_PROXY_SECRET'),
					],
				],
			],
		],
	],
];
```

Webhook event dedupe is enabled by default. Mailer stores a stable event hash in
`dataphyre.mailer_webhook_events`, so provider retries return
`duplicate=>true` without repeating suppression or event side effects.

## Health Snapshot

`Mailer::health()` returns a compact operator snapshot for dashboards, scheduler
checks, and deployment smoke tests.

```php
$health=Mailer::health(24);
```

The response includes default provider readiness, outbox counts, recent event
counts, suppression totals, and webhook ingestion counts for the requested
window. Provider rows also include a `capabilities` map for dashboards and
operator tooling, including support flags for SMTP, HTTP APIs, batch sending,
attachments, message headers, tags, metadata, native templates, idempotency
headers, webhook normalization, and provider sandbox mode.

## Message Trace

`Mailer::trace($messageId)` returns the outbox row, mailer events, and webhook
events associated with a message id. JSON columns are decoded for operator UIs
and support tools.

```php
$trace=Mailer::trace('mail_...');
```
