# Third-Party Notices

Dataphyre-owned source is released under the MIT License. Some runtime modules
bundle upstream or separately maintained code so embedded installs can keep
working without an external package step.

Bundled components keep their own license files in place. This inventory is for
release review; the license files listed below remain the authoritative notices.

| Component | Path | License | Notes |
| --- | --- | --- | --- |
| Stripe PHP | `runtime/modules/stripe/src/` | MIT | Upstream Stripe PHP client used by the optional Stripe module. See [`LICENSE`](../runtime/modules/stripe/src/LICENSE). |
| Adminer | `runtime/modules/sql/third_party/adminer/` | Apache-2.0 | Bundled database administration UI used by SQL tooling. See [`LICENSE`](../runtime/modules/sql/third_party/adminer/LICENSE). |

The vendored paths are marked in `.gitattributes` and excluded from
Dataphyre-owned header rewriting. If a bundled component is updated, review its
license file and update this inventory in the same change.

Private/internal service clients are not part of this public release inventory.

