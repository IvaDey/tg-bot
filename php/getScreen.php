<?php
//    ini_set('error_reporting', E_ALL);
//    ini_set('display_errors', 1);
//    ini_set('display_startup_errors', 1);

    header('Content-Type: text/html; charset=utf-8');

    require_once 'dbSettings.php';
    $db = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

    $screenID = $_GET['screenID'];

    $screen = $db->query("SELECT * FROM screens WHERE screen_id='{$screenID}'")->fetch_object();
    $res = Array(
        'screenName' => $screen->screen_name,
        'screenMessage' => $screen->screen_message,
        'keyboard' => Array()
    );

    $query = "SELECT * FROM keyboards WHERE keyboard_id='{$screen->screen_id}'";
    $query = $db->query($query);

    $res['keyboard'] = Array();
    while ($row = $query->fetch_object())
    {
        array_push($res['keyboard'], Array(
            'buttonRow' => $row->button_row,
            'caption' => $row->caption,
            'reply' => $row->reply,
            'next_screen' => $row->next_screen
        ));
    }

    print_r(json_encode($res, JSON_UNESCAPED_UNICODE));
?>