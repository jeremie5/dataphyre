<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Bootstrap {

	public static function current(?string $project_root=null, ?string $application_name=null): ?BootstrapPlan {
		$application=Application::current($project_root, $application_name);
		if(!$application instanceof Application){
			return null;
		}
		return BootstrapPlan::fromApplication(
			$application,
			$project_root ?? Runtime::projectRoot()
		);
	}

	public static function resolve(string $application_name, ?string $project_root=null): ?BootstrapPlan {
		$application=Application::discover($application_name, $project_root);
		if(!$application instanceof Application){
			return null;
		}
		return BootstrapPlan::fromApplication($application, $project_root);
	}

	public static function for(Application|string $application, ?string $project_root=null): ?BootstrapPlan {
		if($application instanceof Application){
			return BootstrapPlan::fromApplication($application, $project_root ?? Runtime::projectRoot());
		}
		return static::resolve($application, $project_root);
	}

	public static function catalog(?string $project_root=null): BootstrapCatalog {
		$project_root=$project_root ?? Runtime::projectRoot();
		$plans=[];
		foreach(Application::catalog($project_root) as $application){
			$plans[$application->id]=BootstrapPlan::fromApplication($application, $project_root);
		}
		return new BootstrapCatalog($project_root, $plans);
	}

	public static function boot(Application|string $application, ?string $project_root=null): BootstrapPlan {
		$plan=static::for($application, $project_root);
		if(!$plan instanceof BootstrapPlan){
			$application_id=$application instanceof Application ? $application->id : trim($application);
			throw new \RuntimeException("Application {$application_id} could not be resolved for boot.");
		}
		$plan->boot();
		return $plan;
	}
}
