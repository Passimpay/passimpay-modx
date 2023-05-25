<?php
include_once MODX_CORE_PATH . 'components/minishop2/custom/payment/mspassimpay.class.php';
$hand = new msPaspy();
$hand->init($modx);
return $hand->payment_fields();
