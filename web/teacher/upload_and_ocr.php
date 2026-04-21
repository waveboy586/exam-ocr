<?php
// web/teacher/upload_and_ocr.php
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $extra = []) {
  http_response_code(400);
  echo json_encode(array_merge(["ok"=>false,"error"=>$msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_FILES['pdf'])) fail("ไม่พบไฟล์อัปโหลด (field name ต้องชื่อ pdf)");

$f = $_FILES['pdf'];
if ($f['error'] !== UPLOAD_ERR_OK) fail("อัปโหลดล้มเหลว", ["code"=>$f['error']]);

// 1) ตรวจนามสกุล
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf') fail("ไฟล์ต้องเป็น .pdf เท่านั้น");

// 2) ตรวจ MIME แบบจริงจังด้วย finfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);
if ($mime !== 'application/pdf') fail("ไฟล์นี้ไม่ใช่ PDF จริง", ["mime"=>$mime]);

// 3) เตรียม path เก็บไฟล์
$root = realpath(__DIR__ . "/../../"); // -> C:\xampp\htdocs\exam-ocr\web
$projectRoot = realpath($root . "/.."); // -> C:\xampp\htdocs\exam-ocr

$uploadsDir = $projectRoot . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "uploads";
$outDir     = $projectRoot . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "outputs";
$logDir     = $projectRoot . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "logs";

@mkdir($uploadsDir, 0777, true);
@mkdir($outDir, 0777, true);
@mkdir($logDir, 0777, true);

$stamp = date("Ymd_His");
$safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
$pdfPath = $uploadsDir . DIRECTORY_SEPARATOR . $safeBase . "_" . $stamp . ".pdf";
$mdPath  = $outDir . DIRECTORY_SEPARATOR . $safeBase . "_" . $stamp . ".md";
$jsonPath= $outDir . DIRECTORY_SEPARATOR . $safeBase . "_" . $stamp . ".json";
$logPath = $logDir . DIRECTORY_SEPARATOR . $safeBase . "_" . $stamp . ".log";

if (!move_uploaded_file($f['tmp_name'], $pdfPath)) {
  fail("ย้ายไฟล์ไป storage ไม่สำเร็จ");
}

// 4) ระบุ python ที่จะใช้ (PYTHON_BIN env → Windows venv → python3/python)
$python = getenv('PYTHON_BIN') ?: ($projectRoot . DIRECTORY_SEPARATOR . "venv" . DIRECTORY_SEPARATOR . "Scripts" . DIRECTORY_SEPARATOR . "python.exe");
if (!@file_exists($python)) {
  $python = PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/bin/python3';
}

// 5) สั่งรัน ocr.py -> md และ parse_exam.py -> json
$ocrPy   = $projectRoot . DIRECTORY_SEPARATOR . "ocr.py";
$parsePy = $projectRoot . DIRECTORY_SEPARATOR . "parse_exam.py";

$cmd1 = escapeshellarg($python) . " " . escapeshellarg($ocrPy)
      . " --input " . escapeshellarg($pdfPath)
      . " --output " . escapeshellarg($mdPath);

$cmd2 = escapeshellarg($python) . " " . escapeshellarg($parsePy)
      . " --input " . escapeshellarg($mdPath)
      . " --output " . escapeshellarg($jsonPath);

// ใช้ proc_open เพื่อเก็บ stdout/stderr
function run_cmd($cmd, $cwd, $logPath) {
  $des = [
    0 => ["pipe","r"],
    1 => ["pipe","w"],
    2 => ["pipe","w"],
  ];
  $p = proc_open($cmd, $des, $pipes, $cwd);
  if (!is_resource($p)) return ["code"=>-1, "out"=>"", "err"=>"proc_open failed"];

  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $code = proc_close($p);

  file_put_contents($logPath, "CMD: $cmd\n\nOUT:\n$out\n\nERR:\n$err\n\nEXIT:$code\n\n", FILE_APPEND);
  return ["code"=>$code, "out"=>$out, "err"=>$err];
}

$r1 = run_cmd($cmd1, $projectRoot, $logPath);
if ($r1["code"] !== 0) fail("OCR ล้มเหลว", ["log"=>$logPath, "stderr"=>$r1["err"]]);

$r2 = run_cmd($cmd2, $projectRoot, $logPath);
if ($r2["code"] !== 0) fail("Parse ล้มเหลว", ["log"=>$logPath, "stderr"=>$r2["err"]]);

if (!file_exists($jsonPath)) fail("ไม่พบไฟล์ json หลัง parse", ["log"=>$logPath]);

// 6) โหลด json เพื่อ “สร้างโจทย์อัตโนมัติ” (ตอนนี้ทำแค่ return กลับไปก่อน)
$data = json_decode(file_get_contents($jsonPath), true);
if (!$data) fail("json อ่านไม่ได้/รูปแบบไม่ถูก", ["log"=>$logPath]);

echo json_encode([
  "ok" => true,
  "pdfPath" => $pdfPath,
  "mdPath" => $mdPath,
  "jsonPath" => $jsonPath,
  "summary" => [
    "exam_title" => $data["exam_title"] ?? null,
    "sections" => isset($data["sections"]) ? count($data["sections"]) : 0
  ]
], JSON_UNESCAPED_UNICODE);
