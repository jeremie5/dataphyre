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
		'memory_limit'=>'128M',
		'asset_roots'=>[
			'/var/www/app/public',
		],
	],
],
```

If `IS_PRODUCTION === true`, Flightdeck and protected Dataphyre interface pages are disabled.

If Flightdeck is installed, a console password is required. Missing password configuration does not unlock control-panel access.

If Flightdeck is not installed and `IS_PRODUCTION === false`, module interface pages keep their previous development behavior.

DataDoc is an exception because the module explicitly requires Flightdeck; DataDoc browser routes return unavailable when Flightdeck is missing.

## Pre-Init Diagnostic Login

The pre-init diagnostic gate posts password submissions to `/dataphyre/login` with the failing request URI in the `return` query parameter. This keeps diagnostic authentication inside Flightdeck even when the failing application route is a Dataphyre MVC route with its own CSRF middleware. The gate still uses Flightdeck's stateless `csrf` token, and a successful login redirects back to the original request so the authenticated diagnostic report can render.

The normal Flightdeck login form also posts explicitly to `/dataphyre/login` with a safe local `return` query. Password submits stay in Flightdeck's stateless CSRF scope instead of relying on the current browser path or an application route's MVC session token.

Flightdeck redirects set an explicit HTTP 302 status before emitting `Location`. MVC adapters that mount Flightdeck by requiring `kernel/flightdeck.php` preserve the buffered body, response status, `Location`, and `Set-Cookie` headers so password submits, logout, and debugbar actions behave like normal browser redirects instead of empty 200 responses.

## Routes

- `/dataphyre` opens the Flightdeck dashboard.
- `/dataphyre/login` and `/dataphyre/logout` manage the bootstrap-password session cookie.
- `/dataphyre/logs` shows app/shared Dataphyre logs.
- `/dataphyre/modules` lists installed runtime modules and known interface pages.
- `/dataphyre/flight-sheet` shows a redacted flight sheet view.
- `/dataphyre/debugbar` controls the signed runtime-toolbar cookie and shows retained request snapshots captured by the toolbar.
- `/dataphyre/datadoc` opens the Flightdeck DataDoc workspace and project/application management surface.
- `/dataphyre/datadoc/{project}` opens a Flightdeck-rendered DataDoc project.
- `/dataphyre/datadoc/{project}/settings` opens Flightdeck-rendered project settings.
- `/dataphyre/datadoc/{project}/dynadoc` opens a Flightdeck-rendered dynamic code record.
- `/dataphyre/datadoc/{project}/manudoc/{document}` opens a Flightdeck-rendered manual document.
- `/dataphyre/tracelog` opens the Flightdeck Tracelog surface.
- `/dataphyre/tracelog/plotter` opens the Flightdeck-embedded Tracelog plotter.
- `/dataphyre/dpanel` opens the Flightdeck diagnostic surface.
- `/dataphyre/panel` opens the Panel resource lifecycle inspector when the Panel
  module is installed.
- `/dataphyre/reactor` opens the Reactor component manifest and lifecycle
  inspector when the Reactor module is installed.

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
- Panel uses Flightdeck for resource manifests and retained lifecycle trace
  inspection across generated forms, saves, actions, relations, notifications,
  and redirects.
- Reactor uses Flightdeck for component manifests, client-binding visibility,
  validation/action/effect traces, and route-agnostic reactive diagnostics.

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

When the signed toolbar cookie is present, Flightdeck starts its request collector before the application boots. That early collector attaches to Dataphyre SQL as soon as the SQL module is loaded, so the toolbar can show the request shape instead of only a late response summary.

If the application normally runs with a small PHP `memory_limit`, set `flightdeck.debugbar.memory_limit` to a higher value such as `128M` or `256M`. Flightdeck applies this only after the signed debugbar cookie is authenticated and the toolbar is enabled, and it only raises the active limit; it does not lower a larger or unlimited server limit.

The toolbar surfaces the active runtime state:

- request duration, memory, included file count, loaded Dataphyre modules, app, request id, run mode, and production state
- an open-by-default triage view that ranks the strongest request leads, shows next checks, and breaks server, SQL, first byte, DOM, and browser load timing into one compact read
- per-route snapshot comparison against the previous retained capture, including status, server time, SQL volume/time, findings, browser events/load, missing assets, API failures, memory, and response-size deltas
- an in-page toolbar shell that can collapse or expand, dock to the top or bottom of the viewport, switch into a full-viewport inspector, cycle between compact, normal, and tall layouts, drag-resize to a custom height, focus the active panel, keep the active panel highlighted while scrolling, filter panels by text for the current request, jump directly between major panels, and open or close all panels while keeping shell preference in browser-local storage
- SQL executions, queued SQL pushes, and queue flushes from Dataphyre SQL, including DBMS, cluster, target table, queue name, cache names, invalidation names, result status, measured duration, result size, reconstructed statement text, sanitized bound variables, origin callsite, highlighted source stack snippets, target heatmaps, operation mixes, cache maps, and derived insights for likely repeated lookups, template binding loops, hot targets, cache miss pressure, and read/write cache-order risks
- SQL cache activity, including hits, misses, stores, table invalidations, named-index invalidations, cache type, reason, and affected entry count when available
- slow query ranking and repeated query-shape detection for the current request; repeated shapes ignore bind values so N+1-style loops are visible even when every query uses a different id
- a range-aware request waterfall that normalizes request start, route match, SQL execution start/end ranges, queued SQL wait time, queue flushes, cache events, response readiness, browser navigation timing, async fetch/XHR work, resource timing, and client-side failures into one chronological view
- signed production-mode request replay for safe GET/HEAD pages; the browser performs one same-page replay with `IS_PRODUCTION === true`, Flightdeck markup disabled, debug logging suppressed, Dataphyre SQL/cache mutations blocked, and replay memory reported as app memory with retained debug/session logging payloads excluded so the snapshot can compare against a production-like read-only baseline
- derived diagnostics that call out failed responses, PHP warnings/errors, failed or slow SQL, repeated query shapes, high query counts, missing route matches, response-body issues, memory pressure, timeline trimming, and Tracelog capture; Triage leads include clickable references to the relevant evidence panels in both the live overlay and retained snapshot view
- toolbar-time PHP error capture that chains to the previous PHP error handler and shows highlighted source stack snippets through the shared Flightdeck renderer; fatal shutdown errors are recorded into request history when the session is still available
- a dedicated Tracelog panel that shows the full current request buffer, preserves Tracelog's per-function color spans, includes a clean plain-text fallback, lists pre-module retroactive rows and retained session fallback rows, automatically enables Tracelog plotting while the signed toolbar is active, renders a compact call graph in the toolbar, and links to the full Flightdeck Tracelog viewer and plotter
- a Reactor panel that appears when Reactor was active in the request, showing
  reactive component shapes, capabilities, bindings, validation/action/effect
  lifecycle events, diagnostics, and timeline markers
- templating-linked SQL, including render trace id, binding trace id, template name, binding name, binding path, query target, and query mode when a template binding performs SQL work
- routing details, including request path, normalized request, HTTP method, matched route, target file, selected page, route bindings, non-match count, configured not-found target, and verbose match notes
- request details, including response status, method, scheme, host, path, query string, client IP, user agent, request headers, response headers, query parameters, body parameters, cookie names, and a bounded session snapshot
- response audit details from the response buffer before toolbar handling, including HTML body size, title, charset, document shell counts, asset URLs, local asset resolution status, suspicious asset paths, duplicate HTML ids, JSON shape, Dataphyre-style API batch route summaries, API failure markers, mojibake-like sequences, and visible framework/PHP error text
- browser-side events reported by the injected HTML probe, including navigation timing, DOM/load duration, resource load failures, stylesheets that never attach to the CSSOM, JavaScript errors, unhandled promise rejections, failed or slow `fetch`/XHR calls, matching server snapshot links for captured API requests, and a bounded Resource Timing inventory for the slowest/largest loaded assets
- Panel accessibility policy reports in a dedicated Accessibility tab, with issue and adjustment counts, token filters, remediation hints, field focus/copy actions, a policy event log, retained rows after empty pass reports, labels for combined field payloads, and row-reflow adjustment guidance
- runtime details, including PHP version, SAPI, OS family, timezone, memory limit, Opcache state, loaded extension count, included files by module, latest included files, root paths, and Tracelog buffer sizing

Dataphyre SQL and the runtime toolbar redact sensitive values before exposing them to Flightdeck. Keys containing `password`, `passwd`, `secret`, `token`, `csrf`, `api_key`, `authorization`, or `cookie` are replaced with `[redacted]`; cookie values are always hidden; large strings and deep arrays are also trimmed for toolbar safety.

The response audit resolves same-origin asset URLs against `DOCUMENT_ROOT`, Dataphyre/rootpath roots, the active application root, and optional `flightdeck.debugbar.asset_roots`. This is a filesystem probe only; Flightdeck does not perform browser-side HTTP requests while rendering the toolbar.

JSON and other non-HTML responses are captured into request history without injecting toolbar markup into the response body. JSON responses are decoded for top-level shape, route-like batch keys, Dataphyre API batch result summaries, bounded redacted preview data, and failure markers such as `failed`, `error`, `errors`, and `success: false`.

For HTML responses, the toolbar includes a small signed browser probe. It reports client-side failures back to `/dataphyre/debugbar?action=client_event` for the matching request snapshot. Flightdeck accepts those events only when the authenticated session is still active and the per-snapshot HMAC token matches. The probe ignores its own Flightdeck reporting endpoint and records only failed or slow browser network calls to keep the snapshot focused.

Panel can also emit browser-side accessibility policy summaries through the
`DataphyrePanelAccessibilityPolicy` event. The probe listens on both `document`
and `window`, renders the live Accessibility tab immediately, and posts a
normalized `accessibility_policy` client event into the retained snapshot. The
preferred payload shape is:

```js
{
  checked: 2,
  issue_count: 1,
  adjustment_count: 1,
  status: "needs_attention",
  issues: [{ name: "email", issues: ["width_constrained"], issue_messages: ["Usable width is below policy."] }],
  adjustments: [{ name: "sku", actions: ["width_expanded"], action_messages: ["Field expanded to satisfy usable width policy."] }]
}
```

For compatibility with custom emitters, Flightdeck also accepts combined field
rows as `fields` in the browser event, or as `fields`/`a11y_fields` when posting
directly to the client-event endpoint. Rows with `issues` become issue rows;
rows with `actions` become adjustment rows. Retained snapshots label those rows
as combined fields so the source shape is visible during debugging. If `status`
is omitted, Flightdeck derives `needs_attention` from issue rows, `adjusted`
from adjustment-only rows, and `pass` when no rows need action.

On safe GET/HEAD application pages, that probe also launches one signed production replay against the same URL. Bootstrap verifies the short-lived HMAC token before defining `IS_PRODUCTION`, forces production mode for that replay only, suppresses debug logging, disables toolbar injection inside the replay, and marks Dataphyre SQL/cache as read-only. The replay reports HTTP status, production verification, server duration, app memory with debug logging overhead excluded, response size, and blocked SQL/cache mutations back into Browser Events, Triage, and Timeline. It is intended as a production-like baseline for debug overhead; POST body replay and arbitrary external side-effect blocking are intentionally outside this first safety boundary.

When a reported `fetch` or XHR event matches another captured request by method and path, Flightdeck links the browser event to that server-side snapshot. This lets the Browser Events panel jump directly from a client API failure to the backend response, SQL, routing, and diagnostics captured for the same request.

When the toolbar renders on an application page, it records a bounded, sanitized snapshot in the authenticated PHP session. Flightdeck keeps the most recent snapshots only, trims high-cardinality SQL event lists, compares each request with the previous retained capture for the same app, HTTP method, and path, and skips Flightdeck control-plane pages so the history focuses on the application being debugged. The toolbar overlay is also not injected into `/dataphyre` control-plane pages; those pages use their own Flightdeck shell and render retained snapshots inline.

The `/dataphyre/debugbar` control page owns the signed runtime-toolbar cookie and request history. It can enable or disable the overlay, clear retained snapshots, list captured requests with status, duration, finding count, SQL count, and timeline count, and render the selected snapshot through the same diagnostics, SQL, timeline, routing, request, templating, and runtime panels used by the overlay.
