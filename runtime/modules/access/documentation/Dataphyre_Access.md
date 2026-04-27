## Access Module - Dataphyre

### Overview

The **Access** module is Dataphyre's authentication and session layer.

It has two distinct surfaces:

- **Kernel surface** via `\dataphyre\access`
  - Fast, low-level session and auth-type handling.
  - Safe for apps that want zero framework overhead.
- **Framework surface** via `\Dataphyre\Access\*`
  - Guards, providers, middleware, JWT request auth, and OAuth / OpenID Connect.
  - Loaded only when the application explicitly asks for it.

This supports both low-level auth handling and higher-level application auth flows.

### Module Loading

Kernel loading follows the normal Dataphyre module boot path.

Framework loading is explicit:

```php
\dataphyre\core::load_framework_module('access');
```

Once loaded, the framework namespace becomes available under `Dataphyre\Access\...`.

### Kernel Scope

Kernel scope is under the `dataphyre\*` namespace.

Primary class:

- `\dataphyre\access`

Core responsibilities:

- session creation and validation
- session recovery
- access gating for pages / handlers
- bot and mobile detection
- auth-type selection
- delegation to alternate auth handlers through dialbacks

### Kernel Configuration

The module reads its kernel config from `access.php` in common and app config paths.

Important kernel config keys include:

- `sessions_table_name`
- `sanction_on_useragent_change`
- `default_auth_type`
- `auth_types`

The owning kernel defines the effective readonly config constant as `DP_ACCESS_CFG`.

Example:

```php
return [
	'sessions_table_name'=>'sessions',
	'sanction_on_useragent_change'=>true,
	'default_auth_type'=>'session',
	'auth_types'=>[
		'session'=>['enabled'=>true],
		'jwt'=>['enabled'=>true],
	],
];
```

### Kernel Auth Types

Auth types are first-class. Session auth is the default path, and alternate auth types can be enabled and validated through dialbacks.

Relevant kernel methods:

- `\dataphyre\access::default_auth_type(): string`
- `\dataphyre\access::enabled_auth_types(): array`
- `\dataphyre\access::auth_type_enabled(string $auth_type): bool`
- `\dataphyre\access::create_session(int $userid, bool $keepalive=false, ?string $auth_type=null): bool`
- `\dataphyre\access::create_id(?string $auth_type=null): string`
- `\dataphyre\access::userid(?string $auth_type=null): bool|int|string`
- `\dataphyre\access::disable_session(?string $auth_type=null): bool`
- `\dataphyre\access::disable_all_sessions_of_user(int $userid, ?string $auth_type=null): bool`
- `\dataphyre\access::validate_session(bool $cache=true, ?string $auth_type=null): bool`
- `\dataphyre\access::recover_session(?string $auth_type=null): bool`
- `\dataphyre\access::logged_in(?string $auth_type=null): bool`
- `\dataphyre\access::access(bool $session_required=true, bool $must_no_session=false, bool $prevent_mobile=false, bool $prevent_robot=false): bool`

### Kernel TOTP / MFA

Time-based one-time password support lives in the access kernel surface.

Relevant kernel methods:

- `\dataphyre\access::create_totp_secret(int $bytes=20): string|false`
- `\dataphyre\access::totp_code(string $secret, ?int $timestamp=null, int $period=30, int $digits=6): string|false`
- `\dataphyre\access::verify_totp(string $secret, string $code, int $window=1, ?int $timestamp=null, int $period=30, int $digits=6): bool`
- `\dataphyre\access::totp_uri(string $secret, string $account_name, ?string $issuer=null): string|false`
- `\dataphyre\access::get_totp_pairing_image(string $secret, string $account_name, ?string $issuer=null, int $size=200): string|false`

`get_totp_pairing_image(...)` returns a local SVG QR image as a `data:image/svg+xml;base64,...` URI, so pairing does not depend on an external QR service.

Example:

```php
$secret=\dataphyre\access::create_totp_secret();
$pairing_image=\dataphyre\access::get_totp_pairing_image($secret, 'user@example.com');

if(\dataphyre\access::verify_totp($secret, $_POST['2fa_token'])===true){
	// token is valid
}
```

Example:

```php
if(\dataphyre\access::validate_session(true, 'session')===false){
	// handle invalid session
}

if(\dataphyre\access::logged_in('jwt')===true){
	// request is authenticated through a JWT auth type handler
}
```

### Framework Scope

Framework scope is exposed under `Dataphyre\Access\...`.

Main entry points:

- `\Dataphyre\Access\Auth`
- `\Dataphyre\Access\AuthManager`
- `\Dataphyre\Access\OAuth`

Supporting framework components include:

- guards
- user providers
- auth middleware
- JWT codec / payload support
- OAuth 2.0 / OpenID Connect provider client support

### Framework Guard Layer

The framework layer adds a Laravel-style auth abstraction on top of the kernel access module.

Key facade methods:

- `Auth::guards(): array`
- `Auth::hasGuard(string $name): bool`
- `Auth::shouldUse(string $guard): void`
- `Auth::guard(?string $name=null): Guard`
- `Auth::provider(string $name): ?UserProvider`
- `Auth::extendGuard(string $driver, callable $resolver): void`
- `Auth::extendProvider(string $name, mixed $config): void`
- `Auth::context(?string $guard=null): AuthContext`
- `Auth::check(?string $guard=null): bool`
- `Auth::guest(?string $guard=null): bool`
- `Auth::user(?string $guard=null): mixed`
- `Auth::claims(?string $guard=null): array`
- `Auth::token(?string $guard=null): ?string`
- `Auth::id(?string $guard=null): int|string|null`
- `Auth::login(mixed $user, bool $remember=false, ?string $guard=null): bool`
- `Auth::loginUsingId(int|string $identifier, bool $remember=false, ?string $guard=null): bool`
- `Auth::attempt(array $credentials, bool $remember=false, ?string $guard=null): bool`
- `Auth::validate(bool $cache=true, ?string $guard=null): bool`
- `Auth::recover(?string $guard=null): bool`
- `Auth::logout(?string $guard=null): bool`
- `Auth::oauth(string $provider): \Dataphyre\Access\OAuthClient\Provider`

Example:

```php
\dataphyre\core::load_framework_module('access');

use Dataphyre\Access\Auth;

if(Auth::check()===true){
	$user=Auth::user();
}

Auth::shouldUse('jwt');

if(Auth::guard('jwt')->check()===true){
	$claims=Auth::claims('jwt');
}
```

### Guard Drivers

The framework ships with:

- `access`
- `session`
- `jwt`

The default guard comes from `DP_ACCESS_CFG['framework']['default_guard']`.

Guard configuration lives under `DP_ACCESS_CFG['framework']['guards']`.

Provider configuration lives under `DP_ACCESS_CFG['framework']['providers']`.

Example:

```php
return [
	'framework'=>[
		'default_guard'=>'session',
		'guards'=>[
			'session'=>[
				'driver'=>'session',
				'provider'=>'users',
			],
			'api'=>[
				'driver'=>'jwt',
				'provider'=>'users',
				'token_sources'=>['bearer', 'query'],
			],
		],
		'providers'=>[
			'users'=>[
				'retrieve_by_id'=>static function(int|string $identifier){
					// return your user object
				},
				'retrieve_by_credentials'=>static function(array $credentials){
					// return your user object
				},
				'validate_credentials'=>static function($user, array $credentials): bool {
					// validate credentials
				},
			],
		],
	],
];
```

Place that array in `common/dataphyre/config/access.php` or the application override file at `applications/<app>/backend/dataphyre/config/access.php`.

Example effective reads in framework code:

```php
$default_guard=DP_ACCESS_CFG['framework']['default_guard'] ?? 'session';
$guards=DP_ACCESS_CFG['framework']['guards'] ?? [];
$providers=DP_ACCESS_CFG['framework']['providers'] ?? [];
```

### JWT Guard

The JWT guard is request-oriented and stateless.

It is intended for API guards and machine-to-machine auth flows where local PHP session state is not the source of truth.

Capabilities include:

- bearer-token request auth
- JWT decoding and validation
- provider-backed user resolution
- request guard integration through `Auth::guard('jwt')`

The JWT codec also supports dynamic key resolution, which is used by the OAuth / OpenID Connect layer for `id_token` verification.

### Middleware

The access framework provides two middleware classes:

- `Dataphyre\Access\Middleware\Authenticate`
- `Dataphyre\Access\Middleware\Guest`

These are intended to be used through compiled framework routes.

### OAuth / OpenID Connect

The framework layer includes a provider-driven OAuth client under `Dataphyre\Access\OAuthClient`.

Main facade:

```php
use Dataphyre\Access\OAuth;

$request=OAuth::provider('google')->authorizationRequest();
$user=OAuth::provider('google')->user($_GET);
$logged_in=OAuth::provider('google')->login($_GET, 'session', true);
```

Supported capabilities:

- authorization code flow
- PKCE
- state handling
- nonce support
- token exchange
- userinfo fetch
- local-user resolution
- session login bridging
- refresh token exchange
- token revocation
- OpenID Connect discovery
- JWKS key resolution
- `id_token` validation

Important provider methods:

- `authorizationRequest(): AuthorizationRequest`
- `user(Request|array|null $request=null): OAuthUser`
- `userFromToken(string $access_token): OAuthUser`
- `refresh(string|OAuthUser $refresh_token_or_user): array`
- `refreshedUser(string|OAuthUser $refresh_token_or_user): OAuthUser`
- `revoke(string|OAuthUser $token_or_user, ?string $hint=null): bool`
- `login(Request|array|OAuthUser|null $request_or_user=null, ?string $guard=null, bool $remember=false): bool`

### OAuth Provider Configuration

OAuth config lives under `DP_ACCESS_CFG['framework']['oauth']['providers']`.

You can configure providers either explicitly or through discovery.

Explicit endpoint example:

```php
return [
	'framework'=>[
		'oauth'=>[
			'providers'=>[
				'example'=>[
					'client_id'=>'client-id',
					'client_secret'=>'client-secret',
					'authorization_url'=>'https://example.com/oauth/authorize',
					'token_url'=>'https://example.com/oauth/token',
					'userinfo_url'=>'https://example.com/oauth/userinfo',
					'redirect_uri'=>'https://app.example.com/oauth/example/callback',
					'scopes'=>['profile', 'email'],
				],
			],
		],
	],
];
```

OpenID Connect discovery example:

```php
return [
	'framework'=>[
		'oauth'=>[
			'providers'=>[
				'google'=>[
					'client_id'=>'client-id',
					'client_secret'=>'client-secret',
					'issuer'=>'https://accounts.google.com',
					'discover'=>true,
					'redirect_uri'=>'https://app.example.com/oauth/google/callback',
					'scopes'=>['openid', 'profile', 'email'],
					'resolve_user'=>static function(\Dataphyre\Access\OAuthClient\OAuthUser $oauth_user){
						// map external identity to local user
					},
				],
			],
		],
	],
];
```

### OAuth Diagnostics

The access diagnostics validate provider config at the framework layer.

The diagnostic checks enforce:

- provider config must be an array
- `client_id` is required
- `authorization_url` and `token_url` are required only when discovery is not enabled

### Design Notes

- The kernel auth path handles core session lifecycle and access gating.
- Framework loading is explicit and optional.
- JWT and OAuth are framework features built on top of the kernel auth-type seam.
- Applications that only need session auth through `\dataphyre\access` do not pay the framework cost.

### Scope

The module includes:

- session auth
- guard abstraction
- JWT request auth
- OAuth / OIDC client layer
- refresh / revoke helpers
- discovery and JWKS support

Application integration responsibilities include:

- wiring real providers in app config
- callback routes / controllers
- local user mapping rules
- any token persistence or revocation storage strategy beyond caller-managed tokens
