<?php
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

if (str_contains($BASE_URL, 'public/')){
    $BASE_URL = substr($BASE_URL,0,strpos($BASE_URL, '/public/')+ strlen('/public'));
    
}
?>
