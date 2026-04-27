# Dataphyre Flightdeck

Flightdeck is Dataphyre's non-production control plane. Normal requests do not load the module unless the request targets `/dataphyre`, a protected module interface page, a pre-init error renderer, or an already-enabled runtime-toolbar cookie.

Flightdeck also owns the visual shell for Dataphyre's browser-facing module tools. Module pages that need a control-plane UI should render through Flightdeck templates instead of shipping a separate page frame.

Flightdeck requires the Dataphyre `templating` module. That dependency is intentional: Flightdeck is the shared definition layer for console layouts, module pages, contracts, slots, and the runtime-toolbar presentation.

## Bootstrap Config

Configure Flightdeck from the Dataphyre flight sheet bootstrap block:

```php
'flightdeck'=>[
	'enabled'=>true,
	'password_hash'=>password_hash('developer-password', PASSWORD_DEFAULT),
	'session_ttl'=>43200,
	'rate_limit'=>[
		'window'=>300,
		'max_attempts'=>5,
	],
	'debugbar'=>[
		'enabled'=>true,
	],
],
```

If `IS_PRODUCTION === true`, Flightdeck and protected Dataphyre interface pages are disabled.

If Flightdeck is installed, a console password is required. Missing password configuration does not unlock control-panel access.

If Flightdeck is not installed and `IS_PRODUCTION === false`, module interface pages keep their previous development behavior.

DataDoc is an exception because the module explicitly requires Flightdeck; DataDoc browser routes return unavailable when Flightdeck is missing.

## Routes

- `/dataphyre` opens the Flightdeck dashboard.
- `/dataphyre/login` and `/dataphyre/logout` manage the bootstrap-password session cookie.
- `/dataphyre/logs` shows app/shared Dataphyre logs.
- `/dataphyre/modules` lists installed runtime modules and known interface pages.
- `/dataphyre/flight-sheet` shows a redacted flight sheet view.
- `/dataphyre/debugbar` controls the signed runtime-toolbar cookie.
- `/dataphyre/datadoc` opens the Flightdeck DataDoc workspace and project/application management surface.
- `/dataphyre/datadoc/{project}` opens a Flightdeck-rendered DataDoc project.
- `/dataphyre/datadoc/{project}/settings` opens Flightdeck-rendered project settings.
- `/dataphyre/datadoc/{project}/dynadoc` opens a Flightdeck-rendered dynamic code record.
- `/dataphyre/datadoc/{project}/manudoc/{document}` opens a Flightdeck-rendered manual document.
- `/dataphyre/tracelog` opens the Flightdeck Tracelog surface.
- `/dataphyre/tracelog/plotter` opens the Flightdeck-embedded Tracelog plotter.
- `/dataphyre/dpanel` opens the Flightdeck diagnostic surface.

Operational endpoints such as CASPOW routes are not treated as control-panel pages.

The Tracelog surface consumes the fresh `$_SESSION['tracelog']` buffer after rendering and falls back to `$_SESSION['flightdeck_last_tracelog']` on refresh. Tracelog writes that retained buffer during output-buffer processing, not only during shutdown, to avoid races between the traced page and the Flightdeck viewer popup. If session handoff misses because the viewer opens under a different PHP session id, Flightdeck can recover the exact trace from the signed `handoff` token on the Tracelog popup URL, fall back to Tracelog's session/auth-cookie keyed handoff cache, or finally show the newest retained handoff file for the authenticated console.

## Templated Surfaces

Flightdeck templates live in `modules/flightdeck/templates/`:

- `layout.tpl` defines the full Flightdeck shell.
- `module.tpl` defines the standard module landing page shape.

Those templates use Dataphyre templating slots such as `css`, `head`, `nav`, `sidebar_bottom`, `actions`, and `content`. Flightdeck passes slot content through the fourth `\dataphyre\templating::render(...)` argument; module surfaces should not interpolate shell HTML manually.

The shared renderer lives in `modules/flightdeck/kernel/view.php` as `dataphyre_flightdeck_view`.

Use it for module-owned tools:

```php
require_once(ROOTPATH['common_dataphyre_runtime'].'modules/flightdeck/kernel/view.php');

$content=dataphyre_flightdeck_view::card(
	'Runtime State',
	dataphyre_flightdeck_view::table(['Key', 'Value'], [
		['Status', dataphyre_flightdeck_view::badge('Ready', 'success')],
	])
);

echo dataphyre_flightdeck_view::module_page(
	'Example',
	'Example Runtime Tool',
	'A Dataphyre module surface rendered through Flightdeck.',
	$content,
	'modules'
);
```

Current Flightdeck-backed surfaces:

- DataDoc uses Flightdeck for project and application management, project summaries, settings, dynamic code records, manual documents, and synchronization actions. DataDoc indexing runs as bounded lazy batches with visible progress and continuation controls, not as one blocking browser request. DataDoc has no standalone login page; accidental direct login includes redirect to Flightdeck auth.
- Tracelog uses Flightdeck for session trace inspection and the D3 call-graph plotter.
- Dpanel uses Flightdeck for scoped diagnostic execution, summary metrics, and expandable diagnostic details.

Prefer this shape for new module UIs. The module owns the data and actions; Flightdeck owns the frame, navigation, cards, tables, buttons, badges, and control-plane copy.

## Logs

`/dataphyre/logs` uses a lazy live-tail reader instead of rendering the full log file with the page. The browser requests bounded batches, catches up quickly when backlog exists, and automatically pauses polling while the user is selecting text so copied stack traces are not disturbed.

Stack snippets are opt-in per log entry. Normal polling only does lightweight detection and adds a `Render Smart Snippets` button to applicable rows. Clicking that row-level button performs a separate authenticated AJAX render for that one entry, avoiding source-file reads and syntax/snippet work for the rest of the visible log stream.

Log stack snippets use the same reusable renderer as pre-init diagnostics: `modules/flightdeck/kernel/stack_snippets.php`. That renderer applies DataDoc syntax highlighting, PHP/DataDoc token links, de-indentation, and callsite line highlighting when the DataDoc highlighter is available. Log batches execute the embedded highlighter pass after AJAX insertion, so dynamically loaded snippets get the same line numbers and highlight treatment as full-page pre-init errors.

When a returned log entry includes a recognizable PHP failure, the shared renderer can also add smart diagnostics before the snippets. Current checks cover actual include/require open failures, readability and directory permissions; typed argument mismatches with callsite argument extraction; undefined variables compared against nearby variables in the source scope; and undefined functions, methods, properties, constants, and classes compared against loaded runtime symbols.

## Pre-init Errors

When production is false and Flightdeck is installed, `pre_init_error()` can render a richer authenticated error page with:

- exception class, message, file, and line
- request/runtime context
- code snippets for stack frames
- DataDoc highlighting and token links when the DataDoc highlighter is installed
- DataDoc record/project links when a stack frame maps to an indexed DataDoc project
- call-stack navigation between related snippets, including caller/callee links and callsite highlighting
- snippet de-indentation so partial extracts from broader brace scopes remain readable
- smart diagnostics for include/require target checks and likely undefined symbol typos
- retroactive tracelog entries

Each stack snippet gets a stable `fd-frame-N` anchor. The origin frame points at the thrown error location, while later frames point at the callsite that referenced the next frame inward. When DataDoc highlighting is available, Flightdeck passes explicit line-number and callsite-highlight metadata into the highlighter instead of post-processing rendered code.

Unauthenticated users see a Flightdeck login gate instead of traces.

## Runtime Toolbar

The runtime toolbar has no module-load overhead by default. The output buffer only attempts to load Flightdeck toolbar code when the signed `dataphyre_flightdeck_debugbar` cookie is present and production is false.
