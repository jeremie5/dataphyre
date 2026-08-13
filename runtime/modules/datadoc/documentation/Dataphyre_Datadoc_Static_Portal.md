# Datadoc Static Portal and Publication

Datadoc owns Dataphyre's universal documentation renderer and release format. A product or module may generate documentation content, but it must not duplicate the portal shell, search protocol, version protocol, security policy, or publication transaction.

## Ownership boundary

The data flow is deliberately one-way:

```text
module or product producer
        |
        v
relative Markdown paths plus inert raster content assets
        |
        v
Datadoc DocumentationCorpus
        |
        v
Datadoc DocumentationPortal
        |
        v
DocumentationPortalBuild
        |
        v
DocumentationPortalPublication
        |
        v
<base>/versions/<semver>/
```

Datadoc owns:

- safe Markdown rendering and link normalization;
- local image resolution, signature validation, and responsive image presentation;
- navigation, table of contents, local search, and version switching;
- responsive, print, reduced-motion, forced-colors, light, and dark behavior;
- generated CSS, JavaScript, favicon, HTML, JSON protocols, CSP, and referrer policy;
- canonical SemVer release paths, integrity manifests, exact-tree verification, locking, staging, and atomic publication.

Content producers own:

- what is documented;
- how API references, tutorials, or package data become Markdown;
- product-specific catalog and compatibility metadata;
- an optional thin adapter from an existing publication value to Datadoc's corpus contract.

Panel is one producer. `PanelDocumentationPublisher` generates Panel API and cookbook material, while `PanelDocumentationPortal` adapts that verified material to Datadoc. The adapter deliberately contains no renderer or browser runtime.

## Framework API

`DocumentationCorpus` is the reusable source-discovery boundary. Manual mode
composes one root source plus deterministic producer mounts. Workspace mode
discovers Dataphyre's conventional project and module manuals without loading
the runtime, a module kernel, a provider, or application code:

```php
use Dataphyre\Datadoc\DocumentationCorpus;

$corpus=DocumentationCorpus::discover(
	$projectRoot,
	source: null,
	mounts: [
		'generated/panel'=>$generatedPanelDocumentation,
	],
	workspace: true,
	title: 'Dataphyre Documentation',
	exclude: ['docs/generated/datadoc'],
);
```

Workspace destinations preserve their project-relative paths. Datadoc mounts
`docs/` recursively, every existing
`runtime/modules/<module>/documentation/` tree recursively, and the direct
support files in `runtime/`, `config/`, and `examples/minimal/` without
recursing into executable trees. It generates `index.md` as a plain catalog.
That topology keeps existing links such as
`docs/README.md -> ../runtime/logo.png` valid in the static release. Additional
generated corpora remain explicit mounts; Panel is treated exactly like any
other producer.

The corpus manifest records discovery mode, sorted source identity and
repository-relative paths, entry pages, recursive policy, exclusions, page and
asset bounds, ignored-file counts, and a deterministic SHA-256 fingerprint.
Exclusions are bounded, case-unambiguous, root-relative paths. They are pruned
before traversal, reject symlinks, and let a publisher exclude its own output
directory so a repeated build cannot recursively ingest an older release.

The resulting maps feed the renderer directly:

```php
use Dataphyre\Datadoc\DocumentationPortal;
use Dataphyre\Datadoc\DocumentationPortalPublication;

$build=DocumentationPortal::make()->build(
	'2.1.0',
	'Dataphyre Documentation',
	[
		'index.md'=>"# Dataphyre Documentation\n\nStart here.\n",
		'guides/install.md'=>"# Install\n\nInstallation guidance.\n",
		'modules/panel.md'=>"# Panel\n\nPanel reference.\n",
	],
	reservedPaths: [],
	options: [
		'language'=>'en-CA',
		'direction'=>'ltr',
		'ui_copy'=>$completeLocalizedPortalCopy,
		'default_theme'=>'system',
		'version_links'=>[
			'2.1.0'=>'',
			'2.0.0'=>'../2.0.0/index.html',
		],
		'canonical_base_url'=>'https://docs.example.test/dataphyre/2.1.0/',
		'repository_url'=>'https://code.example.test/dataphyre',
		'maximum_search_text_bytes'=>12000,
	],
	contentAssets: [
		'media/architecture.png'=>$pngBytes,
	],
);

$publication=DocumentationPortalPublication::fromBuild(
	$build,
	'docs/dataphyre',
	['channel'=>'stable'],
);

$preview=$publication->apply($projectRoot, dryRun: true);
$written=$publication->apply($projectRoot, dryRun: false);
```

`build()` accepts a relative `.md` path-to-UTF-8-string map plus an optional relative content-asset path-to-binary-string map. It requires `index.md`, rejects case-ambiguous and unsafe paths, never executes source, escapes raw HTML, strips unsafe links, and enforces page, asset, per-file, total-byte, title, language, URL, and search-text bounds. `reservedPaths` lets an adapter prevent generated files from replacing producer-owned artifacts.

`language` sets the document language and search locale. `direction` is
explicitly `ltr` or `rtl`. The optional `ui_copy` map localizes the complete
static shell, including navigation, search, theme, copy-code, empty-result, and
not-found messages. When supplied, it must contain exactly every supported key
and retain the documented `{version}`, `{theme}`, and `{count}` placeholders.
Omitting it uses the English framework defaults. This strict contract lets any
producer add a language without forking Datadoc while preventing a partly
translated shell. The portal manifest records the direction, UI-copy key count,
and deterministic UI-copy fingerprint.

The complete `ui_copy` contract is:

```text
skip_to_content
repository
search
version
change_color_theme
theme
menu
close_navigation
documentation
generated_release
search_documentation
close_search
close
search_terms
search_placeholder
type_two_characters
javascript_required
page_not_found
missing_page
open_documentation
on_this_page
overview
api_reference
color_theme_status
color_theme_changed
search_result_one
search_result_many
no_matching_documentation
searching
search_index_unavailable
copy
copied
code_copied
code_copy_failed
```

Datadoc derives non-overview section labels from the localized title of each
section index page. Producers therefore do not need a second translation map
for navigation labels.

Content assets are intentionally narrower than general attachments. Datadoc accepts signature-valid PNG, JPEG, GIF, WebP, AVIF, and ICO files. It rejects SVG, HTML, PDF, JavaScript, stylesheets, fonts, archives, and extension/signature mismatches. Markdown image syntax resolves only to a declared local content asset, supports escaped alternative text, quoted titles, angle-bracket destinations, spaces and Unicode in declared paths, and remains root-confined across nested pages. Images use lazy asynchronous decoding; external image URLs are not requested.

The build contains generated protocol files and declared inert content assets. Its required protocol artifacts are:

- `index.html` and one HTML page per Markdown source;
- `404.html`;
- `assets/portal.css`, `assets/portal.js`, and `assets/favicon.svg`;
- `portal.json`, `search-index.json`, and `versions.json`;
- `sitemap.xml` when a canonical base URL is configured.

`portal.json` records sorted content-asset paths, media types, byte counts, SHA-256 digests, aggregate bounds, and a deterministic corpus fingerprint. `DocumentationPortalBuild` independently verifies those declarations and rejects missing, tampered, malformed, duplicate, or undeclared raster files.

No inline script, dynamic HTML injection, external runtime dependency, external asset request, or source execution is required. Search runs locally from the generated index and includes image alternative text.

## Immutable publication

`DocumentationPortalPublication` adds a canonical `publication.json` and publishes under:

```text
<base path>/versions/<canonical semver>/
```

Preview is the default API and CLI posture. A write:

1. acquires a non-blocking root-scoped Datadoc lock;
2. writes every artifact to a private staging directory;
3. verifies the staged exact tree, byte counts, and SHA-256 digests;
4. while staging remains private, normalizes verified files to `0644` and
   directories to `0755` on POSIX so a distinct static-web user can read them;
5. creates confined non-symlink parent directories with explicit traversable
   permissions when Datadoc owns their creation;
6. exposes the complete release with one directory rename;
7. removes staging state and releases the lock.

Publishing the same byte-identical release is idempotent. A partial, tampered, aliased, or changed release at the same version fails closed. Corrections require a new documentation version; there is no replace or skip mode.

## Universal CLI

Preview the complete Dataphyre workspace with automatic project/module
discovery:

```text
php source-checkout-maintainer-tool \
  --root /workspace/dataphyre \
  --workspace \
  --mount generated/panel=build/panel-documentation \
  --output docs/generated/datadoc \
  --version 2.1.0 \
  --title "Dataphyre Documentation"
```

`--workspace` and the root `--source` mode are mutually exclusive. Workspace
mode creates the root catalog automatically. The publication output is always
passed to the corpus as an exclusion; rebuilding an already published version
therefore remains byte-stable instead of scanning its HTML or copied media.

Preview a Markdown tree:

```text
php source-checkout-maintainer-tool \
  --root /workspace/dataphyre \
  --source docs/manual \
  --mount modules/panel=runtime/modules/panel/documentation \
  --mount modules/datadoc=runtime/modules/datadoc/documentation \
  --output public/docs \
  --version 2.1.0 \
  --title "Dataphyre Documentation"
```

Publish it explicitly:

```text
php source-checkout-maintainer-tool \
  --root /workspace/dataphyre \
  --source docs/manual \
  --output public/docs \
  --version 2.1.0 \
  --portal-config docs/portal.json \
  --meta docs/publication-meta.json \
  --write
```

The root `--source` supplies the release `index.md`. Up to 256 total sources may compose one corpus; repeatable `--mount PREFIX=DIR` options add product and module manuals under deterministic destination prefixes. Prefixes are safe relative paths; source trees remain realpath-confined to `--root`; recursive symlinks, case-ambiguous destinations, cross-source collisions, and mounted attempts to replace root content fail closed.

The command emits JSON on stdout for successful previews and writes, JSON on stderr for failures, and a nonzero exit code on failure. Supported raster files are validated and copied as integrity-manifested content assets. Other non-Markdown files are reported as ignored and are not copied into the release. Corpus evidence reports every mount, page and asset counts, Markdown and asset bytes, and ignored-file counts without exposing resolved host paths.

## Deployment boundary

Datadoc creates a complete static release but does not choose a CDN, object store, domain, TLS policy, cache invalidation strategy, authentication gateway, or deployment credential. A host may upload a verified version directory as immutable content. Pointer promotion such as changing a `current` alias remains a separate deployment transaction and must never mutate the version directory itself.
