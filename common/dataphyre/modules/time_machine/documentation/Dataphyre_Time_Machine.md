### Time Machine Module

The **Time Machine** module in Dataphyre is designed to track and manage reversible changes within the application. It records modifications made by users, allowing for these changes to be rolled back when needed, such as reverting user settings, database operations, or application state.

#### Key Functionalities

1. **Create Change Logs**: Tracks user actions that are reversible, logging each change in the `dataphyre.user_changes` table.
2. **Rollback Changes**: Allows reversing changes based on specific identifiers.
3. **Purge Old Logs**: Cleans up change logs that are older than a specified time period.

---

#### Public Methods

1. **`create(string $type, string $rollback_type, array $change_data, bool $user_can_rollback = false)`**

   Creates a change record that can later be reversed. This record is stored with encrypted data about the change, including the user’s session ID.

   - **Parameters**:
     - `$type`: A label indicating the type of change (e.g., "USER_UPDATE").
     - `$rollback_type`: The category of rollback action (e.g., "SQL_UPDATE" or "USER_PARAMETER").
     - `$change_data`: Data specific to the change, such as database rows affected.
     - `$user_can_rollback`: If `true`, allows the user to initiate the rollback.
   - **Returns**: The `changeid` of the created log entry on success, `false` on failure.
   - **Example Usage**:
     ```php
     $change_data = ['setting_name' => 'email_notifications', 'old_value' => true, 'new_value' => false];
     $changeid = time_machine::create('USER_UPDATE', 'USER_PARAMETER', $change_data, true);
     ```

2. **`rollback($changeid, int $userid, int $rollback_request_userid = 0)`**

   Reverts a change based on its identifier. This method restores previous values or deletes inserted data according to the `rollback_type`. It checks if the user has permission to perform the rollback, and updates the record to reflect the rollback status.

   - **Parameters**:
     - `$changeid`: The unique identifier for the change to roll back.
     - `$userid`: The user ID of the original actor.
     - `$rollback_request_userid`: The ID of the user requesting the rollback. If the user was not the one who made the change, this method verifies whether the user can roll back.
   - **Returns**: `true` if the rollback succeeded, `false` if it failed or the user lacked permissions.
   - **Example Usage**:
     ```php
     $rollbackSuccess = time_machine::rollback(12345, 1);
     ```

3. **`purge_old(string $period = '7 days')`**

   Deletes change logs that are older than a specified period, freeing up space and reducing clutter. This operation is often scheduled periodically.

   - **Parameters**:
     - `$period`: A string representing the time period for purging, such as `'7 days'`.
   - **Returns**: `true` on success, `false` on failure.
   - **Example Usage**:
     ```php
     $purged = time_machine::purge_old('30 days');
     ```

---

#### Workflow

1. **Creating a Change Log**:
   - When a reversible action occurs (e.g., a user changes a setting or deletes data), the `create()` method logs it with metadata, encryption, and permissions.
   - This log entry is stored in the `dataphyre.user_changes` table, allowing it to be referenced later if a rollback is requested.

2. **Rolling Back a Change**:
   - When a rollback is requested, the `rollback()` method fetches the change data, decrypts it, and performs the appropriate action (e.g., restoring the previous value or re-inserting deleted rows).
   - Rollback types supported include:
     - **USER_PARAMETER**: Reverts a user's settings.
     - **SQL_DELETE**: Deletes rows that were originally inserted as part of the tracked change.
     - **SQL_INSERT**: Re-inserts rows that were deleted as part of the tracked change.
     - **SQL_UPDATE**: Re-applies an update to return the data to its original state.

3. **Purging Old Logs**:
   - The `purge_old()` method removes logs older than the specified period, helping maintain a manageable database size by removing outdated change records.

---

This module is crucial for maintaining an audit trail of changes in an application and gives users or administrators the flexibility to undo actions. It ensures application stability by allowing recovery from accidental changes, enhancing the robustness of user and data management.