<?php
declare(strict_types=1);

/**
 * NutriPath Health Questionnaire - submit.php
 * - Validates POST data
 * - Inserts into MySQL using prepared statements
 * - Sends confirmation (user) and notification (admin) emails via PHPMailer
 */

/* ------------------------------
   Simple helpers
   ------------------------------ */
function required($key) {
  return isset($_POST[$key]) && trim((string)$_POST[$key]) !== '';
}
function field($key, $default = '') {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}
function field_array($key) {
  return isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];
}

/* ------------------------------
   Validate required fields
   ------------------------------ */
$errors = [];

if (!required('name')) $errors[] = 'Name is required.';
if (!required('email')) $errors[] = 'Email is required.';
if (required('email') && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
if (!required('age')) $errors[] = 'Age is required.';
if (!required('gender')) $errors[] = 'Gender is required.';
if (!required('meals_per_day')) $errors[] = 'Meals per day is required.';
if (!required('skip_breakfast')) $errors[] = 'Breakfast question is required.';
if (!required('foods_avoid')) $errors[] = 'Foods to avoid is required.';
if (!required('water_intake')) $errors[] = 'Water intake is required.';
if (!required('activity_level')) $errors[] = 'Activity level is required.';
if (!required('sleep_hours')) $errors[] = 'Sleep hours is required.';
if (!required('smoke')) $errors[] = 'Smoking status is required.';
if (!required('alcohol')) $errors[] = 'Alcohol use is required.';
if (!required('stress_level')) $errors[] = 'Stress level is required.';
if (!required('primary_goal')) $errors[] = 'Primary goal is required.';
if (!required('timeframe')) $errors[] = 'Time frame is required.';
if (!required('additional_info')) $errors[] = 'Additional info is required.';
if (!required('on_medication')) $errors[] = 'Medication question is required.';

if (!empty($errors)) {
  http_response_code(422);
  echo '<h2>Form errors</h2><ul>';
  foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; }
  echo '</ul><p><a href="index.html">Go back</a></p>';
  exit;
}

/* ------------------------------
   Capture inputs
   ------------------------------ */
$name = field('name');
$email = field('email');
$age = (int) field('age');
$gender = field('gender');
$occupation = field('occupation');
$location = field('location');
$height_cm = field('height_cm') !== '' ? (int) field('height_cm') : null;
$weight_kg = field('weight_kg') !== '' ? (int) field('weight_kg') : null;
$marital_status = field('marital_status');

$conditions = array_filter(array_map('trim', field_array('conditions')));
$foods = array_filter(array_map('trim', field_array('foods')));

$responses_map = [
  'allergies' => 'Allergies (food/medication)',
  'symptoms' => 'Recent symptoms',
  'on_medication' => 'Currently on medication',
  'medication_details' => 'Medication details',
  'meals_per_day' => 'Meals per day',
  'skip_breakfast' => 'Do you skip breakfast?',
  'foods_avoid' => 'Foods you avoid',
  'water_intake' => 'Daily water intake',
  'activity_level' => 'Physical activity level',
  'sleep_hours' => 'Average sleep per night',
  'smoke' => 'Do you smoke?',
  'alcohol' => 'Alcohol consumption',
  'stress_level' => 'Stress level',
  'supplements' => 'Supplements',
  'primary_goal' => 'Primary health goal',
  'timeframe' => 'Target time frame',
  'additional_info' => 'Additional information',
];

if (!empty($_POST['symptoms']) && is_array($_POST['symptoms'])) {
  $_POST['symptoms'] = implode(', ', array_map('trim', $_POST['symptoms']));
}

/* ------------------------------
   Database connection
   ------------------------------ */
$DB_SERVER   = 'localhost';
$DB_USERNAME = 'root';
$DB_PASSWORD = '';
$DB_NAME     = 'nutripath_db';

$mysqli = @new mysqli($DB_SERVER, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo 'Database connection failed: ' . htmlspecialchars($mysqli->connect_error);
  exit;
}
$mysqli->set_charset('utf8mb4');

$user_id = null;
$mysqli->begin_transaction();
try {
  $stmt = $mysqli->prepare("
    INSERT INTO users (name, email, age, gender, occupation, location, height_cm, weight_kg, marital_status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) { throw new Exception('Prepare failed: users'); }

  $stmt->bind_param(
    'ssisssiis',
    $name,
    $email,
    $age,
    $gender,
    $occupation,
    $location,
    $height_cm,
    $weight_kg,
    $marital_status
  );
  if (!$stmt->execute()) {
    if ($mysqli->errno === 1062) {
      throw new Exception('This email is already registered. Please use a different email.');
    }
    throw new Exception('Execute failed: users');
  }
  $user_id = $stmt->insert_id;
  $stmt->close();

  if (!empty($conditions)) {
    $stmt = $mysqli->prepare("INSERT INTO conditions (user_id, condition_name) VALUES (?, ?)");
    foreach ($conditions as $cond) {
      $c = mb_substr($cond, 0, 100);
      $stmt->bind_param('is', $user_id, $c);
      $stmt->execute();
    }
    $stmt->close();
  }

  if (!empty($foods)) {
    $stmt = $mysqli->prepare("INSERT INTO foods (user_id, food_name) VALUES (?, ?)");
    foreach ($foods as $food) {
      $f = mb_substr($food, 0, 100);
      $stmt->bind_param('is', $user_id, $f);
      $stmt->execute();
    }
    $stmt->close();
  }

  $stmt = $mysqli->prepare("INSERT INTO responses (user_id, question, answer) VALUES (?, ?, ?)");
  foreach ($responses_map as $key => $questionLabel) {
    $val = field($key);
    if ($val === '' && !isset($_POST[$key])) continue;
    $answer = mb_substr(is_array($val) ? implode(', ', $val) : (string)$val, 0, 65535);
    $q = mb_substr($questionLabel, 0, 255);
    $stmt->bind_param('iss', $user_id, $q, $answer);
    $stmt->execute();
  }
  $stmt->close();

  $mysqli->commit();
} catch (Throwable $ex) {
  $mysqli->rollback();
  http_response_code(500);
  echo 'We could not save your submission. ' . htmlspecialchars($ex->getMessage());
  exit;
}

/* ------------------------------
   PHPMailer setup
   ------------------------------ */
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$ADMIN_EMAIL = 'nutripath72@gmail.com'; // notification email

// ✅ Gmail SMTP settings
$SMTP_HOST = 'smtp.gmail.com';
$SMTP_PORT = 587; // TLS
$SMTP_USER = 'nutripath72@gmail.com';
$SMTP_PASS = 'kiii qhky ifkl atri'; // your App Password
$FROM_EMAIL = 'nutripath72@gmail.com';
$FROM_NAME  = 'NutriPath Team';

$mail = new PHPMailer(true);
$mail2 = new PHPMailer(true);

try {
  foreach ([$mail, $mail2] as $m) {
    $m->isSMTP();
    $m->Host = $SMTP_HOST;
    $m->Port = $SMTP_PORT;
    $m->SMTPAuth = true;
    $m->Username = $SMTP_USER;
    $m->Password = $SMTP_PASS;
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $m->setFrom($FROM_EMAIL, $FROM_NAME);
    $m->isHTML(true);
    $m->SMTPDebug = 0; // change to 2 for debugging
  }

  // User email
  $mail->addAddress($email, $name);
  $mail->Subject = 'Thank you for completing the NutriPath Questionnaire';
  $mail->Body    = 'Hi ' . htmlspecialchars($name) . ',<br><br>Thanks for completing the NutriPath questionnaire. Our team will analyze your answers and send you a personalized diet recommendation based on affordable, local foods. Stay healthy!';

  // Admin email
  $mail2->addAddress($ADMIN_EMAIL, 'NutriPath Admin');
  $mail2->Subject = 'New NutriPath Submission';
  $mail2->Body    = 'A new questionnaire has been submitted by ' . htmlspecialchars($name) . ' (' . htmlspecialchars($email) . '). Please check the database for details.';

  $mail->send();
  $mail2->send();
} catch (Exception $e) {
  error_log("Mailer Error: " . $e->getMessage());
}

/* ------------------------------
   Success page
   ------------------------------ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Submission received</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,Segoe UI,Roboto,Inter,Arial;background:#f6fbf8;color:#0f172a;padding:24px}
    .card{max-width:640px;margin:40px auto;background:#fff;border:1px solid #e6f3ee;border-radius:16px;padding:22px;box-shadow:0 8px 24px rgba(2,32,36,0.06)}
    h1{margin:0 0 8px}
    a.btn{display:inline-block;margin-top:10px;padding:10px 14px;border-radius:10px;background:#0ea5a5;color:#fff;text-decoration:none;font-weight:700}
  </style>
</head>
<body>
  <div class="card">
    <h1>Thanks, <?= htmlspecialchars($name) ?>!</h1>
    <p>Your NutriPath questionnaire has been received. We’ll review your answers and follow up via email at <strong><?= htmlspecialchars($email) ?></strong>.</p>
    <a class="btn" href="index.html">Back to form</a>
  </div>
</body>
</html>
