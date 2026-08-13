# Dataphyre Panel static analysis

Panel's fluent builders expose PHPStan-, Psalm-, and IDE-readable generic contracts without requiring either analyzer in production. The contracts preserve the application record, field value, form state, related record, search result, and cursor types across clone-on-write builder calls and callback boundaries.

Every typed fluent clone returns the same concrete template tuple (or an explicitly refined tuple), so a type established at a factory boundary does not silently collapse back to `mixed` later in a chain.

The checked source contract lives in `runtime/modules/panel/static-analysis/panel-builder-contract.json`. It is extracted directly from PHP source with `token_get_all()`, so verification does not boot Panel, Composer, a database, or an HTTP surface.

## Builder type parameters

| Builder | Type parameters | Meaning |
| --- | --- | --- |
| `Resource<TRecord,TState>` | record, form state | Model or record payload and normalized form state |
| `ResourceForm<TRecord,TState>` | record, form state | Fields and sections bound to a resource |
| `ResourceTable<TRecord,TState>` | record, state | Columns, filters, views, summaries, and groups |
| `RelationManager<TParentRecord,TRelatedRecord,TState>` | parent, related, state | Parent context and related rows |
| `Field<TRecord,TValue,TState>` | record, value, state | Current record, field value, and sibling values |
| `FormSection<TRecord,TState>` | record, state | Section descriptor carried by a typed form/schema |
| `Schema<TRecord,TState>` | record, state | Typed component tree and lifecycle state |
| `SchemaComponent<TRecord,TValue,TState>` | record, value, state | Typed field or structural schema node |
| `SchemaLifecycle<TRecord,TState>` | record, state | Hydration, validation, and dehydration runtime |
| `Infolist<TRecord,TState>` | record, state | Read-only record schema |
| `InfolistEntry<TRecord,TValue,TState>` | record, value, state | Read-only field value |
| `Column<TRecord,TValue>` | record, cell value | Row and resolved cell value |
| `PageTable<TRecord,TState>` | record, state | Page-owned table rows and table configuration |
| `TableFilter<TRecord,TValue,TState>` | record, filter value, state | Active value and predicate context |
| `TableGroup<TRecord,TKey>` | record, group key | Row and resolved group identity |
| `TableView<TRecord,TState>` | record, state | View predicate and badge context |
| `TableSummary<TRecord,TValue>` | record, aggregate value | Visible rows and aggregate result |
| `Widget<TRecord,TValue,TState>` | record, value, state | Widget value and resolved data |
| `PanelSearchProvider<TResult,TCursor>` | raw result, cursor | Provider adapter result and cursor payload |
| `PanelPage<TContent,TRecord,TState>` | rendered content, record, state | Page content plus its page-owned tables and widgets |
| Navigation and command builders | request and manager callback context | Typed URLs, badges, current-state, and visibility resolvers |

Generic parameters have backward-compatible defaults. Existing unannotated applications continue to see `mixed` records and values plus `array<string,mixed>` state. Applications gain strict inference incrementally by annotating their builder entry points or by using refining methods such as `Resource::model()`, `Field::default()`, `Field::hydrateUsing()`, `Column::valueUsing()`, `PageTable::records()`, `RelationManager::queryUsing()`, `TableGroup::stateUsing()`, `TableSummary::valueUsing()`, `Widget::value()`, and `PanelSearchProvider::searchUsing()`.

## A typed resource

```php
<?php

use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Panel\ResourceTable;

final class Order
{
    public function __construct(
        public int $id,
        public string $email,
        public string $status,
    ) {}
}

/**
 * PHPStan and Psalm both understand this local state shape.
 *
 * @var Field<Order,string,array{email:string,status:string}> $email
 */
$email = Field::make('email')
    ->hydrateUsing(static fn(string $value, ?Order $record): string => trim($value))
    ->validateUsing(
        static fn(string $value, array $state, ?Order $record): ?string =>
            filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email.',
    );

/** @var Column<Order,string> $emailColumn */
$emailColumn = Column::make('email')
    ->valueUsing(static fn(Order $order): string => $order->email)
    ->format(static fn(string $value, Order $order): string => strtolower($value));

/** @var ResourceForm<Order,array{email:string,status:string}> $form */
$form = ResourceForm::make()->field($email);

/** @var ResourceTable<Order,array{email:string,status:string}> $table */
$table = ResourceTable::make()->column($emailColumn);

/** @var Resource<Order,array{email:string,status:string}> $orders */
$orders = Resource::make('orders')
    ->model(Order::class)
    ->form($form)
    ->resourceTable($table)
    ->recordTitleUsing(static fn(Order $order): string => $order->email);
```

The inline `@var` declarations are useful at boundaries where a builder factory has no typed input from which an analyzer could infer the application record or state. Once established, the type flows through child builders, lifecycle callbacks, getters, relations, table records, and resolvers.

## Callback contracts

Panel callback PHPDoc follows the runtime argument order and marks trailing utility-resolver arguments as optional. A callback may declare only the leading arguments it needs. Common shapes include:

```php
// Field hydration and dehydration.
callable(TValue, ?TRecord=, ?PanelRequest=, Field<TRecord,TValue,TState>=): TValue

// Field validation.
callable(TValue, TState=, ?TRecord=, ?PanelRequest=, Field<TRecord,TValue,TState>=): bool|string|list<string>|null

// Column value resolution.
callable(TRecord, Column<TRecord,TValue>=): TValue

// Filter predicate.
callable(TRecord, ?TValue, PanelRequest=, TableFilter<TRecord,TValue,TState>=): bool

// Relation query factory.
callable(TParentRecord, Resource<TParentRecord,TState>=, ?PanelRequest=, RelationManager<TParentRecord,TRelatedRecord,TState>=): iterable<TRelatedRecord>|object|null

// Search provider.
callable(string, PanelRequest, PanelSearchProvider<TResult,TCursor>, int, ?PanelManager, PanelSearchContext): iterable<TResult>|PanelSearchPage|array<string,mixed>

// Page rendering.
callable(PanelRequest, PanelPage<TContent,TRecord,TState>=, ?PanelManager=): TContent

// Navigation and command visibility.
callable(?PanelRequest, NavigationItem|PanelCommand|PanelMenuItem|PanelTenant, ?PanelManager): bool
```

Resolver return types are intentionally bounded where Panel normalizes the result. Search provider rows, for example, are `PanelSearchResult` objects or `array<string,mixed>` payloads; cursors are strings, string-keyed arrays, or null. Unsupported asynchronous/future objects remain runtime errors instead of being hidden behind `mixed`.

## Relations and tables

```php
/** @var RelationManager<Order,LineItem,array{sku:string}> $items */
$items = RelationManager::make('items')->queryUsing(
    static fn(Order $order): iterable => $lineRepository->forOrder($order->id),
);

/** @var TableGroup<Order,string> $status */
$status = TableGroup::make('status')->stateUsing(
    static fn(Order $order): string => $order->status,
);

/** @var TableSummary<Order,int> $count */
$count = TableSummary::make('count')->valueUsing(
    static fn(array $orders): int => count($orders),
);

/** @var PageTable<Order,array<string,mixed>> $table */
$table = PageTable::make('orders')->recordsUsing(
    static fn(): iterable => $orderRepository->recent(),
);
```

For heterogeneous field or column collections, type each element before adding it to the parent builder. The parent intentionally stores mixed value types while retaining the shared record and state types.

## Search providers

```php
/** @var PanelSearchProvider<array{id:int,title:string},string|null> $provider */
$provider = PanelSearchProvider::make('orders')
    ->searchUsing(
        static fn(
            string $query,
            PanelRequest $request,
            PanelSearchProvider $provider,
            int $limit,
            ?PanelManager $manager,
            PanelSearchContext $context,
        ): iterable => $index->search($query, $limit, $context->cursor()),
    )
    ->scoreUsing(
        static fn(PanelSearchResult $result, string $query): float =>
            $ranking->score($result, $query),
    )
    ->deduplicateUsing(
        static fn(PanelSearchResult $result): string => $result->url(),
    );
```

`searchUsing()` and its `pageUsing()` alias refine the provider result type from the returned iterable. Explicit `@var` remains useful when a repository abstraction exposes only `iterable` instead of `iterable<SpecificShape>`.

## Instance extension registries

Configurator and macro registration preserves the concrete builder input:

```php
Field::configureUsing(
    static fn(Field $field): Field => $field->meta(['source' => 'application']),
);

$registry->runAs(
    'orders-package',
    ['extensible.macro.register'],
    ['version' => 1],
    static function () use ($registry): void {
        $registry->registerMacro(
            Field::class,
            'tenantDefault',
            static fn(Field $field): Field => $field->default('CA'),
        );
    },
);
```

Dynamic method names cannot be discovered from runtime registration alone. Generate analyzer stubs for package macros instead of weakening the builder to `mixed`.

## Macro stub generation

Start from `runtime/modules/panel/static-analysis/panel-macros.example.json`:

```json
{
  "schema_version": 1,
  "classes": {
    "Dataphyre\\Panel\\Field": {
      "templates": [
        "@template TRecord",
        "@template TValue",
        "@template TState"
      ],
      "methods": {
        "currencyLabel": {
          "return": "self<TRecord,TValue,TState>",
          "parameters": [
            {"name": "currency", "type": "non-empty-string"}
          ]
        }
      }
    }
  }
}
```

Generate a deterministic stub:

```shell
php dev/tools/panel_static_analysis.php generate-stubs \
  --manifest=runtime/modules/panel/static-analysis/panel-macros.example.json \
  --output=cache/panel-macros.stub.php
```

Add that file to PHPStan's `stubFiles` or Psalm's project files. Class names, method names, PHPDoc types, template declarations, defaults, references, variadics, and line-breaking input are validated before output. Equal manifests always produce byte-identical stubs.

## PHPStan

The repository ships `runtime/modules/panel/static-analysis/phpstan.neon.dist` as a maximum-level compile fixture. With PHPStan available as a development tool:

```shell
phpstan analyse -c runtime/modules/panel/static-analysis/phpstan.neon.dist
```

That configuration analyzes the compile fixture and loads `Framework` through `scanDirectories`, proving the real builder declarations while keeping the assertion surface intentionally small.

An application can include Panel's source and its generated macro stub:

```neon
parameters:
    level: max
    scanDirectories:
        - vendor/dataphyre/dataphyre/runtime/modules/panel/Framework
    stubFiles:
        - cache/panel-macros.stub.php
```

PHPStan is not a Dataphyre runtime dependency. Pin it in the consuming application's development dependencies or CI image.

## Psalm

The repository ships `runtime/modules/panel/static-analysis/psalm.xml.dist`:

```shell
psalm --config=runtime/modules/panel/static-analysis/psalm.xml.dist
```

Psalm analyzes the same fixture and loads `Framework` as `extraFiles`. This makes all production declarations available for inference without turning unrelated legacy Framework diagnostics into fixture failures.

Generated macro stubs can be added as a `<file>` entry under `<projectFiles>`. Psalm is likewise a development-only consumer.

## Analyzer-free CI contract

Run the first-party gate on every supported PHP runtime:

```shell
php dev/tools/panel_static_analysis.php check
php bin/dataphyre-test run \
  --scope=framework \
  --kind=code \
  --path=dataphyre.panel.static_analysis_contract.test.php
```

The gate verifies:

- the exact 28-target builder, page, navigation, and registry inventory;
- class templates, typed properties, callback shapes, and generic method tags;
- balanced PHPDoc delimiters;
- absence of unshaped public callback parameters;
- absence of `mixed` unions that erase a useful type;
- absence of punctuation attached to PHPDoc types and corrupted generator fragments;
- required PHPStan, Psalm, fixture, and macro-manifest support files.

When a deliberate public type contract changes, inspect the diff and regenerate the checked JSON:

```shell
php dev/tools/panel_static_analysis.php update-contract
```

Do not regenerate merely to silence an unexpected diff. The contract is review evidence: a callback or generic propagation change should be intentional, documented, and covered by the compile fixture.

## Migration from untyped builders

1. Add a record class or array shape to each `Resource` boundary.
2. Add one state shape shared by the resource form, schema, lifecycle, filters, and relations.
3. Type each `Field` value and each `Column` value independently.
4. Replace callbacks documented only as `callable` in application wrappers with the Panel callback shape.
5. Add related-record types to each `RelationManager`.
6. Add result and cursor types to search providers.
7. Describe package macros in a manifest and generate stubs.
8. Enable the compile fixture at the analyzer's strongest level before widening analysis to application code.

No runtime migration is required. Generic annotations do not change serialized manifests, callback invocation order, clone-on-write behavior, or PHP 8.2/8.4 compatibility.

## Troubleshooting

### The analyzer reports `mixed` after a factory call

Factories such as `Field::make('email')` cannot infer an application record or state from a string name. Add an inline `@var Field<Order,string,OrderState>` at that boundary, or pass the field into an already typed form/resource context.

### A closure receives fewer arguments than the documented callback

Trailing resolver arguments are optional. Declare only the leading arguments the closure uses, while preserving their order and types.

### A collection rejects differently typed fields

The form retains one record/state type but deliberately stores fields with mixed value types. Type the individual field variables, then add them to the typed parent. Do not replace the parent record or state with `mixed` to accommodate heterogeneous values.

### A runtime macro is still unknown

Runtime registration proves that a method exists only after application boot. Static analyzers need the generated stub because they never execute the registry.

### The checked contract drifted after an edit

Run `php dev/tools/panel_static_analysis.php dump-contract` and compare the affected class. Fix accidental PHPDoc drift; for an intentional API change, update the fixture and documentation before regenerating the contract.
