### CASPOW Module

The **CASPOW** module in Dataphyre provides a client proof-of-work challenge for abuse-sensitive requests such as login, registration, and other public form submissions. It is designed to be cheap for the server, expensive enough for automation, and bounded so browsers do not treat it like a long-running miner.

---

#### Design Goals

- **Signed one-time challenges**: each challenge is issued by the server, signed, stored server-side, and bound to the active session context.
- **Replay resistance**: solved challenges are single-use and expire quickly.
- **Low server cost**: verification is one hash recomputation plus signature and binding checks.
- **Adaptive client effort**: difficulty and budget scale within a safe range based on device hints and server policy.
- **Bounded browser work**: the client solves within a small time window and yields between chunks to avoid long uninterrupted CPU bursts.

---

#### Protocol Overview

1. The client requests a challenge from the CASPOW endpoint.
2. The server returns a signed challenge payload containing:
   - `challenge_id`
   - `nonce`
   - `difficulty_bits`
   - `algorithm`
   - `expires_at`
   - `scope`
   - `chunk_size`
   - `max_duration_ms`
   - `max_iterations`
   - `signature`
3. The browser worker looks for a `counter` where:
   - `SHA-256(challenge_id + ":" + nonce + ":" + counter)`
   - has at least `difficulty_bits` leading zero bits.
4. The client submits the solved proof as a base64-encoded JSON payload.
5. The server verifies:
   - the challenge exists
   - it has not expired
   - it has not already been used
   - its signature is valid
   - it is still bound to the same session/request context
   - the submitted digest and counter satisfy the proof target

---

#### Kernel API

##### `create_challenge(?string $scope=null, ?array $capabilities=null): array`

Creates a signed proof-of-work challenge and stores its server-side record in the current session.

- **`$scope`**:
  Optional logical scope used to bind a proof to a specific form or endpoint.
- **`$capabilities`**:
  Optional client hints such as hardware concurrency, device memory, save-data mode, and reduced-motion preference.

Returns an array containing the public challenge fields needed by the browser worker.

Example:

```php
$challenge=\dataphyre\caspow::create_challenge('login_form', [
	'hardware_concurrency'=>8,
	'device_memory'=>8,
	'save_data'=>false,
	'reduced_motion'=>false,
]);
```

##### `verify_payload(mixed $payload): bool`

Verifies a submitted proof payload.

- Accepts either:
  - the already-decoded proof array, or
  - a base64-encoded JSON proof string
- Returns `true` only if the proof is valid, current, correctly signed, properly bound, and unused.

Example:

```php
if(!\dataphyre\caspow::verify_payload($_POST['caspow_result'] ?? null)){
	pre_init_error('Cryptographic challenge verification failed.');
}
```

---

#### Challenge Fields

The browser receives a challenge like:

```json
{
  "version": "2",
  "challenge_id": "8f0e4f6e3f3cc0b8e8d6e0c1",
  "algorithm": "SHA-256",
  "nonce": "4d6aaf0c5c1fbad1cbb3d4ec8a9d7c64",
  "difficulty_bits": 17,
  "issued_at": 1775198105,
  "expires_at": 1775198285,
  "scope": "login_form",
  "chunk_size": 256,
  "max_duration_ms": 1500,
  "max_iterations": 524288,
  "profile": "standard",
  "signature": "..."
}
```

The solved proof payload contains:

```json
{
  "version": "2",
  "challenge_id": "...",
  "scope": "login_form",
  "algorithm": "SHA-256",
  "nonce": "...",
  "signature": "...",
  "counter": 41827,
  "digest": "0000f3...",
  "duration_ms": 423,
  "iterations": 41828,
  "worker": true
}
```

---

#### Endpoint Usage

The bundled endpoint supports:

- `POST /dataphyre/caspow/create`
- `POST /dataphyre/caspow/verify`

Challenge request body:

```json
{
  "scope": "login_form",
  "capabilities": {
    "hardware_concurrency": 8,
    "device_memory": 8,
    "save_data": false,
    "reduced_motion": false
  }
}
```

Verify request body:

```json
{
  "payload": "base64-encoded-json-proof"
}
```

---

#### Browser Integration

The bundled `caspow.js` file:

- finds forms that contain a `<caspow>` element
- fetches a challenge before submit
- solves it in a worker
- bounds solving time and iterations
- yields between chunks
- injects `caspow_result` into the form
- re-submits the form normally

Typical markup:

```html
<caspow
	endpoint="/dataphyre/caspow/create"
	submit="#login_submit"
	scope="login_form"
	string_ongoing="Verifying request..."
	string_failed="Verification failed. Click to try again."></caspow>
```

---

#### Configuration

CASPOW reads optional values from `dataphyre/caspow/*`.

Key settings:

- `algorithm`
- `ttl_seconds`
- `desktop_base_bits`
- `mobile_base_bits`
- `minimum_desktop_bits`
- `minimum_mobile_bits`
- `maximum_bits`
- `chunk_size`
- `max_duration_ms`
- `max_active_challenges`
- `max_used_challenges`
- `max_iterations_multiplier`

These control the signed challenge budget and difficulty envelope. The server remains authoritative; client-supplied capability hints only influence challenge selection within server-defined limits.

---

#### Security Notes

- CASPOW does **not** try to make browser automation impossible in an absolute sense. A determined attacker can still solve challenges.
- It **does** prevent trivial bypasses such as replaying old proofs or fabricating unsigned payloads.
- It keeps verification cheap enough that the server does not pay the client's proof cost.
- It is best used as one layer in a broader abuse-control stack alongside rate limits, access controls, and behavioral detection.
