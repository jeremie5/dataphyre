# TestKit semantic contracts

TestKit cases can describe both the behavior they prove and the worker boundary
needed to prove it. The metadata is emitted by `Registry::caseSummaries()` and
every case result, so selection, mutation, reporting, and CI do not need to
infer intent from filenames or tags.

## A self-describing case

```php
test('stale query identity cannot replace a pretty bulk route', function(Context $t): void {
    $t->panel()
        ->givenReviewOrder()
        ->underAmbientQuery(operation: 'index', record: 'stale')
        ->approveSelected()
        ->assertRequestOperation('bulk_transition');
})
    ->id('panel.bulk-transition.stale-query')
    ->contract('panel.bulk-transition', 2)
    ->layer(TestLayer::Browser)
    ->risk(TestRisk::Critical)
    ->watches(
        'symbol:PanelRouteParser::infer',
        'route:panel.bulk-transition',
        'asset:panel.ajax-runtime'
    )
    ->through('renderer', 'route', 'request', 'dispatcher', 'browser')
    ->isolation(TestIsolation::File)
    ->strictIssues()
    ->forbidsOutput()
    ->requiresAssertions();
```

`id()` is the durable address used by histories and impact graphs. Without an
explicit ID, TestKit derives one from the module, suite, case name, and contract;
line numbers and dataset order are deliberately excluded. Every dataset and
repeat receives a deterministic suffix, so expanded cases are independently
addressable.

`contract(name, version)` names the capability. `layer()` accepts `unit`,
`integration`, `contract`, `feature`, `browser`, `architecture`, `performance`,
`security`, or `system`. `risk()` accepts `low`, `medium`, `high`, or `critical`.
`watches()` contains explicit source, route, configuration, fixture, asset, or
contract dependencies. Prefixes are descriptive conventions rather than a
closed vocabulary.

`through()` records the ordered observable boundaries crossed by a contract.
It lets topology reports distinguish a parser unit proof from a renderer to
route to dispatcher to browser journey even when both watch the same symbols.

`isolation()` accepts `case`, `file`, `process`, or `shared`. `repeat(n)` expands
the case without hiding repeated attempts inside the body. File workers call
`Registry::runMany($indexes, $file)`: `before_all` and `after_all` run once,
while fixtures and before/after-each hooks remain per case.

Summaries include `isolation_explicit`. The default remains the conservative
`case`, but its explicitness is false; a runner may speculatively batch that
file and fall back when its leak sentinel finds drift. Deliberate `case` or
`process` declarations are hard boundaries and must never be auto-batched.

## Strict lifecycle declarations

Policies are opt-in for existing tests and enforceable for migrated suites:

- `strictIssues()` fails on any observed PHP warning, notice, or deprecation.
- `allowsIssues(E_USER_DEPRECATED)` allows only the declared issue types.
- `expectsIssues(E_USER_NOTICE)` requires every declaration and rejects others.
- `forbidsOutput()` fails when the case or its lifecycle emits output.
- `allowsOutput('reason')` documents intentional output.
- `expectsOutput('reason')` requires an observable output contract.
- `requiresAssertions()` rejects accidental zero-assertion cases.
- `allowsNoAssertions('reason')` explicitly identifies a smoke/exit contract.

Suites expose the same methods as case defaults. A case can then override a
default where its boundary genuinely differs.

## Module-owned testing kits

A module can ship a runtime-inert DSL beside its own tests and register it when
that tooling file is required:

```php
Context::extend('panel', static fn(\Dataphyre\Test\Contracts\TestContext $t): PanelTestKit => new PanelTestKit($t));

$panel=$t->extension('panel', PanelTestKit::class); // explicit and type-checked
$t->panel()->givenReviewOrder();                   // zero-argument DSL alias
```

Factories are lazy and cached once per `Context`. `hasExtension()` and
`forgetExtension()` support optional tooling and isolated tests. A registered
name never intercepts an existing Context method; the explicit `extension()`
form is always available.

Predicate-backed expectations can also be registered without editing TestKit:

```php
Expectation::extend(
    'toBeRouteOperation',
    static fn(mixed $actual, string $expected): bool =>
        $actual===$expected && str_contains($actual, '_')
);

$t->expect('bulk_update')->toBeRouteOperation('bulk_update');
$t->expect('index')->not()->toBeRouteOperation('bulk_update');
```

## Deterministic properties

`DeterministicRandom` is a hash-counter stream. It never calls `mt_srand()` and
therefore cannot perturb application or neighboring-test randomness. `fork()`
creates independent named streams. Compositions fork by case, field, and list
index, so adding an unrelated field does not change existing generated values.

```php
$routes=Generators::shape([
    'operation'=>Generators::element(['bulk_update', 'bulk_transition']),
    'record'=>Generators::nullable(Generators::integer(1, 10_000)),
    'tags'=>Generators::listOf(Generators::string(1, 12), 0, 4),
    'confirmed'=>Generators::boolean(),
]);

$t->fuzz(
    Generators::cases($routes, count: 200, seed: 20260714, kind: 'panel-route'),
    function(Context $t, array $route): void {
        // Assert an invariant.
    }
);
```

`Arbitrary` also supports `map()`, bounded `filter()`, `named()`, `shape()`,
`tupleOf()`, `listOf()`, and `nullable()`. Legacy `integers()`, `strings()`,
`oneOf()`, `fuzzIntegers()`, and `fuzzStrings()` remain compatible, now using
independent deterministic streams.

On failure, shrinking restarts from every newly discovered failing candidate
until it reaches a fixed point or the candidate budget. `ShrinkResult` records
the original, minimal value, path, candidate count, and whether the fixed point
was proven.

Replay tokens use the `dpt1` schema with a checksum, generator kind, and
generator fingerprint. `validateReplayToken()` rejects corruption or replay
against the wrong generator. `DATAPHYRE_FUZZ_REPLAY` is strict; invalid tokens
fail instead of silently running a new random sample. Valid legacy base64 tokens
remain readable.

## Failure corpus

`FailureCorpus` stores deduplicated replay tokens by contract:

```php
$corpus=FailureCorpus::open($t->workspace()->path('corpora/panel.json'));
$id=$corpus->record('panel.bulk-transition', $cases, $label, $minimal, [
    'boundary'=>'route-parser',
]);

foreach($corpus->replay('panel.bulk-transition', $cases) as $case){
    // Promoted regression or pre-random corpus lane.
}
```

Set `DATAPHYRE_FUZZ_CORPUS` to make `Context::fuzz()` automatically persist the
minimized replay token. Corpus entries count recurrences while retaining their
stable ID and metadata. A failure report includes the seed, generator
fingerprint, original token, minimized token, shrink path, and corpus ID.

The corpus contains generated test inputs only. Do not write credentials,
customer records, or other sensitive production data into it.
