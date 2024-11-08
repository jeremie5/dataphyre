### Scheduling Module

The **Scheduling** module in Dataphyre provides functionality for managing scheduled tasks, allowing periodic execution of specified scripts with defined constraints on frequency, timeout, memory usage, and dependencies. This module is useful for handling background tasks, periodic maintenance, and automated operations that need to run on a schedule.

#### Key Functionalities

1. **Task Execution**: Runs specified tasks at defined intervals, enforcing frequency and timeout constraints.
2. **Concurrency Control**: Prevents overlapping executions of the same task through a locking mechanism.
3. **Dependencies and Resource Management**: Monitors system load and task dependencies to ensure tasks are executed when server conditions are optimal.
4. **Graceful Handling**: Uses a shutdown function to re-trigger the scheduler if necessary, allowing for quick reactivation of timed-out tasks.

---

#### Core Methods

1. **`run(string $name, string $file_path, float $frequency, float $timeout, string $memory_limit, array $dependencies, ?string $app_override=null) : bool`**

   - **Purpose**: Runs a scheduled task with the specified parameters, if conditions are met. It logs the task execution details and creates a locking mechanism to prevent concurrent runs.
   - **Parameters**:
     - `$name`: Name of the task.
     - `$file_path`: Path to the file that should be executed.
     - `$frequency`: Minimum time interval (in seconds) between executions.
     - `$timeout`: Maximum allowed time (in seconds) a task can remain locked.
     - `$memory_limit`: Memory limit for the task.
     - `$dependencies`: List of dependencies required for the task.
     - `$app_override`: Optional application override for task execution.
   - **Returns**: `true` if the task is successfully initiated.

   - **Workflow**:
     - Checks if the task can run based on the last execution time, frequency, and timeout constraints.
     - If eligible, updates the last run timestamp and creates a lock file.
     - Registers a shutdown function that may re-trigger the scheduler with a short timeout.

2. **`can_run(array $scheduler) : bool`**

   - **Purpose**: Checks if a task is eligible for execution based on frequency, timeout, and server load.
   - **Parameters**:
     - `$scheduler`: Array of scheduler configuration parameters, including frequency, timeout, and dependencies.
   - **Returns**: `true` if the task is eligible for execution, `false` otherwise.

   - **Workflow**:
     - Clears file status cache to ensure accurate reading of lock and timestamp files.
     - Verifies if the task frequency and timeout constraints are met.
     - Checks for existing locks and assesses server load level.
     - Logs the scheduler status, noting if the task is due, locked, or not yet due.

---

#### Example Usage

1. **Defining a Task Run**:
   ```php
   scheduling::run(
       $name = 'daily_backup',
       $file_path = '/path/to/backup_script.php',
       $frequency = 86400, // Run once a day (in seconds)
       $timeout = 3600,    // Allow up to 1 hour for the task
       $memory_limit = '512M',
       $dependencies = ['database.php', 'filesystem.php'],
       $app_override = 'backup_app'
   );
   ```

2. **Task Eligibility Check**:
   ```php
   $scheduler = [
       'name' => 'daily_cleanup',
       'file_path' => '/path/to/cleanup_script.php',
       'frequency' => 3600, // Every hour
       'timeout' => 600,    // 10 minutes timeout
       'memory_limit' => '256M',
       'dependencies' => []
   ];
   $can_run = scheduling::can_run($scheduler);
   ```

---

#### Workflow

1. **Task Registration**: When a task is initiated with `run`, it records the task properties if they do not already exist, such as file path, frequency, memory limit, and dependencies.
2. **Execution Constraints**: The `can_run` method is invoked to ensure the task meets the scheduling conditions, including time since the last execution, server load, and whether any existing lock is in place.
3. **Locking and Unlocking**: A lock file is created to prevent simultaneous execution, and the lock is released once the task completes. If the task times out, it may be re-triggered through the shutdown function.
4. **Logging and Monitoring**: The scheduling process logs detailed information on task eligibility, server load, lock status, and timing to aid in monitoring and debugging.

---

This **Scheduling** module in Dataphyre enables robust and flexible scheduling of background tasks with fine-grained control over execution intervals, dependencies, and system resources, ensuring efficient task management and resource utilization.