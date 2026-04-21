<?php
session_name('TEACHERSESS');
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_files') {
  session_write_close(); // ปิด session ชั่วคราวเพื่อให้ไฟล์ถูกลบทันทีโดยไม่ติดคิว
  header('Content-Type: application/json');
  $inputJSON = file_get_contents('php://input');
  $inputData = json_decode($inputJSON, true);

  if (isset($inputData['files']) && is_array($inputData['files'])) {
    foreach ($inputData['files'] as $filePath) {
      $fullPath = __DIR__ . '/' . $row;
      // ลบเฉพาะไฟล์ในโฟลเดอร์ที่กำหนดเพื่อความปลอดภัย
      if (preg_match('/storage\/(outputs|uploads)\//', $filePath)) {
        $fullPath = __DIR__ . '/' . $filePath;
        if (file_exists($fullPath)) {
          @unlink($fullPath);
        }
      }
    }
    echo json_encode(['status' => 'success']);
  }
  exit;
}
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
  header('Location: teacher_login.php');
  exit;
}
// [AJAX] ส่วนจัดการบันทึกลง Database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_db') {
  header('Content-Type: application/json');

  $inputJSON = file_get_contents('php://input');
  $inputData = json_decode($inputJSON, true);
  //เพิ่มuser_id ลงไปใน database
  if (isset($_SESSION['user_id'])) {
    $inputData['created_by'] = $_SESSION['user_id'];
  }

  if (!$inputData) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
  }

  $outputDir = __DIR__ . '/storage/outputs';
  if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0777, true);
  }

  $tempFile = $outputDir . '/temp_import_' . time() . '.json';
  file_put_contents($tempFile, json_encode($inputData, JSON_UNESCAPED_UNICODE));

  $pythonScript = realpath(__DIR__ . '/../import_to_mysql.py');

  if (!$pythonScript || !file_exists($pythonScript)) {
    echo json_encode(['status' => 'error', 'message' => 'Python script not found at: ' . $pythonScript]);
    @unlink($tempFile);
    exit;
  }

  $pythonExec = getenv('PYTHON_BIN') ?: "C:/xampp/htdocs/exam-ocr/venv/Scripts/python.exe";
  if (!@file_exists($pythonExec)) {
    $pythonExec = PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/bin/python3';
  }

  $command = $pythonExec . " " . escapeshellarg($pythonScript) . " " . escapeshellarg($tempFile);
  $output = shell_exec($command . " 2>&1");
  @unlink($tempFile);


  $decodedOutput = json_decode($output, true);
  if ($decodedOutput) {
    if (isset($decodedOutput['status']) && $decodedOutput['status'] === 'success') {
      //ดึง Path ของ JSON ล่าสุด 
      $jPath = $_SESSION['last_json_path'] ?? '';

      if (!empty($jPath)) {
        $baseName = pathinfo($jPath, PATHINFO_FILENAME);
        $outputFolder = dirname($jPath); // โฟลเดอร์ storage/outputs
        $uploadFolder = realpath($outputFolder . '/../uploads'); // ถอยกลับไป storage/uploads

        // ลบไฟล์ Markdown (.md) - อ้างอิงชื่อเดียวกับ JSON ในโฟลเดอร์ outputs
        $mdPath = $outputFolder . DIRECTORY_SEPARATOR . $baseName . '.md';
        if (file_exists($mdPath)) {
          @unlink($mdPath);
        }

        //ลบไฟล์ PDF - อ้างอิงชื่อเดียวกับ JSON ในโฟลเดอร์ uploads
        $pdfPath = $uploadFolder . DIRECTORY_SEPARATOR . $baseName . '.pdf';
        if (file_exists($pdfPath)) {
          @unlink($pdfPath);
        }
      }
    }
    echo $output;
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Python Execution Error: ' . $output]);
  }
  exit;
}

//ลบไฟล์ชั่วคราวเมื่อกดกลับ
if (isset($_GET['action']) && $_GET['action'] === 'cleanup') {
  $jPath = $_SESSION['last_json_path'] ?? '';

  if (!empty($jPath)) {
    $jPath = fixPath($jPath);
    $baseName = pathinfo($jPath, PATHINFO_FILENAME);
    $outputFolder = dirname($jPath); // โฟลเดอร์ storage/outputs
    $uploadFolder = realpath($outputFolder . '/../uploads'); // โฟลเดอร์ storage/uploads

    //ลบไฟล์ JSON (.json)
    if (file_exists($jPath)) {
      @unlink($jPath);
    }

    //ลบไฟล์ Markdown (.md) - อยู่ใน outputs เหมือน JSON
    $mdPath = $outputFolder . DIRECTORY_SEPARATOR . $baseName . '.md';
    if (file_exists($mdPath)) {
      @unlink($mdPath);
    }

    //ลบไฟล์ PDF (.pdf) - อยู่ในโฟลเดอร์ uploads
    $pdfPath = $uploadFolder . DIRECTORY_SEPARATOR . $baseName . '.pdf';
    if (file_exists($pdfPath)) {
      @unlink($pdfPath);
    }
  }

  // เคลียร์ค่าใน Session
  unset($_SESSION['last_json_path']);
  unset($_SESSION['last_md_path']);
  unset($_SESSION['last_pdf_path']);

  header("Location: teacher_home.php");
  exit;
}
//หา path json
$jsonPath = $_SESSION['last_json_path'] ?? '';
$outputDir = __DIR__ . '/storage/outputs';

function fixPath($p)
{
  return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
}

if (!empty($jsonPath)) {
  $jsonPath = fixPath($jsonPath);
  if (!is_file($jsonPath)) {
    $jsonPath = '';
  }
}

// ถ้าหาไม่เจอ ให้ไปค้นหา ไฟล์ JSON ล่าสุด
if (empty($jsonPath)) {
  // ค้นหาไฟล์ .json ทั้งหมด
  $files = glob($outputDir . '/*.json');

  if ($files && count($files) > 0) {
    // เรียงลำดับตามเวลา
    usort($files, function ($a, $b) {
      return filemtime($b) - filemtime($a);
    });

    // หยิบไฟล์แรก
    $jsonPath = $files[0];

    // อัปเดตกลับเข้า Session
    $_SESSION['last_json_path'] = $jsonPath;
  }
}

$data = [];

// เช็คครั้งสุดท้าย
if (!empty($jsonPath) && is_file($jsonPath)) {
  $raw = file_get_contents($jsonPath);
  $data = json_decode($raw, true);

  // ถ้า JSON พัง
  if (!$data) {
    $jsonError = json_last_error_msg(); // เพิ่มบรรทัดนี้
    echo "<div style='color:red; padding:20px;'>Error: ไฟล์ JSON เสียหาย (Path: $jsonPath)<br>JSON Error: $jsonError</div>";
    exit;
  }
} else {
  // กรณีหาไม่เจอจริงๆ ให้แสดง Error
  echo "<div style='padding:40px; text-align:center; font-family:sans-serif;'>";
  echo "<h2 style='color:red;'>ไม่พบข้อมูลไฟล์ข้อสอบ</h2>";
  echo "<p>ระบบพยายามค้นหาไฟล์ล่าสุดใน: <code>" . htmlspecialchars($outputDir) . "</code> แล้วแต่ไม่พบ</p>";
  echo "<p>สิ่งที่เป็นไปได้: Python อาจจะยังทำงานไม่เสร็จ หรือไม่มีไฟล์ JSON ถูกสร้างขึ้น</p>";
  echo "<a href='teacher_home.php' style='padding:10px 20px; background:#ddd; text-decoration:none; border-radius:5px;'>กลับหน้าหลัก</a>";
  echo "</div>";
  exit;
}

// Helper Functions
function normalize_type($t)
{
  $t = strtolower(trim((string) $t));
  if (in_array($t, ["multiple_choice", "mcq", "choice"]))
    return "multiple_choice";
  if (in_array($t, ["essay", "long_answer"]))
    return "essay";
  if (in_array($t, ["short_answer", "short", "text", "subjective", "subjective_subparts"]))
    return "short_answer";
  if (in_array($t, ["instruction", "header", "info"]))
    return "instruction";
  return $t ?: "short_answer";
}

function clean_choice_prefix($text)
{
  $pattern = '/^(\(?[a-zA-Z0-9ก-ฮ]+\s*[\.\)]\s*)/u';
  return preg_replace($pattern, '', $text);
}

function normalize_choices($choices)
{
  if (!is_array($choices) || empty($choices)) return [];
  $out = [];
  foreach ($choices as $c) {
    if (is_string($c)) {
      $out[] = ["text" => $c, "correct" => false];
    } else if (is_array($c)) {
      $text = $c['text'] ?? $c['label'] ?? "";
      $isCorrect = !empty($c['correct']) || !empty($c['is_correct']);
      if (!empty($text)) $out[] = ["text" => $text, "correct" => $isCorrect];
    }
  }
  return $out;
}

function extract_inline_choices($text, $hasSubs = false)
{
  if ($hasSubs) return null;
  // รองรับ: ก. ข. ค. ง. / a. b. c. d. / A) B) C) D) / 1. 2. 3. 4.
  $pattern = '/(?<!\w)(?=[กขคงก-ง]\.[ \t]|[a-dA-D][\.\)][ \t]|\([ก-งa-dA-D]\)[ \t]|[1-4]\.[ \t])/u';
  $parts = preg_split($pattern, $text);
  if (!$parts || count($parts) < 2) return null;

  $questionPart = trim($parts[0]);
  $choiceParts  = array_slice($parts, 1);

  if (count($choiceParts) < 3) return null;

  // ตรวจว่าแต่ละ choicePart ขึ้นต้นด้วย prefix จริงๆ
  $PREFIX = '/^(?:[กขคงก-ง][\.\)]|[a-dA-D][\.\)]|\([ก-งa-dA-D]\)|[1-4][\.\)])\s*/u';
  $choices = [];
  foreach ($choiceParts as $c) {
    $c = trim($c);
    if ($c === '') continue;
    // แต่ละ choice ต้องไม่ยาวเกินไป
    if (mb_strlen($c) > 200) return null;
    $cleanText = preg_replace($PREFIX, '', $c);
    $choices[] = ["text" => trim($cleanText), "correct" => false];
  }

  if (count($choices) < 3) return null;

  return ["question" => $questionPart, "choices" => $choices];
}

// แปลง JSON เป็น Structure ของ PHP
$examTitle = "ข้อสอบนำเข้า";
$instructions = "";
$sections = [];

if (!empty($data)) {
  // โค้ดเดิมของคุณที่ใช้ดึงชื่อจาก JSON
  $examTitle = $data['exam_title'] ?? $data[0]['exam_title'] ?? "ข้อสอบ";

  // เตรียมตัวแปรสำหรับจัดกลุ่ม
  $grouped = [];

  // เริ่มวนลูปอ่านโจทย์ทีละข้อจาก JSON
  foreach ($data as $index => $q) {
    // ข้ามส่วนที่ไม่ใช่ข้อมูลโจทย์
    if (!is_array($q) || isset($q['exam_title'])) continue;

    $secNo = $q['section'] ?? 1;
    $qId = (string)($q['id'] ?? $q['number'] ?? ($index + 1));

    // สร้างโครงสร้างส่วน (Section) ถ้ายังไม่มี
    if (!isset($grouped[$secNo])) {
      $grouped[$secNo] = [
        "section_order" => (int)$secNo,
        "section_title" => "ส่วนที่ " . $secNo,
        "questions" => []
      ];
    }

    $qText = $q['text'] ?? $q['question'] ?? "";
    $rawOptions  = $q['options'] ?? $q['choices'] ?? [];
    $rawSubItems = $q['sub_items'] ?? $q['sub_questions'] ?? $q['sub_parts'] ?? [];
    $hasSubs     = !empty($rawSubItems);
    $extractedChoices = null;
    if (empty($rawOptions)) {
      $extracted = extract_inline_choices($qText, $hasSubs);
      if ($extracted !== null) {
        $qText            = $extracted['question'];  // ตัดตัวเลือกออกจาก text
        $extractedChoices = $extracted['choices'];
      }
    }
    $qText = preg_replace('/[ \t]*_{6,}[ \t]*/', "\n", $qText);

    // ลบขีดที่ยังหลงเหลืออยู่ที่ต้นบรรทัดและท้ายบรรทัด
    $qText = preg_replace('/(?m)^[\s\x{00A0}]+_+[\s\x{00A0}]*/u', '', $qText);
    $qText = preg_replace('/(?m)[\s\x{00A0}]+_+[\s\x{00A0}]*$/u', '', $qText);

    $tables = [];
    $qText = preg_replace_callback('/<table.*?<\/table>/s', function ($matches) use (&$tables) {
      $placeholder = "##TABLE_PLACEHOLDER_" . count($tables) . "##";
      $tables[] = $matches[0];
      return $placeholder;
    }, $qText);

    $headerOutput = "";
    $codeBuffer = "";
    $codeOutput = "";

    if (strpos($qText, '```') !== false) {
      $parts = preg_split('/(```[a-zA-Z]*\s*.*?\s*```)/s', $qText, -1, PREG_SPLIT_DELIM_CAPTURE);

      foreach ($parts as $part) {
        if (preg_match('/^```(?:[a-zA-Z]*)\s*(.*?)\s*```$/s', $part, $matches)) {
          $inner = $matches[1];
          // ลบขีดติดกันที่เป็นขยะในโค้ด (2 ตัวขึ้นไป)
          $inner = preg_replace('/_{2,}/', '', $inner);
          $codeBuffer .= trim($inner) . "\n";
        } else {
          $text = trim($part);
          if ($text !== "") {
            $text = preg_replace('/(?m)^[\s\x{00A0}]*(?:c|java|python|cpp)[\s\x{00A0}]*$/i', '', $text);

            $text = preg_replace('/_{3,5}/', '<span class="editable-text-gap" contenteditable="true">_____</span>', $text);

            $headerOutput .= '<div class="question-header" contenteditable="true">' . nl2br($text) . '</div>';
          }
        }
      }

      if (trim($codeBuffer) !== "") {
        $finalCode = htmlspecialchars(trim($codeBuffer));
        $codeOutput = '<div class="code-overflow-handler"><pre class="code-container" contenteditable="true">' . $finalCode . '</pre></div>';
      }
      $qText = $headerOutput . $codeOutput;
    } else {
      // กรณีไม่มี Code Block
      $qText = preg_replace('/_{3,5}/', '<span class="editable-text-gap" contenteditable="true">_____</span>', $qText);
      $qText = '<div class="question-header" contenteditable="true">' . nl2br($qText) . '</div>';
    }

    //  คืนค่าตาราง HTML
    foreach ($tables as $index => $tableHtml) {
      $placeholder = "##TABLE_PLACEHOLDER_" . $index . "##";
      $qText = str_replace($placeholder, '<div class="table-responsive-wrapper" contenteditable="true">' . $tableHtml . '</div>', $qText);
    }

    // Logic การรวมข้อย่อย
    $existingKey = -1;
    foreach ($grouped[$secNo]["questions"] as $idx => $existingQ) {
      if ((string)$existingQ['id'] === $qId) {
        $existingKey = $idx;
        break;
      }
    }
    // เช็คว่าขึ้นต้นด้วยเลขย่อยหรือไม่
    $isSubPattern = preg_match('/^\d+(\.\d+)+\s+/', ltrim($qText));
    if ($existingKey > -1) {
      // เจอ ID ซ้ำ -> นำไปเป็นข้อย่อย
      $grouped[$secNo]["questions"][$existingKey]["sub_questions"][] = ["question" => $qText, "answer" => ""];
    } else if ($isSubPattern && count($grouped[$secNo]["questions"]) > 0) {
      $lastIdx = count($grouped[$secNo]["questions"]) - 1;
      $grouped[$secNo]["questions"][$lastIdx]["sub_questions"][] = ["question" => $qText, "answer" => ""];
    } else {
      // --- กรณีเป็นข้อใหม่
      $rawSubs = $q['sub_items'] ?? $q['sub_questions'] ?? $q['sub_parts'] ?? [];
      $formattedSubs = [];
      foreach ((array)$rawSubs as $sub) {
        if (is_string($sub)) $formattedSubs[] = ["question" => $sub, "answer" => ""];
        else if (is_array($sub)) {
          $formattedSubs[] = [
            "question" => $sub['question'] ?? $sub['text'] ?? "",
            "answer" => $sub['answer'] ?? $sub['ans'] ?? ""
          ];
        }
      }

      $rawType = $q['type'] ?? "short_answer";
      $finalType = normalize_type($rawType);
      if (!empty($formattedSubs)) $finalType = "short_answer";
      $finalChoices = normalize_choices($q['options'] ?? $q['choices'] ?? []);
      $finalNote    = $q['note'] ?? "";
      if ($extractedChoices !== null) {
        $finalChoices = $extractedChoices;
        $finalType    = "multiple_choice";
        $finalNote = preg_replace('/ตัวเลือกหายหมด.*?(\n|$)/u', '', (string)$finalNote);
        $finalNote = preg_replace('/All options missing.*?(\n|$)/i', '', (string)$finalNote);
        $finalNote = trim((string)$finalNote);
      }

      $grouped[$secNo]["questions"][] = [
        "id" => $qId,
        "number" => $q['number'] ?? $qId,
        "question" => $qText,
        "description" => $q['description'] ?? "",
        "type" => $finalType,
        "choices" => $finalChoices,
        "sub_questions" => $formattedSubs,
        "answer" => $q['answer'] ?? "",
        "note" => $finalNote
      ];
    }
  }
  // แปลงข้อมูลที่จัดกลุ่มแล้วเข้าสู่ตัวแปร $sections หลัก
  ksort($grouped);
  $sections = array_values($grouped);
}

$totalQ = 0;
foreach ($sections as $s) {
  $qs = $s['questions'] ?? [];
  if (is_array($qs))
    $totalQ += count($qs);
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($examTitle) ?></title>
  <link rel="stylesheet" href="style.css">
  <style>
    .media-menu {
      position: fixed;
      background: #fff;
      border: 1px solid var(--line);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
      border-radius: 8px;
      width: 160px;
      display: none;
      flex-direction: column;
      z-index: 9999;
      overflow: hidden;
    }

    .media-menu button {
      background: none;
      border: none;
      padding: 12px 16px;
      text-align: left;
      cursor: pointer;
      font-size: 14px;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 10px;
      transition: background 0.1s;
    }

    .media-menu button:hover {
      background: #f3f4f6;
    }

    .media-menu button svg {
      width: 18px;
      height: 18px;
      fill: var(--muted);
    }

    .more-menu {
      position: absolute;
      bottom: 45px;
      right: 0;
      background: #fff;
      border: 1px solid var(--line);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border-radius: 8px;
      width: 180px;
      display: none;
      flex-direction: column;
      z-index: 100;
      padding: 6px 0;
    }

    .more-menu button {
      background: none;
      border: none;
      padding: 10px 16px;
      text-align: left;
      cursor: pointer;
      font-size: 14px;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 10px;
      width: 100%;
    }

    .more-menu button:hover {
      background: #f9fafb;
    }

    .more-menu .check-icon {
      width: 16px;
      visibility: hidden;
      color: var(--brand);
      font-weight: bold;
    }

    .more-menu button.checked .check-icon {
      visibility: visible;
    }

    .more-menu button.text-danger {
      color: var(--danger);
    }

    .more-divider {
      height: 1px;
      background: var(--line);
      margin: 4px 0;
    }

    .move-label {
      font-size: 11px;
      color: var(--muted);
      padding: 4px 16px;
      font-weight: 600;
    }

    .move-options {
      display: flex;
      flex-direction: column;
    }

    @media (max-width: 900px) {
      .qFloatToolbar {
        position: relative;
        right: auto;
        top: auto;
        width: 100%;
        flex-direction: row;
        justify-content: center;
        background: #fff;
        padding: 8px;
        border-radius: 0 0 16px 16px;
        border: none;
        border-top: 1px solid var(--line);
        box-shadow: none;
        margin-top: 0;
        gap: 16px;
      }

      .qFloatDivider {
        width: 1px;
        height: 24px;
        margin: 0 2px;
      }
    }

    .scoreWrap {
      display: flex;
      gap: 6px;
      min-width: 100px;
    }

    .scoreInput {
      width: 100px;
      padding: 10px 12px;
      border: 1px solid var(--line, #e5e7eb);
      border-radius: 12px;
      outline: none;
      text-align: center;
    }

    .scoreInput:focus {
      border-color: rgba(15, 118, 110, .55);
      box-shadow: 0 0 0 4px rgba(15, 118, 110, .14);
    }

    /* --- ชุดสไตล์สำหรับ Code Block ก้อนเดียว --- */

    /* 1. คอนเทนเนอร์หลักและส่วนหัวคำถาม (Editable) */
    .question-header {
      display: block;
      margin-bottom: 15px;
      font-size: 16px;
      font-weight: bold;
      color: #1a1a1a;
      line-height: 1.6;
      outline: none !important;
      cursor: text !important;
      user-select: text !important;
    }

    /* 2. จัดการก้อนโค้ด (ก้อนเดียวและลบขีดข้างในได้) */
    .code-overflow-handler {
      width: 100% !important;
      max-width: 100% !important;
      overflow: hidden;
      margin: 15px 0;
    }

    .code-container {
      display: block !important;
      white-space: pre-wrap !important;
      /* แก้เป็น pre-wrap เพื่อให้เคอร์เซอร์เมาส์แม่นยำเวลาคลิกลบ */
      background-color: #ffffff !important;
      border: 1px solid #000 !important;
      padding: 20px !important;
      font-family: 'Consolas', 'Monaco', monospace !important;
      font-size: 14px !important;
      line-height: 1.5 !important;
      overflow-x: auto !important;
      max-width: 100%;
      outline: none !important;
      cursor: text !important;
      user-select: text !important;
      /* บังคับให้คลุมดำได้ */
    }

    /* ป้ายกำกับด้านบนกล่องโค้ด */
    .code-container::before {
      content: "[ โปรแกรมสำหรับคำถาม ]";
      display: block;
      font-size: 11px;
      font-weight: bold;
      color: #666;
      margin-bottom: 12px;
      border-bottom: 1px dashed #ccc;
      padding-bottom: 5px;
      user-select: none;
      /* ป้องกันไม่ให้คลุมดำโดนป้ายกำกับ */
    }

    /* 3. ตาราง (ยุบรวมจาก 3 ชุดเหลือชุดเดียวที่สมบูรณ์ที่สุด) */
    .table-responsive-wrapper {
      width: 100%;
      margin: 20px 0;
      overflow-x: auto;
    }

    .table-responsive-wrapper table {
      width: 100% !important;
      border-collapse: collapse !important;
      border: 1px solid #000 !important;
      table-layout: auto;
    }

    .table-responsive-wrapper td,
    .table-responsive-wrapper th {
      border: 1px solid #000 !important;
      padding: 10px !important;
      text-align: left;
      min-height: 40px;
      user-select: text !important;
    }

    /* 4. ช่องเติมคำ (แบบที่คลิกลบหรือพิมพ์ทับได้) */
    .editable-gap {
      border-bottom: 2px solid #000;
      min-width: 60px;
      display: inline-block;
      vertical-align: bottom;
      outline: none !important;
    }

    /* 5. สไตล์เสริมเมื่อมีการโฟกัสหรือชี้เมาส์ */
    [contenteditable="true"]:hover {
      background-color: rgba(0, 128, 128, 0.03) !important;
      /* ไฮไลท์สีฟ้าอ่อนเพื่อให้รู้ว่าลบขีดทิ้งได้ */
    }

    /* ลบสไตล์เส้นใต้ที่เป็นขยะในก้อนโค้ด */
    .code-container .answer-gap {
      border-bottom: none !important;
      display: inline;
    }

    .editable-content img {
      max-width: 100%;
      height: auto;
      border-radius: 8px;
      border: 1px solid var(--line);
      margin: 10px 0;
    }

    .editable-content video {
      max-width: 100%;
      border-radius: 8px;
    }
  </style>
</head>

<body>
  <div class="topbar">
    <div class="topbar-inner">
      <div class="page-title">ข้อสอบ</div>
      <div class="top-actions">
        <a class="btn ghost" href="?action=cleanup"
          onclick="return confirm('หากย้อนกลับ หน้าที่สร้างไว้จะหาย หากยังไม่บันทึก ต้องการดำเนินการต่อหรือไม่?');">←
          ยกเลิก/กลับ</a>
        <button class="btn primary" type="button" id="saveBtn">บันทึกข้อสอบ</button>
      </div>
    </div>
  </div>

  <div class="wrap" id="examWrapper">

    <div class="headerCard">
      <div class="headerStripe"></div>
      <div class="headerBody">
        <div class="label">ชื่อข้อสอบ</div>
        <input class="titleInput" id="examTitle" value="<?= htmlspecialchars($examTitle) ?>"
          placeholder="เช่น แบบทดสอบบทที่ 1">
        <div class="label">คำชี้แจง</div>
        <textarea class="auto-textarea" id="examInstructions"
          placeholder="พิมพ์คำชี้แจง..."><?= htmlspecialchars($instructions) ?></textarea>
        <div class="mutedSmall">จำนวนข้อทั้งหมด: <span id="totalQCount"><?= (int) $totalQ ?></span></div>
      </div>
    </div>

    <?php foreach ($sections as $si => $sec):
      $secTitle = $sec['section_title'] ?? ("ส่วนที่ " . ($sec['section_order'] ?? ($si + 1)));
      $questions = $sec['questions'] ?? [];
      if (!is_array($questions))
        $questions = [];
    ?>
      <div class="sectionHeader">
        <div class="sectionTitle" contenteditable="true"><?= htmlspecialchars($secTitle) ?></div>
        <div style="position:relative">
          <button type="button" class="iconBtn" onclick="toggleMoreMenu(event, this)">⋮</button>
          <div class="more-menu">
            <button type="button" class="text-danger" onclick="deleteSection(this)">
              🗑️ ลบส่วนนี้
            </button>
          </div>
        </div>
      </div>

      <div id="section-<?= (int) $si ?>" class="section" data-section-index="<?= (int) $si ?>">
        <?php foreach ($questions as $qi => $q):
          $qType = ($q['type'] ?? "short_answer");
          $qText = $q['question'] ?? "";
          $qDesc = $q['description'] ?? "";
          $qNote = $q['note'] ?? null;
          $choices = normalize_choices($q['choices'] ?? []);
          $hasNote = is_string($qNote) && trim($qNote) !== "";
          $noteSaysChoicesMissing = false;
          if ($hasNote) {
            $noteSaysChoicesMissing = (bool) preg_match('/(ตัวเลือก\s*หาย|ช้อยส์\s*หาย|ตัวเลือก\s*หายหมด|choices?\s*missing|options?\s*missing)/iu', (string) $qNote);
          }
          $hasDesc = !empty($qDesc);
          $isInst = ($qType === 'instruction');
          $mid = (int) $si . "_" . (int) $qi;

          $subQuestions = [];
          if (isset($q['sub_questions']) && is_array($q['sub_questions'])) {
            $subQuestions = $q['sub_questions'];
          }
        ?>
          <div class="qCard <?= $hasNote ? 'has-note' : '' ?> <?= $isInst ? 'type-instruction' : '' ?>"
            data-qcard
            data-qnumber="<?= htmlspecialchars((string) ($q['number'] ?? '')) ?>"
            data-subjson='<?= htmlspecialchars(json_encode($q['sub_questions'] ?? [], JSON_UNESCAPED_UNICODE)) ?>'>
            <div class="leftStripe"></div>

            <div class="qFloatToolbar" data-qtoolbar>
              <button type="button" class="qFloatBtn" title="เพิ่มรูปภาพ" onclick="openMediaMenu(event, '<?= $mid ?>','image')">
                <svg viewBox="0 0 24 24">
                  <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                </svg>
              </button>
              <button type="button" class="qFloatBtn" title="เพิ่มวิดีโอ" onclick="openMediaMenu(event, '<?= $mid ?>','video')">
                <svg viewBox="0 0 24 24">
                  <path d="M10 8v8l6-4-6-4zm9-5H5c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12H5V5h14v10z" />
                </svg>
              </button>
              <button type="button" class="qFloatBtn" title="เพิ่มเสียง" onclick="openMediaMenu(event, '<?= $mid ?>','audio')">
                <svg viewBox="0 0 24 24">
                  <path d="M12 3v9.28c-.47-.17-.97-.28-1.5-.28C8.01 12 6 14.01 6 16.5S8.01 21 10.5 21c2.31 0 4.16-1.75 4.45-4H15V6h4V3h-7z" />
                </svg>
              </button>
              <div class="qFloatDivider"></div>
              <button type="button" class="qFloatBtn" title="เพิ่มคำถาม (+)" onclick="onToolbarAdd(this, 'question')">
                <svg viewBox="0 0 24 24">
                  <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                </svg>
              </button>
              <button type="button" class="qFloatBtn" title="เพิ่มชื่อเรื่อง/รายละเอียด (Tt)" onclick="onToolbarAdd(this, 'title')">
                <svg viewBox="0 0 24 24">
                  <path d="M2.5 4v3h5v12h3V7h5V4h-13zm19 5h-9v3h3v7h3v-7h3V9z" />
                </svg>
              </button>
              <button type="button" class="qFloatBtn" title="เพิ่มส่วนใหม่ (Section)" onclick="onToolbarAdd(this, 'section')">
                <svg viewBox="0 0 24 24">
                  <path d="M19 13H5v-2h14v2zM5 15h14v2H5v-2z" />
                </svg>
              </button>
            </div>

            <input type="file" id="media-file-<?= $mid ?>" style="display:none" accept="image/*,video/*,audio/*" onchange="onMediaPicked(this,'<?= $mid ?>')">

            <div class="qBody">
              <div class="qGrid">
                <div style="width:100%">
                  <div class="auto-textarea qText editable-content"
                    data-qtext
                    contenteditable="true"
                    placeholder="<?= $isInst ? 'พิมพ์ข้อความ/คำชี้แจง...' : 'พิมพ์คำถาม...' ?>"
                    style="min-height: 50px; border: 1px solid #ccc; padding: 10px; border-radius: 4px; background: #fff; line-height: 1.5;">
                    <?= $qText ?>
                  </div>

                  <div class="auto-textarea qDesc editable-content"
                    data-qdesc
                    contenteditable="true"
                    placeholder="คำอธิบาย (ระบุหรือไม่ก็ได้)"
                    style="min-height: 30px; border: 1px solid #ccc; padding: 10px; border-radius: 4px; background: #fff; margin-top: 8px; <?= $hasDesc ? '' : 'display:none' ?>">
                    <?= $qDesc ?>
                  </div>
                </div>

                <select class="qType" data-qtype>
                  <option value="multiple_choice" <?= $qType === 'multiple_choice' ? 'selected' : '' ?>>ตัวเลือก</option>
                  <option value="short_answer" <?= $qType === 'short_answer' ? 'selected' : '' ?>>คำตอบสั้น</option>
                  <option value="essay" <?= $qType === 'essay' ? 'selected' : '' ?>>คำตอบยาว</option>
                  <option value="instruction" <?= $qType === 'instruction' ? 'selected' : '' ?>>ข้อความ/คำชี้แจง</option>
                </select>
              </div>

              <div class="opts" data-opts style="<?= $qType === 'multiple_choice' ? '' : 'display:none' ?>">
                <?php
                if (count($choices) === 0 && !$noteSaysChoicesMissing && !$isInst) {
                  $choices = [["text" => "ตัวเลือก 1", "correct" => false], ["text" => "ตัวเลือก 2", "correct" => false], ["text" => "ตัวเลือก 3", "correct" => false], ["text" => "ตัวเลือก 4", "correct" => false]];
                }
                foreach ($choices as $ci => $cObj):
                  $ct = $cObj['text'];
                  $isCorrect = $cObj['correct'];
                ?>
                  <div class="optRow" data-optrow>
                    <div class="optBullet <?= $isCorrect ? 'correct' : '' ?>" title="คลิกเพื่อตั้งเป็นเฉลย"></div>
                    <input class="optInput" data-optinput value="<?= htmlspecialchars($ct) ?>" />
                    <button type="button" class="trash" data-delopt title="ลบตัวเลือก">🗑️</button>
                  </div>
                <?php endforeach; ?>
                <button type="button" class="btn" style="margin-top:8px" data-addopt>+ เพิ่มตัวเลือก</button>
              </div>

              <div class="answerBox" data-answer style="<?= ($qType !== 'multiple_choice' && $qType !== 'instruction') ? '' : 'display:none' ?>">
                <div class="subWrap" data-subwrap style="<?= $qType === 'short_answer' ? '' : 'display:none' ?>">
                  <div style="margin-bottom:12px; border-bottom:1px solid #eee; padding-bottom:12px;">
                    <div class="mutedSmall" style="margin-bottom:6px;">เฉลยคำตอบ (กรณีไม่มีข้อย่อย หรือเป็นคำตอบรวม):</div>
                    <input class="optInput" data-main-answer placeholder="ระบุคำตอบที่ถูกต้อง..."
                      value="<?= htmlspecialchars((string) ($q['answer'] ?? '')) ?>" style="width:100%;" />
                  </div>

                  <div class="mutedSmall" style="margin:0 0 8px;">โจทย์ย่อย (ถ้ามี):</div>
                  <div data-sublist></div>
                  <button type="button" class="btn" data-addsub style="margin-top:10px">+ เพิ่มโจทย์ย่อย</button>
                </div>

                <div class="essayWrap" data-essaywrap style="<?= $qType === 'essay' ? '' : 'display:none' ?>">
                  <div class="mutedSmall" style="margin:0 0 8px;">คำตอบยาว</div>
                  <textarea class="auto-textarea" data-essayanswer placeholder="คำตอบ"
                    style="min-height:90px"><?= htmlspecialchars((string) ($q['essay_answer'] ?? '')) ?></textarea>
                </div>
              </div>

              <?php if ($hasNote): ?>
                <div class="noteBox">Note: <?= htmlspecialchars($qNote) ?></div>
              <?php endif; ?>

              <div class="cardFooter">
                <div style="flex:1"></div>
                <div class="scoreWrap">
                  <div class="mutedSmall" style="margin-bottom:6px;">คะแนน</div>
                  <input type="number" class="scoreInput" data-score min="0" step="0.5" value="1" />
                </div>
                <div style="position:relative">
                  <button type="button" class="iconBtn" onclick="toggleMoreMenu(event, this)">⋮</button>
                  <div class="more-menu">
                    <button type="button" class="<?= $hasDesc ? 'checked' : '' ?>" onclick="toggleDescription(this)">
                      <span class="check-icon">✓</span> แสดงคำอธิบาย
                    </button>
                    <div class="more-divider"></div>
                    <div class="move-label">ย้ายไปที่...</div>
                    <div class="move-options"></div>
                    <div class="more-divider"></div>
                    <button type="button" class="text-danger" onclick="deleteCard(this)">
                      🗑️ ลบข้อนี้
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div id="globalMediaMenu" class="media-menu">
    <button type="button" onclick="handleMediaChoice('upload')"><svg viewBox="0 0 24 24">
        <path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z" />
      </svg> อัปโหลดจากเครื่อง</button>
  </div>

  <script>
    // --- Initialization & Helper Functions ---
    function applyTypeUI(card, type) {
      const opts = card.querySelector('[data-opts]');
      const ans = card.querySelector('[data-answer]');
      const subWrap = card.querySelector('[data-subwrap]');
      const essayWrap = card.querySelector('[data-essaywrap]');
      const qText = card.querySelector('[data-qtext]');

      // Reset Style
      card.classList.remove('type-instruction');
      qText.placeholder = 'พิมพ์คำถาม...';

      if (type === 'instruction') {
        card.classList.add('type-instruction');
        qText.placeholder = 'พิมพ์ข้อความ/คำชี้แจง...';
        opts.style.display = 'none';
        ans.style.display = 'none';
        return;
      }

      if (type === 'multiple_choice') {
        opts.style.display = '';
        ans.style.display = 'none';
        if (subWrap) subWrap.style.display = 'none';
        if (essayWrap) essayWrap.style.display = 'none';
        return;
      }

      opts.style.display = 'none';
      ans.style.display = '';

      if (type === 'short_answer') {
        if (subWrap) subWrap.style.display = '';
        if (essayWrap) essayWrap.style.display = 'none';
        ensureAtLeastOneSub(card);
      } else if (type === 'essay') {
        if (subWrap) subWrap.style.display = 'none';
        if (essayWrap) essayWrap.style.display = '';
      } else {
        if (subWrap) subWrap.style.display = '';
        if (essayWrap) essayWrap.style.display = 'none';
      }
    }

    function ensureAtLeastOneSub(card) {
      // ไม่บังคับสร้างข้อย่อยอัตโนมัติ
    }

    function addSubQuestion(card, qText = '', aText = '') {
      const list = card.querySelector('[data-sublist]');
      if (!list) return;
      const row = document.createElement('div');
      row.className = 'optRow';
      row.setAttribute('data-subrow', '');
      row.innerHTML = `
    <div style="width:18px;height:18px;border-radius:6px;border:2px solid var(--line);flex:0 0 auto"></div>
    <div class="optInput editable-content" data-subq contenteditable="true" placeholder="โจทย์ย่อย (ถ้ามี)" 
         style="min-height: 30px; border: 1px solid #ccc; padding: 8px; border-radius: 4px; background: #fff; flex:1;">
         ${qText}
    </div>
    <input class="optInput" data-suba placeholder="คำตอบ" value="${escapeHtml(aText)}" style="max-width:220px" />
    <button type="button" class="trash" data-delsub title="ลบโจทย์ย่อย">🗑️</button>
  `;
      list.appendChild(row);
    }

    function addOption(card, text = '', isCorrect = false) {
      const opts = card.querySelector('[data-opts]');
      const row = document.createElement('div');
      row.className = 'optRow';
      row.setAttribute('data-optrow', '');
      const correctClass = isCorrect ? 'correct' : '';
      row.innerHTML = `
      <div class="optBullet ${correctClass}" title="คลิกเพื่อตั้งเป็นเฉลย"></div>
      <input class="optInput" data-optinput value="${escapeHtml(text)}" />
      <button type="button" class="trash" data-delopt title="ลบตัวเลือก">🗑️</button>
    `;
      const addBtn = opts.querySelector('[data-addopt]');
      opts.insertBefore(row, addBtn);
    }

    function escapeHtml(str) {
      return String(str ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", "&#039;");
    }
    function splitInlineChoices(text) {
      if (!text) return null;
      const CHOICE_SPLIT_PATTERN = /(?=(?:^|\s)(?:\(\s*[a-eA-E]\s*\)\s|\(\s*[ก-ง]\s*\)\s|[a-eA-E][\.\)]\s|[ก-ง][\.\)]\s|\d+[\.\)]\s))/u;
      const parts = text.split(CHOICE_SPLIT_PATTERN).map(s => s.trim()).filter(Boolean);
      const CHOICE_PREFIX = /^(?:[ก-งa-dA-D][\.\)]|\([ก-งa-dA-D]\)|\d+[\.\)])\s+/u;
      const validChoices = parts.filter(p => CHOICE_PREFIX.test(p));

      if (validChoices.length >= 2 && validChoices.length === parts.length) {
        return validChoices.map(c => ({
          text: c.replace(CHOICE_PREFIX, '').trim(),
          correct: false
        }));
      }
      return null;
    }

    function createCardHTML(number, defaultType = 'multiple_choice') {
      const mid = 'new_' + Date.now() + Math.random().toString(36).substr(2, 5);
      const isInst = (defaultType === 'instruction');
      const ph = isInst ? 'พิมพ์ข้อความ/คำชี้แจง...' : 'พิมพ์คำถาม...';

      return `
      <div class="leftStripe" style="${isInst ? 'background:var(--muted)' : ''}"></div>
      <div class="qFloatToolbar" data-qtoolbar>
        <button type="button" class="qFloatBtn" title="เพิ่มรูปภาพ" onclick="openMediaMenu(event, '${mid}','image')"><svg viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg></button>
        <button type="button" class="qFloatBtn" title="เพิ่มวิดีโอ" onclick="openMediaMenu(event, '${mid}','video')"><svg viewBox="0 0 24 24"><path d="M10 8v8l6-4-6-4zm9-5H5c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12H5V5h14v10z"/></svg></button>
        <button type="button" class="qFloatBtn" title="เพิ่มเสียง" onclick="openMediaMenu(event, '${mid}','audio')"><svg viewBox="0 0 24 24"><path d="M12 3v9.28c-.47-.17-.97-.28-1.5-.28C8.01 12 6 14.01 6 16.5S8.01 21 10.5 21c2.31 0 4.16-1.75 4.45-4H15V6h4V3h-7z"/></svg></button>
        <div class="qFloatDivider"></div>
        <button type="button" class="qFloatBtn" title="เพิ่มคำถาม (+)" onclick="onToolbarAdd(this, 'question')"><svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg></button>
        <button type="button" class="qFloatBtn" title="เพิ่มชื่อเรื่อง/รายละเอียด (Tt)" onclick="onToolbarAdd(this, 'title')"><svg viewBox="0 0 24 24"><path d="M2.5 4v3h5v12h3V7h5V4h-13zm19 5h-9v3h3v7h3v-7h3V9z"/></svg></button>
        <button type="button" class="qFloatBtn" title="เพิ่มส่วนใหม่ (Section)" onclick="onToolbarAdd(this, 'section')"><svg viewBox="0 0 24 24"><path d="M19 13H5v-2h14v2zM5 15h14v2H5v-2z"/></svg></button>
      </div>
      
      <div class="qBody">
        <div class="qGrid">
          <div style="width:100%">
              <textarea class="auto-textarea qText" data-qtext placeholder="${ph}"></textarea>
              <textarea class="auto-textarea qDesc" data-qdesc placeholder="คำอธิบาย (ระบุหรือไม่ก็ได้)" style="display:none"></textarea>
          </div>
          <select class="qType" data-qtype>
            <option value="multiple_choice" ${defaultType === 'multiple_choice' ? 'selected' : ''}>ตัวเลือก</option>
            <option value="short_answer" ${defaultType === 'short_answer' ? 'selected' : ''}>คำตอบสั้น</option>
            <option value="essay" ${defaultType === 'essay' ? 'selected' : ''}>คำตอบยาว</option>
            <option value="instruction" ${defaultType === 'instruction' ? 'selected' : ''}>ข้อความ/คำชี้แจง</option>
          </select>
        </div>
        <div class="opts" data-opts style="${defaultType !== 'multiple_choice' ? 'display:none' : ''}">
          <button type="button" class="btn" style="margin-top:8px" data-addopt>+ เพิ่มตัวเลือก</button>
        </div>
        <div class="answerBox" data-answer style="${(defaultType === 'multiple_choice' || defaultType === 'instruction') ? 'display:none' : ''}">
          <div class="subWrap" data-subwrap style="${defaultType === 'short_answer' ? '' : 'display:none'}">
            <div style="margin-bottom:12px; border-bottom:1px solid #eee; padding-bottom:12px;">
                <div class="mutedSmall" style="margin-bottom:6px;">เฉลยคำตอบ (กรณีไม่มีข้อย่อย หรือเป็นคำตอบรวม):</div>
                <input class="optInput" data-main-answer placeholder="ระบุคำตอบที่ถูกต้อง..." style="width:100%;" />
            </div>
            <div class="mutedSmall" style="margin:0 0 8px;">โจทย์ย่อย (ถ้ามี):</div>
            <div data-sublist></div>
            <button type="button" class="btn" data-addsub style="margin-top:10px">+ เพิ่มโจทย์ย่อย</button>
          </div>
          <div class="essayWrap" data-essaywrap style="${defaultType === 'essay' ? '' : 'display:none'}">
            <div class="mutedSmall" style="margin:0 0 8px;">คำตอบยาว</div>
            <textarea class="auto-textarea" data-essayanswer placeholder="คำตอบ" style="min-height:90px"></textarea>
          </div>
        </div>
        <div class="cardFooter">
            <div style="flex:1"></div>
            <div class="scoreWrap">
  <div class="mutedSmall" style="margin-bottom:6px;">คะแนน</div>
  <input type="number" class="scoreInput" data-score min="0" step="0.5" value="1" />
</div>
            <div style="position:relative">
                <button type="button" class="iconBtn" onclick="toggleMoreMenu(event, this)">⋮</button>
                <div class="more-menu">
                    <button type="button" onclick="toggleDescription(this)">
                        <span class="check-icon">✓</span> แสดงคำอธิบาย
                    </button>
                    <div class="more-divider"></div>
                    <div class="move-label">ย้ายไปที่...</div>
                    <div class="move-options"></div>
                    <div class="more-divider"></div>
                    <button type="button" class="text-danger" onclick="deleteCard(this)">
                        🗑️ ลบข้อนี้
                    </button>
                </div>
            </div>
        </div>
      </div>
    `;
    }

    function addNewCard(type = 'multiple_choice', insertAfterElement = null) {
      let targetContainer = null;
      if (insertAfterElement) {
        targetContainer = insertAfterElement.parentNode;
      } else {
        targetContainer = document.querySelector('.section:last-of-type');
      }
      if (!targetContainer) return;

      let maxNo = 0;
      document.querySelectorAll('[data-qcard]').forEach(c => {
        const n = parseInt(c.dataset.qnumber || '0', 10);
        if (!Number.isNaN(n)) maxNo = Math.max(maxNo, n);
      });
      const nextNo = maxNo + 1;

      const card = document.createElement('div');
      card.className = 'qCard is-active ' + (type === 'instruction' ? 'type-instruction' : '');
      card.setAttribute('data-qcard', '');
      if (type !== 'instruction') card.dataset.qnumber = String(nextNo);

      card.innerHTML = createCardHTML(nextNo, type);
      document.querySelectorAll('.qCard').forEach(c => c.classList.remove('is-active'));

      if (insertAfterElement && insertAfterElement.nextSibling) {
        targetContainer.insertBefore(card, insertAfterElement.nextSibling);
      } else {
        targetContainer.appendChild(card);
      }
      card.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
      });

      if (type === 'multiple_choice') {
        addOption(card, 'ตัวเลือก 1');
        addOption(card, 'ตัวเลือก 2');
        addOption(card, 'ตัวเลือก 3');
        addOption(card, 'ตัวเลือก 4');
      }

      initAutoResize(card);
      updateTotalCount();
    }

    function addNewSection(insertAfterElement = null) {
      const wrap = document.getElementById('examWrapper');
      const allSections = document.querySelectorAll('.section');
      const nextIdx = allSections.length;

      const headerDiv = document.createElement('div');
      headerDiv.className = 'sectionHeader';
      headerDiv.innerHTML = `
      <div class="sectionTitle" contenteditable="true">ส่วนที่ ${nextIdx + 1}</div>
      <div style="position:relative">
           <button type="button" class="iconBtn" onclick="toggleMoreMenu(event, this)">⋮</button>
           <div class="more-menu">
              <button type="button" class="text-danger" onclick="deleteSection(this)">
                  🗑️ ลบส่วนนี้
              </button>
           </div>
      </div>
    `;
      const secDiv = document.createElement('div');
      secDiv.id = 'section-' + nextIdx;
      secDiv.className = 'section';
      secDiv.setAttribute('data-section-index', nextIdx);

      let referenceNode = null;
      if (insertAfterElement) {
        const currentSection = insertAfterElement.closest('.section');
        if (currentSection && currentSection.nextElementSibling) {
          referenceNode = currentSection.nextElementSibling;
        }
      }
      if (referenceNode) {
        wrap.insertBefore(headerDiv, referenceNode);
        wrap.insertBefore(secDiv, referenceNode);
      } else {
        wrap.appendChild(headerDiv);
        wrap.appendChild(secDiv);
      }
      headerDiv.scrollIntoView({
        behavior: 'smooth'
      });
    }

    function onToolbarAdd(btn, action) {
      const currentCard = btn.closest('.qCard');
      if (action === 'question') addNewCard('multiple_choice', currentCard);
      else if (action === 'title') addNewCard('instruction', currentCard);
      else if (action === 'section') addNewSection(currentCard);
    }

    function deleteCard(btn) {
      const card = btn.closest('[data-qcard]');
      if (confirm('ยืนยันลบข้อนี้?')) {
        card.remove();
        updateTotalCount();
      }
    }

    function deleteSection(btn) {
      const header = btn.closest('.sectionHeader');
      const sectionDiv = header.nextElementSibling;
      if (confirm('ยืนยันลบส่วนนี้ และคำถามทั้งหมดในส่วนนี้?')) {
        if (header) header.remove();
        if (sectionDiv) sectionDiv.remove();
        updateTotalCount();
      }
    }

    function updateTotalCount() {
      const c = document.querySelectorAll('[data-qcard]:not(.type-instruction)').length;
      const el = document.getElementById('totalQCount');
      if (el) el.textContent = c;
    }

    function toggleMoreMenu(e, btn) {
      e.stopPropagation();
      document.querySelectorAll('.more-menu').forEach(m => {
        if (m !== btn.nextElementSibling) m.style.display = 'none';
      });
      const menu = btn.nextElementSibling;
      const isOpening = (menu.style.display !== 'flex');
      menu.style.display = isOpening ? 'flex' : 'none';

      if (isOpening) {
        const moveContainer = menu.querySelector('.move-options');
        if (moveContainer) {
          moveContainer.innerHTML = '';
          const card = btn.closest('[data-qcard]');
          const currentSection = card.closest('.section');
          const sections = document.querySelectorAll('.section');

          if (sections.length <= 1) {
            moveContainer.innerHTML = '<div style="padding:4px 16px;color:#aaa;font-size:12px;">มีส่วนเดียว</div>';
          } else {
            sections.forEach((sec, idx) => {
              if (sec !== currentSection) {
                let title = 'ส่วนที่ ' + (idx + 1);
                let prev = sec.previousElementSibling;
                if (prev && prev.classList.contains('sectionHeader')) {
                  title = prev.querySelector('.sectionTitle').textContent.trim();
                }
                const mBtn = document.createElement('button');
                mBtn.type = 'button';
                mBtn.textContent = 'ไปยัง: ' + title;
                mBtn.onclick = function() {
                  sec.appendChild(card);
                  card.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                  });
                  menu.style.display = 'none';
                };
                moveContainer.appendChild(mBtn);
              }
            });
          }
        }
      }
    }

    function toggleDescription(btn) {
      const menu = btn.closest('.more-menu');
      const card = menu.closest('[data-qcard]');
      const descInput = card.querySelector('[data-qdesc]');
      if (descInput.style.display === 'none') {
        descInput.style.display = 'block';
        btn.classList.add('checked');
        descInput.focus();
      } else {
        descInput.style.display = 'none';
        btn.classList.remove('checked');
      }
      menu.style.display = 'none';
    }

    function autoResize(el) {
      if (!el) return;
      el.style.height = 'auto';
      el.style.height = (el.scrollHeight + 2) + 'px';
    }

    function initAutoResize(root = document) {
      root.querySelectorAll('textarea.auto-textarea').forEach(el => {
        autoResize(el);
        el.addEventListener('input', () => autoResize(el));
      });
    }

    let activeMediaContext = null;

    function openMediaMenu(e, mid, type) {
      e.stopPropagation();
      activeMediaContext = {
        mid: mid,
        type: type
      };
      const menu = document.getElementById('globalMediaMenu');
      const btn = e.currentTarget;
      const rect = btn.getBoundingClientRect();
      menu.style.top = (rect.top + window.scrollY) + 'px';
      menu.style.left = (rect.left + window.scrollX - 170) + 'px';
      menu.style.display = 'flex';
    }

    function handleMediaChoice(action) {
      document.getElementById('globalMediaMenu').style.display = 'none';
      if (!activeMediaContext) return;
      const {
        mid,
        type
      } = activeMediaContext;

      if (action === 'upload') {
        const input = document.getElementById('media-file-' + mid);
        if (input) {
          input.value = '';
          input.accept = type + '/*';
          input.click();
        }
      }
    }

    function onMediaPicked(input, mid) {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        const formData = new FormData();
        formData.append('file', file);

        // แสดงสถานะกำลังโหลด (ถ้าต้องการ)
        const qCard = input.closest('.qCard');

        fetch('upload_media.php', {
            method: 'POST',
            body: formData
          })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              let mediaHtml = '';
              const fType = file.type;

              if (fType.startsWith('image/')) {
                mediaHtml = `<img src="${data.filePath}" style="max-width:100%; display:block; margin:10px 0;">`;
              } else if (fType.startsWith('video/')) {
                mediaHtml = `<video controls style="max-width:100%; display:block; margin:10px 0;"><source src="${data.filePath}" type="${fType}"></video>`;
              } else if (fType.startsWith('audio/')) {
                mediaHtml = `<div style="margin:10px 0;"><audio controls style="width:100%;"><source src="${data.filePath}" type="${fType}"></audio></div>`;
              }

              const qText = qCard.querySelector('.qText');
              qText.innerHTML += mediaHtml;
            } else {
              alert("Upload failed: " + data.message);
            }
          })
          .catch(err => {
            console.error(err);
            alert("เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์");
          })
          .finally(() => {
            input.value = '';
          });
      }
    }

    function extractCleanHTML(el) {
      if (!el) return '';
      // กรณี textarea หรือ input → ใช้ value ตามปกติ
      if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
        return el.value.trim();
      }
      const clone = el.cloneNode(true);
      function unwrapDivs(root) {
        // ทำซ้ำจนกว่าจะไม่มี wrapper เหลือ
        let changed = true;
        while (changed) {
          changed = false;
          const wrappers = root.querySelectorAll(
            'div.question-header, div.editable-text-gap'
          );
          wrappers.forEach(w => {
            // ย้าย children ออกมาแทนที่ wrapper
            const frag = document.createDocumentFragment();
            while (w.firstChild) frag.appendChild(w.firstChild);
            w.parentNode.replaceChild(frag, w);
            changed = true;
          });
        }
      }

      unwrapDivs(clone);

      clone.querySelectorAll('[contenteditable]').forEach(el => {
        el.removeAttribute('contenteditable');
      });

      return clone.innerHTML.trim();
    }

    // Event Listeners
    document.addEventListener('DOMContentLoaded', () => {
      initAutoResize(document);
      document.querySelectorAll('[data-qcard]').forEach(card => {
        const qTypeEl = card.querySelector('[data-qtype]');
        if (!qTypeEl) return;

        const type = qTypeEl.value;
        const jsonStr = card.dataset.subjson;
        if (type === 'short_answer' && jsonStr) {
          try {
            // แปลง JSON string กลับเป็น Array ของโจทย์ย่อย
            const arr = JSON.parse(jsonStr);

            if (Array.isArray(arr) && arr.length > 0) {
              const list = card.querySelector('[data-sublist]');
              if (list) {
                // ล้างรายการเก่าทิ้งก่อนเพื่อป้องกันการแสดงผลซ้ำ
                list.innerHTML = '';
                arr.forEach(item => {
                  // รองรับทั้งโครงสร้าง {question: '', answer: ''} หรือ {text: '', ans: ''}
                  const q = item.question || item.text || '';
                  const a = item.answer || item.ans || '';
                  addSubQuestion(card, q, a);
                });
              }
            }
          } catch (e) {
            console.error("Error parsing sub-questions for card:", e);
          }
        }

        applyTypeUI(card, type);
        if (type === 'multiple_choice') {
          const hasChoices = card.querySelectorAll('[data-optrow]').length > 0;
          if (!hasChoices) {
            const qEl = card.querySelector('[data-qtext]');
            const rawText = qEl ? qEl.innerText : '';
            const extracted = splitInlineChoices(rawText);
            if (extracted) {
              extracted.forEach(c => addOption(card, c.text, c.correct));
            }
          }
        }
      });
      updateTotalCount();
    });

    document.getElementById('saveBtn').addEventListener('click', function() {
      const thisBtn = this;
      thisBtn.disabled = true;
      thisBtn.textContent = 'กำลังบันทึก...';

      const payload = {
        exam_title: document.getElementById('examTitle').value.trim(),
        instructions: document.getElementById('examInstructions').value.trim(),
        sections: []
      };

      const wrap = document.getElementById('examWrapper');
      let currentSecTitle = "";
      let secIdx = 0;

      for (let i = 0; i < wrap.children.length; i++) {
        const child = wrap.children[i];
        if (child.classList.contains('sectionHeader')) {
          currentSecTitle = child.querySelector('.sectionTitle').textContent.trim();
        } else if (child.classList.contains('section')) {
          secIdx++;
          const secObj = {
            section_order: secIdx,
            section_title: currentSecTitle,
            questions: []
          };

          child.querySelectorAll('[data-qcard]').forEach((card, qIdx) => {
            const qTextEl = card.querySelector('[data-qtext]');
            const qText = extractCleanHTML(qTextEl);
            const qDescEl = card.querySelector('[data-qdesc]');
            const qDesc = extractCleanHTML(qDescEl);
            const qType = card.querySelector('[data-qtype]').value;
            const rawNo = (card.dataset.qnumber || '').trim();
            const parsedNo = parseInt(rawNo, 10);
            const qNo = (!Number.isNaN(parsedNo) && parsedNo > 0) ? parsedNo : (qIdx + 1);

            const scoreRaw = card.querySelector('[data-score]')?.value ?? '1';
            const scoreVal = parseFloat(scoreRaw);
            const qScore = Number.isFinite(scoreVal) ? scoreVal : 1;

            const qObj = {
              number: qNo,
              score: qScore,
              type: qType,
              question: qText,
              description: qDesc,
              choices: []
            };

            if (qType === 'multiple_choice') {
              card.querySelectorAll('[data-optrow]').forEach((row) => {
                const inp = row.querySelector('[data-optinput]');
                const bullet = row.querySelector('.optBullet');
                if (inp && inp.value.trim()) {
                  qObj.choices.push({
                    text: inp.value.trim(),
                    correct: bullet.classList.contains('correct')
                  });
                }
              });
            } else if (qType === 'short_answer') {
              qObj.essay_answer = card.querySelector('[data-main-answer]')?.value?.trim() ?? '';
              qObj.sub_questions = [];

              card.querySelectorAll('[data-subrow]').forEach((row) => {
                const sqEl = row.querySelector('[data-subq]');
                const sq = extractCleanHTML(sqEl);

                const sa = row.querySelector('[data-suba]')?.value?.trim() ?? '';

                if (sq || sa) {
                  qObj.sub_questions.push({
                    question: sq,
                    answer: sa
                  });
                }
              });
            } else if (qType === 'essay') {
              qObj.essay_answer = card.querySelector('[data-essayanswer]')?.value?.trim() ?? '';
            }
            secObj.questions.push(qObj);
          });
          payload.sections.push(secObj);
        }
      }

      fetch('?action=save_db', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        })
        .then(response => response.json())
        .then(data => {
          if (data.status === 'success') {
            alert('บันทึกข้อมูลสำเร็จ!\n(Exam ID: ' + data.exam_id + ', Saved ' + data.questions_count + ' questions)');
            window.location.href = '?action=cleanup';
          } else {
            throw new Error(data.message || 'Unknown error occurred');
          }
        })
        .catch(error => {
          console.error('Save Error:', error);
          alert('เกิดข้อผิดพลาดในการบันทึก:\n' + error.message);
          thisBtn.disabled = false;
          thisBtn.textContent = 'บันทึกข้อสอบ';
        });
    });

    document.addEventListener('change', function(e) {
      if (e.target.matches('[data-qtype]')) applyTypeUI(e.target.closest('[data-qcard]'), e.target.value);
    });

    document.addEventListener('click', function(e) {
      const t = e.target;
      if (t.matches('.optBullet')) {
        const card = t.closest('[data-qcard]');
        if (t.classList.contains('correct')) t.classList.remove('correct');
        else {
          t.classList.add('correct');
        }
      }
      if (t.matches('[data-addopt]')) addOption(t.closest('[data-qcard]'), '');
      if (t.matches('[data-delopt]')) {
        const row = t.closest('[data-optrow]');
        const card = t.closest('[data-qcard]');
        if (card.querySelectorAll('[data-optrow]').length > 2) row.remove();
      }
      if (t.matches('[data-addsub]')) addSubQuestion(t.closest('[data-qcard]'), '', '');
      if (t.matches('[data-delsub]')) {
        t.closest('[data-subrow]').remove();
      }

      if (!e.target.closest('.media-menu') && !e.target.closest('.qFloatBtn')) {
        document.getElementById('globalMediaMenu').style.display = 'none';
      }
      if (!e.target.closest('.more-menu') && !e.target.closest('.iconBtn')) {
        document.querySelectorAll('.more-menu').forEach(m => m.style.display = 'none');
      }
      const card = e.target.closest('.qCard[data-qcard]');
      if (card) {
        if (!card.classList.contains('is-active')) {
          document.querySelectorAll('.qCard').forEach(c => c.classList.remove('is-active'));
          card.classList.add('is-active');
        }
      }
    });
    // เก็บชื่อไฟล์ที่ต้องการลบ (ดึงจาก PHP ที่ Render มา)
    const filesToDelete = [];
    <?php
    if (isset($_GET['file'])) {
      $baseFile = $_GET['file'];
      // ระบุเส้นทางไฟล์ให้ตรงกับที่เก็บจริงในเครื่อง
      $mdFile = 'storage/outputs/' . str_replace('.json', '.md', $baseFile);
      $pdfFile = 'storage/uploads/' . str_replace('.json', '.pdf', $baseFile);
      echo "filesToDelete.push('$mdFile');";
      echo "filesToDelete.push('$pdfFile');";
    }
    ?>

    function cleanupFiles() {
      if (filesToDelete.length === 0) return;
      const url = 'generate_from_json.php?action=delete_files';
      const data = JSON.stringify({
        files: filesToDelete
      });

      // ใช้ sendBeacon เพื่อส่งข้อมูลในขณะที่หน้าเว็บกำลังจะปิด
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url, new Blob([data], {
          type: 'application/json'
        }));
      } else {
        fetch(url, {
          method: 'POST',
          body: data,
          keepalive: true
        });
      }
    }

    // ใช้ pagehide จะแม่นยำกว่าสำหรับการลบไฟล์เมื่อออกจากหน้าเว็บ
    window.addEventListener('pagehide', cleanupFiles);

    document.querySelector('.btn-back')?.addEventListener('click', function() {
      cleanupFiles();
    });
  </script>

</body>

</html>