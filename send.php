<?php
/**
 * Приём заявок с сайта Палвер.
 * Отправляет письмо с вложенной сметой на почту отдела продаж.
 * Дополнительно (по желанию) — уведомление в Telegram.
 *
 * Настройка: поменяйте адреса ниже. Хостинг должен поддерживать PHP mail()
 * (Timeweb, Beget, Reg.ru, Spaceweb и т.п. — поддерживают).
 */

$TO        = 'sales@palverstroi.ru';      // куда слать заявки
$TO_GEO    = 'salegeo@palverstroi.ru';    // заявки со страницы геоматериалов
$FROM      = 'no-reply@palverstroi.ru';   // адрес отправителя (домен сайта)
$TG_TOKEN  = '';                          // токен Telegram-бота (необязательно)
$TG_CHAT   = '';                          // id чата для уведомлений (необязательно)
$MAX_MB    = 20;

header('Content-Type: application/json; charset=utf-8');

function done($ok, $msg = '') { echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE); exit; }
function clean($s, $len = 2000) { return mb_substr(trim(strip_tags((string)$s)), 0, $len); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); done(false, 'Метод не поддерживается'); }
if (!empty($_POST['website'])) done(true); // бот заполнил скрытое поле — тихо игнорируем

$name    = clean($_POST['name'] ?? '', 120);
$phone   = clean($_POST['phone'] ?? '', 40);
$comment = clean($_POST['comment'] ?? '', 4000);
$type    = clean($_POST['type'] ?? 'Заявка', 80);
$page    = clean($_POST['page'] ?? '', 300);
$digits  = preg_replace('/\D/', '', $phone);

if (mb_strlen($name) < 2 || strlen($digits) !== 11) { http_response_code(422); done(false, 'Проверьте имя и телефон'); }

// простая защита от частых отправок: не чаще раза в 20 секунд с одного IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$lock = sys_get_temp_dir() . '/palver_' . md5($ip);
if (file_exists($lock) && time() - filemtime($lock) < 20) { http_response_code(429); done(false, 'Слишком часто, попробуйте через минуту'); }
@touch($lock);

$to = (strpos($page, 'geomaterial') !== false) ? $TO_GEO : $TO;
$subject = "=?UTF-8?B?" . base64_encode("$type с сайта: $name, $phone") . "?=";
$text = "$type\n\nИмя: $name\nТелефон: $phone\n" . ($comment ? "Комментарий: $comment\n" : '') . "Страница: $page\nДата: " . date('d.m.Y H:i') . "\n";

$boundary = 'b' . md5(uniqid('', true));
$headers  = "From: Сайт Палвер <$FROM>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
$body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n";

if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
  if ($_FILES['file']['size'] > $MAX_MB * 1024 * 1024) { http_response_code(413); done(false, 'Файл слишком большой'); }
  $fname = basename($_FILES['file']['name']);
  $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
  $allowed = ['pdf','xls','xlsx','doc','docx','csv','jpg','jpeg','png','heic'];
  if (in_array($ext, $allowed, true)) {
    $data = chunk_split(base64_encode(file_get_contents($_FILES['file']['tmp_name'])));
    $fnameEnc = "=?UTF-8?B?" . base64_encode($fname) . "?=";
    $body .= "--$boundary\r\nContent-Type: application/octet-stream; name=\"$fnameEnc\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fnameEnc\"\r\n\r\n$data\r\n";
    $text .= "Вложение: $fname\n";
  }
}
$body .= "--$boundary--";

$sent = @mail($to, $subject, $body, $headers);

if ($TG_TOKEN && $TG_CHAT) {
  $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query(['chat_id' => $TG_CHAT, 'text' => $text]), 'timeout' => 5]]);
  @file_get_contents("https://api.telegram.org/bot$TG_TOKEN/sendMessage", false, $ctx);
}

if (!$sent) { http_response_code(500); done(false, 'Не удалось отправить письмо'); }
done(true);
