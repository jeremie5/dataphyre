<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Mcp\Testing;

use Dataphyre\Mcp\Contracts\ContractCatalog;
use Dataphyre\Mcp\Contracts\SourceContractIndex;
use Dataphyre\Test\Contracts\TestContext;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TempWorkspace;

require_once dirname(__DIR__).'/kernel/dataphyre_mcp.contract.source.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.contract.index.php';
require_once dirname(__DIR__).'/kernel/dataphyre_mcp.contract.catalog.php';

/**
 * Small, intention-named source corpus for exercising every MCP contract family.
 *
 * Its PHP test file contains a deliberate filesystem side effect. If the MCP
 * ever requires test sources instead of tokenizing them, markerExists() makes
 * that safety regression immediately visible.
 */
final class McpContractFixture {
	public const CODE_TEST='runtime/modules/ledger/unit_tests/dataphyre.ledger.contracts.test.php';
	public const CONFLICT_TEST='runtime/modules/ledger/unit_tests/dataphyre.ledger.contract_version.test.php';
	public const DANGLING_TEST='runtime/modules/ledger/unit_tests/dataphyre.ledger.dangling.test.php';
	public const EXHAUSTED_TEST='runtime/modules/ledger/unit_tests/dataphyre.ledger.exhausted.test.php';
	public const JSON_TEST='runtime/modules/ledger/unit_tests/dataphyre.ledger.legacy.json';
	public const INVALID_JSON_TEST='runtime/modules/omega/unit_tests/dataphyre.omega.invalid.json';

	private TempWorkspace $workspace;

	public function __construct(TestContext $context) {
		$this->workspace=$context->workspace('mcp-contract-corpus');
		$this->writeCorpus();
	}

	public function repositoryRoot(): string {return $this->workspace->root();}
	public function dataphyreRoot(): string {return $this->workspace->path('dataphyre');}
	public function repoPath(string $path): string {return 'dataphyre/'.ltrim($path,'/');}
	public function markerExists(): bool {return is_file($this->workspace->path('dataphyre/runtime/modules/ledger/unit_tests/SOURCE_WAS_EXECUTED'));}

	public function source(array $modules=[],int $maxFiles=30000): SourceContractIndex {
		return new SourceContractIndex($this->dataphyreRoot(),$modules,$maxFiles);
	}

	public function catalog(array $modules=[],int $maxFiles=30000): ContractCatalog {
		return new ContractCatalog($this->source($modules,$maxFiles));
	}

	private function writeCorpus(): void {
		$this->workspace->file('dataphyre/runtime/modules/ledger/Framework/Contracts/LedgerStore.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Fixture\Ledger\Contracts;

/** Persists and replays an ordered tenant ledger. */
interface LedgerStore {
	public function append(string $scope,array $events): int;
	function count(string $scope): int;
	public function read(string $scope,int $after=0): array;
}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/ledger/Framework/PdoLedgerStore.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Fixture\Ledger;

use Fixture\Ledger\Contracts\LedgerStore as Store;

final class PdoLedgerStore implements Store {
	public function append(string $scope,array $events): int {return count($events);}
	public function count(string $scope): int {return 0;}
	public function read(string $scope,int $after=0): array {return [];}
}

abstract class LedgerStoreDecorator implements Store {
	abstract public function append(string $scope,array $events): int;
	public function count(string $scope): int {return 0;}
	public function read(string $scope,int $after=0): array {$noop=function(): void {};$noop();return [];}
}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/ledger/Framework/LedgerManifest.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Fixture\Ledger;

final class ContractVersions {public const CHECKPOINT=4;}

final class LedgerManifest implements \JsonSerializable {
	public function jsonSerialize(): array {
		return ['type'=>'ledger_manifest','version'=>2,'scope'=>'demo'];
	}
}

final class LedgerCheckpoint implements \JsonSerializable {
	public function jsonSerialize(): array {
		return ['type'=>'ledger_checkpoint','version'=>ContractVersions::CHECKPOINT,'cursor'=>9];
	}
}

final class LedgerEvent implements \JsonSerializable {
	public function jsonSerialize(): array {
		return ['type'=>'ledger_event','version'=>null,'scope'=>'demo'];
	}
}
PHP);
		$this->workspace->file('dataphyre/runtime/modules/ledger/unit_tests/dataphyre.ledger.contracts.test.php',<<<'PHP'
<?php
declare(strict_types=1);
namespace Fixture\Ledger\Tests;

use Dataphyre\Test\Context;
use Fixture\Ledger\Contracts\LedgerStore;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

file_put_contents(__DIR__.'/SOURCE_WAS_EXECUTED','unsafe');

final class ContractVersions {public const BEHAVIOR=7;}
final class LedgerStoreDouble implements LedgerStore {
	public function append(string $scope,array $events): int {return 0;}
	public function count(string $scope): int {return 0;}
	public function read(string $scope,int $after=0): array {return [];}
}

suite('Ledger store source contract')
	->tag('ledger','contract')
	->group('framework-coverage')
	->contract('ledger.store.behavior',2)
	->layer('contract')
	->risk('critical')
	->watches('type:Fixture\\Ledger\\Contracts\\LedgerStore')
	->through('append','read')
	->isolation('case')
	->maxMillis(2500);

test('reports malformed records',static function(Context $t): void {
	(new FixtureOnlyDefinition())->contract('ledger.fixture-only',99);
	$t->isTrue(true);
})
	->contract('ledger.store.dynamic',ContractVersions::BEHAVIOR)
	->risk('high')
	->watches('type:Fixture\\Ledger\\Contracts\\LedgerStore');
PHP);
		$this->workspace->file('dataphyre/'.self::DANGLING_TEST,<<<'PHP'
<?php
use function Dataphyre\Test\suite;

suite('Incomplete editor buffer')->
PHP);
		$this->workspace->file('dataphyre/'.self::EXHAUSTED_TEST,'<?php class');
		$this->workspace->file('dataphyre/runtime/modules/ledger/unit_tests/dataphyre.ledger.contract_version.test.php',<<<'PHP'
<?php
declare(strict_types=1);
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Ledger store compatibility contract')
	->contract('ledger.store.behavior',3)
	->tag('ledger','compatibility');

test('keeps version three readable',static function(): void {});
PHP);
		$this->workspace->file('dataphyre/runtime/modules/ledger/unit_tests/dataphyre.ledger.legacy.json',(string)json_encode([
			['name'=>'legacy ledger smoke','function'=>'fixture_ledger_smoke','expected'=>['ok'=>true]],
		],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
		$this->workspace->file('dataphyre/runtime/modules/ledger/Framework/Noise.php','<?php final class UnrelatedLedgerHelper {}');
		$this->workspace->file('dataphyre/runtime/modules/omega/unit_tests/dataphyre.omega.invalid.json','{malformed');
	}
}

/** Readable probes over the normalized catalog used by contract tests. */
final class McpContractProbe {
	public function __construct(private ContractCatalog $catalog) {}

	/** @return array<string,mixed> */
	public function record(string $id): array {
		$descriptor=$this->catalog->describe($id);
		if(($descriptor['status']??null)!=='found'){
			throw new \RuntimeException('Expected an unambiguous fixture contract: '.$id);
		}
		return $descriptor['contract'];
	}

	/** @return list<string> */
	public static function ids(array $catalog): array {
		return array_values(array_map(static fn(array $record): string=>(string)($record['id']??''),$catalog['records']??[]));
	}

	/** @return list<string> */
	public static function implementationNames(array $contract): array {
		return array_values(array_map(static fn(array $implementation): string=>(string)($implementation['fqcn']??''),$contract['implemented_by']??[]));
	}
}

/** Intent-named access to parser fallbacks that have no public output shape. */
final class McpContractParserProbe {
	private NonPublicAccess $access;
	public function __construct(TestContext $context,SourceContractIndex $source) {$this->access=$context->nonPublic($source);}
	public function conventionalSerializedConfidence(): string {return (string)$this->access->invoke('serializedConfidence','ledger_event',null);}
	/** @return array{value:mixed,resolved:bool,expression:string} */
	public function unresolvedLiteral(): array {return $this->access->invoke('literalValue',[[T_VARIABLE,'$dynamic',1]]);}
	public function nearbyValueIsMissing(): bool {return $this->access->invoke('nearbyArrayValue',token_get_all('<?php [\'other\'=>1];'),0,'missing',20)===null;}
	public function relativeNameUsesNamespace(): string {return (string)$this->access->invoke('resolveName','LedgerEvent','Fixture\\Ledger',[]);}
}
