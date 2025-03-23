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


ini_set('display_errors', 0);
 
require(__DIR__."/dpanel.main.php");

if(isset($_POST['dataphyre_full'])){
	\dataphyre\dpanel::diagnose_modules_in_folder($rootpath['common_dataphyre']."modules");
	\dataphyre\dpanel::diagnose_modules_in_folder($rootpath['dataphyre']."modules");
	$trace=\dataphyre\dpanel::get_verbose();
}
else
{
	$full_diagnosis_result['log']='<h4 class="pt-5 text-center">Click on a button below to scan for issues ...</h4>';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
    <title>Dataphyre Dpanel</title>
  </head>
  <body class="bg-dark">
	<div class="p-4 ">
		<div class="row justify-content-center">
			<div class="col-lg-6 col-12">
				<h1 class="text-center text-white">Dataphyre Dpanel</h1>
				<h5 class="text-center text-secondary">Dpanel provides a comprehensive, in-depth diagnostic interface. It validates PHP syntax, traces execution logs, and runs JSON-defined unit tests to ensure module integrity and stability. Use this tool to debug issues, verify system health, and confirm readiness for production.</h6>
			</div>
			<div class="col-12">
				<div class="py-3">
					<div class="card bg-secondary text-white rounded p-2">
						<div class="card-body p-0">

							<?php if (is_array($trace) && count($trace) > 0): ?>
								<div style="max-height: 750px; overflow: auto;">
									<table class="table table-sm table-dark table-bordered mb-0">
										<thead class="thead-light text-dark">
											<tr>
												<th>Type</th>
												<th>File / Module</th>
												<th>Line</th>
												<th style="min-width: 300px;">Message</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ($trace as $index => $entry): ?>
												<?php
													$type = htmlspecialchars($entry['type'] ?? 'Info');
													$filename = $entry['file'] ?? $entry['test_case_file'] ?? $entry['module'] ?? 'N/A';
													$filenameDisplay = $filename ? basename($filename) : 'N/A';
													$filenameTooltip = $filename ? "title=\"$filename\"" : '';
													$line = isset($entry['line']) && $entry['line'] != 0 ? htmlspecialchars($entry['line']) : 'N/A';
													$traceHtml = '';
													if ($type === 'php_exception' && isset($entry['exception']) && $entry['exception'] instanceof \Throwable) {
														$exception = $entry['exception'];
														$message = $exception->getMessage();
														$filenameDisplay = basename($exception->getFile());
														$filenameTooltip = "title=\"" . $exception->getFile() . "\"";
														$line = $exception->getLine();
														$traceHtml = "<pre class=\"mb-0 text-light bg-dark p-2\" style=\"white-space: pre-wrap; overflow-x: auto;\">"
															. htmlspecialchars($exception->getTraceAsString()) . "</pre>";
													}
													elseif ($type === 'tracelog' && isset($entry['tracelog']) && is_array($entry['tracelog'])) {
														$countTypes = [];
														foreach ($entry['tracelog'] as $logEntry) {
															$msgType = strtolower($logEntry['type'] ?? 'info');
															$countTypes[$msgType] = ($countTypes[$msgType] ?? 0) + 1;
														}
														$typeSummary = [];
														foreach ($countTypes as $msgType => $msgCount) {
															$typeSummary[] = "$msgCount $msgType";
														}
														ob_start(); ?>
														<table class="table table-sm table-bordered table-dark bg-secondary mb-0">
															<thead>
																<tr>
																	<th>File</th>
																	<th>Line</th>
																	<th>Class</th>
																	<th>Function</th>
																	<th>Message</th>
																</tr>
															</thead>
															<tbody>
																<?php foreach ($entry['tracelog'] as $log): ?>
																	<tr>
																		<td><span title="<?= htmlspecialchars($log['file'] ?? '') ?>"><?= htmlspecialchars(basename($log['file'] ?? 'Unknown')) ?></span></td>
																		<td><?= htmlspecialchars($log['line'] ?? '—') ?></td>
																		<td><?= htmlspecialchars($log['class'] ?? '—') ?></td>
																		<td><?= htmlspecialchars($log['function'] ?? '—') ?></td>
																		<td><pre class="mb-0 text-light bg-dark p-2" style="white-space: pre-wrap;"><?= nl2br(htmlspecialchars($log['message'] ?? '')) ?></pre></td>
																	</tr>
																<?php endforeach; ?>
															</tbody>
														</table>
														<?php $traceHtml = ob_get_clean();
														$message = '<i>' . count($entry['tracelog']) . ' trace entries (' . implode(', ', $typeSummary) . ')</i>';
													}
													else 
													{
														$message = $entry['fail_string'] ?? $entry['warning_string'] ?? $entry['error'] ?? $entry['message'] ?? $entry['reason'] ?? '';
														$message = !empty($message) ? nl2br(htmlspecialchars($message)) : '<i>No message provided</i>';
													}
												?>
												<tr>
													<td style="vertical-align: middle;">
														<h4><span class="badge bg-info"><?= $type ?></span></h4>
													</td>
													<td style="vertical-align: middle;">
														<span <?= $filenameTooltip ?>><?= htmlspecialchars($filenameDisplay) ?></span>
													</td>
													<td style="vertical-align: middle;">
														<?= $line ?>
													</td>
													<td>
														<div class="d-flex justify-content-between align-items-center">
															<pre class="mb-0 text-light bg-dark p-2 flex-grow-1" style="white-space: pre-wrap; overflow-x: auto;"><?= $message ?></pre>
															<?php if (!empty($traceHtml)): ?>
																<button class="btn btn-sm btn-outline-light ms-2" onclick="document.getElementById('trace-<?= $index ?>').classList.toggle('d-none');">Expand Logs</button>
															<?php endif; ?>
														</div>
														<?php if (!empty($traceHtml)): ?>
															<div id="trace-<?= $index ?>" class="d-none mt-2"><?= $traceHtml ?></div>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php else: ?>
								<h3 class="text-center">No log data captured.<br>Press a button below to perform a scan.</h3>
							<?php endif; ?>

						</div>
					</div>
					<div class="py-3 text-center">
						<form method="post">
							<input type="submit" name="dataphyre_full" class="btn btn-lg btn-primary" value="Diagnose Dataphyre">
							<input type="submit" name="project_full" class="btn btn-lg btn-primary" value="Diagnose All Projects">
							<input type="submit" name="project_full" class="btn btn-lg btn-primary" value="Diagnose <?=ucfirst($bootstrap_config['app']);?>">
							<!--
							<div class="d-flex justify-content-center mt-3">
								<div class="custom-control custom-checkbox">
									<input type="checkbox" class="custom-control-input" id="showErrorOnly" name="show_error_only" value="1" checked>
									<label class="custom-control-label" for="showErrorOnly">Show Errors Only</label>
								</div>
							</div>
							-->
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.12.9/dist/umd/popper.min.js" integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
  </body>
</html>