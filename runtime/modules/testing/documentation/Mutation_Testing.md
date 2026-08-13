# First-party mutation testing

Line coverage proves that code executed. Mutation testing asks the stronger
question: would the selected contract fail if that behavior changed?

Dataphyre ships a runtime-inert native engine. It uses PHP's tokenizer, so
operator-looking text in strings and comments is never mutated. Each candidate
has a stable content-derived ID, source line, named operator, original token,
and replacement token.

## Plan before executing

```console
php bin/dataphyre-mutate operators
php bin/dataphyre-mutate plan --path=runtime/modules/http --profile=http-contract
php bin/dataphyre-mutate plan --path=runtime/modules/panel/Framework/Http --limit=20 --report=artifacts/panel-mutation-plan.json
```

The built-in profiles communicate intent rather than hiding a bag of operators:

- `http-contract` pressures identity, fallback, and boolean transport choices;
- `route-contract` also pressures branch composition and boundaries;
- `permission-contract` pressures allow/deny boolean composition;
- `data-contract` pressures comparisons, boundaries, and null fallback;
- `core` enables every first-party operator.

Individual operators can be selected with `--operator`. Repeating `--path` or
`--operator` composes the selection; comma-separated values are equivalent.
`run --dry-run` emits the same plan without changing source or starting tests.

## Execute mutants

```console
php bin/dataphyre-mutate run \
  --path=runtime/modules/http/Framework/Request.php \
  --profile=http-contract \
  --timeout=90 \
  --report=artifacts/http-mutation.json
```

The engine first verifies that every affected module's baseline is green. It
then applies one mutant at a time, validates PHP syntax, and delegates to
`bin/dataphyre-test` with the owning module selected. A failing test kills the
mutant; a green run means the mutant survived and the command exits non-zero.
Timeouts and engine errors are reported separately from killed mutants.

Use `--test-name` to narrow an exploratory run only when the named contract is
known to own the source. The default owner selection is safer for release work.
`--skip-baseline` exists for deliberate diagnostics; it removes the green-suite
safety gate and should not be used for release measurement.

When the parent command runs under phpdbg for coverage, lint and test children
resolve to the sibling ordinary PHP executable. Mutation execution therefore
keeps normal PHP behavior and does not multiply debugger overhead.

## Source safety

Mutation execution takes an exclusive repository lock. Before changing a source
file it writes a checksummed recovery journal containing the exact original
bytes. Restoration happens in a `finally` block. If the PHP process or machine
is interrupted, the next mutation run restores the journal before doing any
work; it refuses to overwrite a file that changed independently.

Recovery can also be requested explicitly:

```console
php bin/dataphyre-mutate recover
```

The journal is operational state under `cache/mutation/`; it must not be
committed. A successful run leaves source bytes identical to their pre-run hash.

## Reading the report

`dataphyre-mutation-report-v1` contains exact counts for `killed`, `survived`,
`timeout`, `invalid`, and `error`, plus each mutant's stable identity and timing.
The mutation score is `killed / (killed + survived)`. Invalid or timed-out
mutants are never silently counted as killed.

Every successful command emits JSON to standard output. `--report=path` writes
the same document to a file, creating its parent directory when needed.
`--json` is an explicit compatibility marker; JSON is already the native
output. Unknown option names and unexpected positional arguments are rejected
rather than ignored.

CLI exit codes are stable:

- `0` — help, planning, operator listing, or recovery succeeded; a mutation run
  also returns zero when it has no survived, timed-out, or errored mutant;
- `1` — at least one mutant survived, timed out, or produced a per-mutant
  `error` result;
- `2` — usage was invalid, the baseline was red, or the command itself failed.

Syntax-invalid mutants are reported as `invalid`; they are not actionable
survivors and do not alone change a run's exit code.

A surviving mutant is a test-design finding, not automatically a production
bug. Either add the missing observable contract or document why that operator
does not represent meaningful behavior and narrow the profile. Do not add an
assertion that merely mirrors the implementation to improve the score.
