<?php
// c:\XAMPP\htdocs\cms\admin\ajax_test_email.php
require_once '../config.php';
require_once '../core/Email.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Érvénytelen CSRF token.']);
    exit;
}

$to = $_POST['to'] ?? '';
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Érvénytelen email cím.']);
    exit;
}

$result = Email::send($db, $to, 'Teszt Email - CMS Rendszer', '<b>Sikeres teszt!</b><br>Az email küldés megfelelően működik az új SMTP beállításokkal.');

if ($result['success']) {
    echo json_encode(['success' => true, 'message' => 'Teszt email sikeresen elküldve a következő címre: ' . $to]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error']]);
}
