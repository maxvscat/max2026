<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';
$dataFile = __DIR__ . '/data/gallery.json';
$uploadDir = __DIR__ . '/uploads/';

function respond($payload, $status = 200) { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE); exit; }
function items($file) { $data = json_decode(@file_get_contents($file), true); return is_array($data) ? $data : []; }
function writeItems($file, $items) { return file_put_contents($file, json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false; }
function authorized() { return !empty($_SESSION['gallery_admin']); }
function needAuth() { if (!authorized()) respond(['error' => '請先登入管理後台。'], 401); }
function clean($value) { return trim((string)$value); }

$action = $_REQUEST['action'] ?? 'list';
if ($action === 'list') respond(['items' => items($dataFile)]);
if ($action === 'session') respond(['loggedIn' => authorized()]);
if ($action === 'login') {
  if (hash_equals($config['admin_password'], (string)($_POST['password'] ?? ''))) { $_SESSION['gallery_admin'] = true; respond(['ok' => true]); }
  respond(['error' => '密碼不正確。'], 401);
}
if ($action === 'logout') { session_destroy(); respond(['ok' => true]); }
needAuth();

if ($action === 'save') {
  $id = clean($_POST['id'] ?? ''); $title = clean($_POST['title'] ?? ''); $description = clean($_POST['description'] ?? ''); $currentImage = clean($_POST['currentImage'] ?? '');
  if (!$id || !$title) respond(['error' => '請填寫圖片標題。'], 422);
  $image = $currentImage;
  if (!empty($_FILES['image']['tmp_name'])) {
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($_FILES['image']['tmp_name']);
    if (!isset($allowed[$mime])) respond(['error' => '僅接受 JPG、PNG、WEBP 或 GIF 圖片。'], 422);
    if ($_FILES['image']['size'] > 20 * 1024 * 1024) respond(['error' => '圖片不可超過 20MB。'], 422);
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $name)) respond(['error' => '主機無法儲存圖片，請確認 uploads 資料夾可寫入。'], 500);
    $image = 'uploads/' . $name;
  }
  if (!$image) respond(['error' => '請選擇一張圖片。'], 422);
  $all = items($dataFile); $found = false;
  foreach ($all as &$item) { if ($item['id'] === $id) { $item = compact('id', 'title', 'description', 'image'); $found = true; break; } }
  unset($item); if (!$found) $all[] = compact('id', 'title', 'description', 'image');
  if (!writeItems($dataFile, $all)) respond(['error' => '主機無法更新資料，請確認 data 資料夾可寫入。'], 500);
  respond(['ok' => true, 'items' => $all]);
}
if ($action === 'delete') {
  $id = clean($_POST['id'] ?? ''); $all = items($dataFile); $removed = null;
  $all = array_values(array_filter($all, function($item) use ($id, &$removed) { if ($item['id'] === $id) { $removed = $item; return false; } return true; }));
  if ($removed && str_starts_with($removed['image'], 'uploads/')) @unlink(__DIR__ . '/' . $removed['image']);
  if (!writeItems($dataFile, $all)) respond(['error' => '主機無法更新資料。'], 500);
  respond(['ok' => true, 'items' => $all]);
}
if ($action === 'reorder') {
  $order = json_decode($_POST['order'] ?? '[]', true); if (!is_array($order)) respond(['error' => '排序資料不正確。'], 422);
  $byId = []; foreach (items($dataFile) as $item) $byId[$item['id']] = $item; $all = [];
  foreach ($order as $id) if (isset($byId[$id])) { $all[] = $byId[$id]; unset($byId[$id]); }
  foreach ($byId as $item) $all[] = $item;
  if (!writeItems($dataFile, $all)) respond(['error' => '主機無法更新資料。'], 500);
  respond(['ok' => true, 'items' => $all]);
}
respond(['error' => '未知操作。'], 404);
