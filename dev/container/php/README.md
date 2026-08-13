# Dataphyre development runtime

This Dockerfile is the shared PHP development base for Dataphyre applications.
It contains the Dataphyre runtime and the common PHP extensions used by first-
party applications, but no application source, credentials, database, proxy, or
tenant-specific behavior.

The final stage includes Composer, Git, the PostgreSQL client, and runtime
libraries. Compilers and PHP extension development headers remain in the
discarded build stage, so ordinary dependency installation needs no host tools
without bloating the reusable runtime image.

An application can build it directly from GitHub without cloning Dataphyre:

```yaml
services:
  app:
    build:
      context: ${DATAPHYRE_DEV_CONTEXT:-https://github.com/jeremie5/dataphyre.git#main}
      dockerfile: dev/container/php/Dockerfile
```

The application repository remains the only live source bind mount. Databases,
queues, object stores, and other application dependencies belong to that
application's private Compose project. Use a different Git ref in
`DATAPHYRE_DEV_CONTEXT` when intentionally testing a framework branch.
