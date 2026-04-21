<?php
session_name('ADMINSESS');
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// ----- OCR: รับไฟล์ PDF และประมวลผล -----
if (($_POST['action'] ?? '') === 'run_ocr') {
    ob_clean();
    $file = $_FILES['pdf'];
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $path = $uploadDir . time() . '.pdf';
    move_uploaded_file($file['tmp_name'], $path);

    $pythonVenv = getenv('PYTHON_BIN') ?: 'C:\xampp\htdocs\exam-ocr\venv\Scripts\python.exe';
    if (!@file_exists($pythonVenv)) {
        $pythonVenv = PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/bin/python3';
    }
    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'ocr_processor.py';

    $command = escapeshellarg($pythonVenv) . " " . escapeshellarg($scriptPath) . " " . escapeshellarg($path) . " 2>&1";
    $output = shell_exec($command);

    if (file_exists($path)) unlink($path);

    header('Content-Type: application/json; charset=utf-8');
    echo $output;
    exit;
}

// ----- OCR: บันทึกข้อมูลที่อ่านได้ลงฐานข้อมูล -----
if (($_POST['action'] ?? '') === 'save_ocr_data') {
    $students = $_POST['students'] ?? [];
    foreach ($students as $s) {
        $sid  = trim($s['username']);
        $name = trim($s['full_name']);
        $maj  = trim($s['major']);
        $sub  = trim($s['subject']);

        if ($sid != '') {
            $pass = password_hash($sid, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password_hash, full_name, major, subject, role)
                VALUES (?, ?, ?, ?, ?, 'student')
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    major     = VALUES(major),
                    subject   = VALUES(subject)
            ");
            $stmt->execute([$sid, $pass, $name, $maj, $sub]);
        }
    }
    header('Location: manage_users.php?import_success=1');
    exit;
}

// ----- ลบนักศึกษาทั้งหมดตามรายวิชา -----
if (($_POST['action'] ?? '') === 'delete_by_subject') {
    $subject_to_delete = $_POST['subject'] ?? '';
    if ($subject_to_delete !== '') {
        $stmt = $pdo->prepare("DELETE FROM users WHERE role = 'student' AND subject = ?");
        $stmt->execute([$subject_to_delete]);
        header('Location: manage_users.php?delete_success=1');
        exit;
    }
}

// ----- จัดการ action อื่น ๆ -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // เพิ่มผู้ใช้
    if ($action === 'add_user') {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? 'user';
        $major     = trim($_POST['major'] ?? '');
        $subject   = trim($_POST['subject'] ?? '');

        if ($username !== '' && $password !== '' && $full_name !== '') {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password_hash, full_name, major, subject, role)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            try {
                $stmt->execute([$username, $password_hash, $full_name, $major, $subject, $role]);
                header('Location: manage_users.php?add_success=1');
                exit;
            } catch (PDOException $e) {
                $error_add = 'ไม่สามารถเพิ่มผู้ใช้ได้ (อาจมี username นี้แล้ว)';
            }
        } else {
            $error_add = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        }
    }

    // รีเซ็ต IP
    if ($action === 'reset_ip') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET first_login_ip = NULL,
                    first_login_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$user_id]);
        }
    }

    // ลบผู้ใช้ (ห้ามลบตัวเอง)
    if ($action === 'delete_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id > 0 && $user_id != $_SESSION['user_id']) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
        }
    }

    header('Location: manage_users.php');
    exit;
}

// ----- ค้นหา / กรอง -----
$search_username = trim($_GET['search_username'] ?? '');
$filter_subject  = trim($_GET['filter_subject'] ?? '');
$filter_role     = trim($_GET['filter_role'] ?? '');

$query  = "SELECT id, username, full_name, role, first_login_ip, first_login_at, major, subject
           FROM users WHERE 1=1";
$params = [];

if ($search_username !== '') {
    $query   .= " AND username LIKE ?";
    $params[] = '%' . $search_username . '%';
}
if ($filter_subject !== '') {
    $query   .= " AND subject = ?";
    $params[] = $filter_subject;
}
if ($filter_role !== '') {
    $query   .= " AND role = ?";
    $params[] = $filter_role;
}

$query .= " ORDER BY id ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการผู้ใช้ (Admin) - Exam OCR</title>
    <style>
        body { margin:0; font-family:system-ui,sans-serif; background:#f3f4f6; }
        header {
            background:#111827; color:white; padding:12px 20px;
            display:flex; justify-content:space-between; align-items:center;
        }
        .back-link { color:#38bdf8; text-decoration:none; font-size:14px; }
        main { padding:20px; }
        h1 { margin-top:0; }

        .add-user-box {
            background:white; padding:16px; border-radius:8px;
            box-shadow:0 2px 6px rgba(0,0,0,.05); margin-bottom:20px;
        }
        .add-user-box h2 { margin-top:0; font-size:18px; }

        .form-row { display:flex; gap:12px; margin-bottom:10px; flex-wrap:wrap; }
        .form-group { flex:1; min-width:150px; }

        label { display:block; font-size:13px; margin-bottom:4px; }
        input[type="text"], input[type="password"], select {
            width:100%; padding:6px 8px; border-radius:4px;
            border:1px solid #d1d5db; font-size:13px; box-sizing:border-box;
        }

        /* student-only fields: ซ่อน/แสดงตาม role ที่เลือก */
        .student-fields { display:none; }

        .btn-primary {
            padding:8px 14px; border-radius:6px; border:none;
            background:#2563eb; color:white; font-size:13px; cursor:pointer;
        }
        .btn-primary:hover { background:#1d4ed8; }
        .btn-secondary {
            padding:6px 10px; border-radius:4px; border:1px solid #9ca3af;
            background:#e5e7eb; color:#111827; font-size:12px; cursor:pointer;
        }
        .btn-secondary:hover { background:#d1d5db; }

        table { width:100%; border-collapse:collapse; background:white; }
        th, td { border:1px solid #e5e7eb; padding:6px 8px; font-size:13px; }
        th { background:#e5e7eb; text-align:left; }

        .btn-danger {
            padding:4px 8px; border-radius:4px; border:none;
            background:#ef4444; color:white; font-size:12px; cursor:pointer;
        }
        .btn-danger:hover { background:#b91c1c; }
        .btn-warning {
            padding:4px 8px; border-radius:4px; border:none;
            background:#f59e0b; color:white; font-size:12px; cursor:pointer;
        }
        .btn-warning:hover { background:#d97706; }

        .error   { color:#b91c1c; font-size:13px; margin-bottom:6px; }
        .success { color:#16a34a; font-size:13px; margin-bottom:6px; }

        /* Search / Filter bar */
        .search-container {
            background:#ffffff; border-radius:10px; padding:10px 14px;
            margin-bottom:12px; box-shadow:0 1px 3px rgba(15,23,42,.08);
            display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            border:1px solid #e5e7eb;
        }
        .search-controls {
            display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex:1;
        }
        .search-input {
            flex:1; min-width:160px; padding:6px 10px;
            border-radius:999px; border:1px solid #d1d5db; font-size:13px; outline:none;
        }
        .search-input:focus {
            border-color:#2563eb; box-shadow:0 0 0 2px rgba(37,99,235,.18);
        }
        .btn-search {
            padding:6px 14px; border-radius:999px; border:none;
            background:#2563eb; color:#fff; font-size:13px; cursor:pointer;
        }
        .btn-search:hover { background:#1d4ed8; }
        .btn-refresh {
            padding:6px 12px; border-radius:999px; border:1px solid #d1d5db;
            background:#f9fafb; color:#111827; font-size:13px; cursor:pointer;
        }
        .btn-refresh:hover { background:#e5e7eb; }

        /* Progress Modal */
        .progress-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,.7);
            display:flex; justify-content:center; align-items:center; z-index:10000;
        }
        .progress-card {
            background:white; padding:30px; border-radius:12px;
            width:100%; max-width:400px; text-align:center;
        }
        .progress-container {
            background:#e5e7eb; border-radius:999px; height:12px;
            width:100%; margin:20px 0; overflow:hidden;
        }
        .progress-bar {
            background:#2563eb; height:100%; width:0%;
            transition:width .4s ease;
        }
        .status-text { font-size:14px; color:#4b5563; font-weight:500; }

        /* Role badge */
        .badge {
            display:inline-block; padding:2px 8px; border-radius:999px;
            font-size:11px; font-weight:600;
        }
        .badge-admin   { background:#fef3c7; color:#92400e; }
        .badge-teacher { background:#dbeafe; color:#1e40af; }
        .badge-student { background:#dcfce7; color:#166534; }
    </style>
</head>
<body>
<header>
    <div>จัดการผู้ใช้ (Admin)</div>
    <div><a class="back-link" href="admin_home.php">← กลับหน้าหลัก Admin</a></div>
</header>

<main>
    <h1>จัดการผู้ใช้</h1>

    <?php if (!empty($_GET['add_success'])): ?>
        <div class="success">✅ เพิ่มผู้ใช้สำเร็จ</div>
    <?php endif; ?>
    <?php if (!empty($_GET['import_success'])): ?>
        <div class="success">✅ นำเข้ารายชื่อสำเร็จ</div>
    <?php endif; ?>
    <?php if (!empty($_GET['delete_success'])): ?>
        <div class="success">✅ ลบรายชื่อตามรายวิชาสำเร็จ</div>
    <?php endif; ?>

    <!-- ===== ฟอร์มเพิ่มผู้ใช้ ===== -->
    <div class="add-user-box">
        <h2>เพิ่มผู้ใช้ใหม่</h2>
        <?php if (!empty($error_add)): ?>
            <div class="error"><?= htmlspecialchars($error_add) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="add_user">
            <div class="form-row">
                <div class="form-group">
                    <label for="username">ชื่อผู้ใช้ (username)</label>
                    <input type="text" name="username" id="username" required placeholder="username / รหัสนักศึกษา">
                </div>
                <div class="form-group">
                    <label for="password">รหัสผ่าน</label>
                    <input type="password" name="password" id="password" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">ชื่อ - นามสกุล</label>
                    <input type="text" name="full_name" id="full_name" required>
                </div>
                <div class="form-group">
                    <label for="role">สิทธิ์การใช้งาน (role)</label>
                    <select name="role" id="role" onchange="toggleStudentFields(this.value)">
                        <option value="student">student</option>
                        <option value="teacher">teacher</option>
                        <option value="admin">admin</option>
                    </select>
                </div>
            </div>

            <!-- แสดงเฉพาะเมื่อ role = student -->
            <div class="form-row student-fields" id="student-fields">
                <div class="form-group">
                    <label for="major">สาขาวิชา</label>
                    <input type="text" name="major" id="major" placeholder="เช่น IT">
                </div>
                <div class="form-group">
                    <label for="subject">รหัสรายวิชา</label>
                    <input type="text" name="subject" id="subject" placeholder="เช่น 51xxxx">
                </div>
            </div>

            <div style="margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <button type="submit" class="btn-primary">เพิ่มผู้ใช้</button>

                <!-- ปุ่ม OCR (ใช้งานได้เฉพาะตอน role = student) -->
                <button type="button" id="ocr-btn"
                    style="background:#059669; color:white; border:none; padding:8px 14px;
                           border-radius:6px; font-size:13px; cursor:pointer;"
                    onclick="document.getElementById('ocr_upload').click();">
                    📄 นำเข้าไฟล์ PDF รายชื่อ
                </button>
                <input type="file" id="ocr_upload" style="display:none;" accept=".pdf" onchange="startOCR(this)">
                <span id="ocr-hint" style="font-size:12px; color:#6b7280;">(สำหรับนำเข้ารายชื่อ student เท่านั้น)</span>
            </div>
        </form>
    </div>

    <!-- ===== แถบค้นหา / กรอง ===== -->
    <form method="get" class="search-container">
        <div class="search-controls">
            <input type="text" name="search_username" class="search-input"
                value="<?= htmlspecialchars($search_username) ?>"
                placeholder="ค้นหา username / รหัสนักศึกษา...">

            <!-- กรองตามรายวิชา (student) -->
            <select name="filter_subject" class="search-input" style="flex:0.5; border-radius:6px;" onchange="this.form.submit()">
                <option value="">-- ทุกรายวิชา --</option>
                <?php
                $sub_stmt = $pdo->query("SELECT DISTINCT subject FROM users WHERE subject IS NOT NULL AND subject != '' ORDER BY subject");
                while ($row = $sub_stmt->fetch()) {
                    $sel = ($filter_subject === $row['subject']) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($row['subject']) . '" ' . $sel . '>'
                         . htmlspecialchars($row['subject']) . '</option>';
                }
                ?>
            </select>

            <!-- กรองตาม role -->
            <select name="filter_role" class="search-input" style="flex:0.4; border-radius:6px;" onchange="this.form.submit()">
                <option value="">-- ทุก role --</option>
                <option value="student"  <?= $filter_role === 'student'  ? 'selected' : '' ?>>student</option>
                <option value="teacher"  <?= $filter_role === 'teacher'  ? 'selected' : '' ?>>teacher</option>
                <option value="admin"    <?= $filter_role === 'admin'    ? 'selected' : '' ?>>admin</option>
            </select>

            <button type="submit" class="btn-search">ค้นหา</button>
            <button type="button" class="btn-refresh" onclick="window.location.href='manage_users.php'">⟳ รีเฟรช</button>
        </div>
    </form>

    <!-- ===== ปุ่มลบทั้งวิชา (แสดงเฉพาะเมื่อกรองตามวิชา) ===== -->
    <?php if ($filter_subject !== ''): ?>
    <div style="margin-bottom:12px; display:flex; align-items:center;
                background:white; padding:12px 16px; border-radius:8px;
                box-shadow:0 1px 3px rgba(0,0,0,.1);">
        <form method="post"
              onsubmit="return confirm('⚠️ ยืนยันการลบนักศึกษาทั้งหมดในวิชา <?= htmlspecialchars($filter_subject) ?> หรือไม่?');">
            <input type="hidden" name="action" value="delete_by_subject">
            <input type="hidden" name="subject" value="<?= htmlspecialchars($filter_subject) ?>">
            <button type="submit" class="btn-danger"
                    style="padding:8px 16px; border-radius:6px; display:flex; align-items:center; gap:6px;">
                🗑️ ลบรายชื่อทั้งหมดในวิชา <?= htmlspecialchars($filter_subject) ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ===== ตารางผู้ใช้ ===== -->
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Username / รหัส</th>
            <th>ชื่อ-นามสกุล</th>
            <th>Role</th>
            <th>สาขาวิชา</th>
            <th>รายวิชา</th>
            <th>IP / เวลา Login ครั้งแรก</th>
            <th>จัดการ</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($users)): ?>
            <tr><td colspan="8" style="text-align:center;">ไม่พบข้อมูลผู้ใช้</td></tr>
        <?php else: ?>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['id']) ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td>
                    <?php
                    $badgeClass = match($u['role']) {
                        'admin'   => 'badge-admin',
                        'teacher' => 'badge-teacher',
                        default   => 'badge-student',
                    };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($u['role']) ?></span>
                </td>
                <td><?= htmlspecialchars($u['major'] ?? '-') ?></td>
                <td>
                    <?php if (!empty($u['subject'])): ?>
                        <a href="?filter_subject=<?= urlencode($u['subject']) ?>"
                           style="color:#2563eb; text-decoration:none; font-weight:bold;"
                           title="คลิกเพื่อกรองวิชานี้">
                            <?= htmlspecialchars($u['subject']) ?>
                        </a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td style="font-size:11px;">
                    <?= htmlspecialchars($u['first_login_ip'] ?? '-') ?><br>
                    <?= htmlspecialchars($u['first_login_at'] ?? '-') ?>
                </td>
                <td>
                    <form method="post" style="display:inline-block"
                          onsubmit="return confirm('ยืนยันรีเซ็ต IP ผู้ใช้นี้หรือไม่?');">
                        <input type="hidden" name="action" value="reset_ip">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="btn-warning">รีเซ็ต IP</button>
                    </form>

                    <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                    <form method="post" style="display:inline-block"
                          onsubmit="return confirm('ยืนยันลบผู้ใช้นี้หรือไม่?');">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="btn-danger">ลบ</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</main>

<script>
// ===== แสดง / ซ่อนช่อง major+subject ตาม role =====
function toggleStudentFields(role) {
    const fields  = document.getElementById('student-fields');
    const ocrBtn  = document.getElementById('ocr-btn');
    const ocrHint = document.getElementById('ocr-hint');
    const isStudent = role === 'student';
    fields.style.display  = isStudent ? 'flex'   : 'none';
    ocrBtn.style.display  = isStudent ? 'inline-block' : 'none';
    ocrHint.style.display = isStudent ? 'inline' : 'none';
}
// ตั้งค่าเริ่มต้นตาม option ที่ถูก select
toggleStudentFields(document.getElementById('role').value);

// ===== Progress Modal =====
function showProgress(show = true) {
    let modal = document.getElementById('ocr-progress-modal');
    if (show) {
        if (!modal) {
            document.body.insertAdjacentHTML('beforeend', `
                <div id="ocr-progress-modal" class="progress-overlay">
                    <div class="progress-card">
                        <h3 style="margin-top:0">กำลังดำเนินการ OCR</h3>
                        <div class="progress-container">
                            <div id="ocr-bar" class="progress-bar"></div>
                        </div>
                        <div id="ocr-status" class="status-text">เริ่มเตรียมไฟล์...</div>
                    </div>
                </div>`);
        }
    } else if (modal) {
        modal.remove();
    }
}

function updateProgress(percent, text) {
    const bar    = document.getElementById('ocr-bar');
    const status = document.getElementById('ocr-status');
    if (bar)    bar.style.width    = percent + '%';
    if (status) status.textContent = text;
}

// ===== เริ่ม OCR =====
function startOCR(input) {
    if (!input.files[0]) return;

    showProgress(true);
    updateProgress(10, 'เริ่มอัปโหลดและวิเคราะห์โครงสร้างไฟล์...');

    const fd = new FormData();
    fd.append('pdf', input.files[0]);
    fd.append('action', 'run_ocr');

    let currentPercent = 10;
    const progressInterval = setInterval(() => {
        if (currentPercent < 95) {
            currentPercent += 2;
            let msg = 'กำลังกวาดข้อมูลหน้าที่ ' + Math.floor(currentPercent / 10) + '...';
            if (currentPercent > 70) msg = 'กำลังตรวจสอบความถูกต้องของรายชื่อ...';
            updateProgress(currentPercent, msg);
        }
    }, 800);

    fetch('manage_users.php', { method: 'POST', body: fd })
        .then(res => res.text())
        .then(text => {
            clearInterval(progressInterval);
            try {
                const data = JSON.parse(text);
                if (data.error) throw new Error(data.error);
                updateProgress(100, 'อ่านครบทุกหน้าเรียบร้อย!');
                setTimeout(() => {
                    showProgress(false);
                    showOCRModal(data);
                }, 800);
            } catch (e) {
                throw new Error('ระบบประมวลผลผิดพลาด: ' + text);
            }
        })
        .catch(err => {
            clearInterval(progressInterval);
            showProgress(false);
            alert(err.message);
        });
}

// ===== OCR Modal ตรวจสอบข้อมูล =====
function showOCRModal(data) {
    const studentCount = data.length;
    let rows = data.map((item, i) =>
        createRowHtml(i, item.username, item.full_name, item.major, item.subject)
    ).join('');

    document.body.insertAdjacentHTML('beforeend', `
        <div id="ocrModal" style="position:fixed; inset:0; background:rgba(0,0,0,0.7);
             display:flex; justify-content:center; align-items:center; z-index:9999;">
            <div style="background:white; padding:25px; border-radius:12px;
                        width:95%; max-width:1100px; max-height:85vh; overflow-y:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2 style="margin:0;">ตรวจสอบข้อมูลรายชื่อที่อ่านได้</h2>
                    <div style="background:#fee2e2; color:#b91c1c; padding:8px 20px;
                                border-radius:999px; font-weight:bold; border:1px solid #f87171;">
                        จำนวนที่พบ: <span id="ocr-count">${studentCount}</span> คน
                    </div>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="save_ocr_data">
                    <table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
                        <thead>
                            <tr style="background:#f3f4f6;">
                                <th style="padding:10px; border:1px solid #ddd;">รหัสนักศึกษา</th>
                                <th style="padding:10px; border:1px solid #ddd;">ชื่อ-นามสกุล</th>
                                <th style="padding:10px; border:1px solid #ddd;">สาขาวิชา</th>
                                <th style="padding:10px; border:1px solid #ddd;">รหัสวิชา</th>
                                <th style="padding:10px; border:1px solid #ddd; width:40px;">ลบ</th>
                            </tr>
                        </thead>
                        <tbody id="ocr-table-body">${rows}</tbody>
                    </table>
                    <div style="display:flex; justify-content:space-between; gap:10px;">
                        <button type="button" onclick="addNewRow()"
                            style="padding:8px 16px; border-radius:6px; border:1px solid #d1d5db;
                                   background:#f9fafb; cursor:pointer;">+ เพิ่มแถว</button>
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="closeOCRModal()" class="btn-refresh">ยกเลิก</button>
                            <button type="submit" class="btn-primary" style="padding:10px 25px;">
                                ✅ บันทึกรายชื่อทั้งหมดลงระบบ
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>`);
}

function createRowHtml(index, username = '', fullName = '', major = '', subject = '') {
    return `
    <tr id="ocr-row-${index}">
        <td style="padding:5px; border:1px solid #e5e7eb;">
            <input type="text" name="students[${index}][username]" value="${username}"
                   placeholder="รหัส 9 หลัก" style="width:100px; border:1px solid #ddd; padding:4px;">
        </td>
        <td style="padding:5px; border:1px solid #e5e7eb;">
            <input type="text" name="students[${index}][full_name]" value="${fullName}"
                   placeholder="ชื่อ-นามสกุล" style="width:250px; border:1px solid #ddd; padding:4px;">
        </td>
        <td style="padding:5px; border:1px solid #e5e7eb;">
            <input type="text" name="students[${index}][major]" value="${major}"
                   placeholder="เช่น คณิตศาสตร์" style="width:150px; border:1px solid #ddd; padding:4px;">
        </td>
        <td style="padding:5px; border:1px solid #e5e7eb;">
            <input type="text" name="students[${index}][subject]" value="${subject}"
                   placeholder="6 หลัก" style="width:80px; border:1px solid #ddd; padding:4px; text-align:center;">
        </td>
        <td style="padding:5px; border:1px solid #e5e7eb; text-align:center;">
            <button type="button"
                onclick="document.getElementById('ocr-row-${index}').remove(); updateOCRCount();"
                style="color:#ef4444; border:none; background:none; cursor:pointer; font-size:18px;">🗑️</button>
        </td>
    </tr>`;
}

function updateOCRCount() {
    const tbody    = document.getElementById('ocr-table-body');
    const countSpan = document.getElementById('ocr-count');
    if (tbody && countSpan) countSpan.textContent = tbody.rows.length;
}

function addNewRow() {
    const tbody = document.getElementById('ocr-table-body');
    const newIndex = Date.now();
    let defaultSubject = '';
    const firstSubjectInput = tbody.querySelector('input[name*="[subject]"]');
    if (firstSubjectInput) defaultSubject = firstSubjectInput.value;

    tbody.insertAdjacentHTML('beforeend', createRowHtml(newIndex, '', '', '', defaultSubject));
    updateOCRCount();
    document.getElementById('ocrModal').firstElementChild.scrollTop =
        document.getElementById('ocrModal').firstElementChild.scrollHeight;
}

function closeOCRModal() {
    const modal = document.getElementById('ocrModal');
    if (modal) modal.remove();
    const fileInput = document.getElementById('ocr_upload');
    if (fileInput) fileInput.value = '';
}
</script>
</body>
</html>