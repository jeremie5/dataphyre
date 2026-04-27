<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class CsrfToken implements \JsonSerializable {

	private ?string $token=null;

	private function __construct(
		private readonly string $form_name
	){}

	public static function for(string $form_name): self {
		return new self(trim($form_name));
	}

	public function formName(): string {
		return $this->form_name;
	}

	public function value(): string {
		if($this->token===null){
			$value=\dataphyre\core::csrf($this->form_name);
			$this->token=is_string($value) ? $value : '';
		}
		return $this->token;
	}

	public function refresh(): self {
		$this->token=null;
		return $this;
	}

	public function validate(#[\SensitiveParameter] mixed $token): bool {
		return \dataphyre\core::csrf($this->form_name, $token)===true;
	}

	public function equals(#[\SensitiveParameter] mixed $token): bool {
		$value=$this->value();
		return is_string($token) && $value!=='' && hash_equals($value, $token);
	}

	public function hiddenField(string $field_name='csrf'): string {
		$field_name=trim($field_name);
		if($field_name===''){
			$field_name='csrf';
		}
		return '<input type="hidden" name="'.htmlspecialchars($field_name, ENT_QUOTES, 'UTF-8').'" value="'.htmlspecialchars($this->value(), ENT_QUOTES, 'UTF-8').'">';
	}

	public function toArray(): array {
		return [
			'form_name'=>$this->form_name,
			'token'=>$this->value(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function __toString(): string {
		return $this->value();
	}
}
