<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

namespace dataphyre\sql;

class migration {

    private static array $migration_roots=[
        'common_dataphyre'=>ROOTPATH['common_dataphyre'].'sql_migration/plans/',
        'dataphyre'=>ROOTPATH['dataphyre'].'sql_migration/plans/'
    ];
    private static string $version_file=ROOTPATH['common_dataphyre'].'sql_migration/table_versions.json';
    private static string $lock_file=ROOTPATH['common_dataphyre'].'sql_migration/migrating';
	private static string $snapshot_dir = ROOTPATH['common_dataphyre'] . 'sql_migration/snapshots/';
	
    public static function run_all(bool $interactive=false): void {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        if(file_exists(self::$lock_file)){
            core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D='Schema migration in progress', 'maintenance');
        }
        file_put_contents(self::$lock_file, '');
        $versions=file_exists(self::$version_file) ? json_decode(file_get_contents(self::$version_file), true) : [];
        global $configurations;
        $dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$configurations['dataphyre']['sql']['default_cluster']]['dbms'];
        foreach(self::$migration_roots as $scope=>$dir){
            $plans=glob($dir.'*.yaml');
            sort($plans);
            foreach($plans as $plan_path){
                $plan=self::parse_yaml($plan_path);
                $table=$plan['table'] ?? null;
                $migrations=$plan['migrations'] ?? [];
                if(!$table || empty($migrations)) continue;
                $table_key=$scope.':'.$table;
                $current_version=$versions[$table_key]['current_version'] ?? 0;
                foreach($migrations as $migration){
                    $version=$migration['version'];
                    if($version<=$current_version) continue;
                    $sql=is_array($migration['up'] ?? null) ? ($migration['up'][$dbms] ?? null) : trim($migration['up'] ?? '');
                    if(!$sql) continue;
                    try {
                        sql::query($sql);
                        $versions[$table_key]['current_version']=$version;
                        $versions[$table_key]['log'][]=[
                            'version'=>$version,
                            'timestamp'=>date('c'),
                            'desc'=>$migration['description'] ?? '',
                        ];
                        if($interactive){
                            echo "[OK] {$scope}/{$table} migrated to version {$version}\n";
                        }
                    } catch(\Throwable $e){
                        core::log(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'error', [
                            'msg'=>'Migration failed',
                            'table'=>$table,
                            'version'=>$version,
                            'scope'=>$scope,
                            'error'=>$e->getMessage()
                        ]);
                        unlink(self::$lock_file);
                        core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $D='Migration failed: '.$e->getMessage(), 'error');
                    }
                }
            }
        }
        file_put_contents(self::$version_file, json_encode($versions, JSON_PRETTY_PRINT));
        unlink(self::$lock_file);
    }

    private static function parse_yaml(string $path): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        if(!function_exists('yaml_parse_file')){
            throw new \RuntimeException("YAML PHP extension not installed");
        }
        return yaml_parse_file($path) ?: [];
    }

    public static function get_current_version(string $table, string $scope='common_dataphyre'): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        $versions=file_exists(self::$version_file) ? json_decode(file_get_contents(self::$version_file), true) : [];
        $table_key=$scope.':'.$table;
        return $versions[$table_key]['current_version'] ?? 0;
    }

    public static function status(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        return file_exists(self::$version_file) ? json_decode(file_get_contents(self::$version_file), true) : [];
    }

    public static function is_migrating(): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        return file_exists(self::$lock_file);
    }
	
    public static function generate_migration_diff(string $table, string $scope='dataphyre'): ?string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        global $configurations;
        $dbms=$configurations['dataphyre']['sql']['datacenters'][$configurations['dataphyre']['datacenter']]['dbms_clusters'][$configurations['dataphyre']['sql']['default_cluster']]['dbms'];
        $snapshot_file=self::$snapshot_dir.$scope.'.'.$table.'.json';
        $current=sql::select('*', [
            'mysql'=>"SHOW COLUMNS FROM `$table`",
            'postgresql'=>"SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name=$1",
            'sqlite'=>"PRAGMA table_info('$table')"
        ], null, ['postgresql'=>[$table]], true);
        $indexes=sql::select('*', [
            'mysql'=>"SHOW INDEXES FROM `$table`",
            'postgresql'=>"SELECT indexname, indexdef FROM pg_indexes WHERE tablename=$1",
            'sqlite'=>"PRAGMA index_list('$table')"
        ], null, ['postgresql'=>[$table]], true);
        $permissions=sql::select('*', [
            'postgresql'=>"SELECT grantee, privilege_type FROM information_schema.role_table_grants WHERE table_name=$1",
            'mysql'=>"SHOW GRANTS FOR CURRENT_USER"
        ], null, ['postgresql'=>[$table]], true);
        if(!$current || !is_array($current)) return null;
        $previous=file_exists($snapshot_file) ? json_decode(file_get_contents($snapshot_file), true) : [];
        file_put_contents($snapshot_file, json_encode($current, JSON_PRETTY_PRINT));
        $adds=[];
        $drops=[];
        foreach($current as $col){
            $name=$col['Field'] ?? $col['column_name'] ?? $col['name'];
            $found=false;
            foreach($previous as $old){
                $old_name=$old['Field'] ?? $old['column_name'] ?? $old['name'];
                if($old_name===$name){
                    $found=true;
                    break;
                }
            }
            if(!$found) $adds[]=$name;
        }
        foreach($previous as $col){
            $name=$col['Field'] ?? $col['column_name'] ?? $col['name'];
            $found=false;
            foreach($current as $new){
                $new_name=$new['Field'] ?? $new['column_name'] ?? $new['name'];
                if($new_name===$name){
                    $found=true;
                    break;
                }
            }
            if(!$found) $drops[]=$name;
        }
        $up_sql=[];
        foreach($adds as $col){
            $up_sql[]="ALTER TABLE $table ADD COLUMN $col TEXT";
        }
        foreach($drops as $col){
            $up_sql[]="ALTER TABLE $table RENAME COLUMN $col TO {$col}_old";
        }
        foreach($drops as $col){
            $up_sql[]="-- ALTER TABLE $table DROP COLUMN {$col}_old";
        }
        foreach($indexes as $index){
            if($dbms==='postgresql'){
                $up_sql[]=trim($index['indexdef']).";";
            } elseif($dbms==='mysql'){
                $up_sql[]="CREATE INDEX {$index['Key_name']} ON $table({$index['Column_name']});";
            } elseif($dbms==='sqlite'){
                $up_sql[]="-- CREATE INDEX {$index['name']} ON $table(...);"; // SQLite details not expanded here
            }
        }
        if($dbms==='postgresql'){
            foreach($permissions as $perm){
                $up_sql[]="GRANT {$perm['privilege_type']} ON $table TO {$perm['grantee']};";
            }
        }
        if(empty($up_sql)) return null;
        $filename=self::$migration_roots[$scope].$table.'.'.time().'.yaml';
        $yaml=[
            'table'=>$table,
            'migrations'=>[[
                'version'=>time(),
                'description'=>'Auto-generated diff with index and grants',
                'up'=>[$dbms=>implode("\n", $up_sql)],
            ]]
        ];
        file_put_contents($filename, yaml_emit($yaml));
        return $filename;
    }
	
}
