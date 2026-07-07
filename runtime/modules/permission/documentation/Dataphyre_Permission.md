# Dataphyre Permission

Dataphyre Permission is a semantic authorization module for fast, readable permission rules. It keeps the legacy permission grammar that worked well, then makes it first-class for Dataphyre Access, Panel, policy gate integration, and optional Laravel adapter support.

## Rule Grammar

Permissions are dot-delimited semantic strings:

```php
\Dataphyre\Permission\Permission::check('panel.orders.update');
```

Supported forms:

| Rule | Meaning |
| --- | --- |
| `orders.view` | exact permission, and also a parent grant for child checks |
| `orders.*` | wildcard grant for every child permission |
| `orders.%` | passes when any child permission under `orders` exists |
| `-orders.delete` | explicit deny; deny always wins |
| `-orders.*` | deny every child permission |
| `<orders.view>` | strict exact check, no parent inference |
| `role.admin` / `group.admin` | expand a configured role; `group.*` is kept for legacy compatibility |

Checks with an array require every listed permission. Use `Permission::any([...])` for any-of checks.

## Configuration

`permission.main.php` defines `DP_PERMISSION_CFG` with conservative defaults:

```php
[
    'default_roles' => ['default'],
    'roles' => [
        'admin' => ['panel.*'],
        'support' => ['panel.orders.*', '-panel.orders.force_delete'],
    ],
    'aliases' => [
        'orders.manage' => 'panel.orders.update',
    ],
]
```

Subjects can expose `permissions`, `roles`, `groups`, or object getters. The module also uses Dataphyre Access when no subject is passed:

```php
Permission::for($user)->can('panel.products.create');
Permission::check(['panel.orders.view', 'panel.orders.update']);
Permission::any(['panel.orders.refund', 'panel.orders.cancel'], $user);
Permission::ensure('panel.orders.update', $user); // throws AuthorizationException on deny
```

For high-volume UI surfaces, batch decisions compile the subject once:

```php
$decisions = Permission::decisions([
    'panel.orders.view_any',
    'panel.orders.create',
    'panel.orders.force_delete',
], $user);

$allowed = Permission::filterAllowed(array_keys($decisions), $user);
```

Named conditions add ABAC-style context without making permission strings noisy:

```php
Permission::defineCondition('own', function($user, array $context) {
    return ($context['record']['user_id'] ?? null) === ($user['id'] ?? null);
});

Permission::checkWhen('panel.orders.update', 'own', $user, ['record'=>$order]);
Permission::ensureWhen('panel.orders.update', ['own', 'tenant'], $user, $context);
```

Runtime tracing can be enabled around a suspicious flow without changing checks:

```php
Permission::trace(true);
Permission::check('panel.orders.update', $user, ['tenant'=>'north']);
Permission::any(['panel.orders.refund', 'panel.orders.cancel'], $user);

$summary = Permission::traceSummary();
Permission::flushTrace();
```

The trace captures decision timings, cache hits and misses, matched/failed
permissions, subject ids, and compact aggregate stats. Context is omitted by
default; set `DP_PERMISSION_CFG['trace']['include_context']` only in trusted
diagnostic environments.

Permission expectations can be tested as a compact matrix:

```php
$subjects = [
    'manager' => ['permissions' => ['panel.orders.*', '-panel.orders.force_delete']],
    'viewer' => ['permissions' => ['panel.orders.view_any']],
];

$report = Permission::testMatrix($subjects, [
    'manager' => [
        'allow' => ['panel.orders.view_any', 'panel.orders.update'],
        'deny' => ['panel.orders.force_delete'],
    ],
    'viewer' => [
        'panel.orders.view_any' => true,
        'panel.orders.update' => false,
    ],
]);

Permission::assertMatrix($subjects, $reportingExpectations);
Permission::assertAllows($subjects['manager'], 'panel.orders.update');
Permission::assertDenies($subjects['viewer'], 'panel.orders.update');
```

`testMatrix()` returns a report with pass/fail counts and explanations for
failures. `assertMatrix()`, `assertAllows()`, and `assertDenies()` throw
`RuntimeException` on mismatch for direct use in test suites.

Use the simulator for safe what-if analysis before saving a role or assignment:

```php
$preview = Permission::simulate(
    $user,
    [
        'grant' => ['panel.orders.update'],
        'deny' => ['panel.orders.force_delete'],
        'remove_roles' => ['viewer'],
    ],
    ['panel.orders.view_any', 'panel.orders.update', 'panel.orders.force_delete']
);

$preview['delta']['granted']; // permissions that would become allowed
$preview['delta']['denied'];  // permissions that would become denied
```

Simulation uses the same rule compiler as live checks, but never writes to the
assignment tables.

Snapshots capture effective access over a catalog and can be diffed for reviews:

```php
$catalog = ['panel.orders.view_any', 'panel.orders.update', 'panel.orders.delete'];

$before = Permission::snapshot($viewer, $catalog);
$after = Permission::snapshot($manager, $catalog, [], ['include_explain' => true]);
$diff = Permission::diffSnapshots($before, $after);
```

Snapshot diffs report permissions newly granted or denied, plus role and rule
changes. They are useful in deployment checks, admin previews, and support
debugging because they describe the effective access surface rather than only
the raw assignments.

Rule optimization helps keep large roles readable:

```php
$analysis = Permission::analyzeRules([
    'panel.orders.*',
    'panel.orders.view_any',
    'panel.orders.update',
    '-panel.orders.force_delete',
]);

$optimized = Permission::optimizeRules($analysis['optimized']);
$roleReports = Permission::analyzeRoleRules($roles);
```

The optimizer removes duplicates and same-sign shadowed rules, such as
`panel.orders.update` when `panel.orders.*` is already present. It reports
conflicting grant/deny pairs without silently removing them, so explicit deny
exceptions remain visible during review.

## Dataphyre Access Integration

The framework bootstrap loads Access and resolves the current user via `Dataphyre\Access\Auth` when available. Applications can override resolution with dialbacks:

```php
\dataphyre\core::register_dialback('CALL_PERMISSION_RESOLVE_SUBJECT_PERMISSIONS', function ($subject, array $context) {
    return $subject['permissions'] ?? [];
});

\dataphyre\core::register_dialback('CALL_PERMISSION_RESOLVE_SUBJECT_ROLES', function ($subject, array $context) {
    return $subject['roles'] ?? [];
});
```

## Stored Assignments

When SQL is loaded, the module registers three tables:

```text
dataphyre.permission_assignments
dataphyre.permission_roles
dataphyre.permission_role_permissions
```

Use the facade for assignment workflows:

```php
\Dataphyre\Permission\Permission::storeRole('support', [
    'panel.orders.*',
    '-panel.orders.force_delete',
]);

\Dataphyre\Permission\Permission::assignRole($user, 'support');
\Dataphyre\Permission\Permission::assignPermission($user, 'panel.products.create');
\Dataphyre\Permission\Permission::denyPermission($user, 'panel.products.delete');
\Dataphyre\Permission\Permission::revoke($user, 'permission', 'panel.products.create');
```

Stored rows hydrate automatically during checks. Pass `scope`, `tenant`, or `tenant_id` in the context to isolate assignments by tenant while still inheriting `global` rows.

## Panel Integration

Register the Panel bridge after Panel auth:

```php
$panel = \Dataphyre\Access\PanelAuth::register($panel);
$panel = \Dataphyre\Permission\PermissionPanel::register($panel);
```

Panel also exposes convenience methods that load this bridge on demand:

```php
$panel = \Dataphyre\Panel\Panel::surface('admin')
    ->auth()
    ->permissions(['super_permission' => 'panel.*']);

\Dataphyre\Panel\Panel::permissionAdmin();
```

Or use the closure directly in Panel configuration:

```php
'authorize' => \Dataphyre\Permission\PermissionPanel::authorizer(),
```

Panel configuration can opt into the same authorizer without code:

```php
'permission' => [
    'super_permission' => 'panel.*',
    'allow_guest_pages' => ['login', 'register'],
],
```

Panel manifests expose the generated permission surface under
`permission.catalog`, `permission.permissions`, and
`capabilities.permission`. This lets Flightdeck, generated docs, tests, or admin
previews inspect the expected Panel grants without mounting the built-in
Permission resources.
Resource manifests mirror the same surface under `resource.permission`, including
operation, action, relation, and flat permission maps for the individual
resource.
Action manifests also expose the exact action permission, and relation manifests
expose view/update relation permissions, so debug tooling can point at the
specific button or relation panel that needs a grant.
Panel action state and relation access use the same bridge when enabled, so
`panel.orders.action.review` can deny the button state and
`panel.orders.relation.items.view` controls relation page access before relation
handlers run.
Custom Panel pages use `panel.{page}.view` by default, expose that permission in
their page manifest, and still honor `allow_guest_pages` for login/register
flows.

Set `manifest_decisions` in the Panel permission options to include a per-request
snapshot of allowed and denied catalog permissions:

```php
$panel->permissions([
    'manifest_decisions' => true,
]);
```

The snapshot includes subject id, roles, rules, request context, allowed rows,
denied rows, and a decision map. Treat it as trusted-admin/debug data.

To expose a built-in security workspace:

```php
$panel = \Dataphyre\Permission\PermissionPanel::registerAdminResources($panel);
```

This registers Roles and Assignments resources. They are protected by semantic
permissions too:

```text
panel.permission.roles.*
panel.permission.assignments.*
```

It also registers a Permission Catalog page. Grant
`panel.permission.catalog.view` or the configured Panel super permission to view
the generated permission map.

## Catalogs And Matrices

Permission catalogs make the authorization surface inspectable:

```php
$rows = \Dataphyre\Permission\PermissionCatalog::panel($panel);
$markdown = \Dataphyre\Permission\PermissionCatalog::markdown($panel);
$matrix = \Dataphyre\Permission\PermissionCatalog::roleMatrix($panel);
```

Facade shortcuts are also available:

```php
$rows = \Dataphyre\Permission\Permission::panelCatalog($panel);
$matrix = \Dataphyre\Permission\Permission::roleMatrix($panel);
$audit = \Dataphyre\Permission\Permission::audit($panel);
```

The catalog derives resource operations, custom actions, and relation operations
from registered Panel resources. Role matrices compare stored roles against that
catalog, making over-broad grants and missing permissions visible during review.
Audits flag empty roles, unknown permissions, broad grants, conflicting
grant/deny rules, assignments to unknown roles, and catalog permissions that no
stored role currently grants.

Generated role presets provide a sane starting point:

```php
$presets = \Dataphyre\Permission\Permission::rolePresets($panel);
$results = \Dataphyre\Permission\Permission::seedRolePresets($panel);
```

The default presets are `owner`, `manager`, `operator`, `viewer`, and `auditor`.
They are generated from the current Panel catalog, so newly registered resources
are reflected without maintaining a separate static permission list.

## Manifests

Permission manifests are deterministic exports for review, CI, and environment
promotion:

```php
$manifest = \Dataphyre\Permission\Permission::manifest($panel);
$json = \Dataphyre\Permission\Permission::manifestJson($panel);
$diff = \Dataphyre\Permission\Permission::diffManifests($old, $new);
\Dataphyre\Permission\Permission::importManifestRoles($manifest, ['dry_run'=>true]);
```

Manifests can include generated catalog rows, role presets, stored roles,
assignments, and audit output.

## CLI Checks

`kernel/permission_check.php` validates exported permission JSON without
booting a request, Panel, or SQL:

```bash
php common/dataphyre/runtime/modules/permission/kernel/permission_check.php \
    --manifest=permission-manifest.json \
    --fail-on-warning
```

It can also audit standalone role files against a known permission list:

```bash
php common/dataphyre/runtime/modules/permission/kernel/permission_check.php \
    --roles=roles.json \
    --known=permission-catalog.json \
    --assignments=assignments.json \
    --json=permission-report.json
```

For promotion workflows, compare a new manifest against the currently deployed
manifest:

```bash
php common/dataphyre/runtime/modules/permission/kernel/permission_check.php \
    --manifest=permission-next.json \
    --against=permission-current.json \
    --fail-on-diff
```

By default the checker exits non-zero for errors. Add `--fail-on-warning`,
`--fail-on-info`, or `--fail-on-diff` when a stricter pipeline should block broad
grants, uncovered catalog entries, or manifest drift.

Panel permissions are generated as:

```text
panel.{resource}.{operation}
panel.orders.view_any
panel.orders.update
panel.orders.relation.items.update
panel.orders.action.refund
```

For per-resource policies:

```php
$orders = \Dataphyre\Permission\PermissionPanel::resource($panel->resource('orders'));
```

## Laravel Adapter

The optional Laravel service provider registers a `Gate::before` hook that delegates abilities to Dataphyre Permission:

```php
Dataphyre\Permission\Laravel\PermissionServiceProvider::class
```

Middleware is available as `Dataphyre\Permission\Laravel\AuthorizePermission` and accepts one or more semantic permission strings.

## Route Middleware

Dataphyre Routing exposes permission middleware aliases:

```php
Route::get('/admin/orders', OrderController::class)
    ->middleware('auth', 'can:panel.orders.view_any');

Route::post('/admin/orders/{id}/transition', TransitionController::class)
    ->middleware('can_any:panel.orders.update,panel.orders.transition');

Route::post('/admin/orders/{id}', UpdateOrderController::class)
    ->middleware('can_when:panel.orders.update|own,tenant');
```

`can` requires all listed permissions. `can_any` accepts the first matching
permission. Both throw `Dataphyre\Permission\Exceptions\AuthorizationException`
with status code `403` when denied.
Condition-aware variants are also available: `can_when`, `permission_when`,
`can_any_when`, and `permission_any_when`.

## Shield Migration

Shield-style permission names can be translated into semantic permissions:

```php
Permission::fromShield('view_any_order');       // panel.orders.view_any
Permission::fromShield('force_delete_any_user'); // panel.users.force_delete_any
Permission::fromShieldMany(['view_order', '-delete_order']);
Permission::name('orders', 'update');           // panel.orders.update
```

This lets migrations keep existing role exports while moving checks and Panel
resources onto the clearer semantic grammar.

## Performance Notes

Rules are normalized once, roles are expanded once, and compiled permission sets keep exact grants and wildcard prefixes in hash maps. Request-level subject caching avoids rebuilding sets for every Panel navigation item, table action, and policy call. Runtime tracing is disabled by default and keeps its hot-path overhead to a static enabled check until explicitly enabled.
