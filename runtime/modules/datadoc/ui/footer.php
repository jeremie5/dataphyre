<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
?>
<footer>
	<?php $app_version=(string)(\dataphyre\core::config_all()['version'] ?? '0'); ?>
	<hr class="mt-5 mb-5 <?=adapt(["dark"=>"bg-white"]);?>">
	<div class="container">
		<div class="row">
			<div class="col-md-12">
				<div class="d-flex mt-2 mb-2">
					<div class="copyright <?=adapt(["dark"=>"text-white"]);?>"><b>Dataphyre is released under the MIT License.</b></div>
				</div>
				<div class="alert alert-info" role="alert">
					<strong>NOTICE:</strong> Dataphyre source code is licensed under the MIT License.
					Bundled third-party libraries retain their own license files.
				</div>
			</div>
		</div>
	</div>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/jquery.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/bootstrap.min.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/jquery-ui.min.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/shopirocs/library/js/custom.min.js"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/popper.min.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/header-save-settings.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/jquery.countdown.min.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/jquery.meanmenu.min.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/jquery.nivo.slider.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/jquery.fancybox.min.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/owl.carousel.min.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/plugins.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/main.js?v=<?=$app_version; ?>"></script>
	<script src="https://cdn.shopiro.ca/res/assets/genesis/js/sweetalert2.js?v=<?=$app_version; ?>"></script>
</footer>
</body>
</html>
