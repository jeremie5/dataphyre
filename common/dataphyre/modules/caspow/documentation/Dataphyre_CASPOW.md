### CASPOW Module

The **CASPOW** (Cryptographic Anti-Spam Proof of Work) module in Dataphyre generates and verifies cryptographic challenges to reduce spam and ensure message integrity. It implements a proof-of-work mechanism using hash-based challenges, where clients must solve a hash challenge before their payloads are accepted, which can help mitigate automated spamming.

---

#### Purpose and Use Cases

The CASPOW module is designed to:
- **Mitigate spam** by requiring clients to perform computational work before submitting requests.
- **Verify the integrity** of payloads to prevent tampering.
- **Optimize workload** based on the client device, with lighter challenges for mobile users.

---

#### Key Components

##### Configuration Properties

- **`$algorithm`**: Specifies the hashing algorithm used for challenges. Supported algorithms include:
  - `SHA-256`
  - `SHA-384`
  - `SHA-512`
  
- **`$range_min`**: The minimum number range for generating the challenge number, influencing the difficulty level.
- **`$range_max`**: The maximum number range for generating the challenge number.

---

#### Methods

##### `create_challenge(?string $salt=null, ?int $number=null)`

- **Parameters**:
  - **`$salt`** (optional): A random or user-provided string used to create a unique challenge.
  - **`$number`** (optional): A specific integer to be used in the challenge. If not provided, a random number is chosen based on device type.
  
- **Functionality**:
  - Generates a cryptographic challenge by hashing the salt and number with the specified algorithm.
  - If `$salt` is not provided, a random 12-byte salt is generated.
  - If `$number` is not provided, the range for random number generation is adjusted for mobile devices (one-fifth of the usual range for mobile).
  - Calculates a `signature` for the challenge using HMAC, ensuring authenticity.
  
- **Returns**:
  - An associative array with the following fields:
    - **`algorithm`**: The hashing algorithm used (e.g., `SHA-256`).
    - **`challenge`**: The generated hash-based challenge string.
    - **`salt`**: The salt value used to generate the challenge.
    - **`signature`**: The HMAC signature of the challenge, providing integrity verification.

- **Example Usage**:
  ```php
  $challenge = caspow::create_challenge();
  ```

##### `verify_payload(mixed $payload): bool`

- **Parameters**:
  - **`$payload`**: The payload to verify, typically a base64-encoded JSON string containing the salt, number, algorithm, challenge, and signature.

- **Functionality**:
  - Decodes and parses the payload.
  - Recreates the challenge using the salt and number from the payload.
  - Verifies the integrity and correctness of the payload by checking that the algorithm, challenge, and signature match the recreated challenge.

- **Returns**:
  - **`true`** if the payload is verified successfully.
  - **`false`** if verification fails, indicating possible tampering or incorrect challenge response.

- **Example Usage**:
  ```php
  $is_valid = caspow::verify_payload($received_payload);
  ```

---

#### Example Workflow

1. **Challenge Creation**: A server generates a challenge for a client using `create_challenge()`, which provides a salt, challenge, and signature.
2. **Challenge Solution**: The client attempts to solve the challenge by guessing the correct number that hashes to the challenge result.
3. **Payload Submission**: Once solved, the client submits a payload containing the salt, number, algorithm, challenge, and signature.
4. **Verification**: The server verifies the payload using `verify_payload()` to ensure it matches the original challenge and hasn't been tampered with.

---

#### Summary

The **CASPOW** module provides an efficient mechanism for enforcing computational work as an anti-spam measure in Dataphyre-based applications, adding a layer of security against automated requests.