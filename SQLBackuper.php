<?php
/**
 * Created by PhpStorm.
 * User: lukas
 * Date: 19.02.18
 * Time: 03:56
 */


include "../lib/inc.all.php";
require_once "mysqldump-php/src/Ifsnop/Mysqldump/Mysqldump.php";

echo "I am Alive!";
//var_dump(strtotime("-1 Month")-strtotime("now"));
$b = new SQLBackuper();
$b->startBackup(false);
$b->deleteOldBackups();

class SQLBackuper{
    private $dumper;
    private $backupStrat;
    private $knownBackups;
    private $backupPath = "../sql/SQLBackup/backup/";
    
    public function __construct($blacklisttbl = []){
        global $DB_PREFIX,$DB_DSN,$DB_USERNAME,$DB_PASSWORD,$scheme;
        
        $incTables = [];
        foreach ($scheme as $tblname => $content){
            $incTables[] = $DB_PREFIX.$tblname;
        }
        $incTables = array_diff($incTables,$blacklisttbl);
        $dumpSettings = array(
            'include-tables' => $incTables,
            'exclude-tables' => array(),
            'compress' => \Ifsnop\Mysqldump\Mysqldump::NONE,
            'init_commands' => array(),
            'no-data' => array(),
            'reset-auto-increment' => false,
            'add-drop-database' => false,
            'add-drop-table' => false,
            'add-drop-trigger' => true,
            'add-locks' => true,
            'complete-insert' => false,
            'databases' => false,
            'default-character-set' => \Ifsnop\Mysqldump\Mysqldump::UTF8,
            'disable-keys' => true,
            'extended-insert' => true,
            'events' => false,
            'hex-blob' => true, /* faster than escaped content */
            'net_buffer_length' => \Ifsnop\Mysqldump\Mysqldump::MAXLINESIZE,
            'no-autocommit' => true,
            'no-create-info' => false,
            'lock-tables' => true,
            'routines' => false,
            'single-transaction' => true,
            'skip-triggers' => false,
            'skip-tz-utc' => false,
            'skip-comments' => false,
            'skip-dump-date' => false,
            'skip-definer' => false,
            'where' => '',
            /* deprecated */
            'disable-foreign-keys-check' => true
        );
        $this->dumper = new Ifsnop\Mysqldump\Mysqldump($DB_DSN,$DB_USERNAME,$DB_PASSWORD,$dumpSettings);
        $this->scanExistingBackups();
        //var_dump($this->knownBackups);
        $this->setBackupStrategy([
            "save1perDay" => "-1 week",
            "save1perWeek" => "-1 month",
            "save1perMonth" => "-1 year",
        ]);
    }
    public function scanExistingBackups(){
        $this->knownBackups = array_diff(scandir($this->backupPath),[".",".."]);
        rsort($this->knownBackups,SORT_STRING);
        foreach ($this->knownBackups as $key => $backup){
            $this->knownBackups[$key] = substr($backup,0,strrpos($backup,"."));
        }
        var_dump($this->knownBackups);
    }
    
    public function setBackupStrategy($strategy){
        foreach ($strategy as $key => $val){
            if(strpos($key,"save1per") !== 0){
                unset($strategy[$key]);
                continue;
            }else if(($t = strtotime("1 ".substr($key,strlen("save1per")))) == false){
                unset($strategy[$key]);
                continue;
            }else{
                $diff_cycle[] = $t - time();
            }
        }
        $keys = array_keys($strategy);
        for($i = 0;$i < count($strategy)-1; $i++){
            if($diff_cycle[$i] >=  $diff_cycle[$i+1]){
                die("Backupstrategie Keys nicht aufsteigend sortiert!");
            }
            if(($t1 = strtotime($strategy[$keys[$i]])) == false ){
                die("value: {$strategy[$keys[$i]]} keine gültige Zeitangabe");
            }
            if(($t2 = strtotime($strategy[$keys[$i]])) == false ){
                die("value: {$strategy[$keys[$i]]} keine gültige Zeitangabe");
            }
            if($t1 < $t2 ){
                die("Backupstrategie values nicht aufsteigend sortiert!");
            }
        }
        $this->backupStrat = $strategy;
        var_dump($strategy);
    }
    public function startBackup($forceNow = false){
        $now = time();
        $keys = array_keys($this->backupStrat);
        $firstkey = reset($keys);
        $smallest_diff_cycle = strtotime("-1 ".substr($firstkey,strlen("save1per")))- $now;
        $newBackup = true;
        foreach ($this->knownBackups as $timestamp){
            $diff_file = strtotime($timestamp)-$now;
            if($diff_file>$smallest_diff_cycle)
                $newBackup = false;
        }
        if($newBackup || $forceNow){
            echo "do next backup";
            $this->dumper->start($this->backupPath.date("Y-m-d H:i:s").".sql");
        }
        $this->scanExistingBackups();
        
        
    }
    
    public function deleteOldBackups(){
        $now = time();
        $files = [];
        foreach ($this->knownBackups as $timestr){
            $fileage = abs($now - strtotime($timestr));
            $files[$fileage] = $timestr;
        }
        var_dump($files);
        $ages = array_keys($files);
        $delete = [];
        foreach ($this->backupStrat as $cycle_frequency => $duration){
            $diff_freq = abs(strtotime("-1 ".substr($cycle_frequency,strlen("save1per"))) - $now);
            $diff_duration = abs(strtotime($duration)-$now);
            $count = abs($diff_duration/$diff_freq);
            var_dump($count);
            var_dump($diff_duration);
            var_dump($ages);
            $i = 0;
            while (($el = reset($ages)) < $diff_duration && $el != false){
                array_shift($ages);
                var_dump($el);
                if(++$i > $count){
                    $delete[$el] = $files[$el];
                }
            
            }
        }
        foreach ($ages as $obsoletKey){
            $delete[$obsoletKey] = $files[$obsoletKey];
        }
        var_dump($delete);
        foreach ($delete as $timestr){
            unlink($this->backupPath.$timestr.".sql");
        }
        $this->scanExistingBackups();
    }
    
}
