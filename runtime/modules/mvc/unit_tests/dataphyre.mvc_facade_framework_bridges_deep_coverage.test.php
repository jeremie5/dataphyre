<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Templating {
	final class DpMvcRenderValue { public function __construct(private string $value){} public function content(): string { return $this->value; } }
	final class DpMvcManifestValue { public function __construct(private array $value){} public function toArray(): array { return $this->value; } }
	final class Templating {
		public static function render(string $template,array $data=[],array $theme=[],array $slots=[]): mixed { return new DpMvcRenderValue('framework:'.$template); }
		public static function renderString(string $template,array $data=[],array $theme=[],array $slots=[],string $name='inline.tpl'): mixed { return new DpMvcRenderValue('framework-string:'.$name); }
		public static function assetManifest(string $template): object { return new DpMvcManifestValue(['head_html'=>'<fh>','body_tags'=>['<fb>'],'all_tags'=>['<fa>']]); }
		public static function assetManifestString(string $template,string $name='inline.tpl'): object { return new DpMvcManifestValue(['body_html'=>'<fsb>','head_tags'=>['<fsh>'],'all_tags'=>['<fsa>']]); }
	}
}

namespace Dataphyre\Sanitation {
	final class Sanitation {
		public static function anonymizeEmail(string $email,int $count=2,string $char='*'): string { return 'framework-mask@example.test'; }
		public static function sanitize(mixed $value,string|array $rule='default',array $options=[]): mixed { return ['value'=>$value,'rule'=>$rule]; }
		public static function string(mixed $value): object { return (object)['value'=>$value]; }
		public static function bag(array $data): object { return (object)$data; }
		public static function schema(array $data,array $schema,array $defaults=[],array $options=[]): array { return ['schema'=>true]+$data+$defaults; }
		public static function validated(array $data,array $schema,array $defaults=[],array $options=[]): array { return ['validated'=>true]+$data; }
		public static function schemaOrFail(array $data,array $schema,array $defaults=[],array $options=[],?string $message=null): array { return ['or_fail'=>$message]+$data; }
		public static function preset(string $name,array $data,array $overrides=[],array $defaults=[],array $options=[]): array { return ['preset'=>$name]+$data; }
		public static function validatedPreset(string $name,array $data,array $overrides=[],array $defaults=[],array $options=[]): array { return ['validated_preset'=>$name]+$data; }
		public static function presetOrFail(string $name,array $data,array $overrides=[],array $defaults=[],array $options=[],?string $message=null): array { return ['preset_or_fail'=>$name]+$data; }
	}
}

namespace Dataphyre\Localization {
	final class Localization {
		public static function translate(string $key,?string $fallback=null,?array $parameters=null,?string $language=null,?string $page=null,?string $theme=null): string { return 'framework:'.($fallback ?? $key); }
		public static function translateOrNull(string $key,?array $parameters=null,?string $language=null,?string $page=null,?string $theme=null): ?string { return $key==='missing' ? null : 'framework:'.$key; }
		public static function has(string $key,?string $language=null,?string $page=null,?string $theme=null): bool { return $key!=='missing'; }
		public static function missing(string $key,?string $language=null,?string $page=null,?string $theme=null): bool { return $key==='missing'; }
		public static function choice(int|float $count,string $one,string $many,?string $zero=null,?array $parameters=null,?string $language=null,?string $page=null,?string $theme=null): string { return 'choice:'.$count; }
	}
}

namespace Dataphyre\Currency {
	final class Currency {
		public static function format(mixed $amount,bool $showFree=false,?string $currency=null): string { return 'framework-format:'.$amount; }
		public static function convert(mixed $amount,string $source,string $target,bool $formatted=false,bool $showFree=true): string|float { return $formatted ? 'framework-convert' : (float)$amount*3; }
		public static function convertToDisplay(mixed $amount,bool $formatted=false,bool $showFree=true,?string $currency=null): string|float { return $formatted ? 'framework-display' : (float)$amount+2; }
		public static function convertToBase(mixed $amount,string $currency,bool $formatted=false,bool $showFree=true): string|float { return $formatted ? 'framework-base' : (float)$amount-2; }
		public static function roundAmount(mixed $amount,string $currency,bool $cash=false): float { return round((float)$amount,1); }
		public static function splitAmount(mixed $amount,string $currency,int $parts,bool $cash=false): array { return array_fill(0,$parts,(float)$amount/$parts); }
		public static function allocateAmount(mixed $amount,string $currency,array $ratios,bool $cash=false): array { return array_reverse($ratios); }
	}
}

namespace Dataphyre\Async {
	final class Async {
		public static array $cancelled=[];
		public static function dispatch(mixed $task,array $arguments=[],?string $driver=null): mixed { return is_callable($task) ? $task(...$arguments) : 'dispatched'; }
		public static function inline(mixed $task,array $arguments=[]): mixed { return is_callable($task) ? $task(...$arguments) : 'inline'; }
		public static function all(array $tasks,?string $driver=null): array { return array_values($tasks); }
		public static function after(callable $task,int $milliseconds): int { return 41; }
		public static function every(callable $task,int $milliseconds): int { return 42; }
		public static function cancel(int $id): void { self::$cancelled[]=$id; }
	}
}

namespace Dataphyre\Reactor {
	final class DpMvcReactorResult implements \JsonSerializable {
		public function __construct(private int $code=207){}
		public function jsonSerialize(): mixed { return ['status'=>$this->code,'ok'=>$this->code<400,'html'=>'<r>']; }
		public function status(): int { return $this->code; }
	}
	final class Reactor {
		public static function mount(string $component,array $state=[],array $attributes=[]): string { return '<mount>'.$component.'</mount>'; }
		public static function dispatch(array|object|null $request=null): object { return new DpMvcReactorResult(208); }
		public static function manifest(): array { return ['components'=>['demo']]; }
	}
	final class ReactorEndpoint {
		public static function handleBatch(?array $requests=null): array { return [['status'=>201],['status'=>422],['status'=>700],[]]; }
		public static function handle(array|object|null $request=null): object { return new DpMvcReactorResult(209); }
	}
}

namespace Dataphyre\Storage {
	final class DpMvcStorageMetadata { public function __construct(private array $value){} public function toArray(): array { return $this->value; } }
	final class Storage {
		public static mixed $metadata;
		public static function get(string $path,?string $disk=null): string|false { return $path==='missing' ? false : 'framework-storage'; }
		public static function metadata(string $path,?string $disk=null): mixed { return self::$metadata ?? new DpMvcStorageMetadata(['mime_type'=>'application/framework','modified_at'=>200]); }
		public static function temporaryUrl(string $path,int|\DateTimeInterface $expires,?string $disk=null,array $options=[]): string|false { return 'framework://'.$path; }
	}
}

namespace Dataphyre\Mailer {
	final class DpMvcMailerResult implements \JsonSerializable { public function toArray(): array { return ['ok'=>true,'source'=>'framework']; } public function jsonSerialize(): mixed { return ['ok'=>true,'source'=>'json']; } }
	final class Mailer {
		public static function send(array $message,?string $provider=null,array $options=[]): object { return new DpMvcMailerResult(); }
		public static function queue(array $message,?string $provider=null,array $options=[]): object { return new DpMvcMailerResult(); }
		public static function render(string $template,array $data=[],array $options=[]): mixed { return $template==='bad' ? 'bad' : ['subject'=>'framework','html'=>'h','text'=>'t']; }
	}
}

namespace Dataphyre\Permission {
	final class Permission {
		public static function check(mixed $required,mixed $subject=null,array $context=[]): bool { return $required==='allow'; }
		public static function any(mixed $required,mixed $subject=null,array $context=[]): bool { return $required===['allow']; }
	}
}

namespace {
	use Dataphyre\Http\Request;
	use Dataphyre\Mvc\HttpException;
	use Dataphyre\Mvc\Mvc;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['http','routing','mvc']);

	test('mvc facade framework bridges deep coverage exercises templating sanitation localization and currency',static function(Context $t): void {
		$t->isTrue(Mvc::templatingAvailable());$t->same('framework:view',Mvc::renderTemplate('view'));$t->same('framework-string:inline',Mvc::renderTemplateString('source',[],[],[],'inline'));
		$t->same('<fh>',Mvc::templateAssetHtml('view','head'));$t->same('<fsb>',Mvc::templateStringAssetHtml('source','body'));
		$t->isTrue(Mvc::sanitationAvailable());$t->same('framework-mask@example.test',Mvc::anonymizeEmail('person@example.test'));$t->same('text',Mvc::sanitize('value','text')['rule']);
		$t->isTrue(is_object(Mvc::sanitizer('value')));$t->isTrue(is_object(Mvc::inputBag(['name'=>'Ada'])));$t->isTrue(Mvc::sanitizeSchema(['name'=>'Ada'],[])['schema']);
		$t->isTrue(Mvc::sanitized(['name'=>'Ada'],[])['validated']);$t->same('message',Mvc::sanitizedOrFail(['name'=>'Ada'],[],[],[],'message')['or_fail']);
		$t->same('preset',Mvc::sanitizePreset('preset',['name'=>'Ada'])['preset']);$t->same('preset',Mvc::sanitizedPreset('preset',['name'=>'Ada'])['validated_preset']);$t->same('preset',Mvc::sanitizedPresetOrFail('preset',['name'=>'Ada'])['preset_or_fail']);
		$t->isTrue(Mvc::localizationAvailable());$t->same('framework:fallback',Mvc::translate('key','fallback'));$t->same('framework:key',Mvc::translateOrNull('key'));$t->isTrue(Mvc::translationHas('key'));$t->isTrue(Mvc::translationMissing('missing'));$t->same('choice:2',Mvc::choice(2,'one','many'));
		$t->isTrue(Mvc::currencyAvailable());$t->same('framework-format:5',Mvc::moneyFormat(5));$t->same(15.0,Mvc::moneyConvert(5,'USD','CAD'));$t->same(7.0,Mvc::moneyToDisplay(5));$t->same(3.0,Mvc::moneyToBase(5,'USD'));
		$t->same(1.2,Mvc::moneyRound(1.24,'USD'));$t->same([3.0,3.0],Mvc::moneySplit(6,'USD',2));$t->same([2,1],Mvc::moneyAllocate(6,'USD',[1,2]));
	})->tag('mvc','facade','framework','deep-coverage')->group('framework-coverage');

	test('mvc facade framework bridges deep coverage exercises async reactor storage mail and permission',static function(Context $t): void {
		$t->isTrue(Mvc::asyncAvailable());$t->same(3,Mvc::asyncDispatch(static fn(int $a,int $b): int=>$a+$b,[1,2]));$t->same(5,Mvc::asyncInline(static fn(int $v): int=>$v,[5]));$t->same(['a','b'],Mvc::asyncAll(['a','b']));
		$t->same(41,Mvc::asyncAfter(static fn()=>null,5));$t->same(42,Mvc::asyncEvery(static fn()=>null,5));Mvc::asyncCancel(41);$t->same([41],\Dataphyre\Async\Async::$cancelled);
		$t->isTrue(Mvc::reactorAvailable());$t->same('<mount>demo</mount>',Mvc::reactorMount('demo'));$t->same(209,Mvc::reactorDispatch(['x'=>1])->status);$batch=Mvc::reactorBatch([['x'=>1]]);$t->same(599,$batch->status);$t->same(['demo'],Mvc::reactorManifest()['components']);
		$t->isTrue(Mvc::storageAvailable());$file=Mvc::storageFile('file.bin','disk');$t->same('framework-storage',$file->body);$t->same('application/framework',$file->headers['Content-Type']);$t->same('framework://file.bin',Mvc::storageTemporaryUrl('file.bin',time()+60));$t->throws(static fn()=>Mvc::storageFile('missing'),HttpException::class);
		$t->isTrue(Mvc::mailerAvailable());$t->same('framework',Mvc::sendMail(['to'=>[]])['source']);$t->same('framework',Mvc::queueMail(['to'=>[]])['source']);$t->same('framework',Mvc::renderMail('template')['subject']);$t->same([],Mvc::renderMail('bad'));
		$t->isTrue(Mvc::permissionAvailable());$t->isTrue(Mvc::can('allow'));$t->isTrue(Mvc::canAny(['allow']));$t->isTrue(Mvc::authorize('allow'));$t->isTrue(Mvc::authorizeAny(['allow']));
		$request=Request::create('POST','/x',['a'=>1],['b'=>2],[],[],[],['c'=>3]);$t->isTrue(is_object(Mvc::inputBag($request)));
	})->tag('mvc','facade','framework','deep-coverage')->group('framework-coverage');
}
