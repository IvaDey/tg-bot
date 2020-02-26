<?php
    header('Content-Type: text/html; charset=utf-8');

    require_once 'dbSettings.php';
    $db = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

    $query = $db->query("SELECT * FROM screens");
    $res = Array();
    while ($row = $query->fetch_object()) {
        array_push($res, Array(
            'screenID' => $row->screen_id,
            'screenName' => $row->screen_name
        ));
    }
    print_r(json_encode($res, JSON_UNESCAPED_UNICODE));
?>