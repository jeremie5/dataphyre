<?php
$logDirectory = $rootpath['dataphyre'] . "logs";

// Function to get the latest file from directory based on the filename date-time
function getLatestFile($directory) {
    $files = scandir($directory);
    $latestHtmlFile = null;
    $latestLogFile = null;
    foreach ($files as $file) {
        if ($file == "." || $file == "..") continue;
        $pathinfo = pathinfo($file);
        if (isset($pathinfo['extension'])) {
            if ($pathinfo['extension'] === 'html') {
                if ($latestHtmlFile === null || strcmp($file, $latestHtmlFile) > 0) {
                    $latestHtmlFile = $file;
                }
            } elseif ($pathinfo['extension'] === 'log') {
                if ($latestLogFile === null || strcmp($file, $latestLogFile) > 0) {
                    $latestLogFile = $file;
                }
            }
        }
    }
    if ($latestHtmlFile !== null) {
        return $directory . "/" . $latestHtmlFile;
    } elseif ($latestLogFile !== null) {
        return $directory . "/" . $latestLogFile;
    }
    return null;
}

$latestFilePath = getLatestFile($logDirectory);

$log_date=str_replace($logDirectory, '', $latestFilePath);
$log_date=str_replace('/', '', $log_date);
$log_date=str_replace('.html', '', $log_date);
$log_date.='.log';

// Display file content if exists
if ($latestFilePath) {
    $fileContent = file_get_contents($latestFilePath);
	$fileContent=explode('<!--ENDLOG-->', $fileContent);
	$fileContent=array_reverse($fileContent);
	$fileContent=implode('', $fileContent);
	echo <<<HTML
	<!DOCTYPE html>
	<html>
	<head>
		<meta http-equiv="refresh" content="1">
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
		<style>
			.table td, .table th {
				word-break: break-all;
			}
		</style>
	</head>
	<body>
		<div class="container mt-5" style="max-width:1800px">
			<h1>Dataphyre Failure Logs ($log_date)</h1>
			<table class="table table-bordered">
				<thead>
					<tr>
						<th style="min-width:180px;">Timestamp</th>
						<th>Error</th>
					</tr>
				</thead>
				<tbody>
					$fileContent
				</tbody>
			</table>
		</div>
	</body>
	</html>
	HTML;
}
else
{
    echo "No files found in the log directory.";
}
?>
</body>
</html>