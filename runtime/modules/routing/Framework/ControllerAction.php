<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

final class ControllerAction {

	private string $class;
	private string $method;
	private bool $static;
	private ?string $bootstrap;

	private function __construct(string $class, string $method, bool $static=true, ?string $bootstrap=null){
		$this->class=trim($class, '\\');
		$this->method=trim($method);
		$this->static=$static;
		$this->bootstrap=$bootstrap!==null && trim($bootstrap)!==''
			? trim($bootstrap)
			: null;
	}

	public static function static(string $class, string $method, array $options=[]): self {
		return new self($class, $method, true, $options['bootstrap'] ?? null);
	}

	public static function instance(string $class, string $method, array $options=[]): self {
		return new self($class, $method, false, $options['bootstrap'] ?? null);
	}

	public function compile(): array {
		return [
			'type'=>'controller',
			'class'=>$this->class,
			'method'=>$this->method,
			'static'=>$this->static,
			'bootstrap'=>$this->bootstrap,
		];
	}
}
