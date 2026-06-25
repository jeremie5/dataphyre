# Dataphyre Panel Module

The Panel module is the first-party resource layer for building internal control
surfaces on top of Dataphyre SQL, Access, and Templating. It provides typed
resource definitions instead of forcing applications to hand-write every CRUD
screen from raw templates.

Panel is intentionally a framework module. Loading it registers the panel
namespace and loads the SQL, Access, and Templating framework layers when the
core framework loader is available. It is not a routed application; it is a set
of reusable resource, form, table, action, widget, and rendering primitives.

## Reality Status

The current capability audit lives in
[Dataphyre_Panel_Capability_Audit.md](Dataphyre_Panel_Capability_Audit.md).
Use it as the source of truth for what is solid, partial, demo-only, or missing
before comparing Panel to Filament-style admin builders. The `/debug` live
example is a useful exerciser, but it is not by itself a production-completeness
claim.

```php
\dataphyre\core::load_framework_module('panel');
```

The generated surface is URL-agnostic. Panel does not own application paths;
generated forms, links, redirects, breadcrumbs, relation actions, bulk actions,
and global search resolve through a host-provided `url_builder` or fall back to
query-state on the current host page.

```php
return [
	'dataphyre'=>[
		'panel'=>[
			// Optional. Without this, Panel links back to the current page with
			// resource, operation, record, relation, and action query parameters.
			'url_builder'=>'app_panel_url',
		],
	],
];

function app_panel_url(string $target, array $query): string {
	$base='/workspace';
	return $base.($target!=='' ? '/'.$target : '').($query!==[] ? '?'.http_build_query($query) : '');
}
```

### Routing and MVC Mounts

Panel remains route-agnostic, but it can now be mounted inside Dataphyre
Routing or MVC when an application wants clean path URLs instead of query-state
URLs. `Panel::mountedRoutes()` registers the page catch-all plus Panel asset and
upload endpoints. During dispatch, the route controller injects mounted URL
builders so generated links, CSS/JS, and custom uploader endpoints stay under
the same prefix.

When a route reuses a Panel surface below an application base path, the
controller resolves the effective mount from the current request path. For
example, a route configured with the inner prefix `/admin` and reached at
`/backoffice/admin/orders` generates assets, uploads, forms, and navigation
under `/backoffice/admin` for that request.

```php
use Dataphyre\Panel\Panel;

// Dataphyre Routing
return [
	...Panel::mountedRoutes('/admin', 'default', [
		'name'=>'admin.panel',
		'middleware'=>['auth'],
	]),
];

// Dataphyre MVC
Panel::mvcMountedRoutes($app->routes(), '/admin', 'default', [
	'name'=>'admin.panel',
	'middleware'=>['auth'],
]);
```

The mounted URL contract is canonical:

```php
Panel::routeUrlBuilder('/admin')('orders/edit/42');        // /admin/orders/42/edit
Panel::routeAssetUrl('/admin', 'panel.css');               // /admin/assets/panel.css?v=...
Panel::routeUploadUrl('/admin');                           // /admin/upload
Panel::routeManifest('/admin', 'default', ['name'=>'admin.panel']);
```

When a panel is dispatched through a mounted route, `Panel::panelManifest()`
also includes a `routes` section with the current prefix, endpoint paths,
generated examples, and controller classes. Unmounted panels report
`routes.mounted=false` so tooling can distinguish route-free embeds from native
Routing/MVC mounts.

Use the narrower helpers when an application wants to place endpoints in
separate route groups: `Panel::routes()`, `Panel::assetRoutes()`,
`Panel::uploadRoutes()`, `Panel::mvcRoutes()`, `Panel::mvcAssetRoutes()`, and
`Panel::mvcUploadRoutes()`. Legacy kernel endpoints under
`/dataphyre/panel/assets/...` and `/dataphyre/panel/upload` still work and
delegate to the same controller code used by mounted routes.

### Modal Chrome

Panel slide-over modals keep secondary chrome actions in the header, but those
actions wrap within the available header width instead of creating a horizontal
scrollbar. Password reveal controls use the host panel localization keys such as
`common.show`, so localized applications keep generated form controls in the
same language as the surrounding surface.

Create-resource modal descriptions use neutral operator copy by default. The
`table.create_resource_body` localization key tells the operator to add details
and save when ready, without exposing implementation language such as generated
forms or table mechanics.

### Native Localization

Panel has a first-pass, route-agnostic localization layer for translatable
labels and copy. It is intentionally small and framework-native: a
`PanelLocalization` catalogue tracks the active locale, fallback locale, flat or
nested scoped keys, parameter interpolation, and a JSON manifest.

```php
$panel=Panel::make('seller')
	->localization([
		'locale'=>'fr-CA',
		'fallback_locale'=>'en',
		'translations'=>[
			'en'=>[
				'actions.save'=>'Save :resource',
			],
			'fr'=>[
				'actions'=>[
					'save'=>'Enregistrer :resource',
				],
			],
		],
	]);

echo $panel->trans('actions.save', ['resource'=>'orders']);
echo $panel->localization()->scope('actions')->t('save', ['resource'=>'orders']);
```

Lookup checks the requested locale, its base language, the fallback locale, and
the fallback base language. Placeholders support `:name`, `{name}`, and
`{{ name }}` forms. The catalogue serializes through `toArray()` and
`jsonSerialize()` for host manifests without requiring Panel to own routes.

Panel instances can carry their own manager and URL/config context. Use them
when an application exposes more than one surface, or when a package should
provide panel building blocks without touching the process-local default panel.

```php
$seller_panel=Panel::make('seller')
	->label('Seller Console')
	->homeLabel('Overview')
	->urlBuilder('seller_console_url')
	->authorize(fn(string $ability, ?Resource $resource, mixed $user, PanelRequest $request) => $user!==null);

$seller_panel->register(
	$seller_panel->resource('orders')
		->label('Order')
		->pluralLabel('Orders')
		->table('commerce.orders')
		->fields([
			$seller_panel->field('number')->required(),
			$seller_panel->field('status', 'select')->options(['open'=>'Open', 'shipped'=>'Shipped']),
		])
);

$seller_panel->registerNavigationItem(
	$seller_panel->navigationItem('storefront')
		->label('Open Storefront')
		->group('Commerce')
		->icon('external-link')
		->url('/seller/storefront')
		->description('Preview the public selling experience')
);

echo $seller_panel->dispatch(PanelRequest::capture())->content();
```

Packages can expose providers instead of routes. A provider receives the surface
that the host created and registers only definitions.

```php
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelProvider;

final class CommercePanelProvider implements PanelProvider {

	public function panel(PanelInstance $panel): PanelInstance {
		$panel->register(
			$panel->resource('orders')
				->label('Order')
				->pluralLabel('Orders')
		);
		$panel->registerWidget($panel->stat('open_orders', fn() => OrderRepository::openCount()));
		return $panel;
	}
}

$seller_panel->provide(CommercePanelProvider::class);
```

### Native Access Auth

Panel can register native authentication pages through Dataphyre Access. This
adds route-agnostic pages for login, logout, registration, email verification,
password reset, and password change, then protects the rest of the surface with
the active Access guard.

```php
\dataphyre\core::load_framework_module('panel');
\dataphyre\core::load_framework_module('access');

use Dataphyre\Panel\Panel;

$panel=Panel::make('ops')
	->label('Operations')
	->auth([
		'allow_registration'=>true,
		'require_email_verification'=>true,
		'after_login'=>'/debug',
	]);
```

The auth pages use the same Panel URL builder as the rest of the surface. Mail
for verification and password reset is sent through Dataphyre Mailer when the
Mailer framework is available. Applications can provide an identity repository
through `DP_ACCESS_CFG['identity']` callbacks or a configured SQL users table.

### Render hooks

Panel surfaces expose trusted render hooks for small host or package-owned UI
extensions. Hooks do not register routes and they do not require forking the
renderer. They receive a context array and return HTML.

```php
$seller_panel
	->renderHook('content.before', function(array $context): string {
		$tenant=$context['tenant'] ?? null;
		return $tenant===null
			? ''
			: '<div class="panel-scope">Showing tenant '.htmlspecialchars((string)$tenant).'</div>';
	})
	->renderHook('resource.index.after', function(array $context): string {
		$resource=$context['resource'] ?? null;
		if(!$resource instanceof Resource || $resource->name()!=='orders'){
			return '';
		}
		return '<aside>Orders index was extended by a package.</aside>';
	});
```

Available shell hooks:

| Hook | Position |
| --- | --- |
| `head.end` | Before the closing `</head>` tag. |
| `body.start` | Immediately after the opening `<body>` tag. |
| `body.end` | Before the closing `</body>` tag. |
| `header.before` | Before breadcrumbs, brand, and the generated page heading. |
| `header.after` | After the generated page heading tools. |
| `page.before` | Inside the generated `<main>` before page content. |
| `page.after` | Inside the generated `<main>` after page content. |
| `content.before` | Around the content payload before it enters the page shell. |
| `content.after` | Around the content payload after it enters the page shell. |

Available resource hooks:

| Hook | Position |
| --- | --- |
| `resource.index.before` | Before a resource index table surface. |
| `resource.index.after` | After a resource index table surface. |
| `resource.form.before` | Before create/edit forms. |
| `resource.form.after` | After create/edit forms. |
| `resource.show.before` | Before a record show surface. |
| `resource.show.after` | After a record show surface. |

Hook callbacks receive `array $context`, `string $hook`, and `PanelManager
$manager` when their signature asks for them. Common context keys include
`kind`, `title`, `tenant`, `request`, `resource`, `page`, `theme`, `data`, and
`manager`. Resource hooks receive the live `Resource` object; shell hooks receive
the serialized resource/page metadata from the rendered result.

Render hook output is trusted server-side HTML. Escape user content before
returning it.

Named surfaces can also be kept in the registry. This remains route-free: a host
can fetch the same surface from a console command, a page, an API responder, or a
test harness.

```php
Panel::surface('seller')
	->label('Seller Console')
	->urlBuilder('seller_console_url')
	->provide(CommercePanelProvider::class);

$page=Panel::surface('seller')->dispatch(PanelRequest::capture());
```

Hosts can use `PanelHost` when they want Panel to capture the current request
and emit the result. This is still not routing; it is the boundary between a host
that already matched a request and a Panel surface that can answer it.

```php
Panel::host('seller', $current_user)->emit();
```

For frameworks that own their own response object, keep the result instead:

```php
$result=Panel::host('seller', $current_user)->dispatch();

return app_response(
	$result->content(),
	$result->status(),
	$result->headers()
);
```

## Plugins

Providers are the lightest way to register definitions. Plugins are for package
features that need a stable identity, options, render hooks, widgets, resources,
theme changes, or boot-time composition.

```php
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelPlugin;

final class AuditTrailPanelPlugin implements PanelPlugin {

	public function id(): string {
		return 'audit_trail';
	}

	public function register(PanelInstance $panel): void {
		$options=$panel->pluginConfig($this->id());

		$panel->renderHook('resource.show.after', function(array $context) use ($options): string {
			if(($options['show_timeline'] ?? true)===false){
				return '';
			}
			return AuditTrailRenderer::recordTimeline($context['resource'] ?? null, $context['record'] ?? null);
		});

		$panel->registerWidget(
			$panel->widget('audit_events')
				->label('Audit events')
				->value(fn() => AuditTrailRepository::todayCount())
		);
	}

	public function boot(PanelInstance $panel): void {
		// Final cross-resource wiring can happen after register().
	}
}

$seller_panel->plugin(new AuditTrailPanelPlugin(), [
	'show_timeline'=>true,
]);
```

Plugin registration is idempotent per surface. Registering the same plugin id
again only merges the supplied plugin options. Plugins can be configured in
module config alongside providers:

```php
return [
	'dataphyre'=>[
		'panel'=>[
			'plugins'=>[
				AuditTrailPanelPlugin::class=>[
					'show_timeline'=>true,
				],
			],
			'surfaces'=>[
				'seller'=>[
					'plugins'=>[
						[
							'plugin'=>AuditTrailPanelPlugin::class,
							'config'=>['show_timeline'=>false],
						],
					],
				],
			],
		],
	],
];
```

Use `pluginConfig($id)`, `hasPlugin($id)`, and `pluginIds()` from a
`PanelInstance`. During rendering, `PanelConfig::pluginConfig($id)` and
`PanelConfig::pluginIds()` read the active surface context. `describe()` includes
loaded plugin ids, classes, config keys, and optional `label()`, `version()`, or
`description()` metadata.

## Global Configuration And Macros

Core Panel builders can be configured globally or extended with macros. This is
intended for plugins and application boot code that need consistent defaults
without subclassing final builder objects.

```php
use Dataphyre\Panel\Action;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Resource;

Resource::configureUsing(function(Resource $resource): Resource {
	return $resource->navigationBadgeTone('info');
});

Action::configureUsing(function(Action $action): Action {
	return $action->modalWidth('lg');
});

Field::macro('opsHint', function(string $hint): Field {
	return $this->help($hint)->meta(['ops_hint'=>$hint]);
});

$field=Field::make('next_step', 'select')
	->opsHint('Changes as the operator moves through the workflow.');
```

Configurators run when a builder is created with `make()` or a Panel factory
method such as `$panel->field()` or `$panel->resource()`. If a configurator
returns a new instance, that instance becomes the configured builder; otherwise
the current builder is kept. Pass `important: true` as the second argument to
run a configurator after ordinary defaults.

Macro methods are normalized the same way as resource names. A closure macro is
bound to the builder instance, so `$this` is the field, action, resource, widget,
or table object being extended. Non-closure callables receive the builder as the
first argument. Use `hasMacro()`, `flushMacros()`, and `flushConfigurators()` for
package safety and tests.

The extensible builders are `Resource`, `Field`, `Action`, `ActionGroup`,
`Widget`, `PanelPage`, `Schema`, `SchemaComponent`, `FormSection`, `Column`,
`TableFilter`, `TableView`, `TableSummary`, `TableGroup`, `PageTable`, `RelationManager`, and
`NavigationItem`. `PanelCommand` is the URL/client-action command descriptor used
by the command palette state.

## Workspace Experience

Panel renders a progressively enhanced workspace around the registered resources,
pages, widgets, and navigation items. The generated HTML works as regular links
and forms first. JavaScript then adds faster navigation, local preferences, and
keyboard control without changing the server-side resource definitions.

The workspace layer is URL-agnostic. It reads the links generated by the active
`url_builder`, the current page title, and the rendered navigation tree. It does
not register routes and does not require an application to expose a fixed
`/admin` path.

### AJAX updates

Generated Panel links and ordinary GET/POST forms are intercepted when the
browser supports `fetch`, `DOMParser`, and the History API. The request is sent
with `__panel_partial=fragment` and `X-Requested-With:
DataphyrePanelFragment`; the returned Panel fragment is reconciled into the
current `main.dp-panel` element so the shell, focus, scroll position, open
details, and table scroll state remain stable where possible.

The same URL remains valid without JavaScript. Exports, import templates,
external URLs, file uploads, explicit `target` links, and controls marked with
`data-dp-panel-no-ajax="1"` continue to use normal browser navigation.
Opened URLs that contain `__panel_partial=fragment` without a Dataphyre Panel
request header are treated as normal full-page requests, so copied or restored
browser URLs never display raw JSON.

Live refresh is enabled for dashboard, index, board, show, and relation
surfaces when `live_updates` is enabled. It pauses automatically when the page is
hidden, offline, inside a modal, holding unsaved form changes, carrying selected
rows, or while the user is typing. Refreshes are quiet by default: no row flash,
header glow, page fade, or loading bar is shown for background updates. Visible
update feedback is opt-in through Panel result data or configuration:

```php
Panel::configure([
	'live_updates'=>true,
	'live_update_interval_ms'=>15000,
	'content_update_flashes'=>false,
]);
```

During fragment reconciliation, Panel also refreshes the embedded command and
surface state scripts. This keeps command palette entries, navigation metadata,
theme state, and client-side surface metadata in sync after a React-like content
swap without forcing a full page redraw.

### Mobile rendering

Panel emits responsive shell styles with the generated workspace. The mobile
layer is server-owned and does not require application routes or handwritten
per-resource markup. At tablet and phone widths, the shell adapts by:

- stacking the header, tools, search, filters, and page actions into touch-safe
  rows
- turning tables into labelled record cards using each column label as mobile
  context
- making modals and the command palette behave like bottom sheets
- keeping bulk actions, pagination, relation managers, boards, tabs, and
  horizontal navigation usable with touch scrolling
- closing transient menus, row action popovers, column pickers, and horizontal
  navigation groups during navigation or outside taps
- preserving full links and forms when JavaScript is unavailable

Generated resources should still provide concise column labels and action labels,
because those labels become the mobile record context.

### Surface state

Every full Panel response is backed by a `PanelSurfaceState` snapshot. The
snapshot describes the rendered page rather than a single resource primitive:
title, page kind, HTTP status, request context, resource/page identity,
breadcrumbs, notifications, compact navigation state, compact command state,
chrome preferences, and the state fragments present on the page.

```php
$result=Panel::dispatch(['resource'=>'orders']);
$surface=$result->data()['surface_state'] ?? null;

$kind=$surface['kind'] ?? null;
$commands=$surface['commands']['command_count'] ?? 0;
$navigation=$surface['navigation']['entry_count'] ?? 0;
```

The same snapshot is embedded in the response as
`data-dp-panel-surface-state`, which gives reactive clients and browser tools a
single server-owned manifest to reconcile against. It intentionally stores
counts, keys, and page-level metadata instead of full records or form payloads.

### Navigation Layouts

Panel navigation is driven by `PanelNavigationState` and can render as a left
sidebar, a horizontal top navigation bar, or be hidden when the host application
provides its own shell:

```php
Panel::make('ops')
	->navigationLayout('horizontal')
	->navigationMode('edge')
	->headerMode('docked')
	->footerMode('edge')
	->stickyNavigation()
	->stickyHeader()
	->stickyFooter();

Panel::configure([
	'navigation_layout'=>'sidebar', // sidebar, horizontal, or none
	'navigation_mode'=>'floating', // floating, docked, edge, or overlay
	'header_mode'=>'floating', // floating, docked, edge, or overlay
	'footer_mode'=>'floating', // floating, docked, edge, or overlay
	'content_spacing'=>'normal', // normal, compact, or flush
	'custom_page_layout'=>'carded', // carded or flow
	'navigation_features'=>[
		'search'=>true,
		'recent'=>true,
		'pinning'=>true,
	],
	'navigation_sticky'=>false,
	'header_sticky'=>false,
	'footer_sticky'=>false,
]);
```

`contentSpacing('flush')` lets edge chrome sit directly against the browser
edge. Use it for page bodies that draw their own full-bleed shell. For custom
pages that provide their own card grid, prefer normal content spacing and pair
it with `customPageLayout('flow')` so Panel does not wrap the direct page
section in an additional card surface:

```php
Panel::surface('erp')
	->navigationLayout('sidebar')
	->navigationMode('edge')
	->headerMode('edge')
	->footerMode('docked')
	->contentSpacing('normal')
	->customPageLayout('flow')
	->stickyNavigation()
	->navigationFeatures([
		'search'=>false,
		'recent'=>false,
		'pinning'=>false,
	]);
```

The generated sidebar is derived from resources, custom pages, and host-owned
navigation items. It supports:

- a local `Find in panel` search box
- persistent collapsed/expanded navigation groups
- a persistent collapsed sidebar mode
- pinned navigation items
- recent navigation items
- nested submenus and folder-only navigation containers
- keyboard movement across visible links
- group counts and a current-location summary
- group heading links to the first visible group item when group collapse is disabled

Search, recent navigation, and pinning can be disabled independently with
`navigationSearch(false)`, `recentNavigation(false)`,
`pinnedNavigation(false)`, or the grouped `navigationFeatures()` helper.

Sidebar search is a local filter. It does not make a server request and it does
not change the current URL. While searching, collapsed groups are temporarily
revealed so matching links are discoverable.

Pinned and recent navigation are stored in `localStorage` for the current Panel
host path. Pinned links appear ahead of regular groups. Recent links appear
below pinned links and omit the current page and anything already pinned.

Navigation groups can be expanded or collapsed individually from the sidebar, or
globally from the command palette. Collapsed state is also stored locally.
Stored collapsed-group state includes the page path that wrote it. Active
navigation groups reopen from persisted state even if an older or legacy local
preference listed that group as collapsed, so the current workspace remains
readable. A user can still collapse the active group manually during the current
page session.
When a Panel disables group collapse, sidebar group headings render as ordinary
links to the first navigable item in the group, so dense grouped sidebars still
respond when a user clicks the top-level group label.
Panel's generated `panel.css` includes the navigation experience stylesheet, so
clickable sidebar group headings inherit Panel navigation typography, spacing,
hover treatment, and link reset styles instead of browser-default anchor styles.

Horizontal navigation uses the same entries and authorization rules. Groups
render as compact menus in the shell header area, while active entries still
receive `aria-current="page"` and the same state snapshot used by the sidebar.
Nested submenus render in both sidebar and horizontal modes from the same
navigation tree, so hosts do not need separate menu definitions per layout.
Horizontal menus float above the page rather than expanding the scrolling track,
which keeps opening a menu from changing page or toolbar scrollbars.

Navigation mode is separate from layout. Layout decides which navigation
structure is rendered; mode decides how that structure is attached to the
viewport:

- `floating` keeps the current card-like shell behavior.
- `docked` keeps navigation in the page flow but removes extra lift so it reads
  as part of the application frame.
- `edge` clamps the sidebar or horizontal bar to the viewport edge and removes
  outer seams.
- `overlay` reserves a mode for hosts that want navigation to sit above the
  content layer.

The current layout and mode are emitted on the root panel as
`data-dp-panel-navigation-layout` and `data-dp-panel-navigation-mode`, and also
appear in the surface and navigation manifests.

Headers and footers use the same attachment vocabulary. The generated page
header is a named chrome region with `data-dp-panel-header` and
`data-dp-panel-header-mode`; optional footers render when the host supplies
`footer`, `footer_html`, or footer render hooks, and receive
`data-dp-panel-footer` plus `data-dp-panel-footer-mode`. This keeps route
placement, shell chrome, and visual attachment independent from the resources
and pages being rendered.

Stickiness is explicit and independent of mode. `edge` can describe a chrome
region that visually touches the viewport edge, while `stickyNavigation()`,
`stickyHeader()`, and `stickyFooter()` decide whether those regions remain in
view while scrolling. The same switches are available as
`navigation_sticky`, `header_sticky`, and `footer_sticky`; aliases
`nav_sticky`, `sticky_nav`, `sticky_header`, and `sticky_footer` are accepted
for host configs that prefer shorter names.

Footer hooks are intentionally opt-in:

```php
Panel::make('ops')
	->footerMode('docked')
	->renderHook('footer', fn() => '<div class="dp-panel-footer-slim"><p>&copy; 2026 Shopiro Ltd.</p><nav><a href="/policies">Policies</a></nav></div>');
```

Panel also has an explicit page-width contract. By default, sidebar layouts use
`constrained` width and horizontal/no-navigation layouts use `fluid` width so
content does not inherit unused sidebar-era gutters. Hosts can override this
with `page_width` or `content_width`:

```php
Panel::configure([
	'navigation_layout'=>'horizontal',
	'page_width'=>'fluid', // fluid, constrained, or compact
]);
```

Navigation chrome is themeable. The renderer keeps stable structure classes for
the shell, but app themes should usually start with tokens instead of replacing
the markup. Navigation tokens are emitted as regular `--dp-*` variables and are
resolved late in the Panel stylesheet:

```php
Panel::theme('merchant_ops')
	->tokens([
		'nav_width'=>'18rem',
		'nav_shell_bg'=>'linear-gradient(180deg,#ffffff,#f7fafc)',
		'nav_shell_radius'=>'1.5rem',
		'nav_brand_bg'=>'#eef6ff',
		'nav_item_radius'=>'0.875rem',
		'nav_item_hover_bg'=>'#eef6ff',
		'nav_item_active_bg'=>'linear-gradient(135deg,#2563eb,#0891b2)',
		'nav_icon_bg'=>'#e2e8f0',
		'nav_submenu_rail'=>'#cbd5e1',
	])
	->darkTokens([
		'nav_shell_bg'=>'linear-gradient(180deg,#111827,#0b1220)',
		'nav_brand_bg'=>'#172033',
		'nav_item_hover_bg'=>'#1f2937',
	]);
```

Useful navigation tokens include `nav_width`, `nav_gap`, `nav_shell_bg`,
`nav_shell_border`, `nav_shell_radius`, `nav_shell_padding`,
`nav_shell_shadow`, `nav_brand_bg`, `nav_brand_border`, `nav_search_bg`,
`nav_current_bg`, `nav_current_border`, `nav_section_gap`,
`nav_section_border`, `nav_section_label`, `nav_section_active_rail`,
`nav_item_bg`, `nav_item_hover_bg`, `nav_item_active_bg`,
`nav_item_active_color`, `nav_item_border`, `nav_item_radius`,
`nav_item_height`, `nav_item_padding`, `nav_icon_bg`, `nav_icon_color`,
`nav_icon_active_bg`, `nav_icon_active_color`, `nav_badge_bg`,
`nav_badge_color`, `nav_submenu_indent`, and `nav_submenu_rail`.

Themes can still ship CSS assets when they need a radically different menu,
for example a branded rail, a compact icon strip, or tenant-specific folder
treatments. Theme CSS is loaded after the generated core stylesheet, and the
stable shell selectors are `.dp-panel-sidebar`, `.dp-panel-sidebar-brand`,
`.dp-panel-sidebar-search`, `.dp-panel-sidebar-context`,
`.dp-panel-sidebar-nav`, `.dp-panel-sidebar-group`,
`.dp-panel-sidebar-link`, `.dp-panel-sidebar-submenu`, and
`.dp-panel-horizontal-nav`.

### Table header controls

Generated resource tables can place compact table controls in the metadata row
with `Panel::tableHeaderControls('compact')` or
`Panel::surface(...)->tableHeaderControls('compact')`. In compact mode the
metadata row owns search, filter launchers, and the create action while the
lower commandbar remains available for extra resource actions. This keeps
common list work close to the record count and page size controls without
requiring per-resource markup.

The compact header layout is responsive. Search keeps normal input padding,
filters stay in the same control group, and the create action drops below the
search field before it can crowd the query input on narrower surfaces. The
resource table empty state remains configurable through `emptyState()` and
`filteredEmptyState()`, so product modules can provide domain-specific empty
copy instead of using the generic generated message.

### Saved views

Generated resource tables include a small table metadata row with record count,
page count, and saved-view controls. A saved view stores the current table URL,
including search, filters, table view, sort, page size, density, and visible
column state. Saved views are local to the browser and the current Panel page.

The saved-view menu can:

- save the current table URL with a label
- remove the current saved view
- jump to any saved view
- copy saved views as JSON
- import saved views from JSON

Saved views are convenience state only. They do not change resource definitions,
authorization, filters, or query handlers.

### Workspace snapshots

The saved-view menu and command palette can copy or restore a workspace
snapshot. A snapshot is JSON containing the current URL, saved views, and local
Panel preferences such as theme mode, sidebar collapse state, live-update pause
state, pinned navigation, recent navigation, collapsed navigation groups, and
client-side column widths.

Snapshots are intended for operators moving their workspace between browsers or
for support/debug handoff. Restoring a snapshot only writes keys beginning with
`dataphyre_panel_` into local storage. If the snapshot contains a URL on the same
origin, the user is asked before navigating to it.

Sidebar panels with docked footers render the main region as a column flex
container. The footer remains in normal document flow, uses `margin-top:auto` to
sit at the viewport bottom on shallow pages, and expands through the main
region's right padding without becoming sticky.

### Keyboard shortcuts

Panel exposes a command palette with `Ctrl+K` or `Cmd+K`. The palette includes
commands, visible navigation links, recent pages, pinned pages, saved views,
actions, filters, column controls, pagination links, breadcrumbs, and table
utilities.

Server-owned commands can be registered independently of routes:

```php
Panel::registerCommand(
	Panel::command('open_billing')
		->label('Open billing')
		->group('Operations')
		->description('Review invoices and billing status')
		->icon('credit-card')
		->url('/billing')
		->keywords(['invoice', 'finance'])
);
```

Every page exposes a `PanelCommandState` snapshot in `PanelPageResult::data()` as
`command_state`, and embeds the same state for the command palette. The snapshot
contains registered commands, built-in workspace commands, visible navigation
entries, and resource-level commands such as create/import/board when the
resource and current policy allow them:

```php
$state=Panel::commandState(PanelRequest::fromArray(['resource'=>'orders']));
$commands=$state->commands();
$matches=Panel::commandState(null, 'import')->matched();
```

The palette still augments this server state with local-only browser commands
such as focused row actions, saved views, pinned navigation, and selected-row
copy commands.

Core shortcuts:

| Shortcut | Behavior |
| --- | --- |
| `Ctrl+K` / `Cmd+K` | Open the command palette. |
| `/` | Focus the current table or global search. |
| `N` | Focus sidebar navigation search. |
| `F` | Toggle the current filter panel when one exists. |
| `C` | Open column controls when a table supports them. |
| `?` | Open the keyboard shortcut reference. |
| `Esc` | Close transient panels and menus. |

Sidebar shortcuts:

| Shortcut | Behavior |
| --- | --- |
| `ArrowDown` / `ArrowUp` | Move through visible sidebar links. |
| `Home` / `End` | Jump to the first or last visible sidebar link. |
| `Enter` in sidebar search | Open the first visible match. |
| `Esc` in sidebar search | Clear the sidebar search. |

Table shortcuts:

| Shortcut | Behavior |
| --- | --- |
| `ArrowDown` / `ArrowUp` | Move focused table row. |
| `Home` / `End` | Jump to the first or last visible table row. |
| `Enter` | Open the focused row's primary link. |
| `Space` | Toggle focused row selection when selection is available. |
| `P` | Open a preview modal for the focused row. |
| `A` | Select visible rows. |
| `X` | Invert visible row selection. |

### Client-side table tools

Table headers can be resized by dragging their right edge. Double-clicking the
edge resets that column's local width. Widths are stored per host path, heading,
and table label.

Focused rows can be previewed in a modal. By default the preview uses visible
table cells, keeps row actions available, and can copy the focused row as JSON
or CSV. Resources can opt into an explicit Preview row action and can provide
server-defined preview fields when the visible columns are not the best summary:

```php
$resource
	->previewFields([
		'number',
		'customer',
		'total'=>fn(array $order): string => money($order['total']),
		['label'=>'Handling note', 'value'=>fn(array $order): string => $order['note'] ?? 'None'],
	])
	->previewAction();
```

`previewFields()` accepts field names, label/value arrays, associative labels,
and callbacks. Callback values receive the record, request, resource, and table.
The command palette can also copy selected rows or visible rows as CSV/JSON.

Applications may configure providers for the default surface and for named
surfaces, then explicitly boot those definitions when needed.

```php
return [
	'dataphyre'=>[
		'panel'=>[
			'providers'=>[
				CoreOperationsPanelProvider::class,
			],
			'plugins'=>[
				AuditTrailPanelPlugin::class=>[
					'show_timeline'=>true,
				],
			],
			'surfaces'=>[
				'seller'=>[
					'panel_label'=>'Seller Console',
					'url_builder'=>'seller_console_url',
					'providers'=>[
						CommercePanelProvider::class,
					],
					'plugins'=>[
						AuditTrailPanelPlugin::class,
					],
				],
			],
		],
	],
];

Panel::bootConfigured();
```

## Resource Definitions

Resources describe the application object, its form, its table view, and the
actions users can take.

```php
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelHost;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelProvider;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelResponseEmitter;
use Dataphyre\Panel\Resource;

Panel::register(
	Panel::resource('projects')
		->label('Project')
		->pluralLabel('Projects')
		->table('datadoc.projects')
		->group('Documentation')
		->icon('folder')
		->navigationDescription('Project docs and source roots')
		->navigationBadge(fn() => ProjectRepository::pendingCount())
		->navigationBadgeTone('warning')
		->recordKeyUsing('name')
		->recordTitleUsing('title')
		->recordSubtitleUsing('path')
		->formColumns(2)
		->fields([
			Panel::field('name')->required()->section('Identity'),
			Panel::field('title')->required()->section('Identity'),
			Panel::field('path')->required()->placeholder('C:/path/to/project')->section('Storage')->columnSpan('full'),
		])
		->columns([
			Panel::column('title')->sortable()->searchable(),
			Panel::column('name')->sortable(),
			Panel::column('path')->searchable(),
			Panel::column('updated_at')->datetime()->hiddenByDefault(),
			Panel::column('id')->toggleable(false),
			Panel::column('created_at')->datetime('Y-m-d H:i'),
			Panel::column('total')->money('CAD'),
		])
		->globalSearchable()
		->globalSearchColumns(['title', 'name', 'path'])
		->views([
			Panel::view('drafts')
				->label('Drafts')
				->tone('warning')
				->where(fn($record) => ($record['status'] ?? null)==='draft'),
			Panel::view('published')
				->label('Published')
				->tone('success')
				->where(fn($record) => ($record['status'] ?? null)==='published'),
		])
		->filters([
			Panel::filter('status', 'select')
				->options(['draft'=>'Draft', 'published'=>'Published']),
			Panel::filter('enabled', 'boolean'),
		])
		->summaries([
			Panel::summary('projects')->count(),
			Panel::summary('gross_total')->sum('total')->money('CAD'),
		])
		->defaultSort('created_at', 'desc')
		->perPageOptions([10, 25, 50, 100])
		->perPage(25)
		->relation(
			Panel::relation('documents')
				->label('Documents')
				->columns([
					Panel::column('title')->searchable(),
					Panel::column('updated_at', 'datetime'),
				])
				->queryUsing(fn($project) => ProjectDocumentRepository::forProject($project))
		)
		->action(
			Panel::action('scan')
				->label('Scan')
				->tone('primary')
		)
);

Panel::registerPage(
	Panel::page('imports')
		->label('Imports')
		->group('Operations')
		->icon('upload')
		->navigationDescription('Review pending imports')
		->navigationBadge('New')
		->navigationBadgeTone('info')
		->renderUsing(function(PanelRequest $request){
			return '<section><h2>Import queue</h2><p>Review pending imports before they touch production data.</p></section>';
		})
		->widget(
			Panel::stat('pending_imports', fn() => ImportRepository::pendingCount())
				->label('Pending imports')
				->tone('warning')
		)
		->table(
			Panel::pageTable('recent_imports')
				->label('Recent imports')
				->description('Latest supplier files and their current status')
				->columns([
					Panel::column('filename'),
					Panel::column('status')->badge([
						'completed'=>'success',
						'failed'=>'danger',
						'pending'=>'warning',
					]),
					Panel::column('created_at')->datetime(),
				])
				->recordsUsing(fn() => ImportRepository::recent(10))
		)
		->action(
			Panel::action('refresh')
				->label('Refresh queue')
				->tone('primary')
				->handle(fn() => ['message'=>'Import queue refreshed.'])
		)
);
```

## Current Surface

- `Panel::resource()` creates a `Resource`.
- `Panel::make()` creates an isolated panel instance with its own manager and
  configuration context.
- `Panel::surface()` retrieves or creates a named surface in the process-local
  panel registry.
- `Panel::registerSurface()` stores an existing panel instance in the named
  registry.
- `Panel::surfaces()` returns registered panel instances keyed by surface name.
- `Panel::bootConfigured()` applies configured providers to the default surface
  and configured named surfaces.
- `Panel::default()` creates an instance wrapper around the process-local default
  panel manager.
- `Panel::configure()` creates an isolated panel instance with only configuration
  overrides.
- `Panel::provide()` applies a provider to the default panel manager.
- `Panel::host()` creates a host adapter for dispatching or emitting a chosen
  surface.
- `Panel::theme()` creates or returns the default theme definition.
- `Panel::palette()` expands a named palette or hex color into Panel theme
  shades.
- `Panel::themePreset()` returns a reusable theme preset definition.
- `Panel::registerThemePreset()` adds a reusable preset recipe.
- `Panel::registerTheme()`, `Panel::namedTheme()`, and `Panel::loadThemes()`
  add and retrieve complete named themes from packages or app directories.
- `Panel::loadThemePresets()` is retained as an alias for preset/theme package
  loading.
- `Panel::page()` creates a custom Panel page definition.
- `Panel::navigationItem()` creates a first-class navigation entry for a
  host-owned URL or external destination.
- `Panel::nav()` is a short alias for `Panel::navigationItem()`.
- `Panel::field()` creates a form field definition.
- `Panel::schema()` creates a form or infolist schema.
- `Panel::schemaTab()` creates a tab component for schemas.
- `Panel::schemaStep()` creates a wizard step component for schemas.
- `Panel::column()` creates a table column definition.
- `Panel::pageTable()` creates a focused table section for a custom page.
- `Panel::pageFilter()` creates a table filter intended for a page table.
- `Panel::view()` creates a generated table view definition.
- `Panel::filter()` creates a table filter definition.
- `Panel::summary()` creates a generated table summary definition.
- `Panel::action()` creates an action definition.
- `Panel::actionGroup()` creates a dropdown group for resource, record, bulk,
  or page actions.
- `Panel::relation()` creates a relation manager definition.
- `Panel::widget()` creates a dashboard widget definition.
- `Panel::pageWidget()` is an alias for widget definitions intended for custom
  pages.
- `Panel::stat()` creates a dashboard stat widget definition.
- `Panel::notify()` creates a notification payload.
- `Panel::notificationInbox()` creates an adapter-ready notification inbox
  manifest.
- `Panel::inboxNotification()` promotes a toast payload, array, or string into a
  durable notification record.
- `Panel::register()` stores a resource in the process-local default panel
  manager.
- `Panel::registerPage()` stores a custom page in the process-local panel
  manager.
- `Panel::registerWidget()` stores a widget in the process-local panel manager.
- `Panel::registerNavigationItem()` stores a host-owned navigation entry in the
  process-local panel manager.
- `Panel::registerNavigationItems()` stores multiple host-owned navigation
  entries.
- `Panel::registerCommand()` stores a server-owned command palette entry.
- `Panel::registerCommands()` stores multiple server-owned command palette
  entries.
- `Panel::navigationLayout()` sets the default surface navigation chrome to
  `sidebar`, `horizontal`, or `none`.
- `Panel::navigationMode()`, `Panel::headerMode()`, and `Panel::footerMode()`
  choose whether Panel chrome floats, docks, attaches to the edge, or overlays.
- `Panel::contentSpacing()` controls the page spacing density with `normal`,
  `compact`, or `flush`.
- `Panel::customPageLayout()` controls direct custom-page section treatment with
  `carded` or `flow`.

## Lightweight Page Templates

`PanelPageTemplate` renders structured custom-page sections such as `stats`,
`action_list`, `description_list`, `color_swatches`, `table`, `form`, `notice`, `chat`, `confidential_fields`, and `document_content` into
escaped Panel HTML. Table rows accept scalar cell values for plain text and
`actions` or `form_actions` descriptors for row actions. POST action cells emit
the MVC session CSRF token by default and also include any configured
`hidden_fields`, either as keyed scalar values or `{name, value}` rows, so native
custom pages can submit record identifiers without raw HTML. Empty array entries
are ignored, which lets custom pages include optional sections without rendering
blank fallback cards when the condition is not active.

`description_list` renders label/value/detail facts as carded rows with explicit
block flow for labels, values, and help text. Labels and values wrap inside their
item instead of relying on inline browser flow, so compact document metadata,
settings summaries, and profile facts remain readable in light, dark, desktop,
and mobile layouts.

`color_swatches` renders labeled palette values as accessible swatch rows. Items
accept `label`, `value`, and optional `detail`; valid shorthand and six-digit hex
values are normalized before being used as CSS custom properties, and invalid
values render as text-only rows. Use this for brand, theme, status, or design
token previews when a visual color sample is more useful than raw hex text.

`record_list` renders title/subtitle/value summaries as stacked record rows.
The row title and subtitle are block-level text inside the primary column, and
the optional value renders as a compact status badge. Rows wrap on mobile and
keep long labels inside the row instead of collapsing adjacent strings together.

`confidential_fields` renders auditable disclosure rows for privacy-sensitive
values. Each item includes `label`, `placeholder`, optional `value`,
`revealed`, optional `meta`, and an optional `action` descriptor. Hidden rows
show the placeholder and a CSRF-protected access request action; revealed rows
show the supplied value. An action may include template `fields` for purpose or
reason capture before submit, plus `hidden_fields` for stable identifiers. The
template does not decide policy or write audit logs; the owning Panel or MVC
handler performs those checks before setting `revealed=true`.

`chat` renders a two-pane messaging workspace with a conversation rail, active
thread header, message bubbles, realtime state placeholder, scrollable message
area, and sticky composer. Native POST composer submits are treated as intentional
navigation, so the dirty-form guard clears before the browser unloads instead of
warning the operator that the message body is unsaved.

Form sections accept `compact=>true` for utility forms that should sit beside
their heading on desktop and collapse back to normal single-column flow on
mobile. Compact forms keep the same CSRF, hidden-field, validation, and submit
lifecycle behavior as standard template forms.
- `Panel::navigationFeatures()` configures optional sidebar search, recent
  navigation, and pinning controls.
- `Panel::authorize()` registers a gate for the default generated Panel surface.
- `Panel::accessPermissions()` / `Panel::permissions()` registers the optional
  Dataphyre Permission bridge for semantic resource, action, and relation
  checks.
- `Panel::permissionAdmin()` registers the optional Permission roles,
  assignments, and catalog resources when the Permission module is installed.
- `Panel::navigation()` returns the visible navigation entries for a request.
- `Panel::navigationState()` returns the normalized navigation, grouping,
  active-entry, and search discovery snapshot for a request.
- `Panel::commands()` returns the generated command entries for a request.
- `Panel::commandState()` returns the normalized command palette snapshot for a
  request.
- `Panel::describe()` returns a serializable resource and navigation manifest.
- `Panel::render()` renders a resource page without reading the current request.
- `Panel::dispatch()` handles a captured or explicit `PanelRequest`.
- `Panel::globalSearch()` searches registered opt-in resources.
- `Panel::trace()` and `Panel::traceSummary()` expose lifecycle events for
  Flightdeck and test assertions.
- `Panel::accessibilityAudit()` runs a route-free baseline accessibility audit
  over generated Panel HTML.
- `Panel::regressionSuite()` creates a named route-free regression suite with
  manifest-backed check results.
- `Panel::documentationCatalog()` creates a structured API reference and
  cookbook catalog.
- `Panel::documentationEntry()` creates a single categorized documentation
  entry with status, API references, examples, links, tags, and metadata.
- `Panel::localization()` gets or configures the default panel localization
  catalogue; `PanelInstance::localization()` does the same for a named surface.
- `Panel::trans()` / `Panel::t()` translate a scoped key with interpolation
  through the default surface catalogue.
- `Panel::packageManifest()` creates a package manifest for a plugin, theme,
  adapter, or package array.
- `Panel::compatibilityMatrix()` evaluates package requirements against a
  runtime snapshot.
- `Panel::packageTemplate()` creates a package starter artifact manifest without
  writing files by default.
- `Panel::packageRepository()` discovers package manifests and emits a
  deterministic package lock.
- `Panel::packageTrustPolicy()` creates a host trust policy for package
  signatures, publishers, keys, statuses, and revocations.
- `Panel::packageInstallPlan()` creates an installer plan and can apply package
  template artifacts with dry-run, overwrite, backup, and blocked-file metadata.
- `Panel::packageRollbackPlan()` creates a dry-run rollback plan from an install
  plan or a concrete package apply result.

The first implementation focuses on resource metadata, authorization hooks,
query factories, save handlers, action handlers, and generated HTML backed by the
Templating framework when the templating kernel is loaded.

Generated pages include breadcrumbs derived from the current dashboard, resource,
record, relation, action, or custom page. The same trail is exposed as
`breadcrumbs` in `PanelPageResult::data()` for custom emitters and tests.

## Themes

Themes are route-free panel configuration. A theme belongs to the active panel
surface or to the default panel manager; the renderer reads it from
`PanelContext` and emits CSS variables, optional external stylesheets, favicon,
brand assets, and dark-mode metadata with the response.

```php
Panel::theme('operations')
	->preset('flat_minima')
	->colors([
		'primary'=>'#2563eb',
		'success'=>'emerald',
		'warning'=>'amber',
		'danger'=>'rose',
		'info'=>'sky',
		'gray'=>'zinc',
	])
	->font('Inter')
	->darkMode()
	->defaultMode('system')
	->darkBody('#0f172a')
	->darkSurface('#1e293b', '#111827')
	->darkText('#f8fafc', '#cbd5e1', '#94a3b8')
	->maxWidth('1440px')
	->panelPadding('32px')
	->controlPadding('10px 14px')
	->brandName('Operations')
	->brandLogo('/assets/panel/logo.svg')
	->darkModeBrandLogo('/assets/panel/logo-dark.svg')
	->brandLogoHeight('2rem')
	->favicon('/favicon.ico')
	->assetRoot('ops', '/assets/panel')
	->css('/assets/panel/operations.css')
	->stylesheet('ops::forms.css', 'forms', ['media'=>'screen']);
```

Isolated surfaces can carry their own theme without affecting another panel:

```php
$support=Panel::make('support')
	->label('Support')
	->useTheme(
		Panel::themePreset('glass')
			->colors(['primary'=>'teal'])
			->font('Inter')
	);
```

Colors are registered as semantic palettes rather than one-off component
values. The built-in semantic colors are `primary`, `success`, `warning`,
`danger`, `info`, and `gray`. A color may be a named palette, a hex color that
Dataphyre expands into shades, or an explicit shade map using `50`, `100`,
`200`, `300`, `400`, `500`, `600`, `700`, `800`, `900`, and `950`.

`Panel::palette()` returns the same generated shade map for reuse in config or
tests:

```php
Panel::theme()->colors([
	'primary'=>Panel::palette('#2563eb'),
]);
```

Presets are composable recipes. Built-in presets include `flat_minima`, `glass`,
and `brutalist`. A preset can be converted into a theme, applied to an existing
theme, or serialized as a manifest:

```php
$theme=Panel::themePreset('flat_minima')->toTheme('operations');

Panel::theme()
	->preset('flat_minima')
	->colors(['primary'=>'blue']);

Panel::theme('studio')
	->preset('glass')
	->colors(['primary'=>'sky']);

Panel::theme('warehouse')
	->preset('brutalist')
	->colors(['primary'=>'blue']);

Panel::configure([
	'theme'=>[
		'name'=>'ops',
		'presets'=>['flat_minima'],
		'colors'=>['primary'=>'blue'],
	],
]);

$manifest=Panel::theme()->manifest();
$css=Panel::theme()->toCss();
Panel::theme()->exportTo(__DIR__.'/build/panel', 'operations');
```

`manifest()`, `toJson()`, and `toCss()` expose the generated tokens for package
installers, tests, and deployment builds. `writeManifest()`, `writeCss()`, and
`exportTo()` write the same artifacts to disk without requiring a renderer or
route context.

Packages and apps can publish preset and theme files and load them before a
panel is rendered. JSON files may contain a single preset, a single typed theme,
a list of presets, a `presets` array, a `themes` array, or both arrays. PHP
files should return a preset, theme, array definition, or list of definitions:

```php
Panel::loadThemes(__DIR__.'/panel-themes');

Panel::registerThemePreset([
	'name'=>'merchant_ops',
	'colors'=>['primary'=>'emerald'],
	'tokens'=>['radius'=>'7px'],
]);

Panel::registerTheme([
	'type'=>'theme',
	'name'=>'merchant_full',
	'presets'=>['flat_minima'],
	'colors'=>['primary'=>'emerald'],
	'dark_tokens'=>['body_bg'=>'#052e2b'],
]);

Panel::registerTheme([
	'type'=>'theme',
	'name'=>'merchant_audit',
	'extends'=>'merchant_full',
	'tokens'=>['max_width'=>'1600px'],
	'colors'=>['warning'=>'orange'],
]);

Panel::theme()->preset('merchant_ops');
Panel::theme('merchant_audit');

$dense_review=Panel::themeVariant('dense_review', [
	'tokens'=>[
		'max_width'=>'1500px',
		'table_cell_padding'=>'7px 9px',
	],
	'colors'=>['primary'=>'indigo'],
]);

Panel::themeLibrary()->exportTo(__DIR__.'/build/panel-themes');

$diagnostics=Panel::themeDiagnostics();
$preview=Panel::themePreview('merchant_audit');
$preview_html=Panel::themePreviewHtml('merchant_audit');
```

`extends`, `base_theme`, and `base` may reference a registered complete theme,
a preset name, a theme definition, or a list of those values. Bases are applied
first, then local roots, presets, colors, tokens, assets, and brand options are
applied as normal overrides. Theme packages are resolved lazily, so a theme may
extend another theme that appears later in the same manifest. Cyclic references
are skipped during resolution.

`copy()`, `with()`, and `variant()` create derived themes without mutating the
registered theme package. `Panel::themeVariant()` derives from the active panel
theme, which is useful for a surface that needs denser tables, a wider canvas,
or a temporary color adjustment while keeping the shared theme unchanged.

## Schemas

Panel schemas are route-free component trees. They give forms, action forms,
filters, infolists, and custom page regions a shared foundation without binding
the framework to an `/admin` URL or a single renderer.

```php
$product_schema=Panel::schema()
	->columns(2)
	->section(
		Panel::section('Basics')->description('Core catalogue details.'),
		[
			Panel::field('title')->required()->columnSpan('full'),
			Panel::field('sku')->required(),
			Panel::field('status', 'select')->options([
				'draft'=>'Draft',
				'active'=>'Active',
			]),
		]
	)
	->section('Pricing', [
		Panel::field('price', 'decimal')->required()->rules('min:0'),
		Panel::field('compare_at_price', 'decimal')->rules('min:0'),
	])
	->tab('Publishing', [
		Panel::schemaSection(
			Panel::section('Visibility')->description('Where this record appears.'),
			[
				Panel::field('published_at', 'datetime'),
			]
		),
	])
	->step('Review', [
		Panel::field('review_note', 'textarea')->columnSpan('full'),
	]);

Panel::resource('products')
	->schema($product_schema)
	->infolist(Panel::infolist()
		->columns(3)
		->section('Snapshot', [
			Panel::entry('title')->copyable()->columnSpan('full'),
			Panel::entry('status', 'select')->badge([
				'draft'=>'warning',
				'active'=>'success',
			])->options([
				'draft'=>'Draft',
				'active'=>'Active',
			]),
			Panel::entry('price', 'decimal')->prefix('CAD ')->icon('wallet'),
		])
	)
	->bulkSchema(Panel::schema([
		Panel::field('status', 'select')->options(['draft'=>'Draft', 'active'=>'Active']),
	]));

Panel::action('approve')
	->schema(Panel::schema([
		Panel::field('note', 'textarea')->columnSpan('full'),
	]));
```

`ResourceForm::schema()` converts existing form fields and sections into a
schema without losing field callbacks. Passing a schema back into a form replaces
the form's fields, sections, metadata, and column count. This lets older
`fields()` code and newer schema code coexist while the shared schema engine
expands.

`Panel::infolist()` returns a first-class `Infolist` builder for read-only
record presentation. It compiles to the shared schema engine, so show pages,
modals, manifests, tests, and Flightdeck can inspect the same entry tree without
requiring the renderer to be the only source of truth. Resource show pages use
the resource infolist when one is defined and fall back to the form schema when
it is not, so existing resources keep working while richer record views can
diverge from edit forms.

`Panel::entry()`, `Panel::textEntry()`, `Panel::badgeEntry()`, and
`Panel::imageEntry()` create `InfolistEntry` objects. Entries are the read-only
companions to fields: they can use the same labels, options, visibility rules,
display callbacks, sections, tabs, steps, and responsive grid spans as form
fields, while adding record-display presentation helpers:

```php
Panel::entry('order_number')->label('Order')->copyable()->icon('hash');
Panel::entry('status')->badge(['paid'=>'success', 'review'=>'warning']);
Panel::entry('total', 'decimal')->prefix('CAD ')->icon('wallet');
Panel::entry('notes')->emptyLabel('No notes yet')->description('Internal team context.');
```

The fluent builder can be used without route or resource assumptions:

```php
$infolist=Panel::infolist()
	->section('Identity', [
		Panel::textEntry('number')->label('Order')->copyable()->icon('hash'),
		Panel::textEntry('customer')->icon('user'),
	])
	->section('Operations', [
		Panel::badgeEntry('status', ['review'=>'warning', 'paid'=>'primary']),
		Panel::badgeEntry('risk', ['low'=>'success', 'critical'=>'danger']),
	])
	->columns(['default'=>1, 'md'=>6, 'xl'=>12]);

Panel::resource('orders')->infolist($infolist);
```

Generated show pages render badge entries with theme tones, boolean entries as
state pills, email and URL entries as links, image entries as media previews,
repeaters as compact lists, and copyable entries with a one-click clipboard
control. `displayUsing()` still owns the resolved value, so resources can keep
formatting logic close to the schema without making the show page route-aware.

`Resource::infolistState()` returns the same server-owned snapshot used by the
generated show page:

```php
$state=$resource->infolistState($record, $request);

foreach($state->visibleSections() as $section => $entries){
	foreach($entries as $entry){
		$name=$entry['name'];
		$display=$entry['display'];
	}
}
```

`PanelInfolistState` carries the infolist schema, visible entries, sections,
record identity, raw values, display values, entry metadata, and copy/media/badge
hints. This makes detail surfaces reusable by custom pages, modals, tests, and
Flightdeck without asking the renderer to be the only source of truth.

`Schema::tab()` and `Panel::schemaTab()` group fields or sections into generated
tabs. Tabs are preserved when a schema is flattened into a resource form by
carrying tab metadata on the resulting fields and sections, so the same schema
continues to work for action forms, record forms, and infolists.

`Schema::step()` and `Panel::schemaStep()` create wizard-style steps. Steps are
still a single form submission: inactive step controls are temporarily disabled
for browser validation and re-enabled at submit time, while server-side form
validation remains the source of truth.

`themeDiagnostics()` reports loaded theme and preset names, unresolved base
references, missing preset references, inheritance cycles, and contrast checks
for light and dark surfaces. The lower-level
`Panel::themeLibrary()->isValid()` helper returns `true` when no blocking theme
package issues or contrast failures were found.

`themePreview()` returns route-free preview data for the active or named theme:
semantic swatches, light and dark mode tokens, sample surface/action colors,
stylesheet assets, brand assets, and contrast results. `Panel::themeLibrary()
->preview()` returns previews for every registered complete theme.
`themePreviewHtml()` returns the same theme preview as a reusable HTML fragment
with scoped preview CSS. It is a building block for any panel surface, not a
route or admin page.

When dark mode is enabled, generated pages include a compact Light, Dark, and
System mode control. The selected mode is applied immediately, stored in
`localStorage`, and mirrored into a `dataphyre_panel_theme_mode` cookie so the
next server render starts in the same mode. Disable the control with
`->modeToggle(false)` while still keeping a fixed `->defaultMode('dark')` or
`->defaultMode('light')`.

Hosts that expose multiple panel presets can add a route-safe selector beside
the generated mode control. Use an app-specific query parameter such as
`panel_theme` rather than `theme` when the host application already uses
`theme` for its own runtime skin:

```php
Panel::make('operations', [
	'theme_selector'=>true,
	'theme_selector_parameter'=>'panel_theme',
	'theme_selector_presets'=>[
		''=>'Flat Minima',
		'flat_minima'=>'Flat Minima',
		'glass'=>'Glass',
		'brutalist'=>'Brutalist',
	],
]);
```

When a selector is enabled, generated panel URLs preserve the active preset and
the browser stores it in `dataphyre_panel_theme_preset`. This keeps resource,
page, modal, and dashboard navigation visually stable while still allowing a
shared URL such as `?panel_theme=glass`.

Dark mode uses `dark_tokens` as overrides on top of the normal theme tokens.
Partial definitions inherit the flat minimal default dark surface:

```php
Panel::theme()
	->darkTokens([
		'body_bg'=>'#09090b',
		'surface'=>'#18181b',
		'surface_muted'=>'#111113',
		'text'=>'#fafafa',
		'border'=>'#3f3f46',
	])
	->darkToken('control_bg', '#09090b');
```

Generated CSS exposes theme tokens as variables such as `--dp-primary-600`,
`--dp-success-100`, `--dp-surface`, `--dp-text`, `--dp-radius`,
`--dp-max_width`, `--dp-panel_padding`, `--dp-control_padding`,
`--dp-input_padding`, `--dp-table_cell_padding`, and `--dp-gap`. Panel core
components use semantic tokens, and custom CSS is loaded after the core
stylesheet so app-specific styling can stay small.

Custom page CSS should prefer semantic tokens, but it must also tolerate partial
themes and app presets that only define color scales in one mode. When a custom
page owns a large bespoke surface, bridge Panel tokens into page-local variables
with explicit light fallbacks, then consume those local variables throughout the
page CSS:

```css
.ops-schedule {
	--ops-primary: var(--dp-primary-600, #2563eb);
	--ops-surface: var(--dp-surface, #fff);
	--ops-surface-muted: var(--dp-surface_muted, #f8fafc);
	--ops-text: var(--dp-text, #101828);
	--ops-text-muted: var(--dp-text_muted, #667085);
	--ops-border: var(--dp-border, #d7dee8);
	--ops-border-soft: var(--dp-border_soft, #e7ecf2);
}

.ops-schedule-card {
	background: var(--ops-surface);
	border: 1px solid var(--ops-border);
	color: var(--ops-text);
}
```

Avoid writing custom pages against aliases that may only be defined by dark
tokens or by one preset. In particular, do not rely on a global semantic token
unless the page has a fallback path for light mode. After custom CSS changes,
verify both light and dark modes with browser screenshots at the breakpoints the
surface can realistically hit; route-free Panel regressions are useful, but they
do not prove token contrast or responsive overflow.

The `flat_minima` preset sets `theme_effects` to `flat_minima`. It is the
default panel skin and keeps the renderer close to modern Tailwind/Filament
admin interfaces: flat surfaces, subtle borders, compact radius, minimal
shadows, and plain white or slate backgrounds.

The `brutalist` preset sets `theme_effects` to `brutalist`. It keeps panel
behavior unchanged while rendering hard-edged surfaces, heavy borders, flat
colors, and near-zero rounding. The preset exposes `brutalist_shadow`,
`brutalist_shadow_soft`, and `brutalist_focus` tokens for hosts that want to
soften or exaggerate the blocky treatment without replacing the renderer.

The `glass` preset is the first built-in effect theme. It sets
`theme_effects` to `glass`, which adds translucent surfaces, blurred chrome,
soft depth, and fallback behavior for browsers without `backdrop-filter`.
Effect styling still uses tokens, so hosts can keep the glass renderer behavior
while changing the material. The renderer recognizes separate glass layers for
main surfaces, stronger chrome, muted nested panels, controls, menus, overlays,
highlights, and lifted shadows:

```php
Panel::theme('frosted_ops')
	->preset('glass')
	->tokens([
		'glass_surface_bg'=>'linear-gradient(135deg,rgba(255,255,255,.80),rgba(255,255,255,.44))',
		'glass_control_bg'=>'linear-gradient(135deg,rgba(255,255,255,.78),rgba(255,255,255,.48))',
		'glass_menu_bg'=>'linear-gradient(135deg,rgba(255,255,255,.92),rgba(255,255,255,.68))',
		'glass_overlay_bg'=>'rgba(15,23,42,.36)',
		'glass_noise_opacity'=>'.10',
		'glass_tone_strength'=>'14%',
		'glass_focus'=>'0 0 0 4px rgba(14,165,233,.18),0 18px 50px rgba(14,165,233,.13)',
		'glass_active_glow'=>'0 18px 42px rgba(14,165,233,.22)',
		'glass_shimmer'=>'linear-gradient(90deg,transparent,rgba(255,255,255,.46),transparent)',
		'glass_scroll_thumb'=>'rgba(14,165,233,.36)',
		'glass_scroll_track'=>'rgba(255,255,255,.24)',
		'glass_mobile_blur'=>'blur(14px) saturate(1.12)',
		'glass_blur'=>'blur(28px) saturate(1.35)',
		'glass_shadow'=>'0 28px 80px rgba(15,23,42,.16)',
		'glass_shadow_lifted'=>'0 38px 100px rgba(15,23,42,.20)',
	]);
```

Tone-aware components such as widgets, summaries, board lanes, notices, alerts,
and guidance panels automatically tint their frosted surface from the semantic
tone class. The same Glass pass also styles command palette entries, dropdown
menus, table focus/selection, tabs, steps, charts, toasts, and form focus rings.
Glass includes dedicated treatment for scrollbars, empty states, loading
shimmers, board drag/drop targets, active navigation glow, relation managers, and
a lower-blur mobile material so small screens stay responsive.

Spacing tokens have fluent helpers for common layout choices:

```php
Panel::theme()
	->maxWidth('1280px')
	->panelPadding('24px')
	->sectionPadding('14px')
	->controlPadding('8px 12px')
	->inputPadding('8px 10px')
	->tableCellPadding('8px 10px')
	->gap('10px');
```

Stylesheets may be simple URLs or named assets with attributes:

```php
Panel::theme()->css([
	'/vendor/acme/panel/base.css',
	[
		'name'=>'charts',
		'href'=>'/vendor/acme/panel/charts.css',
		'media'=>'screen',
		'integrity'=>'sha384-...',
		'crossorigin'=>'anonymous',
	],
]);
```

Asset roots let packages publish portable presets without knowing the host URL:

```php
Panel::registerThemePreset([
	'name'=>'acme_ops',
	'css'=>[
		'acme::base.css',
		['name'=>'charts', 'href'=>'acme::charts.css'],
	],
]);

Panel::theme()
	->assetRoot('acme', '/assets/vendor/acme-panel')
	->preset('acme_ops');
```

When a preset leaves an alias unresolved, the active theme resolves it using its
own roots as the preset is applied. That lets apps override package asset
locations without editing the preset file.

`cssAssets()` returns the stylesheet URLs for older integrations.
`stylesheetAssets()` returns the full asset manifest used by the renderer.

Resources with status transitions also expose a generated `board` operation. The
board groups records into columns generated from the transition statuses, keeps
the resource search and filters available, and renders row actions directly on
each card so operators can move records through the workflow without leaving the
board. Cards can also be dragged into another status column when the resource
defines a valid transition for that move; the drag action submits the same
generated transition form used by the card buttons.

Generated forms render common control types directly:

- `text`, `email`, `password`, `number`, `integer`, `float`, `decimal`
- `date`, `time`, `datetime`, `datetime_local`, `month`, and `week`
- `textarea`, `markdown`, `code`
- `select` and `enum`, or any field with `options()`
- `boolean`, `bool`, `checkbox`, and `toggle`
- hidden fields through `hidden()`

Field components expose reusable presentation metadata in schema manifests.
This lets renderers, Flightdeck, tests, and documentation know whether a field is
an input, choice, editor, upload, structure, boolean, or date/time control
without guessing from CSS classes.

```php
Panel::field('sku')
	->mask('AAA-999')
	->prependLabel('SKU')
	->appendButton('Upper', 'uppercase')
	->suggestions(['NS-100', 'NS-200'])
	->autocomplete('off');

Panel::field('customer')->titleCase();
Panel::field('internal_code')->uppercase();
Panel::field('release_notes')->sentenceCase();
Panel::field('integration_key')->snakeCase();
Panel::field('webhook_name')->kebabCase();
Panel::field('client_token')->camelCase();

Panel::field('tracking_code')
	->mask('AA-999999', true)
	->maskPlaceholder('AA-000000')
	->appendButton('Copy', 'copy');

Panel::field('total', 'number')
	->prependLabel('CAD')
	->format('currency', ['decimals'=>2, 'on'=>'blur']);

Panel::field('phone', 'tel')
	->appendButton('Clear', 'clear')
	->phone();

Panel::field('email')->email()->copyButton();
Panel::field('website')->url()->prependLabel('https://');
Panel::field('delivery_postal_code')->postalCodeCountryField('market');
Panel::field('tax_reference')->ein();
Panel::field('operator_ssn')->ssn();
Panel::field('compliance_ssn')->mask('999-99-9999', true);
Panel::field('follow_up_date', 'date')->todayButton();
Panel::field('handoff_at', 'datetime')->nowButton();
Panel::field('sample_reference')->setButton('Use sample', 'sample-value');
Panel::field('stock', 'number')->min(0)->step(5)->stepperButtons('-5', '+5');

Panel::field('price', 'number')->currency('CAD');
Panel::field('margin', 'number')->percent(1);
Panel::field('password', 'password')->passwordReveal();

Panel::field('status', 'select')
	->searchable()
	->native(false)
	->clearable();

Panel::field('description', 'markdown')
	->editor('markdown')
	->preview()
	->maxLength(2000);
```

The built-in renderer supports native controls, datalist suggestions, input
masks, preset formatting rules, searchable/custom-select hints, and previewable
editor surfaces. Text-like fields, selects, multi-selects, textareas, and
key/value textareas can declare `prependLabel()`, `appendLabel()`,
`prependButton()`, and `appendButton()` without custom templates. Built-in field
button actions include `clear`, `copy`, `toggle_password`, `today`, `now`,
`uppercase`, `lowercase`, `title_case`, `sentence_case`, `snake_case`,
`kebab_case`, `camel_case`, `digits`, `alpha`, `alphanumeric`, `slug`, and
`set`.
Existing `prefix()` and `suffix()` calls are treated as prepend and append
labels for text-like controls. `clearable()` automatically adds a clear button
to text-like controls, and password fields get a reveal button by default unless
`password_reveal` is disabled in field metadata.
Use `formatOn('blur')`, `formatOn('change')`, or the `on` format option when a
rule should wait until the operator leaves the field. Masks continue to apply
while typing so literal separators stay predictable.

Shortcut builders exist for common field shapes: `currency()`, `percent()`,
`phone()`, `email()`, `url()`, `mapUrl()` / `mapsUrl()`, `domain()` / `hostname()`,
`timezone()`, `locale()`, `json()` / `jsonText()`,
`mimeType()` / `contentType()`, `semver()` / `semanticVersion()`,
`cronExpression()` / `cron()`,
`languageCode()` / `isoLanguage()`,
`countryCode()` / `isoCountry()`, `subdivisionCode()` / `regionCode()`,
`currencyCode()` / `isoCurrency()`, `ipAddress()` /
`ip()`, `ipv4()`, `ipv6()`, `macAddress()` / `mac()`, `uuid()`, `ulid()`,
`hexColor()` / `colorHex()`, `latitude()`, `longitude()`, `coordinates()` /
`latLng()` / `lngLat()`, `postalCode()`, `postalCodeForCountry()`,
`postalCodeCountryField()`, `postalCodeSubdivisionField()`,
`postalCodeLocaleFields()`, `zipCode()` as a US compatibility alias, `ssn()`,
`ein()`, `oneTimeCode()` / `verificationCode()` /
`otp()` / `pinCode()`, `creditCard()`,
`creditCardExpiry()` / `cardExpiry()`, `cardCvc()` / `cvc()` / `cvv()`,
`iban()`, `slug()`, `slugFrom()`, `copyButton()`, `copyNormalizedButton()`,
`clearButton()`, `revealButton()`, `todayButton()`, `nowButton()`, `setButton()`,
`incrementButton()`, `decrementButton()`, `stepperButtons()`, `formatButton()`,
`uppercaseButton()`, `lowercaseButton()`, `titleCaseButton()`, and
`trimButton()`.
Stepper buttons use the field control's native `step`, `min`, and `max`
attributes when adjusting numeric values.
Use `copyable()` on form fields to auto-append a copy button, or
`copyableNormalized()` when the button should copy the same normalized value that
submit sends.
Text-normalization helpers include `uppercase()`, `lowercase()`, `titleCase()`,
`sentenceCase()`, `snakeCase()`, `kebabCase()`, `camelCase()`, `trimmed()`,
`digits()`, `alpha()`, and `alphanumeric()`.
Use `sourceField('title')` / `fromField('title')` after a formatter when the
formatted value should be generated from a sibling field until the operator
edits it manually. Edit forms also recognize preloaded values that still match
the source-derived value, so those fields continue following the source until
they diverge.
Use `characterCounter()` / `charCounter()` / `counter()` to add a live prepend
or append counter adornment; passing a maximum also applies `maxLength()`.
Use `autoResize()` / `autosize()` on textareas to grow the control as operators
type longer content.
Array field definitions accept the same common formatting and masking controls,
including `format_rule`, `format_options`, top-level `country_field` /
`subdivision_field`, `format_placeholder`, `mask_placeholder`,
`submit_unmasked`, `submit_formatted`, `character_counter`, `auto_resize`, and
`copyable`.

Formatted fields submit normalized values by default. For example phone and
credit-card, card-expiry, and CVC rules submit digits, currency and percent
rules submit decimal text, and postal code, IBAN, and alphanumeric rules submit
uppercase compact text. Email fields trim and lowercase text; URL fields trim
whitespace and add `https://` when an operator enters a domain without a scheme.
Domain fields accept pasted URLs and store only the lowercased hostname.
Timezone fields canonicalize common IANA casing, such as `america/toronto` to
`America/Toronto`, and validate against the runtime timezone catalog.
Locale fields normalize BCP-47-style tags, such as `en_ca` to `en-CA`, and
validate language, script, region, and variant shape.
JSON fields render as auto-resizing textareas, pretty-format valid JSON for the
operator, validate JSON syntax, and submit compact normalized JSON.
MIME type fields normalize case and parameter spacing, such as
`Application/JSON ; Charset = UTF-8` to `application/json; charset=utf-8`.
Semantic-version fields normalize optional `v` prefixes, such as `v1.2.3-beta`
to `1.2.3-beta`, and validate SemVer-style major/minor/patch text.
Cron expression fields normalize spacing and casing, and validate standard
five-field cron expressions including ranges, lists, steps, and month/day names.
Language code fields normalize common names and locale tags, such as `English`
or `en-CA` to `en`, and validate ISO-style two-letter language codes.
Country code fields normalize common names and alpha-3 aliases, such as
`Canada` or `CAN` to `CA`, and validate ISO alpha-2 codes.
Subdivision code fields normalize common province, state, and region names, such
as `Quebec` to `QC`, and can validate against a country with
`subdivisionCodeForCountry('CA')` or `subdivisionCodeCountryField('country')`.
Currency code fields normalize common names and symbols, such as `Canadian
dollar` to `CAD` or `€` to `EUR`, and validate ISO 4217 codes.
IP address fields trim text, lowercase IPv6 hex, and validate IPv4/IPv6
semantics with `ipAddress()`, `ipv4()`, or `ipv6()`.
MAC address fields normalize common pasted separators into uppercase
colon-separated pairs.
UUID fields normalize compact or braced UUIDs into lowercase hyphenated text and
validate UUID version and variant bits.
ULID fields normalize pasted lowercase or spaced values into uppercase compact
text and validate Crockford Base32 ULID syntax.
Hex color fields normalize shorthand or bare values into `#rrggbb` text and show
a live swatch adornment beside the input. Use `hideColorSwatch()` or
`colorSwatch(false)` to suppress the preview in dense forms.
Coordinate fields use native number controls, normalize decimal precision, and
validate latitude/longitude bounds.
Use `coordinates()` / `latLng()` for a single text field that stores normalized
`latitude,longitude`, or `lngLat()` to accept pasted longitude-first pairs and
store them in the same normalized order.
Map URL fields accept Google Maps URLs or coordinate pairs and normalize
coordinates into `https://www.google.com/maps?q=...` links.
Slug fields can use `slugFrom('title')` as a convenience wrapper around
`slug()->sourceField('title')`.
Use
`submitFormatted()` when the stored value should keep the visual punctuation, or
`submitNormalized(false)` to opt out explicitly. `copyNormalizedButton()` copies
the same normalized value that submit will send; `copyButton()` keeps copying the
visible field text unless called as `copyButton('Copy', true)`.
Server validation checks both generated patterns and field-specific semantics
where available, including credit-card Luhn checks, future card-expiry checks,
IBAN mod-97 checks, and IP address validation.
Common format rules also generate placeholder hints, such as `+1 000 000 0000`
for international phone fields and `00000-0000` for US postal fields, when no explicit
placeholder is set. Use `formatPlaceholder()` to override the generated hint or
`hideFormatPlaceholder()` to omit it. Phone, postal-code, and credit-card
formatters also receive native `pattern` and validation `title` attributes unless
an explicit `pattern()` or `title` metadata value is set. Formatting can follow
sibling locale fields with `phoneCountryField('market')`,
`postalCodeCountryField('country')`, `postalCodeLocaleFields('country', 'region')`, or the
lower-level `formatCountryField()` / `formatSubdivisionField()` helpers. The
browser refreshes generated placeholders, patterns, titles, input modes, and
country prepend labels when the source field changes; Canadian postal-code
patterns also narrow by province or territory when a subdivision source is
provided or inferred from an unambiguous subdivision-only source, US ZIP patterns
can narrow by known state prefixes, and GB/UK
postcodes use `SW1A 1AA` style spacing and validation. Australian postcodes use
four-digit formatting and can narrow by state or territory; New Zealand
postcodes use the same four-digit style and can narrow by region. EU market
forms can use the subdivision field as the country for FR, DE, NL, and IE postal
formats. Explicit placeholder, pattern, and title values are preserved.
International phone fields can use country/subdivision-aware calling-code
placeholders such as `+44` for GB, `+61` for AU, `+64` for NZ, and `+49` for
Germany; local trunk prefixes such as the leading `0` in `020...` are removed
when the international prefix is applied. The same
country/subdivision-aware rules run during server-side dehydration and
validation, so no-JS submits and visually formatted values are normalized and
checked against the effective locale rule too. When the `geoposition` module is
loaded, server validation also consults its SQL-backed postal-code regex and
reformatting rules, trying both local subdivision codes such as `ON` and
ISO-style codes such as `CA-ON`, before falling back to the panel's built-in
patterns. Browser formatting and server dehydration both scope repeater
child fields to their current row, so `postalCodeCountryField('country')` can live
inside repeated address rows. Text-based locale source fields also refresh
dependent formatters while typing, not only after select changes.

Masks use `9` for digits, `A` for uppercase letters, `a` for lowercase letters,
and `*` for alphanumeric characters. Masks keep their visual punctuation on
submit unless `mask($pattern, true)` or `submitUnmasked()` is used. Use
`submitMasked()` to make that choice explicit for SKU-style values where the
separators are part of the stored identifier. Masked fields receive a generated
placeholder such as `AAA-000` unless a normal placeholder is already set. Use
`maskPlaceholder('AA-000000')` to override it or `hideMaskPlaceholder()` to
disable the hint. Masked fields also receive native `maxlength`, `pattern`, and
validation `title` attributes derived from the mask unless `maxLength()`,
`pattern()`, or explicit `title` metadata is set. Enhanced fields intercept paste
so copied values such as `12 345
6789` can settle into the configured mask before browser length limits drop
meaningful characters. Formatting presets currently include
`phone`, `postal_code_ca`, `credit_card`, `iban`, `currency`, `percent`,
`zip_code_us`, `ssn`, `ein`, `email`, `url`, `digits`, `alpha`,
`alphanumeric`, `uppercase`, `lowercase`, `title_case`, `sentence_case`,
`snake_case`, `kebab_case`, `camel_case`, `trim`, and `slug`.
Apps can register custom browser-side behavior without patching Panel:

```js
window.DataphyrePanel
	.registerFieldFormatter('tracking_code', function(value) {
		return String(value || '').toUpperCase().replace(/[^A-Z0-9-]/g, '');
	})
	.registerFieldButton('normalize_tracking', function(input) {
		input.value=window.DataphyrePanel.fieldFormatters.tracking_code(input.value);
	});
```

Rich text, markdown, and code fields are real field types with manifests and
preview surfaces, but they are not yet full editor packages with toolbar
plugins, upload adapters, or syntax engines.

Select-like fields can use static options, option groups, or request-aware
dynamic options:

```php
Panel::field('status', 'select')
	->options([
		'open'=>'Open',
		'Closed states'=>[
			'resolved'=>'Resolved',
			'cancelled'=>'Cancelled',
		],
	]);

Panel::field('assignee_id', 'select')
	->optionsUsing(function($record, PanelRequest $request, string $operation){
		return UserRepository::panelAssignableOptions($request->user(), $record, $operation);
	});
```

Dynamic options are resolved when create/edit forms, action forms, and show pages
render. Show pages use the resolved option label for stored values.

Forms can be arranged into generated sections and responsive grids:

```php
$resource=$resource
	->formColumns(2)
	->formSections([
		Panel::formSection('identity')
			->label('Identity')
			->description('Public-facing profile details.')
			->columns(2),
		Panel::formSection('operations')
			->description('Internal workflow controls.')
			->collapsible(),
	])
	->fields([
		Panel::field('name')->section('Identity'),
		Panel::field('email')->section('Identity'),
		Panel::field('status')->section('Operations'),
		Panel::field('bio', 'textarea')->section('Operations')->columnSpan('full'),
	]);
```

`section()` groups fields under a heading. `columnSpan(2)` spans a field across
multiple grid columns, and `columnSpan('full')` spans the whole form row.
`formSection()` adds optional section metadata such as descriptions, per-section
column counts, and collapsible/collapsed behavior. Generated show pages use the
same field sections, form column count, section metadata, and column spans for
the read-only record view. Hidden fields are omitted from the show view.

`displayUsing()` customizes a field's read-only value on show pages without
changing form hydration, dehydration, validation, or save payloads:

```php
Panel::field('total', 'number')
	->displayUsing(fn($value) => 'CAD '.number_format((float)$value, 2));

Panel::field('name')
	->displayUsing(fn($value, $record) => $record['first_name'].' '.$record['last_name']);
```

Fields can be conditionally visible by operation. Invisible fields are not
rendered and are skipped during dehydration and validation:

```php
Panel::field('created_at')->onlyOn('show');
Panel::field('invite_message', 'textarea')->visibleOn('create');
Panel::field('internal_note')->hiddenOn('create', 'show');
Panel::field('approval_code')->visibleUsing(
	fn($operation, $record, $request) => $operation==='edit' && $request->user()?->can('approve')
);
```

Operations normalize `store` to `create`, `update` to `edit`, and `view` to
`show`.

Fields can also depend on other submitted values. Dependency rules are enforced
server-side during dehydration and validation, and generated forms include a
small browser-side refresher so dependent fields appear, disappear, or become
required as the operator changes the controlling value:

```php
Panel::field('requires_review', 'checkbox');

Panel::field('review_note', 'textarea')
	->visibleWhen('requires_review', true)
	->required();

Panel::field('close_reason', 'select')
	->visibleWhen('status', ['closed', 'cancelled'])
	->requiredWhen('status', ['closed', 'cancelled'])
	->options([
		'resolved'=>'Resolved',
		'duplicate'=>'Duplicate',
	]);
```

`hiddenWhen()` is the inverse visibility rule, and `dependsOn()` can be used to
expose a dependency without adding a visibility rule. `requiredWhen()` and
`requiredUnless()` make validation conditional on another submitted value and
update the browser `required` / `aria-required` state for generated forms.
Select and enum fields automatically validate that submitted values exist in
their current static or dynamic option list.

Reactive fields refresh through the server-owned form state model without
redrawing the form. Mark controlling fields with `live()` when they should
trigger updates, and use `optionsDependOn()` on a dynamic select to declare the
state it reads. The generated form posts the current form state to the field
state endpoint and applies only the affected field changes: visibility,
`required` / `aria-required`, dynamic `<select>` options, computed field values,
help text, placeholders, readonly state, and validation messages after live
fields lose focus.

```php
Panel::field('status', 'select')
	->options(OrderStatus::labels())
	->live();

Panel::field('next_step', 'select')
	->optionsUsing(fn($record, $request) => NextSteps::for(
		$request?->input('status', $record['status'] ?? null)
	))
	->optionsDependOn('status')
	->help('Updates when status changes without repainting the form.');
```

Fields can also compute their own browser state from the submitted form values:

```php
Panel::field('sla_minutes', 'number')
	->stateUsing(function($value, array $state){
		$suggested=($state['risk'] ?? null)==='critical' ? 30 : 180;

		return [
			'value'=>$value === null || $value === '' ? $suggested : $value,
			'placeholder'=>(string)$suggested,
			'help'=>'Suggested from the current risk signal.',
		];
	}, 'risk');
```

`stateUsing()` accepts the current value, all submitted field values, the record,
request, field, and operation. Return a scalar to update only the field value, or
an array with `value`, `help`, `placeholder`, `options`, `visible`, `required`,
`readonly`, `errors`, `set`, `fields`, `force_value`, or `propagate`. `set` and
`fields` accept sibling field patches, which gives source fields a `$set`-style
way to update related controls:

```php
Panel::field('risk', 'select')
	->options(Risk::labels())
	->live()
	->stateUsing(fn($value) => [
		'set'=>[
			'priority_handling'=>[
				'value'=>in_array($value, ['high', 'critical'], true) ? '1' : '0',
				'force_value'=>true,
			],
		],
	]);
```

`force_value` allows a server-computed value to replace the current focused
value, and `propagate` dispatches a browser `change` event when the computed
value changes.

The same resolver runs through the schema state snapshot used by dehydration,
validation, and live field updates. Browser reactivity is therefore a preview of
the submitted state, not a separate client-only layer: computed values and
sibling `set` patches are applied again when the form is saved.

Resources can also mutate validated form data before it reaches `saveUsing()`.
Use this for normalization and workflow defaults that belong to the resource,
not to a single field:

```php
Panel::resource('orders')
	->mutateFormDataUsing(fn(array $data) => [
		...$data,
		'email'=>strtolower(trim($data['email'] ?? '')),
	])
	->mutateCreateDataUsing(fn(array $data) => [
		...$data,
		'source'=>'panel',
	])
	->mutateUpdateDataUsing(fn(array $data) => [
		...$data,
		'updated_at'=>date('Y-m-d H:i:s'),
	]);
```

The global mutator runs first. Create and update mutators then run for matching
modes, including imports for create data and bulk updates/transitions for update
data. The mutated array is the array passed to `saveUsing()`.

Resource save lifecycles can wrap the generated form flow without moving that
workflow into `saveUsing()`:

```php
Panel::resource('orders')
	->beforeValidateUsing(fn($record, string $mode, PanelRequest $request) => Audit::touch($request))
	->afterValidateUsing(fn(PanelFormState $state) => $state)
	->beforeSaveUsing(fn(array $data) => [
		...$data,
		'sla_bucket'=>($data['sla_minutes'] ?? 999) <= 30 ? 'urgent' : 'standard',
	])
	->afterSaveUsing(function($result, array $data){
		$result['notifications'][]=[
			'tone'=>'success',
			'title'=>'Saved',
			'body'=>'SLA bucket: '.$data['sla_bucket'],
		];

		return $result;
	});
```

`beforeValidateUsing()` is called before generated validation. `afterValidateUsing()`
may return a replacement `PanelFormState`. `beforeSaveUsing()` may return a
replacement data array after mutation and before `saveUsing()`. `afterSaveUsing()`
may return a replacement save result, which lets resources attach notifications
or redirect metadata without changing persistence logic.

`PanelFormState` is immutable and includes helpers for lifecycle hooks:

```php
->afterValidateUsing(function(PanelFormState $state): PanelFormState {
	if($state->value('risk')==='critical' && trim($state->value('internal_note', ''))===''){
		return $state
			->withError('internal_note', 'Critical orders need an internal handling note.')
			->withMeta(['cross_field_validated'=>true]);
	}

	return $state;
})
```

Use `withValue()`, `withValues()`, `withError()`, `withErrors()`,
`withoutError()`, `withMeta()`, `only()`, and `except()` to return modified form
state without editing the state arrays in place.

Forms and schemas can also produce a full state snapshot without submitting:

```php
$state=$resource->form()->state(
	record: $order,
	request: $request,
	operation: 'edit',
	validate: true,
);

$state->values();            // hydrated current values
$state->initialValues();     // values before submitted input
$state->dehydratedValues();  // save-ready values
$state->dirtyFields();       // fields changed from initial values
$state->stateUpdates();      // computed live patches by field
$state->fieldState('status');
```

`Schema::state()` exposes the same snapshot for route-free schema trees. This is
the foundation for Filament-style form components: one server-owned state model
drives render, live updates, validation, dirty tracking, and action lifecycles.
Under the hood, forms and schemas both use `SchemaLifecycle`, so the same
primitive can be reused by resource forms, action forms, modal workflows,
Reactor islands, tests, or any host that needs form state without owning a Panel
route.

```php
$schema=Panel::schema([
	Panel::field('title')->required(),
	Panel::field('risk', 'select')
		->options(['low'=>'Low', 'critical'=>'Critical'])
		->live(),
	Panel::field('priority', 'range')
		->min(1)
		->max(5)
		->stateUsing(fn(array $values) => ($values['risk'] ?? null)==='critical' ? 5 : null, 'risk'),
]);

$lifecycle=Panel::schemaLifecycle($schema, [
	'surface'=>'seller_intake',
]);

$state=$lifecycle->state(
	record: $existing_record,
	request: $request,
	operation: 'action',
	input: ['risk'=>'critical'],
	validate: true,
);

$state->dirtyFields();
$state->dehydratedValues();
$lifecycle->describe('action');
```

`SchemaLifecycle::hydrate()`, `dehydrate()`, `validate()`, `submit()`, and
`state()` return `PanelFormState`. `SchemaLifecycle::describe()` returns a
structured field manifest with required, readonly, reactive, dependency,
hydration, dehydration, and metadata flags. The manifest is intended for
generated tools, Flightdeck introspection, tests, and non-Panel frontends that
need to understand a form before rendering or submitting it.

When a caller needs the full component tree instead of only fields, use
`Schema::manifest()`, `ResourceForm::manifest()`, or `Panel::schemaManifest()`.
The schema manifest preserves layout nodes, parent ids, component paths,
sections, field/component links, responsive columns, lifecycle field metadata,
and aggregate capabilities:

```php
$manifest=Panel::schemaManifest($schema, 'action');

$manifest['components'];   // flattened tab/step/section/field tree with paths
$manifest['fields'];       // lifecycle field descriptions linked to components
$manifest['sections'];     // generated form sections
$manifest['capabilities']; // layout, live state, validation, and hydration counts
```

Lifecycle hooks can also stop the generated save flow by returning a
`PanelLifecycleResult`:

```php
->beforeSaveUsing(function(array $data){
	if(($data['risk'] ?? null)==='critical' && ($data['market'] ?? null)==='EU'){
		return PanelLifecycleResult::halt(
			'EU critical orders need compliance intake before creation.',
			[PanelNotification::warning('Open compliance intake before saving this order.')]
		);
	}

	return $data;
})
```

`PanelLifecycleResult::halt()` renders a stopped-operation response with
notifications. `PanelLifecycleResult::redirect()` redirects with notifications.
Returning `false` from a lifecycle hook is treated as a generic halt for quick
guards, but explicit lifecycle results are preferred for user-facing flows.

Resources can also mutate the data used to fill generated forms:

```php
Panel::resource('orders')
	->mutateCreateFormDataBeforeFillUsing(fn(array $data) => [
		...$data,
		'market'=>$data['market'] ?? 'CA',
		'status'=>$data['status'] ?? 'review',
	])
	->mutateEditFormDataBeforeFillUsing(fn(array $data) => [
		...$data,
		'email'=>strtolower(trim($data['email'] ?? '')),
	]);
```

`mutateFormDataBeforeFillUsing()` runs for every generated resource form.
Create/edit-specific fill mutators run after it. Fill mutators receive the
hydrated state values, current record, mode, request, and resource. They are
display-time hooks only; submit-time normalization belongs in the save data
mutators.

Fill has its own lifecycle hooks:

```php
Panel::resource('orders')
	->beforeFillUsing(fn($record, string $mode, PanelRequest $request) => Audit::touch($request))
	->afterFillUsing(function(PanelFormState $state){
		return $state->withValue('internal_note', $state->value('internal_note', ''));
	});
```

`beforeFillUsing()` runs before form hydration. `afterFillUsing()` receives the
hydrated, fill-mutated state and may return a replacement `PanelFormState`.
Generated resource forms also resolve live state before `afterFillUsing()` runs,
so computed values and sibling `set` patches are already reflected on the first
render, not only after the first browser-side change.

`reactive()` is available when a field should participate in the live state
model even without dynamic options. `debounce(ms)` or `live(true, ms)` controls
the browser delay before dependent field-state requests are sent. The older
field-options endpoint remains available for lightweight option-only refreshes,
but generated forms use the richer state endpoint by default.

Live validation is scoped. When a generated form validates a field after focus
leaves it, the field-state endpoint returns validation results for that field
only, while still returning visibility and option state for dependent fields.
This prevents one touched required field from revealing every unrelated required
field on the screen. Generated forms also track changed fields locally and add a
subtle dirty state to the modified controls while keeping automatic live updates
paused until the form is saved, reset, or left.

Panel navigation uses the same dirty-state model. Internal links, same-panel
AJAX navigation, history navigation, and unsafe full-page links open a Panel
"Leave with unsaved changes?" dialog instead of the browser confirmation when
JavaScript is available. The browser `beforeunload` prompt is kept only as the
last-resort guard for closing or reloading the tab, where browsers do not allow
custom dialog content.
The Panel dialog uses an opaque, high-contrast surface in light mode and a solid
dark surface in dark/system-dark mode so warnings remain readable even when the
active theme uses flat, minimal, or glass effects.

## Resource Capabilities

Resources can define:

- labels and navigation entries
- grouped dashboard navigation through `group()`, ordered by resource `sort()`
- SQL table names, repository classes, or custom query factories
- form fields with type, rules, default values, hydration/dehydration callbacks,
  custom validation callbacks, options, help text, sections, column spans, and
  metadata
- table columns with sorting/searching flags and optional formatters
- table filters with select, text, boolean, date, or custom predicate matching
- actions with handlers, authorization callbacks, confirmation flags, tone, and
  bulk-action metadata
- relation managers with child tables, query hooks, authorization callbacks, and
  optional related-resource metadata
- dashboard widgets with lazy values, tones, icons, links, and descriptions

Authorization is explicit. A global gate can deny the generated Panel surface
before resources, widgets, or action handlers run:

```php
Panel::authorize(function(string $ability, ?Resource $resource, mixed $user, PanelRequest $request){
	return $user?->can('use_panel') === true;
});
```

The module config key `authorize` accepts the same callable, or a boolean for
simple environment-level enablement.

Panel can also delegate the global gate to Dataphyre Permission without coupling
the renderer to a concrete app user model:

```php
Panel::surface('admin')
	->auth()
	->permissions([
		'super_permission'=>'panel.*',
		'allow_guest_pages'=>['login', 'register'],
	]);
```

The same bridge can be enabled from `DP_PANEL_CFG`:

```php
[
	'permission'=>[
		'super_permission'=>'panel.*',
	],
]
```

Permission checks receive the Panel request context (`tenant`, `resource`,
`operation`, `record`, `action`, and `relation`) and use semantic names such as
`panel.orders.view_any`, `panel.orders.update`,
`panel.orders.action.review`, and `panel.orders.relation.items.view`. The bridge
uses Dataphyre Access as the subject source when no explicit Panel user is
present, so apps can opt in to Access login first and then layer Permission
policies over the same identity.

`Panel::panelManifest()` includes a `permission` section whenever the manifest is
built. It reports whether the Permission module is available, whether the bridge
is configured, the expected super permission, generated catalog rows, flat
permission names, counts by permission type, and examples. Tooling can use this
to show missing grants or generate role previews without registering the
Permission admin resources.

Each resource manifest also includes `permission.operations`,
`permission.actions`, `permission.relations`, and `permission.permissions` so
builders can display or validate the exact permission string for a resource
operation, action button, or relation operation in place.
Individual action manifests expose `permission.permission`, and relation
manifests expose `permission.operations.view` / `permission.operations.update`,
which lets tooling attach permission guidance directly to a button or relation
panel.
When the Permission bridge is enabled, generated action state and relation
access also consult those permission names, so unauthorized buttons and relation
panels can be hidden or forbidden before their handlers run.
Packages that need to compute the same names can use Panel's internal
`PanelPermissionBridge` helper, which centralizes the configured prefix,
resource normalization, action/relation permission naming, and optional
Dataphyre Permission checks.
Custom pages are permission-aware as well: `panel.reports.view` controls a page
named `reports`, page manifests expose `permission.operations.view`, and
`allow_guest_pages` keeps login/register style pages visible without a subject.

Per-request allow/deny snapshots are available when an app opts in:

```php
Panel::surface('admin')->permissions([
	'manifest_decisions'=>true,
]);
```

With that enabled, `permission.decision_snapshot` includes the current request
context, subject id, roles, raw rules, allowed permissions, denied permissions,
and a decision map for the generated catalog. Keep this disabled for public
manifests unless the caller is trusted.

To expose admin surfaces for roles, assignments, and the generated permission
catalog:

```php
Panel::surface('admin')->permissionAdmin();
```

Resource authorization runs after the global gate:

```php
$resource=$resource->authorize(function(string $ability, mixed $record, mixed $user){
	return $user?->can($ability) === true;
});
```

For Filament-style policy definitions, use `policy()`. Policies may be arrays,
objects, or class names. Array values can be booleans or callbacks:

```php
Panel::resource('orders')
	->policy([
		'viewAny'=>true,
		'view'=>true,
		'create'=>fn($record, $user) => $user?->can('create_orders') === true,
		'update'=>fn($record, $user) => ($record['status'] ?? '') !== 'shipped',
		'delete'=>fn($record) => ($record['status'] ?? '') === 'cancelled',
		'bulkUpdate'=>true,
		'export'=>true,
	]);
```

Policy methods use the same names: `viewAny()`, `view()`, `create()`,
`update()`, `delete()`, `forceDelete()`, `bulkUpdate()`, and so on. Ability
aliases are resolved automatically, so `index` maps to `viewAny`, `show` maps to
`view`, `edit` maps to `update`, and `store` maps to `create`. Scoped abilities
such as `transition:approve` try the exact ability first, then fall back to the
base ability. Generated navigation, create/edit links, export/import controls,
bulk actions, show pages, and save handlers all use the same policy result.

Tenant context is also route-agnostic. The active tenant key is read from the
configured tenant parameter, request input, `X-Dataphyre-Panel-Tenant`, or a
panel-level resolver. Generated URLs preserve the tenant key automatically:

```php
$panel=Panel::make('seller')
	->tenantParameter('store')
	->tenantResolver(fn(PanelRequest $request) => $request->tenantKey());

Panel::resource('orders')
	->tenantScoped('store_id')
	->queryUsing(fn(PanelRequest $request) => OrderRepository::query());
```

`tenantScoped('store_id')` applies the current tenant to array-backed resources
and query builders with a `where()` method. Use `tenantScoped('store_id', false)`
when the resource should show all records until a tenant is selected. For custom
storage, use `tenantUsing()` to resolve the tenant key per resource or
`tenantScopeUsing()` to apply the tenant to a repository/query object yourself.
The resolved tenant is exposed as `PanelRequest::tenantKey()` and included in
`PanelRequest::toArray()` for custom renderers, tests, action handlers, relation
queries, and widgets.

Actions can also have their own authorizer:

```php
$action=Panel::action('publish')
	->authorize(fn($record, $user) => $user?->can('publish') === true)
	->handle(fn($record) => publish_record($record));
```

Generated pages render non-bulk actions in the resource toolbar and beside each
record. Action targets are generated from the current panel URL builder and
submit with `POST` by default. Actions marked with `requiresConfirmation()` add a
Panel confirmation step. The generated button carries a confirmation marker, and
the server refuses to execute confirmed actions when that marker is missing. When
modal actions are enabled, confirmation renders in the Panel dialog instead of a
browser prompt.

Actions can define their own fields. When fields are present, the first action
request renders an action form. The confirmed form submission validates those
fields and passes their values to the handler:

```php
Panel::action('reject')
	->tone('danger')
	->field(Panel::field('reason', 'textarea')->required())
	->handle(function($record, array $data){
		return reject_listing($record, $data['reason']);
	});
```

Action fields use the same field controls, sections, validation, and visibility
rules as resource forms. Use `visibleOn('action')` or `visibleUsing()` for
action-specific field behavior.

## Utility Injection

Panel evaluates action, field, and column callbacks through the same utility
resolver. Existing positional callbacks continue to work, but callbacks can now
ask for utilities by parameter name or type:

```php
Panel::action('assign')
	->handle(function(array $data, Resource $resource, PanelRequest $request, Action $action){
		return [
			'resource'=>$resource->name(),
			'assignee'=>$data['assignee'] ?? null,
			'operation'=>$request->operation(),
			'action'=>$action->name(),
		];
	});
```

Common named utilities include `record`, `data`, `request`, `resource`,
`action`, `field`, `column`, `operation`, `mode`, `result`, `exception`,
`meta`, `get`, and `set`. Aliases such as `state`, `values`, `formData`,
`model`, `row`, `arguments`, `schemaGet`, and `schemaSet` map to the same
canonical utilities.

The `get` utility reads from submitted data first, then the record, then the
request input:

```php
Panel::field('next_step', 'select')
	->optionsUsing(function(callable $get){
		return NextSteps::for($get('status'));
	});

Panel::column('customer')
	->stateUsing(fn(callable $get) => trim($get('first_name', '').' '.$get('last_name', '')));
```

Standalone code can use the same resolver:

```php
Panel::evaluate(
	fn(PanelRequest $request, Resource $resource) => [$request->operation(), $resource->name()],
	['request'=>$request, 'resource'=>$resource]
);
```

Actions also have a lifecycle around the handler:

```php
Panel::action('approve')
	->mutateFormDataUsing(fn(array $data) => array_map('trim', $data))
	->afterValidateUsing(function(PanelFormState $state){
		return $state->value('reason')===''
			? $state->withError('reason', 'Add the approval reason.')
			: $state;
	})
	->beforeActionUsing(function($record, array $data){
		if(($record['status'] ?? '')!=='review'){
			return PanelLifecycleResult::halt('Only records in review can be approved.');
		}
		return null;
	})
	->handle(fn($record, array $data) => approve_record($record, $data))
	->afterActionUsing(function($result){
		$result['notifications'][]=PanelNotification::success('Approval recorded.');
		return $result;
	})
	->failure(fn(Throwable $error) => [
		'message'=>'Approval could not be completed.',
		'notification'=>PanelNotification::error($error->getMessage(), 'Action failed'),
	]);
```

`beforeValidateUsing()` runs before an action form is submitted.
`afterValidateUsing()` receives a `PanelFormState` and may return a replacement
state with extra field errors or a `PanelLifecycleResult`. `mutateFormDataUsing()`
runs after validation and before the action handler. `beforeActionUsing()` may
return `null` / `true` to continue, a normal action result to deliberately skip
the handler, or `PanelLifecycleResult::halt()` / `PanelLifecycleResult::redirect()`
to stop with first-class Panel lifecycle metadata. `afterActionUsing()` can
replace or enrich the handler result before redirects and notifications are
resolved. `failure()` hooks can convert thrown exceptions into normal action
results; unhandled exceptions render a Panel failure page and are recorded in the
Panel trace instead of breaking routing. The older `mutateDataUsing()`,
`before()`, and `after()` aliases remain available for compact actions.

Every generated action also owns a `PanelActionState` snapshot. The state is
route-free and follows the action from authorization through form display,
validation, mutation, execution, lifecycle halts, redirects, and failure pages.
It includes the action definition, mode (`action`, `bulk_action`, or
`page_action`), selected-record count, record key when available, current
`PanelFormState`, submitted data keys, lifecycle result, handler result, and a
small stage marker such as `form`, `validated`, `mutated`, or `completed`.

```php
$state=$action->state(
	record: $record,
	request: $request,
	resource: $resource,
	mode: 'action'
);

if($state->hasForm() && $state->valid()){
	$keys=array_keys($state->data());
}
```

Generated resource actions, bulk actions, and page actions expose this snapshot
in `PanelPageResult` data as `action_state` and record `action.state` /
`page_action.state` trace events. This gives Flightdeck, tests, and reactive
frontends the same server-owned lifecycle model that forms and tables use,
without coupling actions to a specific URL or controller.

Actions can also describe themselves before they render. `Action::manifest()`,
`ActionGroup::manifest()`, and `Panel::actionManifest()` produce a route-free
contract that combines resolved presentation, authorization/visibility state,
modal settings, schema manifest, lifecycle hooks, effects, shortcuts, and bulk
metadata:

```php
$manifest=Panel::actionManifest(
	Panel::action('assign')
		->slideOver('Assign owner')
		->schema(Panel::schema([
			Panel::field('owner')->required(),
			Panel::field('note', 'textarea'),
		]))
		->refresh(['widgets', 'table:orders'])
		->dispatchBrowserEvent('orders:assigned'),
	record: $order,
	request: $request,
	resource: $orders,
	mode: 'action'
);

$manifest['presentation'];  // label, icon, tone, badge, tooltip
$manifest['interaction'];   // modal, form, confirmation, shortcuts, bulk flags
$manifest['form'];          // full schema manifest for the action form
$manifest['effects'];       // refresh targets, browser events, modal control
$manifest['lifecycle'];     // hooks, mutators, handler and guard flags
```

This is the action equivalent of the schema manifest: a generated renderer,
Reactor island, Flightdeck panel, test, or package can inspect how an operation
behaves without scraping HTML or assuming a route.

Dashboard navigation is grouped automatically from resources, pages, and
navigation items. Entries without a group appear under `Workspace`; grouped
entries are ordered by their lowest `sort()` value and then by group label:

```php
Panel::resource('orders')->group('Commerce')->sort(10);
Panel::resource('products')->group('Commerce')->sort(20);
Panel::resource('pages')->group('Content')->sort(30);
```

Navigation cards can show short descriptions and badges. Badges may be static
values or lazy callbacks resolved when the dashboard is rendered:

```php
Panel::resource('orders')
	->navigationDescription('Fulfillment and exception queues')
	->navigationBadge(fn(PanelRequest $request, Resource $resource) => OrderRepository::openCount())
	->navigationBadgeTone('warning');

Panel::page('imports')
	->navigationDescription('Incoming supplier files')
	->navigationBadge('3 failed')
	->navigationBadgeTone('danger');
```

Host-owned navigation can be registered without creating a page or resource:

```php
Panel::registerNavigationItem(
	Panel::navigationItem('billing_portal')
		->label('Billing Portal')
		->group('Operations')
		->icon('credit-card')
		->url('/billing')
		->badge(fn(PanelRequest $request) => BillingRepository::openInvoiceCount($request->user()))
		->badgeTone('warning')
);

Panel::registerNavigationItem(
	Panel::nav('status_page')
		->label('Status Page')
		->url('https://status.example.com')
		->newTab()
		->visibleUsing(fn(PanelRequest $request) => $request->user()?->can('view_status') === true)
);
```

Submenus are first-class navigation state. A host can register folder-only
items that group pages and resources without creating a route:

```php
$commerce=Panel::nav('commerce_folder')
	->label('Commerce')
	->group('Operations')
	->icon('shopping-bag')
	->folderOnly();

$fulfillment=Panel::nav('fulfillment_folder')
	->label('Fulfillment')
	->parent($commerce)
	->icon('package-check')
	->folderOnly();

Panel::registerNavigationItems([$commerce, $fulfillment]);

Panel::resource('orders')
	->navigationParent('fulfillment_folder')
	->navigationDescription('Review demand and move fulfillment.');

Panel::page('command_center')
	->navigationParent($commerce);
```

`NavigationItem::child()` can also define explicit inline children. The
normalized `PanelNavigationState` recursively marks active descendants, counts
nested entries, keeps orphaned entries visible if a parent is not registered,
and exposes `allEntries()` for command palettes or host search.

Every generated navigation tree also owns a `PanelNavigationState` snapshot.
The snapshot normalizes resource, page, and host-owned entries into the same
shape, marks the active entry for the current request, groups entries for the
sidebar and dashboard, and can carry global-search discovery metadata:

```php
$request=PanelRequest::fromArray(['resource'=>'orders']);
$state=Panel::navigationState($request, [
	'query'=>'packing',
	'results'=>Panel::globalSearch('packing', $request),
]);

$groups=$state->groups();
$active=$state->active();
```

Generated dashboards expose this snapshot as `navigation_state` in
`PanelPageResult::data()`, `Panel::describe()` includes it in the manifest, and
Flightdeck records it through `navigation.state`. This keeps navigation
route-agnostic while still giving tests, debug tools, and reactive clients a
single state model.

## Dashboard Widgets

Widgets render above the resource directory on the generated dashboard. Static
values are stored as-is. Callable values are resolved when the dashboard is
rendered, which keeps expensive counts out of registration time.

```php
Panel::registerWidget(
	Panel::stat('open_orders', fn() => OrderRepository::openCount())
		->label('Open orders')
		->description('Orders awaiting fulfillment')
		->tone('warning')
		->icon('package')
		->url(PanelConfig::resourceUrl('orders', '', ['status'=>'open']))
		->sort(10)
);

Panel::registerWidget(
	Panel::widget('gross_volume')
		->value(fn() => money_format_compact(SalesRepository::todayTotal()))
		->label('Gross volume')
		->tone('success')
);
```

Widget callbacks receive the current `PanelRequest` and the `Widget` instance:

```php
Panel::stat('active_users', fn($request, $widget) => UserRepository::activeCount());
```

`Widget::state()` resolves the widget into a `PanelWidgetState`. Dashboard and
custom-page renderers use this state internally, while `PanelManager::widgets()`
and `PanelPage::resolvedWidgets()` still return render-compatible arrays for
older integrations.

```php
$state=Panel::widget('revenue_flow', 'chart')
	->chart('area')
	->data(['Mon'=>12, 'Tue'=>19])
	->state($request);

$state->type();        // chart
$state->chart();       // labels, datasets, height, point count
$state->jsonSerialize(); // renderer-compatible widget payload plus state metadata
```

Generated dashboards expose `widget_states` in `PanelPageResult::data()` and
record `widgets.state` trace events. Resource status widgets are wrapped in the
same state object, so dashboard cards, custom page widgets, and generated status
stats share one lifecycle contract.

### Chart Widgets

Chart widgets are regular widgets with `type('chart')` or `chart()`. They render
as responsive, server-generated SVG, so dashboards can show graph cards without a
JavaScript chart dependency.

```php
Panel::registerWidget(
	Panel::widget('revenue_flow', 'chart')
		->label('Revenue flow')
		->value(fn() => money(OrderRepository::todayRevenue()))
		->description('Gross demand by operating window')
		->chart('area')
		->labels(['06:00', '09:00', '12:00', '15:00', '18:00'])
		->dataset('Revenue', fn() => OrderRepository::revenueSeries(), 'primary')
		->height(220)
);

Panel::registerWidget(
	Panel::widget('risk_mix')
		->label('Risk mix')
		->chart('donut')
		->data(fn() => [
			'Low' => 42,
			'Medium' => 17,
			'High' => 8,
			'Critical' => 3,
		])
		->tone('danger')
);
```

Supported chart types are `line`, `area`, `bar`, `donut`, and `sparkline`.
`labels()`, `data()`, and `dataset()` accept arrays or callbacks. Dataset callbacks
receive the same request-aware resolution lifecycle as widget values.

## Custom Pages

Custom pages are for internal workflows that are not CRUD resources: import
queues, reconciliation tools, support consoles, migration previews, and other
operator screens. They share the Panel dashboard navigation and authorization
flow, but own their rendered content.

```php
Panel::registerPage(
	Panel::page('reconciliation')
		->label('Reconciliation')
		->group('Finance')
		->sort(15)
		->content('<section><h2>Today</h2><p>No unmatched payments.</p></section>')
);

Panel::registerPage(
	Panel::page('review_queue')
		->label('Review queue')
		->authorize(fn($ability, $user) => $user?->can('review') === true)
		->renderUsing(function(PanelRequest $request, PanelPage $page, PanelManager $manager){
			$open=ReviewRepository::openItems();

			return [
				'title'=>'Review queue',
				'content'=>render_review_queue($open),
				'data'=>['open_count'=>count($open)],
			];
		})
);
```

The same dispatch flow can render a registered custom page when no resource with the
same name exists. Resources intentionally take precedence so existing CRUD
resource names remain stable. A page renderer may return raw HTML, an array with
`title`, `content`, `status`, `data`, and `notifications`, or a full
`PanelPageResult`.

## Global Search

Dashboard global search is opt-in. A resource becomes searchable with
`globalSearchable()`, then uses `globalSearchColumns()` or searchable table
columns to match array-backed records. Results link to the generated show page
when a record exposes `id`, `key`, `uuid`, or `name`.

```php
Panel::resource('customers')
	->columns([
		Panel::column('name')->searchable(),
		Panel::column('email')->searchable(),
	])
	->globalSearchable()
	->globalSearchTitleUsing(fn($record) => $record['name'])
	->globalSearchSubtitleUsing(fn($record) => $record['email']);
```

Custom search handlers can delegate to an application repository or search
index. Handlers return either record-like arrays/objects or normalized result
arrays with `title`, `subtitle`, `url`, and `record_key`:

```php
Panel::resource('orders')
	->globalSearchUsing(function(string $query, PanelRequest $request, Resource $resource, int $limit){
		return OrderRepository::panelSearch($query, $limit);
	});
```

`Panel::globalSearch($query, $request, $limit)` returns the same normalized
result shape used by the generated dashboard.

Bulk actions render a selection column and receive the selected records as the
first handler argument:

```php
Panel::action('archive')
	->bulk()
	->requiresConfirmation()
	->tone('danger')
	->handle(function(array $records){
		return [
			'message'=>count($records).' projects archived.',
		];
	});
```

## Request Lifecycle

Panel requests are small value objects. They can be captured from the current
HTTP request or passed explicitly by any host surface.

```php
$page=Panel::dispatch([
	'resource'=>'projects',
	'operation'=>'index',
	'query'=>['page'=>1],
]);

echo $page->content();
```

`PanelResponseEmitter::emit($page)` applies the status and headers carried by the
result, then writes the body. Hosts that already own response emission should use
`content()`, `status()`, and `headers()` directly.

Supported operations:

- `index` renders a table.
- `create` renders a blank form.
- `store` runs the resource save handler with form input.
- `edit` renders a form with an existing record.
- `update` runs the resource save handler with form input and the loaded record.
- `show` renders field values.
- `relation` renders one named relation table for a record.
- `action` runs a named resource action.
- `export` downloads the current table view as CSV.
- `import` renders the CSV import form on GET and imports rows on POST.

Custom pages can also expose actions. Page actions render in a toolbar above the
custom content. Actions with fields use the generated form controls; actions
without fields submit directly.

```php
Panel::page('reconciliation')
	->renderUsing(fn() => render_reconciliation_dashboard())
	->widget(
		Panel::stat('unmatched_payments', fn() => ReconciliationRepository::unmatchedCount())
			->label('Unmatched payments')
			->tone('warning')
	)
	->widget(
		Panel::pageWidget('last_settlement')
			->label('Last settlement')
			->value(fn() => ReconciliationRepository::lastSettlementLabel())
			->tone('info')
	)
	->action(
		Panel::action('close_batch')
			->label('Close batch')
			->tone('danger')
			->requiresConfirmation()
			->modalHeading('Close reconciliation batch')
			->modalDescription('Add an audit note before this batch is locked.')
			->modalSubmitLabel('Close batch')
			->modalWidth('lg')
			->field(Panel::field('note', 'textarea')->required())
			->handle(function($record, array $data){
				ReconciliationRepository::closeCurrentBatch($data['note']);
				return Panel::notify('Batch closed.', 'success');
			})
	);
```

Actions support modal intent metadata with `modal()`, `modalHeading()`,
`modalDescription()`, `modalSubmitLabel()`, `modalCancelLabel()`,
`modalWidth()`, `modalSize()`, and `slideOver()`. `modal()` and `slideOver()`
also accept a heading, description, and width so actions can be declared in one
line:

```php
Panel::action('assign')
	->slideOver('Assign owner', 'Move ownership without leaving the table.', 'lg')
	->field(Panel::field('owner', 'select')->options($owners)->required())
	->handle(fn($record, array $data) => Orders::assign($record, $data['owner']));
```

Generated pages progressively enhance form and confirmation actions into dialog
or slide-over interactions by fetching the existing server-rendered action
form. If JavaScript is unavailable, the same URLs still work as full
server-rendered pages without changing handlers.

Confirmation modals use the action `tone()` metadata for their icon and submit
button treatment. Generated resource actions also emit explicit tone metadata
for transitions, restore, duplicate, delete, force delete, approvals, tasks, and
bulk equivalents, with text-based detection kept only as a fallback for older
markup.

Content-only modals use `modalContent()` or `infoModal()`. The content may be
HTML, a stringable value, an associative array rendered as read-only facts, or
a callback. Resource action callbacks receive the current record, request,
resource, and action when those values are available.

```php
Panel::action('snapshot')
	->label('Snapshot')
	->modal('Order snapshot', 'Generated from the selected row.', 'lg')
	->modalContent(fn(array $record) => [
		'Order'=>$record['number'],
		'Status'=>$record['status'],
		'Owner'=>$record['owner'],
	]);
```

Modal actions can declare explicit modal stack behavior with `modalStack()`.
Use `modalStack('push')`, `stackedModal()`, `modalBack()`, or
`preserveModalHistory()` when an action opened from inside a modal should expose
a Back control. Use `replaceModal()` for one-way detail panels, and
`clearModalStack()` for terminal actions such as workspace resets. Actions
without explicit stack metadata replace the current modal, which keeps
destructive confirmations and one-way flows direct.

```php
Panel::action('assign')
	->slideOver('Assign owner', 'Move ownership without leaving the record.', 'lg')
	->stackedModal()
	->fields([
		Panel::field('owner', 'select')->options($owners)->required(),
	])
	->handle(fn(array $record, array $data) => Orders::assign($record, $data['owner']));

Panel::action('reset_workspace')
	->modal('Reset workspace')
	->clearModalStack()
	->requiresConfirmation()
	->handle(fn() => Workspace::reset());
```

Actions can also declare client effects that are returned with fragment and
modal responses. Effects are hints for the Panel shell after the server action
has completed; they do not replace the handler result or the normal fallback
redirect.

```php
Panel::action('assign')
	->slideOver('Assign owner')
	->stackedModal()
	->refreshTable('orders')
	->refreshWidgets()
	->dispatchBrowserEvent('orders:assigned')
	->handle(fn(array $record, array $data) => [
		'message'=>'Order was assigned.',
		'effects'=>[
			'refresh'=>['table:orders', 'widgets'],
			'event'=>[
				'name'=>'orders:assigned',
				'detail'=>['order'=>$record['number']],
			],
		],
	]);
```

Available helpers are `refresh()`, `refreshPanel()`, `refreshTable()`,
`refreshWidgets()`, `refreshNavigation()`, `withoutRefresh()`, `closeModal()`,
`keepModalOpen()`, and `dispatchBrowserEvent()`. Returned `effects` from the
handler are merged with the action definition, so a generic action can declare
its usual behavior while a specific outcome can add an event or override whether
the modal closes.

Refresh targets are patch-aware. `refreshPanel()` updates the full Panel
surface. Narrower targets such as `refreshTable('orders')`, `refreshWidgets()`,
or `refreshNavigation()` ask the browser to replace only the matching refresh
regions when the returned page contains them. If a target cannot be matched, the
client falls back to the normal full Panel refresh instead of leaving stale UI
behind.

Custom pages and plugins can expose their own refreshable islands with
`refreshRegion()` or `refreshIsland()`. Actions can then target them with
`refresh('region:system_health')` or `refresh('island:session_pulse')`.

```php
Panel::page('command_center')
	->content(fn() => Panel::refreshIsland(
		'session_pulse',
		'<section class="dp-panel-card">...</section>'
	))
	->action(
		Panel::action('simulate_peak')
			->refresh(['widgets', 'island:session_pulse'])
			->handle(fn() => Operations::simulatePeak())
	);
```

Use `liveRefreshRegion()` or `liveRefreshIsland()` when an island should keep
itself fresh without a full panel redraw. The browser quietly requests a
fragment for only that named target, preserves scroll and focus, and skips the
poll while the user is typing, selecting rows, editing unsaved forms, viewing a
modal, offline, hidden, or paused from the live control.

```php
Panel::page('command_center')
	->content(fn() => Panel::liveRefreshIsland(
		'session_pulse',
		'<section class="dp-panel-card">...</section>',
		15000,
		['aria-live' => 'polite']
	));
```

The lower-level `refreshRegion()` helpers also accept
`data-dp-panel-refresh-interval`, `refresh_interval`, `live_interval`,
`interval_ms`, `poll_interval`, or `poll` attributes. Values below `1000` are
treated as seconds; larger values are milliseconds.

When an island should expose its own controls, render `refreshControls()` next
to it. The generated controls refresh only that target and can pause or resume
that island without disabling the rest of the Panel.

```php
Panel::liveRefreshIsland('session_pulse', $html, 15000)
	.Panel::refreshControls('session_pulse', 'island', [
		'label' => 'Session pulse updates',
		'status' => 'Refreshes every 15s',
	]);
```

Use `lazyRefreshRegion()` or `lazyRefreshIsland()` for expensive sections that
should not block the first page render. The first response emits a placeholder
with the same refresh key; once the Panel is interactive, the browser requests a
deferred fragment for that target and swaps only the island. The deferred
request uses `__panel_defer` internally and does not alter browser history.

```php
Panel::page('command_center')
	->content(fn() => Panel::lazyRefreshIsland(
		'attention_stream',
		fn() => Operations::attentionStreamHtml(),
		'<section class="dp-panel-card dp-panel-lazy-placeholder">Loading...</section>'
	));
```

Lazy regions can wait until they are near the viewport by passing
`lazy_visible`, `visible`, `when_visible`, or `load_when_visible`. The default
look-ahead margin is `360` pixels and can be changed with `lazy_margin`,
`visible_margin`, or `load_margin`.

```php
Panel::lazyRefreshIsland(
	'attention_stream',
	fn() => Operations::attentionStreamHtml(),
	null,
	['lazy_visible' => true, 'lazy_margin' => 260]
);
```

Use `lazy_manual`, `manual`, or `load_on_interaction` when the placeholder should
wait for a user action. Buttons or controls that target an unloaded lazy region
automatically add the deferred fragment hint, so they fetch the real island
instead of re-rendering the placeholder. If a lazy load fails, the placeholder
is unlocked and can be retried.

```php
Panel::lazyRefreshIsland(
	'attention_stream',
	fn() => Operations::attentionStreamHtml(),
	'<section class="dp-panel-card dp-panel-lazy-placeholder">
		<h2>Attention stream</h2>
		<button type="button" data-dp-panel-refresh-now="island:attention_stream">
			Load recommendations
		</button>
	</section>',
	['lazy_manual' => true]
);
```

Manual lazy regions can warm themselves before the user clicks. Pass
`lazy_prefetch`, `prefetch`, `prefetch_on_hover`, or `load_on_hover` to start
the deferred load on pointer hover, keyboard focus, or touch. Use
`lazy_prefetch_delay`, `prefetch_delay`, or `hover_delay` to tune the delay.

```php
Panel::lazyRefreshIsland(
	'attention_stream',
	fn() => Operations::attentionStreamHtml(),
	$placeholder,
	[
		'lazy_manual' => true,
		'lazy_prefetch' => true,
		'lazy_prefetch_delay' => 120,
	]
);
```

Generated resource controls use the same modal transport. Create, edit, import,
and bulk update links open as slide-over forms, view links open as read-only
record surfaces, and generated transition, duplicate, restore, delete, permanent
delete, and their bulk equivalents open as confirmation modals. Board card
titles, relation-manager create/view controls, and resource-backed global search
results use the same modal metadata so users keep their table, board,
dashboard, or parent-record context. These controls still point to normal Panel
URLs, so they remain usable as full pages when JavaScript is unavailable or a
modal request cannot be completed.

The client classifies modal content at runtime as form, confirmation, record
surface, generated facts, or plain content. That classification controls the
interior presentation, scroll affordances, sticky form actions, relation/table
fit, and tone accents while preserving the same server-rendered source.
GET-backed modal triggers expose an "Open full page" control in the modal
header so users can leave the dialog when the work naturally grows beyond a
quick review or edit. They also expose Copy link and Refresh controls so
record previews and search results can be shared or reloaded without leaving
the dialog. POST confirmations and content-only modals omit those controls
because they do not have a safe standalone read URL.
Modal form submits and confirmation actions keep an inline status strip while
the request is running, then briefly acknowledge success before closing and
refreshing the underlying workspace. Validation responses can replace the modal
form in place so the user stays in context.
Dialogs also include an Expand/Normal control, with `Alt+Enter` as a keyboard
toggle, so dense generated forms and record surfaces can temporarily use the
full viewport without abandoning the modal flow.

Page widgets use the same `Widget` definitions as dashboard widgets. They are
resolved for the current `PanelRequest` and rendered above the custom page
content.

Custom pages can also expose local table sections. Page tables reuse Panel
columns and cell formatting without becoming CRUD resources:

```php
Panel::page('review_queue')
	->table(
		Panel::pageTable('pending_reviews')
			->label('Pending reviews')
			->columns([
				Panel::column('title')->truncate(60),
				Panel::column('risk')->badge([
					'high'=>'danger',
					'medium'=>'warning',
					'low'=>'success',
				]),
				Panel::column('submitted_at')->datetime(),
			])
			->filters([
				Panel::pageFilter('risk', 'select')->options([
					'high'=>'High',
					'medium'=>'Medium',
					'low'=>'Low',
				]),
				Panel::pageFilter('submitted_at')->dateRange(),
			])
			->views([
				Panel::view('high_risk')
					->tone('danger')
					->filterValue('risk', 'high'),
				Panel::view('recent')
					->default()
					->search('refund')
					->range('submitted_at', date('Y-m-d', strtotime('-7 days')), null),
			])
			->groups([
				Panel::tableGroup('risk')
					->label('Risk')
					->default()
					->collapsible()
					->descriptionUsing(fn(string $key, array $records) => count($records).' records'),
				Panel::tableGroup('owner')->label('Owner')->collapsible(),
			])
			->recordsUsing(fn(PanelRequest $request) => ReviewRepository::pendingFor($request->user()))
			->defaultSort('submitted_at', 'desc')
			->limit(25)
	);
```

Page table filters use the same `TableFilter` definitions as resources. Their
query parameters are scoped with the table name, so two page tables can use the
same filter names without affecting each other. Page tables also render scoped
search controls. Page table views use the same `TableView` definitions as
resources, and their search/filter query defaults are scoped in the same way.
Page table groups use the same `TableGroup` definitions as generated resource
tables and write `table_group=` query parameters using the table prefix. A
`high_risk` view on the `pending_reviews` table writes
`pending_reviews_view=high_risk` and can default `pending_reviews_risk=high`
without affecting another table on the page.

Forms submit to the generated lifecycle endpoints by convention. Persistence is
explicit through `saveUsing()`:

```php
$resource=$resource->saveUsing(function(array $data, mixed $record, string $mode){
	return ProjectRepository::save($data, $record);
});
```

CSV imports parse to a preview page before records are saved. The preview shows
mapped columns, skipped columns, sample rows, and field validation issues. The
confirmed import validates again; invalid rows block the import rather than
partially saving a broken file. When CSV headers do not match resource field
names or labels, the preview exposes a column mapping control so each CSV column
can be mapped to a field or skipped. The import form also exposes a CSV template
download built from the resource's writable fields, with sample values when the
field metadata can provide them. Imports can reuse `saveUsing()` row by row with
mode `import`, or use a batch import handler:

```php
$resource=$resource->importUsing(function(array $rows){
	foreach($rows as $row){
		ProjectRepository::createFromImport($row);
	}

	return ['imported'=>count($rows)];
});
```

Import handlers receive normalized row arrays keyed by resource field names after
preview confirmation. Returning `imported`, `failed`, `success`, `message`,
`notification`, or `redirect` follows the same result conventions as saves and
actions.

`PanelPageResult` carries the rendered content, HTTP status, headers, and a
machine-readable data payload. If the HTTP framework is loaded, `toResponse()`
returns a `Dataphyre\Http\Response`.

## Generated Tables

Generated index pages support the table metadata declared on columns:

- `searchable()` columns participate in the `?q=` search filter.
- If no columns are marked searchable, search falls back to the visible columns.
- `sortable()` columns render clickable headers using `?sort=` and `?dir=`.
- `defaultSort('column', 'desc')` sets the generated table order when no
  explicit sort is present in the request.
- column types format common values automatically: boolean, date, datetime,
  money/currency, percent/percentage, json, array, badge, url, and email.
- `money()`, `date()`, `datetime()`, `booleanLabels()`, `badge()`, `url()`,
  `email()`, `truncate()`, and `limit()` are convenience helpers for common
  column metadata.
- `editable()`, `inlineEditable()`, `editableType()`, and `editableOptions()`
  make a generated table cell writable in place. Inline edits post the single
  field through the resource's `saveUsing()` handler with the `inline_update`
  mode, so normal save hooks, notifications, authorization, and redirects still
  apply. Text, number, select, and checkbox controls are rendered natively.
- `perPage()` sets the default page size; `?per_page=` may override it per
  request.
- `perPageOptions()` controls the generated row-count selector. The selector
  preserves active search, filters, sort, visible columns, and density.
- `views()` / `view()` add one-click table slices above the generated table.
  Views are applied before filters, search, sorting, pagination, summaries, and
  CSV export.
- `tableGroups()` / `tableGroup()` add one-click row grouping without changing
  the data source. Groups can read a record field directly, use `stateUsing()`
  for a computed grouping key, use `labelUsing()` for section labels, set
  `direction('desc')`, add section context with `description()` or
  `descriptionUsing()`, make sections interactive with `collapsible()`, start
  them closed with `collapsed()`, attach per-group metrics with `summary()` or
  `summaries()` using the same `TableSummary` definitions as table summaries,
  attach drilldown links with `action()` or `actions()`, and mark a default
  group with `default()`. Page tables use `groups()` / `group()` because they
  do not have a navigation group.
- `filters()` / `filter()` add generated controls. Active filters are preserved
  across search, sort, and pagination links.
- Generated filter labels, empty select choices, boolean choices, and range
  placeholders use Panel localization keys such as `common.any`,
  `common.yes`, `common.no`, `table.filter_from`, and `table.filter_to`.
- Filters expose first-class active indicators. Use `indicator()`,
  `indicatorUsing()`, and `indicatorTone()` when the active chip should say
  something more useful than the raw query value. Indicator callbacks can return
  one chip or several chips, and each chip may declare the exact query keys it
  clears, which lets range filters clear their `from` and `to` sides
  independently.
- Filters can be request- and operation-aware with `visible()`, `hidden()`,
  `visibleUsing()`, `hiddenUsing()`, `visibleOn()`, and `hiddenOn()`. Hidden
  filters do not render controls, do not apply stale query parameters, and do
  not create active indicators.
- `summaries()` / `summary()` add generated metrics above the table.
  Array-backed resources calculate summaries after filters, search, and sort,
  before pagination. Paginated query objects summarize the records supplied to
  the page.
- `toggleable(false)` keeps a column visible. Other columns can be shown/hidden
  through the generated column picker, backed by `?visible_columns=`.
- `hiddenByDefault()` keeps a toggleable column out of the initial table while
  still making it available in the column picker. `visibleByDefault(false)` is
  the equivalent explicit form.
- `visible()`, `hidden()`, `visibleUsing()`, `hiddenUsing()`, `visibleOn()`,
  and `hiddenOn()` remove a column from generated tables before saved
  preferences are applied. Use them for request-, tenant-, operation-, or
  feature-aware table layouts; hidden columns do not appear in the column picker
  for that request.
- table density is controlled with `?density=compact`, `normal`, or
  `comfortable`, and is preserved across generated table links.
- column visibility and density are persisted in the PHP session per resource
  once a user changes them. `?reset_table_view=1` clears the saved table view.
- table metadata includes local saved-view controls. A saved view captures the
  current generated table URL, so search, filters, active view, sort, page size,
  density, page, and visible columns can be recalled without changing server
  definitions.
- table columns can be resized in the browser. Width preferences are stored in
  local storage per host path, page heading, and table label; they do not affect
  CSV/JSON exports or server-side column metadata.
- focused rows can be previewed with `P`. The preview uses visible table cells,
  keeps row actions available, and can copy the row as JSON or CSV.
- `Export CSV` downloads the current filtered/searched/sorted view and respects
  visible columns. `Export JSON` uses the same view and returns formatted row
  data plus column metadata.
- `Export selected CSV` and `Export selected JSON` appear in the
  selected-records action bar and download only the checked rows while
  preserving visible columns.
- `Import CSV` appears when `importUsing()` is present, or when a resource has
  form fields and `saveUsing()`. Uploaded or pasted CSV rows are mapped by field
  name or label. CSV without headers uses form field order.
- Rows with an `id`, `key`, `uuid`, or `name` value get View and Edit links.
- Empty tables show a create action; filtered empty states show a reset action.
  `emptyState()` and `filteredEmptyState()` let a resource replace those
  defaults with a heading, description, optional icon, and optional action.
  `emptyStateAction()` and `filteredEmptyStateAction()` can attach actions
  later. Action URLs may be static strings or callbacks that receive the current
  request, resource, table, and whether the table is constrained.

```php
$resource=$resource
	->emptyState(
		'No orders yet.',
		'When demand starts flowing, this table will expose review lanes.',
		'Create order',
		'/debug?resource=orders&operation=create',
		'shopping-bag'
	)
	->filteredEmptyState(
		'No orders match this slice.',
		'Clear the search or reset the current view.',
		'Reset table view',
		fn($request, $resource) => PanelConfig::resourceUrl($resource, '', ['view'=>'all']),
		'filter-x'
	);
```

Tables expose the same resolved state as the generated renderer:

```php
$state=$resource->tableState($request, $records);

$state->allColumns();          // every declared or inferred column
$state->visibleColumns();      // columns after request/session visibility
$state->visibleColumnNames();
$state->summaries();
$state->query();
$state->filterValues();
$state->sort();                // ['column'=>'created_at', 'direction'=>'desc']
$state->activeView();
```

`ResourceTable::state()` and `Resource::tableState()` return immutable
`PanelTableState` snapshots. The generated index page and export pipeline use
the same column resolution model, so table rendering, JSON/CSV export, saved
views, and future reactive table updates all share one table state shape.

Tables can also describe their whole contract before rendering. Use
`ResourceTable::manifest()`, `PageTable::manifest()`, `Resource::tableManifest()`,
or `Panel::tableManifest()` to inspect columns, filters, views, groups,
summaries, row behavior, pagination, sort defaults, action surfaces, and
resource data operations:

```php
$manifest=Panel::tableManifest($orders_resource, request: $request);

$manifest['columns'];      // searchable, sortable, toggleable, computed columns
$manifest['filters'];      // filter controls, ranges, dynamic options
$manifest['views'];        // saved server-defined queues
$manifest['groups'];       // grouping controls, summaries, group actions
$manifest['row_behavior']; // clickable rows, modal row targets, previews
$manifest['operations'];   // import, duplicate, delete, transitions, bulk update
$manifest['actions'];      // action manifests attached to the resource table
```

The table manifest is the table equivalent of schema and action manifests: a
custom renderer, Flightdeck tab, test, generated documentation page, or Reactor
table island can understand a table without scraping generated HTML or assuming
that the table is hosted at a particular URL.

Resources can describe their complete generated surface too. Use
`Resource::resourceManifest()`, `Panel::resourceManifest()`, or
`PanelInstance::resourceManifest()` when an external renderer, test, Flightdeck
tab, or documentation generator needs the whole resource contract:

```php
$manifest=Panel::resourceManifest('orders', $request);

$manifest['identity'];       // record key, title, subtitle, and URL strategy
$manifest['navigation'];     // group, icon, badge, hidden state, route target
$manifest['forms'];          // create, edit, and bulk-update schema manifests
$manifest['infolist'];       // show-surface schema manifest
$manifest['table'];          // table manifest for the index
$manifest['actions'];        // action manifests attached to the resource
$manifest['relations'];      // relation managers and their table manifests
$manifest['record_surface']; // alerts, notes, activity, messages, files, tasks
$manifest['operations'];     // imports, bulk updates, duplicate/delete/restore
```

The resource manifest is intentionally route-free. It is the equivalent of
asking “what can this resource do?” rather than “what HTML did this URL emit?”.
That keeps custom shells, Reactor islands, generated docs, tests, and
Flightdeck lifecycle introspection aligned with the same source of truth.

Relation managers also expose a standalone contract. Use
`RelationManager::manifest()`, `Panel::relationManifest()`, or
`PanelInstance::relationManifest()` when a nested record surface needs to be
inspected without walking through the parent resource manifest:

```php
$manifest=$panel->relationManifest($orders->relationManagers()['items'], $request);

$manifest['presentation'];  // labels, dynamic badges, parent title, empty state
$manifest['data'];          // related resource, storage table, key mapping
$manifest['operations'];    // create, attach, detach, read-only, custom handlers
$manifest['authorization']; // whether an authorizer exists
$manifest['facts'];         // relation-level summaries
$manifest['table'];         // table manifest for the nested records
$manifest['capabilities'];  // table, data, operation, fact, and presentation counts
```

Relation manifests make relation managers closer to nested resources: the
record detail renderer, Flightdeck, generated docs, tests, and external tools
can all understand the related table and its write affordances without touching
callbacks or route state.

Custom pages have their own manifest instead of borrowing the resource model.
Use `PanelPage::pageManifest()`, `Panel::pageManifest()`, or
`PanelInstance::pageManifest()` when a tool needs a custom page contract:

```php
$manifest=$panel->pageManifest('feature_showcase', $request);

$manifest['navigation'];   // group, icon, badge, hidden state, URL
$manifest['rendering'];    // custom renderer, static content, authorization
$manifest['actions'];      // page action manifests
$manifest['widgets'];      // page widgets
$manifest['tables'];       // page table manifests
$manifest['capabilities']; // aggregate page feature counts
```

Page manifests are useful for dashboards, utility pages, settings screens, and
tooling pages that have tables and actions but are not backed by a resource.

Widgets also have a route-free contract. Use `Widget::manifest()`,
`Panel::widgetManifest()`, or `PanelInstance::widgetManifest()` to inspect stat,
chart, trend, lazy, and linked widgets:

```php
$manifest=$panel->widgetManifest(
	$panel->widget('revenue_flow', 'chart')
		->labels(['Mon', 'Tue'])
		->dataset('Revenue', [1200, 1800])
);

$manifest['presentation']; // label, description, tone, icon, group, sort
$manifest['data'];         // static value, lazy flag, optional resolved state
$manifest['interaction'];  // link target and link flag
$manifest['chart'];        // type, height, labels, datasets, point counts
$manifest['capabilities']; // stat/chart/trend, lazy, dynamic data, link
```

Pass `resolve: true` when you explicitly want the widget value and dynamic chart
metadata resolved through its callbacks. The default manifest keeps callback
data private and only describes the shape.

Commands have the same route-free contract. Use `PanelCommand::manifest()`,
`Panel::commandManifest()`, or `PanelInstance::commandManifest()` when a command
palette, documentation generator, shortcut trainer, shell test, or Flightdeck
panel needs to inspect an operation without rendering the palette:

```php
$manifest=$panel->commandManifest('switch_glass_theme', $request);

$manifest['presentation']; // description, icon, tone, sort
$manifest['target'];       // URL, lazy URL, client action, new-tab behavior
$manifest['search'];       // keywords and indexed text
$manifest['visibility'];   // hidden and lazy visibility flags
$manifest['capabilities']; // target, search, presentation, visibility features
```

Panel manifests compose command manifests for all registered commands. Commands
with request-dependent URLs keep that fact visible through `target.url_lazy`, so
tools can distinguish a resolved link from a callback-backed target.

Themes can be described independently from the panel shell. Use
`Panel::themeManifest()` or `PanelInstance::themeManifest()` when a package,
test, visual builder, or Flightdeck pane needs the active theme and theme
library contract:

```php
$manifest=$panel->themeManifest(include_preview: true);

$manifest['active'];       // active theme definition
$manifest['library'];      // registered presets and named themes
$manifest['diagnostics'];  // missing bases, missing presets, cycles, contrast
$manifest['tokens'];       // light/dark token and variable maps
$manifest['modes'];        // dark mode, default mode, mode toggle
$manifest['assets'];       // brand, favicon, fonts, asset roots, stylesheets
$manifest['capabilities']; // counts for colors, tokens, modes, assets, library
$manifest['preview'];      // optional generated preview metadata
```

This keeps Filament-style theme customization inspectable as data. A theme can
radically change navigation, cards, tables, forms, and modals while external
tools still see the same token, asset, mode, and diagnostic contract.

Plugins expose a package contract too. Use `Panel::pluginManifest()`,
`PanelInstance::pluginManifest()`, or `PanelInstance::pluginManifests()` when a
shell, test, package browser, or Flightdeck tab needs to inspect extensions:

```php
$manifest=$panel->pluginManifest('shopiro_ops_signals');

$manifest['package'];       // id, class, and version
$manifest['configuration']; // safe config shape and redacted scalar values
$manifest['capabilities'];  // metadata, lifecycle, package, config features
$manifest['meta'];          // caller metadata
```

The plugin manifest keeps the `PanelPlugin` interface small. Optional
`label()`, `version()`, and `description()` methods are read when they exist,
configuration values are redacted by sensitive key name, and the lifecycle
contract records whether the plugin exposes `register()` and `boot()`.

Packages can describe broader ecosystem metadata with `Panel::packageManifest()`
and `Panel::compatibilityMatrix()`. A package manifest can represent plugins,
themes, adapters, docs packs, or local packages. It records requirements for PHP,
Panel, Reactor, modules, and themes, then evaluates those requirements against a
runtime snapshot.

```php
$package=Panel::packageManifest([
	'id'=>'seller_trust_pack',
	'label'=>'Seller Trust Pack',
	'version'=>'1.0.0',
	'type'=>'plugin',
	'requires'=>[
		'php'=>'>=8.3',
		'panel'=>'^1.0',
		'reactor'=>'>=2.0',
		'modules'=>['templating'=>'>=2.0'],
		'themes'=>['default'],
	],
	'provides'=>['resources', 'widgets', 'actions'],
]);

$matrix=Panel::compatibilityMatrix([$package]);
$matrix->manifest(); // package counts, compatibility checks, runtime, provides
```

Package authors can also start from a template contract:

```php
$template=Panel::packageTemplate($package)
	->namespace('App\\Panel\\Packages\\SellerTrust')
	->theme(true)
	->marketplace(['category'=>'Trust and safety']);

$template->manifest(); // source, docs, tests, package JSON, marketplace listing
```

The template returns artifacts first. Production tooling can later write those
files, prompt before overwrites, publish marketplace listings, or run generated
regression suites without making the Panel runtime know about a specific app
folder or route.

Hosts can collect packages through a repository contract. `Panel::packageRepository()`
and `$panel->packageRepository()` can register manifests directly, discover
`dataphyre-panel-package.json` files from package folders, read generated
template artifacts, evaluate compatibility, and emit a deterministic lock
manifest.

```php
$repository=$panel->packageRepository()
	->discover('app/Panel/Packages')
	->discoverArtifacts($template->artifacts(), 'seller_trust_template');

$manifest=$repository->manifest(); // sources, errors, compatibility, packages
$lock=$repository->lock();         // stable lock manifest with checksum
```

The repository does not install code. It gives package browsers, CI, and future
marketplace tooling a stable way to inspect what would be installed, why it is
compatible or blocked, and which package versions were evaluated.

Compatibility and locks answer whether packages can run and which versions were
evaluated. Trust policies answer whether the host should accept them.
`Panel::packageTrustPolicy()` and `$panel->packageTrustPolicy()` evaluate package
signature metadata against trusted publishers, trusted key ids, allowed package
statuses, revoked package ids, and revoked signature digests:

```php
$policy=$panel->packageTrustPolicy([
	'require_signature'=>true,
	'allow_unknown_publishers'=>false,
	'trusted_publishers'=>['dataphyre'],
	'trusted_keys'=>['dp-release-key'],
	'revoked_packages'=>['old_theme_pack'],
]);

$report=$policy->report($repository);
$report->summary(); // total, trusted, blocked, signed
```

This is a policy manifest, not cryptographic verification. Future marketplace
and installer tooling can verify detached signatures or transparency logs, then
feed the verified publisher, key, digest, and timestamp into the same package
manifest shape.

Finally, installer tooling can ask for a dry-run plan before writing anything.
`Panel::packageInstallPlan()` and `$panel->packageInstallPlan()` combine a
package template, target path, runtime compatibility, optional trust policy, and
overwrite policy into a list of planned file operations:

```php
$plan=$panel->packageInstallPlan($template, app_path('Panel/Packages'), [
	'overwrite_policy'=>'skip', // fail, skip, or replace
	'trust_policy'=>$policy,
	'runtime'=>PanelCompatibilityMatrix::defaultRuntime(),
]);

$manifest=$plan->manifest(); // ready, blocked, steps, conflicts, bytes
```

The plan manifest never writes files. It resolves artifact target paths, counts
creates, replacements, skips, conflicts, and bytes, and blocks when
compatibility or trust checks fail. After a human or deployment policy approves
the manifest, the same plan can produce a storage-safe apply result:

```php
$preview=$plan->apply(app_path('Panel/Packages'), [
	'dry_run'=>true,
	'overwrite'=>true,
	'backup_root'=>storage_path('panel-package-backups'),
]);

$preview->toArray(); // ok, written, skipped, backups, blocked, duration_ms
```

`apply()` returns a `PanelPackageApplyResult`. With `dry_run` enabled it reports
the same written and backup metadata without creating directories, copying
backups, or writing package files. With `dry_run` disabled it creates missing
target directories, writes template artifacts, and copies existing files into
`backup_root` before replacement when a backup root is provided. Every artifact
target is resolved under the requested target root; paths that would escape that
root are added to `blocked` instead of being written. The per-call `overwrite`
option can force replacement or fail-on-existing behavior without changing the
stored install plan.

Every install plan can still produce a dry-run rollback plan:

```php
$rollback=$panel->packageRollbackPlan($plan);
$rollback->manifest(); // delete, restore, snapshot, leave, blocked counts
```

Rollback plans are manifest-only. Created files become delete steps, replaced
files require snapshots and restore steps, skipped files are left alone, and
unresolved install conflicts block rollback readiness.

After an install is applied, rollback planning should consume the apply result
instead of guessing what happened on disk:

```php
$result=$plan->apply(app_path('Panel/Packages'), [
	'overwrite'=>true,
	'backup_root'=>storage_path('panel-package-backups'),
]);

$rollback=PanelPackageRollbackPlan::fromApplyResult($result);
$rollback->manifest(); // restore when a backup exists, delete otherwise
```

`Panel::packageRollbackPlan($result)` and `$panel->packageRollbackPlan($result)`
accept the same apply result object. Each written file becomes a restore step
when `apply()` captured a backup for that target; written files without a backup
become delete steps. Skipped files become leave steps, and blocked apply entries
remain blocked in the rollback manifest.

Global search has its own manifest as well. Use `Panel::searchManifest()` or
`PanelInstance::searchManifest()` when a command palette, docs exporter,
Flightdeck tab, or test needs searchable providers without rendering the shell:

```php
$manifest=$panel->searchManifest($request, query: 'SO-', limit: 5);

$manifest['providers'];        // searchable resources keyed by resource name
$manifest['resource_columns']; // indexed columns per provider
$manifest['query'];            // optional sampled query metadata and results
$manifest['capabilities'];     // provider, tenant, query, result, column counts
```

The manifest is cheap when no query is passed. Supplying a query asks the same
resource-backed global search path to return a bounded sample, which is useful
for diagnostics and demos without hardcoding provider-specific behavior.

Tenant scope has a standalone manifest as well. Use
`Panel::tenantManifest()` or `PanelInstance::tenantManifest()` when a shell,
action runner, docs exporter, Flightdeck tab, or test needs to know how tenant
context moves through the panel:

```php
$manifest=$panel->tenantManifest($request);

$manifest['parameter'];     // request/query parameter name
$manifest['current'];       // active tenant key, or null when unscoped
$manifest['resources'];     // tenant-scoped resources keyed by resource name
$manifest['search'];        // tenant-aware global search provider summary
$manifest['propagation'];   // links, forms, actions, exports, imports, modals
$manifest['capabilities'];  // scoped resources, resolvers, scopes, active state
```

The manifest is built from resource definitions, `PanelRequest::tenantKey()`,
and panel configuration. It keeps tenant behavior inspectable without making
themes or host routes understand application-specific tenancy.

Navigation has a standalone shell contract too. Use
`NavigationItem::manifest()`, `Panel::navigationManifest()`, or
`PanelInstance::navigationManifest()` when a sidebar, horizontal nav, mobile
sheet, command surface, test, or documentation generator needs to inspect the
same grouped tree:

```php
$manifest=$panel->navigationManifest(
	request: $request,
	meta: ['navigation_layout'=>'horizontal']
);

$manifest['entries'];      // grouped tree-ready entries
$manifest['entries_flat']; // depth-aware flattened tree
$manifest['groups'];       // grouped navigation sections
$manifest['active'];       // active entry and operation
$manifest['search'];       // current navigation search result metadata
$manifest['counts'];       // entries, folders, leaves, badges, max depth
$manifest['capabilities']; // sidebar/horizontal/mobile support and hierarchy
```

This keeps navigation flexible in the Filament sense without making a theme
scrape rendered markup. Themes can change how navigation looks while tooling
still sees the same tree, active path, folders, badges, descriptions, and
layout hints.

The same idea exists at the whole-panel level. Use
`Panel::panelManifest()` or `PanelInstance::panelManifest()` when a tool needs
to inspect the shell itself:

```php
$manifest=$panel->panelManifest($request);

$manifest['resources'];    // resource manifests keyed by resource name
$manifest['pages'];        // custom pages, page tables, widgets, and actions
$manifest['widgets'];      // dashboard widgets
$manifest['navigation'];   // entries, tree, groups, active state, layout hints
$manifest['commands'];     // command palette entries, groups, keywords, targets
$manifest['theme'];        // active theme, library names, diagnostics, assets
$manifest['plugins'];      // installed panel plugins
$manifest['tenant'];       // tenant parameter, scoped resources, propagation
$manifest['search'];       // global search providers and indexed columns
$manifest['capabilities']; // aggregate counts for tests and Flightdeck
```

Panel manifests are the top of the manifest stack. They compose schema, action,
table, and resource manifests into one stable contract for shell renderers,
visual builders, test assertions, documentation exports, and Flightdeck’s panel
lifecycle view.

## Record Identity

Resources can define how Panel identifies a record. The same identity is used
for generated row links, bulk selection, show page headings, action URLs, and
global search results.

```php
Panel::resource('orders')
	->recordKeyUsing('order_number')
	->recordTitleUsing(fn($record) => 'Order '.$record['order_number'])
	->recordSubtitleUsing(fn($record) => $record['customer_name'].' / '.$record['status']);
```

Custom URLs can point to application-specific destinations while keeping the generated
table and actions aware of the same record:

```php
Panel::resource('tickets')
	->recordKeyUsing('uuid')
	->recordUrlUsing(function($record, string $operation){
		return '/support/tickets/'.$record['uuid'].($operation==='edit' ? '/edit' : '');
	});
```

Array-backed resources are filtered and sorted in the generated renderer. Query
objects may still apply their own filtering or pagination before records reach
Panel. If a query object exposes `paginate()` or `paginateRecords()`, Panel sends
the current page and resolved page size and will not slice that result a second
time.

CSV export prefers unpaginated query methods (`getRecords()` or `get()`) when
available so downloads can include the full current table view. If a query object
only exposes `paginate()` / `paginateRecords()`, export uses the records returned
by that paginated query.

Custom formatters still take precedence over built-in type formatting:

```php
Panel::column('margin', 'percent')->meta(['decimals'=>1]);
Panel::column('total')->money('CAD');
Panel::column('paid')->booleanLabels('Paid', 'Open');
Panel::column('status')->format(fn($value) => strtoupper((string)$value));
Panel::column('priority')
	->sortable()
	->sortUsing(fn(array $record) => match($record['priority'] ?? '') {
		'critical'=>0,
		'high'=>1,
		'medium'=>2,
		'low'=>3,
		default=>99,
	});
Panel::column('customer')
	->searchable()
	->searchUsing(fn(array $record) => [
		$record['customer'] ?? '',
		$record['email'] ?? '',
		$record['company'] ?? '',
	]);
```

Columns can also be computed from the whole record. Computed values participate
in generated display, local search, local sorting, and CSV export:

```php
Panel::column('customer')
	->valueUsing(fn($record) => trim($record['first_name'].' '.$record['last_name']))
	->searchable()
	->sortable();

Panel::column('gross_total')->money('CAD')
	->stateUsing(fn($record) => (float)$record['subtotal']+(float)$record['tax']);
```

HTML table cells can add presentation while exports remain plain text:

```php
Panel::column('status')->badge([
	'published'=>'success',
	'draft'=>'warning',
	'blocked'=>'danger',
]);
Panel::column('website')->url('name')->truncate(40);
Panel::column('support_email')->email();
Panel::column('description')->limit(90);
Panel::column('customer')
	->descriptionUsing(fn(array $record) => $record['email'] ?? 'No email');
Panel::column('sla_minutes')
	->label('SLA')
	->tooltipUsing(fn(array $record) => $record['sla_minutes']<0
		? 'Past target. Prioritize recovery.'
		: 'Minutes remaining before the next operating target.');
Panel::column('order_number')
	->copyable()
	->copyMessage('Order number copied');
Panel::column('order_number')
	->linkTo(fn(array $record) => '/orders/'.$record['id']);
Panel::column('status')
	->badge(['review'=>'warning', 'shipped'=>'success'])
	->group('Operations', 'State and risk signals');
Panel::column('margin')
	->visibleUsing(fn(PanelRequest $request) => $request->query('view')==='premium');
Panel::column('customer')
	->copyValueUsing(fn(array $record) => $record['email'] ?? '')
	->copyMessage('Customer email copied');
Panel::column('status')
	->iconUsing(fn(array $record) => $record['status']==='shipped' ? 'truck' : 'workflow')
	->colorUsing(fn(array $record) => $record['status']==='cancelled' ? 'danger' : 'primary');
Panel::column('risk')
	->headerData('qa', 'orders-risk-header')
	->cellAttributes(fn(array $record): array => [
		'data-qa'=>'orders-risk-cell',
		'data-order-risk'=>$record['risk'] ?? 'unknown',
		'aria-label'=>'Risk: '.ucfirst($record['risk'] ?? 'unknown'),
		'class'=>'risk-cell-'.($record['risk'] ?? 'unknown'),
	]);
```

Generated resources may use equivalent array definitions when a builder emits
configuration instead of fluent PHP:

```php
$resource->columns([
	[
		'name'=>'sku',
		'label'=>'SKU',
		'searchable'=>true,
		'sortable'=>true,
		'copyable'=>true,
		'copy_message'=>'SKU copied',
		'icon'=>'barcode',
		'color'=>'primary',
		'group'=>'Catalog',
		'group_description'=>'Identity and product context',
		'link_to'=>fn(array $record): string => '/products/'.$record['id'],
	],
]);
```

Columns can also render table footers. Footers are resolved against the current
filtered table records, so operators see totals and averages exactly beneath the
columns they are scanning:

```php
Panel::column('total')->money('CAD')->sum('Visible total');
Panel::column('margin')->average('Avg margin');
Panel::column('orders')->count('Rows');
Panel::column('stock')->footerUsing(fn(array $records) => [
	'label'=>'Low stock',
	'value'=>count(array_filter($records, fn(array $record) => $record['stock'] < $record['reorder_at'])),
]);

$resource->columns([
	[
		'name'=>'stock',
		'summary'=>'sum',
		'summary_label'=>'In stock',
	],
]);
```

The same footer definitions work on resource tables, generated page tables, and
relation tables. Built-in summaries support `sum`, `avg` / `average`, `min`,
`max`, and `count`; custom footer callbacks may return a string or an array with
`label` and `value`.

Custom column types registered through
`PanelComponentRegistry::registerColumnType()` can provide `value`, `format`,
`export`, `search`, `sort`, and `summary` hooks plus a cell renderer. Generated
cells consult registered renderers before the built-in badge/link/text renderers,
while exports call `Column::exportValue()` so custom export hooks stay plain-text
friendly. Columns also support lightweight presentation metadata with
`description()`, dynamic cell subtext through `descriptionUsing()`, `tooltip()`,
`tooltipUsing()`, `icon()`, `color()`, and `linkTo()`. Static `tooltip()` values
appear on table headers and cells; `tooltipUsing()` resolves record-aware cell
hints. Use `copyable()` for generated copy buttons, or `copyValueUsing()` when
the copied value should differ from the displayed value. `iconUsing()` and
`colorUsing()` can resolve those visual cues per record while still exporting
plain values. `linkTo()` wraps the primary cell content in a sanitized internal
or HTTP(S) link without changing export values; pass `true` as the second
argument or call `openInNewTab()` for a new-tab target. Use `group()` or the
array keys `group` and `group_description` to render consecutive related columns
under a shared header band. Ungrouped columns keep their normal single header
while grouped columns receive a second-level label row.
`sortUsing()` keeps generated sorting attached to the column while letting the
displayed value differ from the comparable value, such as status pipelines,
priority ranks, natural dates, or nested relation fields. `searchUsing()` does
the same for generated table search; return a scalar or a list of aliases,
normalized terms, related labels, or hidden fields that should match the column.
Column shell attributes can be attached with `headerAttributes()`,
`cellAttributes()`, `extraAttributes()`, `attributes()`, `headerData()`,
`cellData()`, `headerAria()`, and `cellAria()`. Header callbacks receive the
request, column, resource, and table; cell callbacks also receive the record,
raw value, and formatted value. Panel renders only safe `data-*`, `aria-*`,
`class`, `id`, `role`, `tabindex`, `headers`, and `scope` attributes while
keeping internal table labels, sorting state, and responsive markup
authoritative. These attributes render on resource tables, grouped rows,
relation tables, and page tables.

Resource tables can also decorate the generated `<tr>` for each record:

```php
$resource->rowAttributes(fn(array $order): array => [
	'data-qa'=>'orders-table-row',
	'data-order-status'=>$order['status'] ?? 'unknown',
	'data-order-risk'=>$order['risk'] ?? 'unknown',
	'class'=>'order-row order-row-risk-'.($order['risk'] ?? 'unknown'),
]);
```

Use `rowAttributes()`, `recordAttributes()`, `rowAttribute()`, `rowData()`, and
`rowAria()` when the whole row needs host hooks for QA, accessibility, live
updates, or stateful styling. Row callbacks receive the record, request,
resource, and table. Panel keeps its own row focus, record key, internal
`data-dp-panel-*`, and generated row label authoritative; hosts may add safe
`data-*`, `aria-*`, `class`, `id`, and `role` attributes. Row attributes render
on normal resource table rows, grouped rows, and relation manager rows.

Rows can be made directly interactive without relying on the first visible link
inside the row:

```php
$resource->rowClick('show');          // open the show view in the default modal
$resource->rowAction('edit');         // open the edit view
$resource->recordAction('brief');     // open a named resource action
$resource->clickableRows(false);      // disable row activation
$resource->rowUrl(fn($order) => '/orders/'.$order['id']);
```

Clickable rows emit `data-dp-panel-row-url` and reuse Panel's modal metadata
when modal navigation is enabled. Mouse clicks, double clicks, and keyboard
Enter all activate the same row target while controls inside the row still keep
their own behavior. `recordAction()` targets a registered row action by name and
inherits that action's visibility, authorization, disabled state, modal content,
form fields, confirmation copy, and modal width/style metadata. Bulk-only actions
are ignored as row targets. Content-only action URLs also render as normal
fallback pages, so opening a row target outside JavaScript still lands on a
useful action detail page instead of attempting to execute a handler.

Table views turn repeated operational filters into one-click queues:

```php
$resource=$resource->views([
	Panel::view('needs_review')
		->label('Needs review')
		->tone('warning')
		->columns(['id', 'customer', 'status', 'created_at'])
		->filterValue('review_status', 'pending')
		->sort('created_at', 'desc')
		->perPage(50)
		->density('compact')
		->where(fn($record) => ($record['review_status'] ?? null)==='pending'),
	Panel::view('high_risk')
		->label('High risk')
		->tone('danger')
		->filters(['risk_band'=>'high'])
		->range('risk_score', 80, null)
		->where(fn($record) => (int)($record['risk_score'] ?? 0)>=80),
]);
```

Generated pages always include an `All` view. If a view is marked
`default()`, it becomes the initial slice until the operator chooses `All`.
Views may also provide query defaults with `query()`, `search()`,
`filterValue()`, `filters()`, `range()`, `visibleColumns()` / `columns()`,
`sort()`, `perPage()`, and `density()`. Defaults are applied only when the
operator has not supplied an explicit value. Array-backed resources show view
counts automatically.
Paginated query objects receive the resolved view and its defaults in the
`PanelRequest` before the query factory runs, so repository-backed tables can
apply the same queues:

```php
Panel::resource('orders')
	->views([
		Panel::view('open')->default(),
		Panel::view('closed'),
	])
	->queryUsing(function(PanelRequest $request){
		return OrderRepository::queryForPanelView((string)$request->query('view', 'all'));
	});
```

Table summaries cover common aggregate values without a custom dashboard widget:

```php
Panel::summary('orders')->count();
Panel::summary('gross')->sum('total')->money('CAD');
Panel::summary('average_margin')->avg('margin')->percent(1, 1);
Panel::summary('open_orders')
	->label('Open orders')
	->tone('warning')
	->valueUsing(fn(array $records) => count(array_filter(
		$records,
		fn($record) => ($record['status'] ?? null)==='open'
	)));
```

Filters compare against a column with the same name by default, or a custom
column through `column()`:

```php
$resource=$resource->filters([
	Panel::filter('status', 'select')
		->options(['draft'=>'Draft', 'published'=>'Published']),
	Panel::filter('assignee_id', 'select')
		->optionsUsing(fn(PanelRequest $request) => UserRepository::filterOptions($request->user())),
	Panel::filter('enabled', 'boolean'),
	Panel::filter('created_at')->dateRange(),
	Panel::filter('total')->numberRange(),
	Panel::filter('minimum_total')
		->where(fn($record, $value) => (float)$record['total'] >= (float)$value),
]);
```

Select/enum filters accept static options, option groups, or request-aware
`optionsUsing()` callbacks. Invalid option values in the URL are ignored so a
stale link cannot accidentally collapse a table to an impossible state.
Active filters render as chips below the generated controls. Each chip links to
the same table state with only that filter removed; the main Reset link clears
all filters while preserving the current view, search, sort, columns, density,
and page size.
Range filters use `{name}_from` and `{name}_to` query parameters. `dateRange()`
compares ISO date prefixes, while `numberRange()` compares numeric values.

## Notifications And Redirects

Save handlers and action handlers can return a simple string, an
`PanelNotification`, or a structured outcome array.

```php
$resource=$resource->saveUsing(function(array $data){
	save_project($data);

	return [
		'message'=>'Project saved.',
		'redirect'=>PanelConfig::resourceUrl('projects'),
		'notification'=>Panel::notify('Project saved.', 'success', 'Saved'),
	];
});
```

Resources can define native status transitions. Generated row and show pages
render one confirmed POST button per transition that is available for the
record's current status:

```php
$resource=$resource
	->statusField('status')
	->statusTransitions([
		[
			'name'=>'publish',
			'label'=>'Publish',
			'from'=>'draft',
			'to'=>'published',
			'tone'=>'success',
			'confirmation'=>'Publish this order?',
		],
		[
			'name'=>'archive',
			'label'=>'Archive',
			'from'=>['draft', 'published'],
			'to'=>'archived',
			'tone'=>'warning',
		],
	])
	->transitionUsing(function(array $transition, $record){
		OrderRepository::changeStatus($record['id'], $transition['to']);

		return $transition['label'].' completed.';
	});
```

If `transitionUsing()` is not registered, Panel reuses `saveUsing()` with
`[$statusField=>$transition['to']]` and mode `transition`. Transitions check
`transition` and `transition:{name}` authorization, redirect back to the current
table context by default, and use the same outcome contract as saves and
actions. Index tables also expose one selected-records button per transition.
Bulk transitions run the same transition path for each selected record and
summarize changed, unavailable, failed, and denied records.

Declaring transitions also creates table views for every status mentioned in
`from` or `to`. These generated status views behave like normal `view()`
definitions: they show counts, filter local records, affect exports, and can be
overridden by registering a manual view with the same name.

Resources can opt into dashboard status widgets with `statusWidgets()`. Panel
then renders one stat widget per generated status view, with counts, tones, and
links back to the matching resource view:

```php
Panel::resource('orders')
	->statusField('status')
	->statusTransitions([...])
	->statusWidgets();
```

Status widgets use the resource query, respect `dashboard_widgets`
authorization, and stay disabled by default.

Resources can expose record activity on generated show pages with
`activityUsing()`. The handler receives the record, request, and resource, and
returns timeline entries from any source the app owns:

```php
Panel::resource('orders')
	->activityUsing(function($record, PanelRequest $request){
		return [
			[
				'title'=>'Order placed',
				'message'=>'Checkout completed successfully.',
				'time'=>$record['created_at'],
				'actor'=>$record['customer_email'],
				'tone'=>'success',
			],
			[
				'title'=>'Payment review',
				'message'=>'Risk team requested a second look.',
				'time'=>'2026-05-05 10:30:00',
				'actor'=>'Payments',
				'tone'=>'warning',
				'url'=>PanelConfig::resourceUrl('payment-reviews', 'show/'.$record['id']),
			],
		];
	});
```

Activity entries accept `title`, `message`, `time`, `actor`, `tone`, `url`, and
`meta`. String entries are treated as simple titles. The generated renderer
checks `activity` authorization before displaying the section.

Resources can expose record insights on generated show pages with
`insightsUsing()` or `recordInsightsUsing()`. Insights are compact cards for
operator-facing facts such as SLA, margin, risk, fulfillment health, or account
status:

```php
Panel::resource('orders')
	->insightsUsing(fn($record) => [
		[
			'label'=>'Risk',
			'value'=>$record['risk_score'].'%',
			'description'=>'Payment and account review',
			'tone'=>$record['risk_score'] > 60 ? 'danger' : 'success',
		],
		[
			'label'=>'SLA',
			'value'=>'2h left',
			'tone'=>'warning',
			'url'=>PanelConfig::resourceUrl('sla', 'show/'.$record['id']),
		],
	]);
```

Insight entries accept `label`/`title`, `value`, `description`, `tone`, `icon`,
and `url`. Scalar entries are accepted as simple value cards. The generated
renderer checks `insight` authorization before displaying the section.

Resources can expose record alerts on generated show pages with `alertsUsing()`
or `recordAlertsUsing()`. Alerts are short, operator-facing prompts for records
that need review, follow-up, verification, or remediation:

```php
Panel::resource('orders')
	->alertsUsing(fn($record) => $record['risk_score'] > 60 ? [
		[
			'title'=>'Payment review required',
			'message'=>'Risk score is above the automatic release threshold.',
			'tone'=>'danger',
			'action'=>'Review payment',
			'url'=>PanelConfig::resourceUrl('payment-reviews', 'show/'.$record['id']),
			'meta'=>['Risk '.$record['risk_score'].'%', 'Before fulfillment'],
		],
	] : []);
```

Alert entries accept `title`/`label`/`name`, `message`/`description`/`detail`,
`tone`, `url`/`href`/`to`, `action`/`action_label`, and `meta`. Scalar entries
are treated as simple alert messages. Only same-site paths and `http`/`https`
URLs are rendered. The generated renderer checks `alert` authorization before
displaying the section.

Resources can expose record links on generated show pages with `linksUsing()` or
`recordLinksUsing()`. Links are intended for the practical next places an
operator might need: storefront pages, shipment tracking, payment records,
customer profiles, source tickets, logs, or internal app views:

```php
Panel::resource('orders')
	->linksUsing(fn($record) => [
		[
			'label'=>'Storefront order',
			'url'=>'/orders/'.$record['public_id'],
			'description'=>'Open the customer-facing order page',
			'group'=>'Public',
			'tone'=>'primary',
		],
		[
			'label'=>'Carrier tracking',
			'url'=>'https://carrier.example/track/'.$record['tracking_number'],
			'group'=>'Fulfillment',
			'tone'=>'info',
			'external'=>true,
		],
	]);
```

Link entries accept `label`/`title`/`name`, `url`/`href`/`to`, `description`,
`group`, `tone`, `icon`, and `external`. String entries are treated as URLs.
Only same-site paths and `http`/`https` URLs are rendered. The generated renderer
checks `link` authorization before displaying the section.

Resources can expose record contacts with `contactsUsing()` or
`recordContactsUsing()`. Contacts are compact person or organization cards for
customer, seller, owner, assignee, billing, vendor, warehouse, or support
contacts attached to the record:

```php
Panel::resource('orders')
	->contactsUsing(fn($record) => [
		[
			'name'=>$record['customer_name'],
			'role'=>'Customer',
			'email'=>$record['customer_email'],
			'phone'=>$record['customer_phone'],
			'location'=>$record['shipping_city'],
			'status'=>'verified',
			'profile_url'=>PanelConfig::resourceUrl('customers', 'show/'.$record['customer_id']),
		],
	]);
```

Contact entries accept `name`/`label`/`title`/`display_name`, `role`/`type`/
`kind`, `email`/`mail`, `phone`/`telephone`/`mobile`, `company`/`organization`,
`location`/`address`/`city`, `status`/`state`, `url`/`href`/`profile_url`, and
`tone`. String entries are treated as names, or email contacts when they contain
`@`. Only same-site paths and `http`/`https` URLs are rendered. The generated
renderer checks `contact` authorization before displaying the section.

Resources can expose record locations with `locationsUsing()` or
`recordLocationsUsing()`. Locations are compact cards for shipping, billing,
warehouse, pickup, service, office, event, or risk-review addresses:

```php
Panel::resource('orders')
	->locationsUsing(fn($record) => [
		[
			'label'=>'Shipping address',
			'type'=>'Delivery',
			'address1'=>$record['shipping_address1'],
			'address2'=>$record['shipping_address2'],
			'city'=>$record['shipping_city'],
			'province'=>$record['shipping_province'],
			'postal_code'=>$record['shipping_postal_code'],
			'country'=>$record['shipping_country'],
			'status'=>'verified',
			'map_url'=>'https://maps.example/?q='.$record['shipping_postal_code'],
		],
	]);
```

Location entries accept `label`/`title`/`name`, `type`/`kind`/`role`,
`address`/`address1`/`line1`/`street`, `address2`/`line2`/`unit`/`suite`,
`city`/`locality`, `subdivision`/`province`/`state`/`region`, `postal_code`/
`postal`/`zip`, `country`/`country_code`, `lat`/`latitude`, `lng`/`lon`/
`longitude`, `timezone`/`tz`, `status`/`state`, `url`/`href`/`map_url`, and
`tone`. String entries are treated as address text. Only same-site paths and
`http`/`https` URLs are rendered. The generated renderer checks `location`
authorization before displaying the section.

Resources can expose record tags with `tagsUsing()` and optionally add/remove
tags with `tagUsing()` or `updateTagUsing()`:

```php
Panel::resource('orders')
	->tagsUsing(fn($record) => [
		['name'=>'vip', 'label'=>'VIP', 'tone'=>'success'],
		['name'=>'fraud_review', 'label'=>'Fraud review', 'tone'=>'warning'],
	])
	->updateTagUsing(function($record, string $tag, string $action){
		$action === 'add'
			? OrderTags::add($record['id'], $tag)
			: OrderTags::remove($record['id'], $tag);

		return $action === 'add' ? 'Tag added.' : 'Tag removed.';
	});
```

Tag entries accept `name`/`key`/`slug`, `label`/`title`, `description`/
`detail`, `tone`, and `status`. Scalar entries are treated as tag names. The
generated add/remove controls check `tag`, `tag:update`, `tag:add`/
`tag:remove`, and `tag:{name}` authorization before submitting changes.

Resources can expose record items with `itemsUsing()` or `recordItemsUsing()`.
Items are read-only lines for products, services, subscriptions, assets,
devices, packages, invoice lines, or other child units:

```php
Panel::resource('orders')
	->itemsUsing(fn($record) => [
		[
			'title'=>'Wireless keyboard',
			'sku'=>'KB-100',
			'quantity'=>2,
			'price'=>'39.95',
			'total'=>'79.90',
			'currency'=>'CAD',
			'status'=>'fulfilled',
			'item_url'=>PanelConfig::resourceUrl('products', 'show/KB-100'),
		],
	]);
```

Item entries accept `title`/`label`/`name`/`product`/`service`, `sku`/`code`/
`reference`, `type`/`kind`/`category`, `quantity`/`qty`/`count`,
`unit_price`/`price`/`rate`, `total`/`amount`/`subtotal`, `currency`,
`status`/`state`, `url`/`href`/`item_url`, and `tone`. Scalar entries are
treated as item titles. Only same-site paths and `http`/`https` URLs are
rendered. The generated renderer checks `item` authorization before displaying
the section.

Resources can expose record totals with `totalsUsing()` or
`recordTotalsUsing()`. Totals are compact amount cards for subtotal, tax,
shipping, discounts, fees, grand total, paid, refunded, or balance due:

```php
Panel::resource('orders')
	->totalsUsing(fn($record) => [
		'currency'=>'CAD',
		'subtotal'=>$record['subtotal'],
		'tax'=>$record['tax_total'],
		'shipping'=>$record['shipping_total'],
		[
			'label'=>'Balance due',
			'value'=>$record['balance_due'],
			'status'=>$record['balance_due'] > 0 ? 'due' : 'paid',
		],
	]);
```

Total entries accept `label`/`title`/`name`, `value`/`amount`/`total`/
`balance`/`paid`, `currency`, `description`/`detail`, `status`/`state`, and
`tone`. A top-level `currency` value is applied to scalar total entries. The
generated renderer checks `total` authorization before displaying the section.

Resources can expose record approvals with `approvalsUsing()` and resolve them
with `approvalUsing()` or `resolveApprovalUsing()`. Approvals are generated
review actions for workflows such as seller verification, refund release,
payout release, fraud review, catalog publication, or support escalation:

```php
Panel::resource('orders')
	->approvalsUsing(fn($record) => [
		[
			'name'=>'release_refund',
			'title'=>'Release refund',
			'description'=>'Customer refund is waiting for a final review.',
			'requested_by'=>'Support',
			'requested_at'=>'2026-05-05 13:00:00',
			'due_at'=>'2026-05-05 16:00:00',
			'tone'=>'warning',
		],
	])
	->resolveApprovalUsing(function($record, string $approval, string $decision, PanelRequest $request){
		ApprovalQueue::resolve('orders', $record['id'], $approval, $decision, $request->user()?->id);

		return $decision === 'approve' ? 'Approval accepted.' : 'Approval rejected.';
	});
```

Approval entries accept `name`/`id`/`key`, `title`/`label`, `description`,
`status`/`state`, `requested_by`/`requester`/`actor`, `requested_at`/`time`,
`due_at`, and `tone`. Pending approvals render Approve and Reject buttons when a
resolver is registered. The generated renderer checks `approval`,
`approval:resolve`, `approval:{name}`, and `approval:{name}:{decision}`
authorization before submitting a decision.

Resources can expose field-level change history on generated show pages with
`changesUsing()` or `recordChangesUsing()`. The renderer displays each entry as
a before/after comparison with optional actor, time, reason, tone, and source
link:

```php
Panel::resource('orders')
	->changesUsing(fn($record) => AuditLog::forRecord('orders', $record['id'])
		->map(fn($entry) => [
			'field'=>$entry['field'],
			'before'=>$entry['old_value'],
			'after'=>$entry['new_value'],
			'actor'=>$entry['actor_name'],
			'time'=>$entry['created_at'],
			'reason'=>$entry['reason'],
			'tone'=>$entry['field'] === 'status' ? 'info' : 'neutral',
			'url'=>PanelConfig::resourceUrl('audit', 'show/'.$entry['id']),
		])
		->all());
```

Change entries accept `field`/`label`/`name`, `before`/`old`/`from`,
`after`/`new`/`to`, `time`/`changed_at`/`created_at`, `actor`/`user`/`by`,
`reason`/`message`/`description`, `tone`, and `url`/`href`. Scalar entries are
accepted as simple after-values. The generated renderer checks `change`
authorization before displaying the section.

Resources can also expose internal notes on generated show pages. `notesUsing()`
returns existing notes, and `noteUsing()` or `addNoteUsing()` receives new notes
from the generated POST form:

```php
Panel::resource('orders')
	->notesUsing(fn($record) => OrderNotes::forOrder($record['id']))
	->addNoteUsing(function($record, string $note, PanelRequest $request){
		OrderNotes::create([
			'order_id'=>$record['id'],
			'body'=>$note,
			'author_id'=>$request->user()?->id,
		]);

		return Panel::notify('Note added.', 'success');
	});
```

Note entries accept `message`, `note`, `body`, or `text`, plus `author` and
`time`/`created_at`. The generated note form is exposed as a record-section
modal, checks `note` and `note:create` authorization, and redirects back to the
record by default when JavaScript is unavailable.

Resources can expose record messages with `messagesUsing()` and send new
messages with `messageUsing()` or `sendMessageUsing()`. Messages are intended
for outward-facing or system communications, while notes remain internal:

```php
Panel::resource('orders')
	->messagesUsing(fn($record) => MessageLog::forOrder($record['id']))
	->sendMessageUsing(function($record, array $message, PanelRequest $request){
		MessageBus::send([
			'order_id'=>$record['id'],
			'channel'=>$message['channel'],
			'to'=>$message['recipient'],
			'subject'=>$message['subject'],
			'body'=>$message['body'],
			'sent_by'=>$request->user()?->id,
		]);

		return 'Message sent.';
	});
```

Message entries accept `subject`/`title`, `body`/`message`/`text`/`content`,
`channel`/`type`, `status`/`state`, `recipient`/`to`/`customer`,
`sender`/`from`/`actor`, and `time`/`sent_at`/`created_at`. The generated send
form opens as a record-section modal and posts `channel`, `recipient`,
`subject`, and `body`. The renderer checks `message` and `message:send`
authorization before showing or sending messages.

Resources can expose record payments with `paymentsUsing()` or
`recordPaymentsUsing()`. Payments are read-only ledger cards for charges,
refunds, credits, payouts, payment intents, disputes, or account balance events:

```php
Panel::resource('orders')
	->paymentsUsing(fn($record) => [
		[
			'type'=>'charge',
			'title'=>'Card payment',
			'amount'=>'124.95',
			'currency'=>'CAD',
			'status'=>'captured',
			'provider'=>'Stripe',
			'payment_intent'=>$record['payment_intent'],
			'paid_at'=>$record['paid_at'],
			'dashboard_url'=>'https://dashboard.stripe.com/payments/'.$record['payment_intent'],
		],
	]);
```

Payment entries accept `title`/`label`/`name`, `type`/`kind`/`event`,
`amount`/`value`/`total`/`gross`, `amount_label`, `currency`, `status`/`state`,
`provider`/`processor`/`gateway`, `reference`, `transaction_id`,
`payment_intent`, `charge_id`, `refund_id`, `payout_id`, `time`/`paid_at`/
`created_at`, `url`/`href`/`dashboard_url`, and `tone`. Scalar entries are
accepted as simple amount cards. Only same-site paths and `http`/`https` links
are rendered. The generated renderer checks `payment` authorization before
displaying the section.

Resources can expose record shipments with `shipmentsUsing()` or
`recordShipmentsUsing()`. Shipments are rendered as compact fulfillment cards
with carrier, service, tracking number, status, ETA, route, and safe tracking
links:

```php
Panel::resource('orders')
	->shipmentsUsing(fn($record) => [
		[
			'title'=>'Package 1',
			'carrier'=>'Canada Post',
			'service'=>'Expedited Parcel',
			'tracking_number'=>'4000000000000000',
			'status'=>'in_transit',
			'estimated_delivery'=>'2026-05-08',
			'origin'=>'Montreal, QC',
			'destination'=>'Toronto, ON',
			'tracking_url'=>'https://carrier.example/track/4000000000000000',
		],
	]);
```

Shipment entries accept `title`/`label`/`name`, `tracking`/`tracking_number`,
`carrier`/`provider`, `service`/`method`, `status`/`state`, `eta`/
`estimated_delivery`, `origin`/`from`, `destination`/`to`, `url`/`href`/
`tracking_url`, and `tone`. Scalar entries are treated as tracking numbers.
Only same-site paths and `http`/`https` links are rendered. The generated
renderer checks `shipment` authorization before displaying the section.

Resources can expose record attachments with `attachmentsUsing()` and accept new
uploads with `attachUsing()` or `uploadAttachmentUsing()`:

```php
Panel::resource('orders')
	->attachmentsUsing(fn($record) => OrderFiles::forOrder($record['id']))
	->uploadAttachmentUsing(function($record, array $file, PanelRequest $request){
		OrderFiles::storeUploadedFile($record['id'], $file['tmp_name'], $file['name']);

		return Panel::notify('Attachment uploaded.', 'success');
	});
```

Attachment entries accept `name`/`filename`, `url`, `type`/`mime`, `size`,
`uploaded_at`/`created_at`, and `author`. String entries are treated as URLs.
The generated upload form opens as a record-section modal, checks `attachment`
and `attachment:create` authorization, and redirects back to the record by
default.

Resources can expose record tasks with `tasksUsing()` and handle complete/reopen
updates with `taskUsing()` or `updateTaskUsing()`. Add `createTaskUsing()` or
`addTaskUsing()` to render the generated add-task form:

```php
Panel::resource('orders')
	->tasksUsing(fn($record) => [
		[
			'name'=>'verify_address',
			'title'=>'Verify shipping address',
			'description'=>'Confirm the address before purchasing a label.',
			'due_at'=>'2026-05-05 16:00:00',
			'assignee'=>'Support',
			'completed'=>false,
		],
	])
	->updateTaskUsing(function($record, string $task, bool $completed){
		OrderTasks::setCompleted($record['id'], $task, $completed);

		return $completed ? 'Task completed.' : 'Task reopened.';
	})
	->addTaskUsing(function($record, array $task){
		OrderTasks::create($record['id'], $task);

		return 'Task added.';
	});
```

Task entries accept `name`/`id`, `title`/`label`, `description`, `completed`,
`status`, `due_at`, `assignee`, and `tone`. The generated buttons check `task`,
`task:update`, and `task:{name}` authorization. The add-task form checks
`task:create` and opens as a record-section modal. Complete/reopen actions use
the same generated confirmation modal path as other Panel actions. Both paths
redirect back to the record by default when JavaScript is unavailable.

Resources can also define a native delete handler. Generated row and show pages
render a confirmed POST delete button when `deleteUsing()` is present:

```php
$resource=$resource->deleteUsing(function($record){
	OrderRepository::delete($record['id']);

	return [
		'message'=>'Order deleted.',
		'redirect'=>PanelConfig::resourceUrl('orders'),
	];
});
```

Delete handlers use the same outcome contract as saves and actions. If no
redirect is returned, Panel redirects back to the current table context and
flashes the delete notification.

Permanent deletion is a separate handler so soft delete and purge can coexist.
Generated row and show pages render a confirmed POST Force delete button when
`forceDeleteUsing()` is present:

```php
$resource=$resource->forceDeleteUsing(function($record){
	OrderRepository::forceDelete($record['id']);

	return 'Order permanently deleted.';
});
```

Force delete handlers use the same outcome contract as saves, deletes, and
actions. Returning `['force_deleted'=>false]` marks that record as failed during
bulk force delete. Index tables expose a Force delete selected button, resolve
the selected records, check `force_delete` authorization for each record, and
summarize permanently deleted, failed, and denied records.

Resources can define a native duplicate handler. Generated row and show pages
render a confirmed POST duplicate button when `duplicateUsing()` is present:

```php
$resource=$resource->duplicateUsing(function($record){
	$copy=OrderRepository::duplicate($record['id']);

	return [
		'message'=>'Order duplicated.',
		'redirect'=>PanelConfig::resourceUrl('orders', 'edit/'.$copy['id']),
	];
});
```

Duplicate handlers use the same outcome contract as saves, deletes, and actions.
If no redirect is returned, Panel redirects back to the current table context and
flashes the duplicate notification.

Resources can define a native restore handler for soft-deleted or archived
records. Generated row and show pages render a confirmed POST restore button
when `restoreUsing()` is present:

```php
$resource=$resource->restoreUsing(function($record){
	OrderRepository::restore($record['id']);

	return 'Order restored.';
});
```

Restore handlers use the same outcome contract as saves, deletes, duplicates,
and actions. If no redirect is returned, Panel redirects back to the current
table context and flashes the restore notification. Index tables also expose a
native Restore selected button. Bulk restore resolves the selected records,
checks restore authorization for each record, runs the same restore handler, and
summarizes restored, failed, and denied records.

When `duplicateUsing()` is present, index tables also expose a native bulk
duplicate button in the selected-records action bar. Bulk duplicate resolves the
selected records, checks duplicate authorization for each record, runs the same
duplicate handler, and redirects back to the current table context with a summary
notification. Empty selections are rejected before the handler is called.

When `deleteUsing()` is present, index tables also expose a native bulk delete
button in the selected-records action bar. Bulk delete resolves the selected
records, checks delete authorization for each record, runs the same delete
handler, and redirects back to the current table context with a summary
notification. Empty selections are rejected before the handler is called.

Resources can also expose a native bulk update form. Define the editable fields
with `bulkFields()` or `bulkField()`. Panel renders an Edit selected button,
validates the bulk form with the same field lifecycle, and then calls
`bulkUpdateUsing()` once for all records. If no bulk handler is registered,
Panel reuses `saveUsing()` once per selected record with mode `bulk_update`.

```php
$resource=$resource
	->bulkFields([
		Panel::field('status', 'select')
			->required()
			->options(['draft'=>'Draft', 'live'=>'Live']),
		Panel::field('review_note')->rules('max:120'),
	])
	->bulkUpdateUsing(function(array $data, array $records){
		foreach($records as $record){
			OrderRepository::update($record['id'], $data);
		}

		return count($records).' orders updated.';
	});
```

Action handlers use the same contract:

```php
Panel::action('scan')
	->successMessage('Scan queued.')
	->confirmation('Queue a fresh scan for this record?')
	->handle(function($record){
		queue_scan($record);
	});
```

Actions can be grouped without changing their execution route. Groups are
rendering containers: each nested action still resolves by its own action name,
authorization callback, form schema, modal settings, confirmation message, bulk
state, and handler.

```php
Panel::resource('orders')
	->actionGroup('fulfillment', [
		Panel::action('print_label')->label('Print label'),
		Panel::action('mark_shipped')->label('Mark shipped')->tone('success'),
	])
	->actionGroup(
		Panel::actionGroup('review')
			->label('Review')
			->tone('neutral')
			->outlined()
			->compact()
			->dropdownWidth('lg')
			->alignStart()
			->section('Review flow', 'Decision actions')
			->action(Panel::action('approve')->tone('success'))
			->divider()
			->section('Exceptions')
			->action(Panel::action('reject')->tone('danger')->requiresConfirmation())
	);
```

Action groups support the same button presentation language as actions:
`style()` / `variant()`, `outlined()`, `ghost()`, `link()`, `size()`,
`compact()`, `large()`, and `iconOnly()` / `iconButton()`. Use
`dropdownWidth('sm'|'md'|'lg'|'xl'|'auto')` when grouped action labels need more
or less room than the default menu. Use `dropdownAlignment('start'|'center'|'end')`
or `alignStart()`, `alignCenter()`, and `alignEnd()` when the menu should open
from a specific edge of the trigger. The browser runtime keeps open action
menus inside the viewport by clamping fixed-position dropdowns on resize,
scroll, and open. It also assigns menu roles and supports Arrow Up/Down,
Home/End, Escape, and Tab close behavior for generated action-group menus.
`section()` / `heading()` and `divider()` add generated menu structure without
changing the action route or handler. Array-based definitions can use
`Panel::actionGroupSection()` and `Panel::actionGroupDivider()` markers between
child actions.

Generated action buttons use POST/redirect/get by default. If an action handler
does not return a redirect, Panel redirects back to the current Panel table or
record context and flashes the success message. Action forms carry the same
return target through validation and the Cancel button.

Actions can be visible but unavailable. Use `disabled()` when the operator
should understand that a workflow step exists but is blocked by the current
record state:

```php
Panel::action('capture_payment')
	->label('Capture')
	->disabled(
		fn($order) => ($order['status'] ?? null) !== 'paid',
		fn($order) => 'Payment capture requires Paid status.'
	)
	->handle(fn($order) => Payments::capture($order));
```

Disabled actions render with `disabled`, `aria-disabled`, a title, and
`data-dp-panel-disabled-reason`. Direct requests to the action endpoint return an
`Action unavailable` result instead of running the handler. Authorization still
controls whether an action appears at all; disabled state controls whether a
visible action can currently run.

Use `visible()` or `hidden()` when an action should only exist for certain
record or page states:

```php
Panel::action('critical_escalation')
	->visible(fn($order) => ($order['risk'] ?? null) === 'critical')
	->handle(fn($order) => Escalations::open($order));
```

Hidden actions are removed from generated buttons and action groups. Direct
requests to a hidden action return a not-found result for that state instead of
falling through to authorization or execution. Visibility, disabled state, and
authorization are intentionally separate: visibility describes workflow shape,
disabled state explains temporary blockers, and authorization represents user
permission.

Action presentation can also be resolved from the current record or request.
`label()`, `icon()`, and `tone()` accept callbacks, so a single action can
rename itself, swap icons, and change color without cloning the resource:

```php
Panel::action('capture_payment')
	->label(fn($order) => ($order['status'] ?? null) === 'paid' ? 'Capture payment' : 'Capture later')
	->icon(fn($order) => ($order['status'] ?? null) === 'paid' ? 'credit-card' : 'clock')
	->tone(fn($order) => ($order['status'] ?? null) === 'paid' ? 'success' : 'warning')
	->disabled(fn($order) => ($order['status'] ?? null) !== 'paid')
	->handle(fn($order) => Payments::capture($order));
```

Dynamic presentation is resolved for table row actions, record-page actions,
bulk actions, page actions, action groups, confirmation screens, action forms,
modal metadata, and `PanelActionState`. Raw action definitions still expose
`label_dynamic`, `icon_dynamic`, `tone_dynamic`, `badge_dynamic`,
`badge_tone_dynamic`, `tooltip_dynamic`, and `description_dynamic` so tools can
distinguish static configuration from resolved runtime presentation.

Actions can also declare their visual treatment without custom CSS. Use
`style()` / `variant()` for `solid`, `outline`, `ghost`, or `link`, or the
helpers `outlined()`, `ghost()`, `subtle()`, and `link()`. Use `size()` for
`xs`, `sm`, `md`, `lg`, or `xl`, with `compact()` and `large()` as shortcuts.
`iconOnly()` / `iconButton()` hides the text visually while preserving an
accessible label:

```php
Panel::action('snapshot')
	->icon('eye')
	->iconButton()
	->outlined()
	->tooltip('Open a compact read-only snapshot.');

Panel::action('risk_review')
	->tone('danger')
	->large();
```

The renderer emits variant and size classes consistently for row actions,
record-page actions, action groups, bulk actions, and modal triggers. The
action manifest includes `style`, `size`, and `icon_only` so clients can mirror
server-rendered presentation.

Actions may also carry concise badges and tooltips. Both can be static or
record-aware. `description()` / `descriptionUsing()` adds short explanatory copy
for richer generated menus. Toolbar buttons stay compact, while action group
menus and row-more menus reveal the description under the label:

```php
Panel::action('capture_payment')
	->description(fn($order) => 'Capture funds for '.($order['order_number'] ?? 'this order').'.')
	->badge(fn($order) => strtoupper($order['status'] ?? ''))
	->badgeTone(fn($order) => ($order['status'] ?? null) === 'paid' ? 'success' : 'warning')
	->tooltip(fn($order) => ($order['status'] ?? null) === 'paid'
		? 'Ready to capture because this order is paid.'
		: 'Capture unlocks when the order reaches Paid.');
```

Badges are rendered inside generated action buttons and stay present in action
groups, row menus, modal triggers, and mobile action layouts. Tooltips are
emitted as native `title` text plus `data-dp-panel-action-tooltip` for richer
clients. Disabled actions keep the disabled reason as their title so the blocker
remains the primary explanation.

Actions can advertise keyboard bindings with `keyBinding()` or `keyBindings()`.
The generated button receives `data-dp-panel-key-bindings` and
`aria-keyshortcuts`; Panel's client dispatcher activates the first visible,
enabled matching action while ignoring typing fields and disabled controls:

```php
Panel::action('capture_payment')
	->keyBinding('mod+shift+p');

Panel::action('critical_escalation')
	->keyBindings(['mod+shift+e', 'ctrl+alt+e']);
```

Use `mod` for Ctrl on Windows/Linux and Command on macOS. Bindings normalize
common aliases such as `cmd`, `command`, `control`, `option`, `esc`, and
`return`. The command palette reads the same metadata and shows the shortcut
hint beside matching actions, so keyboard affordances stay tied to the action
definition instead of a route or controller.

Generated action controls can carry safe host attributes with
`extraAttributes()`, `attributes()`, `attribute()`, `data()`, or `aria()`.
Static maps and record-aware callbacks are both supported:

```php
Panel::action('capture_payment')
	->extraAttributes(static fn($order): array => [
		'data-qa'=>'capture-payment-action',
		'data-order-status'=>$order['status'] ?? 'unknown',
		'aria-label'=>'Capture payment for '.($order['order_number'] ?? 'this order'),
		'class'=>'qa-critical-action',
	]);

Panel::action('critical_escalation')
	->data('qa', 'critical-escalation-action')
	->aria('label', 'Escalate this critical order');
```

Extra attributes are resolved with the same record, request, resource, and
action context as dynamic labels, badges, and tooltips. Panel keeps its own
internal control attributes authoritative: `data-dp-panel-*`, disabled state,
modal metadata, shortcut metadata, titles, form targets, names, values, event
handlers, and inline styles are reserved. Hosts may add `data-*`, `aria-*`,
`class`, `id`, `role`, `tabindex`, `download`, `target`, and `rel`; false and
null values are omitted, while true values render as boolean attributes.

Modal action forms are progressively enhanced. A direct action form URL renders
as a normal generated page, while requests carrying
`X-Requested-With: DataphyrePanelModal` and, when needed,
`__panel_partial=modal` return only the form fragment with the same status and
structured result metadata. This
keeps custom emitters, non-JavaScript clients, and tests on the full-page path
while letting interactive panels open and re-render action modals without
downloading a complete shell.

Bulk actions receive the selected records as the handler's first argument and
refuse an empty selection unless `allowEmptySelection()` is set:

```php
Panel::action('archive_selected')
	->bulk()
	->requiresConfirmation()
	->successMessage('Selected records archived.')
	->handle(function(array $records){
		foreach($records as $record){
			archive_record($record);
		}
	});
```

Outcome keys:

- `message`: text shown on the result page.
- `notification` or `notifications`: one or more `PanelNotification`, array, or
  string notices.
- `redirect` or `redirect_to`: target URL for a `303` response by default.
- `status`: optional 3xx redirect status.

When a generated save or action response redirects and a PHP session is active,
notifications are flashed into the session and displayed once on the next
generated Panel page.

Notifications use one shape across full-page responses, redirects, modal
submissions, and AJAX fragments. A notification may include a title, icon,
action link, display duration, and persistence flag:

```php
return [
	'message'=>'Order was assigned.',
	'notification'=>PanelNotification::success('Ownership moved to Mina.', 'Assignment complete')
		->icon('user-check')
		->action('Open order', '/debug?resource=orders&operation=show&record=42')
		->duration(5200),
];
```

Use `persistent()` for warnings or failures that should stay visible until the
operator dismisses them. Array notifications accept the same keys:
`type`, `title`, `message`, `icon`, `action_label`, `action_url`,
`duration_ms`, `persistent`, and `meta`. Generated pages render flashed
notifications as inline notices at the top of the page and expose the same
payload to the browser toast system on boot; partial and modal responses carry
the same payload in JSON so custom JavaScript does not need its own notification
format.

`PanelPageResult::isRedirect()`, `redirectTo()`, and `notifications()` expose the
same data for custom emitters and tests.

## Form Lifecycle

Panel forms follow an explicit lifecycle:

1. `state()` builds the server-owned snapshot for current, initial, dehydrated,
   dirty, computed, and optionally validated values.
2. `hydrate()` prepares field values from a record, defaults, or submitted input.
3. `dehydrate()` turns submitted field values into save-ready data.
4. `validate()` applies field rules and custom validators.
5. `submit()` dehydrates then validates.
6. `saveUsing()` runs only when the submitted form is valid.

Failed validation renders the form again with submitted values and inline field
messages. The page result uses HTTP status `422` and includes the structured form
state in `PanelPageResult::data()`.

```php
Panel::field('email')
	->required()
	->rules(['email']);

Panel::field('title')
	->rules(['min:3', 'max:120'])
	->dehydrateUsing(fn($value) => trim((string)$value))
	->validateUsing(function($value){
		return str_contains((string)$value, 'draft')
			? 'Title should not contain draft.'
			: [];
	});

Panel::field('links', 'repeater')
	->label('Useful links')
	->minItems(1)
	->maxItems(5)
	->addItemLabel('Add link')
	->repeaterFields([
		Panel::field('label')->required(),
		Panel::field('url')->rules('url')->required(),
	]);

Panel::field('receipt', 'file_upload')
	->acceptedTypes(['image/*', '.pdf'])
	->maxFileSize(5 * 1024 * 1024);
```

Generated forms include richer field primitives that share the same state,
dehydration, validation, and live update lifecycle:

```php
Panel::field('risk', 'radio')
	->options(['low'=>'Low', 'critical'=>'Critical'])
	->required();

Panel::field('markets', 'checkbox_list')
	->options(['CA'=>'Canada', 'US'=>'United States']);

Panel::field('segments', 'multi_select')
	->options(['retail'=>'Retail', 'wholesale'=>'Wholesale']);

Panel::field('tags', 'tags')
	->tagSeparator(',');

Panel::field('metadata', 'key_value')
	->keyValueSeparators("\n", '=');

Panel::field('brand_color', 'color');

Panel::field('priority', 'range')
	->min(1)
	->max(10)
	->step(1);
```

`radio` submits one option value. `checkbox_list` and `multi_select` submit an
array of option values and validate each submitted value against the current
option list. `tags` dehydrates comma- or newline-separated text into a unique
array of tags. `key_value` accepts JSON or `key=value` lines and dehydrates into
an associative array. `hidden`, `url`, `tel`, `time`, `color`, `range`,
`rich_editor`, and `rich_text` are first-class field types, and custom field
renderers registered through `PanelComponentRegistry::registerFieldType()` are
consulted before the built-in renderer.

Built-in field rules:

- `required`
- conditional required helpers: `requiredWhen()` and `requiredUnless()`
- `email`
- `number` / `numeric`
- `integer`
- `url`
- `min:n`
- `max:n`
- `in:a,b,c`

Repeater fields submit as arrays of rows. Blank rows are discarded during
dehydration, child field rules are validated per row, and `minItems()` /
`maxItems()` control the number of accepted rows. The generated UI uses a
disabled template row for client-side add/remove behavior while server-side
validation remains authoritative.

File fields use the `file`, `file_upload`, `upload`, `drag_drop_upload`, or
`image` field types.
Generated create, edit, bulk, and action forms automatically use multipart
encoding when a file field is present. Submitted values are normalized PHP upload
arrays; `multiple()` returns a list of upload arrays, while single upload fields
return one upload array. On edit, leaving the file input empty preserves the
record value. `acceptedTypes()` accepts MIME types, wildcard groups such as
`image/*`, or file extensions, and `maxFileSize()` validates the uploaded byte
size.

Use `customUploader()` or `dragDropUpload()` for the Panel uploader shell with
drag/drop, accepted-type and size policy display, queue rows, transfer status,
progress bars, validation errors, chunked uploads, and retry controls.
`uploadEndpoint()`,
`uploadChunkSize()`, `uploadRetries()`, and `uploadConcurrency()` tune the
runtime. `uploadMinFiles()` and `uploadMaxFiles()` add client and server count
validation for custom uploader payloads. `uploadHeaders()`, `uploadHeader()`,
`uploadFields()`, `uploadField()`, and `uploadCsrf()` attach per-chunk request
metadata for secured custom upload endpoints. `uploadDeleteEndpoint()` (or
`deleteEndpoint()`) makes completed/stored row removal call a backend cleanup
endpoint before the hidden payload is changed. `storageUploader('local',
'panel_uploads/{date}/{filename}')` wires the active Panel upload endpoint
(`/dataphyre/panel/upload` by default, or the mounted route endpoint such as
`/admin/upload` when the panel is dispatched through `Panel::mountedRoutes()`).
The endpoint assembles chunks and persists the completed file through Dataphyre
Storage; it also accepts stored-file delete requests.
The storage path template supports `{date}`, `{field}`, `{collection}`,
`{filename}`, `{original}`, `{name}`, `{ext}`, `{hash}`, and `{id}`.

Calling `rules()` appends rules, so `required()->rules('email')` keeps both
rules. Use `required(false)` to remove the required rule.

## Relation Managers

Relation managers attach child tables to a resource. They can be rendered inside
the parent `show` page and dispatched directly through the `relation` operation.

```php
$resource=$resource->relation(
	Panel::relation('orders')
		->label('Recent orders')
		->parentTitleUsing(fn($customer) => $customer['name'])
		->description(fn($customer) => 'Orders placed by '.$customer['name'].'.')
		->badgeUsing(fn(array $orders) => count($orders).' orders')
		->emptyState('No orders yet.', 'This customer has no related orders in the current workspace.')
		->relatedResource('orders')
		->foreignKey('customer_id')
		->localKey('id')
		->perPage(10)
		->columns([
			Panel::column('order_number')->searchable(),
			Panel::column('status'),
			Panel::column('total', 'money'),
		])
		->filter(Panel::filter('status', 'select')->options([
			'open'=>'Open',
			'paid'=>'Paid',
			'cancelled'=>'Cancelled',
		]))
		->view(Panel::view('open')->where(
			fn($order) => ($order['status'] ?? null) === 'open'
		))
		->facts([
			Panel::summary('order_count')->count(),
			Panel::summary('revenue')->sum('total')->money('CAD')->tone('success'),
		])
		->summary(Panel::summary('total_orders')->count())
		->queryUsing(function($customer){
			return OrderRepository::forCustomer($customer);
		})
);
```

Relation queries can return:

- a plain array of rows or records
- a Dataphyre SQL table/repository query exposing `get()` or `getRecords()`
- a paginated query exposing `paginate()` or `paginateRecords()`

Relation tables use the same table grammar as resources: sortable/searchable
columns, filters, range filters, table views, summaries, default sort,
pagination, and per-page options. When several relations render on the same
record page their state is scoped with relation-prefixed query parameters, so
one child table cannot overwrite another child table's search, filter, view, or
page state.

Relation manager headers are parent-aware. `parentTitleUsing()` controls the
record label shown above the relation, `description()` and `badgeUsing()` may be
static strings or callbacks, and `emptyState()` accepts a heading plus optional
description. `facts()` accepts `TableSummary` definitions and resolves them
against the full related dataset before table filters and pagination, while
regular `summary()` values remain tied to the current relation view.

Generated relation surfaces are backed by `PanelRelationState`. The state carries
the relation definition, parent record identity, relation-scoped request,
resolved columns, all related records, filtered records, current page records,
view counts, facts, empty-state copy, and the nested `PanelTableState`.

Direct relation pages expose this as `relation_state` in the `PanelPageResult`
data, while embedded show-page relations record `relation.state` for Flightdeck
and tests:

```php
$result=Panel::dispatch([
	'resource'=>'customers',
	'operation'=>'relation',
	'record'=>'42',
	'relation'=>'orders',
]);

$state=$result->data()['relation_state'] ?? null;
```

When `relatedResource()` points to another registered resource, relation rows
inherit that resource's row controls: View, Edit, native delete, and record
actions. Submitted buttons carry a safe return target back to the parent record
or direct relation page. Use `readOnly()` when a relation should expose only
record links while hiding mutating controls.

Relations with `relatedResource()`, `foreignKey()`, and `localKey()` also render
a Create button. The child create form receives `prefill[foreign_key]` from the
parent record's local key and carries `return_to` back to the parent relation.
Define the foreign-key field on the child resource, usually as a hidden required
field, when the save handler should receive it:

```php
Panel::resource('orders')
	->field(Panel::field('customer_id', 'integer')->hidden()->required())
	->saveUsing(function(array $data){
		return OrderRepository::create($data);
	});
```

Relations can also expose Filament-style attach and detach operations without
hardcoding routes. Register attachable records plus mutators on the relation:

```php
Panel::relation('items')
	->attachLabel('Attach product')
	->detachLabel('Remove line')
	->attachableRecordsUsing(fn($order) => ProductRepository::availableFor($order))
	->attachUsing(function($order, string $productKey, PanelRequest $request){
		return OrderRepository::attachProduct($order, $productKey);
	})
	->detachUsing(function($order, string $lineKey, PanelRequest $request){
		return OrderRepository::removeLine($order, $lineKey);
	});
```

Panel renders `attachUsing()` as a generated modal in the relation toolbar and
`detachUsing()` as row actions. The generated forms post back through the
relation operation, keep relation-scoped search, filters, view, sort, and per
page state, then redirect back to the current parent context. Authorization can
distinguish `view`, `create`, `attach`, and `detach` inside the relation
authorizer.

### Relation Manager Operations

Advanced relationship operations use the same relation contract. `associate`
and `dissociate` are available for relationships where the related record
already exists and the operation changes ownership rather than creating or
removing a join row. `reorderUsing()` describes sortable child records, and
`pivotFields()` describes editable join metadata:

```php
Panel::relation('items')
	->associateLabel('Associate product')
	->dissociateLabel('Unlink product')
	->reorderLabel('Reorder lines')
	->associateUsing(fn($order, string $productKey) => OrderRepository::associateProduct($order, $productKey))
	->dissociateUsing(fn($order, string $lineKey) => OrderRepository::dissociateLine($order, $lineKey))
	->reorderUsing(fn($order, array $lineKeys) => OrderRepository::reorderLines($order, $lineKeys), 'position')
	->pivotFields([
		Panel::field('quantity', 'number')->required()->min(1),
		Panel::field('supplier_note', 'textarea')->maxLength(180),
	])
	->updatePivotUsing(function($order, string $lineKey, array $values){
		return OrderRepository::updateLinePivot($order, $lineKey, $values);
	});
```

The relation manifest includes these operation labels, handler flags, pivot
field schemas, and the optional order column. `PanelRelationState` exposes the
same operations as structured `entries` for renderers: each entry has a stable
name, label, `enabled` flag, `authorized` flag, modal label, disabled reason,
and operation-specific metadata such as `pivot_fields` or `order_column`.
`readOnly()` keeps the entries visible for inspection but disables mutating
operations so generated toolbars and row actions can hide or explain them
without serializing callbacks. Renderers use the same action form convention for
attach, associate, detach, dissociate, reorder, and update-pivot submissions, so
nested relation pages, slide-over editors, ordering controls, and compact row
actions do not need app-specific route conventions.

Relation authorization is independent from the parent resource:

```php
Panel::relation('payments')
	->authorize(fn($ability, $record, $user) => $user?->can('view_payments') === true);
```

## Inspection Trace

The Panel framework records a compact lifecycle trace for Flightdeck and tests.
It is intentionally framework-local: Flightdeck can read it without the Panel
module needing to render a Flightdeck-specific panel.

```php
$summary=Panel::traceSummary();
$events=Panel::trace();
```

Recorded events include:

- `resource.registered`
- `page.registered`
- `command.registered`
- `request.dispatch`
- `request.render`
- `page.dashboard`
- `page.custom`
- `page.index`
- `global_search.completed`
- `surface.state`
- `navigation.state`
- `commands.state`
- `page.form`
- `page.show`
- `infolist.state`
- `relation.state`
- `relation.action.start`
- `relation.action.completed`
- `widgets.state`
- `form.hydrated`
- `form.dehydrated`
- `form.validated`
- `save.start`
- `save.validation_failed`
- `save.completed`
- `action.state`
- `transition.start`
- `transition.completed`
- `bulk_transition.start`
- `bulk_transition.completed`
- `action.start`
- `action.completed`
- `action.lifecycle_result`
- `page_action.state`
- `page_action.lifecycle_result`
- `export.csv`
- `export.json`
- `bulk_export.start`
- `bulk_export.completed`
- `import.form`
- `import.template`
- `import.start`
- `import.preview`
- `import.completed`
- `relation.render`
- `relation.records`
- `request.forbidden`

Trace entries are capped to the latest 200 events, deduplicated across the
current request and the retained session trace, and sanitize records/objects down
to compact metadata so forms and action payloads do not leak large or sensitive
application data into diagnostics.

## Testing Harness

`Panel::test()` and `$panel->test()` create a route-free test harness for Panel
definitions. The harness dispatches through the same `PanelInstance`,
`PanelManager`, `PanelRequest`, and renderer contracts used by hosted pages, but
it does not require `/admin`, `/debug`, or any application route.

```php
$panel=Panel::make('ops')
	->register($ordersResource);

$test=$panel->test();

$result=$test->render('orders', 'index', ['records'=>$orders]);
PanelTestHarness::assertOk($result);
PanelTestHarness::assertSee($result, 'Orders');

$table=$test->tableState('orders', $orders, ['status'=>'paid']);
PanelTestHarness::assertTableCount($table, count($orders));
PanelTestHarness::assertTableColumn($table, 'total');

$form=$test->validateForm('orders', ['customer'=>'']);
PanelTestHarness::assertFormInvalid($form, 'customer');

$action=$test->actionState('orders', 'approve', $orders[0]);
PanelTestHarness::assertActionVisible($action);
PanelTestHarness::assertActionEnabled($action);
```

The harness currently covers:

- HTML results, status codes, redirects, notifications, and response data.
- Resource and page dispatch through `render()`, `dispatch()`, `fragment()`,
  and `modal()`.
- Table state assertions for record counts, totals, visible/all columns, active
  filters, views, groups, pagination, and summaries through `PanelTableState`.
- Form hydration, dehydration, validation, field values, field errors, and dirty
  state through `PanelFormState`.
- Action visibility, authorization, disabled state, validation, execution
  result, selected counts, and record context through `PanelActionState`.
- Navigation, command palette, and panel manifest inspection without rendering a
  browser shell.
- Route-free accessibility audits through `PanelAccessibilityAudit`.

This is the first-party testing layer for Filament-style resources. Browser
regression testing still belongs to Playwright/Puppeteer, but framework tests can
now prove that a Panel definition, action, form, table, manifest, or modal
contract behaves correctly before a CSS or JavaScript runtime is involved.

### Accessibility Audits

`Panel::accessibilityAudit()`, `$panel->accessibilityAudit()`, and
`$panel->test()->accessibilityAudit()` inspect generated HTML without a hosted
route. The audit is intentionally a baseline regression check, not a replacement
for browser, keyboard, and screen-reader testing.

```php
$result=$panel->test()->render('orders', 'index', ['records'=>$orders]);
$audit=$panel->accessibilityAudit($result);

$audit->passed();     // true when no error-level findings exist
$audit->score();      // simple 0-100 regression score
$audit->issues();     // structured findings

PanelTestHarness::assertAccessible($audit);
```

The route-free audit checks:

- duplicate ids.
- buttons and links without accessible names.
- images missing `alt` or `aria-hidden`.
- form controls without labels, ARIA labels, titles, or placeholders.
- dialogs missing `aria-modal` or labels.
- broken ARIA id references.
- malformed `aria-live` values.
- missing reduced-motion and live-region hooks.

Use this in generated package tests and local examples to catch regressions
early, then layer Playwright/Puppeteer, axe, visual checks, and real assistive
technology passes on top for production confidence.

### Accessibility Policies

Panel fields, form sections, and resource forms can declare browser-enforced
accessibility policies as code. These policies are rendered as
`data-dp-panel-a11y-*` attributes, then the Panel browser runtime evaluates
usable width, label/adornment pressure, touch target size, and contrast after
layout settles.

```php
$panel->field('email', 'email')
	->minUsableCharacters(28)
	->minTouchTarget(44)
	->maxLabelRatio(0.5)
	->maxAdornmentRatio(0.45)
	->contrastPolicy(4.5, 'control');

$panel->schema([...])
	->columns(['default'=>1, 'md'=>6, 'xl'=>12])
	->meta(['accessibility'=>[
		'min_usable_chars'=>24,
		'min_touch_target'=>44,
		'contrast_policy'=>['min_ratio'=>4.5, 'scope'=>'control'],
	]]);
```

When policies are active, Panel summarizes each evaluated container and emits a
`DataphyrePanelAccessibilityPolicy` browser event. The summary includes
`checked`, `issue_count`, `adjustment_count`, `fields`, `issues`, and
`adjustments`. Its `status` is `needs_attention` when issues remain, `adjusted`
when automatic layout recoveries were applied without remaining issues, or
`pass` when all checked fields satisfy policy. Flightdeck listens for that event,
shows issue and adjustment rows in its Accessibility tab, records token counts
for retained snapshots, and keeps the last actionable rows visible if a later
pass report arrives without field rows.

Width recovery is row-aware. Panel first snapshots the original visual rows,
then expands fields that fail usable-width or adornment pressure checks. If a
field moves out of a crowded row, the remaining siblings in that original row are
reflowed across the available grid columns and reported with the `row_reflowed`
adjustment token.

### Regression Suites

`Panel::regressionSuite()` and `$panel->regressionSuite()` turn route-free
assertions into a named manifest. Each check records status, duration, message,
details, metadata, and failure location. This makes local examples and package
fixtures useful as repeatable regression targets instead of informal demos.

```php
$suite=$panel->regressionSuite('orders_showroom')
	->check('Orders index renders', function(PanelTestHarness $test) use ($orders): array {
		$result=$test->render('orders', 'index', ['records'=>$orders]);
		PanelTestHarness::assertOk($result);
		PanelTestHarness::assertSee($result, 'Orders');

		return ['message'=>'Orders index rendered.'];
	})
	->check('Table columns exist', function(PanelTestHarness $test) use ($orders): array {
		$table=$test->tableState('orders', $orders);
		PanelTestHarness::assertTableColumn($table, 'number');
		PanelTestHarness::assertTableColumn($table, 'status');

		return ['visible_columns'=>count($table->visibleColumns())];
	})
	->skip('Browser screenshot parity', 'Handled by Playwright.');

$report=$suite->run();
$report->ok();       // true when no checks failed
$report->toArray();  // serializable regression report
```

The bundled CLI runner executes the live example or a suite file without a
route. It exits `0` when checks pass, `1` when checks fail, and `2` when a suite
cannot be loaded.

```powershell
& '.\.local\shopiro\php\php.exe' 'common\dataphyre\runtime\modules\panel\kernel\panel_regression.php' --example
& '.\.local\shopiro\php\php.exe' 'common\dataphyre\runtime\modules\panel\kernel\panel_regression.php' --example --json '.tmp\panel-regression.json'
```

Regression suites are not a browser replacement. They sit between unit tests and
browser checks: fast enough to run from generated package tests, rich enough for
Flightdeck or docs tooling to show exactly which framework contract failed.

For browser-backed editor coverage, the live debug panel includes a Puppeteer
stress runner for the rich text and Markdown editor surfaces:

```powershell
node tools\panel-browser\rich-editor-stress.js
node tools\panel-browser\rich-editor-stress.js --json --report .tmp\rich-editor-stress-report.json
node tools\panel-browser\rich-editor-stress.js --headed --base-url http://127.0.0.1:8088/debug
powershell -ExecutionPolicy Bypass -File tools\panel-browser\run-rich-editor-stress.ps1 -Json -Report .tmp\rich-editor-stress-report.json
```

The runner defaults to `http://127.0.0.1:8088/debug`, reuses the local
`.tmp\puppeteer-check` install, and writes its final screenshot under `.tmp`.
Use `--base-url`, `--browser`, `--screenshot-dir`, `--report`, `--headed`, or
`--json` for ad hoc runs. On failure, the runner emits a structured failure
payload and attempts to save `.tmp\rich-editor-stress-failure.png`. The matching
environment variables `DP_PANEL_BASE_URL`, `CHROME_PATH`,
`DP_PANEL_SCREENSHOT_DIR`, `DP_PANEL_STRESS_JSON=1`, and
`DP_PANEL_STRESS_REPORT` are also supported for CI wrappers.

## Scaffolding

`Panel::scaffold()` and `$panel->scaffold()` generate starter artifacts without
assuming any route, controller, or CLI. A scaffold result is inspectable first:
it contains the artifact kind, normalized name, class, suggested path, contents,
byte count, and metadata. Nothing is written until `write()` is called.

```php
$scaffold=$panel->scaffold();

$resource=$scaffold->resource(App\Panel\Resources\OrderResource::class, [
	'name'=>'orders',
	'label'=>'Order',
	'columns'=>['id', 'number', 'status', 'total'],
	'fields'=>['number', 'status', 'total'],
]);

echo $resource->path();
echo $resource->contents();

// Optional, explicit file write.
$resource->write(overwrite: false);
```

First-party scaffold kinds:

- `resource()` creates a `Resource` factory with generated columns and fields.
- `page()` creates a `PanelPage` factory with group, icon, and starter content.
- `provider()` creates a `PanelProvider` that registers generated resources and
  pages.
- `plugin()` creates a `PanelPlugin` with package identity and lifecycle hooks.
- `theme()` creates a `PanelThemePreset` factory.
- `test()` creates a starter class using `PanelTestHarness`.
- `suite()` generates several artifacts from a single blueprint array.

This is deliberately not a CRM-specific generator and not tied to `/admin`.
Applications or CLI wrappers can layer namespace discovery, overwrite prompts,
dry-run diffs, and file placement policies on top of the same artifact contract.

## Data Jobs

`Panel::importJob()`, `Panel::exportJob()`, and `$panel->dataJob()` create a
queue-ready contract for long-running import/export style work. Jobs can run
synchronously today, but the plan/result shape is designed so a queue adapter can
persist and resume the same work later.

```php
$result=$panel->exportJob('orders_snapshot')
	->resource($ordersResource)
	->records($records)
	->chunkSize(250)
	->queue('panel')
	->map(static fn(array $record): array => [
		'number'=>$record['number'],
		'status'=>$record['status'],
		'total'=>$record['total'],
	])
	->run();

$result->status();     // completed, completed_with_failures, failed
$result->processed();  // processed rows
$result->failures();   // compact failure report
$result->artifacts();  // generated export/failure files
```

Data jobs expose:

- chunk plans with `total`, `chunk_size`, queue name, and queueable flag.
- progress events for chunk start/completion.
- per-row success/failure accounting.
- failure reports with compact row shape and generated failure CSV artifacts.
- export artifacts generated from `map()` results.
- `PanelDataJobResult` summaries suitable for tests, Flightdeck, dashboards, and
  future background workers.

The built-in job runner is intentionally storage-neutral. Production adapters can
store job state, generated artifacts, retries, and downloadable failure files
without changing the resource, form, or table definitions that produced the job.

## Notification Inbox

`PanelNotification` still represents immediate toast-style feedback. For durable
notifications, `Panel::notificationInbox()` and `Panel::inboxNotification()`
describe notification records with recipients, read state, dismissal state,
action links, icons, type counts, and an adapter-ready manifest.

```php
$inbox=Panel::notificationInbox();

$inbox->add(
	Panel::notify('Critical orders need review.', 'warning', 'Risk queue')
		->persistent()
		->action('Open risk view', '/admin/orders?view=risk'),
	'operations'
);

$record=Panel::notify('Seller documents arrived.', 'info')->inbox('trust');

$read=$inbox->add([
	'type'=>'success',
	'title'=>'Import complete',
	'message'=>'The order import finished with one failure report.',
	'recipient'=>'operations',
	'action_label'=>'Open import',
	'action_url'=>'/admin/imports/42',
])->markRead();

$inbox->counts();    // total, unread, read, dismissed, by_type
$inbox->manifest();  // serializable inbox contract for renderers/adapters
```

The built-in inbox is intentionally in-memory. Applications can back the same
record shape with SQL, cache, queue workers, email/web-push fanout, or a
per-user notification center without changing action handlers that already emit
`PanelNotification` payloads.

## Media Collections

`Panel::mediaLibrary()`, `Panel::mediaCollection()`, and `$panel->mediaItem()`
describe files above raw upload fields. The contract is storage-neutral: it
names collections, disks, paths, visibility, accepted types, size limits,
variants, preview expectations, cleanup policy, and item manifests without
requiring a CDN, local filesystem, or object-store adapter.

```php
$library=$panel->mediaLibrary();
$library->register(
	$panel->mediaCollection('product_images')
		->disk('vestra')
		->path('products/{collection}')
		->public()
		->images()
		->multiple()
		->maxSize(5 * 1024 * 1024)
		->variant('thumb', ['width'=>320, 'height'=>320, 'fit'=>'cover'])
		->variant('detail', ['width'=>1400, 'height'=>1400, 'fit'=>'contain'])
		->cleanup(['orphan_ttl_days'=>14, 'delete_derivatives'=>true])
);

$item=$library->item('product_images', [
	'name'=>'paper.webp',
	'type'=>'image/webp',
	'size'=>382144,
	'error'=>UPLOAD_ERR_OK,
]);

$item->previewable();       // true
$item->validation();        // collection validation result
$library->manifest();       // portable collection and variant contract
```

Upload fields can reference the same collection so forms, manifests, tests, and
future storage adapters all agree on the policy:

```php
Panel::field('receipt', 'file')
	->acceptedTypes(['application/pdf', 'image/*'])
	->maxFileSize(2 * 1024 * 1024)
	->mediaCollection($library->collection('product_images'));
```

Media collections expose:

- named collection manifests for forms, resources, tests, and Flightdeck.
- accepted MIME/extension policies including `image/*` and `.pdf` style rules.
- minimum and maximum size validation using normal PHP upload arrays.
- per-collection disk, path, visibility, and cleanup metadata.
- image/document variants as declarative transform definitions.
- previewable item metadata without assuming how the file is ultimately served.

This layer deliberately does not store bytes yet. Production adapters can later
attach local disks, Dataphyre CDN, S3-compatible storage, virus scanning,
progress UI, derivative generation, and cleanup jobs without changing the form
or resource definitions that declared the media collection.

## Documentation Catalog

`Panel::documentationCatalog()`, `Panel::documentationEntry()`, and the matching
instance methods describe docs as data. Entries can carry a category, completion
status, summary, public API references, cookbook examples, links, tags, and
metadata. The catalog can then be searched or exported as a manifest for docs
pages, generated help, compatibility checks, tests, or Flightdeck.

```php
$catalog=$panel->documentationCatalog()
	->meta(['package'=>'operations-panel']);

$catalog->register(
	$panel->documentationEntry('resources', 'Resources')
		->category('Builder')
		->status('solid')
		->summary('Resources define records, forms, tables, actions, policies, search, and manifests.')
		->api(['Panel::resource()', 'Resource::fields()', 'Resource::columns()'])
		->example('Minimal resource', "Panel::resource('orders')->column(Panel::column('number'));")
		->link('Resource docs', 'Dataphyre_Panel.md#resource-builder')
		->tags(['resource', 'table', 'form'])
);

$catalog->search('resource'); // matching PanelDocumentationEntry objects
$catalog->manifest();         // serializable reference/cookbook contract
```

This is the first framework-level contract for the full API reference and
cookbook. It does not replace the human documentation yet; it gives packages,
examples, tests, and debug tooling a single shape to describe what exists, how
complete it is, and where a developer should go next.

When Flightdeck is installed, `/dataphyre/panel` presents the Panel lifecycle
summary, recent trace events, and the currently registered resources. The Panel
module still owns only the resource language; Flightdeck owns the inspection
surface.
