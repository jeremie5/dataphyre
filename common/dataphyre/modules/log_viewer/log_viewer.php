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
 
$logDirectory=$rootpath['dataphyre']."logs";
$maxLogsPerRequest=20;

function getLatestFile($directory){
    $files=scandir($directory);
    $latestHtmlFile=null;
    $latestLogFile=null;
    foreach($files as $file){
        if($file==="." || $file==="..") continue;
        $pathinfo=pathinfo($file);
        if(isset($pathinfo['extension'])){
            if($pathinfo['extension']==='html'){
                if($latestHtmlFile===null || strcmp($file, $latestHtmlFile)>0){
                    $latestHtmlFile=$file;
                }
            }
			elseif($pathinfo['extension']==='log'){
                if($latestLogFile===null || strcmp($file, $latestLogFile)>0){
                    $latestLogFile=$file;
                }
            }
        }
    }
    return $latestHtmlFile ? "$directory/$latestHtmlFile" : ($latestLogFile ? "$directory/$latestLogFile" : null);
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1'){
    header('Content-Type: application/json');
    $latestFilePath=getLatestFile($logDirectory);
    if(!$latestFilePath){
        echo json_encode(['log_date'=>'', 'new_entries'=>[], 'last_entry_hash'=>'']);
        exit;
    }
    $lastReadEntryHash=isset($_POST['last_entry_hash']) ? $_POST['last_entry_hash'] : '';
    $fileContent=file_get_contents($latestFilePath);
	$logEntries=explode("<!--ENDLOG-->", $fileContent);
	$logEntries=array_map('trim', $logEntries);
	$logEntries=array_filter($logEntries, fn($entry)=>!empty($entry));
	$logEntries=array_reverse($logEntries);
	$foundLastEntry=false;
	$newEntries=[];
	foreach($logEntries as $entry){
		$entryHash=md5(trim(preg_replace('/\s+/', ' ', $entry)));
		if ($entryHash===$lastReadEntryHash){
			$foundLastEntry=true;
			continue;
		}
		if ($foundLastEntry || $lastReadEntryHash==='' || count($newEntries) < $maxLogsPerRequest){
			$newEntries[]=$entry;
		}
	}
	$newLastEntryHash=!empty($newEntries) ? md5(end($newEntries)) : $lastReadEntryHash;
	if (!$foundLastEntry && !empty($logEntries)){
		$newEntries=array_slice($logEntries, 0, $maxLogsPerRequest);
	}
	$newLastEntryHash=!empty($newEntries) ? md5(trim(preg_replace('/\s+/', ' ', end($newEntries)))) : '';
	echo json_encode([
		'log_date'=>basename($latestFilePath),
		'new_entries'=>array_values($newEntries),
		'last_entry_hash'=>$newLastEntryHash
	]);
	exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dataphyre Failure Logs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        .table td, .table th {
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container mt-5" style="max-width:1800px">
        <h1 id="log-title">Dataphyre Failure Logs</h1>
		<button id="toggle-logs" class="btn btn-primary mb-3">Pause Logs</button>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th style="min-width:180px;">Timestamp</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody id="log-content">
                <tr><td colspan="2">Waiting for log events...</td></tr>
            </tbody>
        </table>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let lastReadEntryHash="";
let pollingInterval=2000;
let maxDisplayedEntries=50; // Limit entries to prevent performance issues
let isSelecting=false;

$('#log-content').on('mousedown', function (){
    isSelecting=true;
});
$('#log-content').on('mouseup', function (){
    setTimeout(()=>{ isSelecting=false; }, 2000);
});

function startFetchingLogs(){
    fetchLogs();
    setTimeout(startFetchingLogs, pollingInterval);
}

let logsPaused=false;

$('#toggle-logs').click(function (){
    logsPaused=!logsPaused;
    $(this).text(logsPaused ? 'Resume Logs' : 'Pause Logs');
});

function fetchLogs(){
    if (logsPaused) return; // Stop updating logs if paused
	if (logsPaused || isSelecting) return; // Skip updating logs if selecting
	
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        dataType: 'json',
        data: {
            ajax: '1',
            last_entry_hash: lastReadEntryHash
        },
        success: function(response){
            if (response.new_entries.length>0){
                let newRows="";
                response.new_entries.forEach(entry=>{
                    if (entry.trim().length>0){
                        newRows += `<tr><td colspan="2">${entry}</td></tr>`; // Ensure proper row formatting
                    }
                });

                let logContent=$('#log-content');
                let waitingMessage=logContent.find('tr:first-child:contains("Waiting for log events...")');
                if (waitingMessage.length) waitingMessage.remove();

                if (newRows !== ""){
                    let tempContainer=document.createElement('tbody');
                    tempContainer.innerHTML=newRows;
                    logContent.prepend(tempContainer.childNodes);
                    
                    while (logContent.children().length>maxDisplayedEntries){
                        logContent.children().last().remove();
                    }

                    if (response.last_entry_hash !== ""){
                        lastReadEntryHash=response.last_entry_hash;
                    }
                }
            }
        },
        error: function(){
            console.error('Error loading logs.');
        }
    });
}


$(document).ready(function (){
    startFetchingLogs();
});

</script>
</body>
</html>