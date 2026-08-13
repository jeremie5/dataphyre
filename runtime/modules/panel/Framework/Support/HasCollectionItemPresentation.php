<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Adds immutable per-item layout builders through an object's existing meta contract. */
trait HasCollectionItemPresentation {
	/** @param array<string,mixed>|PanelCollectionItemPresentation $presentation */
	public function itemPresentation(array|PanelCollectionItemPresentation $presentation): self {
		$presentation=PanelCollectionItemPresentation::normalize($presentation);
		return $presentation===[] ? $this : $this->meta(['item_presentation'=>$presentation]);
	}

	/** @param int|array<string,int> $span */
	public function itemSpan(int|array $span, string $breakpoint='base'): self {
		return $this->itemPresentation($this->itemPresentationValue()->span($span, $breakpoint));
	}

	/** @param int|float|string|array<string,int|float|string> $basis */
	public function itemBasis(int|float|string|array $basis, string $breakpoint='base'): self {
		return $this->itemPresentation($this->itemPresentationValue()->basis($basis, $breakpoint));
	}

	/** @param int|float|string|array<string,int|float|string> $width */
	public function itemMinWidth(int|float|string|array $width, string $breakpoint='base'): self {
		return $this->itemPresentation($this->itemPresentationValue()->minWidth($width, $breakpoint));
	}

	/** @param int|float|string|array<string,int|float|string> $width */
	public function itemMaxWidth(int|float|string|array $width, string $breakpoint='base'): self {
		return $this->itemPresentation($this->itemPresentationValue()->maxWidth($width, $breakpoint));
	}

	/** @param int|float|array<string,int|float> $grow */
	public function itemGrow(int|float|array $grow=1, string $breakpoint='base'): self { return $this->itemPresentation($this->itemPresentationValue()->grow($grow, $breakpoint)); }
	/** @param int|float|array<string,int|float> $shrink */
	public function itemShrink(int|float|array $shrink=1, string $breakpoint='base'): self { return $this->itemPresentation($this->itemPresentationValue()->shrink($shrink, $breakpoint)); }
	/** @param int|array<string,int> $order */
	public function itemOrder(int|array $order, string $breakpoint='base'): self { return $this->itemPresentation($this->itemPresentationValue()->order($order, $breakpoint)); }
	/** @param bool|array<string,bool> $break */
	public function itemBreakBefore(bool|array $break=true, string $breakpoint='base'): self { return $this->itemPresentation($this->itemPresentationValue()->breakBefore($break, $breakpoint)); }
	/** @param bool|array<string,bool> $fill */
	public function itemFillRemainder(bool|array $fill=true, string $breakpoint='base'): self { return $this->itemPresentation($this->itemPresentationValue()->fillRemainder($fill, $breakpoint)); }

	private function itemPresentationValue(): PanelCollectionItemPresentation {
		$serialized=method_exists($this, 'toArray') ? $this->toArray() : [];
		$meta=is_array($serialized['meta'] ?? null) ? $serialized['meta'] : [];
		return PanelCollectionItemPresentation::make(PanelCollectionItemPresentation::fromMeta($meta));
	}
}
