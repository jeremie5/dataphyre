# Application keyrings

`dpvks()` supplies the application's persistent signing and encryption keys in
rotation order. `dpvk()` selects the last key. These keys must survive container
replacement and be identical across instances of the same application.

Key resolution preserves the existing installation contract:

1. Read `<ROOTPATH['dataphyre']>/config/static/dpvk`, when present and readable.
   Its comma-separated entries retain their exact bytes and order.
2. Otherwise use `DP_CORE_CFG['private_key']`, as a nonempty string or an ordered
   list of nonempty strings. Application configuration can obtain this value
   from its existing protected environment or secret provider.

An empty or malformed selected keyring fails closed. A corrupt readable static
file does not silently fall back to configuration. Do not trim or reorder legacy
keys. Do not put keys in source, image layers, logs, request headers, or command
arguments.

## Managed and internal bootstrap

The runtime creates a fresh bootstrap identity for each supervisor invocation.
It belongs to the attested managed bootstrap context; it is **not** an
application signing or encryption key. The sealed broker envelope, native request context,
role binding, caller guards, and identity lifecycle remain independent of the
persistent application keyring.

`core.main.php` alone can obtain the internal seed for its bootstrap validation.
Release preflight and registration-only bootstrap can therefore operate without
an application key. Calling application cryptography in those modes still
requires the configured keyring. A successful health or inventory probe does
not establish that durable application keys have been configured.

Managed startup skips the ordinary flight-sheet installer and does not generate
a replacement static key. Ordinary installations retain the existing
`generated_dpvk` behavior: preserve an existing target, otherwise copy the shared
installation key or generate a key. Preserve that protected file when adopting
an existing installation; an operator-supplied configured key cannot override
it. Check both application and shared installation paths before moving an
installation into an immutable image.

## Rotation and deployment

Use a distinct keyring for each application. Deploy the same protected ring to
all instances that share encrypted data or signatures. Validate stored data
across independent instances and a restart before changing production writers.

To rotate, retain old keys at their original indices and append the new key.
New encryption and signing use the final index; prior indices remain available
for decryption and verification. Readers must have the new ring before they can
read new-slot ciphertext. Coordinate readers and writers accordingly; a process
with only the old ring cannot read data written with the appended key. Keep old
keys while stored data or outstanding signed values still depend on them.

Adopting stable keys after previously using ephemeral managed identities cannot
recover ciphertext whose old key has been lost. Preserve any required legacy
keys through the existing protected keyring mechanism; do not re-sign historical
records or invent verification evidence to conceal a missing key.
