<?php
session_name('TEACHERSESS');
session_start();
require_once 'config.php';

// ย้ายการตรวจสอบสิทธิ์และประกาศตัวแปร $user_id มาไว้บนสุด
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: teacher_login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? $_SESSION['username'];

// จัดการ Folder (Create / Edit / Move Exam)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // สร้างโฟลเดอร์ใหม่
    if (isset($_POST['action']) && $_POST['action'] === 'create_folder') {
        $fname = trim($_POST['folder_name']);
        $fcolor = $_POST['folder_color'] ?? '#3b82f6';
        if (!empty($fname)) {
            $stmt = $pdo->prepare("INSERT INTO folders (user_id, folder_name, folder_color) VALUES (:uid, :name, :color)");
            $stmt->execute([':uid' => $user_id, ':name' => $fname, ':color' => $fcolor]);
            $_SESSION['flash_success'] = "สร้างโฟลเดอร์เรียบร้อยแล้ว";
            header("Location: teacher_home.php?view=folders");
            exit;
        }
    }
    // แก้ไขโฟลเดอร์
    if (isset($_POST['action']) && $_POST['action'] === 'edit_folder') {
        $fid = $_POST['folder_id'];
        $fname = trim($_POST['folder_name']);
        $fcolor = $_POST['folder_color'];

        $stmt = $pdo->prepare("UPDATE folders SET folder_name = :name, folder_color = :color WHERE id = :id AND user_id = :uid");
        $stmt->execute([':name' => $fname, ':color' => $fcolor, ':id' => $fid, ':uid' => $user_id]);
        $_SESSION['flash_success'] = "แก้ไขโฟลเดอร์เรียบร้อย";
        header("Location: teacher_home.php?view=folders");
        exit;
    }
    // ลบโฟลเดอร์
    if (isset($_POST['action']) && $_POST['action'] === 'delete_folder') {
        $fid = $_POST['folder_id'];
        // ย้ายข้อสอบในโฟลเดอร์นั้นออกมาก่อน (ป้องกัน orphan)
        $stmt = $pdo->prepare("UPDATE exams SET folder_id = NULL WHERE folder_id = :id AND created_by = :uid");
        $stmt->execute([':id' => $fid, ':uid' => $user_id]);
        // ลบโฟลเดอร์
        $stmt = $pdo->prepare("DELETE FROM folders WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $fid, ':uid' => $user_id]);
        $_SESSION['flash_success'] = "ลบโฟลเดอร์เรียบร้อยแล้ว (ข้อสอบในโฟลเดอร์ถูกย้ายออกมาแล้ว)";
        header("Location: teacher_home.php?view=folders");
        exit;
    }
    // ย้ายข้อสอบ
    if (isset($_POST['action']) && $_POST['action'] === 'move_exam') {
        $eid = $_POST['exam_id'];
        $target_folder = empty($_POST['target_folder_id']) ? NULL : $_POST['target_folder_id'];

        $stmt = $pdo->prepare("UPDATE exams SET folder_id = :fid WHERE id = :eid AND created_by = :uid");
        $stmt->execute([':fid' => $target_folder, ':eid' => $eid, ':uid' => $user_id]);
        $_SESSION['flash_success'] = "ย้ายรายการเรียบร้อย";
        header("Location: teacher_home.php");
        exit;
    }
}

// เตรียมตัวแปรสำหรับการแสดงผล (View Mode & Folders)
$view_mode = $_GET['view'] ?? 'all';
$current_folder_id = $_GET['folder_id'] ?? null;
$search_keyword = isset($_GET['q']) ? trim($_GET['q']) : ''; // รับค่าค้นหาตรงนี้
$current_folder = null;

// ถ้าเลือกดูในโฟลเดอร์ ให้ดึงข้อมูลโฟลเดอร์นั้น
if ($current_folder_id) {
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => $current_folder_id, ':uid' => $user_id]);
    $current_folder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current_folder) {
        $current_folder_id = null;
    }
}

// ดึงรายชื่อโฟลเดอร์ทั้งหมด
$allFolders = [];
$stmt = $pdo->prepare("SELECT * FROM folders WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute([':uid' => $user_id]);
$allFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// [4. Logic] ดึงข้อสอบ (รวม Logic ค้นหา + โฟลเดอร์ ไว้ที่เดียว)
$myExams = [];
try {
    $sql = "SELECT * FROM exams WHERE created_by = :uid";
    $params = [':uid' => $user_id];

    // กรองตามโฟลเดอร์ (เฉพาะเมื่อเลือกโหมด folders และเจาะจง folder_id)
    if ($view_mode === 'folders' && $current_folder_id) {
        $sql .= " AND folder_id = :fid";
        $params[':fid'] = $current_folder_id;
    }

    // ค้นหา (Search)
    if (!empty($search_keyword)) {
        $sql .= " AND title LIKE :keyword";
        $params[':keyword'] = "%" . $search_keyword . "%";
    }

    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $myExams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $myExams = [];
}

// --- ส่วนจัดการ Upload File (คงเดิม) ---
$upload_error = '';
$upload_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_pdf_teacher'])) {
    // ... (โค้ดส่วน Upload เดิมของคุณ ยาวๆ ใส่ไว้เหมือนเดิมได้เลยครับ ไม่ต้องแก้) ...
    // ใส่ Logic Upload ทั้งก้อนของคุณตรงนี้ 
    // (เพื่อความกระชับ ผมละไว้ แต่ให้คุณคงโค้ดส่วน Upload เดิมไว้ตรงนี้นะครับ)
    if (!isset($_FILES['exam_pdf']) || $_FILES['exam_pdf']['error'] === UPLOAD_ERR_NO_FILE) {
        $upload_error = 'กรุณาเลือกไฟล์ก่อนอัปโหลด';
    } else {
        $file = $_FILES['exam_pdf'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_error = 'เกิดข้อผิดพลาดระหว่างการอัปโหลดไฟล์';
        } else {
            $filename = $file['name'];
            $tmp_path = $file['tmp_name'];

            // ตรวจสอบนามสกุลไฟล์
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $upload_error = 'อนุญาตเฉพาะไฟล์ PDF เท่านั้น (.pdf)';
            } else {
                // ตรวจ MIME type
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($tmp_path);

                if ($mime !== 'application/pdf') {
                    $upload_error = 'ไฟล์ที่เลือกไม่ใช่ไฟล์ PDF ที่ถูกต้อง';
                } else {
                    // ตำแหน่งโฟลเดอร์ teacher
                    $teacherDir = __DIR__; // web/teacher

                    // storage ของ teacher
                    $storageDir = $teacherDir . DIRECTORY_SEPARATOR . 'storage';
                    $uploadDir  = $storageDir . DIRECTORY_SEPARATOR . 'uploads';
                    $outputDir  = $storageDir . DIRECTORY_SEPARATOR . 'outputs';
                    $logDir     = $storageDir . DIRECTORY_SEPARATOR . 'logs';

                    // สร้างโฟลเดอร์ถ้ายังไม่มี
                    @mkdir($uploadDir, 0777, true);
                    @mkdir($outputDir, 0777, true);
                    @mkdir($logDir, 0777, true);

                    $new_name  = 'exam_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
                    $dest_path = $uploadDir . '/' . $new_name;

                    if (move_uploaded_file($tmp_path, $dest_path)) {
                        $upload_success = 'อัปโหลดไฟล์สำเร็จ: ' . htmlspecialchars($filename);
                        // OCR -> Markdown -> Parse -> JSON
                        try {
                            // กัน timeout ระหว่างรัน OCR (โดยเฉพาะไฟล์หลายหน้า)
                            @set_time_limit(0);

                            // โฟลเดอร์ output (เก็บ .md/.json คู่กับไฟล์ pdf)
                            $base = pathinfo($new_name, PATHINFO_FILENAME);
                            $out_md   = $outputDir . '/' . $base . '.md';
                            $out_json = $outputDir . '/' . $base . '.json';

                            // ชี้ไปที่ root โปรเจค: C:\xampp\htdocs\exam-ocr
                            $project_root = realpath(__DIR__ . '/../../');
                            if (!$project_root) {
                                throw new Exception('หาโฟลเดอร์โปรเจคไม่เจอ (project_root)');
                            }

                            $ocr_script   = $project_root . DIRECTORY_SEPARATOR . 'ocr.py';
                            $parse_script = $project_root . DIRECTORY_SEPARATOR . 'parse_exam.py';

                            if (!file_exists($ocr_script)) {
                                throw new Exception('ไม่พบไฟล์ ocr.py ที่ ' . $ocr_script);
                            }
                            if (!file_exists($parse_script)) {
                                throw new Exception('ไม่พบไฟล์ parse_exam.py ที่ ' . $parse_script);
                            }

                            // ใช้ Python จาก env (Docker) → venv (Windows) → system python
                            $python = getenv('PYTHON_BIN') ?: ($project_root . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe');
                            if (!@file_exists($python)) {
                                $python = PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/bin/python3';
                            }

                            // 1) OCR: pdf -> md
                            $cmd1 = escapeshellcmd($python) . ' ' .
                                escapeshellarg($ocr_script) . ' ' .
                                escapeshellarg($dest_path) . ' ' .
                                '--out ' . escapeshellarg($out_md) . ' 2>&1';

                            $out1 = [];
                            $code1 = 0;
                            exec($cmd1, $out1, $code1);
                            if ($code1 !== 0 || !file_exists($out_md)) {
                                throw new Exception("OCR ล้มเหลว (code=$code1)\n" . implode("\n", $out1));
                            }

                            // 2) Parse: md -> json
                            $cmd2 = escapeshellcmd($python) . ' ' .
                                escapeshellarg($parse_script) . ' ' .
                                escapeshellarg($out_md) . ' ' .
                                '--out ' . escapeshellarg($out_json) . ' 2>&1';

                            $out2 = [];
                            $code2 = 0;
                            exec($cmd2, $out2, $code2);
                            if ($code2 !== 0 || !file_exists($out_json)) {
                                throw new Exception("Parse ล้มเหลว (code=$code2)\n" . implode("\n", $out2));
                            }
                            // 3) ลบไฟล์ PDF ต้นฉบับทิ้ง (ตามที่ขอ)
                            if (file_exists($dest_path)) {
                                unlink($dest_path);
                            }
                            // ตอนนี้ได้ไฟล์ .json แล้ว
                            $_SESSION['flash_success'] = "อัปโหลดและประมวลผลสำเร็จ";

                            // เก็บ path ไฟล์ล่าสุดไว้ให้หน้า generate อ่านต่อ
                            $_SESSION['last_json_path'] = $out_json;
                            $_SESSION['last_md_path']   = $out_md;
                            // $_SESSION['last_pdf_path'] ไม่ต้องเก็บแล้วเพราะไฟล์ถูกลบไปแล้ว

                            header("Location: generate_from_json.php");
                            exit;
                        } catch (Throwable $e) {
                            $_SESSION['flash_error'] = "อัปโหลดสำเร็จ แต่ประมวลผล OCR/Parse ไม่สำเร็จ";
                            header('Location: teacher_home.php');
                            exit;
                        }
                    } else {
                        $upload_error = 'ไม่สามารถบันทึกไฟล์ที่อัปโหลดได้';
                    }
                }
            }
        }
    }
}

// PRG: กันการรีเฟรชแล้วส่ง POST ซ้ำ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($upload_error) && empty($_SESSION['flash_error'])) {
        $_SESSION['flash_error'] = $upload_error;
    }
    if (!empty($_SESSION['flash_error']) || !empty($_SESSION['flash_success'])) {
        header('Location: teacher_home.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>หน้าหลักอาจารย์ - Exam OCR</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            /* ปรับขนาดตัวอักษรพื้นฐานให้ใหญ่ขึ้น */
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #e5e7eb;
            color: #111827;
            font-size: 16px;
        }

        /* แถบบนสุด */
        header {
            background: #0f766e;
            border-bottom: 1px solid #d1d5db;
            padding: 12px 30px;
            /* เพิ่ม Padding */
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            font-size: 20px;
            /* ใหญ่ขึ้น */
            font-weight: 600;
            color: #ffffff;
        }

        .header-right {
            font-size: 15px;
            /* ใหญ่ขึ้น */
            color: #ffffff;
        }

        .header-right a {
            color: #2600fd;
            text-decoration: none;
            margin-left: 12px;
            font-weight: 700;
            letter-spacing: 0.01em;
            text-shadow:
                0 0 1px #fff,
                0 0 2px #fff,
                0 1px 3px rgba(255, 255, 255, 0.5);
        }

        .header-right a:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .page {
            padding: 24px 40px;
            /* เพิ่มพื้นที่ขอบข้าง */
            max-width: 1600px;
            /* คุมความกว้างสูงสุดไม่ให้กระจายเกินไป */
            margin: 0 auto;
        }

        /* Alert ข้อความอัปโหลด */
        .alert {
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 13px;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #ecfdf5;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        /* เริ่มแบบฟอร์มใหม่ (สไตล์ template) */
        .template-section {
            margin-bottom: 30px;
            text-align: center;
        }

        .template-title {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #374151;
        }

        .template-list {
            display: flex;
            gap: 30px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .template-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            width: 240px;
            /* ขยายจาก 180px */
            padding: 15px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(15, 23, 42, 0.1);
            transition: all 0.2s;
        }

        .template-card:hover {
            box-shadow: 0 10px 15px rgba(15, 23, 42, 0.15);
            transform: translateY(-3px);
        }

        .template-thumb {
            width: 100%;
            height: 140px;
            /* สูงขึ้น */
            border-radius: 8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            /* Icon ใหญ่ขึ้น */
        }

        .template-name {
            font-size: 16px;
            /* ใหญ่ขึ้น */
            font-weight: 600;
            margin-bottom: 5px;
        }

        .template-sub {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.4;
        }

        /* toolbar ฟิลเตอร์ + ค้นหา */
        .toolbar {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .toolbar-filters {
            padding: 8px 16px;
            /* ใหญ่ขึ้น */
            font-size: 15px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .filter-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #f3f4f6;
            cursor: pointer;
            font-size: 13px;
        }

        .filter-pill input {
            margin: 0;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .search-input {
            padding: 10px 18px;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            font-size: 15px;
            min-width: 300px;
        }

        .search-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        .btn-search {
            padding: 10px 24px;
            border-radius: 999px;
            border: none;
            background: #2563eb;
            color: white;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-search:hover {
            background: #1d4ed8;
        }

        /* การ์ดรายการแบบทดสอบ (mockup) */
        .forms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            /* ระยะห่างระหว่างการ์ด */
            background: transparent;
            /* เอาพื้นหลังซ้อนออก */
            border: none;
            box-shadow: none;
        }

        .content-area {
            background: white;
            border-radius: 20px;
            /* มนขึ้น */
            padding: 35px;
            /* เพิ่มพื้นที่ขาวด้านในให้กว้างขึ้น */
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #f3f4f6;
        }

        .form-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            border: 1px solid #f1f5f9;
            transition: transform 0.2s;
        }

        .form-card-header {
            background: #0f766e;
            height: 80px;
        }

        .form-card-body {
            padding: 15px;
        }

        .form-title {
            font-size: 17px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-meta {
            font-size: 13px;
            color: #6b7280;
        }

        /* === Modal นำเข้าไฟล์ PDF === */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal {
            background: #f9fafb;
            border-radius: 16px;
            padding: 30px;
            min-width: 320px;
            max-width: 500px;
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.35);
            border: 1px solid #e5e7eb;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 18px;
            cursor: pointer;
        }

        .modal-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 16px;
        }

        .modal-body-box {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            padding: 16px;
            text-align: center;
        }

        .modal-pdf-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }

        .modal-body-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .modal-body-text {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 12px;
        }

        .btn-outline {
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background: white;
            color: #111827;
            font-size: 13px;
            cursor: pointer;
        }

        .btn-outline:hover {
            background: #f3f4f6;
        }

        /* preview ไฟล์ที่เลือก */
        .file-preview {
            margin-top: 12px;
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px dashed #d1d5db;
            background: #f8fafc;
            text-align: left;
        }

        .file-preview.open {
            display: flex;
        }

        .file-preview-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .file-preview-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: #fee2e2;
            border: 1px solid #fecaca;
            font-size: 18px;
            flex: 0 0 auto;
        }

        .file-preview-name {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 240px;
        }

        .file-preview-meta {
            font-size: 11px;
            color: #6b7280;
        }

        .btn-remove {
            border: none;
            background: transparent;
            color: #b91c1c;
            cursor: pointer;
            font-size: 12px;
            padding: 6px 8px;
            border-radius: 999px;
            flex: 0 0 auto;
        }

        .btn-remove:hover {
            background: #fee2e2;
        }

        #progressPageInfo {
            font-size: 16px;
            font-weight: 700;
            color: #0f766e;
            margin-bottom: 8px;
            display: none;
            /* จะแสดงเมื่อเริ่มนับหน้า */
        }
    </style>
</head>

<body>
    <header>
        <div class="header-left">Home</div>
        <div class="header-right">
            สวัสดี, <?= htmlspecialchars($full_name) ?> (อาจารย์)
            | <a href="manage_users.php">จัดการผู้ใช้</a>
            | <a href="attempts_list.php">ผลการสอบ</a>
            | <a href="teacher_logout.php">ออกจากระบบ</a>
        </div>
    </header>

    <div class="page">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="template-section">
            <div class="template-title">เริ่มแบบฟอร์มใหม่</div>
            <div class="template-list">
                <button class="template-card" type="button" onclick="openUploadModal();">
                    <div class="template-thumb template-thumb-import">📂</div>
                    <div class="template-name">นำเข้าไฟล์</div>
                    <div class="template-sub">สร้างฟอร์มจากไฟล์ข้อสอบ PDF</div>
                </button>

                <button class="template-card" type="button" onclick="window.location.href='create_blank_exam.php';">
                    <div class="template-thumb template-thumb-new">➕</div>
                    <div class="template-name">ฟอร์มใหม่</div>
                    <div class="template-sub">สร้างชุดข้อสอบใหม่ด้วยตัวเอง</div>
                </button>
            </div>
        </div>

        <div class="toolbar">
            <div class="toolbar-left">
                <div class="toolbar-filters">
                    <label class="filter-pill"
                        onclick="window.location.href='teacher_home.php?view=all'"
                        style="<?= ($view_mode ?? 'all') == 'all' ? 'background:#e0f2fe; border-color:#38bdf8; color:#0284c7;' : '' ?>">
                        <input type="radio" name="view_mode" <?= ($view_mode ?? 'all') == 'all' ? 'checked' : '' ?>>
                        <span>แบบฟอร์มของฉัน (ทั้งหมด)</span>
                    </label>
                    <label class="filter-pill"
                        onclick="window.location.href='teacher_home.php?view=folders'"
                        style="<?= ($view_mode ?? '') == 'folders' ? 'background:#e0f2fe; border-color:#38bdf8; color:#0284c7;' : '' ?>">
                        <input type="radio" name="view_mode" <?= ($view_mode ?? '') == 'folders' ? 'checked' : '' ?>>
                        <span>โฟลเดอร์</span>
                    </label>
                </div>
            </div>
            <div class="toolbar-right">
                <form method="GET" action="teacher_home.php" style="display: flex; gap: 8px; align-items: center;">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode ?? 'all') ?>">
                    <?php if (!empty($current_folder_id)): ?>
                        <input type="hidden" name="folder_id" value="<?= $current_folder_id ?>">
                    <?php endif; ?>

                    <input type="text"
                        name="q"
                        class="search-input"
                        placeholder="ค้นหาแบบฟอร์ม..."
                        value="<?= htmlspecialchars($search_keyword) ?>">

                    <button type="submit" class="btn-search">ค้นหา</button>

                    <?php if (!empty($search_keyword)): ?>
                        <a href="teacher_home.php?view=<?= $view_mode ?? 'all' ?><?= !empty($current_folder_id) ? '&folder_id=' . $current_folder_id : '' ?>"
                            class="btn-outline" style="text-decoration:none;">
                            ล้าง
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="content-area">

            <?php if (($view_mode ?? '') === 'folders' && empty($search_keyword) && empty($current_folder_id)): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 style="margin:0; font-size:15px; color:#4b5563;">📁 โฟลเดอร์ของคุณ</h3>
                    <button class="btn-search" style="font-size:12px; padding: 4px 10px;" onclick="openCreateFolderModal()">+ สร้างโฟลเดอร์</button>
                </div>

                <div class="forms-grid" style="margin-bottom: 30px;">
                    <?php if (!empty($allFolders)): ?>
                        <?php foreach ($allFolders as $folder): ?>
                            <div class="form-card" style="border-top: 4px solid <?= $folder['folder_color'] ?>;">
                                <div class="form-card-body" style="position:relative;">
                                    <div style="position:absolute; top:8px; right:8px; cursor:pointer; opacity:0.6;"
                                        onclick="openEditFolderModal(<?= $folder['id'] ?>, '<?= htmlspecialchars($folder['folder_name']) ?>', '<?= $folder['folder_color'] ?>'); event.stopPropagation();">
                                        ⚙️
                                    </div>

                                    <div onclick="window.location.href='teacher_home.php?view=folders&folder_id=<?= $folder['id'] ?>'" style="cursor:pointer; padding: 10px 0;">
                                        <div style="font-size:32px; margin-bottom:8px; text-align:center;">📂</div>
                                        <div class="form-title" style="text-align:center;"><?= htmlspecialchars($folder['folder_name']) ?></div>
                                        <div class="form-meta" style="text-align:center;">
                                            <?= date("d/m/Y", strtotime($folder['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; text-align: center; color: #9ca3af; padding: 20px; border: 2px dashed #e5e7eb; border-radius: 8px;">
                            ยังไม่มีโฟลเดอร์ กดปุ่มสร้างด้านบนได้เลย
                        </div>
                    <?php endif; ?>
                </div>
                <hr style="border:0; border-top:1px solid #e5e7eb; margin-bottom:20px;">
                <h3 style="margin:0 0 15px 0; font-size:15px; color:#4b5563;">📄 ไฟล์ที่ไม่ได้จัดหมวดหมู่</h3>
            <?php endif; ?>

            <?php if (!empty($current_folder)): ?>
                <div style="margin-bottom: 20px; display:flex; align-items:center; gap:12px; background: white; padding: 12px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <a href="teacher_home.php?view=folders" class="btn-outline" style="text-decoration:none;">⬅ กลับ</a>
                    <div style="font-size:24px;">📂</div>
                    <div>
                        <div style="font-size:18px; font-weight:600; color: <?= $current_folder['folder_color'] ?>">
                            <?= htmlspecialchars($current_folder['folder_name']) ?>
                        </div>
                        <div style="font-size:11px; color:#6b7280;">
                            สร้างเมื่อ <?= date("d/m/Y", strtotime($current_folder['created_at'])) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="forms-grid">
                <?php
                // Filter เล็กน้อย: ถ้าอยู่หน้า Root ของ Folders ให้ซ่อนไฟล์ที่มีโฟลเดอร์อยู่แล้ว (เพื่อไม่ให้ซ้ำซ้อน)
                $displayCount = 0;
                ?>

                <?php if (!empty($myExams)): ?>
                    <?php foreach ($myExams as $exam): ?>
                        <?php
                        // Logic: ถ้าดูโหมด Folders + อยู่หน้า Root + ข้อสอบนี้มี folder_id -> ข้ามไป (เพราะมันอยู่ในกล่องโฟลเดอร์ข้างบนแล้ว)
                        if (($view_mode ?? '') === 'folders' && empty($current_folder_id) && !empty($exam['folder_id']) && empty($search_keyword)) {
                            continue;
                        }
                        $displayCount++;
                        ?>
                        <div class="form-card" onclick="window.location.href='edit_exam.php?exam_id=<?= $exam['id'] ?>'">
                            <div class="form-card-header" style="position:relative;">
                                <button type="button"
                                    onclick="openMoveModal(<?= $exam['id'] ?>, <?= $exam['folder_id'] ?? 0 ?>); event.stopPropagation();"
                                    style="position:absolute; top:6px; right:6px; background:rgba(255,255,255,0.95); color:#374151; border:1px solid #d1d5db; border-radius:6px; padding:4px 10px; cursor:pointer; font-size:11px; font-weight:500; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                                    ย้าย ↗
                                </button>
                            </div>
                            <div class="form-card-body">
                                <div class="form-title">
                                    <?php
                                    $title_display = htmlspecialchars($exam['title']);
                                    if (!empty($search_keyword)) {
                                        $title_display = str_ireplace($search_keyword, "<span style='background:#fef08a;'>$search_keyword</span>", $title_display);
                                    }
                                    echo $title_display;
                                    ?>
                                </div>
                                <div class="form-meta">
                                    <?= date("d/m/Y H:i", strtotime($exam['created_at'])) ?>
                                    <?php if (($view_mode ?? '') === 'all' && !empty($exam['folder_id'])): ?>
                                        <br><span style="color:#2563eb; background:#eff6ff; padding:2px 6px; border-radius:4px; margin-top:4px; display:inline-block;">อยู่ในโฟลเดอร์</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($displayCount === 0): ?>
                    <div style="grid-column: 1 / -1; text-align: center; color: #6b7280; padding: 40px;">
                        <?php if (!empty($search_keyword)): ?>
                            <div style="font-size: 32px; margin-bottom: 10px;">🔍</div>
                            ไม่พบแบบทดสอบที่ตรงกับคำว่า "<strong><?= htmlspecialchars($search_keyword) ?></strong>"
                        <?php elseif (!empty($current_folder)): ?>
                            <div style="font-size: 32px; margin-bottom: 10px; opacity:0.3;">📂</div>
                            ว่างเปล่า<br>ยังไม่มีข้อสอบในโฟลเดอร์นี้
                        <?php else: ?>
                            ยังไม่มีแบบทดสอบ
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="uploadModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">นำเข้าไฟล์ของคุณ</div>
                <button class="modal-close" type="button" onclick="closeUploadModal()">×</button>
            </div>
            <div class="modal-subtitle">สร้างฟอร์มสอบจากไฟล์ข้อสอบ PDF ที่คุณมีอยู่แล้ว</div>
            <div style="margin-bottom: 14px; padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                <div style="font-size: 12px; color: #166534; line-height: 1.5;">
                    <span style="font-weight: 600;">📋 ไม่แน่ใจว่าต้องจัดรูปแบบยังไง?</span><br>
                    ดาวน์โหลดไฟล์ตัวอย่างเพื่อดูรูปแบบที่รองรับ
                </div>
                <a href="sample/ตัวอย่างไฟล์ข้อสอบที่แนะนำ.pdf"
                    download
                    style="flex-shrink: 0; padding: 6px 14px; background: #16a34a; color: white; border-radius: 999px; font-size: 12px; font-weight: 600; text-decoration: none; white-space: nowrap;">
                    ⬇ ดาวน์โหลดตัวอย่าง
                </a>
            </div>
            <form method="post" enctype="multipart/form-data" id="uploadForm">
                <div class="modal-body-box">
                    <div class="modal-pdf-icon">📄</div>
                    <div class="modal-body-title">อัปโหลดจากอุปกรณ์นี้</div>
                    <div class="modal-body-text">เลือกไฟล์ PDF จากคอมพิวเตอร์ของคุณ</div>

                    <div class="file-preview" id="filePreview">
                        <div class="file-preview-left">
                            <div class="file-preview-icon">📄</div>
                            <div style="min-width:0;">
                                <div class="file-preview-name" id="fileName">ยังไม่ได้เลือกไฟล์</div>
                                <div class="file-preview-meta" id="fileMeta"></div>
                            </div>
                        </div>
                        <button type="button" class="btn-remove" id="btnRemoveFile">ลบ</button>
                    </div>

                    <input type="file" name="exam_pdf" id="exam_pdf" accept="application/pdf,.pdf" style="display:none;">
                    <button type="button" class="btn-outline" onclick="document.getElementById('exam_pdf').click();">เลือกไฟล์</button>
                </div>
                <div style="margin-top: 16px; text-align: right;">
                    <button type="button" class="btn-outline" onclick="closeUploadModal()">ยกเลิก</button>
                    <button type="submit" name="upload_pdf_teacher" class="btn-search">อัปโหลด</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-backdrop" id="progressModal" style="z-index: 60;">
        <div class="modal" style="width: 400px; text-align: center;">
            <div class="modal-title">กำลังประมวลผล...</div>
            <div class="modal-subtitle" style="margin-bottom: 20px;">กรุณาอย่าปิดหน้าต่างนี้</div>

            <div id="progressPageInfo"></div>

            <div style="width: 100%; background: #e5e7eb; border-radius: 99px; height: 10px; overflow: hidden; margin-bottom: 10px;">
                <div id="progressBar" style="width: 0%; height: 100%; background: #0f766e; transition: width 0.3s;"></div>
            </div>
            <div id="progressText" style="font-size: 13px; color: #4b5563; min-height: 20px;">รอเริ่มการทำงาน...</div>
            <div id="progressLog" style="margin-top: 15px; height: 100px; overflow-y: auto; background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px; font-size: 11px; text-align: left; color: #666; font-family: monospace; display: none;"></div>
        </div>
    </div>

    <div class="modal-backdrop" id="folderModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title" id="folderModalTitle">สร้างโฟลเดอร์ใหม่</div>
                <button class="modal-close" onclick="closeFolderModal()">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" id="folderAction" value="create_folder">
                <input type="hidden" name="folder_id" id="folderIdInput" value="">

                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:6px; font-size:13px; font-weight:500;">ชื่อโฟลเดอร์</label>
                    <input type="text" name="folder_name" id="folderNameInput" required
                        class="search-input" style="width:100%; border-radius:6px; padding: 8px 12px;" placeholder="เช่น กลางภาค, ปลายภาค">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:6px; font-size:13px; font-weight:500;">สีโฟลเดอร์</label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="color" name="folder_color" id="folderColorInput" value="#3b82f6" style="height:40px; width:60px; cursor:pointer; border:1px solid #d1d5db; padding:2px; border-radius:4px;">
                        <div style="font-size:12px; color:#666;">คลิกที่แถบสีเพื่อเปลี่ยน</div>
                    </div>
                </div>

                <div style="text-align: right; border-top: 1px solid #e5e7eb; padding-top: 15px; display:flex; justify-content:space-between; align-items:center;">
                    <button type="button" id="btnDeleteFolder"
                        onclick="confirmDeleteFolder()"
                        style="display:none; background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; padding:8px 16px; border-radius:6px; font-size:13px; cursor:pointer;">
                        🗑️ ลบโฟลเดอร์
                    </button>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-outline" onclick="closeFolderModal()">ยกเลิก</button>
                        <button type="submit" class="btn-search">บันทึก</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden form สำหรับลบโฟลเดอร์ -->
    <form method="POST" id="deleteFolderForm" style="display:none;">
        <input type="hidden" name="action" value="delete_folder">
        <input type="hidden" name="folder_id" id="deleteFolderIdInput" value="">
    </form>

    <div class="modal-backdrop" id="moveModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">ย้ายรายการ</div>
                <button class="modal-close" onclick="document.getElementById('moveModal').classList.remove('open')">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="move_exam">
                <input type="hidden" name="exam_id" id="moveExamId" value="">

                <div style="margin-bottom:25px;">
                    <label style="display:block; margin-bottom:8px; font-size:13px; font-weight:500;">เลือกโฟลเดอร์ปลายทาง</label>
                    <select name="target_folder_id" class="search-input" style="width:100%; border-radius:6px; padding: 8px;">
                        <option value="">-- ไม่จัดกลุ่ม (หน้ารวม) --</option>
                        <?php if (!empty($allFolders)): ?>
                            <?php foreach ($allFolders as $f): ?>
                                <option value="<?= $f['id'] ?>">📁 <?= htmlspecialchars($f['folder_name']) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div style="text-align: right; border-top: 1px solid #e5e7eb; padding-top: 15px;">
                    <button type="button" class="btn-outline" onclick="document.getElementById('moveModal').classList.remove('open')">ยกเลิก</button>
                    <button type="submit" class="btn-search">ย้าย</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- ส่วนจัดการ Upload Modal (Original) ---
        const uploadModal = document.getElementById('uploadModal');
        const uploadForm = document.getElementById('uploadForm');
        const fileInput = document.getElementById('exam_pdf');
        const filePreview = document.getElementById('filePreview');
        const fileNameEl = document.getElementById('fileName');
        const fileMetaEl = document.getElementById('fileMeta');
        const btnRemove = document.getElementById('btnRemoveFile');

        function openUploadModal() {
            uploadModal.classList.add('open');
        }

        function closeUploadModal() {
            uploadModal.classList.remove('open');
            if (fileInput) fileInput.value = '';
            if (filePreview) filePreview.classList.remove('open');
            if (fileNameEl) fileNameEl.textContent = 'ยังไม่ได้เลือกไฟล์';
            if (fileMetaEl) fileMetaEl.textContent = '';
        }

        uploadModal.addEventListener('click', function(e) {
            if (e.target === uploadModal) closeUploadModal();
        });

        function formatBytes(bytes) {
            if (!bytes && bytes !== 0) return "";
            const units = ["B", "KB", "MB", "GB"];
            let i = 0,
                n = bytes;
            while (n >= 1024 && i < units.length - 1) {
                n = n / 1024;
                i++;
            }
            return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
        }

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (!fileInput.files.length) {
                    if (filePreview) filePreview.classList.remove('open');
                    return;
                }
                const f = fileInput.files[0];
                if (fileNameEl) fileNameEl.textContent = f.name;
                if (fileMetaEl) fileMetaEl.textContent = `PDF • ${formatBytes(f.size)}`;
                if (filePreview) filePreview.classList.add('open');
            });
        }

        if (btnRemove) {
            btnRemove.addEventListener('click', function() {
                if (fileInput) fileInput.value = '';
                if (filePreview) filePreview.classList.remove('open');
                if (fileNameEl) fileNameEl.textContent = 'ยังไม่ได้เลือกไฟล์';
                if (fileMetaEl) fileMetaEl.textContent = '';
            });
        }

        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!fileInput.files.length) {
                alert('กรุณาเลือกไฟล์ก่อนอัปโหลด');
                return;
            }
            const formData = new FormData();
            formData.append('exam_pdf', fileInput.files[0]);
            closeUploadModal();

            const progressModal = document.getElementById('progressModal');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const progressLog = document.getElementById('progressLog');

            progressModal.classList.add('open');
            progressBar.style.width = '0%';
            progressText.textContent = 'กำลังเริ่มอัปโหลด...';
            progressLog.style.display = 'block';
            progressLog.innerHTML = '';

            fetch('stream_upload.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder("utf-8");
                    while (true) {
                        const {
                            done,
                            value
                        } = await reader.read();
                        if (done) break;
                        const chunk = decoder.decode(value);
                        const lines = chunk.split("\n\n");
                        lines.forEach(line => {
                            if (line.startsWith("data: ")) {
                                try {
                                    const jsonStr = line.replace("data: ", "").trim();
                                    if (!jsonStr) return;
                                    const data = JSON.parse(jsonStr);
                                    const progressPageInfo = document.getElementById('progressPageInfo');

                                    // ตรวจสอบค่าที่ส่งมาจาก PHP
                                    if (data.current_page && data.total_pages) {
                                        progressPageInfo.style.display = 'block'; // สั่งให้แสดงผล
                                        progressPageInfo.textContent = `หน้า ${data.current_page} / ${data.total_pages}`;
                                    }
                                    if (data.status === 'done' && data.redirect) {
                                        window.location.href = data.redirect;
                                        return;
                                    }
                                    if (data.message && data.message.startsWith("Error:")) {
                                        alert(data.message);
                                        progressModal.classList.remove('open');
                                        return;
                                    }
                                    if (data.message) {
                                        progressText.textContent = data.message;
                                        const logItem = document.createElement('div');
                                        logItem.textContent = "> " + data.message;
                                        progressLog.appendChild(logItem);
                                        progressLog.scrollTop = progressLog.scrollHeight;
                                    }
                                    if (data.progress) {
                                        progressBar.style.width = data.progress + "%";
                                    }
                                } catch (err) {
                                    console.error("Parse Error", err);
                                }
                            }
                        });
                    }
                }).catch(err => {
                    alert("เกิดข้อผิดพลาดในการเชื่อมต่อ: " + err);
                    progressModal.classList.remove('open');
                });
        });

        // --- ส่วนจัดการ Folder Modals (New) ---
        function openCreateFolderModal() {
            document.getElementById('folderModal').classList.add('open');
            document.getElementById('folderModalTitle').innerText = 'สร้างโฟลเดอร์ใหม่';
            document.getElementById('folderAction').value = 'create_folder';
            document.getElementById('folderNameInput').value = '';
            document.getElementById('folderColorInput').value = '#3b82f6';
            // ซ่อนปุ่มลบตอน create
            document.getElementById('btnDeleteFolder').style.display = 'none';
        }

        function openEditFolderModal(id, name, color) {
            document.getElementById('folderModal').classList.add('open');
            document.getElementById('folderModalTitle').innerText = 'แก้ไขโฟลเดอร์';
            document.getElementById('folderAction').value = 'edit_folder';
            document.getElementById('folderIdInput').value = id;
            document.getElementById('folderNameInput').value = name;
            document.getElementById('folderColorInput').value = color;
            // แสดงปุ่มลบเฉพาะตอน edit
            const btnDel = document.getElementById('btnDeleteFolder');
            btnDel.style.display = 'inline-block';
            btnDel.setAttribute('data-folder-name', name);
        }

        function confirmDeleteFolder() {
            const folderId = document.getElementById('folderIdInput').value;
            const folderName = document.getElementById('btnDeleteFolder').getAttribute('data-folder-name');
            if (confirm('⚠️ ยืนยันการลบโฟลเดอร์ "' + folderName + '" ?\n\nข้อสอบในโฟลเดอร์นี้จะถูกย้ายออกมาที่หน้ารวม')) {
                document.getElementById('deleteFolderIdInput').value = folderId;
                document.getElementById('deleteFolderForm').submit();
            }
        }

        function closeFolderModal() {
            document.getElementById('folderModal').classList.remove('open');
        }

        function openMoveModal(examId, currentFolderId) {
            document.getElementById('moveModal').classList.add('open');
            document.getElementById('moveExamId').value = examId;
            const select = document.querySelector('select[name="target_folder_id"]');
            if (select) {
                // เลือกค่า default เป็นโฟลเดอร์ปัจจุบันของข้อสอบนั้น
                select.value = currentFolderId ? currentFolderId : "";
            }
        }

        // ปิด Modal เมื่อคลิกพื้นหลัง
        window.addEventListener('click', function(e) {
            const folderModal = document.getElementById('folderModal');
            const moveModal = document.getElementById('moveModal');
            if (e.target === folderModal) folderModal.classList.remove('open');
            if (e.target === moveModal) moveModal.classList.remove('open');
        });
    </script>
</body>

</html>