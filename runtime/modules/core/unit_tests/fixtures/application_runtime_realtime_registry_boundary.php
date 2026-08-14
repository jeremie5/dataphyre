<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(!is_string($kernel)) exit(64);
require_once $kernel.'/realtime.php';
$authorize=static fn(array $handshake): false=>false;
$events=static fn(array $authorization,?string $cursor): array=>['cursor'=>$cursor,'events'=>[]];
$wrongPool=false;$invalid=false;$duplicate=false;$limit=false;$sealed=false;
putenv('DATAPHYRE_RUNTIME_POOL=web');
try{\dataphyre\realtime::runtimeRoutes();}
catch(LogicException){$wrongPool=true;}
putenv('DATAPHYRE_RUNTIME_POOL=realtime-preflight');
try{\dataphyre\realtime::register('/dataphyre/runtime/realtime/probe',$authorize,$events);}
catch(InvalidArgumentException){$invalid=true;}
\dataphyre\realtime::register('/route-000',$authorize,$events);
try{\dataphyre\realtime::register('/route-000',$authorize,$events);}
catch(InvalidArgumentException){$duplicate=true;}
for($index=1;$index<128;$index++){
	\dataphyre\realtime::register('/route-'.str_pad((string)$index,3,'0',STR_PAD_LEFT),$authorize,$events);
}
try{\dataphyre\realtime::register('/route-over-limit',$authorize,$events);}
catch(LogicException){$limit=true;}
$evidence=\dataphyre\realtime::runtimeEvidence();
try{\dataphyre\realtime::register('/route-after-seal',$authorize,$events);}
catch(LogicException){$sealed=true;}
echo json_encode(compact('wrongPool','invalid','duplicate','limit','sealed','evidence'),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
