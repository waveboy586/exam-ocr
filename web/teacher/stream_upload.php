<?php
// stream_upload.php - ฉบับ Debug & Fix

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

@ini_set('output_buffering', 0);
@ini_set('zlib.output_compression', 0);
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
while (ob_get_level() > 0) {
    ob_end_flush();
}

set_time_limit(0);

session_name('TEACHERSESS');
session_start();
session_write_close();

function sendMsg($msg, $progress = null, $currentPage = null, $totalPages = null)
{
    $data = ['message' => $msg];
    if ($progress !== null) $data['progress'] = $progress;
    if ($currentPage !== null) $data['current_page'] = $currentPage;
    if ($totalPages !== null) $data['total_pages'] = $totalPages;

    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

// กรณี $_FILES ว่างเปล่า (Server ดีดไฟล์ทิ้งเพราะใหญ่เกิน)
if (empty($_FILES)) {
    $postMax = ini_get('post_max_size');
    $uploadMax = ini_get('upload_max_filesize');

    // ส่งข้อความ Error กลับไปบอก User ตรงๆ
    sendMsg("Error: Server ไม่ได้รับไฟล์ (ว่างเปล่า)");
    sendMsg("สาเหตุ: ไฟล์อาจใหญ่เกินค่า post_max_size ของ Server ($postMax)");
    sendMsg("วิธีแก้: ไปที่ php.ini แล้วปรับ post_max_size และ upload_max_filesize ให้เป็น 100M");
    exit;
}

// กรณีมีตัวแปร $_FILES แต่ไม่มี key 'exam_pdf'
if (!isset($_FILES['exam_pdf'])) {
    // Debug ดูว่าส่ง key อะไรมาบ้าง
    $keys = implode(', ', array_keys($_FILES));
    sendMsg("Error: ไม่พบ key 'exam_pdf' (ที่ส่งมาคือ: $keys)");
    exit;
}

$file = $_FILES['exam_pdf'];

// กรณี PHP รับไฟล์ได้ แต่แจ้ง Error code
if ($file['error'] !== UPLOAD_ERR_OK) {
    $code = $file['error'];
    $msg = "Unknown Error";
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            $msg = "ไฟล์ใหญ่เกิน upload_max_filesize";
            break;
        case UPLOAD_ERR_FORM_SIZE:
            $msg = "ไฟล์ใหญ่เกิน MAX_FILE_SIZE ใน HTML";
            break;
        case UPLOAD_ERR_PARTIAL:
            $msg = "อัปโหลดไม่สมบูรณ์";
            break;
        case UPLOAD_ERR_NO_FILE:
            $msg = "ไม่ได้เลือกไฟล์";
            break;
    }
    sendMsg("Error: อัปโหลดไม่ผ่าน (Code $code: $msg)");
    exit;
}

sendMsg("รับไฟล์สำเร็จ! (ขนาด: " . round($file['size'] / 1024 / 1024, 2) . " MB)", 5);

$teacherDir = __DIR__;
$uploadDir  = $teacherDir . '/storage/uploads';
$outputDir  = $teacherDir . '/storage/outputs';

if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
if (!is_dir($outputDir)) @mkdir($outputDir, 0777, true);

$newFilename = 'exam_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
$destPath    = $uploadDir . '/' . $newFilename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    sendMsg("Error: move_uploaded_file ล้มเหลว (เช็ค Permission โฟลเดอร์ storage)");
    exit;
}

sendMsg("บันทึกไฟล์เสร็จสิ้น... กำลังเริ่ม OCR", 10);
$totalPages = 1;
try {
    if (file_exists($destPath)) {
        $handle = fopen($destPath, "r");
        $content = fread($handle, filesize($destPath));
        fclose($handle);

        // ค้นหาคำว่า /Count ภายในไฟล์ PDF เพื่อดึงจำนวนหน้า
        if (preg_match("/\/Count\s+(\d+)/", $content, $matches)) {
            $totalPages = (int)$matches[1];
        } else {
            // วิธีสำรอง: นับจำนวนคำว่า /Page (ต้องระวังกรณีไฟล์ซับซ้อน)
            $totalPages = preg_match_all("/\/Page\W/", $content, $dummy);
        }
    }
} catch (Exception $e) {
    $totalPages = 1; // กันพัง
}

// ตรวจสอบค่า ถ้าหาไม่เจอจริงๆ ให้ใส่ default ไว้ (หรือแจ้งเตือน)
if ($totalPages <= 0) $totalPages = 1;

sendMsg("พบไฟล์จำนวน $totalPages หน้า...", 12, null, $totalPages);

// --- 3. Run Python ---
$projectRoot = realpath(__DIR__ . '/../../');
$venvPython  = $projectRoot . '/venv/Scripts/python.exe';
$ocrScript   = $projectRoot . '/ocr.py';
$parseScript = $projectRoot . '/parse_exam.py';

$pythonExe = getenv('PYTHON_BIN') ?: ($projectRoot . '/venv/Scripts/python.exe');
if (!@file_exists($pythonExe)) {
    $pythonExe = PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/bin/python3';
}

$baseName = pathinfo($newFilename, PATHINFO_FILENAME);
$mdFile   = $outputDir . '/' . $baseName . '.md';
$jsonFile = $outputDir . '/' . $baseName . '.json';

$cmdOCR = "\"$pythonExe\" \"$ocrScript\" \"$destPath\" --out \"$mdFile\"";

// --- แก้ไขที่นิยามฟังก์ชัน (เพิ่ม $totalPages เป็นพารามิเตอร์ตัวที่ 4) ---
function runCommandStream($cmd, $startPercent, $endPercent, $totalPages)
{
    $descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $process = proc_open($cmd . " 2>&1", $descriptors, $pipes);

    // ประกาศตัวแปรเก็บหน้าปัจจุบัน (เริ่มที่ null จนกว่าจะเจอ PAGE แรกใน Log)
    $currentPage = null;

    if (is_resource($process)) {
        while ($line = fgets($pipes[1])) {
            $line = trim($line);
            if (empty($line)) continue;

            // --- [ขีดเหลือง] ดักจับเลขหน้าจริงจาก "PAGE X:" ---
            if (preg_match('/PAGE\s*(\d+):/i', $line, $matches)) {
                $currentPage = (int)$matches[1];
            }

            // --- [ขีดแดง] ดักจับขั้นตอนย่อยเพื่อขยับหลอด Progress ---
            $currentProgress = $startPercent;
            if (preg_match('/\[(\d+)\/(\d+)\]/i', $line, $subMatches)) {
                $subStep = (int)$subMatches[1];
                $totalSubSteps = (int)$subMatches[2];

                // คำนวณ % ให้หลอดขยับตามขั้นตอนย่อย (สอดคล้องกับจำนวนหน้าจริง)
                $range = $endPercent - $startPercent;
                if ($currentPage && $totalPages > 0) {
                    $pageBase = (($currentPage - 1) / $totalPages) * $range;
                    $subBase = ($subStep / $totalSubSteps) * (1 / $totalPages) * $range;
                    $currentProgress = $startPercent + $pageBase + $subBase;
                }
            }

            // ส่งข้อมูลกลับไป: ใช้ค่า $totalPages ที่ได้รับมาจากพารามิเตอร์ฟังก์ชัน
            sendMsg("System: " . htmlspecialchars($line), round($currentProgress, 2), $currentPage, $totalPages);
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}

// --- แก้ไขบรรทัดที่เรียกใช้งาน (ส่ง $totalPages เข้าไปด้วย) ---

sendMsg("กำลังประมวลผล OCR...", 15);
// ปรับช่วง OCR เป็น 15% - 85% และส่งจำนวนหน้าจริงเข้าไป
runCommandStream($cmdOCR, 15, 85, $totalPages);

if (!file_exists($mdFile)) {
    sendMsg("Error: OCR ล้มเหลว (ไม่พบไฟล์ .md ผลลัพธ์)");
    exit;
}

sendMsg("OCR เสร็จแล้ว! กำลังสร้าง JSON...", 85);
$cmdParse = "\"$pythonExe\" \"$parseScript\" \"$mdFile\" --out \"$jsonFile\"";
// สำหรับการ Parse อาจจะไม่อ้างอิงเลขหน้าใน Log แล้ว แต่เรายังส่ง $totalPages เข้าไปเพื่อรักษารูปแบบฟังก์ชัน
runCommandStream($cmdParse, 85, 95, $totalPages);

if (!file_exists($jsonFile)) {
    sendMsg("Error: Parse JSON ล้มเหลว");
    exit;
}

// เสร็จสิ้น
session_start();
$_SESSION['flash_success'] = "นำเข้าไฟล์สำเร็จ";
$_SESSION['last_json_path'] = $jsonFile;
session_write_close();

sendMsg("เสร็จสมบูรณ์!", 100);
echo "data: " . json_encode(['status' => 'done', 'redirect' => 'generate_from_json.php']) . "\n\n";
flush();
