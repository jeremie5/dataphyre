## Access Module - Dataphyre

### Overview

The **Access Module** in Dataphyre is responsible for handling user authentication, session management, and access control, ensuring the security and integrity of user sessions. It relies on the `SQL` and `Firewall` modules to perform session-related database operations and manage security rules.

### Dependencies

This module requires:
- **SQL Module**: For database interactions.
- **Firewall Module**: For enforcing security measures like CAPTCHA blocks on suspicious activities.

### Configuration

The module retrieves configuration settings from `access.php` within the Dataphyre configuration directory. Essential configurations include:
- **`sessions_table_name`**: The name of the database table used to store session data.
- **`sanction_on_useragent_change`**: Boolean indicating if session should be invalidated upon a change in user agent.

### Core Functions

1. **Constructor (`__construct`)**
   - Initializes user sessions and validates them.
   - Handles user-agent mismatch by blocking access if the session’s user agent changes unexpectedly.

2. **`create_session`**
   - Creates a new user session, sets a secure session cookie, and initializes session variables.
   - **Parameters**:
     - `$userid` (int): The ID of the user.
     - `$keepalive` (bool): Whether the session should persist beyond the current browser session.
   - **Returns**: `bool` - True if the session is successfully created, false otherwise.

3. **`create_id`**
   - Generates a secure 64-character session identifier using a cryptographically secure random string.
   - **Returns**: `string` - The session identifier.

4. **`userid`**
   - Retrieves the ID of the currently logged-in user.
   - **Returns**: `int|bool` - The user ID if logged in, false if not.

5. **`is_bot`**
   - Checks if the current user session likely belongs to a bot by comparing the user agent against a list of known bot identifiers.
   - **Returns**: `bool` - True if a bot is detected, false otherwise.

6. **`is_mobile`**
   - Identifies if the current user is accessing from a mobile device by checking the user agent.
   - **Returns**: `bool` - True if on a mobile device, false otherwise.

7. **`disable_session`**
   - Deletes session variables and destroys the user session in the database.
   - **Returns**: `bool` - True on success, false otherwise.

8. **`disable_all_sessions_of_user`**
   - Disables all active sessions associated with a given user ID.
   - **Parameters**:
     - `$userid` (int): The ID of the user whose sessions are to be disabled.
   - **Returns**: `bool` - True on success, false otherwise.

9. **`validate_session`**
   - Validates the current session by verifying the session ID, user ID, and IP address. Refreshes the IP address if it has changed within the session’s lifespan.
   - **Parameters**:
     - `$cache` (bool): Whether to use cached session validation information.
   - **Returns**: `bool` - True if the session is valid, false otherwise.

10. **`recover_session`**
    - Attempts to recover a user session by checking the session ID, IP address, and user agent.
    - **Returns**: `bool` - True on successful recovery, false otherwise.

11. **`logged_in`**
    - Checks if a user is currently logged in by verifying session data.
    - **Returns**: `bool` - True if logged in, false otherwise.

12. **`self`**
    - Manages access control for a page based on session requirements, mobile device restrictions, and bot detection.
    - **Parameters**:
      - `$session_required` (bool): Whether the user must be logged in to access the page.
      - `$must_no_session` (bool): Whether the user must not be logged in.
      - `$prevent_mobile` (bool): Whether access from mobile devices should be blocked.
      - `$prevent_robot` (bool): Whether access from bots should be blocked.
    - **Returns**: `bool` - True if access is granted, false otherwise.

### Usage

To enable access control, initialize an `access` instance in your script:

```php
new dataphyre\access();
```

For creating a session:
```php
\dataphyre\access::create_session($userid, $keepalive);
```

To validate a session before proceeding with the script:
```php
if (!\dataphyre\access::validate_session()) {
    // Handle invalid session
}
```

For controlling page access based on requirements:
```php
if (!\dataphyre\access::access($session_required = true, $must_no_session = false)) {
    // Handle access denial
}
```