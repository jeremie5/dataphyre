<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	final class Panel {
		public static function traceSummary(): array {
			return ['count'=>1,'events'=>['probe.rendered'=>1],'latest'=>[['event'=>'probe.rendered']]];
		}

		public static function trace(): array {
			return [['time'=>1700000000.125,'event'=>'probe.rendered','context'=>['resource'=>'probe'],'memory'=>1024]];
		}

		public static function describe(): array {
			return [
				'resources'=>[['name'=>'probe','label'=>'Probe resource']],
				'pages'=>[['name'=>'probe-page','label'=>'Probe page']],
				'global_searchable_resources'=>['probe'],
				'navigation'=>[['resource'=>'probe']],
			];
		}
	}
}

namespace Dataphyre\Reactor {
	final class Reactor {
		public static function manifest(): array {
			return [
				'version'=>'probe',
				'components'=>[['name'=>'probe','capabilities'=>['state']]],
				'trace'=>['count'=>1,'events'=>['probe.rendered'=>1],'latest'=>[['event'=>'probe.rendered']]],
			];
		}
	}

	final class ReactorTrace {
		public static function events(): array {
			return [['time'=>1700000000.125,'event'=>'probe.rendered','context'=>['component'=>'probe'],'memory'=>1024]];
		}
	}
}
