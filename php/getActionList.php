<?php
    header('Content-Type: text/html; charset=utf-8');

    require_once 'dbSettings.php';
    $db = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

    $query = "SELECT * FROM actions";
    $query = $db->query($query);

    $result = Array();
    while ($row = $query->fetch_object())
    {
        array_push($result, $row);
    }

    print_r(json_encode($result, JSON_UNESCAPED_UNICODE));
?>