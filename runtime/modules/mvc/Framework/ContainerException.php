<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Reports MVC container resolution or service wiring failures.
 *
 * The exception is raised when controller dependencies, services, or invokable
 * targets cannot be resolved from the MVC container before route execution.
 */
final class ContainerException extends \RuntimeException {}
