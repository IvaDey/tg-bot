<?php
    header('Content-Type: text/html; charset=utf-8');

    require_once 'dbSettings.php';
    $db = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

    $screen_id = $_POST['screen_id'];
    $keyboard_id = $_POST['screen_id'];
    $screen_name = $_POST['screen_name'];
    $screen_message = $_POST['screen_message'];

    $row = $db->query("SELECT * FROM screens WHERE screen_id='{$screen_id}'")->fetch_object();
    // Если в БД нет этапа с таким ID
    if (!$row) { // ...значит это новый этап и мы его добавляем как новый
        $query = "INSERT INTO screens(screen_id, screen_name, screen_message) "
                ."VALUES('{$screen_id}', '{$screen_name}', '{$screen_message}')";
        $db->query($query);

        $ind = 0;
        foreach ($_POST['keyboard'] as $button) {
            $ind++;
            $query = "INSERT INTO keyboards(keyboard_id, button_number, button_row, caption, reply, next_screen) "
                ."VALUES('{$keyboard_id}', '{$ind}', '{$button['button_row']}', '{$button['caption']}', "
                ."'{$button['reply']}', '{$button['next_screen']}')";

            $db->query($query);
        }
    } else { // ...иначе нам просто необходимо обновить информацию
        $query = "UPDATE screens set screen_id='{$screen_id}', screen_name='{$screen_name}', screen_message='{$screen_message}' "
                ."WHERE screen_ID='{$screen_id}'";
        $db->query($query);

        // Клавиатуру мы удаляем полностью и записываем заново, так как она могла сильно измениться
        $query = "DELETE FROM keyboards WHERE keyboard_id='{$keyboard_id}'";
        $db->query($query);

        $ind = 0;
        foreach ($_POST['keyboard'] as $button) {
            $ind++;
            $query = "INSERT INTO keyboards(keyboard_id, button_number, button_row, caption, reply, next_screen) "
                ."VALUES('{$keyboard_id}', '{$ind}', '{$button['button_row']}', '{$button['caption']}', "
                ."'{$button['reply']}', '{$button['next_screen']}')";

            $db->query($query);
        }
    }
?>