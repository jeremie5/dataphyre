PHP_ARG_ENABLE([dataphyre_environment_fd],
  [whether to enable Dataphyre inherited environment descriptor support],
  [AS_HELP_STRING([--enable-dataphyre-environment-fd], [Enable Dataphyre environment fd support])],
  [no])

if test "$PHP_DATAPHYRE_ENVIRONMENT_FD" != "no"; then
  AC_DEFINE([HAVE_DATAPHYRE_ENVIRONMENT_FD], [1], [Dataphyre environment fd support])
  PHP_NEW_EXTENSION([dataphyre_environment_fd], [dataphyre_environment_fd.c], [$ext_shared])
fi
