<?php
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/tracelog_runtime_test_helpers.php';

suite('Bounded trace handoff retention')->layer('integration')->risk('high')
    ->watches('module:tracelog')->isolation('case')->tag('tracelog', 'retention');

function persistRetentionTrace(string $root, string $id, string $trace): array {
    \dataphyre\tracelog::reset(['roots'=>['dataphyre'=>$root], 'session_id'=>$id,
        'session_name'=>'DP', 'cookies'=>[], 'session'=>[], 'retroactive'=>[]]);
    \dataphyre\tracelog::$enable=true;
    \dataphyre\tracelog::$tracelog=$trace;
    \dataphyre\tracelog::persist_to_session();
    return \dataphyre\tracelog::runtimeState()['session'];
}

test('changing sessions stay bounded and newest signed handoff remains readable', static function(Context $t): void {
    $root=$t->workspace('retention-count')->root();
    for($i=0;$i<140;$i++) $session=persistRetentionTrace($root, 'session-'.$i, 'trace-'.$i);
    $t->isTrue(count(glob($root.'/cache/tracelog_handoff/*.dat'))<=128);
    $t->same('trace-139', \dataphyre\tracelog::last_handoff_trace($session['flightdeck_last_tracelog_handoff']));
});

test('byte budget and expiry protect disk while unrelated files and symlinks survive', static function(Context $t): void {
    $root=$t->workspace('retention-bytes')->root();
    persistRetentionTrace($root, 'expired', 'old');
    $dir=$root.'/cache/tracelog_handoff';
    $expired=$dir.'/'.sha1('expired').'.dat';
    touch($expired, time()-7200);
    file_put_contents($dir.'/keep.txt', 'keep');
    file_put_contents($root.'/outside', 'outside');
    symlink($root.'/outside', $dir.'/'.sha1('link').'.dat');
    for($i=0;$i<36;$i++) persistRetentionTrace($root, 'large-'.$i, str_repeat('x', 2097153));
    $bytes=0;
    foreach(glob($dir.'/*.dat') as $file){
        if(is_link($file)) continue;
        $size=filesize($file); $bytes+=$size;
        $t->isTrue($size<=2097152);
    }
    $t->isTrue($bytes<=67108864);
    $t->isFalse(is_file($expired));
    $t->same('keep', file_get_contents($dir.'/keep.txt'));
    $t->same('outside', file_get_contents($root.'/outside'));
    $t->isTrue(is_link($dir.'/'.sha1('link').'.dat'));
});

test('busy retention lock skips disk capture but preserves session capture', static function(Context $t): void {
    $root=$t->workspace('retention-lock')->root();
    persistRetentionTrace($root, 'first', 'first');
    $dir=$root.'/cache/tracelog_handoff';
    $lock=fopen($dir.'/.retention.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $session=persistRetentionTrace($root, 'blocked', 'session survives');
        $t->same('session survives', $session['tracelog']);
        $t->isFalse(is_file($dir.'/'.sha1('blocked').'.dat'));
    } finally {flock($lock, LOCK_UN);fclose($lock);}
});
