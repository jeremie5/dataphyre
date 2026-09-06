# Durable Vestra storage paths

Vestra stores object bytes remotely. The Storage driver also needs a durable map
from each logical path to its object reference. Its default JSON manifest is
appropriate when the configured manifest path persists across processes and
releases. An ephemeral release filesystem cannot provide that guarantee.

Set a disk's `alias_store` to an implementation of
`Dataphyre\Storage\Contracts\VestraAliasStore` to keep that map in the application's
existing durable database or another shared store. The interface provides
`lookup`, atomic `put`, atomic `delete`, and `list` operations. Namespace records
by application and disk, preserve unrelated paths during concurrent writes, and
report store failures instead of interpreting them as missing files. The driver
uses this store exclusively when configured; it does not fall back to a local
manifest after an error. Existing disks without it retain their JSON behavior.

An alias is committed only after Vestra accepts the object. If committing the
alias fails, `write()` returns false and does not claim that the path is readable.
The uploaded object can remain unreferenced; this interface does not provide a
distributed transaction or change Vestra's existing object-retention policy.
Existing StorageManager encryption, file guards, and stream handling are unchanged.
Store stable references rather than application credentials or signed URLs.

Validate deployment wiring with a write followed by a read from a separate
process or freshly constructed driver, then repeat after replacing the runtime.
For confidential disks, also verify that the remotely stored bytes are encrypted
and that the stable application encryption key is available to each reader.
