### Firewall Module

The **Firewall** module in Dataphyre is designed to enhance security by managing and restricting access based on user request patterns. It detects unusual or potentially harmful request patterns such as flooding (high request rate) and repeated CAPTCHA failures. The firewall can enforce actions like CAPTCHA challenges, request throttling, and blocking based on configurable thresholds. This module is integral for protecting the application from brute-force attacks, spam, and other malicious activities.

---

#### Key Functionalities

1. **Flooding Detection**: Monitors request rates to detect and respond to flooding or high-frequency requests.
2. **CAPTCHA Management**: Challenges suspicious users with CAPTCHA to confirm legitimacy.
3. **IP Blocking**: Temporarily blocks users based on certain criteria, such as request flooding or failed CAPTCHA attempts.
4. **Configurable Throttling**: Enforces a delay or throttling for excessive requests to reduce server load and prevent abuse.

---

#### Core Methods

1. **`captcha()`**
   - **Purpose**: Handles CAPTCHA challenges for users flagged for suspicious activity.
   - **Process**:
     - If a user has previously bypassed CAPTCHA (`captcha_unblock` session variable is `true`), the IP is unblocked by removing it from the CAPTCHA block list.
     - Otherwise, it checks if the user is CAPTCHA-blocked and redirects to the CAPTCHA challenge page if necessary.
   - **Example**:
     ```php
     firewall::captcha();
     ```

2. **`flooding_threshold() : int`**
   - **Purpose**: Calculates the maximum allowable number of requests within a certain timeframe before triggering flooding protection measures.
   - **Returns**: The calculated request threshold.
   - **Example**:
     ```php
     $threshold = firewall::flooding_threshold();
     ```

3. **`flooding_check()`**
   - **Purpose**: Monitors request patterns to detect flooding. If excessive requests are detected within a defined period, actions such as throttling or CAPTCHA challenges are triggered.
   - **Process**:
     - Maintains a list of recent request timestamps in the session.
     - If the request rate exceeds the threshold within the configured minimum time (`min_time`), it triggers one of the following actions:
       - **Throttling**: Pauses processing for a specified time.
       - **CAPTCHA Block**: Redirects the user to a CAPTCHA page.
   - **Example**:
     ```php
     firewall::flooding_check();
     ```

4. **`rps_limiter(int $timing) : bool`**
   - **Purpose**: Limits requests per second (RPS) by checking if the time between the current request and the previous one is less than the specified timing.
   - **Returns**: `true` if the request rate is within the limit, `false` if flooding is detected.
   - **Example**:
     ```php
     if (!firewall::rps_limiter(1000)) {
         // Handle flooding scenario
     }
     ```

5. **`check_if_captcha_blocked() : bool`**
   - **Purpose**: Checks if a user is currently CAPTCHA-blocked. If blocked, it redirects them to a CAPTCHA page to verify their identity.
   - **Process**:
     - Retrieves the user’s IP and checks if it is present in either a cache or database of CAPTCHA-blocked IPs.
     - Sets the `captcha_blocked` session variable if the user is blocked.
     - If blocked, redirects the user to the CAPTCHA page.
   - **Returns**: `true` if the user is CAPTCHA-blocked, `false` otherwise.
   - **Example**:
     ```php
     if (firewall::check_if_captcha_blocked()) {
         // User is redirected to CAPTCHA page
     }
     ```

6. **`captcha_block_user(string $reason='unknown') : bool`**
   - **Purpose**: Blocks a user by their IP address and requires them to complete a CAPTCHA to regain access.
   - **Parameters**:
     - `$reason`: The reason for blocking the user (e.g., "request_flooding").
   - **Process**:
     - The user’s IP is added to the CAPTCHA block list, with an expiration time of 6 hours.
     - Calls `check_if_captcha_blocked()` to verify the block.
   - **Returns**: `true` if the IP was successfully blocked.
   - **Example**:
     ```php
     firewall::captcha_block_user("request_flooding");
     ```

---

#### Workflow and Key Considerations

1. **Flood Detection and Response**: The `flooding_check()` and `rps_limiter()` methods serve as the first line of defense by detecting unusual request patterns and enforcing response measures such as throttling or CAPTCHA challenges.
2. **CAPTCHA Challenges**: The module relies on CAPTCHA challenges for users flagged for abnormal behavior to ensure they are legitimate.
3. **Temporary Blocking**: IPs that repeatedly trigger flooding or CAPTCHA events are temporarily blocked, which is managed through session and database records.
4. **Cache Integration**: If a cache module is available, it is used for storing CAPTCHA block records for quick retrieval and efficient memory use.

This **Firewall** module is crucial for maintaining security and stability, reducing the risk of abuse and ensuring legitimate access to Dataphyre applications.