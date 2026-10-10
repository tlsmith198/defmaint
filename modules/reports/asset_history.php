<?php
/** Kept for the "Maintenance history report" link on the asset page. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
redirect('modules/reports/run.php?report=asset_history&asset_id=' . (int) req_int('asset_id'));
