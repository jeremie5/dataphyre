#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <unistd.h>
#include <dirent.h>
#include <stdlib.h>
#include "php.h"
#include "ext/standard/info.h"
#include "main/php_streams.h"

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

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dataphyre_close_inherited_fd, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, fd, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_dataphyre_open_inherited_environment_fd, 0, 0, MAY_BE_RESOURCE|MAY_BE_FALSE)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dataphyre_close_unlisted_inherited_fds, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry dataphyre_environment_fd_functions[] = {
    PHP_FE(dataphyre_close_inherited_fd, arginfo_dataphyre_close_inherited_fd)
    PHP_FE(dataphyre_open_inherited_environment_fd, arginfo_dataphyre_open_inherited_environment_fd)
    PHP_FE(dataphyre_close_unlisted_inherited_fds, arginfo_dataphyre_close_unlisted_inherited_fds)
    PHP_FE_END
};

PHP_MINFO_FUNCTION(dataphyre_environment_fd)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "Dataphyre environment fd support", "enabled");
    php_info_print_table_end();
}

zend_module_entry dataphyre_environment_fd_module_entry = {
    STANDARD_MODULE_HEADER,
    "dataphyre_environment_fd",
    dataphyre_environment_fd_functions,
    NULL,
    NULL,
    NULL,
    NULL,
    PHP_MINFO(dataphyre_environment_fd),
    "1.1.0",
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_DATAPHYRE_ENVIRONMENT_FD
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(dataphyre_environment_fd)
#endif
