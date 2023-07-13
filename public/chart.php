<?php
    require("../includes/config.php"); 
    require_once("phpChart_Lite/conf.php");
    $pc = new C_PhpChartX(array(array(11, 9, 5, 12, 14)));
$pc->draw();
?>