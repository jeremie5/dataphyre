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
 
require(__DIR__."/functions.php");

if(isset($_POST['full_diagnosis'])){
	$full_diagnosis_result=full_diagnosis();
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
    <title>Dataphyre Diagnosis Panel</title>
  </head>
  <body>
	<div class="container pt-5 ">
		<div class="row justify-content-center">
			<div class="col col-lg-10">
				<h1 class="text-center">Dataphyre Diagnosis Panel</h1>
				<div class="py-3">
					<div class="card bg-dark text-white p-2" style="height:750px;overflow:auto;">
						<div class="">
							<?=$full_diagnosis_result["log"];?>
						</div>
					</div>
					<div class="py-3 text-center ">
						<form method="post">
							<input type="submit" name="full_diagnosis" class="btn btn-primary" value="Run full project diagnosis">
						</form>
					</div>
				</div>
				<div class="py-3">
					<?php
					if(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])){
						echo'<span class="text-success">You are connected to a load balancer or proxy using https.</span><br>';
						if($_SERVER['HTTP_X_FORWARDED_PROTO']==='https'){
							echo'<span class="text-success">Traffic between web server and load balancer or proxy is encrypted.</span><br>';
						}
						else
						{
							echo'<span class="text-warning">Traffic between web server and load balancer or proxy is unencrypted.</span><br>';
						}
					}
					else
					{
						if($_SERVER['HTTPS']==='on'){
							echo'<span class="text-success">You ('.$_SERVER['REMOTE_ADDR'].') are connected directly to the server using https.</span><br>';
						}
						else
						{
							echo'<span class="text-danger">You ('.$_SERVER['REMOTE_ADDR'].') are connected directly to the server without https.</span><br>';
						}
					}
					?>
				</div>
			</div>
		</div>
	</div>
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.12.9/dist/umd/popper.min.js" integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
  </body>
</html>