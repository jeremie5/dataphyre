### Sanitation Module

The **Sanitation** module in Dataphyre is responsible for normalizing and cleaning incoming values before the rest of the application touches them. It has two layers:

- the **kernel** layer for fast low-level sanitation helpers
- the optional **framework** layer for fluent single-value sanitation and schema-based payload cleaning

The module is meant to make input handling predictable and explicit. It does not replace business-rule validation; it focuses on cleaning, shaping, and rejecting obviously unsafe or malformed values.

---

#### Start Here

Use the **kernel** when:

- you want the fastest direct helper
- you are working inside existing legacy app code
- you only need to sanitize one value

Use the **framework** when:

- you want fluent builder-style sanitation
- you want to sanitize a whole request payload from a field map
- you want structured error reporting instead of scattered `false` checks

---

#### Kernel API

##### `anonymize_email(string $str, int $count=2, string $char='*'): string`

Masks the local part of an email address.

```php
$masked=\dataphyre\sanitation::anonymize_email('example@domain.com');
```

##### `sanitize(mixed $value, mixed $datatype_or_trim='default', ?bool $escape_html_legacy=null, ?string $legacy_datatype=null): string|bool`

Sanitizes one value and returns:

- a sanitized string on success
- `false` when the value is invalid for the requested type

This method accepts both forms:

```php
\dataphyre\sanitation::sanitize($value, 'email');
\dataphyre\sanitation::sanitize($value, true, true, 'numeric');
```

The second form is kept for compatibility with existing applications that still use the older trim / escape / datatype argument shape.

##### `sanitize_many(array $input, array $schema, bool $preserve_invalid=false): array`

Sanitizes multiple fields from a simple field-to-type map.

```php
$clean=\dataphyre\sanitation::sanitize_many($_POST, [
	'email'=>'email',
	'first_name'=>'person_name',
	'phone'=>'phone_number',
]);
```

---

#### Kernel Datatypes

Supported built-in sanitation types:

- `default`
- `url`
- `phone_number`
- `basic_html`
- `unrestricted`
- `text_nospecial`
- `person_name`
- `email`
- `numeric`
- `integer`
- `float`
- `boolean`
- `slug`
- `username`
- `postal_code`
- `alphanumeric`
- `ascii`

Type aliases accepted by the framework rule layer include:

- `text` -> `default`
- `html` -> `basic_html`
- `raw_html` -> `unrestricted`
- `phone` -> `phone_number`
- `name` -> `person_name`
- `int` -> `integer`
- `bool` -> `boolean`
- `postal` -> `postal_code`

---

#### Framework Loading

Load the optional framework surface explicitly:

```php
\dataphyre\core::load_framework_module('sanitation');
```

Framework namespace:

```php
use Dataphyre\Sanitation\Sanitation;
```

---

#### Framework API

##### Facade

The facade exposes direct helpers and higher-level orchestration:

- `Sanitation::sanitize(...)`
- `Sanitation::clean(...)`
- `Sanitation::string(...)`
- `Sanitation::bag(...)`
- `Sanitation::presets()`
- `Sanitation::hasPreset(...)`
- `Sanitation::registerPreset(...)`
- `Sanitation::presetSchema(...)`
- `Sanitation::preset(...)`
- `Sanitation::validatePreset(...)`
- `Sanitation::validatedPreset(...)`
- `Sanitation::presetOrFail(...)`
- `Sanitation::schema(..., array $defaults=[], array $options=[])`
- `Sanitation::validate(..., array $defaults=[], array $options=[])`
- `Sanitation::validated(..., array $defaults=[], array $options=[])`
- `Sanitation::schemaOrFail(..., array $defaults=[], array $options=[], ?string $message=null)`
- `Sanitation::validateOrFail(..., array $defaults=[], array $options=[], ?string $message=null)`
- `Sanitation::email(...)`
- `Sanitation::url(...)`
- `Sanitation::phone(...)`
- `Sanitation::name(...)`
- `Sanitation::numeric(...)`
- `Sanitation::integer(...)`
- `Sanitation::float(...)`
- `Sanitation::boolean(...)`
- `Sanitation::arrayValue(...)`
- `Sanitation::listValue(...)`
- `Sanitation::slug(...)`
- `Sanitation::username(...)`
- `Sanitation::postalCode(...)`
- `Sanitation::anonymizeEmail(...)`

##### Fluent single-value sanitation

```php
$email=Sanitation::string($_POST['email'] ?? null)
	->required()
	->trim()
	->lower()
	->email()
	->get();

$valid=Sanitation::string($_POST['email'] ?? null)
	->required()
	->email()
	->valid();
```

Available builder helpers include:

- `type(...)`
- `required()`
- `requiredIf(...)`
- `requiredUnless(...)`
- `requiredWith(...)`
- `requiredWithAll(...)`
- `requiredWithout(...)`
- `requiredWithoutAll(...)`
- `mustBePresent()`
- `presentIf(...)`
- `presentUnless(...)`
- `presentWith(...)`
- `presentWithAll(...)`
- `presentWithout(...)`
- `presentWithoutAll(...)`
- `nullable()`
- `trim()`
- `squish()`
- `lower()`
- `upper()`
- `escapeHtml()`
- `raw()`
- `min(...)`
- `max(...)`
- `fallback(...)`
- `withDefault(...)`
- `label(...)`
- `message(...)`
- `messages([...])`
- `accepted()`
- `declined()`
- `same(...)`
- `different(...)`
- `regex(...)`
- `digits(...)`
- `minValue(...)`
- `maxValue(...)`
- `minItems(...)`
- `maxItems(...)`
- `distinct()`
- `distinctIgnoreCase()`
- `uniqueBy(...)`
- `uniqueByIgnoreCase(...)`
- `excludeWhenBlank()`
- `in([...])`
- `notIn([...])`
- `startsWith(...)`
- `endsWith(...)`
- `contains(...)`
- `validate(...)`
- typed shortcuts like `email()`, `url()`, `name()`, `integer()`, `boolean()`, `arrayValue()`, `listValue()`, `slug()`, `postalCode()`

##### Schema-based payload sanitation

```php
$result=Sanitation::schema($_POST, [
	'email'=>'required|email|lower',
	'first_name'=>'required|name',
	'last_name'=>'required|name',
	'username'=>'required|username|lower|max:32',
	'terms'=>'accepted|boolean',
	'confirm_email'=>'required|email|same:email',
	'sellerid'=>'nullable|numeric',
	'bio'=>[
		'type'=>'basic_html',
		'nullable'=>true,
		'max'=>2000,
	],
	'status'=>'required|in:active,pending,disabled',
], [], [
	'labels'=>[
		'confirm_email'=>'email confirmation',
	],
	'messages'=>[
		'email'=>[
			'required'=>'Please enter your email address.',
			'email'=>'That email address is not valid.',
		],
		'confirm_email'=>[
			'same'=>'Your email confirmation must match your email address.',
		],
	],
]);

if($result->failed()){
	$errors=$result->errors();
}

$data=$result->all();
```

Dot-path fields are supported for nested payloads:

```php
$result=Sanitation::validate($_POST, [
	'profile.email'=>'required|email|lower',
	'profile.display_name'=>'required|name|max:120',
	'preferences.language'=>'nullable|ascii|lower|max:8',
]);

$email=$result->get('profile.email');
```

Wildcard array-item rules are supported for repeated structures:

```php
$result=Sanitation::validate($_POST, [
	'addresses'=>'required|list|min_items:1',
	'addresses.*.postal_code'=>'required|postal',
	'addresses.*.country_code'=>'required|ascii|upper|min:2|max:2',
	'addresses.*.phone'=>'nullable|phone',
], [], [
	'labels'=>[
		'addresses.*.postal_code'=>'address postal code',
	],
]);
```

Distinct rules can be applied either to the parent collection or to wildcard item fields:

```php
$result=Sanitation::validate($_POST, [
	'tags'=>'list|distinct',
	'addresses.*.postal_code'=>'required|postal|distinct',
	'users.*.email'=>'required|email|lower|distinct:ignore_case',
]);
```

For arrays of objects or nested list items, use `unique_by` on the parent collection:

```php
$result=Sanitation::validate($_POST, [
	'users'=>'required|list|unique_by:email',
	'addresses'=>'required|list|unique_by:postal_code,country_code',
	'contacts'=>'required|list|unique_by_ignore_case:profile.email',
]);
```

Exclusion rules let sanitation shape the validated payload instead of failing it:

```php
$result=Sanitation::validate($_POST, [
	'company_name'=>'name|exclude_when_blank',
	'vat_number'=>'ascii|upper|exclude_if:is_business,0',
	'discount_code'=>'slug|exclude_unless:has_discount,1',
]);
```

When an exclusion rule matches, the field is removed from the sanitized result instead of producing an error.

Conditional required rules are also supported:

```php
$result=Sanitation::validate($_POST, [
	'is_business'=>'boolean',
	'company_name'=>'name|required_if:is_business,1',
	'country'=>'ascii|upper|required',
	'state'=>'ascii|upper|required_unless:country,CA',
]);
```

Presence-based conditional required rules are supported too:

```php
$result=Sanitation::validate($_POST, [
	'email'=>'email',
	'phone'=>'phone',
	'contact_name'=>'name|required_with:email,phone',
	'state'=>'ascii|upper|required_with_all:country,city',
	'backup_email'=>'email|required_without:phone',
	'backup_phone'=>'phone|required_without_all:email,slack_id',
]);
```

Conditional presence rules are supported when a field must exist even if it may be empty:

```php
$result=Sanitation::validate($_POST, [
	'type'=>'ascii|lower|required',
	'metadata'=>'array|present_if:type,advanced',
	'profile_id'=>'numeric|present_unless:type,guest',
	'timezone'=>'ascii|present_with:locale',
	'country'=>'ascii|present_with_all:region,city',
	'backup_contact'=>'ascii|present_without:email',
	'slack_id'=>'ascii|present_without_all:email,phone',
]);
```

Schema-level rule activation is supported when a whole rule block should only run in certain cases:

```php
$result=Sanitation::validate($_POST, [
	'nickname'=>'sometimes|name|max:64',
	'vat_number'=>[
		'type'=>'ascii',
		'upper'=>true,
		'when'=>['field'=>'is_business', 'values'=>[1]],
	],
	'state'=>[
		'type'=>'ascii',
		'upper'=>true,
		'unless'=>['field'=>'country', 'values'=>['CA']],
	],
	'internal_note'=>[
		'type'=>'default',
		'when'=>static function(array $input, array $validated, array $meta): bool {
			return ($input['mode'] ?? null)==='admin';
		},
	],
]);
```

Use `sometimes` when a missing field should be ignored entirely. Use `when` and `unless` when the field should still be processed normally, including defaults and `required` rules, but only when the schema condition is active.

`when` and `unless` accept either:

- a field comparison like `['field'=>'status', 'values'=>['active']]`
- a presence test like `['field'=>'coupon_code', 'present'=>true]`
- a filled/blank test like `['field'=>'profile.email', 'filled'=>true]`
- a callback

When you want fail-fast controller flow instead of manual result checks:

```php
try{
	$data=Sanitation::validateOrFail($_POST, [
		'email'=>'required|email|lower',
		'password'=>'required|min:8',
	]);
}
catch(Dataphyre\Sanitation\SanitizationException $exception){
	$errors=$exception->errors();
	$raw_input=$exception->input();
}
```

String rule tokens supported by the framework include:

- sanitation types such as `email`, `name`, `numeric`, `slug`, `basic_html`, `array`, `list`
- `required`
- `sometimes`
- `present`
- `required_if:<field,value[,value...]>`
- `required_unless:<field,value[,value...]>`
- `required_with:<field[,field...]>`
- `required_with_all:<field[,field...]>`
- `required_without:<field[,field...]>`
- `required_without_all:<field[,field...]>`
- `present_if:<field,value[,value...]>`
- `present_unless:<field,value[,value...]>`
- `present_with:<field[,field...]>`
- `present_with_all:<field[,field...]>`
- `present_without:<field[,field...]>`
- `present_without_all:<field[,field...]>`
- `nullable`
- `trim`
- `no_trim`
- `squish`
- `lower`
- `upper`
- `raw`
- `min:<length>`
- `max:<length>`
- `same:<field>`
- `different:<field>`
- `accepted`
- `declined`
- `regex:<pattern>`
- `digits:<count>`
- `in:<a,b,c>`
- `not_in:<a,b,c>`
- `starts_with:<a,b>`
- `ends_with:<a,b>`
- `contains:<text>`
- `min_value:<number>`
- `max_value:<number>`
- `min_items:<count>`
- `max_items:<count>`
- `distinct`
- `distinct:ignore_case`
- `unique_by:<field[,field...]>`
- `unique_by_ignore_case:<field[,field...]>`
- `exclude_if:<field,value[,value...]>`
- `exclude_unless:<field,value[,value...]>`
- `exclude_when_blank`

Associative rule arrays can also use:

- `validate` or `validator` for one callback or an array of callbacks
- `in`, `not_in`, `starts_with`, `ends_with`, `contains` as arrays
- `same`, `different`, `regex`, `digits`, `min_value`, `max_value`, `distinct`, `distinct_ignore_case`, `unique_by`, `unique_by_ignore_case`, `sometimes`, `required_if`, `required_unless`, `required_with`, `required_with_all`, `required_without`, `required_without_all`, `present`, `present_if`, `present_unless`, `present_with`, `present_with_all`, `present_without`, `present_without_all`, `exclude_if`, `exclude_unless`, `exclude_when_blank`
- `when` and `unless` for schema-level rule activation
- `label` for a friendly field name
- `messages` for per-rule custom messages

Schema options can include:

- `labels` as `field => label`
- `messages` as `field => [rule => message]`

Custom callbacks receive:

```php
function(mixed $value, array $data, array $input, array $config): bool|string|null
```

Return:

- `true` or `null` to pass
- `false` to fail with a generic message
- a string to fail with a custom message

Schema activation callbacks used by `when` and `unless` receive:

```php
function(array $input, array $validated, array $meta): bool
```

The `$meta` array includes:

- `field`
- `field_pattern`
- `wildcard_values`
- `config`

##### Reusable presets

The framework includes a small preset registry for common payload shapes.

Built-in presets:

- `login`
- `registration`
- `address`
- `search_filters`

Use them directly:

```php
$result=Sanitation::preset('registration', $_POST);

if($result->fails()){
	$errors=$result->messages();
}

$data=Sanitation::validatedPreset('search_filters', $_GET, [
	'defaults'=>[
		'page'=>1,
		'per_page'=>25,
	],
]);

$registration=Sanitation::presetOrFail('registration', $_POST);
```

Inspect a preset schema:

```php
$schema=Sanitation::presetSchema('login');
```

Register your own preset:

```php
Sanitation::registerPreset('profile_update', [
	'schema'=>[
		'display_name'=>'required|name|max:120',
		'bio'=>'nullable|basic_html|max:2000',
	],
	'options'=>[
		'labels'=>[
			'display_name'=>'display name',
		],
	],
]);
```

Custom presets may also be registered as callbacks that return the same definition shape.

##### Input bag

```php
$input=Sanitation::bag($_POST);

$email=$input->string('email');
$nested_email=$input->string('profile.email');
$keyword=$input->whenFilled('keyword', static fn(string $value)=>trim($value));
$email=$input->email('email');
$page=$input->integer('pageno', 1);
$login=$input->validatedPreset('login');
$safe_login=$input->validatedPresetOrFail('login');
$payload=$input->validated([
	'keyword'=>'nullable|trim|max:120',
	'category'=>'nullable|numeric',
]);
```

The input bag offers:

- `all()`
- `get(...)`
- `has(...)`
- `present(...)`
- `missing(...)`
- `filled(...)`
- `blank(...)`
- `only(...)`
- `except(...)`
- `clean(...)`
- `string(...)`
- `text(...)`
- `textNoSpecial(...)`
- `basicHtml(...)`
- `integer(...)`
- `float(...)`
- `boolean(...)`
- `arrayValue(...)`
- `listValue(...)`
- `email(...)`
- `url(...)`
- `phone(...)`
- `name(...)`
- `numeric(...)`
- `slug(...)`
- `username(...)`
- `postalCode(...)`
- `sanitize(...)`
- `validate(...)`
- `validated(...)`
- `validatedOrFail(...)`
- `preset(...)`
- `validatePreset(...)`
- `validatedPreset(...)`
- `validatedPresetOrFail(...)`
- `whenPresent(...)`
- `whenFilled(...)`

Its `sanitize(...)` method also accepts the same `$defaults` and `$options` arguments as the facade schema flow.
Dot-path keys also work across `get(...)`, `has(...)`, `filled(...)`, typed accessors, `only(...)`, and `except(...)`.

##### Result object

Schema sanitation returns `SanitizationResult`, which provides:

- `passed()`
- `passes()`
- `failed()`
- `fails()`
- `all()`
- `validated()`
- `data()`
- `errors()`
- `messages()`
- `error(...)`
- `firstError()`
- `has(...)`
- `invalid(...)`
- `get(...)`
- `only(...)`
- `except(...)`
- `raw(...)`
- `input()`
- `ensureValid(...)`
- `throwIfFailed(...)`

##### Exception flow

Fail-fast sanitation uses `SanitizationException`.

It provides:

- `result()`
- `errors()`
- `input()`
- `firstError()`
- `context()`

---

#### Design Notes

- The kernel stays string-oriented for compatibility and speed.
- The framework layer adds casting for `integer`, `float`, and `boolean`.
- The framework layer also supports lightweight validation-style constraints such as `accepted`, `same`, `in`, and callback-based checks.
- `basic_html` strips unsafe event/script-style vectors while preserving simple markup.
- `unrestricted` is intentionally trust-based and should only be used for already-trusted HTML sources.
- `sanitize(...)` is about cleaning and shaping input. Application-level validation such as uniqueness, permissions, and domain rules should happen elsewhere.
- Nested payloads can be sanitized with dot-path keys such as `profile.email` or wildcard item rules such as `addresses.*.postal_code`.
- Parent collection rules like `array`, `list`, `min_items`, `max_items`, `distinct`, and `unique_by` work alongside wildcard child rules.
