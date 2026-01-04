<?php
declare(strict_types=1);

/* =========================================================
    Restaurant Booking (Single-file)
    PHP + MySQL + Bootstrap + jQuery + PHPMailer
========================================================= */

date_default_timezone_set('Asia/Bangkok');

/* =========================
    Session Hardening
========================= */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => $isHttps, // ✅ true เมื่อใช้ https
  'httponly' => true,     // ✅ JS อ่าน cookie ไม่ได้
  'samesite' => 'Lax',    // ✅ กัน CSRF ระดับหนึ่ง
]);
session_start();

/* =========================
    ENV / DEBUG
========================= */
$APP_ENV = getenv('APP_ENV') ?: 'local'; // local | production
$DEBUG   = ($APP_ENV === 'local');

if ($DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

/* =========================
    CONFIG
========================= */
$config = [
  'db' => [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => '',
    'name' => 'restaurant_booking_ui',
  ],
  'shop' => [
    'name'         => 'Seafood Restaurant',
    'open'         => '10:00',
    'close'        => '20:00',
    'cutoff_today' => '20:00',
    'step_min'     => 15,
    'address'      => '99/9 ริมทะเลบางพระ เขตบางพระ ชลบุรี 999',
    'cutoff_msg'   => 'ระบบเปิดจองวันถัดไป เนื่องจากเลยเวลา ขออภัย',

    // จำกัดจำนวนการจองต่อช่วงเวลา (0 = ไม่จำกัด)
    'max_per_slot' => 10,
  ],
  'smtp' => [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    // ใส่ @gmail.com ตรงนี้ถ้าต้องการให้ส่งเมลได้
     'user' => trim((string)(getenv('SMTP_USER') ?: '')),
     'pass' => preg_replace('/\s+/', '', (string)(getenv('SMTP_PASS') ?: '')),

  ],
];

// local ให้สร้าง DB/Table อัตโนมัติ (production แนะนำปิด)
$AUTO_SETUP_DB = (getenv('AUTO_SETUP_DB') !== false)
  ? (getenv('AUTO_SETUP_DB') === '1')
  : ($APP_ENV === 'local');

/* =========================
    Composer (PHPMailer)
========================= */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
  http_response_code(500);
  echo "<h2>ไม่พบ vendor/autoload.php</h2>";
  echo "<p>ติดตั้ง PHPMailer ก่อน:</p>";
  echo "<pre>cd " . htmlspecialchars(__DIR__, ENT_QUOTES, 'UTF-8') . "\ncomposer require phpmailer/phpmailer</pre>";
  exit;
}
require $autoload;

use PHPMailer\PHPMailer\PHPMailer;

/* =========================
    HELPERS
========================= */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_check(?string $token): bool {
  return isset($_SESSION['csrf']) && $token !== null && hash_equals($_SESSION['csrf'], $token);
}

function flash_set(string $key, $value): void { $_SESSION['flash'][$key] = $value; }
function flash_get(string $key, $default = null) {
  $v = $_SESSION['flash'][$key] ?? $default;
  unset($_SESSION['flash'][$key]);
  return $v;
}

function redirect(string $url): void {
  header("Location: {$url}");
  exit;
}

function app_error(string $publicMsg, ?string $logMsg = null, int $httpCode = 500): void {
  http_response_code($httpCode);
  if ($logMsg) error_log($logMsg);
  exit(h($publicMsg));
}

/* =========================
    Phone Normalize (+66)
========================= */
function normalize_phone(string $phone): string {
  $phone = trim($phone);
  if ($phone === '') return '';
  $phone = str_replace([' ', '-', '(', ')', '.'], '', $phone);

  if (strpos($phone, '00') === 0) $phone = '+' . substr($phone, 2);

  $hasPlus = (strpos($phone, '+') === 0);
  $digits  = preg_replace('/\D+/', '', $phone);
  if ($digits === '') return '';

  if (strpos($digits, '66') === 0) {
    $rest = substr($digits, 2);
    if (strpos($rest, '0') === 0) $rest = substr($rest, 1);
    return '+66' . $rest;
  }

  if (strpos($digits, '0') === 0 && (strlen($digits) === 9 || strlen($digits) === 10)) {
    return '+66' . substr($digits, 1);
  }

  return $hasPlus ? ('+' . $digits) : $digits;
}

function phone_candidates(string $input): array {
  $input = trim($input);
  if ($input === '') return [];

  $c = [];
  $norm = normalize_phone($input);
  if ($norm !== '') $c[] = $norm;

  $digitsOnly = preg_replace('/\D+/', '', $input);
  if ($digitsOnly !== '') $c[] = $digitsOnly;

  if (strpos($norm, '+66') === 0) {
    $rest = substr($norm, 3);
    if ($rest !== '') {
      $c[] = '66' . $rest;
      $c[] = '0'  . $rest;
    }
  }

  return array_values(array_unique(array_filter($c, fn($v) => $v !== '')));
}

/* =========================
    Date/Time Utils
========================= */
function parse_ymd(string $ymd): ?DateTimeImmutable {
  $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
  $err = DateTimeImmutable::getLastErrors();
  if (!$dt || ($err['warning_count'] ?? 0) || ($err['error_count'] ?? 0)) return null;
  return $dt;
}

function time_to_minutes(string $hm): ?int {
  if (!preg_match('/^\d{2}:\d{2}$/', $hm)) return null;
  [$h, $m] = array_map('intval', explode(':', $hm));
  if ($h < 0 || $h > 23 || $m < 0 || $m > 59) return null;
  return $h * 60 + $m;
}

function ceil_to_step(int $minutes, int $step): int {
  return (int)(ceil($minutes / $step) * $step);
}

function time_in_range_and_step(string $hm, string $open, string $close, int $stepMin): bool {
  $t = time_to_minutes($hm);
  $o = time_to_minutes($open);
  $c = time_to_minutes($close);
  if ($t === null || $o === null || $c === null) return false;
  if (!($t >= $o && $t < $c)) return false;
  return ($t % $stepMin) === 0;
}

/* =========================
    Asset Loader
========================= */
function asset(string $baseOrFile): string {
  if (preg_match('/\.(png|jpg|jpeg|webp)$/i', $baseOrFile)) {
    return is_file(__DIR__ . DIRECTORY_SEPARATOR . $baseOrFile)
      ? $baseOrFile
      : "https://via.placeholder.com/1200x700?text=Missing+" . rawurlencode($baseOrFile);
  }
  foreach (['png','jpg','jpeg','webp'] as $ext) {
    $f = "{$baseOrFile}.{$ext}";
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . $f)) return $f;
  }
  return "https://via.placeholder.com/1200x700?text=Missing+" . rawurlencode($baseOrFile);
}

/* =========================
    Email Sender (Gmail SMTP)
========================= */
function sendMailGmail(
  array $smtp,
  string $fromEmail,
  string $fromName,
  string $toEmail,
  string $toName,
  string $subject,
  string $html,
  string $alt,
  ?array $bcc = null,
  ?string &$err = null
): bool {
  try {
    $mail = new PHPMailer(true);
    $mail->CharSet = "UTF-8";

    $mail->isSMTP();
    $mail->Host        = $smtp['host'];
    $mail->SMTPAuth    = true;
    $mail->Username    = $smtp['user'];
    $mail->Password    = $smtp['pass'];
    $mail->Port        = (int)$smtp['port'];
    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPAutoTLS = true;

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail, $toName ?: $toEmail);

    if ($bcc && !empty($bcc[0])) $mail->addBCC($bcc[0], $bcc[1] ?? "");

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = $alt;

    $mail->send();
    return true;
  } catch (\Throwable $e) {
    $err = $e->getMessage();
    return false;
  }
}

/* =========================
    DB Connect + Auto Setup
========================= */
$db = $config['db'];

try {
  if ($AUTO_SETUP_DB) {
    $pdo0 = new PDO("mysql:host={$db['host']};charset=utf8mb4", $db['user'], $db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo0->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo0 = null;
  }

  $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  if ($AUTO_SETUP_DB) {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        email VARCHAR(120) NULL,
        booking_date DATE NOT NULL,
        booking_time TIME NOT NULL,
        people INT NOT NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone (phone),
        INDEX idx_date_time (booking_date, booking_time)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
  }
} catch (\Throwable $e) {
  if ($DEBUG) app_error("DB Error: " . $e->getMessage(), $e->getMessage());
  app_error("ระบบฐานข้อมูลขัดข้อง กรุณาลองใหม่ภายหลัง", $e->getMessage());
}

/* =========================
    Cutoff Logic
========================= */
$shop = $config['shop'];

$now      = new DateTimeImmutable();
$todayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');

$cutoff       = new DateTimeImmutable("today {$shop['cutoff_today']}:00");
$cutoffPassed = ($now >= $cutoff);

$minDateObj = $cutoffPassed ? new DateTimeImmutable('tomorrow') : new DateTimeImmutable('today');
$minDate    = $minDateObj->format('Y-m-d');

/* =========================
    PRG Flash
========================= */
$success      = (bool)flash_get('success', false);
$serverNotice = (string)flash_get('notice', '');
$errors       = (array)flash_get('errors', []);
$summary      = (array)flash_get('summary', []);
$old          = (array)flash_get('old', []);

/* =========================
    Form Values
========================= */
$full_name    = trim((string)($old['full_name'] ?? ($_POST['full_name'] ?? "")));
$phone_raw    = trim((string)($old['phone'] ?? ($_POST['phone'] ?? "")));
$email        = trim((string)($old['email'] ?? ($_POST['email'] ?? "")));
$booking_date = trim((string)($old['booking_date'] ?? ($_POST['booking_date'] ?? $minDate)));
$booking_time = trim((string)($old['booking_time'] ?? ($_POST['booking_time'] ?? "")));
$people       = (int)($old['people'] ?? ($_POST['people'] ?? 2));
$notes        = trim((string)($old['notes'] ?? ($_POST['notes'] ?? "")));

/* =========================
    Safe Self URL
========================= */
$self = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

/* =========================
    Handle Booking (POST)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
  $errors = [];
  $noticeParts = [];

  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = "คำขอไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่";
  }

  if ($full_name === "" || mb_strlen($full_name) < 2) {
    $errors[] = "กรุณากรอกชื่อ-นามสกุล (อย่างน้อย 2 ตัวอักษร)";
  }

  $phone_norm = normalize_phone($phone_raw);
  if ($phone_norm === "" || !preg_match('/^\+?\d{8,20}$/', $phone_norm)) {
    $errors[] = "กรุณากรอกเบอร์โทรให้ถูกต้อง";
  }

  if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "อีเมลไม่ถูกต้อง";
  }

  if ($people < 1 || $people > 20) {
    $errors[] = "จำนวนคนต้องอยู่ระหว่าง 1–20";
  }

  $selectedDate = parse_ymd($booking_date);
  if (!$selectedDate) {
    $errors[] = "รูปแบบวันที่ไม่ถูกต้อง";
  } else {
    if ($cutoffPassed && $selectedDate->format('Y-m-d') === $todayYmd) {
      $selectedDate = new DateTimeImmutable('tomorrow');
      $booking_date = $selectedDate->format('Y-m-d');
      $noticeParts[] = $shop['cutoff_msg'];
    }
    if ($selectedDate->format('Y-m-d') < $minDate) {
      $errors[] = $shop['cutoff_msg'];
    }
  }

  if (!time_in_range_and_step($booking_time, $shop['open'], $shop['close'], (int)$shop['step_min'])) {
    $errors[] = "กรุณาเลือกเวลาในช่วง {$shop['open']}–ก่อน {$shop['close']} (ทุก {$shop['step_min']} นาที)";
  } else {
    if (!$cutoffPassed && $selectedDate && $selectedDate->format('Y-m-d') === $todayYmd) {
      $nowMin  = time_to_minutes($now->format('H:i')) ?? 0;
      $nextMin = ceil_to_step($nowMin, (int)$shop['step_min']);
      $tMin    = time_to_minutes($booking_time) ?? 0;
      $openMin = time_to_minutes($shop['open']) ?? 0;

      $minAllowed = max($openMin, $nextMin);
      if ($tMin < $minAllowed) $errors[] = "เวลาที่เลือกผ่านมาแล้ว กรุณาเลือกเวลาถัดไป";
    }
  }

  $booking_time_full = $booking_time !== '' ? ($booking_time . ":00") : '';

  // กัน “จองซ้ำ” (เบอร์เดียวกัน + วัน + เวลา)
  if (!$errors && $phone_norm !== '' && $booking_time_full !== '' && $selectedDate) {
    try {
      $st = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM bookings
        WHERE phone = :phone AND booking_date = :d AND booking_time = :t
      ");
      $st->execute([
        ':phone' => $phone_norm,
        ':d'     => $booking_date,
        ':t'     => $booking_time_full,
      ]);
      $dup = (int)($st->fetch()['c'] ?? 0);
      if ($dup > 0) $errors[] = "คุณมีการจองซ้ำในวันและเวลานี้แล้ว";
    } catch (\Throwable $e) {
      error_log("dup-check: " . $e->getMessage());
    }
  }

  // จำกัดจำนวนการจองต่อช่วงเวลา (ถ้าตั้ง max_per_slot > 0)
  if (!$errors && (int)$shop['max_per_slot'] > 0 && $booking_time_full !== '' && $selectedDate) {
    try {
      $st = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM bookings
        WHERE booking_date = :d AND booking_time = :t
      ");
      $st->execute([
        ':d' => $booking_date,
        ':t' => $booking_time_full,
      ]);
      $cnt = (int)($st->fetch()['c'] ?? 0);
      if ($cnt >= (int)$shop['max_per_slot']) $errors[] = "ช่วงเวลานี้เต็มแล้ว กรุณาเลือกเวลาอื่น";
    } catch (\Throwable $e) {
      error_log("slot-cap: " . $e->getMessage());
    }
  }

  if ($errors) {
    flash_set('errors', $errors);
    flash_set('notice', implode(' | ', $noticeParts));
    flash_set('old', [
      'full_name'    => $full_name,
      'phone'        => $phone_raw,
      'email'        => $email,
      'booking_date' => $booking_date,
      'booking_time' => $booking_time,
      'people'       => $people,
      'notes'        => $notes,
    ]);
    redirect($self . '#booking');
  }

  // Save to DB
  try {
    $stmt = $pdo->prepare("
      INSERT INTO bookings (full_name, phone, email, booking_date, booking_time, people, notes)
      VALUES (:full_name, :phone, :email, :booking_date, :booking_time, :people, :notes)
    ");
    $stmt->execute([
      ":full_name"    => $full_name,
      ":phone"        => $phone_norm,
      ":email"        => ($email !== "" ? $email : null),
      ":booking_date" => $booking_date,
      ":booking_time" => $booking_time_full,
      ":people"       => $people,
      ":notes"        => ($notes !== "" ? $notes : null),
    ]);
  } catch (\PDOException $e) {
    $mysqlErrNo = $e->errorInfo[1] ?? null;

    if ($mysqlErrNo === 1062) {
      flash_set('errors', ["คุณมีการจองซ้ำในวันและเวลานี้แล้ว กรุณาเลือกเวลาอื่น"]);
    } else {
      $msg = $DEBUG ? ("บันทึกไม่สำเร็จ: " . $e->getMessage()) : "บันทึกไม่สำเร็จ กรุณาลองใหม่";
      flash_set('errors', [$msg]);
    }

    error_log("insert: " . $e->getMessage());

    flash_set('old', [
      'full_name'    => $full_name,
      'phone'        => $phone_raw,
      'email'        => $email,
      'booking_date' => $booking_date,
      'booking_time' => $booking_time,
      'people'       => $people,
      'notes'        => $notes,
    ]);
    redirect($self . '#booking');
  } catch (\Throwable $e) {
    error_log("insert: " . $e->getMessage());
    $msg = $DEBUG ? ("บันทึกไม่สำเร็จ: " . $e->getMessage()) : "บันทึกไม่สำเร็จ กรุณาลองใหม่";
    flash_set('errors', [$msg]);

    flash_set('old', [
      'full_name'    => $full_name,
      'phone'        => $phone_raw,
      'email'        => $email,
      'booking_date' => $booking_date,
      'booking_time' => $booking_time,
      'people'       => $people,
      'notes'        => $notes,
    ]);
    redirect($self . '#booking');
  }

  /* Email Confirm (เมื่อ SMTP ครบ) */
  $smtp = $config['smtp'];
  $mailErr = null;

  $notice = $noticeParts ? implode(' | ', $noticeParts) : "";
  $smtpReady = ($smtp['user'] !== '' && $smtp['pass'] !== '');

  if ($smtpReady) {
    $fromEmail = $smtp['user'];
    $fromName  = $shop['name'];
    $shopNotifyEmail = $smtp['user'];

    $mailHtml = "
      <div style='font-family:Tahoma,Arial,sans-serif;font-size:14px;color:#111'>
        <h2 style='margin:0 0 10px'>ยืนยันการจองโต๊ะ ✅</h2>
        <p style='margin:0 0 12px'>ร้าน <b>".h($shop['name'])."</b></p>
        <table cellpadding='6' style='border-collapse:collapse'>
          <tr><td><b>ชื่อ</b></td><td>".h($full_name)."</td></tr>
          <tr><td><b>โทร</b></td><td>".h($phone_norm)."</td></tr>
          <tr><td><b>วัน</b></td><td>".h($booking_date)."</td></tr>
          <tr><td><b>เวลา</b></td><td>".h($booking_time)."</td></tr>
          <tr><td><b>จำนวนคน</b></td><td>".h((string)$people)."</td></tr>
          ".($notes!=="" ? "<tr><td><b>หมายเหตุ</b></td><td>".h($notes)."</td></tr>" : "")."
        </table>
        <p style='margin:14px 0 0;color:#555'>ที่อยู่ร้าน: ".h($shop['address'])."</p>
      </div>
    ";

    $mailAlt =
      "ยืนยันการจองโต๊ะ\n".
      "ร้าน: {$shop['name']}\n".
      "ชื่อ: {$full_name}\n".
      "โทร: {$phone_norm}\n".
      "วัน: {$booking_date}\n".
      "เวลา: {$booking_time}\n".
      "จำนวนคน: {$people}\n".
      ($notes!=="" ? "หมายเหตุ: {$notes}\n" : "").
      "ที่อยู่ร้าน: {$shop['address']}\n";

    if ($email !== "") {
      $ok = sendMailGmail(
        $smtp,
        $fromEmail, $fromName,
        $email, $full_name,
        "ยืนยันการจองโต๊ะ: {$shop['name']}",
        $mailHtml, $mailAlt,
        [$shopNotifyEmail, $shop['name']],
        $mailErr
      );
      $notice .= ($notice ? " | " : "") . ($ok ? "ส่งอีเมลยืนยันแล้ว ✅" : "บันทึกแล้ว แต่ส่งอีเมลไม่สำเร็จ");
      if (!$ok) error_log("mail: " . ($mailErr ?? 'unknown'));
    } else {
      $ok = sendMailGmail(
        $smtp,
        $fromEmail, $fromName,
        $shopNotifyEmail, $shop['name'],
        "แจ้งเตือนการจองใหม่: {$shop['name']}",
        $mailHtml, $mailAlt,
        null,
        $mailErr
      );
      $notice .= ($notice ? " | " : "") . ($ok ? "แจ้งร้านทางอีเมลแล้ว ✅" : "บันทึกแล้ว แต่แจ้งร้านไม่สำเร็จ");
      if (!$ok) error_log("mail-shop: " . ($mailErr ?? 'unknown'));
    }
  } else {
    $notice .= ($notice ? " | " : "") . "บันทึกแล้ว (ยังไม่ตั้งค่า SMTP จึงไม่ส่งอีเมล)";
  }

  flash_set('success', true);
  flash_set('notice', $notice);
  flash_set('summary', [
    'full_name'    => $full_name,
    'booking_date' => $booking_date,
    'booking_time' => $booking_time,
    'people'       => $people,
  ]);

  redirect($self . '#booking');
}

/* =========================
    Check Booking by Phone
========================= */
$searchPhoneInput = trim((string)($_GET['phone'] ?? ""));
$searchPhoneNorm  = normalize_phone($searchPhoneInput);
$bookings = [];

$cands = phone_candidates($searchPhoneInput);

if ($searchPhoneNorm !== "" && preg_match('/^\+?\d{8,20}$/', $searchPhoneNorm) && $cands) {
  $placeholders = [];
  $params = [];
  foreach ($cands as $i => $p) {
    $ph = ":p{$i}";
    $placeholders[] = $ph;
    $params[$ph] = $p;
  }

  $sql = "SELECT * FROM bookings
          WHERE phone IN (" . implode(',', $placeholders) . ")
          ORDER BY created_at DESC
          LIMIT 20";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $bookings = $stmt->fetchAll();
}

/* =========================
   🖼️ Images
========================= */
$hero           = asset("vibe.png");
$imgPadthai     = asset("padthai.png");
$imgGrilledshrimp = asset("grilledshrimp.png");
$imgOysters     = asset("oysters.png");
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= h($shop['name']) ?> | จองโต๊ะออนไลน์</title>

  <!-- ✅ Bootstrap (Responsive UI) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- ✅ jQuery (Realtime Validation) -->
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

  <style>
    :root{ --brand:#ff7a00; --ink:#0b1220; --muted:#6b7280; --bg:#eef6ff; --card:#fff; --radius:18px; }
    body{ background: var(--bg); color: var(--ink); }
    .container{ max-width: 1100px; }
    .topbar{ position: sticky; top:0; z-index: 1000; background: rgba(255,255,255,.92); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(2,8,23,.06); }
    .brand-name{ color: var(--brand); font-weight: 900; }
    .nav-pill{ border-radius:999px; padding:.48rem .92rem!important; border:1px solid rgba(255,122,0,.30); font-weight: 800; color:#111827!important; }
    .nav-pill.active{ background: var(--brand); border-color: var(--brand); color:#fff!important; }
    .nav-pill:hover{ background: rgba(255,122,0,.10); }

    .hero{ position: relative; min-height: 55vh; overflow:hidden; background:#111; display:flex; align-items:center; }
    .hero::before{ content:""; position:absolute; inset:0; background: url('<?= h($hero) ?>') center/cover no-repeat; }
    .hero::after{ content:""; position:absolute; inset:0; background: linear-gradient(90deg, rgba(0,0,0,.62), rgba(0,0,0,.25)); }
    .hero-inner{ position: relative; width:100%; padding: 72px 0 56px; color:#fff; text-align:center; }

    section{ scroll-margin-top: 86px; }

    .cardx{ background: var(--card); border-radius: var(--radius); border: 1px solid rgba(2,8,23,.06); }
    .muted{ color: var(--muted); }

    .form-control, .form-select{ border-radius: 14px; padding: .7rem .85rem; }
    .btn-brand{ background: var(--brand); border-color: var(--brand); color:#fff; border-radius: 14px; padding: .85rem 1rem; font-weight: 950; }

    .dish{ border-radius: 18px; overflow:hidden; border: 1px solid rgba(2,8,23,.06); background:#fff; height:100%; }
    .dish img{ width:100%; height: 180px; object-fit: cover; }
    .price{ color: var(--brand); font-weight: 950; }
  </style>
</head>

<body>

<div class="topbar">
  <nav class="navbar navbar-expand-lg">
    <div class="container py-2">
      <a class="navbar-brand d-flex align-items-center gap-2" href="javascript:void(0)" data-target="#home">
        <span class="brand-name"><?= h($shop['name']) ?></span>
      </a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topnav">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="topnav">
        <ul class="navbar-nav ms-auto gap-2 align-items-lg-center">
          <li class="nav-item"><a class="nav-link nav-pill active" data-target="#home" href="#home">หน้าแรก</a></li>
          <li class="nav-item"><a class="nav-link nav-pill" data-target="#booking" href="#booking">จองโต๊ะ</a></li>
          <li class="nav-item"><a class="nav-link nav-pill" data-target="#check" href="#check">ตรวจสอบการจอง</a></li>
        </ul>
      </div>
    </div>
  </nav>
</div>

<header class="hero" id="home">
  <div class="container hero-inner">
    <h1 class="display-4 fw-bold mb-2">จองโต๊ะออนไลน์</h1>
    <div class="muted mb-3" style="color:rgba(255, 255, 255, 0.85)!important;">
      เปิด <?= h($shop['open']) ?> – ปิด <?= h($shop['close']) ?> | <?= h($shop['address']) ?>
    </div>

    <?php if ($cutoffPassed): ?>
      <div class="alert alert-warning mt-4 text-start">
        <b><?= h($shop['cutoff_msg']) ?></b>
      </div>
    <?php endif; ?>
  </div>
</header>

<section class="py-5">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="fw-bold">เมนูแนะนำ</h2>
    </div>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="dish">
          <img src="<?= h($imgOysters) ?>" alt="oysters">
          <div class="p-3">
            <div class="fw-bold fs-5">หอยนางรมสด</div>
            <div class="muted">น้ำจิ้มซีฟู้ด สดใหม่</div>
            <div class="price mt-2">220 ฿</div>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="dish">
          <img src="<?= h($imgPadthai) ?>" alt="padthai">
          <div class="p-3">
            <div class="fw-bold fs-5">ผัดไทยกุ้งสด</div>
            <div class="muted">หอมซอสเข้มข้น</div>
            <div class="price mt-2">120 ฿</div>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="dish">
          <img src="<?= h($imgGrilledshrimp) ?>" alt="grilledshrimp">
          <div class="p-3">
            <div class="fw-bold fs-5">กุ้งเผา</div>
            <div class="muted">สด หวานแน่น</div>
            <div class="price mt-2">280 ฿</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="py-5" id="booking">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="fw-bold">จองโต๊ะ</h2>
      <div class="muted">กรอกข้อมูลแล้วกดยืนยัน (ส่งอีเมลเมื่อกรอกอีเมล + ตั้งค่า SMTP แล้ว)</div>
    </div>

    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="cardx p-4 p-md-5">

          <?php if ($serverNotice): ?>
            <div class="alert alert-warning fw-semibold"><?= h($serverNotice) ?></div>
          <?php endif; ?>

          <?php if ($success): ?>
            <div class="alert alert-success">
              <div class="fw-bold fs-5">บันทึกการจองเรียบร้อย ✅</div>
              <?php if ($summary): ?>
                <div class="mt-2">
                  <div><b>ชื่อ:</b> <?= h((string)($summary['full_name'] ?? '')) ?></div>
                  <div><b>วัน-เวลา:</b> <?= h((string)($summary['booking_date'] ?? '')) ?> <?= h((string)($summary['booking_time'] ?? '')) ?></div>
                  <div><b>จำนวนคน:</b> <?= h((string)($summary['people'] ?? '')) ?></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($errors): ?>
            <div class="alert alert-danger">
              <div class="fw-bold">กรุณาแก้ไข:</div>
              <ul class="mb-0">
                <?php foreach ($errors as $e): ?><li><?= h((string)$e) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form method="post" id="bookingForm" action="<?= h($self) ?>#booking" novalidate>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="book">

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-bold">ชื่อ-นามสกุล *</label>
                <input class="form-control" name="full_name" id="full_name" value="<?= h($full_name) ?>" required>
                <div class="invalid-feedback">กรุณากรอกชื่อ-นามสกุล</div>
              </div>

              <div class="col-md-6">
                <label class="form-label fw-bold">เบอร์โทร *</label>
                <input class="form-control" name="phone" id="phone" value="<?= h($phone_raw) ?>" placeholder="เช่น 0812345678" required>
                <div class="invalid-feedback">กรุณากรอกเบอร์โทรให้ถูกต้อง</div>
              </div>

              <div class="col-md-6">
                <label class="form-label fw-bold">อีเมล (ไม่บังคับ)</label>
                <input class="form-control" name="email" id="email" value="<?= h($email) ?>" placeholder="example@email.com">
                <div class="invalid-feedback">อีเมลไม่ถูกต้อง</div>
              </div>

              <div class="col-md-3">
                <label class="form-label fw-bold">วันที่ *</label>
                <input type="date" class="form-control" name="booking_date" id="booking_date"
                       min="<?= h($minDate) ?>" value="<?= h($booking_date ?: $minDate) ?>" required>
                <div class="invalid-feedback">กรุณาเลือกวันที่</div>
              </div>

              <div class="col-md-3">
                <label class="form-label fw-bold">เวลา *</label>
                <select class="form-select" name="booking_time" id="booking_time" required></select>
                <div class="invalid-feedback">กรุณาเลือกเวลาในช่วง <?= h($shop['open']) ?>–ก่อน <?= h($shop['close']) ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label fw-bold">จำนวนคน *</label>
                <input type="number" class="form-control" name="people" id="people" min="1" max="20"
                       value="<?= h((string)($people ?: 2)) ?>" required>
                <div class="invalid-feedback">1–20 คน</div>
              </div>

              <div class="col-md-8">
                <label class="form-label fw-bold">หมายเหตุ (ไม่บังคับ)</label>
                <input class="form-control" name="notes" id="notes" value="<?= h($notes) ?>" placeholder="เช่น ขอที่นั่งริมหน้าต่าง">
              </div>

              <div class="col-12 d-grid mt-2">
                <button class="btn btn-brand btn-lg" type="submit">
                  <i class="bi bi-check2-circle me-1"></i> ยืนยันการจอง
                </button>
                <div class="muted mt-2">
                  * หลัง <?= h($shop['cutoff_today']) ?> ระบบไม่รับจอง “ภายในวันเดียวกัน”
                </div>
              </div>
            </div>
          </form>

        </div>
      </div>
    </div>
  </div>
</section>

<section class="pb-5" id="check">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="fw-bold">ตรวจสอบการจอง</h2>
      <div class="muted">ใส่เบอร์โทรเพื่อดูรายการล่าสุด</div>
    </div>

    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="cardx p-4">
          <form class="d-flex gap-2" method="get" action="<?= h($self) ?>#check">
            <input class="form-control" name="phone" value="<?= h($searchPhoneInput) ?>" placeholder="ใส่เบอร์โทรเพื่อดูรายการจอง">
            <button class="btn btn-dark" type="submit"><i class="bi bi-search"></i></button>
          </form>

          <hr class="my-3">

          <?php if ($searchPhoneInput === ""): ?>
            <div class="alert alert-light border mb-0">พิมพ์เบอร์โทรเพื่อดูรายการจองล่าสุด</div>
          <?php elseif ($searchPhoneNorm === "" || !preg_match('/^\+?\d{8,20}$/', $searchPhoneNorm)): ?>
            <div class="alert alert-warning mb-0">รูปแบบเบอร์โทรไม่ถูกต้อง</div>
          <?php elseif (!$bookings): ?>
            <div class="alert alert-warning mb-0">ไม่พบรายการจองสำหรับเบอร์นี้</div>
          <?php else: ?>
            <div class="small muted mb-2">พบ <?= count($bookings) ?> รายการ (ล่าสุดก่อน)</div>
            <div class="list-group">
              <?php foreach ($bookings as $b): ?>
                <div class="list-group-item">
                  <div class="d-flex justify-content-between">
                    <div class="fw-bold"><?= h((string)$b['full_name']) ?></div>
                    <div class="small muted"><?= h((string)$b['created_at']) ?></div>
                  </div>
                  <div class="mt-2">
                    <span class="badge text-bg-primary"><?= h((string)$b['booking_date']) ?></span>
                    <span class="badge text-bg-dark"><?= h(substr((string)$b['booking_time'],0,5)) ?></span>
                    <span class="badge text-bg-secondary"><?= h((string)$b['people']) ?> คน</span>
                  </div>
                  <?php if (!empty($b['notes'])): ?>
                    <div class="small muted mt-2">หมายเหตุ: <?= h((string)$b['notes']) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>
</section>

<footer class="py-5" style="background:#0b1220;color:#dbeafe;">
  <div class="container text-center">
    <div class="fw-bold fs-4" style="color:#ff7a00;"><?= h($shop['name']) ?></div>
    <div class="mt-3" style="opacity:.65;">© <?= h((string)date('Y')) ?> <?= h($shop['name']) ?>. All rights reserved.</div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ✅ Navbar (Smooth + Scroll Spy) */
function setActiveByTarget(target){
  document.querySelectorAll(".nav-pill").forEach(a=>{
    a.classList.toggle("active", (a.dataset.target || a.getAttribute("href")) === target);
  });
}
function scrollToTarget(target){
  const el = document.querySelector(target);
  if (el) el.scrollIntoView({behavior:"smooth"});
  setActiveByTarget(target);
  history.replaceState(null, "", target);
  const nav = document.getElementById("topnav");
  if (nav && nav.classList.contains("show")) bootstrap.Collapse.getOrCreateInstance(nav).hide();
}
document.querySelectorAll("[data-target]").forEach(a=>{
  a.addEventListener("click", (e)=>{
    const t = a.dataset.target;
    if (t && t.startsWith("#")) { e.preventDefault(); scrollToTarget(t); }
  });
});
(function(){
  const hash = location.hash || "#home";
  setActiveByTarget(hash);
  const sections = ["#home","#booking","#check"].map(sel => document.querySelector(sel)).filter(Boolean);
  const io = new IntersectionObserver((entries)=>{
    let best = null;
    for (const e of entries) {
      if (!e.isIntersecting) continue;
      if (!best || e.intersectionRatio > best.intersectionRatio) best = e;
    }
    if (best) setActiveByTarget("#" + best.target.id);
  }, { threshold:[0.25,0.4,0.55,0.7] });
  sections.forEach(s=>io.observe(s));
})();

/* ✅ Time Slot Builder + Realtime Validation */
(function(){
  const SHOP_OPEN  = "<?= h($shop['open']) ?>";
  const SHOP_CLOSE = "<?= h($shop['close']) ?>";
  const stepMin    = <?= (int)$shop['step_min'] ?>;

  const minDate    = "<?= h($minDate) ?>";
  const TODAY_YMD  = "<?= h($todayYmd) ?>";
  const NOW_HM     = "<?= h($now->format('H:i')) ?>";

  const $form   = $("#bookingForm");
  const $full   = $("#full_name");
  const $phone  = $("#phone");
  const $email  = $("#email");
  const $date   = $("#booking_date");
  const $time   = $("#booking_time");
  const $people = $("#people");

  $date.attr("min", minDate);
  if (!$date.val() || $date.val() < minDate) $date.val(minDate);

  function toMinutes(t){ const [h,m]=t.split(":").map(Number); return h*60+m; }
  function pad(n){ return String(n).padStart(2,"0"); }
  function minutesToTime(min){ return `${pad(Math.floor(min/60))}:${pad(min%60)}`; }

  function buildTimeOptions(selectedDate, preferred){
    const openMin  = toMinutes(SHOP_OPEN);
    const closeMin = toMinutes(SHOP_CLOSE);
    let startMin   = openMin;

    if (selectedDate === TODAY_YMD){
      const nowMin   = toMinutes(NOW_HM);
      const nextSlot = Math.ceil(nowMin / stepMin) * stepMin;
      startMin = Math.max(openMin, nextSlot);
    }

    $time.empty();

    if (startMin >= closeMin){
      $time.append(`<option value="">ไม่มีช่วงเวลาที่จองได้</option>`);
      $time.prop("disabled", true);
      return;
    }

    $time.prop("disabled", false);
    for (let m=startMin; m<closeMin; m+=stepMin){
      const t = minutesToTime(m);
      $time.append(`<option value="${t}">${t}</option>`);
    }

    if (preferred && $time.find(`option[value="${preferred}"]`).length){
      $time.val(preferred);
    } else {
      $time.val($time.find("option:first").val());
    }
  }

  buildTimeOptions($date.val(), "<?= h($booking_time ?: '') ?>");

  $date.on("change", function(){
    if ($date.val() < minDate) $date.val(minDate);
    buildTimeOptions($date.val(), "");
    checkAll();
  });

  function validPhone(v){ return /^[0-9+\-\s]{8,25}$/.test(v); }
  function validEmail(v){ if(!v) return true; return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
  function validTime(v){ return !!v && v >= SHOP_OPEN && v < SHOP_CLOSE; }
  function inRangePeople(n){ return n>=1 && n<=20; }

  function setState($el, ok){
    $el.toggleClass("is-valid", ok);
    $el.toggleClass("is-invalid", !ok);
  }

  function checkAll(){
    setState($full, $full.val().trim().length >= 2);
    setState($phone, validPhone($phone.val().trim()));
    setState($email, validEmail($email.val().trim()));
    setState($date, $date.val() && $date.val() >= minDate);
    setState($time, validTime($time.val()));
    const p = parseInt($people.val() || "0", 10);
    setState($people, inRangePeople(p));
  }

  $form.on("input change blur", "input,select", checkAll);
  $form.on("submit", function(e){
    checkAll();
    if ($form.find(".is-invalid").length) {
      e.preventDefault();
      e.stopPropagation();
    }
  });

  checkAll();
})();
</script>

</body>
</html>
