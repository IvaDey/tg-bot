<?php
    class adminActionList
    {
        private $db = NULL;
        private $tgBot = NULL;

        function __construct($db, $tgBot)
        {
            $this->db = $db;
            $this->tgBot = $tgBot;
        }

        // Метод обработки входящего запроса на присвоение статуса новому польователю
        // Параметр $params состоит из двух частей
        // sender – информация об ID чата с админом и сообщения, для его дальнейшего изменения
        // data – параметры из callback_data нажатой админом кнопки
        private function replyToJoinRequest($params)
        {
            // Записываем в логи действие админа
            // А именно присвоение статуса новому пользователю
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['sender']['chat_id'],
                "chat_name" => "Прямой контакт с вождем",
                "timestamp" =>  Date('Y-m-d H:i', time()),
                "data" => addslashes(json_encode(Array(
                    "event" => Array(
                        "type" => "admin_action",
                        "name" => "Присвоение статуса новому пользователю"
                    ),
                    "data" => Array(
                        "message_id" => $params['sender']['message_id'],    // ID сообщения админа
                        "user_id" => $params['data'][0],                    // ID пользователя, которому был присвоен статус
                        "first_name" => $params['data'][2],                 // Имя пользователя, которому был присвоен статус
                        "username" => $params['data'][3],                   // username пользователя, которому был присвоен статус
                        "status" => $params['data'][1]                      // статус, который был присвоен пользователю
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            // Выделяем ID чата и сообщения для последуюдего обновления
            $chat_id = $params['sender']['chat_id'];
            $message_id = $params['sender']['message_id'];

            // Выделяем данные о пользователе и выбранный для него статус
            $user_id = $params['data'][0];
            $status = $params['data'][1];
            $name = $params['data'][2];
            $username = $params['data'][3];

            // Обновляем статус пользователя
            $this->db->updateUserStatus($user_id, $status);

            // Открываем доступ, но только если не получен отказ
            if ($status != 'loser') {
                $this->db->updateScreen($user_id, 1);

                $message = "Здравствуй, коммунист\n\n"
                          ."Я communa bot – твой помощник и гид в Коммуне (пока только на локации  Сити)\n\n"

                          ."Через меня ты можешь забронировать переговорку, заказать пропуск для своих гостей и узнать"
                          ."информацию о Коммуне (пароль от wi-fi, расписание мероприятий и т.д.)\n\n"

                          ."Также, я иногда буду присылать тебе важные новости.";

                $screen = json_decode($this->db->getScreenObj(1));
                $answer = json_decode($keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard));

                $this->db->updateScreen($user_id, 1);
                $this->tgBot->sendHTMLMessage($user_id, $message."\n\n".$screen->message, $keyboard);

                // Записываем в логи исходящее приветственное сообщение
                // Формируем массив с информацией
                $logs = Array(
                    "chat_id" => $user_id,
                    "chat_name" => $name,
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
                            "text" => $message,
                            "keyboard" => $keyboard
                        )
                    )))
                );
                // Отправляем логи в БД
                $this->db->insertLogs($logs);
            } else { // Иначе предлагаем купить участие в коммуне
                $message = "Я вынужден отказать тебе в доступе :(\n\n"
                    . "Покупай участие и возвращайся, я буду ждать тебя!";
                $answer = json_decode($this->tgBot->sendMessage($user_id, $message));

                // Записываем в логи исходящее сообщение с отказом
                // Формируем массив с информацией
                $logs = Array(
                    "chat_id" => $user_id,
                    "chat_name" => $name,
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
                            "text" => $message
                        )
                    )))
                );
                // Отправляем логи в БД
                $this->db->insertLogs($logs);
            }

            // Удаляем у админа клавиатуру выбора статуса для ползователя
            $status == 'loser' ? $status = "послан нахуй" : $status = "участник коммуны";
            $message = "#Заявка\n\n"
                      ."Имя: {$name}\n"
                      ."username: @{$username}\n\n"
                      ."Присвоенный статус: {$status}";
            $this->tgBot->editMessageText($chat_id, $message_id, $message);
            $this->tgBot->hideInlineKeyboard($chat_id, $message_id);

            // По хорошему тоже надо логировать
            // Но я не уверен как лучше это делать, поэтому пока отложенно
            // Тем более это не так принципиально логировать
            // Пока что по крайней мере
        }

        // Данный метод необходим для уведомления пользователя, что админ увидел и заказл пропуск
        // Параметр $params состоит из двух частей
        // sender – информация об ID чата с админом и сообщения, для его дальнейшего изменения
        // data – параметры из callback_data нажатой админом кнопки
        private function replyToPassRequest($params)
        {
            // Выделяем ID чата и сообщения для последуюдего удаления
            $chat_id = $params['sender']['chat_id'];
            $message_id = $params['sender']['message_id'];

            // Выделяем ID пользователя для отправки уведомления о готовности
            $user_id = $params['data'][0];

            // Уведомляем пользователя, что пропуск заказан и будет готов в течении 5 минут
            $msg = "Пропуск будет готов в течении 5 минут";
            $answer = json_decode($this->tgBot->sendMessage($user_id, $msg));

            // Удаляем сообщение у админа, которое необходимо было только для удобного копирования
            $this->tgBot->deleteMessage($chat_id, $message_id);

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $user_id,
                "chat_name" => $answer->result->chat->first_name,
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
                        "text" => $msg
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);
        }

        //--------------------------------------------------------------------------------------------------------------
        // Единственный публичный метод, предназначенный для вызова нужного действия
        //--------------------------------------------------------------------------------------------------------------

        //  На вход получает имя действия и список параметров, если они есть
        function makeAction($actName, $params = NULL)
        {
            // Сперва проверяем есть ли запрашиваемый метод
            // Если его нет, то завершаем работу
            if (!method_exists($this, $actName))
                return;

            // Если метод есть, то проверяем передали ли нам параметры
            // Если да, то передаем их вызываемому методу, иначе вызываем метод без них
            if ($params)
                return $this->$actName($params);
            else return $this->$actName();
        }
    }
?>