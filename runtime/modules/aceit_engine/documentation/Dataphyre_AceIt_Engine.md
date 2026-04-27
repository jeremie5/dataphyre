# Dataphyre AceIt Engine

## Status

`aceit_engine` is a legacy experimentation module. It predates the current
module discovery convention and should be treated as compatibility code until it
is modernized.

## Runtime Shape

The current entrypoint is:

```text
runtime/modules/aceit_engine/aceit_engine.main.php
```

Unlike modern modules, it does not currently use:

```text
runtime/modules/aceit_engine/kernel/aceit_engine.main.php
```

## Dependencies

The module requires `sql` through:

```php
dp_module_required('sql', 'aceit_engine');
```

## Purpose

AceIt Engine provides A/B or experience experiment helpers:

- define experiments
- assign sessions to experiment groups
- record experiment events
- metricize outcomes
- aggregate experiment results
- chart experiment outcomes from SQL data

## API Surface

```php
\dataphyre\aceit_engine::define_experiment(...);
\dataphyre\aceit_engine::get_group('experiment_name');
\dataphyre\aceit_engine::event('event_name', $value, 'experiment_name');
\dataphyre\aceit_engine::metricize('experiment_name');
\dataphyre\aceit_engine::aggregate_experiment('experiment_name');
\dataphyre\aceit_engine::chart_experiment('experiment_name', $group, $parameters);
```

## Public Release Notes

- The module needs a discovery-shape cleanup before it should be presented as a
  stable public module.
- The SQL schema contract should be documented before production use outside an
  existing Dataphyre installation.
- The source contains an inline example in the module entrypoint.
