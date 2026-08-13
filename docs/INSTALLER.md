# Dataphyre Installer

The Dataphyre installer is a project lifecycle tool. It is not part of request
boot and should not run during application requests.

It manages only the framework install path:

```text
dataphyre
```

Applications are private repositories and declare their dependencies on other
applications in the consuming project registry.

## Commands

```bash
php dataphyre/installer/install.php init --root .
php dataphyre/installer/install.php install --root . --source ../dataphyre
php dataphyre/installer/install.php update --root . --source ../dataphyre
php dataphyre/installer/install.php lock
php dataphyre/installer/install.php verify
php dataphyre/installer/install.php check
php dataphyre/installer/install.php doctor
```

`init` creates `dataphyre.project.json` when a project adopts installer-managed
Dataphyre updates. When no `--source` is provided, `install` and `update` clone
the configured Git source from the consuming project's `dataphyre.project.json`.
Passing `--source ../dataphyre` uses a local framework checkout instead.

`lock` writes the installed tree hash to `dataphyre.lock`. `verify` checks the
installed tree, Dataphyre manifest, and application repository registry against
that lock. `check` is an alias for `verify`. `doctor` prints the resolved
installer state for diagnostics.

`install` and `update` synchronize the managed export and prune stale files
inside `dataphyre` unless those files are excluded by the Dataphyre
manifest. The installer refuses to prune or export into application roots.

The installer refuses to export Dataphyre into application roots or any other
project-owned path outside `dataphyre`.
