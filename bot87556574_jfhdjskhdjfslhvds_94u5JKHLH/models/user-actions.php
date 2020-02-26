<?php
class userActionList
{
    private $db = NULL;
    private $tgBot = NULL;

    function __construct($db, $tgBot)
    {
        $this->db = $db;
        $this->tgBot = $tgBot;
    }

    //--------------------------------------------------------------------------------------------------------------
    // Методы для обработки callback событий (нажатие на inline  кнопку)
    //--------------------------------------------------------------------------------------------------------------
    // Метод обработки бронирования переговорных при помощи inline кнопок
    // Параметр $params состоит из двух частей
    // sender – информация об ID чата и сообщения, для его дальнейшего изменения
    // data – параметры из callback_data нажатой кнопки со следующей струтурой
    // 1-й параметр – location_id
    // 2-й параметр – дата
    // 3-й параметр – время начала брони
    // 4-й параметр – длительность бронирования
    // При этом сам callback_data имеет следующую структуру:
    // inlineReserveBoardroom|location_id|date|start_time|duration
    private function inlineReserveBoardroom($params)
    {
        // Выделяем параметр $location_id
        $location_id = $params['data'][0];

        // Всего может быть три этапа:
        // 1 – мы здесь в первый раз, получили только дату, тогда нам нужно сформировать список часов, в которые
        //     можно забронировать переговорную
        // 2 – мы здесь во второй раз, значит уже имеет дату и время начала бронирования, тогда нам нужно
        //     сформировать доступные варианты длительности бронирования. Всего их может быть до 4-х:
        //     30 минут, 60 минут, 90 минут, 120 минут
        // 3 – мы здесь в третий, заключительный раз. Нам остается только забронировать переговорную и уведомить
        //     пользователя, вернув его на главный экран

        // Определять этап будем исходя из количество параметров
        // Так как я уже заебался, то воспользуемся метом custom_query там, где нужно будет работать с БД

        // Если параметро два, то мы на первом этапе
        if (count($params['data']) == 2) {
            // Получаем выбранную дату
            $date = $params['data'][1];

            // Создадим пустой массив, в который добавим все часы, в которые можно забронировать переговорные
            $hours = Array();

            // Проверяем какие час мы можем вывести
            // Для этого проверяем каждый час и смотрим, можем ли мы забронировать переговорную хотя бы на пол часа
            // Также дополнительное условие в том, что переговорку можно забронировать не менее, чем за 25 минут
            for ($i = 7; $i < 23; $i++)
            {
                // Формируем интервал в пол часа – минимальное время бронирования
                $timeRange = Array(
                    'start' => Date('Y-m-d H:i', strtotime($date." {$i}:00")),
                    'end' => Date('Y-m-d H:i', strtotime($date." {$i}:30"))
                );

                // Если можно забронировать на минимальный интервал (все интервалы будем проверять на
                // следующем этапе), то запоминаем этот час
                if ($this->db->isLocationFreeOnDate($location_id, $date, $timeRange) &&
                    $timeRange['start']>= Date('Y-m-d H:i', time()+25*60)) {
                    array_push($hours, $i.':00');
                }
            }

            // Формируем inline клавиатуру с доступными часами
            $i = 0;
            $inline_keyboard = Array();
            while ($hours[$i]) {
                $j = 0;
                $ind = array_push($inline_keyboard, Array()) - 1;
                while ($hours[$i] && $j < 4) {
                    array_push($inline_keyboard[$ind], Array(
                        'text' => $hours[$i],
                        'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|$hours[$i]"
                    ));
                    $i++; $j++;
                }
            }

            // Формируем объект inline_keyabord для телеграм
            $inline_keyboard = json_encode(Array('inline_keyboard' => $inline_keyboard));

            // Получаем ID чата с пользователем и сообщения, которое необходимо изменить
            $chat_id = $params['sender']['chat_id'];
            $message_id = $params['sender']['message_id'];

            // Формируем сообщение у пользователя
            $msg = "Отлично, теперь выбери время.";

            // Меняем сообщение у пользователя
            $answer = json_decode($this->tgBot->editMessageText($chat_id, $message_id, $msg, $inline_keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $chat_id,
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
                        "text" => $msg,
                        "keyboard" => $inline_keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            // Завершаем работу
            return;
        }

        // Если параметро три, то мы на втором этапе
        if (count($params['data']) == 3) {
            // Получаем выбранную дату
            $date = $params['data'][1];
            // Получаем выбранное время начала брони
            $start_time = $params['data'][2];

            // Создаем заготовку под клавиатуру
            $inline_keyboard = NULL;

            // Проверим сперва можно ли забронировать на два часа
            // Если можно на 2 часа, то не меньшее время тем более
            // Сперва сформируем временной интервал
            // Формируем интервал 2 часа
            $timeRange = Array(
                'start' => Date('Y-m-d H:i', strtotime($date." ".$start_time)),
                'end' => Date('Y-m-d H:i', strtotime($date." ".($start_time + 2).":00"))
            );
            // Проверяем можно ли забронировать переговорную на 2 часа
            // Если можно, то формируем сообщение для пользователя и inline клавиатуру
            // с 4-мя интервалами для бронирования
            if ($this->db->isLocationFreeOnDate($location_id, $date, $timeRange)) {
                // формируем inline клавиатуру с вариантами продолжительности бронирования
                $inline_keyboard = Array(
                    // Первый ряд кнопок
                    Array(
                        Array(
                            'text' => '30 минут',
                            'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|30"
                        ),
                        Array(
                            'text' => '1 час',
                            'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|60"
                        )
                    ),
                    // Второй ряд кнопок
                    Array(
                        Array(
                            'text' => '1 час 30 минут',
                            'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|90"
                        ),
                        Array(
                            'text' => '2 часа',
                            'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|120"
                        )
                    )
                );
            } else {
                // Иначе, если нельзя на 2 часа, то првоерим возможность брони на полтора

                // Формируем интервал в полтора часа
                $timeRange = Array(
                    'start' => Date('Y-m-d H:i', strtotime($date . " " . $start_time)),
                    'end' => Date('Y-m-d H:i', strtotime($date . " " . ($start_time + 1) . ":30"))
                );
                // Проверяем можно ли забронировать переговорную на полтора часа
                // Если можно, то формируем сообщение для пользователя и inline клавиатуру
                // с 3-мя интервалами для бронирования
                if ($this->db->isLocationFreeOnDate($location_id, $date, $timeRange)) {
                    //   Формируем сообщение для пользователя
                    $msg = "Какая длительность бронирования тебе нужна?";

                    // формируем inline клавиатуру с вариантами продолжительности бронирования
                    $inline_keyboard = Array(
                        // Первый ряд кнопок
                        Array(
                            Array(
                                'text' => 'inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|30 минут',
                                'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|30"
                            ),
                            Array(
                                'text' => '1 час',
                                'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|60"
                            )
                        ),
                        // Второй ряд кнопок
                        Array(
                            Array(
                                'text' => '1 час 30 минут',
                                'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|90"
                            )
                        )
                    );
                } else {
                    // Иначе, если и на полтора часа нельзя, то проверим интервал в один час

                    // Формируем интервал в 1 час
                    $timeRange = Array(
                        'start' => Date('Y-m-d H:i', strtotime($date . " " . $start_time)),
                        'end' => Date('Y-m-d H:i', strtotime($date . " " . ($start_time + 1) . ":00"))
                    );
                    // Проверяем можно ли забронировать переговорную на полтора часа
                    // Если можно, то формируем сообщение для пользователя и inline клавиатуру
                    // с 3-мя интервалами для бронирования
                    if ($this->db->isLocationFreeOnDate($location_id, $date, $timeRange)) {
                        //   Формируем сообщение для пользователя
                        $msg = "Какая длительность бронирования тебе нужна?";

                        // формируем inline клавиатуру с вариантами продолжительности бронирования
                        $inline_keyboard = Array(
                            // Первый ряд кнопок
                            Array(
                                Array(
                                    'text' => '30 минут',
                                    'callback_data' => "3inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|30"
                                )
                            ),
                            // Второй ряд кнопок
                            Array(
                                Array(
                                    'text' => '1 час',
                                    'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|60"
                                )
                            )
                        );
                    } else {
                        // Иначе нам остается только вариант на пол часа, поэтому формируем соотвествующую
                        // inline клавиатуру с одной кнопкой
                        $inline_keyboard = Array(
                            // Первый ряд кнопок
                            Array(
                                Array(
                                    'text' => '30 минут',
                                    'callback_data' => "inlineReserveBoardroom|{$location_id}|{$date}|{$start_time}|30"
                                )
                            )
                        );
                    }
                }
            }

            // Формируем сообщение для пользователя
            $msg = "Какая длительность бронирования тебе нужна?";

            // Преобразовавыем нашу клавиатуру в объект inline_keyboard
            $inline_keyboard = json_encode(Array('inline_keyboard' => $inline_keyboard));

            // Получаем ID чата с пользователем и сообщения, которое необходимо изменить
            $chat_id = $params['sender']['chat_id'];
            $message_id = $params['sender']['message_id'];

            // Меняем сообщение у пользователя
            $answer = json_decode($this->tgBot->editMessageText($chat_id, $message_id, $msg, $inline_keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $chat_id,
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
                        "text" => $msg,
                        "keyboard" => $inline_keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            // Завершаем работу
            return;
        }

        // Если параметро четыре, то мы на третьем этапе
        if (count($params['data']) == 4) {
            // Получаем дату бронированя
            $date = $params['data'][1];

            // Получаем время бронирования
            $start_time = $params['data'][2];

            // Получаем длительность бронирования
            $duration = $params['data'][3];

            // Формируем дату и время конца брони
            $end_time = Date('Y-m-d H:i', strtotime($date." ".$start_time) + $duration*60);

            // Формируем дату и время начала брони
            $start_time = Date('Y-m-d H:i', strtotime($date." ".$start_time));

            // Формируем данные для внесения бронирования в БД
            $reservationInfo = Array(
                'location_id' => $location_id,
                'user_id' => $params['sender']['chat_id'],
                'start_time' => $start_time,
                'end_time' => $end_time
            );
            // Бронируем
            $this->db->reserveBoardroom($reservationInfo);
            // Делаем запрос на бронь к API веб-приложения
//            $url = "https://app.beat-me.ru/admin/reserve_location?";
//            $params = Array(
//                'creator' => 'telegram_bot',
//                'for_user' => '@username',
//                'location_id' => $location_id,
//                'start_date' => $start_time,
//                'end_date' => $end_time,
//                'token' => 'pizdec'
//            );
//            $params = http_build_query($params);
//            file_get_contents($url . $params);

            // Уведомляем пользователя и возвращаем его на главный экран
            // Формируем сообщение для пользователя
            $msg = "Переговорная успешно забронированна.";

            // Скрываем inline клавиатуру
            // Удаляем сообщение с inline клавиатурой
            $this->tgBot->deleteMessage($params['sender']['chat_id'], $params['sender']['message_id']);

            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['sender']['chat_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него клавиатуру
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // Отправляем пользователю уведомление о принятии запроса
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['sender']['chat_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['sender']['chat_id'],
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
                        "text" => $msg,
                        "keyboard" => $keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            return;
        }

    }

    // Обраотка кнопок для работы со списком резидентов
    // Параметр $params состоит из двух частей
    // sender – информация об ID чата и сообщения, для его дальнейшего изменения
    // data – параметры из callback_data нажатой кнопки со следующей струтурой
    // 1-й параметр – location_id
    // 2-й параметр – дата
    // или один параметр "-1" – флаг того, что нам пора выходить в главное меню
    // При этом сам callback_data имеет следующую структуру:
    // getOther|start|count
    // или
    // getOther|-1
    private function getOtherResidents($params)
    {
        // Сперва проверим не пора ли нам выходить
        if ($params['data'][0] == -1) {
            // Скрываем inline клавиатуру
            $this->tgBot->hideInlineKeyboard($params['sender']['chat_id'], $params['sender']['message_id']);

            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['sender']['chat_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него сообщение и клавиатуру
            $msg = $screen->message;
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // Отправляем пользователю сообщение
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['sender']['chat_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['sender']['chat_id'],
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
                        "text" => $msg,
                        "keyboard" => $keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            return;
        }

        // Иначе обновляем страницу
        // Стартовую позицию и количество
        $start = $params['data'][0];
        $count = $params['data'][1];

        // Получаем резидентов коммуны
        $residents = json_decode($this->db->getResidents($start, $count), true);
        // Формируем сообщение со списком резидентов
        // Будем выводить по 5 участников за раз
        $msg = "*Наши резиденты:*\n\n";
        foreach ($residents as $resident) {
            $msg .= "<b>Имя:</b> {$resident['name']}\n";
            $msg .= "<b>username:</b> @{$resident['username']}\n";
            $msg .= "<b>Компетенции:</b> {$resident['description']}\n\n";
        }

        // Формируем номера предыдущей и следующей страниц
        $prev = $start - $count;
        $next = $start + $count;

        // Формируем inline клавиатуру в зависимости от полученных значений
        // Если $start < 0, значит назад нельзя, аналогично нельзя вперед,
        // если count($residents) > $count, иначе можно в оба направления
        if ($prev < 0) {
            $inlineKeyboard = Array(
                Array(
                    Array(
                        "text" => "Дальше",
                        "callback_data" => "getOtherResidents|{$next}|{$count}"
                    )
                ),
                Array(
                    Array(
                        "text" => "Главное меню",
                        "callback_data" => "getOtherResidents|-1"
                    )
                )
            );
        } else if (count($residents) < $count) {
            $inlineKeyboard = Array(
                Array(
                    Array(
                        "text" => "Назад",
                        "callback_data" => "getOtherResidents|{$prev}|{$count}"
                    )
                ),
                Array(
                    Array(
                        "text" => "Главное меню",
                        "callback_data" => "getOtherResidents|-1"
                    )
                )
            );
        } else {
            $inlineKeyboard = Array(
                Array(
                    Array(
                        "text" => "Назад",
                        "callback_data" => "getOtherResidents|{$prev}|{$count}"
                    ),
                    Array(
                        "text" => "Дальше",
                        "callback_data" => "getOtherResidents|{$next}|{$count}"
                    )
                ),
                Array(
                    Array(
                        "text" => "Главное меню",
                        "callback_data" => "getOtherResidents|-1"
                    )
                )
            );
        }
        $inlineKeyboard = json_encode(Array("inline_keyboard" => $inlineKeyboard));

        $answer = json_decode($this->tgBot->editMessageText($params['sender']['chat_id'], $params['sender']['message_id'], $msg, $inlineKeyboard));

        // Записываем в логи исходящее сообщение
        // Формируем массив с информацией
        $logs = Array(
            "chat_id" => $params['sender']['chat_id'],
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
                    "text" => $msg,
                    "keyboard" => $inlineKeyboard
                )
            )))
        );
        // Отправляем логи в БД
        $this->db->insertLogs($logs);
    }

    //--------------------------------------------------------------------------------------------------------------
    // Методы для обработки активных экранов
    //--------------------------------------------------------------------------------------------------------------

    // Метод запроса пропусков для своих гостей
    // params состоит из двух частей
    // msg_data – массив с информацией о сообщении, которое прислал пользователь
    // params – массив параметров для метода, в нулевой ячейки которого всегда хранится имя метода
    // Сами параметры начинаются с первой ячейки
    // В данном случае они будут пустые, так как достаточно сообщения
    private function askPass($params)
    {
        // Сперва проверим что в сообщении, которое нам прислал пользователь
        // Если это фраза "Заказ пропуска", то мы сюда попали в первый раз по нажатию на кнопку, поэтому
        // отправляем пользователю иснтрукцию и кнопку отмена
        if ($params['msg_data']['message_body'] == "Заказ пропуска") {
            $msg = "Пришли список с полными Ф.И.О. людей для которых тебе необходим пропуск.";

            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $this->createCancelButton()));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $this->createCancelButton()
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            return;
        }

        // Если мы здесь не первый раз, то пользователь прислал либо список имен, либо нажал кнопку отмена
        if ($params['msg_data']['message_body'] == "Отменить") {
            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['msg_data']['user_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него сообщение и клавиатуру
            $msg = $screen->message;
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // И отправляем пользователю
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);
        } else { // Иначе нам прислали список имен для заказа пропусков
            // Получаем имя пользователя, его username и список Ф.И.О. гостей
            $firstName = $params['msg_data']['first_name'];
            $username = $params['msg_data']['username'];
            $guestList = $params['msg_data']['message_body'];

            // Формируем уведомление для админа
            $notify = "<b>Новый запрос на пропуск</b>\n\n";
            $notify .= "Пропуск заказал: {$firstName}\n";
            $notify .= "username: @{$username}\n";
            $notify .= "На имена:\n{$guestList}";

            // Получаем ID админа и отправляем ему уведомление
            $admin = $this->db->getAdminID();
            $answer = json_decode($this->tgBot->sendHTMLMessage($admin, $notify));

            // Записываем в логи уведомление для админа о новом заказе пропуска
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $admin,
                "chat_name" => "Прямой контакт с вождем",
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
                        "text" => $notify
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            // Формируем уведомление только со списком Ф.И.О. и кнопкой для уведомления
            // Для начала создаем клавиатуру с вариантами статуса
            $inlineKeyboard = Array(
                // Первый ряд кнопок
                Array(
                    Array(
                        'text' => 'Пропуск заказн',
                        'callback_data' => "replyToPassRequest|{$params['msg_data']['user_id']}"
                    )
                )
            );

            // Преобразовавыем в объект inline_keyboard
            $inlineKeyboard = json_encode(Array('inline_keyboard' => $inlineKeyboard));
            // Отправляем сообщение админу
            $this->tgBot->sendHTMLMessage($admin, $guestList, $inlineKeyboard);

            // Записываем в логи второе уведомление для админа с inline кнопкой подвтверждения заказа пропуска
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $admin,
                "chat_name" => "Прямой контакт с вождем",
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
                        "text" => $guestList,
                        "keyboard" => $inlineKeyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            // Возвращаем на главный экран
            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['msg_data']['user_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него клавиатуру
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // Формируем сообщение для пользователя
            $msg = "Запрос принят, ожидайте подтверждения";

            // Отправляем пользователю уведомление о принятии запроса
            $this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard);

            // Записываем в логи исходящее сообщение для пользователя
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            return;
        }
    }

    // Метод для обработки экрана бронирования переговорных
    // Есть два варианта бронирования переговорных: при помощи сообщения с полной датой и временным
    // интервалом брони и упрощенный при помощи inline кнопок. Данный метод обрабатываем только первый вариант
    // второй вариант обрабатываем методом inlineReserveBoardroom, который в разделе обработки callback событий
    // params состоит из двух частей
    // msg_data – массив с информацией о сообщении, которое прислал пользователь
    // params – массив параметров для метода, в нулевой ячейки которого всегда хранится имя метода
    // Сами параметры начинаются с первой ячейки
    // В данном случае будет всего один параметр – ID локации
    private function reserveBoardroom($params)
    {
        // Сперва проверим что в сообщении, которое нам прислал пользователь
        // Если это фраза "Забронировать", то мы сюда попали в первый раз по нажатию на кнопку, поэтому
        // отправляем пользователю иснтрукцию и кнопку отмена
        if ($params['msg_data']['message_body'] == "Забронировать") {
            // Создаем стартовые inline кнопки для упрощенного бронирования с выбором даты (ближайшие 5 дней)
            $en_to_ru = Array(
                'Jan' => 'январь',
                'Feb' => 'февраль',
                'Mar' => 'март',
                'Apr' => 'апрель',
                'May' => 'май',
                'Jun' => 'июнь',
                'Jul' => 'июль',
                'Aug' => 'август',
                'Sep' => 'сентябрь',
                'Oct' => 'октябрь',
                'Nov' => 'ноябрь',
                'Dec' => 'декабрь'
            );
            $one_day = 86400;
            $inline_keyboard = Array(
                Array(
                    Array(
                        'text' => $en_to_ru[Date('M', time())].", ".Date('j', time()),
                        'callback_data' => "inlineReserveBoardroom|{$params['params'][1]}|".Date('Y-m-d', time())
                    ),
                    Array(
                        'text' => $en_to_ru[Date('M', time() + $one_day)].", ".Date('j', time() + $one_day),
                        'callback_data' => "inlineReserveBoardroom|{$params['params'][1]}|".Date('Y-m-d', time() + $one_day)
                    )
                ),
                Array(
                    Array(
                        'text' => $en_to_ru[Date('M', time() + $one_day*2)].", ".Date('j', time() + $one_day*2),
                        'callback_data' => "inlineReserveBoardroom|{$params['params'][1]}|".Date('Y-m-d', time() + $one_day*2)
                    ),
                    Array(
                        'text' => $en_to_ru[Date('M', time() + $one_day*3)].", ".Date('j', time() + $one_day*3),
                        'callback_data' => "inlineReserveBoardroom|{$params['params'][1]}|".Date('Y-m-d', time() + $one_day*3)
                    )
                ),
                Array(
                    Array(
                        'text' => $en_to_ru[Date('M', time() + $one_day*4)].", ".Date('j', time() + $one_day*4),
                        'callback_data' => "inlineReserveBoardroom|{$params['params'][1]}|".Date('Y-m-d', time() + $one_day*4)
                    )
                )
            );
            $inline_keyboard = json_encode(Array('inline_keyboard' => $inline_keyboard));

            // Формируем описание второго варианта бронирования при помощи inline кнопок
            $msg = "Для начала выбери дату, потом тебе надо будет выбрать время начала бронирования и длительность.\n";
            $msg .= "Учти, что длительность одного бронирования огранченна 2-мя часами.";

            // Отправляем его вместе с inline кнопками для упрощенного бронирования
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $inline_keyboard));

            // Записываем в логи исходящее сообщение с информацией о втором варианте бронирования
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $inline_keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            return;
        }

        // Если мы здесь не первый раз, то пользователь прислал либо время брони, либо нажал кнопку отмена
        if ($params['msg_data']['message_body'] == "Отменить") {
            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['msg_data']['user_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него сообщение и клавиатуру
            $msg = $screen->message;
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // И отправляем пользователю
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);
        } else { // Иначе нам прислали время брони
            // Получаем ID локации
            $location_id = $params['params'][1];
            // Получаем сообщение пользователя
            $text = $params['msg_data']['message_body'];

            // Формируем регулярное выражение, которое будет проверять, что пользователь прислал только дату и в корректном формате
            $regexp = "/^([0-3][0-9]-[0-1][0-9]-20[0-9][0-9]) ([0-2][0-9]:[0-5][0-9]) ?- ?([0-2][0-9][ ]{0,1}:(| )[0-5][0-9])$/";
            // Создаем пустой массив заготовку, в который будут записаны группы времени бронирования (дата и временной интервал)
            $res = Array();

            // Проверяем соответствует ли присланное сообщение нашему шаблону
            // Если да, то попробуем заюронировать
            if (preg_match($regexp, $text, $res)) {
                // Получаем дату
                $date = $res[1];
                // Получаем время начала брони
                $start_time = $res[2];
                // Получаем время окончания брони
                $end_time = $res[3];

                // Формируем дату в формате, который необходим для БД
                $date = date('Y-m-d', strtotime($date . ' ' . $start_time));
                // Формируем время начала брони в формате, который необходим для БД
                $start_time = date('Y-m-d H:i', strtotime($date . ' ' . $start_time));
                // Формируем время окончания брони в формате, который необходим для БД
                $end_time = date('Y-m-d H:i', strtotime($date . ' ' . $end_time));

                // Формируем временной интервал бронирования
                $timeRange = Array(
                    'start' => $start_time,
                    'end' => $end_time
                );

                // Проверяем свободна ли переговорная в это время
                // Если нет, то уведомляем об этом пользователя
                if (!$this->db->isLocationFreeOnDate($location_id, $date, $timeRange)) {
                    // Формируем сообщение
                    $msg = "Время на которое ты хочешь забронировать, к сожалению, пересекается с другой бронью";
                    // И отправляем пользователю
                    $answer = json_decode($this->tgBot->sendMessage($params['msg_data']['user_id'], $msg));

                    // Записываем в логи исходящее сообщение с информацией о том, что данное время занято
                    // Формируем массив с информацией
                    $logs = Array(
                        "chat_id" => $params['msg_data']['user_id'],
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

                    // Завершаем работу
                    return;
                }

                // Иначе, если переговорная свободна, то бронируем ее и отправляем сообщение с подтверждением пользователю
                // Формируем данные для внесения бронирования в БД
                $reservationInfo = Array(
                    'location_id' => $location_id,
                    'user_id' => $params['msg_data']['user_id'],
                    'start_time' => $start_time,
                    'end_time' => $end_time
                );
                // Бронируем
                $this->db->reserveBoardroom($reservationInfo);
                // Делаем запрос на бронь к API веб-приложения
                $url = "https://app.beat-me.ru/admin/reserve_location?";
                $params = Array(
                    'creator' => 'telegram_bot',
                    'for_user' => '@username',
                    'location_id' => $location_id,
                    'start_date' => $start_time,
                    'end_date' => $end_time,
                    'token' => 'pizdec'
                );
                $params = http_build_query($params);
                file_get_contents($url . $params);

                // Формируем уведомление для пользователя
                $msg = "Переговорная забронированна";

                // Устанавливаем пользователю первый экран (главный с меню)
                $this->db->updateScreen($params['msg_data']['user_id'], 1);

                // Получаем объект первого экрана
                $screen = json_decode($this->db->getScreenObj(1));

                // Берем из него клавиатуру
                $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

                // И отправляем пользователю
                $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard));

                // Записываем в логи исходящее сообщение
                // Формируем массив с информацией
                $logs = Array(
                    "chat_id" => $params['msg_data']['user_id'],
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
                            "text" => $msg,
                            "keyboard" => $keyboard
                        )
                    )))
                );
                // Отправляем логи в БД
                $this->db->insertLogs($logs);

                // Завершаем работу
                return;
            }

            // Иначе уведомляем его, что нельзя отменять чужие брони
            $msg = "Уважаемый, я же тебе сказал в каком формате присылать мне время бронирования! А ты? ";
            $msg .= "Ну зачем же ты так со мной? У меня и так много работы, чтобы отвлекаться на такое.";
            $answer = json_decode($this->tgBot->sendMessage($params['msg_data']['user_id'], $msg));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
    }

    // Метод для отмены ранее созданной пользователем брони
    // params состоит из двух частей
    // msg_data – массив с информацией о сообщении, которое прислал пользователь
    // params – массив параметров для метода, в нулевой ячейки которого всегда хранится имя метода
    // Сами параметры начинаются с первой ячейки
    // В данном случае они будут пустые, так как достаточно сообщения
    private function cancelReservation($params)
    {
        // Сперва проверим что в сообщении, которое нам прислал пользователь
        // Если это фраза "Отменить бронь", то мы сюда попали в первый раз по нажатию на кнопку, поэтому
        // отправляем пользователю иснтрукцию и кнопку отмена
        if ($params['msg_data']['message_body'] == "Отменить бронь") {
            $msg = "Пришли мне номер брони, которую ты хочешь отменить.";

            $answer = json_decode($this->tgBot->sendMessage($params['msg_data']['user_id'], $msg, $this->createCancelButton()));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $this->createCancelButton()
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            return;
        }

        // Если мы здесь не первый раз, то пользователь прислал либо номер брони, либо нажал кнопку отмена
        if ($params['msg_data']['message_body'] == "Отменить") {
            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['msg_data']['user_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него сообщение и клавиатуру
            $msg = $screen->message;
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // И отправляем пользователю
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $this->createCancelButton()
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);
        } else { // Иначе нам прислали номер брони
            // Получаем номер брони
            $reservation_id = $params['msg_data']['message_body'];

            // Отменяем бронь
            // Помимо номера брони передаем и ID пользователя, чтобы избежать отмены чужой брони
            $res = $this->db->cancelReservation($reservation_id, $params['msg_data']['user_id']);

            // Если БД вернула строку "not found", то уведомляем об этом пользователя
            if ($res == "not found") {
                $msg = "Такой брони нет в БД\n"
                    ."Будь внимательнее";
                $answer = json_decode($this->tgBot->sendMessage($params['msg_data']['user_id'], $msg));

                // Записываем в логи исходящее уведомление об ошибке в номере брони
                // Формируем массив с информацией
                $logs = Array(
                    "chat_id" => $params['msg_data']['user_id'],
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

                return;
            }

            // Если бронь отменена успешно, то уведомляем об этом пользователя и возвращаем его на главный экран
            if ($res) {
                // Возвращаем пользователя на главный экран
                // Устанавливаем пользователю первый экран (главный с меню)
                $this->db->updateScreen($params['msg_data']['user_id'], 1);

                // Получаем объект первого экрана
                $screen = json_decode($this->db->getScreenObj(1));

                // Берем из него клавиатуру
                $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

                // Формируем уведомлея для пользователя об отмене брони
                $msg = "*Бронь успешно отменена*";

                // И отправляем пользователю
                $answer = json_decode($this->tgBot->sendMarkdownMessage($params['msg_data']['user_id'], $msg, $keyboard));

                // Записываем в логи исходящее уведомление об успешной отмене брони
                // Формируем массив с информацией
                $logs = Array(
                    "chat_id" => $params['msg_data']['user_id'],
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
                            "text" => $msg,
                            "keyboard" => $keyboard
                        )
                    )))
                );
                // Отправляем логи в БД
                $this->db->insertLogs($logs);

                return;
            }

            // Иначе уведомляем его, что нельзя отменять чужие брони
            $msg = "Блядь, ну нельзя же так – это не твоя бронь";
            $answer = json_decode($this->tgBot->sendMessage($params['msg_data']['user_id'], $msg));

            // Записываем в логи исходящее уведомление, что чужие брони нельзя отменять
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
    }

    // Метод запроса списка резидентов и краткой информации о них. Работать со списком можно будет при
    // помощи inline кнопок
    // params состоит из двух частей
    // msg_data – массив с информацией о сообщении, которое прислал пользователь
    // params – массив параметров для метода, в нулевой ячейки которого всегда хранится имя метода
    // Сами параметры начинаются с первой ячейки
    // В данном случае они будут пустые, так как достаточно сообщения
    private function getResidents($params)
    {
        // Сперва проверим что в сообщении, которое нам прислал пользователь
        // Если это фраза "Резиденты", то мы сюда попали в первый раз по нажатию на кнопку, поэтому
        // отправляем пользователю иснтрукцию и inline кнопки, для работы со списком резидентов
        if ($params['msg_data']['message_body'] == "Резиденты") {
            // Получаем резидентов коммуны
            $residents = json_decode($this->db->getResidents(0, 5), true);
            // Формируем сообщение со списком резидентов
            // Будем выводить по 5 участников за раз
            $msg = "<b>Наши резиденты:</b>\n\n";
            foreach ($residents as $resident) {
                $msg .= "<b>Имя:</b> {$resident['name']}\n";
                $msg .= "<b>username:</b> @{$resident['username']}\n";
                $msg .= "<b>Компетенции:</b> {$resident['description']}\n\n";
            }

            // Формируем inline клавиатуру
            $inlineKeyboard = Array(
                Array(
                    Array(
                        "text" => "Дальше",
                        "callback_data" => "getOtherResidents|5|5"
                    )
                ),
                Array(
                    Array(
                        "text" => "Главное меню",
                        "callback_data" => "getOtherResidents|-1"
                    )
                )
            );
            $inlineKeyboard = json_encode(Array("inline_keyboard" => $inlineKeyboard));

            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $inlineKeyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $inlineKeyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);
        }
    }

    // Метод для обработки горячей линии
    private function hotLine($params)
    {
        // Сперва проверим что в сообщении, которое нам прислал пользователь
        // Если это фраза "Горячия линия", то мы сюда попали в первый раз по нажатию на кнопку, поэтому
        // отправляем пользователю иснтрукцию
        if ($params['msg_data']['message_body'] == "Горячая линия") {
            $msg = "<b>Горячая линия</b>\n\n";

            $msg .= "<i>Опиши свой вопрос, проблему или предложение одним сообщением и отправь мне. Мы его рассмотрим и ответим тебе :)</i>";

            // Формируем кнопку для отмены
            $keyboard = $this->createCancelButton();

            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $keyboard
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            return;
        }

        // Если мы здесь не первый раз, то пользователь прислал либо прислал свое сообщение, либо нажал кнопку отмена
        if ($params['msg_data']['message_body'] == "Отменить") {
            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['msg_data']['user_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него сообщение и клавиатуру
            $msg = $screen->message;
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // И отправляем пользователю
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $this->createCancelButton()
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);
        } else {
            // Иначе пользователь прислал нам сообщение, которое необходимо обработать
            // И уведомить администраторов
            $msg = "Сообщение принято, в ближайшее с тобой свяжется один из наших администраторов.\n\n";
            $msg .= "А пока можешь продолжить пользоваться мной 🙃";

            // Устанавливаем пользователю первый экран (главный с меню)
            $this->db->updateScreen($params['msg_data']['user_id'], 1);

            // Получаем объект первого экрана
            $screen = json_decode($this->db->getScreenObj(1));

            // Берем из него клавиатуру
            $keyboard = $this->tgBot->getKeyboardMarkup($screen->keyboard);

            // И отправляем пользователю
            $answer = json_decode($this->tgBot->sendHTMLMessage($params['msg_data']['user_id'], $msg, $keyboard));

            // Записываем в логи исходящее сообщение
            // Формируем массив с информацией
            $logs = Array(
                "chat_id" => $params['msg_data']['user_id'],
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
                        "text" => $msg,
                        "keyboard" => $this->createCancelButton()
                    )
                )))
            );
            // Отправляем логи в БД
            $this->db->insertLogs($logs);

            // А теперь делаем рассылку по админам и заносим все в логи
            $admins = $this->db->getAdmins();

            $msg = "<b>Новое обращение</b>\n\n";
            $msg .= "<b>Обратился: </b>" . $params['msg_data']['first_name'] . "\n";
            $msg .= "<b>Username: </b>@" . $params['msg_data']['username'] . "\n\n";

            $msg .= "<pre>" . $params['msg_data']['message_body'] . "</pre>";
            while ($admin = $admins->fetch_object()) {
                $answer = json_decode($this->tgBot->sendHTMLMessage($admin->user_id, $msg));

                // Записываем в логи исходящее сообщение
                // Формируем массив с информацией
                $logs = Array(
                    "chat_id" => $params['msg_data']['user_id'],
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
                            "text" => $msg,
                            "keyboard" => $this->createCancelButton()
                        )
                    )))
                );
                // Отправляем логи в БД
                $this->db->insertLogs($logs);
            }
        }
    }

    // Создание кнопки для активных экранов
    // Создается только одна кнопка "Отменить" для выхода из такого экрана и открытия главного меню
    private function createCancelButton()
    {
        return $this->tgBot->getKeyboardMarkup(Array(
            Array(
                Array('text' => 'Отменить')
            )
        ));
    }

    //--------------------------------------------------------------------------------------------------------------
    // Методы для обработки комбинированных экранов, а именно вычисление полей-вставок
    //--------------------------------------------------------------------------------------------------------------

    // Построение списка бронирований заданной переговорной
    // params состоит из двух частей
    // msg_data – массив с информацией о сообщении, которое прислал пользователь
    // params – массив параметров для метода, в нулевой ячейки которого всегда хранится имя метода
    // Сами параметры начинаются с первой ячейки
    // В данном случае будет один параметр – ID локации
    private function getReservationList($params)
    {
        // Получаем location_id
        $location_id = $params['params'][1];

        // Получаем записи о всех бронированиях заданной переговорной из БД
        $reservationList = $this->db->getReservationList($location_id);

        // Если БД ничего не вернула, значит записей нет и данную переговорную никто не бронировал
        // Поэтому сразу возвращаем ответное сообщение с информацией об этом
        if (!$reservationList)
            return "Переговорную № {$location_id} никто не забронировал";

        // Иначе преобразовываем записи в объект
        $reservationList = json_decode($reservationList);
        // Создаем заготовку списка
        $messageList = "";

        // Проходимся по всем записям и добавляем их в список, формируя одну большую строку
        // с информацией о всех бронированиях
        foreach ($reservationList as $reservation)
        {
            $messageList .= "Номер брони: ".$reservation->reservation_id."\n";
            $messageList .= "Имя коммуниста: ".$reservation->name."\n";
            $messageList .= "Ник коммуниста: ".$reservation->username."\n";
            $messageList .= "Дата: ".$reservation->date."\n";
            $messageList .= "Время: ".$reservation->time_range."\n\n";
        }

        // Возвращаем результат
        return $messageList;
    }

    // Построение списка бронирований заданного пользователя
    // params состоит из двух частей
    // msg_data – массив с информацией о сообщении, которое прислал пользователь
    // params – массив параметров для метода, в нулевой ячейки которого всегда хранится имя метода
    // Сами параметры начинаются с первой ячейки
    // В данном случае будет один параметр – ID пользователя
    private function getUserReservationList($params)
    {
        // Получаем user_id
        $user_id = $params['params'][1];

        // Получаем записи о всех бронированиях заданного пользователя
        $reservationList = $this->db->getUserReservation($user_id);

        // Если БД ничего не вернула, значит у пользователя нет активных броней
        // Поэтому сразу возвращаем ответное сообщение с информацией об этом
        if (!$reservationList)
            return "На данный момент у тебя нет активных броней";

        // Иначе преобразовываем записи в объект
        $reservationList = json_decode($reservationList);
        // Создаем заготовку списка
        $messageList = "";

        // Проходимся по всем записям и добавляем их в список, формируя одну большую строку
        // с информацией о всех бронированиях
        foreach ($reservationList as $reservation)
        {
            $messageList .= "Номер брони: ".$reservation->reservation_id."\n";
            $messageList .= "Локация: ".$reservation->location."\n";
            $messageList .= "Дата: ".$reservation->date."\n";
            $messageList .= "Время: ".$reservation->time_range."\n\n";
        }

        // Возвращаем результат
        return $messageList;
    }

    //--------------------------------------------------------------------------------------------------------------
    // Прочие методы
    //--------------------------------------------------------------------------------------------------------------

    // Данный метод добавляет нового пользователя в БД и отправляет админу запрос на присвоение ему статуса
    // По умолчанию присваевается статус гостя
    // $params – массив, содержащий информацию о пользователе и сообщение, которое он прислал
    private function askToJoin($params)
    {
        // Выделяем данные для регистрации нового пользователя
        // И отправляем запрос к БД на регистрацию
        $newUser = Array(
            'user_id' => $params['user_id'],
            'name' => $params['first_name'],
            'username' => $params['username'],
            'screen_id' => 0
        );
        $this->db->registerNewUser($newUser);

        // Формируем и отправляем ответ пользователю
        $msg = "Привет, {$params['first_name']}.\n\n"
            ."Я работаю только с резидентами коммуны. Сейчас я проверю являешься ли ты нашим участником и напишу тебе, что делать дальше.";
        $answer = json_decode($this->tgBot->sendMessage($params['chat_id'], $msg));

        // Записываем в логи исходящее сообщение
        // Формируем массив с информацией
        // Некоторые поля уже сформированы, поэтому их не трогаем
        $logs = Array(
            "chat_id" => $params['user_id'],
            "chat_name" => $params['first_name'],
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

        // Формируем сообщение для админа
        // Для начала создаем клавиатуру с вариантами статуса
        $inlineKeyboard = Array(
            // Первый ряд кнопок
            Array(
                Array(
                    'text' => 'Участник',
                    'callback_data' => "replyToJoinRequest|{$params['user_id']}|member|{$newUser['name']}|{$newUser['username']}"
                )
            ),
            // Второй ряд кнопок
            Array(
                Array(
                    'text' => 'Послать нахуй',
                    'callback_data' => "replyToJoinRequest|{$params['user_id']}|loser|{$newUser['name']}|{$newUser['username']}"
                )
            )
        );

        // Преобразовавыем в объект inline_keyboard
        $inlineKeyboard = json_encode(Array('inline_keyboard' => $inlineKeyboard));

        // Конструируем непосрдественно текст сообщения для админа с информаций о заявителе
        $message = "Новая заявка\n\n"
            ."Имя: {$newUser['name']}\n"
            ."username: @{$newUser['username']}\n\n"
            ."Какой статус присвоить?";
        // Получаем ID админа
        $admin = $this->db->getAdminID();
        // Отправляем сообщение админу
        $answer = json_decode($this->tgBot->sendMessage($admin, $message, $inlineKeyboard));

        // Записываем в логи исходящее сообщение заявку для админа
        // Формируем массив с информацией
        // Некоторые поля уже сформированы, поэтому их не трогаем
        $logs = Array(
            "chat_id" => $admin,
            "chat_name" => "Прямой контакт с вождем",
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
                    "keyboard" => $inlineKeyboard
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