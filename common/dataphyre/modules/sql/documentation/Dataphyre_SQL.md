# Dataphyre SQL Module Documentation

**Overview**  
The Dataphyre SQL module provides a robust interface for managing database interactions across multiple Database Management Systems (DBMS) such as MySQL, PostgreSQL, and SQLite. The module incorporates caching, migration support, and query queuing for enhanced performance and flexibility. This guide covers the module’s structure, key functions, and configuration.

---

## 1. Configuration and Initialization

Upon loading, the SQL module performs the following configuration steps:

1. **Configuration Loading**: Checks for and loads SQL configurations from both the common and application-specific paths. If no configuration is found, the module enters "safemode," displaying a maintenance message.
2. **Database Connection Management**: Supports multiple DBMS by loading `mysql_query.php`, `postgresql_query.php`, and `sqlite_query.php`.
3. **Session-Based Query Caching**: Manages a cache of database query results stored in the session, helping reduce repeated queries and improving efficiency.

### Session Cache Management
The module maintains an in-memory session cache, capped at 500 entries. Each table cache within this session is limited to 128 entries, with older entries removed to make space for new data. This policy ensures efficient use of memory and avoids excessive data storage in sessions.

### Database Migration Control
When a migration file is detected, Dataphyre enters a maintenance mode to prevent conflicts or data inconsistency during migrations. If migrations are running, the system prevents new tasks until the migration completes.

---

## 2. Key Functions

The SQL module includes several primary functions, each designed for different database operations:

### `__construct($dbms_cluster)`
Initializes the SQL module for the specified DBMS cluster, loading configurations and logging the initialization. This function also invokes `migration()` to check and handle any pending database migrations.

### `migration()`
Checks if a migration is ongoing or required, switching the system to maintenance mode during migrations. If the migration file exists, it launches a migration process and prevents regular database operations until complete.

---

## 3. Query Caching

The SQL module provides caching mechanisms that reduce database load by storing query results for repeated access. The caching policy can be set to one of three types, each with unique storage and access behaviors:

1. **Shared Cache (`shared_cache`)**: Requires Dataphyre’s cache module and is shared across sessions. Ensures consistent data access across requests.
2. **Session Cache (`session`)**: Stores results in the session, providing rapid access within the same session.
3. **Filesystem Cache (`fs`)**: Persists cached results on the filesystem, suited for queries that need retention across sessions and without shared cache support.

### Caching Functions
- **`get_query_cached_result()`**: Retrieves cached results based on the cache policy, falling back to a new query if no cached result exists.
- **`cache_query_result()`**: Stores query results based on the cache policy, enabling repeated access.
- **`invalidate_cache()`**: Removes specific entries or entire cache categories, useful for keeping cached data synchronized with the database.

---

## 4. Core Query Functions

Dataphyre SQL includes a series of functions for executing queries across various DBMSs. Each function automatically adapts to the target DBMS and includes optional caching and callback features.

### `db_query()`
Executes a general SQL query, supporting multipoint queries and caching. The function can queue queries, batch-execute them, and leverage cache for optimal performance.

**Parameters**:
- **$query**: The query string or array (DBMS-specific queries).
- **$vars**: Array of query parameters.
- **$caching**: Cache policy.
- **$clear_cache**: Clears relevant cache entries if set.
- **$queue**: Optional query queue for deferred execution.

### `db_select()`
Performs a `SELECT` query, automatically adapting parameters and format to the specified DBMS. Supports result caching and callback functions for customized post-query operations.

### `db_count()`
Executes a `COUNT` query to retrieve the number of entries in a specified location. Utilizes caching if available, optimizing repeated access patterns.

### `db_insert()`
Handles `INSERT` queries, automatically adapting parameters for the DBMS and clearing relevant cache entries if configured.

### `db_update()`
Processes `UPDATE` queries. Automatically converts parameters based on the DBMS and invalidates cache entries as needed.

### `db_delete()`
Executes `DELETE` queries, adapting parameters to the DBMS and clearing relevant cache entries if set.

---

## 5. Error Handling and Tracelog Integration

Each function within the SQL module is instrumented with **tracelog** calls. This integration logs critical details like file paths, line numbers, and function arguments for every database interaction, supporting comprehensive diagnostics and troubleshooting.

### Dialback Support
The module uses **dialbacks** to inject custom logic at key execution points. For instance:
- **`CALL_SQL_CONSTRUCT`**: Called during SQL module instantiation.
- **`CALL_SQL_FLAG_SERVER_UNAVAILABLE`**: Triggered when marking a server as unavailable.
- **`CALL_SQL_DB_SELECT`**: Used during `db_select()` execution to allow interception and modification of query behavior.

---

## 6. Server Availability Management

The SQL module includes mechanisms for tracking and managing server availability across clusters. If a server is flagged as unavailable, it won’t be used for subsequent queries until it’s marked as available.

- **`flag_server_unavailable()`**: Marks a server as unavailable, storing the timestamp in the session.
- **`is_server_available()`**: Checks server availability by verifying if the server has been flagged unavailable within a defined timeout.

---

## Summary: Using the Dataphyre SQL Module

The Dataphyre SQL module provides an advanced, flexible system for managing database operations across multiple DBMSs. Key features include caching, migration support, modular query functions, error handling, and server availability management. By leveraging these functions and policies, developers can optimize database interactions, minimize load, and ensure robust, scalable performance across applications.