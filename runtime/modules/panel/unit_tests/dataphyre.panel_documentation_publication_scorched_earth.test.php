<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelApiReferenceExtractor;
use Dataphyre\Panel\PanelCompatibilityMatrix;
use Dataphyre\Panel\PanelDocumentationCatalog;
use Dataphyre\Panel\PanelDocumentationPublication;
use Dataphyre\Panel\PanelDocumentationPublisher;
use Dataphyre\Panel\PanelScaffoldResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel deterministic documentation publication')
	->framework(['panel'])
	->tag('panel','documentation','publication','security','deep-coverage')
	->group('framework-coverage');

/** @return string */
function dp_panel_docs_fixture(Context $t): string {
	$root=$t->tempDirectory('panel-doc-source');
	file_put_contents($root.DIRECTORY_SEPARATOR.'Contracts.php',<<<'PHP'
<?php
namespace Acme\Api;

$ignored=new #[\AllowDynamicProperties] class extends \stdClass {
	public function ghost(): void {}
};

if(false){ class Phantom {} }
function createLocal(string $value): void { $copy="Hidden {$value}"; class Local {} }

/** Demonstration contract. */
interface Contract {
	/** Execute work. */
	public function execute(int $count=1): string;
}

/**
 * Reusable behavior.
 * @internal
 */
trait Behavior {
	public function behavior(): string { return 'ready'; }
}

/** Current state. */
enum State: string {
	case Ready='ready';
	case Waiting='waiting';
	public function label(): string { return $this->value; }
}
PHP);
	file_put_contents($root.DIRECTORY_SEPARATOR.'Demo.php',<<<'PHP'
<?php
namespace Acme\Api;

/**
 * Public demonstration API.
 *
 * Further detail is intentionally excluded from the summary.
 */
final readonly class Demo implements Contract {
	use Behavior;
	public const string NAME='demo', OTHER='other';
	public const string SPACED='a,  b';
	public const array MAP=['a'=>1, 'b'=>2], OTHER_MAP=['x'=>3];
	private const HIDDEN='hidden';
	public string $name;
	protected string $protectedName;

	public function __construct(string $name='demo') { $this->name=$name; }

	/** Run the operation.
	 * @deprecated use execute()
	 */
	public static function run(int $count=1): ?string { return $count>0 ? 'ok' : null; }

	public function execute(int $count=1): string { return (string)$count; }
	public function defaults(string $value="a  b"): string { return $value; }
	#[\Deprecated]
	public function legacy(): void {}
	/** Actual attributed method. */
	#[ExampleAttribute(/** @deprecated inner */ true)]
	public function attributed(): void {}
	#[ExampleAttribute, \Deprecated]
	public function groupedDeprecated(): void {}
	#[ExampleAttribute("x, Deprecated]")]
	public function notDeprecated(): void {}
	public function interpolate(string $name): string { $local="Hello {$name}"; return $local; }
	/** Correct method summary. */
	public function documented(/** @deprecated parameter only */ int $value): void {}
	private function hidden(): void {}
}

#[\AllowDynamicProperties]
#[ExampleAttribute("`value`")]
abstract class Secondary {
	public const TYPE=Demo::class;
	public mixed $first=null, $second=null;
	public string $hooked {
		get => (string)$this->first;
		set { $this->first=$value; }
	}
	abstract public string $virtual { get; set; }
	public mixed $complex { get => (function () { set(); return get(); })(); }
	public mixed $reference { &get { return $this->first; } }
	public mixed $attributedHook {
		#[Get]
		set => $this->first=$value;
		#[ExampleAttribute(set: true)]
		get => $this->first;
	}
	/** Build a secondary API. */
	public function __construct(/** Identifier parameter. */ public readonly string $id='default', private string $secret='hidden') {}
	abstract public function open(): void;
	protected function closed(): void {}
}
PHP);
	file_put_contents($root.DIRECTORY_SEPARATOR.'Empty.php',"<?php\n// no public type\n");
	file_put_contents($root.DIRECTORY_SEPARATOR.'invalid.txt','not php');
	return $root;
}

test('api reference extraction is source confined non executing and exhaustive across public type shapes',static function(Context $t): void {
	$root=dp_panel_docs_fixture($t);
	$extractor=PanelApiReferenceExtractor::make($root);
	$t->same(str_replace('\\','/',(string)realpath($root)),$extractor->root());
	$symbols=$extractor->extract(['Demo.php','Contracts.php','Demo.php']);
	$t->same(5,count($symbols));
	$t->same(['Acme\\Api\\Behavior','Acme\\Api\\Contract','Acme\\Api\\Demo','Acme\\Api\\Secondary','Acme\\Api\\State'],array_column($symbols,'fqcn'));
	$t->notContains('Acme\\Api\\Phantom',array_column($symbols,'fqcn'));
	$t->notContains('Acme\\Api\\Local',array_column($symbols,'fqcn'));
	$t->isTrue($symbols[0]['internal']);

	$demo=$symbols[2];
	$t->same('class',$demo['kind']);
	$t->isTrue($demo['final']);
	$t->isTrue($demo['readonly']);
	$t->isFalse($demo['abstract']);
	$t->contains('final readonly class Demo',$demo['signature']);
	$t->same('Public demonstration API.',$demo['summary']);
	$t->same('Demo.php',$demo['source']);
	$t->same(['Contract'],$demo['implements']);
	$t->same(['Behavior'],$demo['uses']);
	$t->same(hash_file('sha256',$root.DIRECTORY_SEPARATOR.'Demo.php'),$demo['source_sha256']);
	$memberNames=array_column($demo['members'],'name');
	$t->contains('NAME',$memberNames);
	$t->contains('OTHER',$memberNames);
	$t->contains('SPACED',$memberNames);
	$t->contains('MAP',$memberNames);
	$t->contains('OTHER_MAP',$memberNames);
	$t->notContains('a',$memberNames);
	$t->contains('name',$memberNames);
	$t->contains('__construct',$memberNames);
	$t->contains('execute',$memberNames);
	$t->contains('defaults',$memberNames);
	$t->contains('legacy',$memberNames);
	$t->contains('attributed',$memberNames);
	$t->contains('groupedDeprecated',$memberNames);
	$t->contains('notDeprecated',$memberNames);
	$t->contains('interpolate',$memberNames);
	$t->contains('documented',$memberNames);
	$t->contains('run',$memberNames);
	$t->notContains('local',$memberNames);
	$t->notContains('HIDDEN',$memberNames);
	$t->notContains('hidden',$memberNames);
	$run=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='run'))[0];
	$t->isTrue($run['deprecated']);
	$t->isTrue($run['static']);
	$t->contains('function run',$run['signature']);
	$spaced=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='SPACED'))[0];
	$t->contains("'a,  b'",$spaced['signature']);
	$defaults=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='defaults'))[0];
	$t->contains('"a  b"',$defaults['signature']);
	$legacy=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='legacy'))[0];
	$t->isTrue($legacy['deprecated']);
	$t->contains('#[\\Deprecated]',$legacy['attributes']);
	$attributed=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='attributed'))[0];
	$t->same('Actual attributed method.',$attributed['summary']);
	$t->isFalse($attributed['deprecated']);
	$groupedDeprecated=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='groupedDeprecated'))[0];
	$t->isTrue($groupedDeprecated['deprecated']);
	$notDeprecated=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='notDeprecated'))[0];
	$t->isFalse($notDeprecated['deprecated']);

	$secondary=$symbols[3];
	$t->isTrue($secondary['abstract']);
	$t->contains('abstract class Secondary',$secondary['signature']);
	$t->contains('TYPE',array_column($secondary['members'],'name'));
	$t->contains('first',array_column($secondary['members'],'name'));
	$t->contains('second',array_column($secondary['members'],'name'));
	$t->contains('id',array_column($secondary['members'],'name'));
	$t->contains('#[\\AllowDynamicProperties]',$secondary['attributes']);
	$t->notContains('secret',array_column($secondary['members'],'name'));
	$idMember=array_values(array_filter($secondary['members'],static fn(array $member): bool=>$member['kind']==='property' && $member['name']==='id'))[0];
	$t->contains("public readonly string \$id='default'",$idMember['signature']);
	$t->same('Identifier parameter.',$idMember['summary']);
	$t->isFalse($idMember['deprecated']);
	$hooked=array_values(array_filter($secondary['members'],static fn(array $member): bool=>$member['kind']==='property' && $member['name']==='hooked'))[0];
	$t->isTrue($hooked['has_hooks']);
	$t->same(['get','set'],array_column($hooked['hooks'],'name'));
	$t->contains('get;',$hooked['signature']);
	$t->contains('set;',$hooked['signature']);
	$virtual=array_values(array_filter($secondary['members'],static fn(array $member): bool=>$member['kind']==='property' && $member['name']==='virtual'))[0];
	$t->same(['get','set'],array_column($virtual['hooks'],'name'));
	$complex=array_values(array_filter($secondary['members'],static fn(array $member): bool=>$member['kind']==='property' && $member['name']==='complex'))[0];
	$t->same(['get'],array_column($complex['hooks'],'name'));
	$reference=array_values(array_filter($secondary['members'],static fn(array $member): bool=>$member['kind']==='property' && $member['name']==='reference'))[0];
	$t->contains('&get',$reference['hooks'][0]['signature']);
	$attributedHook=array_values(array_filter($secondary['members'],static fn(array $member): bool=>$member['kind']==='property' && $member['name']==='attributedHook'))[0];
	$t->same(['set','get'],array_column($attributedHook['hooks'],'name'));
	$t->contains('#[Get] set',$attributedHook['hooks'][0]['signature']);
	$constructor=array_values(array_filter($secondary['members'],static fn(array $member): bool=>$member['kind']==='method' && $member['name']==='__construct'))[0];
	$t->same('Build a secondary API.',$constructor['summary']);
	$t->isFalse($constructor['deprecated']);
	$documented=array_values(array_filter($demo['members'],static fn(array $member): bool=>$member['name']==='documented'))[0];
	$t->same('Correct method summary.',$documented['summary']);
	$t->isFalse($documented['deprecated']);
	$t->notContains('Demo',array_column($secondary['members'],'name'));
	$contract=$symbols[1];
	$t->isTrue($contract['abstract']);
	$t->same('method',$contract['members'][0]['kind']);
	$state=$symbols[4];
	$t->same('enum',$state['kind']);
	$t->contains('Ready',array_column($state['members'],'name'));
	$t->same($symbols,$extractor->extract());
	file_put_contents($root.DIRECTORY_SEPARATOR.'TraitAdaptation.php',<<<'PHP'
<?php
namespace Acme\Traits;
trait HiddenBehavior {
	protected function hiddenTrait(): void {}
	public function publicTrait(): void {}
}
class Adapted {
	use HiddenBehavior {
		hiddenTrait as public revealed;
		publicTrait as alias;
	}
}
PHP);
	$adaptedSymbols=$extractor->extract(['TraitAdaptation.php']);
	$t->same(['Acme\\Traits\\Adapted','Acme\\Traits\\HiddenBehavior'],array_column($adaptedSymbols,'fqcn'));
	$adapted=$adaptedSymbols[0];
	$t->same(['HiddenBehavior'],$adapted['uses']);
	$alias=array_values(array_filter($adapted['members'],static fn(array $member): bool=>$member['kind']==='trait_alias'))[0];
	$t->same('revealed',$alias['name']);
	$t->isTrue($alias['trait_adaptation']);
	$t->contains('publicTrait as alias;',$adapted['trait_adaptations']);
	file_put_contents($root.DIRECTORY_SEPARATOR.'Bracketed.php',"<?php namespace Braced { if(false){ class Hidden {} } class Visible {} }\n");
	$t->same(['Braced\\Visible'],array_column($extractor->extract(['Bracketed.php']),'fqcn'));
	file_put_contents($root.DIRECTORY_SEPARATOR.'Alternative.php',"<?php namespace Alternative; if(false): class Hidden {} endif; class Visible extends namespace\\Base {}\n");
	$alternative=$extractor->extract(['Alternative.php']);
	$t->same(['Alternative\\Visible'],array_column($alternative,'fqcn'));
	$t->same(['namespace\\Base'],$alternative[0]['extends']);
	file_put_contents($root.DIRECTORY_SEPARATOR.'AttributeComment.php',"<?php namespace AttributeScope; /** Actual type. */ #[ExampleAttribute(/** @internal */ true)] class Clean extends \\GlobalBase {}\n");
	$attributeType=$extractor->extract(['AttributeComment.php'])[0];
	$t->same('Actual type.',$attributeType['summary']);
	$t->isFalse($attributeType['internal']);
	$t->same(['\\GlobalBase'],$attributeType['extends']);
	$extractorType=$t->nonPublic(PanelApiReferenceExtractor::class);
	$extractorInternals=$t->nonPublic($extractor);
	$extractorType->invoke('assertNotLink',false);
	$t->throws(static fn()=>$extractorType->invoke('assertNotLink',true),RuntimeException::class);
	$extractorType->invoke('assertContained','/root/file.php','/root');
	$t->throws(static fn()=>$extractorType->invoke('assertContained','/outside/file.php','/root'),RuntimeException::class);
	$t->isFalse($extractorInternals->invoke('anonymousOrClassConstant',[],0));
	$t->isNull($extractorInternals->invoke('nextToken',[],0,T_STRING));
	$t->isNull($extractorInternals->invoke('nextCharacter',[],0,'{'));
	$t->isNull($extractorInternals->invoke('matchingBrace',['{'],0));
	$t->isNull($extractorInternals->invoke('nameAfter',[],0,T_STRING));
	$t->same([],$extractorInternals->invoke('promotedProperties',['(', '(', ')', ')'],0));

	$t->throws(static fn()=>PanelApiReferenceExtractor::make($root.DIRECTORY_SEPARATOR.'missing'),InvalidArgumentException::class);
	$t->throws(static fn()=>$extractor->extract(['']),InvalidArgumentException::class);
	$t->throws(static fn()=>$extractor->extract(['../Demo.php']),InvalidArgumentException::class);
	$t->throws(static fn()=>$extractor->extract([$root.DIRECTORY_SEPARATOR.'Demo.php']),InvalidArgumentException::class);
	$t->throws(static fn()=>$extractor->extract(['invalid.txt']),InvalidArgumentException::class);
	$t->throws(static fn()=>$extractor->extract(['Missing.php']),InvalidArgumentException::class);
	$link=$root.DIRECTORY_SEPARATOR.'linked.php';
	if(@symlink($root.DIRECTORY_SEPARATOR.'Demo.php',$link)){
		$t->throws(static fn()=>$extractor->extract(['linked.php']),RuntimeException::class);
		$t->throws(static fn()=>$extractor->extract(),RuntimeException::class);
	}
	else{ $t->isFalse(is_link($link)); }
	file_put_contents($root.DIRECTORY_SEPARATOR.'Broken.php',"<?php final class Broken {\n");
	$t->throws(static fn()=>$extractor->extract(['Broken.php']),RuntimeException::class);
})->tag('extractor','tokens','confinement');

test('documentation publisher emits deterministic versioned API cookbook package and atomic plans',static function(Context $t): void {
	$source=dp_panel_docs_fixture($t);
	$deepEncodedScheme='javascript:alert(1)';
	for($pass=0;$pass<9;$pass++){ $deepEncodedScheme=rawurlencode($deepEncodedScheme); }
	$catalog=PanelDocumentationCatalog::make([
		[
			'id'=>'quick-start','title'=>'Quick [start]','category'=>'Getting started','status'=>'published',
			'summary'=>'# forged --- 1. list Build *safely* with <Panel>.','api'=>['Acme\\Api\\Demo::execute','Acme\\Api\\Demo::`odd`'],
			'examples'=>[['title'=>'A fenced example','language'=>'PHP','code'=>"````\nDemo::run();\n````"]],
			'links'=>[
				['label'=>'Guide','target'=>'https://example.test/guide'],
				['label'=>'Encoded guide','target'=>'https://example.test/a%20b'],
				['label'=>'Relative','target'=>'../guide.md'],
				['label'=>'Blocked','target'=>'javascript:alert(1)'],
				['label'=>'Entity blocked','target'=>'java&#x73;cript:alert(1)'],
				['label'=>'Double entity blocked','target'=>'javascript&amp;#58;alert(1)'],
				['label'=>'Percent blocked','target'=>'%6aavascript:alert(1)'],
				['label'=>'Deep percent blocked','target'=>$deepEncodedScheme],
			],
			'meta'=>['access_token'=>'must-not-appear','access_'."\n".'token'=>'also-must-not-appear'],
		],
	]);
	$catalog->meta(['publisher'=>'tests','private_key'=>'must-not-appear']);
	$matrix=PanelCompatibilityMatrix::make([
		['id'=>'acme-tools','label'=>'Acme tools','version'=>'1.2.3','requires'=>['php'=>'>=8.2'],'provides'=>['widgets']],
	],['php'=>PHP_VERSION,'panel'=>'2.0','reactor'=>'2.0','modules'=>['panel'=>'2.0'],'themes'=>[]]);
	$publisher=PanelDocumentationPublisher::make($source);
	$publisherInternals=$t->nonPublic(PanelDocumentationPublisher::class);
	$t->same('\\# forged',$publisherInternals->invoke('markdown','# forged'));
	$t->same('\\-\\-\\-',$publisherInternals->invoke('markdown','---'));
	$t->same('1\\. list',$publisherInternals->invoke('markdown','1. list'));
	$t->contains('`` ', $publisherInternals->invoke('inlineCode','`value`'));
	$t->same('_con',$publisherInternals->invoke('pathSegment','CON'));
	$t->same('a-b-'.substr(hash('sha256','A B'),0,8),$publisherInternals->invoke('pathSegment','A B'));
	$t->same('symbol-'.substr(hash('sha256',''),0,8),$publisherInternals->invoke('pathSegment',''));
	$options=['base_path'=>'public/docs','title'=>'Panel *Reference*','source_paths'=>['Demo.php','Contracts.php'],'meta'=>['api_key'=>'hidden','access_'."\n".'token'=>'hidden-too','channel'=>'stable','ratio'=>1.25]];
	$first=$publisher->build('v2.4.0',$catalog,$matrix,$options);
	$second=$publisher->build('2.4.0',$catalog,$matrix,$options);
	$t->instanceOf(PanelDocumentationPublication::class,$first);
	$t->same('2.4.0',$first->version());
	$t->same('public/docs/versions/2.4.0',$first->releasePrefix());
	$t->same($first->jsonSerialize(),$second->jsonSerialize());
	$t->same(count($first),iterator_count($first->getIterator()));
	$t->same(count($first),count($first->artifacts()));
	$t->isTrue(count($first)>8);
	$t->same(5,$first->manifest()['api_symbol_count']);
	$t->same('[redacted]',$first->manifest()['meta']['api_key']);
	$t->same(1.25,$first->manifest()['meta']['ratio']);
	$t->same(null,$first->artifact('missing.md'));

	$api=$first->artifact('public/docs/versions/2.4.0/api/types/acme/api/demo.md');
	$t->instanceOf(PanelScaffoldResult::class,$api);
	$t->contains('# Acme\\\\Api\\\\Demo',$api->contents());
	$t->contains('## Declared public members',$api->contents());
	$cookbook=$first->artifact('public/docs/versions/2.4.0/cookbook/entries/quick-start.md');
	$t->instanceOf(PanelScaffoldResult::class,$cookbook);
	$t->contains('Quick \\[start\\]',$cookbook->contents());
	$t->contains('\\# forged',$cookbook->contents());
	$t->contains('`````php',$cookbook->contents());
	$t->notContains('javascript:',strtolower($cookbook->contents()));
	$t->notContains('entity blocked',strtolower($cookbook->contents()));
	$t->notContains('percent blocked',strtolower($cookbook->contents()));
	$t->contains('https://example.test/a%20b',$cookbook->contents());
	$t->notContains('a%2520b',$cookbook->contents());
	$t->notContains('must-not-appear',json_encode($first,JSON_THROW_ON_ERROR));
	$t->notContains('also-must-not-appear',json_encode($first,JSON_THROW_ON_ERROR));
	$secondaryApi=$first->artifact('public/docs/versions/2.4.0/api/types/acme/api/secondary.md');
	$t->contains('`` #[ExampleAttribute("`value`")] ``',$secondaryApi->contents());
	$compat=$first->artifact('public/docs/versions/2.4.0/packages/compatibility.md');
	$t->contains('acme\\-tools',$compat->contents());
	$publication=$first->artifact('public/docs/versions/2.4.0/publication.json');
	$t->same($first->manifest(),json_decode($publication->contents(),true,512,JSON_THROW_ON_ERROR));
	$artifacts=$first->artifacts();
	$manifest=$first->manifest();
	$copyArtifact=static function(PanelScaffoldResult $artifact,array $changes=[]): PanelScaffoldResult {
		return PanelScaffoldResult::make(
			$artifact->kind(),$artifact->name(),$artifact->class(),
			(string)($changes['path'] ?? $artifact->path()),
			(string)($changes['contents'] ?? $artifact->contents()),
			(array)($changes['metadata'] ?? $artifact->metadata()),
		);
	};
	$malformedManifest=$manifest;
	$malformedManifest['files'][0]='invalid';
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$artifacts,$malformedManifest),InvalidArgumentException::class);
	$duplicateManifest=$manifest;
	$duplicateManifest['files'][]=$duplicateManifest['files'][0];
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$artifacts,$duplicateManifest),InvalidArgumentException::class);
	$missingMetadata=$artifacts;
	$missingMetadata[0]=$copyArtifact($missingMetadata[0],['metadata'=>[]]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$missingMetadata,$manifest),InvalidArgumentException::class);
	$badSuffix=$artifacts;
	$badSuffix[0]=$copyArtifact($badSuffix[0],['path'=>$badSuffix[0]->path().'.wrong']);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$badSuffix,$manifest),InvalidArgumentException::class);
	$badRelease=$artifacts;
	$badRelease[0]=$copyArtifact($badRelease[0],['path'=>'docs/versions/9.9.9/'.$badRelease[0]->metadata()['relative_path']]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$badRelease,$manifest),InvalidArgumentException::class);
	$mixedCaseRelease=$artifacts;
	$mixedCasePath=$mixedCaseRelease[1]->path();
	$mixedCaseRelease[1]=$copyArtifact($mixedCaseRelease[1],['path'=>'PUBLIC/docs'.substr($mixedCasePath,strlen('public/docs'))]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$mixedCaseRelease,$manifest),InvalidArgumentException::class);
	$badMetadata=$artifacts;
	$badMetadata[0]=$copyArtifact($badMetadata[0],['metadata'=>array_replace($badMetadata[0]->metadata(),['version'=>'2.4.1'])]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$badMetadata,$manifest),InvalidArgumentException::class);
	$tamperedArtifact=$artifacts;
	$tamperedContents=$tamperedArtifact[0]->contents().'tampered';
	$tamperedArtifact[0]=$copyArtifact($tamperedArtifact[0],['contents'=>$tamperedContents,'metadata'=>array_replace($tamperedArtifact[0]->metadata(),['sha256'=>hash('sha256',$tamperedContents)])]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$tamperedArtifact,$manifest),InvalidArgumentException::class);
	$publicationIndex=array_search('publication.json',array_map(static fn(PanelScaffoldResult $artifact): string=>(string)($artifact->metadata()['relative_path'] ?? ''),$artifacts),true);
	$t->isTrue(is_int($publicationIndex));
	$withoutPublication=$artifacts;
	array_splice($withoutPublication,$publicationIndex,1);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$withoutPublication,$manifest),InvalidArgumentException::class);
	$invalidPublication=$artifacts;
	$invalidPublicationContents='{';
	$invalidPublication[$publicationIndex]=$copyArtifact($invalidPublication[$publicationIndex],['contents'=>$invalidPublicationContents,'metadata'=>array_replace($invalidPublication[$publicationIndex]->metadata(),['sha256'=>hash('sha256',$invalidPublicationContents)])]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$invalidPublication,$manifest),InvalidArgumentException::class);
	$mismatchedPublication=$artifacts;
	$mismatchedContents=json_encode(array_replace($manifest,['title'=>'Different']),JSON_THROW_ON_ERROR);
	$mismatchedPublication[$publicationIndex]=$copyArtifact($mismatchedPublication[$publicationIndex],['contents'=>$mismatchedContents,'metadata'=>array_replace($mismatchedPublication[$publicationIndex]->metadata(),['sha256'=>hash('sha256',$mismatchedContents)])]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$mismatchedPublication,$manifest),InvalidArgumentException::class);
	$semanticManifest=array_replace($manifest,['api_symbol_count'=>$manifest['api_symbol_count']+1]);
	$semanticContents=json_encode($semanticManifest,JSON_THROW_ON_ERROR);
	$semanticArtifacts=$artifacts;
	$semanticArtifacts[$publicationIndex]=$copyArtifact($semanticArtifacts[$publicationIndex],['contents'=>$semanticContents,'metadata'=>array_replace($semanticArtifacts[$publicationIndex]->metadata(),['sha256'=>hash('sha256',$semanticContents)])]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$semanticArtifacts,$semanticManifest),InvalidArgumentException::class);
	$titleManifest=array_replace($manifest,['title'=>'Contradictory title']);
	$titleContents=json_encode($titleManifest,JSON_THROW_ON_ERROR);
	$titleArtifacts=$artifacts;
	$titleArtifacts[$publicationIndex]=$copyArtifact($titleArtifacts[$publicationIndex],['contents'=>$titleContents,'metadata'=>array_replace($titleArtifacts[$publicationIndex]->metadata(),['sha256'=>hash('sha256',$titleContents)])]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$titleArtifacts,$titleManifest),InvalidArgumentException::class);
	$apiIndex=array_search('api/index.json',array_map(static fn(PanelScaffoldResult $artifact): string=>(string)($artifact->metadata()['relative_path'] ?? ''),$artifacts),true);
	$t->isTrue(is_int($apiIndex));
	$mutateSemanticArtifact=static function(string $relative,callable $mutator) use ($artifacts,$manifest,$copyArtifact,$publicationIndex): array {
		$changedArtifacts=$artifacts;
		$changedManifest=$manifest;
		$index=array_search($relative,array_map(static fn(PanelScaffoldResult $artifact): string=>(string)($artifact->metadata()['relative_path'] ?? ''),$changedArtifacts),true);
		if(!is_int($index)){ throw new RuntimeException('Semantic fixture artifact is missing.'); }
		$payload=json_decode($changedArtifacts[$index]->contents(),true,512,JSON_THROW_ON_ERROR);
		$mutator($payload);
		$contents=json_encode($payload,JSON_THROW_ON_ERROR);
		$changedArtifacts[$index]=$copyArtifact($changedArtifacts[$index],['contents'=>$contents,'metadata'=>array_replace($changedArtifacts[$index]->metadata(),['sha256'=>hash('sha256',$contents)])]);
		foreach($changedManifest['files'] as &$file){
			if($file['path']===$relative){ $file['bytes']=strlen($contents); $file['sha256']=hash('sha256',$contents); }
		}
		unset($file);
		$publicationContents=json_encode($changedManifest,JSON_THROW_ON_ERROR);
		$changedArtifacts[$publicationIndex]=$copyArtifact($changedArtifacts[$publicationIndex],['contents'=>$publicationContents,'metadata'=>array_replace($changedArtifacts[$publicationIndex]->metadata(),['sha256'=>hash('sha256',$publicationContents)])]);
		return [$changedArtifacts,$changedManifest];
	};
	foreach([
		['api/index.json',static function(array &$payload): void { $payload['type']='forged_api_reference'; }],
		['api/index.json',static function(array &$payload): void { $payload['symbols']=['forged'=>$payload['symbols'][0]]; }],
		['api/index.json',static function(array &$payload): void { $payload['symbols'][0]['source_sha256']='forged'; }],
		['cookbook/catalog.json',static function(array &$payload): void { $payload['type']='forged_documentation_catalog'; }],
		['cookbook/catalog.json',static function(array &$payload): void { $payload['entries']=['forged'=>$payload['entries'][0]]; }],
		['cookbook/catalog.json',static function(array &$payload): void { $payload['entry_count']++; $payload['link_count']++; }],
		['packages/compatibility.json',static function(array &$payload): void { $payload['type']='forged_compatibility_matrix'; }],
		['packages/compatibility.json',static function(array &$payload): void { $payload['packages']=['forged'=>$payload['packages'][0]]; }],
		['packages/compatibility.json',static function(array &$payload): void { $payload['compatible_count']++; $payload['blocked_count']--; $payload['provides']['forged']=1; }],
	] as [$relative,$mutator]){
		[$forgedArtifacts,$forgedManifest]=$mutateSemanticArtifact($relative,$mutator);
		$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$forgedArtifacts,$forgedManifest),InvalidArgumentException::class);
	}
	$versionArtifacts=$artifacts;
	$apiPayload=json_decode($versionArtifacts[$apiIndex]->contents(),true,512,JSON_THROW_ON_ERROR);
	$apiPayload['version']='2.4.1';
	$apiContents=json_encode($apiPayload,JSON_THROW_ON_ERROR);
	$versionArtifacts[$apiIndex]=$copyArtifact($versionArtifacts[$apiIndex],['contents'=>$apiContents,'metadata'=>array_replace($versionArtifacts[$apiIndex]->metadata(),['sha256'=>hash('sha256',$apiContents)])]);
	$versionManifest=$manifest;
	foreach($versionManifest['files'] as &$file){
		if($file['path']==='api/index.json'){ $file['bytes']=strlen($apiContents); $file['sha256']=hash('sha256',$apiContents); }
	}
	unset($file);
	$versionManifestContents=json_encode($versionManifest,JSON_THROW_ON_ERROR);
	$versionArtifacts[$publicationIndex]=$copyArtifact($versionArtifacts[$publicationIndex],['contents'=>$versionManifestContents,'metadata'=>array_replace($versionArtifacts[$publicationIndex]->metadata(),['sha256'=>hash('sha256',$versionManifestContents)])]);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',$versionArtifacts,$versionManifest),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDocumentationPublication::make('2.4.0',[...$artifacts,$artifacts[0]],$manifest),InvalidArgumentException::class);

	$target=$t->tempDirectory('panel-doc-publish');
	$hostileLockTarget=$t->tempDirectory('panel-doc-publish-hostile-lock');
	mkdir($hostileLockTarget.DIRECTORY_SEPARATOR.'.dataphyre-panel-docs.lock');
	$t->throws(static fn()=>$first->apply($hostileLockTarget,'error',false),RuntimeException::class);
	$busyTarget=$t->tempDirectory('panel-doc-publish-busy');
	$busyLock=fopen($busyTarget.DIRECTORY_SEPARATOR.'.dataphyre-panel-docs.lock','c+b');
	$t->isTrue(is_resource($busyLock));
	$t->isTrue(flock($busyLock,LOCK_EX|LOCK_NB));
	$t->throws(static fn()=>$first->apply($busyTarget,'error',false),RuntimeException::class);
	flock($busyLock,LOCK_UN);
	fclose($busyLock);
	$preview=$first->apply($target,'error',true);
	$t->isTrue($preview->dryRun());
	$t->isFalse(is_file($target.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'2.4.0'.DIRECTORY_SEPARATOR.'index.md'));
	$previousUmask=umask(0077);
	try { $written=$first->apply($target,'error',false); }
	finally { umask($previousUmask); }
	$t->isFalse($written->dryRun());
	$t->isTrue(is_file($target.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'2.4.0'.DIRECTORY_SEPARATOR.'index.md'));
	$t->same([],glob($target.DIRECTORY_SEPARATOR.'.dataphyre-panel-docs-*') ?: []);
	$release=$target.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'2.4.0';
	$index=$release.DIRECTORY_SEPARATOR.'index.md';
	if(DIRECTORY_SEPARATOR!=='\\'){
		$mode=static function(string $path): string { clearstatcache(true,$path); return substr(sprintf('%o',(int)fileperms($path)),-4); };
		foreach([$target.DIRECTORY_SEPARATOR.'public',$target.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'docs',dirname($release),$release,$release.DIRECTORY_SEPARATOR.'api'] as $directory){ $t->same('0755',$mode($directory)); }
		$t->same('0644',$mode($index));
		foreach([$target.DIRECTORY_SEPARATOR.'public',$target.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'docs',dirname($release),$release,$release.DIRECTORY_SEPARATOR.'api'] as $directory){ chmod($directory,0700); }
		chmod($index,0600);
		$t->isFalse($first->apply($target,'error',true)->changed());
		$t->same('0700',$mode($release));
		$t->same('0600',$mode($index));
		$repaired=$first->apply($target,'error',false);
		$t->isFalse($repaired->changed());
		foreach([$target.DIRECTORY_SEPARATOR.'public',$target.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'docs',dirname($release),$release,$release.DIRECTORY_SEPARATOR.'api'] as $directory){ $t->same('0755',$mode($directory)); }
		$t->same('0644',$mode($index));
	}
	$idempotent=$first->apply($target,'error',false);
	$t->isFalse($idempotent->changed());
	$t->same(count($first),count($idempotent->skipped()));
	$publicationInternals=$t->nonPublic($first);
	$publicationType=$t->nonPublic(PanelDocumentationPublication::class);
	$t->throws(static fn()=>$publicationInternals->invoke('verifyTree',$release.DIRECTORY_SEPARATOR.'missing'),RuntimeException::class);
	$t->throws(static fn()=>$publicationInternals->invoke('verifyTree',$release.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'..'),RuntimeException::class);
	mkdir($release.DIRECTORY_SEPARATOR.'unexpected-directory');
	$t->throws(static fn()=>$publicationInternals->invoke('verifyTree',$release),RuntimeException::class);
	rmdir($release.DIRECTORY_SEPARATOR.'unexpected-directory');
	$cleanup=$t->tempDirectory('panel-doc-cleanup-guard');
	mkdir($cleanup.DIRECTORY_SEPARATOR.'untrusted-directory');
	$publicationType->invoke('removeContainedTree',$cleanup,$t->tempDirectory('panel-doc-cleanup-other-root'));
	$t->isFalse(is_dir($cleanup));
	$t->throws(static fn()=>$publicationType->invoke('relativePath','../escape'),InvalidArgumentException::class);
	$t->throws(static fn()=>$publicationType->invoke('relativePath',''),InvalidArgumentException::class);
	$t->throws(static fn()=>$first->apply($target,'skip',false),InvalidArgumentException::class);
	$t->throws(static fn()=>$first->apply($target,'replace',false),InvalidArgumentException::class);
	file_put_contents($release.DIRECTORY_SEPARATOR.'malware.html','injected');
	$t->throws(static fn()=>$first->apply($target,'error',false),RuntimeException::class);
	unlink($release.DIRECTORY_SEPARATOR.'malware.html');
	$changed=$publisher->build('2.4.0',$catalog,$matrix,array_replace($options,['title'=>'Changed release']));
	$t->throws(static fn()=>$changed->apply($target,'error',false),RuntimeException::class);
	unlink($release.DIRECTORY_SEPARATOR.'index.md');
	$t->throws(static fn()=>$first->apply($target,'error',false),RuntimeException::class);

	$emptyCatalog=$publisher->build('0.0.1',null,null,['source_paths'=>['Demo.php'],'base_path'=>'docs']);
	$t->contains('No cookbook entries',$emptyCatalog->artifact('docs/versions/0.0.1/cookbook/index.md')->contents());
	$t->contains('No packages registered',$emptyCatalog->artifact('docs/versions/0.0.1/packages/compatibility.md')->contents());
	$t->throws(static fn()=>$publisher->build('01.0.0'),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0-01'),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0-RC1'),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0+'.str_repeat('a',65)),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0+'.str_repeat('a',129)),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>'../docs']),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>'/absolute/docs']),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>'\\\\server\\share']),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>'docs/NUL']),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>'docs/a?b']),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>'docs/'.str_repeat('a',97)]),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>str_repeat('a',85).'/'.str_repeat('b',85)]),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['base_path'=>'bad:name']),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['source_paths'=>'bad']),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['source_paths'=>['Empty.php']]),RuntimeException::class);
	file_put_contents($source.DIRECTORY_SEPARATOR.'CaseUpper.php',"<?php namespace Collision; class Same {}\n");
	file_put_contents($source.DIRECTORY_SEPARATOR.'CaseLower.php',"<?php namespace Collision; class same {}\n");
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['source_paths'=>['CaseUpper.php','CaseLower.php']]),RuntimeException::class);
	file_put_contents($source.DIRECTORY_SEPARATOR.'DuplicateOne.php',"<?php namespace Collision; class Exact {}\n");
	file_put_contents($source.DIRECTORY_SEPARATOR.'DuplicateTwo.php',"<?php namespace Collision; class Exact {}\n");
	$t->throws(static fn()=>$publisher->build('1.0.0',options:['source_paths'=>['DuplicateOne.php','DuplicateTwo.php']]),RuntimeException::class);
	$deepNamespace=implode('\\',array_map(static fn(int $index): string=>'Segment'.$index,range(1,32)));
	file_put_contents($source.DIRECTORY_SEPARATOR.'Deep.php',"<?php namespace {$deepNamespace}; class Deep {}\n");
	$deepPublication=$publisher->build('1.0.2',options:['source_paths'=>['Deep.php'],'base_path'=>'docs']);
	$deepApi=array_values(array_filter($deepPublication->artifacts(),static fn(PanelScaffoldResult $artifact): bool=>str_contains($artifact->path(),'/api/types/_long/')));
	$t->same(1,count($deepApi));
	foreach($deepPublication->artifacts() as $deepArtifact){ $t->isTrue(strlen($deepArtifact->path())<=180); }
	file_put_contents($source.DIRECTORY_SEPARATOR.'Index.php',"<?php class Index {}\n");
	$indexPublication=$publisher->build('1.0.1',PanelDocumentationCatalog::make([['id'=>'index','title'=>'Index entry']]),null,['source_paths'=>['Index.php'],'base_path'=>'docs']);
	$t->instanceOf(PanelScaffoldResult::class,$indexPublication->artifact('docs/versions/1.0.1/api/types/index.md'));
	$t->instanceOf(PanelScaffoldResult::class,$indexPublication->artifact('docs/versions/1.0.1/cookbook/entries/index.md'));

	$badCatalog=PanelDocumentationCatalog::make()->meta('unsafe',static fn()=>true);
	$t->throws(static fn()=>$publisher->build('1.0.0',$badCatalog,null,['source_paths'=>['Demo.php']]),InvalidArgumentException::class);
	$invalidUtf8=PanelDocumentationCatalog::make()->meta('invalid_utf8',"\xB1");
	$t->throws(static fn()=>$publisher->build('1.0.0',$invalidUtf8,null,['source_paths'=>['Demo.php']]),RuntimeException::class);
	$collidingMeta=PanelDocumentationCatalog::make()->meta(["foo\nbar"=>'one','foo bar'=>'two']);
	$t->throws(static fn()=>$publisher->build('1.0.0',$collidingMeta,null,['source_paths'=>['Demo.php']]),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',PanelDocumentationCatalog::make()->meta('huge',str_repeat('a',100001)),null,['source_paths'=>['Demo.php']]),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->build('1.0.0',PanelDocumentationCatalog::make()->meta('infinite',INF),null,['source_paths'=>['Demo.php']]),InvalidArgumentException::class);
	$catalogForward=PanelDocumentationCatalog::make([
		['id'=>'a','title'=>'Same','category'=>'Same'],['id'=>'b','title'=>'Same','category'=>'Same'],
	]);
	$catalogReverse=PanelDocumentationCatalog::make([
		['id'=>'b','title'=>'Same','category'=>'Same'],['id'=>'a','title'=>'Same','category'=>'Same'],
	]);
	$runtime=['php'=>PHP_VERSION,'panel'=>'2.0','reactor'=>'2.0','modules'=>[],'themes'=>[]];
	$packagesForward=PanelCompatibilityMatrix::make([['id'=>'a-package','version'=>'1.0.0'],['id'=>'b-package','version'=>'1.0.0']],$runtime);
	$packagesReverse=PanelCompatibilityMatrix::make([['id'=>'b-package','version'=>'1.0.0'],['id'=>'a-package','version'=>'1.0.0']],$runtime);
	$t->same(
		$publisher->build('1.3.0',$catalogForward,$packagesForward,['source_paths'=>['Demo.php']])->jsonSerialize(),
		$publisher->build('1.3.0',$catalogReverse,$packagesReverse,['source_paths'=>['Demo.php']])->jsonSerialize()
	);
	$artifact=PanelScaffoldResult::make('documentation','same','','same.md','one');
	$t->throws(static fn()=>PanelDocumentationPublication::make('1.0.0',[new stdClass()],[]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDocumentationPublication::make('1.0.0',[$artifact,$artifact],[]),InvalidArgumentException::class);
})->tag('publisher','semver','markdown','json','atomic');
