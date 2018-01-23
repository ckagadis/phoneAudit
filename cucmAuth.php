<?php

$username = 'username'; # The username of the application user configured in CUCM
$passwd = 'password'; # The password for the application user configured in CUCM
$context = stream_context_create(array('ssl' => array('verify_peer' => false, 'allow_self_signed' => true)));
$host = 'ipaddress'; # The IP address of the CUCM server the script will be sending and recieving information with.

?>
