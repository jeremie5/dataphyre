# Dataphyre Installer

The Dataphyre installer is a project lifecycle tool. It is not part of request
boot and should not run during application requests.

It manages only the framework install path:

```text
common/dataphyre
```

Applications are private repositories and declare their dependencies on other
applications in the consuming project registry.

## Commands

```bash
php common/dataphyre/installer/install.php lock
php common/dataphyre/installer/install.php verify
php common/dataphyre/installer/install.php install --source ../dataphyre
php common/dataphyre/installer/install.php update --source ../dataphyre
```

When no `--source` is provided, `install` and `update` clone the configured Git
source from the consuming project's `dataphyre.project.json`. Passing
`--source ../dataphyre` uses a local framework checkout instead.

`lock` writes the installed tree hash to `dataphyre.lock`. `verify` checks the
installed tree, Dataphyre manifest, and application repository registry against
that lock.

`install` and `update` synchronize the managed export and prune stale files
inside `common/dataphyre` unless those files are excluded by the Dataphyre
manifest. The installer refuses to prune or export into application roots.

The installer refuses to export Dataphyre into application roots or non-framework
common folders.
