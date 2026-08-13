<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Host-supplied transport boundary for package registry and artifact bytes.
 *
 * Dataphyre never interprets locators as filesystem paths and never invokes a
 * transport implicitly. HTTP, object storage, authenticated gateways, and test
 * fixtures implement this contract outside the package framework. Transport
 * credentials and internal request state must not be returned in response meta.
 */
interface PanelPackageTransport {

	/**
	 * Explicitly fetches one opaque locator.
	 *
	 * A successful response uses `ok=true`, a string `body`, and an exact
	 * `content_type` (parameters such as charset are allowed). `status`, `bytes`,
	 * `sha256`, and `content_encoding` are validated when the consuming boundary
	 * supports them. Other response fields are ignored rather than serialized.
	 * Implementations should return a failed response instead of throwing for
	 * ordinary remote failures; callers still catch transport exceptions.
	 *
	 * @param string $locator Opaque registry-controlled locator passed only to this adapter.
	 * @param array<string,mixed> $request Host-owned request hints without credentials.
	 * @return array<string,mixed> Transport response envelope.
	 */
	public function fetch(string $locator, array $request=[]): array;
}
