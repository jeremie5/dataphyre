# Dataphyre Panel SQL and PDO data sources

Panel's SQL adapter executes the same immutable `PanelDataQuery` contract as the
array and repository adapters without accepting raw SQL from a request. It is a
reference production adapter for PDO-backed MySQL, PostgreSQL, and SQLite.

The implementation is split deliberately:

- `PanelSqlSchema` is the only table, column, search-field, tenant-field, and
  relation allowlist.
- `PanelSqlQueryCompiler` converts known query-expression nodes into quoted SQL
  identifiers and named parameters.
- `PanelSqlExecutor` is the narrow execution contract. `PanelPdoSqlExecutor` is
  the first-party PDO implementation.
- `PanelSqlDataSource` owns authorization, tenant enforcement, count and
  aggregate execution, result validation, stable record keys, and signed
  keyset cursors.
- `PanelPdoDataSource` is the discoverable PDO facade.

## Minimal explicit configuration

SQL access is denied by default. A source must configure both an authorization
mode and at least one 32-byte cursor signing key.

```php
use Dataphyre\Panel\PanelPdoDataSource;
use Dataphyre\Panel\PanelQueryComparison;
use Dataphyre\Panel\PanelSqlRelation;
use Dataphyre\Panel\PanelSqlSchema;

$items = PanelSqlSchema::make(
    table: 'order_items',
    fields: ['id', 'order_id', 'tenant_id', 'sku', 'quantity'],
    primaryKey: 'id',
    options: [
        'tenant_field' => 'tenant_id',
        'search_fields' => ['sku'],
    ],
);

$orders = PanelSqlSchema::make(
    table: 'orders',
    fields: [
        'id', 'tenant_id', 'owner_id', 'email', 'status', 'total', 'created_at',
    ],
    primaryKey: 'id',
    options: [
        'tenant_field' => 'tenant_id',
        'search_fields' => ['email', 'status'],
        'relations' => [
            PanelSqlRelation::make('items', $items, 'id', 'order_id'),
        ],
        'max_limit' => 250,
    ],
);

$source = new PanelPdoDataSource($pdo, $orders, [
    'authorization_mode' => 'callback',
    'authorize' => static function (array $authority) {
        // A callback may return true, false, or a typed expression. It may not
        // return an SQL fragment, identifier, parameter, or arbitrary object.
        return PanelQueryComparison::make(
            'owner_id',
            'eq',
            (string) ($authority['actor_id'] ?? ''),
        );
    },
    'cursor_keys' => [
        '2026-07' => $_ENV['PANEL_SQL_CURSOR_KEY_CURRENT'],
        '2026-06' => $_ENV['PANEL_SQL_CURSOR_KEY_PREVIOUS'],
    ],
    'cursor_active_key' => '2026-07',
    'cursor_ttl' => 900,
]);
```

Use `authorization_mode => trusted` only when the registered source is already
inside an equivalent server-side permission boundary. The mode is explicit so
omitting a callback cannot silently become allow-all. `deny` is the default.

## Security contract

The adapter applies these rules before the executor sees a statement:

1. It negotiates query capabilities and rejects unsupported relations,
   projections, limits, and includes.
2. It requires a tenant when the schema declares a required tenant field. A
   tenant passed to a non-tenant schema is denied rather than ignored.
3. It evaluates the server-owned authorization callback. Exceptions, null,
   false, and unknown decision types deny access. Only `true` or a known typed
   `PanelQueryExpression` can continue.
4. It resolves every field, sort, aggregate, search field, and relation against
   the immutable schema allowlist.
5. It quotes identifiers for the selected PDO driver and binds every value as a
   parameter. There is no public raw-SQL escape hatch.
6. Nested relation predicates use correlated `EXISTS` statements. A related
   schema with a tenant field receives the same tenant automatically, including
   when a caller omitted an equivalent child predicate.

Public manifests and results never serialize cursor secrets, PDO connection
details, DSNs, credentials, SQL parameter values, authorization metadata, or
authorization callbacks. Executor manifests are bounded and recursively
redacted before they are cached.

## Query semantics

The reference compiler supports:

- grouped `and` and `or` expressions;
- equality, inequality, ordered comparisons, escaped contains/prefix/suffix
  matching, membership, null, and inclusive range nodes;
- nested `any`, `none`, and `all` relation quantifiers through allowlisted
  correlated relations;
- multi-term search over explicitly configured columns;
- projection with automatic inclusion of the stable primary key;
- deterministic multi-column ordering with explicit null placement;
- count, distinct count, sum, average, minimum, and maximum aggregates; and
- offset entry followed by forward-only signed keyset continuation.

Null comparison behavior matches the in-memory reference adapter. In
particular, null sorts before a non-null scalar for ordered predicates, negated
ranges include null when the positive range does not, and contains operations
compare against a text-coalesced empty value. SQL's native three-valued logic is
not allowed to change those public query semantics.

## Stable keyset cursors

Every sort receives the primary key as a final tie-breaker unless it is already
present. A cursor contains only the last row's scalar sort values, a logical
offset, issue and expiry timestamps, a query/security/schema fingerprint, and a
retained key identifier. The envelope is HMAC-SHA-256 authenticated and limited
to 4096 bytes.

The fingerprint binds:

- the normalized query excluding pagination;
- the effective typed authorization scope;
- tenant and trusted authority through the query fingerprint;
- the schema fingerprint;
- the PDO dialect; and
- deterministic sort and null-placement rules.

Changing a filter, projection, tenant, principal scope, schema, dialect, or
authorization decision invalidates the old cursor. Retain the previous signing
key until its maximum cursor TTL has elapsed, then remove it. Expiry is
inclusive: a cursor is invalid when `expires_at <= now`.

## Pagination, totals, and consistency

`count_total` defaults to true. Set it to false when the backing database or
surface should expose an unknown total; `PanelDataPage::total()` will then be
null. The adapter fetches one extra row to determine whether a next cursor is
available and never returns that sentinel row.

Count, aggregate, and data statements are separate. The adapter does not start,
commit, or roll back a transaction. A caller may place a PDO-backed source
inside its own transaction, but the public manifest conservatively declares
that the three statements are not inherently atomic or snapshot-consistent.
Forward keyset continuation is stable under inserts before the current window,
but it is not a historical snapshot.

## Deliberate limits

- Identifiers use an explicit schema allowlist; JSON paths, computed columns,
  arbitrary joins, raw expressions, and request-supplied tables are rejected.
- Includes are not materialized by the SQL adapter. Use typed relation filters
  or a resource relation workspace instead.
- Cursors are forward-only. Returning to an earlier page should restore a
  previously retained intent or issue a fresh query, not reverse an opaque
  cursor.
- One query may bind at most 2,000 parameters, contain at most 16 relation
  levels, and use the schema's configured `max_limit`.
- String comparison follows the database column collation after Panel's
  explicit null and pattern semantics. Applications that require a particular
  locale or case-folding policy must configure it at the database/schema level.
- The adapter is read-only. Mutations remain in authorized resource actions,
  operation handlers, workflows, or application repositories.

## Verification

The focused suite is
`runtime/modules/panel/unit_tests/dataphyre.panel_sql_datasource_scorched_earth.test.php`.
It runs compiler and fault-injection coverage on PHP 8.2 and 8.4 and executes a
real SQLite database when `pdo_sqlite` is available. The closed-world phpdbg
gate covers every executable line under
`runtime/modules/panel/Framework/Data/Sql` and requires the source inventory to
remain stable at 100 percent.
