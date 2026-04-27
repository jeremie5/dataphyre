# Install Plugins

Dataphyre loads install-level plugin hooks from this directory. The hook files
are application-specific and are ignored for public export:

- `plugins/pre_init/*.php`
- `plugins/post_init/*.php`

Keep reusable extension behavior in runtime modules. Use these hook directories
for local boot glue, deployment-specific wiring, or private integrations.
