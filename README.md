![Dataphyre](runtime/logo.png)

# Dataphyre

Dataphyre is a modular PHP application framework and runtime. It provides
routing, MVC, APIs, data access, permissions, storage, diagnostics, UI
primitives, and agent-facing application tooling without forcing product code
into the framework core.

## Run the minimal application

Docker Compose is the only host dependency:

```sh
docker compose -f examples/minimal/compose.dev.yaml up -d --build
curl -fsS http://127.0.0.1:18080/
```

The example is isolated to this repository, binds only to loopback, and keeps
generated identity and cache state in project-owned volumes. Stop it without
deleting that state:

```sh
docker compose -f examples/minimal/compose.dev.yaml down
```

Do not add `--volumes` unless resetting the example's local identity is
intentional.

## Use Dataphyre in an application

Applications own their source, configuration, migrations, tests, and release
contract. They consume Dataphyre through Composer, an immutable Cloud release
dependency, or an explicit Git build context for local development. A supported
application never depends on a sibling checkout or a shared workspace mount.

Start with:

- [Getting started](docs/GETTING_STARTED.md)
- [Local Docker development](docs/LOCAL_DEVELOPMENT.md)
- [Minimal application example](examples/minimal/README.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Module index](docs/MODULES.md)
- [Configuration reference](docs/CONFIGURATION.md)
- [Testing](docs/TESTING.md)
- [Security policy](docs/SECURITY.md)
- [Contributing](docs/CONTRIBUTING.md)

## Requirements

The runtime supports PHP 8.1 or later. The repository's Docker-first developer
image uses PHP 8.4 and includes the extensions needed by first-party modules.

Dataphyre is released under the [MIT License](LICENSE). Third-party components
retain their own license notices.
