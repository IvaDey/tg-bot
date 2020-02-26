<?php
//----------------------------------------------------------------------------------------------------------------------

require_once 'models/dbModel.php';
require_once 'models/tg-bot.php';
require_once 'models/admin-actions.php';
require_once 'models/user-actions.php';
require_once 'settings/settings.php';

//----------------------------------------------------------------------------------------------------------------------

$db = new dbModel($db_user, $db_password, $db_name, $db_host, $db_port);
$tgBot = new tg_bot($tgToken);
$adminActions = new adminActionList($db, $tgBot);
$userActions = new userActionList($db, $tgBot);

//----------------------------------------------------------------------------------------------------------------------

//Получаем и декодируем уведомление
$data = file_get_contents('php://input');
$data = json_decode($data, true);

//----------------------------------------------------------------------------------------------------------------------

//Формируем константы с идентификатором и username нашего бота
$bot_id = 000000000;
$bot_name = "bot_name_bot";

//----------------------------------------------------------------------------------------------------------------------

// Проверим сперва какого типа уведомление
// Если это callback_query, то узнаем чей (админа или пользователя) и вызываем необходимый метод соответствуюдего
// класса, отдавая массив с параметрами
if ($data['callback_query']) {
    // Проверяем не отключен ли бот в данной переписке
    if (!$db->getBotStatus($data['callback_query']['message']['chat']['id']))
        return;

    // Записываем в логи входящий callback_query
    // Формируем массив с информацией
    $logs = Array(
        "chat_id" => $data['callback_query']['message']['chat']['id'],
        "chat_name" => $data['callback_query']['message']['chat']['first_name'],
        "timestamp" =>  Date('Y-m-d H:i', time()),
        "data" => addslashes(json_encode(Array(
            "event" => Array(
                "type" => "incoming",
                "name" => "callback_query"
            ),
            "data" => Array(
                "message_id" => $data['callback_query']['message']['message_id'],
                "user_id" => $data['callback_query']['message']['chat']['id'],
                "first_name" => $data['callback_query']['message']['chat']['first_name'],
                "username" => $data['callback_query']['message']['chat']['username'],
                "callback_data" => $data['callback_query']['data']
            )
        )))
    );
    // Отправляем логи в БД
    $db->insertLogs($logs);

    // Запоминаем id чата и сообщения
    $sender = Array(
        'chat_id' => $data['callback_query']['message']['chat']['id'],
        'message_id' => $data['callback_query']['message']['message_id']
    );

    // Извлекаем callback_data, остальные данные нам не нужны
    $data = $data['callback_query']['data'];

    // Извлекаем название действия
    $actionName = trim(explode('|', $data)[0]);

    // Удаляем название действия из callback_data и формируем массива параметров
    $data = str_replace("{$actionName}|", '', $data);
    $data = explode('|', $data);

    // Формируем массив с параметрами
    $params = Array(
        'sender' => $sender,
        'data' => $data
    );

    // Проверяем кто вызвал данное событие
    if ($sender['chat_id'] == $db->getAdminID()) { // Если это админ, то вызываем указанный метод из его класса
        $adminActions->makeAction($actionName, $params);
    } else { // Иначе, если это пользователь, то вызываем указанный метод из его класса
         $userActions->makeAction($actionName, $params);
    }

    // Завершаем работу скрипта
    return;
}

//----------------------------------------------------------------------------------------------------------------------

// Теперь обработаем варианты обычных сообщений
if ($data['message']) {
    // Проверяем не отключен ли бот в данной переписке
    if (!$db->getBotStatus($data['message']['chat']['id']))
        return;

    // Записываем в логи входящее сообщение
    // Формируем массив с информацией
    $logs = Array(
        "chat_id" => $data['message']['chat']['id'],
        "chat_name" => $data['message']['chat']['first_name'],
        "timestamp" =>  Date('Y-m-d H:i', time()),
        "data" => addslashes(json_encode(Array(
            "event" => Array(
                "type" => "incoming",
                "name" => "message"
            ),
            "data" => Array(
                "message_id" => $data['message']['message_id'],
                "user_id" => $data['message']['chat']['id'],
                "first_name" => $data['message']['chat']['first_name'],
                "username" => $data['message']['chat']['username'],
                "text" => $data['message']['text']
            )
        )))
    );
    // Отправляем логи в БД
    $db->insertLogs($logs);

    // Для начала упростим данные, которые нам прислал телеграмм и запишем их в массив
    $data = Array(
        'message_id' => $data['message']['message_id'],
        'user_id' => $data['message']['from']['id'],
        'first_name' => $data['message']['from']['first_name'],
        'username' => $data['message']['from']['username'],
        'chat_id' => $data['message']['chat']['id'],
        'date' => date('Y-m-d H:i:s', $data['message']['date']),
        'message_body' => $data['message']['text']
    );

    //------------------------------------------------------------------------------------------------------------------

    // Получаем текущий экран пользователя
    $currentScreen = json_decode($db->getCurrentScreen($data['user_id']));
    $currentScreen = $currentScreen->result;

    //------------------------------------------------------------------------------------------------------------------

    // Если пользователь пишет нам в первый раз, то регистрируем его
    // И отправляем запрос на присвоение статуса
    if ($currentScreen == 'unknown_user') {
        $userActions->makeAction('askToJoin', $data);

        return;
    }

    //------------------------------------------------------------------------------------------------------------------

    // Проверяем была ли нажата кнопка
    // Для этого запрашиваем в БД кнопку текущего экрана с текстом как присланное сообщение
    $button = $db->getButton($currentScreen, $data['message_body']);
    // Если Это было действительно нажатие на кнопку, то в таком случае обновляем экран
    // И запоминаем его как текущий, так как на него переходим сразу
    if ($button) {
        $db->updateScreen($data['user_id'], $button->next_screen);
        $currentScreen = $button->next_screen;
    }

    // Запрашиваем текущий экран
    // Если была нажата кнопка, то перейдем на другой экран
    // Который станет текущим, поэтому получим его
    $screen = json_decode($db->getScreenObj($currentScreen));

    //------------------------------------------------------------------------------------------------------------------

    // Получаем сообщение экрана
    $text = $screen->message;

    // Проверим какого типа наш экран
    // Если экран полностью активный, то его необходимо обрабатывать полностью программно
    // Основным признаком такого экрана является отсутствие клавиатуры

    // Если клавиатура есть, то обрабатываем согласно содержимому экрана
    if ($screen->keyboard) {
        // В первую очередь подставим значения вместо всех статических полей-вставок, если таковые имеются
        // Структура статического поля-вставки: '__field_name__' (вначале и в конце двойное подчеркивание
        $text = str_replace("__firstName__", $data['first_name'], $text);
        $text = str_replace("__userID__", $data['user_id'], $text);

        // Далее подставим значения вместо полей-вставок
        // Пока что допускается не более одного вычисляемого поля-вставки
        // Структура вычисляемого  поля-вставки: '||field_name|param1|...|paramN||' (вначале и в конце двойное подчеркивание
        // Для начала резделим запрос на составные части при помощи регулярного выражения
        $regexp = "~(?<=\|)[^|]+(?=\|)~";
        $res = Array();
        preg_match_all($regexp, $text, $res);

        // Выделим имя поля-вставки
        // Оно находится в нулевой ячейке массива
        $field_name = $res[0][0];

        // Формируем параметры для метода
        // Помимо параметров поля-вставки, мы также передаем данные о присланном сообщении
        $params = Array(
            'msg_data' => $data,
            'params' => $res[0]
        );

        // Заменяем поле-вставку в сообщении на конкретнного значение
        $text = preg_replace("~\|\|.+\|\|~", $userActions->makeAction($field_name, $params), $text);

        // Запоминаем ответ
        $data['reply'] = $text;
        // Отправляем сообщение пользователю, при этом считываем и декодируем ответ телеграма
        $keyboard = $tgBot->getKeyboardMarkup($screen->keyboard);
        $answer = json_decode($tgBot->sendHTMLMessage($data['chat_id'], $text, $keyboard));

        // Записываем в логи исходящее сообщение
        // Формируем массив с информацией
        // Некоторые поля уже сформированы, поэтому их не трогаем
        $logs = Array(
            "chat_id" => $logs['chat_id'],
            "chat_name" => $logs['chat_name'],
            "timestamp" =>  Date('Y-m-d H:i', time()),
            "data" => addslashes(json_encode(Array(
                "event" => Array(
                    "type" => "outcoming",
                    "name" => "message"
                ),
                "data" => Array(
                    "message_id" => $answer->result->message_id,
                    "user_id" => $answer->result->from->id,
                    "first_name" => $answer->result->from->first_name,
                    "username" => $answer->result->from->username,
                    "text" => $text,
                    "keyboard" => $keyboard
                )
            )))
        );
        // Отправляем логи в БД
        $db->insertLogs($logs);
    } else { // Иначе перед нами экран активного типа
        // Получаем метод обработки данного экрана
        // Структура метода обработки экрана: '||method_name|param1|..|paramN||'
        // Выделим части запроса при помощи регулярного выражения
        $regexp = "~(?<=\|)[^|]+(?=\|)~";
        $res = Array();
        preg_match_all($regexp, $text, $res);

        // Получаем имя необходимого метода
        // Он лежим в нулевой ячейке массива
        $method_name = $res[0][0];

        // Формируем параметры для метода
        // Помимо параметров метода, мы также передаем данные о присланном сообщении
        $params = Array(
            'msg_data' => $data,
            'params' => $res[0]
        );

        // Запускаем обработку данного экрана
        // Все методы обработки активных экранов имеют всего одну кнопку – "Отмена", которая создается программно
        // и ведет на главный экран
        $userActions->makeAction($method_name, $params);
    }
}
?>
















































