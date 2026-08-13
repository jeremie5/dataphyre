# Local application development

The supported local shape is one repository, one private Compose project, and
one unique loopback port set. Applications do not share source trees, document
roots, databases, Docker networks, or mutable dependency containers.

## Fastest start

A minimal Dataphyre checkout can be started with:

```sh
docker compose -f examples/minimal/compose.dev.yaml up -d --build
curl -fsS http://127.0.0.1:18080/
```

No host PHP installation is required. Docker builds the shared development PHP
runtime and mounts only the example application files.

Use Compose itself for the first diagnostic pass:

```sh
docker compose -f examples/minimal/compose.dev.yaml ps
docker compose -f examples/minimal/compose.dev.yaml logs --tail=200 app
```

## Application contract

Every application repository owns:

- `compose.dev.yaml` and its documented, unique loopback ports;
- a private default Compose network and application-owned named volumes;
- its migration, seed, health, and optional frontend services;
- a flight sheet and application manifest;
- `.env.example` only when user-supplied local values are genuinely needed;
- one documented start command and one diagnostic command.

Dataphyre is built from the public Git repository using
`dev/container/php/Dockerfile`. Private application dependencies are separate
Git build contexts fetched through BuildKit SSH forwarding. A dependency may be
copied into an image or run as a private service, but it may never be read from
a sibling checkout or another application's Compose network.

Development defaults must be loopback-only and clearly non-production. Secrets
must not be copied into images, committed, printed by diagnostics, or shared
between projects. Persistent databases and object stores use named volumes;
generated caches and logs are disposable and ignored by Git.

## Dataphyre Cloud parity

The same repository must deploy without changing application semantics. Local
Compose supplies the release-contract inputs—environment, secrets, services,
health checks, migrations, and immutable dependency revisions—that Dataphyre
Cloud supplies in a managed environment. Cloud remains tenant-neutral and must
not branch on application identity.

Local development may follow a Git branch such as `main`. Cloud deployments
must resolve that source to an immutable commit or release artifact before
rollout.

First-party release builders record that immutable framework revision in a
repository-owned `dependencies.json`, copy it into the signed application
artifact, and reconstruct `common/dataphyre` inside the release directory.
That installed path is part of the artifact layout; it is never permission to
read a sibling checkout or a shared mutable framework directory on the host.
