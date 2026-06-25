<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * MCP tool argument validation and descriptor lookup helpers.
 */
trait dataphyre_mcp_registry_validation_surfaces {

	/**
	 * Validates MCP tool arguments against the advertised input schemas.
	 */
	private function validate_tool_arguments(string $name, array $args): array {
		$descriptor=$this->tool_descriptor_map()[$name] ?? null;
		if($descriptor===null){
			throw new RuntimeException('Unknown MCP tool: '.$name, -32602);
		}
		$schema=is_array($descriptor['inputSchema'] ?? null) ? $descriptor['inputSchema'] : [];
		$properties=is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		if(($schema['additionalProperties'] ?? true)===false){
			foreach(array_keys($args) as $key){
				$key=(string)$key;
				if(array_key_exists($key, $properties)){
					continue;
				}
				$message='Invalid arguments for '.$name.': unknown argument '.$key.'.';
				$suggestion=$this->closest_tool_argument($key, array_keys($properties));
				if($suggestion!==null){
					$message.=' Did you mean '.$suggestion.'?';
				}
				throw new RuntimeException($message, -32602);
			}
		}
		foreach((array)($schema['required'] ?? []) as $field){
			$field=(string)$field;
			if(!array_key_exists($field, $args)){
				throw new RuntimeException('Invalid arguments for '.$name.': missing required argument '.$field.'.', -32602);
			}
		}
		return $args;
	}

	/**
	 * Returns tool descriptors keyed by name for low-overhead argument validation.
	 */
	private function tool_descriptor_map(): array {
		static $map=null;
		if($map!==null){
			return $map;
		}
		$map=[];
		foreach($this->list_tools()['tools'] ?? [] as $tool){
			$name=(string)($tool['name'] ?? '');
			if($name!==''){
				$map[$name]=$tool;
			}
		}
		return $map;
	}

	/**
	 * Suggests the nearest known argument name for small typos.
	 */
	private function closest_tool_argument(string $unknown, array $candidates): ?string {
		$best=null;
		$best_distance=PHP_INT_MAX;
		foreach($candidates as $candidate){
			$candidate=(string)$candidate;
			$distance=levenshtein($unknown, $candidate);
			if($distance<$best_distance){
				$best=$candidate;
				$best_distance=$distance;
			}
		}
		return $best_distance<=4 ? $best : null;
	}
}
