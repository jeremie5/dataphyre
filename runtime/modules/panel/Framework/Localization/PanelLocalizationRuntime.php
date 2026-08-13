<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cohesive catalogue loading, message formatting, RTL, and Panel binding runtime. */
final class PanelLocalizationRuntime implements \JsonSerializable {
	private PanelLocalization $localization;
	private PanelLocaleMetadata $metadata;
	private PanelMessageFormatter $formatter;
	public function __construct(private readonly PanelTranslationCatalogueLoader $loader,string $locale='en',?string $fallback='en',string $timezone='UTC') { $this->metadata=PanelLocaleMetadata::make($locale,$fallback!==null?[$fallback]:[]);$this->localization=$loader->load($this->metadata->locale(),$fallback,$this->metadata->fallbackChain());$this->formatter=new PanelMessageFormatter($this->metadata->locale(),true,$timezone); }
	public static function make(PanelTranslationCatalogueLoader $loader,string $locale='en',?string $fallback='en',string $timezone='UTC'):self{return new self($loader,$locale,$fallback,$timezone);}
	public function localization():PanelLocalization{return $this->localization;}
	public function loader():PanelTranslationCatalogueLoader{return$this->loader;}
	public function metadata():PanelLocaleMetadata{return $this->metadata;}
	public function formatter():PanelMessageFormatter{return $this->formatter;}
	public function apply(PanelInstance $panel):PanelInstance{return $panel->localization($this->localization);}
	public function translate(string $key,array $parameters=[],string|\Stringable|null $default=null):string{$message=$this->localization->translate($key,[],$this->metadata->locale(),$default);return $this->formatter->format($message,$parameters);}
	public function htmlAttributes():string{$attributes=[];foreach($this->metadata->htmlAttributes()as$key=>$value){$attributes[]=$key.'="'.htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"';}return implode(' ',$attributes);}
	public function manifest():array{return ['type'=>'panel_localization_runtime','locale'=>$this->metadata->manifest(),'localization'=>$this->localization->manifest(),'formatter'=>$this->formatter->manifest(),'loader'=>$this->loader->manifest(),'html_attributes'=>$this->metadata->htmlAttributes(),'capabilities'=>['catalogues'=>true,'namespaces'=>true,'fallbacks'=>true,'icu_messages'=>true,'pluralization'=>true,'number_formatting'=>true,'currency_formatting'=>true,'date_formatting'=>true,'rtl'=>true]];}
	public function jsonSerialize():array{return $this->manifest();}
}
