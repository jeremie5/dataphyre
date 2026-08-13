<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class mvc { public static function config(string $key,mixed $default=null): mixed { return 'cfg-'.$key; } }
	final class templating {
		public static function render(string $template,array $data=[],array $theme=[],array $slots=[]): string { return 'render:'.$template; }
		public static function render_string(string $template,array $data=[],array $theme=[],array $slots=[],string $name='inline.tpl'): string { return 'string:'.$name; }
		public static function asset_manifest(string $template): mixed { return $template==='invalid' ? 'bad' : ['head_html'=>'<head>','body_tags'=>['<body>'],'all_tags'=>['<all>']]; }
		public static function asset_manifest_string(string $template,string $name='inline.tpl'): mixed { return $template==='invalid' ? 'bad' : ['body_html'=>'<body>','head_tags'=>['<head>'],'all_tags'=>['<all>']]; }
	}
	final class sanitation {
		public static function anonymize_email(string $email,int $count=2,string $char='*'): string { return 'masked@legacy.test'; }
		public static function sanitize(mixed $value,string $rule='default'): mixed { return 'sanitized:'.$rule; }
		public static function sanitize_many(array $data,array $schema): array { return ['legacy'=>true]+$data; }
	}
	final class localization {
		public static bool $missing=false;
		public static function locale(string $key,?string $fallback=null,?array $parameters=null,?string $language=null,?string $page=null): string { return self::$missing ? $key : ($fallback ?? 'legacy:'.$key); }
	}
	final class currency {
		public static function formatter(mixed $amount,bool $showFree=false,?string $currency=null): string { return 'fmt:'.$amount; }
		public static function convert(mixed $amount,string $source,string $target,bool $formatted=false,bool $showFree=true): string|float { return $formatted ? 'converted' : (float)$amount*2; }
		public static function convert_to_user_currency(mixed $amount,bool $formatted=false,bool $showFree=true,?string $currency=null): string|float { return $formatted ? 'display' : (float)$amount+1; }
		public static function convert_to_website_currency(mixed $amount,string $currency,bool $formatted=false,bool $showFree=true): string|float { return $formatted ? 'base' : (float)$amount-1; }
		public static function round_amount(mixed $amount,string $currency,bool $cash=false): float { return round((float)$amount,2); }
		public static function split_amount(mixed $amount,string $currency,int $parts,bool $cash=false): array { return array_fill(0,$parts,(float)$amount/$parts); }
		public static function allocate_amount(mixed $amount,string $currency,array $ratios,bool $cash=false): array { return $ratios; }
	}
	final class date_translation { public static function translate_date(string $date,string $language,string $format): string { return 'translated-date'; } }
	final class cache {
		public static array $values=[];
		public static function get(string $key): mixed { return self::$values[$key] ?? null; }
		public static function set(string $key,mixed $value,int $seconds=0): bool { self::$values[$key]=$value; return true; }
		public static function delete(string $key): bool { unset(self::$values[$key]); return true; }
		public static function increment(string $key,int $offset=1): mixed { if($key==='bad'){return 'not-number';} return self::$values[$key]=(int)(self::$values[$key] ?? 0)+$offset; }
		public static function decrement(string $key,int $offset=1): mixed { if($key==='bad'){return new \stdClass();} return self::$values[$key]=(int)(self::$values[$key] ?? 0)-$offset; }
	}
	final class async {
		public static array $cancelled=[];
		public static function async(callable $task): mixed { return $task(); }
		public static function set_timeout(callable $task,int $milliseconds): int { return 31; }
		public static function set_interval(callable $task,int $milliseconds): int { return 32; }
		public static function cancel(int $id): void { self::$cancelled[]=$id; }
	}
	final class storage {
		public static mixed $metadata=['mime_type'=>'text/custom','modified_at'=>100];
		public static function get(string $path,?string $disk=null): string|false { return $path==='missing' ? false : 'stored-body'; }
		public static function metadata(string $path,?string $disk=null): mixed { return self::$metadata; }
		public static function temporary_url(string $path,int|\DateTimeInterface $expires,?string $disk=null,array $options=[]): string|false { return 'legacy://'.$path; }
	}
	final class DpMvcLegacyMailResult { public function toArray(): array { return ['ok'=>true,'source'=>'object']; } }
	final class mailer {
		public static bool $objects=false;
		public static function send(array $message,?string $provider=null,array $options=[]): mixed { return self::$objects ? new DpMvcLegacyMailResult() : ['ok'=>true,'kind'=>'send']; }
		public static function queue(array $message,?string $provider=null,array $options=[]): mixed { return self::$objects ? new DpMvcLegacyMailResult() : ['ok'=>true,'kind'=>'queue']; }
		public static function render(string $template,array $data=[],array $options=[]): mixed { return $template==='bad' ? 'bad' : ['subject'=>'s','html'=>'h','text'=>'t']; }
	}
	final class access {
		public static function auth_context(?string $type=null): array { return ['auth_type'=>$type,'logged_in'=>true,'userid'=>42]; }
		public static function logged_in(?string $type=null): bool { return true; }
		public static function userid(?string $type=null): int { return 42; }
	}
	final class permission {
		public static function check(mixed $required,mixed $subject=null,array $context=[]): bool { return $required==='allow'; }
		public static function any(mixed $required,mixed $subject=null,array $context=[]): bool { return $required==='allow' || $required===['allow']; }
	}
}

namespace {
	use Dataphyre\Mvc\HttpException;
	use Dataphyre\Mvc\Mvc;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['http','routing','mvc']);

	test('mvc facade legacy bridges deep coverage exercises legacy optional modules',static function(Context $t): void {
		$t->same('cfg-answer',Mvc::config('answer','fallback'));
		$t->isTrue(Mvc::templatingAvailable());$t->same('render:view',Mvc::renderTemplate('view'));$t->same('string:inline-name',Mvc::renderTemplateString('source',[],[],[],'inline-name'));
		$t->same('<head>',Mvc::templateAssetHtml('view','head'));$t->same('<body>',Mvc::templateStringAssetHtml('source','body','inline'));
		$t->same([],Mvc::templateAssets('invalid'));$t->same([],Mvc::templateStringAssets('invalid'));
		$t->isTrue(Mvc::sanitationAvailable());$t->same('masked@legacy.test',Mvc::anonymizeEmail('person@example.test'));$t->same('sanitized:text',Mvc::sanitize('x','text'));
		$t->same(['legacy'=>true,'name'=>'Ada'],Mvc::sanitizeSchema(['name'=>'Ada'],['name'=>'text']));
		$t->isTrue(Mvc::localizationAvailable());$t->same('fallback',Mvc::translate('key','fallback'));$t->same('legacy:key',Mvc::translateOrNull('key'));
		\dataphyre\localization::$missing=true;$t->same(null,Mvc::translateOrNull('key'));$t->isFalse(Mvc::translationHas('key'));$t->isTrue(Mvc::translationMissing('key'));\dataphyre\localization::$missing=false;
		$t->same('legacy:many',Mvc::choice(2,'one','many','zero'));
		$t->isTrue(Mvc::currencyAvailable());$t->same('fmt:5',Mvc::moneyFormat(5));$t->same(10.0,Mvc::moneyConvert(5,'USD','CAD'));$t->same(6.0,Mvc::moneyToDisplay(5));
		$t->same(4.0,Mvc::moneyToBase(5,'USD'));$t->same(1.24,Mvc::moneyRound(1.235,'USD'));$t->same([3.0,3.0],Mvc::moneySplit(6,'USD',2));$t->same([1,2],Mvc::moneyAllocate(6,'USD',[1,2]));
		$t->isTrue(Mvc::dateTranslationAvailable());$t->same('translated-date',Mvc::translateDate('2026-01-01','en','Y-m-d'));
	})->tag('mvc','facade','legacy','deep-coverage')->group('framework-coverage');

	test('mvc facade legacy bridges deep coverage exercises cache async storage mail access and permission',static function(Context $t): void {
		\dataphyre\cache::$values=[];$t->isTrue(Mvc::cacheAvailable());$t->same('fallback',Mvc::cacheGet('missing','fallback'));$t->isTrue(Mvc::cachePut('key','value',10));$t->same('value',Mvc::cacheGet('key'));
		$t->same('value',Mvc::cacheRemember('key',10,static fn()=>'new'));$t->same('resolved',Mvc::cacheRemember('new',10,static fn()=>'resolved'));
		$t->same(2,Mvc::cacheIncrement('count',2));$t->same(1,Mvc::cacheDecrement('count'));$t->same(false,Mvc::cacheIncrement('bad'));$t->same(false,Mvc::cacheDecrement('bad'));$t->isTrue(Mvc::cacheForget('key'));
		$t->isTrue(Mvc::asyncAvailable());$t->same(3,Mvc::asyncDispatch(static fn(int $a,int $b): int=>$a+$b,[1,2]));$t->same(5,Mvc::asyncInline(static fn(int $v): int=>$v,[5]));
		$t->same(31,Mvc::asyncAfter(static fn()=>null,5));$t->same(32,Mvc::asyncEvery(static fn()=>null,5));Mvc::asyncCancel(31);$t->same([31],\dataphyre\async::$cancelled);
		$t->isTrue(Mvc::storageAvailable());$file=Mvc::storageFile('file.txt','disk',null,['X-Test'=>'yes']);$t->same('stored-body',$file->body);$t->same('text/custom',$file->headers['Content-Type']);$t->notEmpty($file->headers['Last-Modified']);
		$download=Mvc::storageDownload('folder/file.txt','disk','custom.txt');$t->contains('attachment',$download->headers['Content-Disposition']);$t->same('legacy://file.txt',Mvc::storageTemporaryUrl('file.txt',time()+60));
		$t->throws(static fn()=>Mvc::storageFile('missing'),HttpException::class);
		$t->isTrue(Mvc::mailerAvailable());$t->same('send',Mvc::sendMail(['to'=>[]])['kind']);$t->same('queue',Mvc::queueMail(['to'=>[]])['kind']);$t->same('s',Mvc::renderMail('template')['subject']);$t->same([],Mvc::renderMail('bad'));
		\dataphyre\mailer::$objects=true;$t->same('object',Mvc::sendMail(['to'=>[]])['source']);$t->same('object',Mvc::queueMail(['to'=>[]])['source']);
		$t->isTrue(Mvc::accessAvailable());$t->isTrue(Mvc::loggedIn('web'));$t->same(42,Mvc::userId('web'));$t->isTrue(Mvc::authContext('web')['logged_in']);
		$t->isTrue(Mvc::permissionAvailable());$t->isTrue(Mvc::can('allow'));$t->isTrue(Mvc::canAny(['allow']));$t->isTrue(Mvc::authorize('allow'));$t->isTrue(Mvc::authorizeAny(['allow']));
	})->tag('mvc','facade','legacy','deep-coverage')->group('framework-coverage');
}
