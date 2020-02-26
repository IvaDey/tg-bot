<?php

    class tg_bot
    {
        private $botToken = NULL;
        private $apiURL = NULL;

        function __construct($token)
        {
            $this->botToken = 'bot' . $token;
            $this->apiURL = 'https://api.telegram.org/bot' . $token . '/';
        }

        function sendMessage($chat_id, $msg, $keyboard = [])
        {
            if ($keyboard === false) {
                $keyboard = json_encode(Array('remove_keyboard' => true));
            }

            $params = Array(
                'chat_id' => $chat_id,
                'text' => $msg,
                'reply_markup' => $keyboard
            );

            $params = http_build_query($params);
            return file_get_contents($this->apiURL . 'sendMessage?' . $params);
        }

        function sendMarkdownMessage($chat_id, $msg, $keyboard = [])
        {
            if ($keyboard === false) {
                $keyboard = json_encode(Array('remove_keyboard' => true));
            }

            $params = Array(
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard
            );

            $params = http_build_query($params);
            return file_get_contents($this->apiURL . 'sendMessage?' . $params);
        }

        function sendHTMLMessage($chat_id, $msg, $keyboard = [])
        {
            if ($keyboard === false) {
                $keyboard = json_encode(Array('remove_keyboard' => true));
            }

            $params = Array(
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            );

            $params = http_build_query($params);
            return file_get_contents($this->apiURL . 'sendMessage?' . $params);
        }

        function getKeyboardMarkup($keyboard, $one_time = true)
        {
            return json_encode(Array(
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => $one_time
            ), JSON_UNESCAPED_UNICODE);
        }

        function hideInlineKeyboard($chat_id, $message_id)
        {
            $params = Array(
                'chat_id' => $chat_id,
                'message_id' => $message_id
            );

            $params = http_build_query($params);
            return file_get_contents($this->apiURL . 'editMessageReplyMarkup?' . $params);
        }

        function editMessageText($chat_id, $message_id, $text, $keyboard = NULL)
        {
            $params = Array(
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'parse_mode' => 'HTML',
                'text' => $text
            );

            if ($keyboard)
                $params['reply_markup'] = $keyboard;

            $params = http_build_query($params);
            return file_get_contents($this->apiURL . 'editMessageText?' . $params);
        }

        function deleteMessage($chat_id, $message_id)
        {
            $params = Array(
                'chat_id' => $chat_id,
                'message_id' => $message_id
            );

            $params = http_build_query($params);
            return file_get_contents($this->apiURL . 'deleteMessage?' . $params);
        }
    }

?>