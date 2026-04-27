### Fulltext Engine Module

The **Fulltext Engine** module provides application-level indexing and search across multiple storage backends. It normalizes text through stopword removal, stemming, keyword extraction, and optional n-gram expansion, then evaluates matches with boolean logic or fuzzy similarity scoring.

It has two intentional surfaces:

- **Kernel surface** via `\dataphyre\fulltext_engine`
  - Low-level index creation, writes, raw searches, and text normalization helpers.
- **Framework surface** via `\Dataphyre\FulltextEngine\*`
  - Optional facade, manager, index handles, query builders, and typed search results.

The kernel API stays small:

- create and delete index definitions
- add, update, remove, and search index entries
- tokenize and normalize text consistently
- route storage to `json`, `sqlite`, `sql`, `elastic`, or `vespa`

---

### Module Loading

Kernel loading follows the normal Dataphyre module boot path.

Framework loading is explicit:

```php
\dataphyre\core::load_framework_module('fulltext_engine');
```

### Framework Surface

Main framework entry points:

- `\Dataphyre\FulltextEngine\Search`
- `\Dataphyre\FulltextEngine\SearchManager`
- `\Dataphyre\FulltextEngine\Index`
- `\Dataphyre\FulltextEngine\Query`
- `\Dataphyre\FulltextEngine\IndexDefinition`
- `\Dataphyre\FulltextEngine\IndexSyncReport`
- `\Dataphyre\FulltextEngine\SearchResults`
- `\Dataphyre\FulltextEngine\SearchHit`
- `\Dataphyre\FulltextEngine\HydratedSearchResults`
- `\Dataphyre\FulltextEngine\HydratedSearchHit`
- `\Dataphyre\FulltextEngine\Contracts\DocumentResolver`

Example:

```php
\dataphyre\core::load_framework_module('fulltext_engine');

use Dataphyre\FulltextEngine\Search;

$results=Search::query('products_index')
	->where('name', 'ultra laptop')
	->boolean(false)
	->limit(25)
	->threshold(0.45)
	->get();

$first_id=$results->first()?->id();
```

Framework `Query` objects also expose `executionState()`, `fingerprintPayload()`, and `fingerprint()`. That gives templating bindings and other framework integrations one explicit search-query identity to reuse instead of reconstructing it from loose state.

Resolver-backed hydration:

```php
use Dataphyre\FulltextEngine\Search;

Search::extendResolver('products_index', static function(array $ids){
	$documents=[];
	foreach($ids as $id){
		$documents[$id]=[
			'productid'=>$id,
			'name'=>'Example '.$id,
		];
	}
	return $documents;
});

$results=Search::query('products_index')
	->where('name', 'ultra laptop')
	->get()
	->hydrate();

$document=$results->first()?->document();
```

Built-in table-backed hydration:

```php
use Dataphyre\FulltextEngine\Search;

Search::useTableResolver(
	'products_index',
	'products',
	'productid',
	['productid', 'name', 'price']
);
```

Built-in repository-backed hydration:

```php
use Dataphyre\FulltextEngine\Search;

Search::useRepositoryResolver(
	'machines_index',
	\App\Repository\MachineRepository::class,
	'machineid'
);
```

Index-oriented usage:

```php
use Dataphyre\FulltextEngine\Search;

Search::createIndex('products_index', 'productid', 'sql');

Search::index('products_index')->add([
	'productid'=>123,
	'name'=>'Ultra Laptop',
	'description'=>'Slim aluminum chassis with OLED display',
]);
```

Typed definition and sync usage:

```php
use Dataphyre\FulltextEngine\IndexDefinition;
use Dataphyre\FulltextEngine\Search;

$report=Search::sync([
	new IndexDefinition('products_index', 'sql', 'productid'),
	new IndexDefinition('blog_posts_index', 'json', 'postid'),
], false);

if($report->hasMismatches()){
	// existing indexes differ from the desired definitions
}
```

The framework layer reads these optional defaults from `DP_FULLTEXT_ENGINE_CFG['framework']`:

```php
return [
	'framework'=>[
		'default_language'=>'en',
		'default_limit'=>50,
		'default_boolean_mode'=>true,
		'default_threshold'=>0.3,
		'default_algorithms'=>'',
		'default_index_type'=>'json',
		'indexes'=>[
			'products_index'=>[
				'primary_key'=>'productid',
				'type'=>'sql',
				'language'=>'en',
			],
		],
		'resolvers'=>[
			'products_index'=>[
				'driver'=>'table',
				'table'=>'products',
				'primary_key'=>'productid',
				'columns'=>['productid', 'name', 'price'],
			],
			'machines_index'=>[
				'driver'=>'repository',
				'repository'=>\App\Repository\MachineRepository::class,
				'primary_key'=>'machineid',
			],
		],
	],
];
```

Resolver drivers supported by the framework layer:

- callback / closure
- `table`
- `repository`

Then:

```php
$report=Search::syncConfigured();
```

---

### Storage Backends

The module supports these index types:

- `json`
  - Stores sharded JSON files under `ROOTPATH['dataphyre']."fulltext_indexes/json/<index_name>"`
- `sqlite`
  - Stores sharded SQLite databases under `ROOTPATH['dataphyre']."fulltext_indexes/sqlite/<index_name>"`
- `sql`
  - Uses the logical table name `dataphyre_fulltext_engine.index_<index_name>`
  - The module manages table creation and deletion
  - Rows store the configured primary key plus a serialized `index_value` payload
- `elastic`
  - Uses Elasticsearch through the bundled external engine adapter
- `vespa`
  - Uses Vespa through the bundled external engine adapter
  - Indexed values are flattened into one searchable `content` field plus the configured primary key

Index definitions are persisted in:

```php
ROOTPATH['dataphyre']."config/fulltext_engine/indexes.json"
```

---

### Configuration

The module recognizes these core configuration values in `DP_FULLTEXT_ENGINE_CFG`:

```php
return [
	'fs_index_entry_count'=>1000,
	'fs_index_entry_count_for_sql'=>100000,
	'external_engines'=>[
		'elastic'=>[
			'url'=>'http://127.0.0.1:9200',
		],
		'vespa'=>[
			'query_url'=>'http://127.0.0.1:8080',
			'config_url'=>'http://127.0.0.1:19071',
			'prepare_max_attempts'=>10,
			'prepare_retry_delay_seconds'=>3,
			'http_timeout_seconds'=>30,
		],
	],
];
```

The external-engine keys are optional. If omitted, the adapters fall back to their internal defaults.

---

### Core Methods

#### `search(string $index_name, array $data, string $language='en', int $max_results=50, bool $boolean_mode=true, float $threshold=0.3, string $forced_algorithms='') : array`

Runs a search against an index and returns:

- `results`
- `count`
- `certainty`
- `time`

`results` are globally sorted by relevance and then truncated to `max_results`.

Example:

```php
$results=\dataphyre\fulltext_engine::search(
	'products_index',
	['name'=>'ultra laptop'],
	'en',
	25,
	false,
	0.45
);
```

#### `create_index(string $index_name, string $primary_key_column_name, string $type='json', $language='en') : bool`

Registers an index definition and performs backend-specific initialization when applicable.

Example:

```php
\dataphyre\fulltext_engine::create_index('products_index', 'productid', 'json');
\dataphyre\fulltext_engine::create_index('listing_titles_en', 'listingid', 'elastic', 'en');
```

#### `delete_index(string $index_name) : bool`

Deletes an index definition and removes backend storage when the backend supports it.

#### `add_to_index(string $index_name, array $values, string $language='en') : bool`

Adds an entry to an index. The primary key field must be included in `$values`.

Example:

```php
\dataphyre\fulltext_engine::add_to_index(
	'products_index',
	[
		'productid'=>123,
		'name'=>'Ultra Laptop',
		'description'=>'Slim aluminum chassis with OLED display',
	],
	'en'
);
```

#### `update_in_index(string $index_name, array $values, string $language='en') : bool`

Updates an existing index entry. The primary key field must be included in `$values`.

#### `remove_from_index(string $index_name, string $primary_key_value) : bool`

Removes one entry by primary key.

#### `find_in_index(string $index_name, array $search_data, string $language='en', bool $boolean_mode=false, int $max_results=50, float $threshold=0.85, string $forced_algorithms='') : bool|array`

Performs the raw backend search and returns scored primary-key results in the form:

```php
[
	['123'=>0.91],
	['456'=>0.74],
]
```

This is the low-level search primitive used by `search()`.

- Each primary key appears at most once in the final result set
- Multiple field matches are collapsed into one scored hit per primary key before `search()` performs final relevance sorting

---

### Text Processing

#### `tokenize(string $text, string $language='en') : array`

Normalizes text by applying:

- stopword removal
- stemming
- n-gram expansion for longer strings
- keyword extraction

#### `remove_stopwords(string $query, string $language) : string`

Filters stopwords using `stopwords/<language_prefix>_stopwords.php`, with English fallback.

#### `apply_stemming(string $query, string $language) : string`

Applies the language stemmer from `stemmers/<language_prefix>_stemmer.php`, with English fallback.

#### `get_stopwords(string $language) : array`

Loads the stopword list for the requested language prefix.

---

### Boolean Search

Boolean mode supports tokenized expressions with:

- `AND`
- `OR`
- `NOT`
- parentheses
- unary `+term`
- unary `-term`

Relevant helpers:

- `tokenize_expression(string $search_value) : array`
- `parse_expression(array $tokens) : array`
- `evaluate_expression(string $index_value, array $expression) : bool`

Example:

```php
$results=\dataphyre\fulltext_engine::search(
	'products_index',
	['*'=>'(laptop OR notebook) AND -refurbished'],
	'en',
	20,
	true
);
```

---

### Similarity Scoring

When boolean mode is disabled, the module selects a scoring strategy based on the token and length profile of the search:

- Jaccard
- Jaro-Winkler
- Levenshtein
- Damerau-Levenshtein
- BM25
- similar_text fallback

For longer-text candidate sets, the engine can expand the candidate window, rerank the matched candidates with BM25, and then apply the final top-`N` cutoff.

You can force a scoring family with `$forced_algorithms`, for example:

- `jaccard_damerau_lavenshtein1`
- `jaccard_damerau_lavenshtein2`
- `jaccard_winkler`
- `lavenshtein`
- `damerau_lavenshtein`
- `bm25`

---

### Backend Notes

#### JSON

- Good for simple embedded indexes
- Writes are sharded by entry count

#### SQLite

- Good for local durable indexing without a separate service
- Requires the `sqlite3` PHP extension

#### SQL

- Uses Dataphyre SQL primitives for reads and writes
- Uses a standard table shape:

```sql
<primary_key_column> PRIMARY KEY,
index_value TEXT
```

- Search uses SQL `LIKE` prefiltering on `index_value` before scoring results in PHP

#### Elastic

- Endpoint is configurable
- Index mappings use a dynamic template for string fields instead of hardcoded document fields

#### Vespa

- Query and config endpoints are configurable
- The adapter stores indexed data in a generic `content` field so applications do not need per-index field schemas
- Deployment packaging uses PHP `ZipArchive`, not a shell `zip` command
- Deployment retries and HTTP timeouts are configurable through the Vespa config keys above

---

### Example Workflow

```php
\dataphyre\fulltext_engine::create_index('products_index', 'productid', 'json');

\dataphyre\fulltext_engine::add_to_index(
	'products_index',
	[
		'productid'=>101,
		'name'=>'Studio Headphones',
		'description'=>'Closed-back headphones for editing and tracking',
	],
	'en'
);

\dataphyre\fulltext_engine::update_in_index(
	'products_index',
	[
		'productid'=>101,
		'name'=>'Studio Headphones Pro',
		'description'=>'Closed-back reference headphones',
	],
	'en'
);

$results=\dataphyre\fulltext_engine::search(
	'products_index',
	['description'=>'reference headphones'],
	'en',
	10,
	false,
	0.4
);

\dataphyre\fulltext_engine::remove_from_index('products_index', '101');
```
