<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Authorizes relation nodes hop-by-hop and injects related-resource tenant predicates. */
final class PanelQueryScopeGuard {
	/** @param null|callable(string):?Resource $resourceResolver */
	public static function apply(?PanelQueryExpression $expression, Resource $resource, ?PanelRequest $request=null, ?callable $resourceResolver=null, string|int|null $tenant=null): PanelQueryScope {
		if($expression===null){ return new PanelQueryScope(null, new PanelQueryScopeManifest($resource->name())); }
		$user=$request?->user();
		if(!$resource->can('view_any', null, $user)){ throw new PanelQueryScopeException('resource_denied', "Panel resource '{$resource->name()}' denied nested query access.", ['resource'=>$resource->name()]); }
		$checks=[];
		$resolve=$resourceResolver===null
			? static fn(string $name): ?Resource=>Panel::get($name)
			: \Closure::fromCallable($resourceResolver);
		$tenant=$tenant ?? $request?->tenantKey();
		$scoped=self::walk($expression, $resource, $user, $tenant, $resolve, '', $checks);
		return new PanelQueryScope($scoped, new PanelQueryScopeManifest($resource->name(), $checks));
	}

	/** @param callable(string):?Resource $resolve @param list<array<string,mixed>> $checks */
	private static function walk(PanelQueryExpression $expression, Resource $resource, mixed $user, string|int|null $tenant, callable $resolve, string $prefix, array &$checks): PanelQueryExpression {
		if($expression instanceof PanelQueryGroup){
			$children=[];
			foreach($expression->children() as $child){ $children[]=self::walk($child, $resource, $user, $tenant, $resolve, $prefix, $checks); }
			return PanelQueryGroup::make($expression->boolean(), $children);
		}
		if(!$expression instanceof PanelQueryRelation){ return $expression; }
		$relation=$resource->relationManager($expression->relation());
		$path=ltrim($prefix.'.'.$expression->relation(), '.');
		if(!$relation instanceof RelationManager){ throw new PanelQueryScopeException('relation_unknown', "Panel relation '{$path}' is not declared.", ['resource'=>$resource->name(), 'path'=>$path]); }
		if(!$relation->can('view', null, $user, $resource)){ throw new PanelQueryScopeException('relation_denied', "Panel relation '{$path}' denied query access.", ['resource'=>$resource->name(), 'path'=>$path]); }
		$relatedName=$relation->relatedResourceName();
		$related=$relatedName===null ? null : $resolve($relatedName);
		if($relatedName!==null && !$related instanceof Resource){ throw new PanelQueryScopeException('related_resource_unknown', "Panel relation '{$path}' references an unavailable resource.", ['resource'=>$resource->name(), 'path'=>$path, 'related_resource'=>$relatedName]); }
		if($related instanceof Resource && !$related->can('view_any', null, $user)){ throw new PanelQueryScopeException('related_resource_denied', "Panel related resource '{$related->name()}' denied query access.", ['resource'=>$resource->name(), 'path'=>$path, 'related_resource'=>$related->name()]); }
		$nested=$expression->expression();
		$relationScope=$expression->scope();
		$tenantApplied=false; $tenantField=null;
		if($related instanceof Resource){
			$definition=$related->tenantScopeDefinition();
			$tenantField=$definition['scoped'] ? trim((string)($definition['field'] ?? 'tenant_id')) : null;
			$tenantRequired=$definition['required'];
			if($tenantField!==null && $tenantField!==''){
				if(($tenant===null || $tenant==='') && $tenantRequired){ throw new PanelQueryScopeException('nested_tenant_missing', "Panel relation '{$path}' requires tenant context.", ['resource'=>$resource->name(), 'path'=>$path, 'related_resource'=>$related->name()]); }
				if($tenant!==null && $tenant!==''){
					$tenantExpression=PanelQueryComparison::make($tenantField, 'eq', $tenant);
					$relationScope=$relationScope===null ? $tenantExpression : PanelQueryGroup::make('and', [$tenantExpression, $relationScope]);
					$tenantApplied=true;
				}
			}
			$nested=self::walk($nested, $related, $user, $tenant, $resolve, $path, $checks);
			if($relationScope!==null){ $relationScope=self::walk($relationScope, $related, $user, $tenant, $resolve, $path, $checks); }
		}
		elseif(self::containsRelation($nested)){
			throw new PanelQueryScopeException('nested_resource_unresolved', "Panel relation '{$path}' cannot authorize a deeper relation without relatedResource().", ['resource'=>$resource->name(), 'path'=>$path]);
		}
		$checks[]=[
			'path'=>$path, 'resource'=>$resource->name(), 'relation'=>$expression->relation(),
			'related_resource'=>$related?->name(), 'permission'=>'view', 'allowed'=>true,
			'tenant_field'=>$tenantField, 'tenant_applied'=>$tenantApplied,
		];
		return PanelQueryRelation::make($expression->relation(), $nested, $expression->quantifier(), $relationScope);
	}

	private static function containsRelation(PanelQueryExpression $expression): bool {
		foreach(PanelQueryExpressionCodec::nodes($expression) as $node){ if($node instanceof PanelQueryRelation){ return true; } }
		return false;
	}
}
