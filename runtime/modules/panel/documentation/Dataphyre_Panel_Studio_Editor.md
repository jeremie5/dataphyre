<!--
Dataphyre
Copyright (c) 2026 Shopiro Ltd.
SPDX-License-Identifier: MIT
-->

# Dataphyre Panel Studio Editor

The Panel Studio editor is an accessible, route-free composition editor layered on the trusted `PanelStudioManager`, schema registry, compiler, and materializer. An opt-in first-party visual runtime renders its trusted builders as real Panel surfaces without adding arbitrary callbacks, PHP classes, templates, or raw HTML to Studio definitions.

The editor renders useful server-side HTML before JavaScript runs. Its progressive runtime adds local undo and redo, focus-preserving property edits, keyboard and pointer reordering, mobile pane navigation, zoom, and canvas reflow. An optional collaboration connector adds document-scoped review threads, comments, assignments, watches, presence, typing, and identity projections without trusting browser-supplied actor or subject identifiers. An optional signed live transport adds visibility-aware delta polling, host-custodied presence, typing updates, reconnect state, safe server-rendered fragment replacement, and single-attempt mutations while remaining route-free. The visual adapter can render unsaved authorized sessions, signed saved revisions, and published revisions into sandboxed, non-mutating frames. The host remains responsible for route registration, authentication, authorization context, CSRF issuance, signing-key custody, server-side checkpoint and presence-token storage, and every editor, collaboration, or preview endpoint.

## Public surface

The editor uses five primary public entry points:

```php
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorOptions;

$session = PanelStudioEditor::open(
    manager: $studioManager,
    document: $document,
    principalId: $principalId,
    initial: $initialDefinition,
);

$session = PanelStudioEditor::handle($session, $_POST, $options);
$html = PanelStudioEditor::render($session, $options);
$checkpoint = PanelStudioEditor::checkpoint($session);
$visual = PanelStudioEditor::visualPreview($session, $dataset, $request);
```

`PanelStudioEditor::resume()` restores a trusted server-owned checkpoint. This is the preferred request-boundary flow for SSR and no-JS operation:

```php
$stored = $serverSessionStore->get($checkpointKey);

$session = is_array($stored)
    ? PanelStudioEditor::resume($studioManager, $document, $principalId, $stored)
    : PanelStudioEditor::open($studioManager, $document, $principalId, $initialDefinition);

$options = PanelStudioEditorOptions::make([
    'action_url' => '/admin/studio/orders',
    'preview_url' => '/admin/studio/orders/preview',
    'csrf_name' => '_token',
    'csrf_token' => $csrfToken,
    'theme' => 'auto',
    'direction' => 'auto',
    'title' => 'Panel Studio',
    'editor_id' => 'orders-studio',
    'inline_assets' => true,
    'zoom' => '100',
    'reflow' => 'desktop',
    // Optional. See "Scoped collaboration connector" below.
    'collaboration_connector' => $collaborationConnector,
]);

if ($request->method() === 'POST') {
    PanelStudioEditor::handle($session, $request->post(), $options);
}

// Render before persisting so a newly issued preview bearer remains limited to
// this response. The checkpoint deliberately omits preview bearer tokens.
$html = PanelStudioEditor::render($session, $options);
$serverSessionStore->put($checkpointKey, PanelStudioEditor::checkpoint($session));
```

When a `PanelInstance` has a configured Studio platform domain, the equivalent
`openStudioEditor()`, `resumeStudioEditor()`, `renderStudioEditor()`, and
`studioEditorManifest()` helpers delegate to this same editor contract. The
`Panel` static facade exposes the same operations for the default instance. The
helpers fail closed when the Studio manager is not ready; they do not create a
permissive manager or install transport.

Checkpoints are JSON-compatible arrays bounded to 2 MiB. They preserve the current definition, deterministic selection, optimistic base identity, conflict state, and up to 50 undo and redo snapshots. Store them only in a trusted server-side session or cache. Never accept a checkpoint directly from a browser.

## Scoped collaboration connector

Studio can project Panel's collaboration subsystem into the same single SSR
form. The connector remains route-free: it does not authenticate a user, mount
an endpoint, create a CSRF token, or choose a persistence store.

```php
use Dataphyre\Panel\PanelStudioCollaborationConnector;
use Dataphyre\Panel\PanelStudioIamIdentityConnector;

// Both facades are derived from the authenticated host request. The IAM facade
// is already actor- and tenant-scoped; the collaboration manager carries the
// host's operation policy and durable store.
$identities = new PanelStudioIamIdentityConnector(
    $iamManager->scope($tenantId, $principalId),
);

$collaborationConnector = new PanelStudioCollaborationConnector(
    manager: $panelPlatform->collaboration(),
    identities: $identities,
    limits: [
        'threads' => 20,
        'comments_per_thread' => 30,
        'directory' => 100,
        'watchers' => 100,
        'presence' => 50,
        'typing_per_thread' => 25,
    ],
);

$options = PanelStudioEditorOptions::make([
    'action_url' => '/admin/studio/orders',
    'csrf_token' => $csrfToken,
    'collaboration_connector' => $collaborationConnector,
]);
```

`PanelStudioArrayIdentityConnector` is the deterministic host/testing adapter
when an application already owns a bounded identity directory. Identity
profiles expose only an identifier, display name, initials, status, source, and
assignability. Email addresses, credentials, claims, and arbitrary profile
fields are rejected or omitted.

The connector derives the collaboration actor from
`PanelStudioEditorSession::principalId()`. It derives a tenant-bound
`studio_document` subject by hashing the trusted document tenant and identifier.
Browser fields named `actor`, `tenant`, or `subject_id` have no authority.
Threads are checked against that derived subject before comments, resolution,
reopening, or typing can proceed. Assignments require an active identity that
the scoped connector can resolve.

The SSR workspace supports these bounded operations:

| Operation | Server behavior |
| --- | --- |
| `create_thread` | Creates a document-scoped review thread |
| `comment:{thread}` | Adds a plain-text, sanitized comment to a scoped thread |
| `resolve:{thread}` / `reopen:{thread}` | Changes a scoped thread status |
| `assign` / `unassign` | Updates the document review owner |
| `watch` / `unwatch` | Updates the current session principal's watch state |

`PanelStudioEditor::handle()` recognizes collaboration submissions and verifies
the editor CSRF token before dispatch. Hosts may call
`PanelStudioEditor::collaborate()` directly when they need the typed
`PanelStudioCollaborationResult` and refreshed snapshot.

Presence is deliberately host-held:

```php
$lease = $collaborationConnector->acquirePresence($session, 60);

// Keep this bearer proof in trusted host state, never in rendered HTML.
$hostState->put('studio_presence_token', $lease->leaseToken());

$collaborationConnector->heartbeatPresence(
    $session,
    $hostState->get('studio_presence_token'),
    60,
);
```

`PanelStudioPresenceLease`, connector manifests, editor options, HTML, and the
browser model never serialize the lease token. The full SSR snapshot is bounded
and secret-free. Its embedded progressive model is smaller still: it omits the
identity directory, comments, thread bodies, presence rows, and typing details.
Without a `PanelStudioCollaborationTransport`, realtime refresh, polling,
notification delivery, and lease storage remain entirely host transport
concerns.

## Signed live collaboration transport

Panel supplies a progressive browser client and a framework-neutral endpoint,
but deliberately does not register the endpoint. The host creates a rotating
keyring, issues a short-lived intent for the exact trusted editor session, and
passes the route-relative transport model into the same options used to render
the editor:

```php
use Dataphyre\Panel\PanelStudioCollaborationIntentSigner;
use Dataphyre\Panel\PanelStudioCollaborationTransport;

$intentSigner = new PanelStudioCollaborationIntentSigner(
    keys: [
        'studio-live-2026-07' => $currentSigningSecret,
        'studio-live-2026-06' => $retiringSigningSecret,
    ],
    currentKeyId: 'studio-live-2026-07',
);

$collaborationTransport = new PanelStudioCollaborationTransport(
    endpointUrl: '/admin/studio/orders/collaboration',
    intent: $intentSigner->issue($session),
    options: [
        'visible_poll_milliseconds' => 2_000,
        'hidden_poll_milliseconds' => 10_000,
        'maximum_backoff_milliseconds' => 30_000,
        'request_timeout_milliseconds' => 10_000,
        'presence_heartbeat_milliseconds' => 20_000,
        'typing_idle_milliseconds' => 1_500,
    ],
);

$options = PanelStudioEditorOptions::make([
    'action_url' => '/admin/studio/orders',
    'preview_url' => '/admin/studio/orders/preview',
    'csrf_name' => '_token',
    'csrf_token' => $csrfToken,
    'collaboration_connector' => $collaborationConnector,
    'collaboration_transport' => $collaborationTransport,
]);
```

Signing secrets contain at least 32 bytes, stay in trusted host configuration,
and never enter editor checkpoints or manifests. A keyring retains at most
eight current or retiring keys. Intents use HS256, last 30 to 900 seconds, and
are bound to the trusted tenant, document, principal, subject, and explicit
abilities (`delta`, `mutate`, `presence`, and `typing`). Every successful
response rotates the browser intent. An intent is browser transport authority;
it is not authentication and never replaces the host policy callback.

The host collaboration route reconstructs the same authorized session,
connector, options, and signer graph used for rendering:

```php
use Dataphyre\Panel\PanelStudioCollaborationEndpoint;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorSession;

$checkpoint = $serverSessionStore->get($checkpointKey);
$session = is_array($checkpoint)
    ? PanelStudioEditor::resume($studioManager, $document, $principalId, $checkpoint)
    : PanelStudioEditor::open($studioManager, $document, $principalId, $initialDefinition);

$endpoint = (new PanelStudioCollaborationEndpoint($intentSigner))
    ->authorizeHost(
        static function (
            string $action,
            PanelStudioEditorSession $session,
            array $context,
        ) use ($studioPolicy): bool {
            return $studioPolicy->allowsLiveCollaboration(
                principalId: $session->principalId(),
                document: $session->document(),
                action: $action,
                context: $context,
            );
        },
    );

$trustedPresenceToken = $hostSession->get('studio_presence_token');
$result = $endpoint->handle(
    session: $session,
    editorOptions: $options,
    request: $request->post(),
    trustedPresenceToken: is_string($trustedPresenceToken)
        ? $trustedPresenceToken
        : null,
    correlationId: $requestCorrelationId,
);

if ($result->presenceDisposition() === 'replace') {
    $hostSession->put(
        'studio_presence_token',
        $result->trustedPresenceToken(),
    );
} elseif ($result->presenceDisposition() === 'clear') {
    $hostSession->remove('studio_presence_token');
}

$serverSessionStore->put(
    $checkpointKey,
    PanelStudioEditor::checkpoint($session),
);

return $responseFactory->response(
    body: $result->content(),
    status: $result->status(),
    headers: $result->headers(),
);
```

The raw presence lease is returned separately through
`PanelStudioCollaborationEndpointResult::trustedPresenceToken()` and is never
part of `body()`, `content()`, or `jsonSerialize()`. Apply the result's
`replace`, `clear`, or `unchanged` disposition before returning the public JSON.
Do not copy the lease into HTML, JavaScript, logs, client storage, or a query
string.

The endpoint accepts five bounded transport actions:

| Action | Ability | CSRF | Behavior |
| --- | --- | --- | --- |
| `delta` | `delta` | Read-only | Returns a bounded change feed, reset signal, current workspace, and refreshed intent |
| `mutate` | `mutate` | Required | Imports and validates the optimistic editor state, then executes one scoped collaboration operation |
| `presence_sync` | `presence` | Required | Acquires or heartbeats the host-held presence lease |
| `presence_release` | `presence` | Required | Releases the host-held lease and clears host custody |
| `typing` | `typing` | Required | Updates one subject-checked thread's bounded typing lease |

The host authorizer is mandatory and runs after intent verification but before
connector access. It receives only sanitized transport context; it must derive
all identity and tenancy decisions from the trusted session and host request.
Missing authorization, malformed input, stale client state, invalid CSRF,
expired or scope-mismatched intents, and oversized requests fail closed with
stable public error codes.

The browser client posts only to the configured same-origin path. Reads are
at-least-once and reset-aware. Visibility changes select the visible or hidden
poll interval; network failures use bounded adaptive backoff and expose live,
syncing, reconnecting, offline, or error state in an `aria-live` status.
Presence heartbeats pause while hidden, and page disposal attempts a
`sendBeacon` or keepalive release.

Mutation delivery is intentionally single-attempt. The client never blindly
replays a thread, comment, assignment, watch, resolve, or reopen command after
an ambiguous timeout. It immediately reconciles with a signed delta instead,
so a server-accepted command converges without a duplicate side effect.

Changed workspaces arrive as server-rendered collaboration fragments. The
client parses them in an inert document, rejects executable, external,
form-owning, or event-bearing nodes and attributes, imports the one
scope-matched fragment, and never uses `innerHTML`. Draft values are restored
by stable control identity and active focus and selection are restored when
that control still exists. A successful mutation clears only the control that
the accepted operation consumed.

## Manifest boundaries

The root Panel `studio_editor` manifest describes editor availability, SSR/no-JS
contracts, assets, connector and signed-transport support and activation, and
whether a host route is registered. It is secret-free: CSRF tokens, preview
bearers, checkpoints, callbacks, signing keys, and raw presence leases are
never serialized.

`PanelStudioManager::manifest()` reports `visual_editor_runtime=true` only when
that exact manager has an attached `PanelStudioVisualRuntime`. The default is
still `false`; class availability and the route-free editor do not activate the
adapter. The dedicated `studio_editor` manifest separately reports its runtime
contract and active state. Neither flag claims that a host mounted an
authenticated route or transport.

When Studio is attached through `PanelPlatform`, the manager, store, compiler,
schema registry, and materializer form one identity-cohesive graph. The
platform manifest verifies that `PanelStudioManager::store()`, `compiler()`,
`registry()`, and `materializer()` return the exact separately registered
objects. When the visual runtime is enabled, it also verifies exact identity
between the manager attachment and `studio.visual_runtime`. Merely registering
compatible objects under the `studio.*` names does not make a split graph
usable. Unresolved factories remain pending and are not executed by manifest
generation. This graph check still says nothing about whether an authenticated
editor or preview route has been mounted.

## SSR command transport contract

This command endpoint is separate from the optional signed live-collaboration
endpoint described above. The subsystem registers neither route. The host SSR
POST endpoint should:

1. Rebuild the authorized `PanelStudioManager` and document identity.
2. Resume the server-owned checkpoint, or open a new editor.
3. Call `PanelStudioEditor::handle()` with the POST map and the same options, including the same scoped collaboration connector, used for rendering.
4. Render the response.
5. Persist the updated checkpoint.

The host must authenticate the request, derive the tenant/principal-scoped
Studio, collaboration, and IAM facades, authorize each operation, issue and
validate CSRF, register the action and preview routes, and operate trusted
checkpoint and presence-lease persistence. The editor supplies none of those
facilities automatically. Rendering an editor or reading its manifest does not
create a route, session, authorization policy, preview store, collaboration
store, identity directory, or persistence backend.

`PanelStudioEditorOptions` accepts only same-origin absolute paths for action and preview URLs. It requires a CSRF token of 16 to 4096 safe bytes. CSRF validation happens before the client definition or command is inspected.

The hidden definition payload is untrusted transport state. The session re-parses it through `PanelStudioDefinition`, checks its base revision and hash, and validates it against the active registry before save. Unsupported commands, stale bases, malformed property encodings, excessive fields, and incompatible child moves fail closed into diagnostics.

## First-party visual runtime

Enable the route-neutral adapter explicitly in the Studio platform domain:

```php
use Dataphyre\Panel\PanelPlatform;

$platform = PanelPlatform::defaults([
    'studio' => [
        'visual_runtime' => true,
        // Supply the normal store, authorization, signer, and other Studio
        // configuration required by this host.
    ],
]);

$preview = $platform->renderStudioVisualPreview($session, $dataset, $request);
$response = $preview->response($ifNoneMatch);
```

`visual_runtime` may instead be a host-created `PanelStudioVisualRuntime`
instance. `false` and `null` leave it inactive. A manually assembled
`PanelStudioManager` must receive the same runtime instance it exposes through
the platform; split attachments fail platform cohesion.

The runtime maps every trusted Studio envelope kind to its actual Panel builder
family, including resources, forms, sections, fields, tables, filters, views,
boards and lanes, infolists and entries, actions and groups, widgets, schemas,
navigation, and all collection bundles. An unsaved session preview first
reauthorizes the document read, validates the current definition through the
attached registry, and materializes it through the attached materializer.
Saved and published paths are available directly from the adapter:

```php
$runtime = $studioManager->visualRuntime();

$signed = $runtime->renderSigned(
    $studioManager,
    $previewBearer,
    $tenantId,
    $documentId,
    $principalId,
    $dataset,
    $request,
);

$published = $runtime->renderPublished(
    $studioManager,
    $tenantId,
    $documentId,
    $principalId,
    $dataset,
    $request,
);
```

`PanelStudioVisualDataset` accepts bounded JSON-only record data, recursively
redacts sensitive keys before rendering, and rejects callbacks, objects,
resources, malformed UTF-8, non-finite numbers, and oversized structures. If no
dataset is supplied, deterministic representative records are derived from the
trusted definition. Dataset values never appear in runtime, editor, surface, or
preview manifests; only counts, limits, a digest, and the synthetic-data flag
are serialized.

Each rendered Panel document becomes a self-contained empty-permissions
`sandbox=""` iframe. Before serialization, the adapter strips scripts, base
elements, and external link assets, then replaces only allow-listed built-in
`panel.css` and `panel-platform.css` links with their capability-scoped
first-party CSS. The frames therefore retain actual Panel presentation without
`allow-same-origin` or network stylesheet authority. The outer preview is inert
and carries a restrictive CSP; executable scripts, forms, callbacks, mutation
authority, preview tokens, and host credentials are absent. Frame content is
capped at 4 MiB, a preview at 64 surfaces and 16 MiB total, and renderer
failures become stable content-free fallback surfaces.
`PanelStudioVisualPreview::response()` supports a content-bound ETag and a
body-free `304`, but installs no cache, route, session, authentication, CSRF, or
authorization policy.

## Save, conflict, and preview lifecycle

Saving calls `PanelStudioManager::saveDraft()` with the editor's optimistic base revision. A successful save clears local history and updates the base hash. If the remote revision changed, the editor enters conflict state and disables save and preview until the operator reloads the remote revision.

Preview is available only for a valid, clean, saved revision. `PanelStudioManager` issues a signed, revision-bound preview intent. The bearer appears only in the dedicated POST preview form for the current response. It is omitted from editor manifests, the progressive model, JSON serialization, and server checkpoints.

The host preview endpoint may verify and materialize the submitted bearer itself,
or call `PanelStudioVisualRuntime::renderSigned()` to perform both operations and
render the exact trusted revision. Do not render the client definition directly.

## Trusted palette and property inspector

The palette is derived from the frozen schema registry. A component can be added only when the selected parent has a matching trusted child rule and available cardinality. Property controls come from `PanelStudioPropertySchema`; enum, boolean, number, collection, scalar, and unset values use bounded typed decoding on both client and server.

The default registry has executable schemas for every envelope kind. `board`,
`board_column`, and `infolist` can be added, edited through typed controls,
saved, and previewed like the other trusted components. Board inspectors expose
status identity, move-source and transition metadata, card/lane presentation,
and responsive brick/masonry controls. The materialized board stays read-only
until the host attaches a mutation handler outside Studio.

The generic portable-only affordance remains for host-defined registries that
intentionally omit a schema. A missing schema is still disabled and explained;
the editor never guesses an executable substitute.

Password fields never expose or serialize credential defaults. Definitions reject objects, resources, callbacks, PHP, raw markup, unsafe identifiers, sensitive keys, embedded credential material, and non-finite numbers.

## Keyboard and pointer behavior

The semantic component tree uses `role="tree"`, `role="treeitem"`, levels, selection, and groups. Controls have accessible names and visible focus treatment.

| Input | Result |
| --- | --- |
| Up or Down | Move focus through visible tree items |
| Home or End | Focus the first or last tree item |
| Alt + Up or Alt + Down | Reorder among siblings |
| Alt + logical outward arrow | Outdent the component |
| Alt + logical inward arrow | Indent beneath the previous compatible sibling |
| Control or Command + Z | Undo |
| Control or Command + Shift + Z | Redo |
| Control + Y | Redo |
| Drag and drop | Reorder before, after, or inside a compatible target |
| Touch press and release | Reorder through the same trusted placement rules |

Left and right reorder semantics reverse in RTL. Inspector rerenders restore focus by a deterministic focus token, including after a component key changes.

## Responsive and theme behavior

Desktop uses palette, tree, structural canvas, and property panes. Tablet reflows the panes without adding page-level horizontal scrolling. At 720px and below, progressive enhancement exposes a two-by-two pane switcher and shows one workspace pane at a time. Collaboration controls become a single-column flow, action pairs brick to equal widths, and comment composition avoids horizontal scrolling. The no-JS surface keeps every pane and the review workspace in document order and hides the progressive switcher.

Interactive targets are at least 44px on mobile. Deep tree controls expand only for the selected, hovered, or keyboard-focused row, preserving readable labels and avoiding nested navigation overflow. The canvas supports desktop, tablet, and mobile widths plus 75%, 100%, 125%, and fit zoom modes.

The stylesheet consumes Panel tokens with safe fallbacks and supports `auto`, `light`, `dark`, and `glass`. It uses logical properties for RTL, explicit dark form-control colors, `prefers-reduced-motion`, `prefers-reduced-transparency`, and `forced-colors`. The editor does not use `!important`, container queries on field grid items, or horizontal-scroll fallbacks.

## Asset delivery

`PanelStudioEditorAssets` exposes deterministic standalone CSS and JavaScript:

```php
$css = PanelStudioEditorAssets::css();
$javascript = PanelStudioEditorAssets::javascript();
$manifest = PanelStudioEditorAssets::manifest();
```

Set `inline_assets` to `true` for an isolated route-free surface. Supply a validated CSP nonce when the host policy requires it. With `inline_assets` set to `false`, the host must serve the exact CSS and JavaScript bytes and include them once. The asset manifest provides byte counts, SHA-256 digests, and SRI values for cache and deployment verification.

## Migration from portable blueprints

1. Continue loading the existing `PanelStudioDefinition`; no definition format conversion is required.
2. Open the definition with `PanelStudioEditor::open()`.
3. Confirm that the active registry and materializer report contract version 3 and complete kind coverage.
4. Re-save through `PanelStudioManager` so the revision binds the active definition, registry, compiler, materializer, and builder fingerprints.
5. Repeat the normal independent approval and signed-preview checks before promotion.
6. Keep old revisions immutable. Earlier artifacts intentionally remain stale until this explicit rebind.

Existing `board`, `board_column`, and `infolist` nodes retain their JSON shape.
Contract version 3 adds typed `data_surface`, `workflow`, `workflow_state`, and
`workflow_transition` composition. The migration changes the trusted execution
contract, not prior envelopes. Do not bypass the re-save by rewriting stored
artifact fingerprints.

## Verification

The editor and visual-runtime lanes are verified on PHP 8.2 and PHP 8.4. Closed-world PHPDBG coverage targets the complete `Framework/Studio` inventory. The visual-runtime contract separately exercises every trusted root kind, unsaved/signed/published flows, exact manager attachment, recursive dataset bounds and redaction, frame budgets, stable failures, ETags, and facade/platform cohesion. The Node source audit checks unsafe DOM APIs, deterministic serialization, motion and forced-color contracts, RTL logical spacing, and overflow policy.

The Chromium regression fixture renders the real PHP facade and verifies:

- focus-preserving rename, undo, redo, keyboard reorder, and pointer reorder;
- complete default palette coverage, trusted board/infolist composition, and missing-host-schema truthfulness;
- dirty/save/preview state and typed registry validation;
- 320px and 390px mobile pane navigation with no page overflow;
- light, dark, and glass input contrast;
- RTL direction and logical outdent behavior;
- reduced motion and forced colors;
- semantic tree, labels, live region, unique IDs, and accessibility-tree availability;
- useful mobile SSR when JavaScript is disabled;
- document-scoped collaboration projection, active-only assignment, verified
  receipt state, CSRF-bound operation payloads, and bearer-proof omission;
- two-tab signed convergence with rotating intents, typing true/idle updates,
  one mutation attempt, cursor advancement, and preservation of the peer's
  focused unsaved comment through local and remote fragment replacement;
- one flat responsive review workspace at 320px without nested forms or
  horizontal scrolling;
- actual visual-runtime surfaces in glass/dark and Flat Minima/light modes,
  including empty sandbox permissions, self-contained first-party CSS,
  instance ownership, labelled 4.5:1 controls, and overflow-free 320px output.

The deterministic editor and integrated visual-runtime showrooms live at
`testing/fixtures/panel_studio_editor_showroom.php` and
`testing/fixtures/panel_browser_showroom.php`. They own no application route or
production persistence and exist only for browser evidence. The integrated
contracts are `panel.studio-editor.lifecycle`,
`panel.studio-collaboration.live-convergence`, and
`panel.studio-visual-runtime.browser` in the browser interaction lane.
