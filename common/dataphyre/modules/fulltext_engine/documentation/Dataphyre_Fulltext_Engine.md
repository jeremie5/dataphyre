### Fulltext Engine Module

The **Fulltext Engine** module in Dataphyre enables robust text-based search capabilities across different types of indexes. It supports custom indexing, tokenization, similarity scoring, and distance calculation using various algorithms. This module allows Dataphyre to perform efficient and scalable search operations with support for multiple backends, including SQLite, Elasticsearch, Vespa, and JSON files.

#### Key Functionalities

1. **Text Search and Ranking**: Conducts searches based on text content, with options for sorting by relevance and scoring.
2. **Tokenization and Stemming**: Processes input text through tokenization, stop-word removal, and stemming to standardize search queries and indexed data.
3. **Similarity Algorithms**: Calculates similarity scores using various algorithms like Jaccard, Jaro-Winkler, and Damerau-Levenshtein for flexible and accurate search ranking.
4. **Customizable Indexing**: Provides functionality to create, update, and manage indexes in different formats (SQLite, JSON, SQL, Elasticsearch, Vespa).
5. **Boolean Search Support**: Allows for advanced query expressions using Boolean operators (AND, OR, NOT) in search queries.

---

#### Core Methods

1. **`search(string $index_name, array $data, string $language='en', int $max_results=50, bool $boolean_mode=true, float $threshold=0.3, string $forced_algorithms='') : array`**

   - **Purpose**: Executes a search on a specified index using the provided data and parameters.
   - **Parameters**:
     - `$index_name`: Name of the index to search in.
     - `$data`: Array of search terms and values.
     - `$language`: Language code for processing (default is English).
     - `$max_results`: Maximum number of results to return.
     - `$boolean_mode`: Enables Boolean search mode if `true`.
     - `$threshold`: Minimum similarity score required to include a result.
     - `$forced_algorithms`: Forces the use of specific similarity algorithms.
   - **Returns**: Array of search results, sorted by relevance.

2. **`tokenize(string $text, string $language='en') : array`**

   - **Purpose**: Tokenizes a given text, applying stop-word removal, stemming, and n-gram transformations as needed.
   - **Parameters**:
     - `$text`: Text to tokenize.
     - `$language`: Language code for processing.
   - **Returns**: Array of tokenized words.

3. **`get_score(string $index_value, string $search_value, string $search_value_raw, string $language='en', bool $boolean_mode=false, string $forced_algorithms='') : float`**

   - **Purpose**: Calculates a similarity score between an indexed value and a search query.
   - **Parameters**:
     - `$index_value`: The indexed text.
     - `$search_value`: The processed search query.
     - `$search_value_raw`: The original search query before processing.
     - `$language`: Language code for processing.
     - `$boolean_mode`: Uses Boolean logic if set to `true`.
     - `$forced_algorithms`: Specific algorithm to use for scoring.
   - **Returns**: A float representing the similarity score.

4. **`add_to_index(string $index_name, array $values, string $language='en') : bool`**

   - **Purpose**: Adds a new entry to the specified index.
   - **Parameters**:
     - `$index_name`: Name of the index to update.
     - `$values`: Array of values to add, with the primary key.
     - `$language`: Language for tokenization and processing.
   - **Returns**: `true` if successful, `false` otherwise.

5. **`update_in_index(string $index_name, array $values, string $language='en') : bool`**

   - **Purpose**: Updates an existing entry in the specified index.
   - **Parameters**:
     - `$index_name`: Name of the index to update.
     - `$values`: Array of values with primary key included.
     - `$language`: Language for tokenization and processing.
   - **Returns**: `true` if update is successful, `false` otherwise.

6. **`remove_from_index(string $index_name, string $primary_key_value) : bool`**

   - **Purpose**: Removes an entry from the specified index.
   - **Parameters**:
     - `$index_name`: Name of the index.
     - `$primary_key_value`: Primary key of the entry to remove.
   - **Returns**: `true` if removal is successful, `false` otherwise.

7. **`delete_index(string $index_name) : bool`**

   - **Purpose**: Deletes an entire index.
   - **Parameters**:
     - `$index_name`: Name of the index to delete.
   - **Returns**: `true` if deletion is successful, `false` otherwise.

8. **`create_index(string $index_name, string $primary_key_column_name, string $type="json") : bool`**

   - **Purpose**: Creates a new index with the specified parameters.
   - **Parameters**:
     - `$index_name`: Name of the index.
     - `$primary_key_column_name`: Primary key column for the index.
     - `$type`: Type of index storage, such as `json`, `sqlite`, `sql`, `elastic`.
   - **Returns**: `true` if index creation is successful, `false` otherwise.

9. **`tokenize_expression(string $search_value) : array`**

   - **Purpose**: Tokenizes a Boolean search expression, splitting it into terms and operators.
   - **Parameters**:
     - `$search_value`: Boolean expression to tokenize.
   - **Returns**: Array of tokens representing terms and operators.

10. **`evaluate_expression(string $index_value, array $expression) : bool`**

    - **Purpose**: Evaluates a Boolean search expression on the indexed value.
    - **Parameters**:
      - `$index_value`: Text to evaluate the expression against.
      - `$expression`: Parsed expression in postfix notation.
    - **Returns**: `true` or `false` based on whether the expression is satisfied.

11. **`apply_stemming(string $query, string $language) : string`**

    - **Purpose**: Applies stemming to the input query based on the specified language.
    - **Parameters**:
      - `$query`: Text query to stem.
      - `$language`: Language code for stemming rules.
    - **Returns**: The stemmed query as a string.

12. **`remove_stopwords(string $query, string $language) : string`**

    - **Purpose**: Removes common stop words from the query.
    - **Parameters**:
      - `$query`: Text query to filter.
      - `$language`: Language code for stop-word list.
    - **Returns**: Filtered query without stop words.

13. **`get_stopwords(string $language) : array`**

    - **Purpose**: Retrieves the list of stop words for the specified language.
    - **Parameters**:
      - `$language`: Language code.
    - **Returns**: Array of stop words.

---

#### Example Usage

1. **Search for a Term**:
   ```php
   $results = fulltext_engine::search("products_index", ["name" => "laptop"], "en", 10, true, 0.5);
   ```

2. **Add an Entry to an Index**:
   ```php
   fulltext_engine::add_to_index("products_index", ["id" => 123, "name" => "Ultra Laptop"], "en");
   ```

3. **Update an Index Entry**:
   ```php
   fulltext_engine::update_in_index("products_index", ["id" => 123, "name" => "Ultra Laptop Pro"], "en");
   ```

4. **Delete an Entry from an Index**:
   ```php
   fulltext_engine::remove_from_index("products_index", "123");
   ```

---

### Workflow and Usage

1. **Text Processing**: Queries are processed through tokenization, stop-word removal, and stemming to standardize both indexed data and search input, allowing more accurate matching.
2. **Similarity Calculation**: Scores between search terms and indexed content are calculated using similarity algorithms based on the structure of the query.
3. **Boolean Search Support**: Users can build complex search queries using Boolean operators for more refined results.
4. **Index Management**: Supports multiple backend types, allowing for flexible storage and retrieval, suitable for large datasets and high-performance environments.

The **Fulltext Engine** module is an integral part of Dataphyre's search capabilities, allowing developers to manage and perform complex text searches across various data sources efficiently.