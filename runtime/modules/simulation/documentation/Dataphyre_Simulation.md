# Dataphyre Simulation

Dataphyre Simulation is a deterministic causal runtime for making development
and demonstration environments behave like living systems. The framework owns
time, replayable randomness, bounded rule execution, causal intent chains,
scope isolation, observation perspectives, optimistic state, and a fail-closed
environment policy. Applications own every business meaning and mutation.

It is deliberately not a fake-row generator. A domain adapter receives an
idempotent intent and applies it through the application's normal services and
repositories. This keeps application invariants, projections, audits, queues,
and downstream side effects representative of the real system.

## Safety contract

- The module is disabled by default.
- `production`, `prod`, and `live` are denied even if accidentally listed as
  allowed environments.
- Applications must provide an explicit state store.
- Each tick is bounded by event, pending-intent, and journal limits.
- The framework journal records intent evidence and safe adapter summaries; it
  never records application payloads.
- A `SimulationPerspective` blocks origins controlled by the surface being
  observed. A KDS can therefore receive simulated customer and driver pressure
  without the simulator accepting, preparing, or completing its tickets.
- Scope is immutable, canonicalized, and included in state identity. Domain
  adapters must still enforce their own tenant and resource boundaries.

## Application integration

Enable `simulation` in the application's flight sheet and configure it in
`backend/dataphyre/config/simulation.php`. Keep production disabled.

Register one adapter and one or more scenarios:

```php
use Dataphyre\Simulation\Simulation;
use Dataphyre\Simulation\SimulationRule;
use Dataphyre\Simulation\SimulationScenario;

Simulation::useStore($applicationSqlStateStore);
Simulation::register('restaurant', $restaurantAdapter, [
    new SimulationScenario('rush', 'Rush', '', [
        'time_scale'=>['type'=>'number', 'default'=>1.0, 'min'=>0.05, 'max'=>100],
        'intensity'=>['type'=>'number', 'default'=>1.0, 'min'=>0, 'max'=>10],
    ], [
        SimulationRule::every(
            'customer.order',
            'restaurant.order.received',
            'customer',
            ['kds', 'dispatch'],
            20,
            $orderPlanner,
        ),
    ]),
]);
```

The application can call `configure()`, `status()`, and `tick()` on
`Simulation::runtime()`. Observation-driven ticks are useful for live surfaces:
polling a KDS can advance only the causal events relevant to that KDS scope.
A scheduled worker may use the same runtime when simulation must continue while
no surface is open.

## Domain adapter rules

`snapshot()` returns the minimum state needed by planners. `apply()` should:

1. verify tenant and scope again;
2. treat the intent ID as an idempotency key;
3. call normal application services rather than inserting fabricated rows;
4. return a payload-free summary suitable for developer diagnostics; and
5. return delayed follow-up intents when one event causes another.

Control schemas are application-defined and normalized by the scenario. This
lets a restaurant expose order cadence and driver pressure while another domain
defines machines, shipments, appointments, or market traffic without changing
Dataphyre itself.

## Testing

The module's unit suite covers deterministic replay, control bounds, scope and
perspective safety, causal follow-ups, payload-free journals, optimistic state,
adapter failures, and the unconditional production denial:

```bash
php bin/dataphyre-test run --path=runtime/modules/simulation/unit_tests
```
