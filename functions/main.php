<?php

use Morilog\Jalali\CalendarUtils;

function get_db(): MysqliDb
{
    return new MysqliDb (array(
        'host' => DB_HOST,
        'username' => DB_USER,
        'password' => DB_PASSWORD,
        'db' => DB_NAME,
        'prefix' => DB_TABLE_PREFIX,
        'charset' => DB_CHARSET));
}

function set_language_by_user_id($user_id)
{
    global $db;

    $lang = DEFAULT_LANGUAGE;

    $q = "select language_code from settings where user_id = ? limit 1";
    $user = $db->rawQueryOne($q, [
        'user_id' => $user_id
    ]);

    if (!empty($user)) {
        $lang = $user['language_code'];
    }

    set_language_by_code($lang);
}

function set_language_by_code($language_code)
{
    unload_textdomain('default');

    load_textdomain('default', __DIR__ . "/../languages/{$language_code}.mo");

    date_default_timezone_set(LANGUAGES[$language_code]['timezone']);

    current_language($language_code);
}

function current_language($language_code = null)
{
    static $_language_code = DEFAULT_LANGUAGE;

    if (!empty($language_code)) {
        $_language_code = $language_code;
    }

    return $_language_code;
}

function apply_rtl_to_keyboard($keyboard)
{
    $rtl = false;

    if (current_language() == 'fa_IR') {
        $rtl = true;
    }

    if ($rtl) {
        foreach ($keyboard as $key => $val) {
            $keyboard[$key] = array_reverse($val);
        }
    }

    return $keyboard;
}

function encode_callback_data($data)
{
    global $db;

    if (empty($data)) {
        return false;
    }

    $action = false;
    if (!empty($data['action'])) {
        $action = $data['action'];
    }

    $target_id = false;
    if (!empty($data['id'])) {
        $target_id = $data['id'];
    }

    ksort($data);
    $data = json_encode($data);

    $p = [];
    if ($action && $target_id) {
        $q = "select id from callback_data where action = ? and target_id = ? and data = ? limit 1";
        $p = ['action' => $action, 'target_id' => $target_id, 'data' => $data];
    } elseif ($action) {
        $q = "select id from callback_data where action = ? and target_id is null and data = ? limit 1";
        $p = ['action' => $action, 'data' => $data];
    } elseif ($target_id) {
        $q = "select id from callback_data where action is null and target_id = ? and data = ? limit 1";
        $p = ['target_id' => $target_id, 'data' => $data];
    } else {
        $q = "select id from callback_data where action is null and target_id is null and data = ? limit 1";
        $p = ['data' => $data];
    }
    $callback_data = $db->rawQueryOne($q, $p);

    if (!empty($callback_data)) {
        return (string)$callback_data['id'];
    }

    $callback_data_id = $db->insert('callback_data', $p);

    if (!$callback_data_id) {
        send_error(__("Unspecified error occurred. Please try again."));
    }

    return (string)$callback_data_id;
}

function decode_callback_data($id)
{
    global $db;
    $q = "select * from callback_data where id=?";
    $callback_data = $db->rawQueryOne($q, ['id' => $id]);
    if (!empty($callback_data)) {
        return json_decode($callback_data['data'], true);
    }

    return false;
}

function generateRandomString($length = 10): string
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

function add_stats_info($user_id, $name): bool
{
    global $db;

    $p = array(
        'user_id' => $user_id,
        'name' => $name,
        'stat_date' => time()
    );

    return $db->insert('stats', $p);
}

function get_com($user_id): ?array
{
    global $db;
    $q = "select * from commands where user_id=?";
    $result = $db->rawQueryOne($q, [
        'user_id' => $user_id
    ]);
    if ($result == null) {
        return null;
    }

    unset($result['id'], $result['user_id']);

    $key = array_keys($result);
    while ($result[$key[count($result) - 1]] == null) {
        unset($result[$key[count($result) - 1]]);
    }

    return $result;
}

function add_com($user_id, $name)
{
    global $db;

    empty_com($user_id);

    $p = array(
        'user_id' => $user_id,
        'name' => $name
    );

    $tmp = $db->insert('commands', $p);
    if (!$tmp) {
        send_error(__("Unspecified error occurred. Please try again."), 115);
    }
}

function edit_com($user_id, $p)
{
    global $db;

    $db->where('user_id', $user_id);
    $tmp = $db->update('commands', $p);

    if (!$tmp) {
        send_error(__("Unspecified error occurred. Please try again.") . $db->getLastError(), 251);
    }
}

function empty_com($user_id)
{
    global $db;
    $db->where('user_id', $user_id);
    $tmp = $db->delete('commands');

    if (!$tmp) {
        send_error(__("Unspecified error occurred. Please try again."), 161);
    }
}

function tgUserToText($user, $parse_mode = ''): string
{
    if (!$user) {
        return "'unknown'";
    }

    $name = $user['first_name'] . ($user['last_name'] != null ? " {$user['last_name']}" : "");

    $link = "";
    if (!empty($user['username'])) {
        $link = "https://t.me/{$user['username']}";
    } elseif (!empty($user['id'])) {
        $link = "tg://user?id={$user['id']}";
    }

    $r = false;
    if ($parse_mode == 'markdown') {
        if (empty($link)) {
            $r = $name;
        } else {
            $r = "[{$name}]({$link})";
        }
    } elseif ($parse_mode == 'html') {
        if (empty($link)) {
            $r = htmlspecialchars($name);
        } else {
            $r = "<a href='{$link}'>" . htmlspecialchars($name) . "</a>";
        }
    } else {
        if ($user['username'] == null) {
            $r = $name;
        } else {
            $r = "{$name} (@{$user['username']})";
        }
    }

    return $r;
}

function dbUserToTG($db_user): array
{
    return [
        'id' => $db_user['user_id'],
        'first_name' => $db_user['first_name'],
        'last_name' => $db_user['last_name'],
        'username' => $db_user['username'],
        'language_code' => $db_user['language_code'],
    ];
}

function tgChatToText($chat, $parse_mode = '')
{
    if (!$chat) {
        return "'unknown'";
    }

    $title = "";
    if (!empty($chat['title'])) {
        $title = $chat['title'];
    } elseif (!empty($chat['first_name'])) {
        $title = trim($chat['first_name'] . " " . $chat['last_name']);
    }

    $link = "";
    if (!empty($chat['username'])) {
        $link = "https://t.me/{$chat['username']}";
    } elseif ($chat['type'] == 'supergroup' || $chat['type'] == 'channel') {
        $link = "https://t.me/c/" . substr($chat['id'], 4) . "/100000000";
    } elseif (!empty($chat['invite_link'])) {
        $link = $chat['invite_link'];
    }

    $r = false;
    if ($parse_mode == 'markdown') {
        if (empty($link)) {
            $r = markdownspecialchars($title);
        } else {
            $r = "[" . markdownspecialchars($title) . "]({$link})";
        }
    } elseif ($parse_mode == 'html') {
        if (empty($link)) {
            $r = htmlspecialchars($title);
        } else {
            $r = "<a href='{$link}'>" . htmlspecialchars($title) . "</a>";
        }
    } else {
        if (empty($chat['username'])) {
            $r = $title;
        } else {
            $r = "{$title} (@{$chat['username']})";
        }
    }

    return $r;
}

function dbChatToTG($db_chat): array
{
    return [
        'id' => $db_chat['tg_id'],
        'type' => $db_chat['type'],
        'title' => $db_chat['title'],
        'username' => $db_chat['username'],
        'first_name' => $db_chat['first_name'],
        'last_name' => $db_chat['last_name'],
    ];
}

function markdownspecialchars($string)
{
    return str_replace(array('*', '_', '[', '`'), array('\*', '\_', '\[', '\`'), $string);
}

function is_url($url)
{
    if (preg_match('#\b(http|https|ftp|ftps)?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $url)) {
        return true;
    } else {
        return false;
    }
}

function mainMenu()
{
    global $db, $tg;

    $q = "select * from settings where user_id=? limit 1";
    $setting = $db->rawQueryOne($q, [
        'user_id' => $tg->update_from
    ]);

    $keyboard = [];

    $keyboard[] = [__("🔘 Inline Buttons"), __("🔗 Hyper"), __("📎 Attach")];
    $keyboard[] = [__("📮 Send without Quotes"), "🌐 Lang / زبان"];
    $keyboard[] = [__("☎️ Contact Us"), __("❔ Help"), __("📂 Bot Source")];

    return $tg->replyKeyboardMarkup(array(
        'keyboard' => apply_rtl_to_keyboard($keyboard),
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ));
}

function hide_link($link, $parse_mode = 'html')
{
    if ($link == "") {
        return "";
    }

    if ($parse_mode == 'html') {
        return "<a href='{$link}'>" . json_decode("\"\u200c\"") . "</a>";
    }

    if ($parse_mode == 'markdown') {
        return "[" . json_decode('"\u200c"') . "]({$link})";
    }

    return false;
}

function pos_in_array($array, $value)
{
    $keys = array_keys($array, $value);
    if (count($keys) == 0) {
        return false;
    } else {
        return $keys[0];
    }
}

function mod($a, $n)
{
    $tempMod = (float)($a / $n);
    $tempMod = ($tempMod - (int)$tempMod) * $n;
    return $tempMod;
}

function send_error($err_message, $err_code = null)
{
    global $tg;
    $update = $tg->getUpdate();
    if (
        !empty($update['message']) ||
        !empty($update['edited_message']) ||
        !empty($update['inline_query']) ||
        !empty($update['chosen_inline_result']) ||
        (!empty($update['callback_query']) && mb_strlen($err_message, 'utf-8') > 200)
    ) {
        $tg->send_error($err_message, $err_code);
    } elseif (!empty($update['callback_query'])) {
        $tg->answerCallbackQuery(array(
            "callback_query_id" => $update['callback_query']['id'],
            "text" => $err_message,
            "show_alert" => true
        ), ['send_error' => false]);
    }
    exit;
}

function get_url_of_str($str)
{
    $re = '#\b(http|https|ftp|ftps)?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';

    preg_match_all($re, $str, $matches);

    return $matches[0];
}

function get_attach_link($str)
{
    $urls = get_url_of_str($str);
    if (stripos($urls[0], str_replace(["https://", "http://"], ["://", "://"], MAIN_LINK) . "/attach.php?id=") !== false) {
        return $urls[0];
    }

    return false;
}

function convert_to_hyper($text, $entities = [])
{
    if (empty($entities)) {
        return htmlspecialchars($text);
    }

    foreach ($entities as $key => $entity) {
        if (
            $entity['type'] != 'bold' &&
            $entity['type'] != 'italic' &&
            $entity['type'] != 'underline' &&
            $entity['type'] != 'strikethrough' &&
            $entity['type'] != 'code' &&
            $entity['type'] != 'pre' &&
            $entity['type'] != 'text_link'
        ) {
            unset($entities[$key]);
        }
    }

    $offsets = [];
    $lengths = [];
    $keys = [];

    foreach ($entities as $key => $entity) {
        $keys[$key] = $key;
        $offsets[$key] = $entity['offset'];
        $lengths[$key] = $entity['length'];
    }

    array_multisort($offsets, SORT_ASC, $lengths, SORT_DESC, $keys, SORT_ASC, $entities);

    $sub_texts_offsets = [];

    foreach ($entities as $entity) {
        $sub_texts_offsets[] = $entity['offset'];
        $sub_texts_offsets[] = $entity['offset'] + $entity['length'];
    }

    $sub_texts_offsets = array_unique($sub_texts_offsets);
    sort($sub_texts_offsets);

    $str = mb_convert_encoding($text, 'UTF-16', 'UTF-8');
    $sub_texts = [];

    for ($i = 0; $i < count($sub_texts_offsets) + 1; $i++) {
        if (isset($sub_texts_offsets[$i]) && $sub_texts_offsets[$i] == 0) {
            continue;
        }

        $start = $sub_texts_offsets[$i - 1] ?? 0;
        $end = $sub_texts_offsets[$i] ?? null;
        $length = $end != null ? ($end - $start) : null;

        $segment = mb_substr($str, $start, $length, 'UCS-2');
        $sub_texts[] = [
            'offset' => $start,
            'length' => $length,
            'text' => mb_convert_encoding(
                $segment,
                'UTF-8',
                'UTF-16'
            )
        ];
    }

    $reversed_entities = array_reverse($entities);

    $final_text = "";
    $offset_index = 0;

    foreach($sub_texts as $sub_text) {
        foreach ($reversed_entities as $entity) {
            if ($offset_index != $entity['offset'] + $entity['length']) {
                continue;
            }

            if ($entity['type'] == 'bold') {
                $final_text .= "</b>";
            } elseif ($entity['type'] == 'italic') {
                $final_text .= "</i>";
            } elseif ($entity['type'] == 'underline') {
                $final_text .= "</u>";
            } elseif ($entity['type'] == 'strikethrough') {
                $final_text .= "</s>";
            } elseif ($entity['type'] == 'code') {
                $final_text .= "</code>";
            } elseif ($entity['type'] == 'pre') {
                $final_text .= "</pre>";
            } elseif ($entity['type'] == 'text_link') {
                $final_text .= "</a>";
            }
        }

        foreach ($entities as $entity) {
            if ($offset_index != $entity['offset']) {
                continue;
            }

            if ($entity['type'] == 'bold') {
                $final_text .= "<b>";
            } elseif ($entity['type'] == 'italic') {
                $final_text .= "<i>";
            } elseif ($entity['type'] == 'underline') {
                $final_text .= "<u>";
            } elseif ($entity['type'] == 'strikethrough') {
                $final_text .= "<s>";
            } elseif ($entity['type'] == 'code') {
                $final_text .= "<code>";
            } elseif ($entity['type'] == 'pre') {
                $final_text .= "<pre>";
            } elseif ($entity['type'] == 'text_link') {
                $final_text .= "<a href=\"{$entity['url']}\">";
            }
        }

        $final_text .= htmlspecialchars($sub_text['text']);
        $offset_index += $sub_text['length'];
    }

    return $final_text;
}

function convert_time_to_text($time): string
{
    $now = time();

    $text = "";

    $tmp = $time - $now;

    if (abs($tmp) < 60) {
        $text .= abs($tmp) . " " . __("seconds") . " ";
        if ($tmp < 0) {
            $text .= __("ago");
        } else {
            $text .= __("later");
        }

        if (current_language() == 'fa_IR') {
            $text .= " (" . CalendarUtils::strftime('l H:i', $time) . ")";
        } else {
            $text .= " (" . date('l H:i', $time) . ")";
        }
    } elseif (abs($tmp) < 60 * 60) {
        $text .= (int)(abs($tmp) / 60) . " " . __("minutes") . " ";
        if ($tmp < 0) {
            $text .= __("ago");
        } else {
            $text .= __("later");
        }

        if (current_language() == 'fa_IR') {
            $text .= " (" . CalendarUtils::strftime('l H:i', $time) . ")";
        } else {
            $text .= " (" . date('l H:i', $time) . ")";
        }
    } elseif (abs($tmp) < 60 * 60 * 24) {
        $text .= (int)(abs($tmp) / (60 * 60)) . " " . __("hours") . " ";
        if ($tmp < 0) {
            $text .= __("ago");
        } else {
            $text .= __("later");
        }

        if (current_language() == 'fa_IR') {
            $text .= " (" . CalendarUtils::strftime('l H:i', $time) . ")";
        } else {
            $text .= " (" . date('l H:i', $time) . ")";
        }
    } elseif (abs($tmp) < 60 * 60 * 24 * 30) {
        $text .= (int)(abs($tmp) / (60 * 60 * 24)) . " " . __("days") . " ";
        if ($tmp < 0) {
            $text .= __("ago");
        } else {
            $text .= __("later");
        }

        if (current_language() == 'fa_IR') {
            $text .= " (" . CalendarUtils::strftime('j F Y ساعت H:i', $time) . ")";
        } else {
            $text .= " (" . date('j F y \a\t H:i', $time) . ")";
        }
    } elseif (abs($tmp) < 60 * 60 * 24 * 365) {
        $text .= (int)(abs($tmp) / (60 * 60 * 24 * 30)) . " " . __("months") . " ";
        if ($tmp < 0) {
            $text .= __("ago");
        } else {
            $text .= __("later");
        }

        if (current_language() == 'fa_IR') {
            $text .= " (" . CalendarUtils::strftime('j F Y ساعت H:i', $time) . ")";
        } else {
            $text .= " (" . date('j F y \a\t H:i', $time) . ")";
        }
    } else {
        if (current_language() == 'fa_IR') {
            $text .= CalendarUtils::strftime('l j F Y ساعت H:i', $time);
        } else {
            $text .= date('l, F j, Y \a\t H:i', $time);
        }
    }
    return $text;
}

function cancel_text(): string
{
    return "\n\n" . "➖➖➖➖➖➖➖➖➖➖➖➖➖" . "\n" . __("‼️ Send /cancel to cancel operation.");
}
