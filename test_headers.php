<?php
require 'C:\laragon\www\monitor.aeonium.com.br\api\auth\session.php';
if (headers_sent($f, $l)) {
    echo "Headers sent by $f:$l\n";
} else {
    echo "No headers sent.\n";
}