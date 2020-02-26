<?php
    include_once 'models/dbModel.php';
    include_once 'models/tg-bot.php';
    include_once 'settings/settings.php';

    class actionList
    {
        private $db = NULL;
        private $tgBot = NULL;

        function __construct()
        {
            $this->db = new dbModel($db_user, $db_password, $db_name, $db_host, $db_port);
            $this->tgBot = new tg_bot($tgToken);
        }

        private function defaultAnswer($params)
        {
            $alex = $this->db->getAdminID();

            $this->tgBot->sendMessage($alex, "default answer \n kkk");
        }

        private function getReservationList($user_id)
        {
            $location_id = $this->db->getLocationID($user_id);
            $reservationList = $this->db->getReservationList($location_id);
            if (!$reservationList)
                return "Переговорную №{$location_id} никто не забронировал";

            $reservationList = json_decode($reservationList);
            $messageList = "";

            foreach ($reservationList as $reservation)
            {
                $messageList .= "Номер брони: ".$reservation->reservation_id."\n";
                $messageList .= "Имя коммуниста: ".$reservation->name."\n";
                $messageList .= "Ник коммуниста: ".$reservation->username."\n";
                $messageList .= "Дата: ".$reservation->date."\n";
                $messageList .= "Время: ".$reservation->time_range."\n\n";
            }

            return $messageList;
        }

        private function notifyAlex($msg)
        {
            $alex = $this->db->getAdminID();

            $this->tgBot->sendMessage($alex, $msg);

            return "";
        }

        private function askPass($params)
        {
            $userName = $params['name'];
            $guestName = $params['guest_name'];

            $notify = "Новый запрос на пропуск\n\n";
            $notify .= "Пропуск заказал: {$userName}\n";
            $notify .= "На имена:";

            $this->notifyAlex($notify);
            $this->notifyAlex($guestName);

            return "Запрос принят, ожидайте подтверждения";
        }

        private function setLocationID($data)
        {
            $user_id = $data['user_id'];
            $location_id = $data['location_name'];

            switch ($location_id) {
                case 'Переговорная 1': {
                    $location_id = 1;
                    break;
                }
                case 'Переговорная 2': {
                    $location_id = 2;
                    break;
                }
            };

            $query = "UPDATE lastMessages SET location_id='{$location_id}' WHERE user_id='{$user_id}'";
            $this->db->customQuery($query);

            return "";
        }

        private function reserveBoardroom($user_id)
        {
            $location_id = $this->db->getLocationID($user_id);
            $text = $this->db->getLastMessage($user_id);

            $text = str_replace("\n", "", $text);
            $text = str_replace(" ", "", $text);
            $date = substr($text, 0, 10);
            $start_time = substr($text, 10, 5);
            $end_time = substr($text, 16, 5);

            $date = date('Y-m-d', strtotime($date.' '.$start_time));
            $start_time = date('Y-m-d H:i', strtotime($date.' '.$start_time));
            $end_time = date('Y-m-d H:i', strtotime($date.' '.$end_time));

            $text = Array(
                'location_id' => $location_id,
                'user_id' => $user_id,
                'start_time' => $start_time,
                'end_time' => $end_time
            );

            $timeRange = Array(
                'start' => $text['start_time'],
                'end' => $text['end_time']
            );

            if (!$this->db->isLocationFreeOnDate($location_id, $date, $timeRange))
                return "Время на которое ты хочешь забронировать пересекается с другой бронью\n";

            $this->db->reserveBoardroom($text);

            return "Переговорная забронирована";
        }

        function action($actName, $params = NULL)
        {
            return $this->$actName($params);
        }
    }
?>