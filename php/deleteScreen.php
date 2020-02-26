<?php
//    ini_set('error_reporting', E_ALL);
//    ini_set('display_errors', 1);
//    ini_set('display_startup_errors', 1);

    header('Content-Type: text/html; charset=utf-8');

    require_once 'dbSettings.php';
    $db = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

    $screen_id = $_GET['screen_id'];

    $query = "DELETE FROM screens WHERE screen_id='{$screen_id}'";
    print_r($query.'    ');
    $db->query($query);
    $query = "DELETE FROM keyboards WHERE keyboard_id='{$screen_id}'";
    print_r($query);
    $db->query($query);
?>