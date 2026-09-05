#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <unistd.h>
#include <dirent.h>
#include <stdlib.h>
#include <errno.h>
#include <fcntl.h>
#include <grp.h>
#include <limits.h>
#include <poll.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <time.h>
#include <sys/prctl.h>
#include <sys/stat.h>
#include <sys/types.h>
#include "php.h"
#include "php_ini.h"
#include "SAPI.h"
#include "ext/standard/info.h"
#include "ext/json/php_json.h"
#include "main/php_streams.h"
#include "Zend/zend_smart_str.h"

#define DATAPHYRE_ENVIRONMENT_FD 198
#define DATAPHYRE_ENVIRONMENT_MAX_BYTES 524288
#define DATAPHYRE_ENVIRONMENT_MAX_ENTRIES 576
#define DATAPHYRE_ENVIRONMENT_TIMEOUT_MILLISECONDS 5000

extern char **environ;

typedef struct {
    char *name;
    size_t name_len;
    char *value;
    size_t value_len;
} dataphyre_environment_entry;

static zend_bool dataphyre_managed_pool = 0;
static zend_bool dataphyre_managed_request = 0;
static zend_bool dataphyre_managed_context_consumed = 0;
static pid_t dataphyre_managed_master_pid = 0;
static dataphyre_environment_entry *dataphyre_managed_values = NULL;
static size_t dataphyre_managed_value_count = 0;
static dataphyre_environment_entry *dataphyre_managed_baseline = NULL;
static size_t dataphyre_managed_baseline_count = 0;
static char *dataphyre_managed_project_root = NULL;
static size_t dataphyre_managed_project_root_len = 0;
static char *dataphyre_managed_private_key = NULL;
static size_t dataphyre_managed_private_key_len = 0;

PHP_INI_BEGIN()
    PHP_INI_ENTRY("dataphyre_environment_fd.managed_pool_role", "", PHP_INI_SYSTEM, NULL)
PHP_INI_END()

static void dataphyre_zero(void *memory, size_t length)
{
    volatile unsigned char *cursor = (volatile unsigned char *) memory;
    while (length-- > 0) {
        *cursor++ = 0;
    }
}

static char *dataphyre_persistent_copy(const char *value, size_t length)
{
    char *copy = pemalloc(length + 1, 1);
    if (copy == NULL) {
        return NULL;
    }
    memcpy(copy, value, length);
    copy[length] = '\0';
    return copy;
}

static void dataphyre_free_entries(dataphyre_environment_entry **entries, size_t *count)
{
    size_t index;
    if (*entries == NULL) {
        *count = 0;
        return;
    }
    for (index = 0; index < *count; index++) {
        if ((*entries)[index].name != NULL) {
            dataphyre_zero((*entries)[index].name, (*entries)[index].name_len);
            pefree((*entries)[index].name, 1);
        }
        if ((*entries)[index].value != NULL) {
            dataphyre_zero((*entries)[index].value, (*entries)[index].value_len);
            pefree((*entries)[index].value, 1);
        }
    }
    dataphyre_zero(*entries, sizeof(dataphyre_environment_entry) * *count);
    pefree(*entries, 1);
    *entries = NULL;
    *count = 0;
}

static int dataphyre_name_valid(const char *name, size_t length)
{
    size_t index;
    if (length < 1 || length > 120 || name[0] < 'A' || name[0] > 'Z') {
        return 0;
    }
    for (index = 1; index < length; index++) {
        unsigned char byte = (unsigned char) name[index];
        if (!((byte >= 'A' && byte <= 'Z') || (byte >= '0' && byte <= '9') || byte == '_')) {
            return 0;
        }
    }
    return 1;
}

static int dataphyre_value_valid(const char *value, size_t length)
{
    size_t index;
    if (length > 65536) {
        return 0;
    }
    for (index = 0; index < length; index++) {
        unsigned char byte = (unsigned char) value[index];
        if ((byte < 0x20 && byte != 0x09 && byte != 0x0a && byte != 0x0d) || byte == 0x7f) {
            return 0;
        }
    }
    return 1;
}

static int dataphyre_lower_hex_valid(const char *value, size_t length)
{
    size_t index;
    for (index = 0; index < length; index++) {
        if (!((value[index] >= '0' && value[index] <= '9') || (value[index] >= 'a' && value[index] <= 'f'))) {
            return 0;
        }
    }
    return 1;
}

static int dataphyre_private_key_valid(const char *value, size_t length)
{
    size_t index;
    if (length != 43 || strchr("AEIMQUYcgkosw048", value[42]) == NULL) return 0;
    for (index = 0; index < length; index++) {
        unsigned char byte = (unsigned char) value[index];
        if (!((byte >= 'A' && byte <= 'Z') || (byte >= 'a' && byte <= 'z')
            || (byte >= '0' && byte <= '9') || byte == '_' || byte == '-')) return 0;
    }
    return 1;
}

static int64_t dataphyre_monotonic_milliseconds(void)
{
    struct timespec observed;
    if (clock_gettime(CLOCK_MONOTONIC, &observed) != 0 || observed.tv_sec < 0
        || (uint64_t) observed.tv_sec > (uint64_t)(INT64_MAX / 1000)) {
        return -1;
    }
    return ((int64_t) observed.tv_sec * 1000) + ((int64_t) observed.tv_nsec / 1000000);
}

static int dataphyre_poll_fd(int fd, short events, int64_t deadline_milliseconds)
{
    struct pollfd descriptor;
    int result;
    descriptor.fd = fd;
    descriptor.events = events;
    descriptor.revents = 0;
    for (;;) {
        int64_t now = dataphyre_monotonic_milliseconds();
        int64_t remaining;
        int timeout;
        if (now < 0 || now >= deadline_milliseconds) return 0;
        remaining = deadline_milliseconds - now;
        timeout = remaining > INT_MAX ? INT_MAX : (int) remaining;
        result = poll(&descriptor, 1, timeout);
        if (result < 0 && errno == EINTR) continue;
        return result == 1 && (descriptor.revents & events) != 0;
    }
}

static int dataphyre_read_exact(int fd, char *buffer, size_t length, int64_t deadline_milliseconds)
{
    size_t offset = 0;
    while (offset < length) {
        ssize_t read_count;
        if (!dataphyre_poll_fd(fd, POLLIN, deadline_milliseconds)) {
            return 0;
        }
        do {
            read_count = read(fd, buffer + offset, length - offset);
        } while (read_count < 0 && errno == EINTR);
        if (read_count <= 0) {
            return 0;
        }
        offset += (size_t) read_count;
    }
    return 1;
}

static int dataphyre_write_exact(int fd, const char *buffer, size_t length, int64_t deadline_milliseconds)
{
    size_t offset = 0;
    while (offset < length) {
        ssize_t write_count;
        if (!dataphyre_poll_fd(fd, POLLOUT, deadline_milliseconds)) {
            return 0;
        }
        do {
            write_count = write(fd, buffer + offset, length - offset);
        } while (write_count < 0 && errno == EINTR);
        if (write_count <= 0) {
            return 0;
        }
        offset += (size_t) write_count;
    }
    return 1;
}

static int dataphyre_hex_header_length(const char header[9], size_t *length)
{
    size_t index;
    unsigned long parsed;
    char *end = NULL;
    if (header[8] != '\n') {
        return 0;
    }
    for (index = 0; index < 8; index++) {
        if (!((header[index] >= '0' && header[index] <= '9') || (header[index] >= 'a' && header[index] <= 'f'))) {
            return 0;
        }
    }
    errno = 0;
    parsed = strtoul(header, &end, 16);
    if (errno != 0 || end != header + 8 || parsed < 1 || parsed > DATAPHYRE_ENVIRONMENT_MAX_BYTES) {
        return 0;
    }
    *length = (size_t) parsed;
    return 1;
}

static zval *dataphyre_array_value(zval *array, const char *name, size_t name_len)
{
    if (array == NULL || Z_TYPE_P(array) != IS_ARRAY) {
        return NULL;
    }
    return zend_hash_str_find(Z_ARRVAL_P(array), name, name_len);
}

static int dataphyre_array_exact_keys(zval *array, const char *const *expected, size_t count)
{
    zend_string *key;
    zval *value;
    size_t index = 0;
    if (array == NULL || Z_TYPE_P(array) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(array)) != count) {
        return 0;
    }
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(array), key, value) {
        size_t expected_len;
        (void) value;
        if (key == NULL || index >= count) return 0;
        expected_len = strlen(expected[index]);
        if (ZSTR_LEN(key) != expected_len || memcmp(ZSTR_VAL(key), expected[index], expected_len) != 0) return 0;
        index++;
    } ZEND_HASH_FOREACH_END();
    return index == count;
}

static int dataphyre_string_equals(zval *value, const char *expected)
{
    size_t length = strlen(expected);
    return value != NULL && Z_TYPE_P(value) == IS_STRING
        && Z_STRLEN_P(value) == length && memcmp(Z_STRVAL_P(value), expected, length) == 0;
}

static int dataphyre_process_stat(pid_t pid, pid_t *parent, char *buffer, size_t buffer_size)
{
    FILE *stream;
    char path[64];
    char stat_buffer[4096];
    char *close, *cursor, *save = NULL, *token = NULL;
    int field = 0;
    int path_length = snprintf(path, sizeof(path), "/proc/%ld/stat", (long) pid);
    if (pid < 1 || path_length < 1 || (size_t) path_length >= sizeof(path)) return 0;
    stream = fopen(path, "rb");
    if (stream == NULL || fgets(stat_buffer, sizeof(stat_buffer), stream) == NULL) {
        if (stream != NULL) fclose(stream);
        return 0;
    }
    fclose(stream);
    close = strrchr(stat_buffer, ')');
    if (close == NULL || close[1] != ' ') {
        return 0;
    }
    cursor = close + 2;
    for (token = strtok_r(cursor, " \t\r\n", &save); token != NULL; token = strtok_r(NULL, " \t\r\n", &save)) {
        if (field == 1) {
            char *end = NULL;
            long parsed;
            errno = 0;
            parsed = strtol(token, &end, 10);
            if (errno != 0 || end == token || *end != '\0' || parsed < 0 || parsed > INT_MAX) return 0;
            *parent = (pid_t) parsed;
        }
        if (field == 19) {
            size_t length = strlen(token);
            if (length < 1 || length >= buffer_size) return 0;
            memcpy(buffer, token, length + 1);
            return 1;
        }
        field++;
    }
    return 0;
}

static int dataphyre_current_start_ticks(char *buffer, size_t buffer_size)
{
    pid_t parent = 0;
    return dataphyre_process_stat(getpid(), &parent, buffer, buffer_size);
}

static int dataphyre_ancestry_valid(zval *ancestry)
{
    static const char *const keys[] = {"pid", "start_time_ticks"};
    zend_ulong numeric_key;
    zval *entry;
    size_t index = 0, count;
    pid_t expected = getppid();
    if (ancestry == NULL || Z_TYPE_P(ancestry) != IS_ARRAY) return 0;
    count = zend_hash_num_elements(Z_ARRVAL_P(ancestry));
    if (count < 1 || count > 16) return 0;
    ZEND_HASH_FOREACH_NUM_KEY_VAL(Z_ARRVAL_P(ancestry), numeric_key, entry) {
        zval *pid_value, *start_value;
        pid_t parent = 0;
        char start_ticks[64];
        if (numeric_key != index || !dataphyre_array_exact_keys(entry, keys, 2)) return 0;
        pid_value = dataphyre_array_value(entry, "pid", sizeof("pid") - 1);
        start_value = dataphyre_array_value(entry, "start_time_ticks", sizeof("start_time_ticks") - 1);
        if (pid_value == NULL || Z_TYPE_P(pid_value) != IS_LONG || Z_LVAL_P(pid_value) != (zend_long) expected
            || start_value == NULL || Z_TYPE_P(start_value) != IS_STRING
            || !dataphyre_process_stat(expected, &parent, start_ticks, sizeof(start_ticks))
            || strlen(start_ticks) != Z_STRLEN_P(start_value)
            || memcmp(start_ticks, Z_STRVAL_P(start_value), Z_STRLEN_P(start_value)) != 0) return 0;
        index++;
        if (expected == 1) {
            return index == count;
        }
        if (parent < 1 || parent == expected) return 0;
        expected = parent;
    } ZEND_HASH_FOREACH_END();
    return 0;
}

static int dataphyre_process_boundary(pid_t expected_parent)
{
    gid_t groups[8];
    int group_count;
    FILE *stream;
    char status[8192];
    size_t length;
    if (getuid() != 10001 || geteuid() != 10001 || getgid() != 10001 || getegid() != 10001) {
        return 0;
    }
    group_count = getgroups((int)(sizeof(groups) / sizeof(groups[0])), groups);
    if (group_count != 1 || groups[0] != 10001) {
        return 0;
    }
    if (expected_parent > 0 && getppid() != expected_parent) {
        return 0;
    }
    if (prctl(PR_GET_NO_NEW_PRIVS, 0, 0, 0, 0) != 1) {
        return 0;
    }
    stream = fopen("/proc/self/status", "rb");
    if (stream == NULL) return 0;
    length = fread(status, 1, sizeof(status) - 1, stream);
    fclose(stream);
    status[length] = '\0';
    if (strstr(status, "Uid:\t10001\t10001\t10001\t10001\n") == NULL
        || strstr(status, "Gid:\t10001\t10001\t10001\t10001\n") == NULL
        || strstr(status, "CapInh:\t0000000000000000") == NULL
        || strstr(status, "CapPrm:\t0000000000000000") == NULL
        || strstr(status, "CapEff:\t0000000000000000") == NULL
        || (strstr(status, "CapBnd:\t0000000000000000") == NULL
            && strstr(status, "CapBnd:\t00000000000000e0") == NULL)
        || strstr(status, "CapAmb:\t0000000000000000") == NULL
        || strstr(status, "NoNewPrivs:\t1") == NULL) {
        return 0;
    }
    return 1;
}

static int dataphyre_scheduler_gateway_boundary(void)
{
    gid_t groups[8];
    int group_count;
    pid_t pid, parent, group, session;
    const char *pool, *pool_role;
    FILE *stream;
    char status[8192];
    size_t length;
    pool = getenv("DATAPHYRE_RUNTIME_POOL");
    pool_role = getenv("DATAPHYRE_RUNTIME_POOL_ROLE");
    pid = getpid();
    parent = getppid();
    group = getpgrp();
    session = getsid(0);
    if (sapi_module.name == NULL || strcmp(sapi_module.name, "cli") != 0
        || pool == NULL || pool_role == NULL
        || strcmp(pool, "scheduler-gateway") != 0 || strcmp(pool_role, "scheduler-gateway") != 0
        || !((session == pid && group == pid) || (session == parent && group == parent))
        || getuid() != 0 || geteuid() != 0 || getgid() != 0 || getegid() != 0) {
        return 0;
    }
    group_count = getgroups((int)(sizeof(groups) / sizeof(groups[0])), groups);
    if (group_count != 1 || groups[0] != 0 || prctl(PR_GET_NO_NEW_PRIVS, 0, 0, 0, 0) != 1) {
        return 0;
    }
    stream = fopen("/proc/self/status", "rb");
    if (stream == NULL) return 0;
    length = fread(status, 1, sizeof(status) - 1, stream);
    fclose(stream);
    status[length] = '\0';
    return strstr(status, "Uid:\t0\t0\t0\t0\n") != NULL
        && strstr(status, "Gid:\t0\t0\t0\t0\n") != NULL
        && strstr(status, "CapInh:\t0000000000000000") != NULL
        && strstr(status, "CapPrm:\t00000000000000e0") != NULL
        && strstr(status, "CapEff:\t00000000000000e0") != NULL
        && strstr(status, "CapBnd:\t00000000000000e0") != NULL
        && strstr(status, "CapAmb:\t0000000000000000") != NULL
        && strstr(status, "NoNewPrivs:\t1") != NULL;
}

static int dataphyre_snapshot_environ(dataphyre_environment_entry **entries, size_t *count)
{
    size_t total = 0, index;
    char **cursor;
    dataphyre_environment_entry *snapshot;
    for (cursor = environ; cursor != NULL && *cursor != NULL; cursor++) total++;
    if (total > 256) return 0;
    snapshot = total > 0 ? pecalloc(total, sizeof(dataphyre_environment_entry), 1) : NULL;
    if (total > 0 && snapshot == NULL) return 0;
    for (index = 0; index < total; index++) {
        const char *separator = strchr(environ[index], '=');
        size_t name_len, value_len;
        if (separator == NULL) goto failure;
        name_len = (size_t)(separator - environ[index]);
        value_len = strlen(separator + 1);
        if (name_len < 1 || name_len > 255 || value_len > 65536) goto failure;
        snapshot[index].name = dataphyre_persistent_copy(environ[index], name_len);
        snapshot[index].name_len = name_len;
        snapshot[index].value = dataphyre_persistent_copy(separator + 1, value_len);
        snapshot[index].value_len = value_len;
        if (snapshot[index].name == NULL || snapshot[index].value == NULL) goto failure;
    }
    *entries = snapshot;
    *count = total;
    return 1;
failure:
    *entries = snapshot;
    *count = total;
    dataphyre_free_entries(entries, count);
    return 0;
}

static int dataphyre_store_values(zval *values)
{
    zend_string *key;
    zval *value;
    size_t index = 0;
    zend_string *previous = NULL;
    size_t total;
    if (values == NULL || Z_TYPE_P(values) != IS_ARRAY) return 0;
    total = zend_hash_num_elements(Z_ARRVAL_P(values));
    if (total < 1 || total > DATAPHYRE_ENVIRONMENT_MAX_ENTRIES) return 0;
    dataphyre_managed_values = pecalloc(total, sizeof(dataphyre_environment_entry), 1);
    if (dataphyre_managed_values == NULL) return 0;
    dataphyre_managed_value_count = total;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(values), key, value) {
        if (key == NULL || Z_TYPE_P(value) != IS_STRING
            || !dataphyre_name_valid(ZSTR_VAL(key), ZSTR_LEN(key))
            || !dataphyre_value_valid(Z_STRVAL_P(value), Z_STRLEN_P(value))
            || (previous != NULL && zend_binary_strcmp(
                ZSTR_VAL(previous), ZSTR_LEN(previous), ZSTR_VAL(key), ZSTR_LEN(key)
            ) >= 0)) {
            return 0;
        }
        dataphyre_managed_values[index].name = dataphyre_persistent_copy(ZSTR_VAL(key), ZSTR_LEN(key));
        dataphyre_managed_values[index].name_len = ZSTR_LEN(key);
        dataphyre_managed_values[index].value = dataphyre_persistent_copy(Z_STRVAL_P(value), Z_STRLEN_P(value));
        dataphyre_managed_values[index].value_len = Z_STRLEN_P(value);
        if (dataphyre_managed_values[index].name == NULL || dataphyre_managed_values[index].value == NULL) {
            return 0;
        }
        previous = key;
        index++;
    } ZEND_HASH_FOREACH_END();
    return index == total;
}

static const dataphyre_environment_entry *dataphyre_find_entry(
    const dataphyre_environment_entry *entries, size_t count, const char *name, size_t name_len
)
{
    size_t index;
    for (index = 0; index < count; index++) {
        if (entries[index].name_len == name_len && memcmp(entries[index].name, name, name_len) == 0) {
            return &entries[index];
        }
    }
    return NULL;
}

static int dataphyre_restore_environment(zend_bool include_application)
{
    size_t current_count = 0, index = 0;
    char **cursor, **names = NULL;
    for (cursor = environ; cursor != NULL && *cursor != NULL; cursor++) current_count++;
    if (current_count > 1024) return 0;
    names = current_count > 0 ? calloc(current_count, sizeof(char *)) : NULL;
    if (current_count > 0 && names == NULL) return 0;
    for (cursor = environ; cursor != NULL && *cursor != NULL && index < current_count; cursor++, index++) {
        const char *separator = strchr(*cursor, '=');
        size_t name_len;
        if (separator == NULL) goto failure;
        name_len = (size_t)(separator - *cursor);
        names[index] = malloc(name_len + 1);
        if (names[index] == NULL) goto failure;
        memcpy(names[index], *cursor, name_len);
        names[index][name_len] = '\0';
    }
    for (index = 0; index < current_count; index++) {
        size_t name_len = strlen(names[index]);
        if (dataphyre_find_entry(dataphyre_managed_baseline, dataphyre_managed_baseline_count, names[index], name_len) == NULL) {
            unsetenv(names[index]);
        }
    }
    for (index = 0; index < dataphyre_managed_baseline_count; index++) {
        if (setenv(dataphyre_managed_baseline[index].name, dataphyre_managed_baseline[index].value, 1) != 0) goto failure;
    }
    if (include_application) {
        for (index = 0; index < dataphyre_managed_value_count; index++) {
            if (setenv(dataphyre_managed_values[index].name, dataphyre_managed_values[index].value, 1) != 0) goto failure;
        }
        if (setenv("DATAPHYRE_RUNTIME_POOL", "web", 1) != 0
            || setenv("DATAPHYRE_RUNTIME_POOL_ROLE", "web", 1) != 0) goto failure;
    }
    for (index = 0; index < current_count; index++) free(names[index]);
    free(names);
    return 1;
failure:
    if (names != NULL) {
        for (index = 0; index < current_count; index++) free(names[index]);
        free(names);
    }
    return 0;
}

static int dataphyre_managed_pool_consume(void)
{
    static const char *const envelope_keys[] = {
        "contract", "role", "nonce", "target", "managed_bootstrap", "values"
    };
    static const char *const target_keys[] = {"pid", "start_time_ticks", "ancestry"};
    static const char *const managed_keys[] = {"contract", "role", "project_root", "private_key"};
    int fd = DATAPHYRE_ENVIRONMENT_FD;
    char header[9], start_ticks[64], acknowledgement[512];
    char *bytes = NULL;
    size_t length = 0;
    zval decoded;
    zval *contract, *role, *nonce, *target, *managed, *values, *pid_value, *start_value, *ancestry;
    zval *managed_contract, *managed_role, *project_root, *private_key, *environment_root;
    smart_str canonical = {0};
    int result = 0, acknowledgement_length;
    int64_t deadline_milliseconds;
    char resolved_root[PATH_MAX];
    struct stat root_status;
    ZVAL_UNDEF(&decoded);
    deadline_milliseconds = dataphyre_monotonic_milliseconds();
    if (deadline_milliseconds < 0
        || deadline_milliseconds > INT64_MAX - DATAPHYRE_ENVIRONMENT_TIMEOUT_MILLISECONDS) goto cleanup;
    deadline_milliseconds += DATAPHYRE_ENVIRONMENT_TIMEOUT_MILLISECONDS;
    if (strcmp(sapi_module.name, "fpm-fcgi") != 0 || !dataphyre_process_boundary(0)
        || !dataphyre_snapshot_environ(&dataphyre_managed_baseline, &dataphyre_managed_baseline_count)
        || !dataphyre_read_exact(fd, header, sizeof(header), deadline_milliseconds)
        || !dataphyre_hex_header_length(header, &length)) {
        goto cleanup;
    }
    bytes = emalloc(length + 1);
    if (bytes == NULL || !dataphyre_read_exact(fd, bytes, length, deadline_milliseconds)) goto cleanup;
    bytes[length] = '\0';
    if (php_json_decode_ex(&decoded, bytes, length, PHP_JSON_OBJECT_AS_ARRAY, 12) != SUCCESS
        || !dataphyre_array_exact_keys(&decoded, envelope_keys, 6)
        || php_json_encode_ex(
            &canonical, &decoded,
            PHP_JSON_UNESCAPED_SLASHES | PHP_JSON_UNESCAPED_UNICODE | PHP_JSON_UNESCAPED_LINE_TERMINATORS,
            12
        ) != SUCCESS) goto cleanup;
    smart_str_0(&canonical);
    if (canonical.s == NULL || length != ZSTR_LEN(canonical.s) + 1 || bytes[length - 1] != '\n'
        || memcmp(bytes, ZSTR_VAL(canonical.s), ZSTR_LEN(canonical.s)) != 0) goto cleanup;
    contract = dataphyre_array_value(&decoded, "contract", sizeof("contract") - 1);
    role = dataphyre_array_value(&decoded, "role", sizeof("role") - 1);
    nonce = dataphyre_array_value(&decoded, "nonce", sizeof("nonce") - 1);
    target = dataphyre_array_value(&decoded, "target", sizeof("target") - 1);
    managed = dataphyre_array_value(&decoded, "managed_bootstrap", sizeof("managed_bootstrap") - 1);
    values = dataphyre_array_value(&decoded, "values", sizeof("values") - 1);
    if (!dataphyre_string_equals(contract, "dataphyre.application_child_environment.v3")
        || !dataphyre_string_equals(role, "web-pool") || nonce == NULL || Z_TYPE_P(nonce) != IS_STRING
        || Z_STRLEN_P(nonce) != 64 || !dataphyre_lower_hex_valid(Z_STRVAL_P(nonce), Z_STRLEN_P(nonce))
        || target == NULL || managed == NULL || values == NULL) goto cleanup;
    pid_value = dataphyre_array_value(target, "pid", sizeof("pid") - 1);
    start_value = dataphyre_array_value(target, "start_time_ticks", sizeof("start_time_ticks") - 1);
    ancestry = dataphyre_array_value(target, "ancestry", sizeof("ancestry") - 1);
    if (pid_value == NULL || Z_TYPE_P(pid_value) != IS_LONG || Z_LVAL_P(pid_value) != (zend_long)getpid()
        || start_value == NULL || Z_TYPE_P(start_value) != IS_STRING
        || !dataphyre_array_exact_keys(target, target_keys, 3) || !dataphyre_ancestry_valid(ancestry)
        || !dataphyre_current_start_ticks(start_ticks, sizeof(start_ticks))
        || strlen(start_ticks) != Z_STRLEN_P(start_value)
        || memcmp(start_ticks, Z_STRVAL_P(start_value), Z_STRLEN_P(start_value)) != 0) goto cleanup;
    managed_contract = dataphyre_array_value(managed, "contract", sizeof("contract") - 1);
    managed_role = dataphyre_array_value(managed, "role", sizeof("role") - 1);
    project_root = dataphyre_array_value(managed, "project_root", sizeof("project_root") - 1);
    private_key = dataphyre_array_value(managed, "private_key", sizeof("private_key") - 1);
    environment_root = dataphyre_array_value(values, "DATAPHYRE_RUNTIME_PROJECT_ROOT", sizeof("DATAPHYRE_RUNTIME_PROJECT_ROOT") - 1);
    if (!dataphyre_string_equals(managed_contract, "dataphyre.managed_runtime_bootstrap.v1")
        || !dataphyre_string_equals(managed_role, "web")
        || !dataphyre_array_exact_keys(managed, managed_keys, 4)
        || project_root == NULL || Z_TYPE_P(project_root) != IS_STRING
        || private_key == NULL || Z_TYPE_P(private_key) != IS_STRING
        || !dataphyre_private_key_valid(Z_STRVAL_P(private_key), Z_STRLEN_P(private_key))
        || environment_root == NULL || Z_TYPE_P(environment_root) != IS_STRING
        || Z_STRLEN_P(environment_root) != Z_STRLEN_P(project_root)
        || memcmp(Z_STRVAL_P(environment_root), Z_STRVAL_P(project_root), Z_STRLEN_P(project_root)) != 0
        || realpath(Z_STRVAL_P(project_root), resolved_root) == NULL
        || strlen(resolved_root) != Z_STRLEN_P(project_root)
        || memcmp(resolved_root, Z_STRVAL_P(project_root), Z_STRLEN_P(project_root)) != 0
        || lstat(resolved_root, &root_status) != 0 || !S_ISDIR(root_status.st_mode) || S_ISLNK(root_status.st_mode)
        || !dataphyre_store_values(values)) goto cleanup;
    dataphyre_managed_project_root = dataphyre_persistent_copy(Z_STRVAL_P(project_root), Z_STRLEN_P(project_root));
    dataphyre_managed_project_root_len = Z_STRLEN_P(project_root);
    dataphyre_managed_private_key = dataphyre_persistent_copy(Z_STRVAL_P(private_key), Z_STRLEN_P(private_key));
    dataphyre_managed_private_key_len = Z_STRLEN_P(private_key);
    if (dataphyre_managed_project_root == NULL || dataphyre_managed_private_key == NULL) goto cleanup;
    acknowledgement_length = snprintf(
        acknowledgement, sizeof(acknowledgement),
        "{\"contract\":\"dataphyre.application_child_environment_ack.v1\",\"nonce\":\"%.*s\",\"pid\":%ld,\"start_time_ticks\":\"%s\"}\n",
        (int)Z_STRLEN_P(nonce), Z_STRVAL_P(nonce), (long)getpid(), start_ticks
    );
    if (acknowledgement_length < 1 || (size_t)acknowledgement_length >= sizeof(acknowledgement)
        || !dataphyre_write_exact(fd, acknowledgement, (size_t)acknowledgement_length, deadline_milliseconds)) goto cleanup;
    dataphyre_managed_master_pid = getpid();
    dataphyre_managed_pool = 1;
    result = 1;
cleanup:
    if (fd >= 0) close(fd);
    dataphyre_zero(acknowledgement, sizeof(acknowledgement));
    if (bytes != NULL) {
        dataphyre_zero(bytes, length);
        efree(bytes);
    }
    smart_str_free(&canonical);
    if (!Z_ISUNDEF(decoded)) zval_ptr_dtor(&decoded);
    if (!result) {
        dataphyre_free_entries(&dataphyre_managed_values, &dataphyre_managed_value_count);
        dataphyre_free_entries(&dataphyre_managed_baseline, &dataphyre_managed_baseline_count);
        if (dataphyre_managed_project_root != NULL) {
            dataphyre_zero(dataphyre_managed_project_root, dataphyre_managed_project_root_len);
            pefree(dataphyre_managed_project_root, 1);
            dataphyre_managed_project_root = NULL;
            dataphyre_managed_project_root_len = 0;
        }
        if (dataphyre_managed_private_key != NULL) {
            dataphyre_zero(dataphyre_managed_private_key, dataphyre_managed_private_key_len);
            pefree(dataphyre_managed_private_key, 1);
            dataphyre_managed_private_key = NULL;
            dataphyre_managed_private_key_len = 0;
        }
    }
    return result;
}

PHP_MINIT_FUNCTION(dataphyre_environment_fd)
{
    const char *role;
    REGISTER_INI_ENTRIES();
    role = INI_STR("dataphyre_environment_fd.managed_pool_role");
    if (role == NULL || role[0] == '\0') {
        return SUCCESS;
    }
    if (strcmp(role, "web") != 0 || !dataphyre_managed_pool_consume()) {
        return FAILURE;
    }
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(dataphyre_environment_fd)
{
    dataphyre_managed_request = 0;
    dataphyre_managed_context_consumed = 0;
    dataphyre_managed_pool = 0;
    dataphyre_free_entries(&dataphyre_managed_values, &dataphyre_managed_value_count);
    dataphyre_free_entries(&dataphyre_managed_baseline, &dataphyre_managed_baseline_count);
    if (dataphyre_managed_project_root != NULL) {
        dataphyre_zero(dataphyre_managed_project_root, dataphyre_managed_project_root_len);
        pefree(dataphyre_managed_project_root, 1);
        dataphyre_managed_project_root = NULL;
        dataphyre_managed_project_root_len = 0;
    }
    if (dataphyre_managed_private_key != NULL) {
        dataphyre_zero(dataphyre_managed_private_key, dataphyre_managed_private_key_len);
        pefree(dataphyre_managed_private_key, 1);
        dataphyre_managed_private_key = NULL;
        dataphyre_managed_private_key_len = 0;
    }
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}

PHP_RINIT_FUNCTION(dataphyre_environment_fd)
{
#if defined(ZTS) && defined(COMPILE_DL_DATAPHYRE_ENVIRONMENT_FD)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    (void) type;
    (void) module_number;
    if (!dataphyre_managed_pool) return SUCCESS;
    dataphyre_managed_request = 0;
    dataphyre_managed_context_consumed = 0;
    if (getpid() == dataphyre_managed_master_pid
        || !dataphyre_process_boundary(dataphyre_managed_master_pid)
        || !dataphyre_restore_environment(1)
        || chdir(dataphyre_managed_project_root) != 0) {
        _exit(78);
    }
    umask(0027);
    dataphyre_managed_request = 1;
    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(dataphyre_environment_fd)
{
    (void) type;
    (void) module_number;
    if (!dataphyre_managed_pool || !dataphyre_managed_request) return SUCCESS;
    dataphyre_managed_request = 0;
    dataphyre_managed_context_consumed = 1;
    if (!dataphyre_restore_environment(0) || chdir(dataphyre_managed_project_root) != 0) {
        _exit(78);
    }
    umask(0027);
    return SUCCESS;
}

PHP_FUNCTION(dataphyre_close_inherited_fd)
{
    zend_long fd;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(fd)
    ZEND_PARSE_PARAMETERS_END();

    if (fd != 198) {
        RETURN_FALSE;
    }
    RETURN_BOOL(close((int) fd) == 0);
}

PHP_FUNCTION(dataphyre_open_inherited_environment_fd)
{
    ZEND_PARSE_PARAMETERS_NONE();
    int duplicated = dup(198);
    if (duplicated < 0) {
        RETURN_FALSE;
    }
    php_stream *stream = php_stream_fopen_from_fd(duplicated, "r+b", NULL);
    if (stream == NULL) {
        close(duplicated);
        RETURN_FALSE;
    }
    php_stream_to_zval(stream, return_value);
}

PHP_FUNCTION(dataphyre_close_unlisted_inherited_fds)
{
    ZEND_PARSE_PARAMETERS_NONE();
    if (geteuid() != 0) {
        RETURN_FALSE;
    }
    DIR *directory = opendir("/proc/self/fd");
    if (directory == NULL) {
        RETURN_FALSE;
    }
    int inventory_fd = dirfd(directory);
    struct dirent *entry;
    while ((entry = readdir(directory)) != NULL) {
        char *end = NULL;
        long fd = strtol(entry->d_name, &end, 10);
        if (end == entry->d_name || *end != '\0' || fd <= 2 || fd == 198 || fd == inventory_fd) {
            continue;
        }
        close((int) fd);
    }
    closedir(directory);
    RETURN_TRUE;
}

PHP_FUNCTION(dataphyre_enable_scheduler_child_subreaper)
{
    int enabled = 0;
    ZEND_PARSE_PARAMETERS_NONE();
    if (!dataphyre_scheduler_gateway_boundary()
        || prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0) != 0
        || prctl(PR_GET_CHILD_SUBREAPER, &enabled, 0, 0, 0) != 0
        || enabled != 1) {
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

PHP_FUNCTION(dataphyre_managed_pool_request_context)
{
    zval environment, managed_bootstrap;
    size_t index;
    ZEND_PARSE_PARAMETERS_NONE();
    if (!dataphyre_managed_pool || !dataphyre_managed_request
        || dataphyre_managed_context_consumed
        || getpid() == dataphyre_managed_master_pid
        || !dataphyre_process_boundary(dataphyre_managed_master_pid)) {
        RETURN_FALSE;
    }
    dataphyre_managed_context_consumed = 1;
    array_init_size(return_value, 7);
    add_assoc_string(return_value, "contract", "dataphyre.managed_php_web_request.v1");
    add_assoc_string(return_value, "role", "web");
    add_assoc_stringl(
        return_value, "project_root", dataphyre_managed_project_root, dataphyre_managed_project_root_len
    );
    add_assoc_long(return_value, "master_pid", (zend_long) dataphyre_managed_master_pid);
    add_assoc_long(return_value, "worker_pid", (zend_long) getpid());
    array_init_size(&managed_bootstrap, 4);
    add_assoc_string(&managed_bootstrap, "contract", "dataphyre.managed_runtime_bootstrap.v1");
    add_assoc_string(&managed_bootstrap, "role", "web");
    add_assoc_stringl(
        &managed_bootstrap, "project_root", dataphyre_managed_project_root, dataphyre_managed_project_root_len
    );
    add_assoc_stringl(
        &managed_bootstrap, "private_key", dataphyre_managed_private_key, dataphyre_managed_private_key_len
    );
    add_assoc_zval(return_value, "managed_bootstrap", &managed_bootstrap);
    array_init_size(&environment, dataphyre_managed_value_count);
    for (index = 0; index < dataphyre_managed_value_count; index++) {
        add_assoc_stringl_ex(
            &environment,
            dataphyre_managed_values[index].name,
            dataphyre_managed_values[index].name_len,
            dataphyre_managed_values[index].value,
            dataphyre_managed_values[index].value_len
        );
    }
    add_assoc_zval(return_value, "environment", &environment);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dataphyre_close_inherited_fd, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, fd, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_dataphyre_open_inherited_environment_fd, 0, 0, MAY_BE_RESOURCE|MAY_BE_FALSE)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dataphyre_close_unlisted_inherited_fds, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dataphyre_enable_scheduler_child_subreaper, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_dataphyre_managed_pool_request_context, 0, 0, MAY_BE_ARRAY|MAY_BE_FALSE)
ZEND_END_ARG_INFO()

static const zend_function_entry dataphyre_environment_fd_functions[] = {
    PHP_FE(dataphyre_close_inherited_fd, arginfo_dataphyre_close_inherited_fd)
    PHP_FE(dataphyre_open_inherited_environment_fd, arginfo_dataphyre_open_inherited_environment_fd)
    PHP_FE(dataphyre_close_unlisted_inherited_fds, arginfo_dataphyre_close_unlisted_inherited_fds)
    PHP_FE(dataphyre_enable_scheduler_child_subreaper, arginfo_dataphyre_enable_scheduler_child_subreaper)
    PHP_FE(dataphyre_managed_pool_request_context, arginfo_dataphyre_managed_pool_request_context)
    PHP_FE_END
};

PHP_MINFO_FUNCTION(dataphyre_environment_fd)
{
    (void) zend_module;
    php_info_print_table_start();
    php_info_print_table_header(2, "Dataphyre environment fd support", "enabled");
    php_info_print_table_row(2, "Managed PHP web pool", dataphyre_managed_pool ? "enabled" : "disabled");
    php_info_print_table_end();
}

static const zend_module_dep dataphyre_environment_fd_dependencies[] = {
    ZEND_MOD_REQUIRED("json")
    ZEND_MOD_END
};

zend_module_entry dataphyre_environment_fd_module_entry = {
    STANDARD_MODULE_HEADER_EX,
    NULL,
    dataphyre_environment_fd_dependencies,
    "dataphyre_environment_fd",
    dataphyre_environment_fd_functions,
    PHP_MINIT(dataphyre_environment_fd),
    PHP_MSHUTDOWN(dataphyre_environment_fd),
    PHP_RINIT(dataphyre_environment_fd),
    PHP_RSHUTDOWN(dataphyre_environment_fd),
    PHP_MINFO(dataphyre_environment_fd),
    "1.2.0",
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_DATAPHYRE_ENVIRONMENT_FD
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(dataphyre_environment_fd)
#endif
