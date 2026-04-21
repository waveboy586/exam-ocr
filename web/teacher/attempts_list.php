<?php
declare(strict_types=1);

session_name('TEACHERSESS');
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: teacher_login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ====== CONFIG DB ======
require_once 'config.php';

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ====== PARAMS ======
$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$q = trim((string)($_GET['q'] ?? ""));

// ====== CHECK users TABLE ======
$hasUsers = false;
try {
    $res = $conn->query("SHOW TABLES LIKE 'users'");
    $hasUsers = ($res->num_rows > 0);
} catch (Throwable $e) {
    $hasUsers = false;
}



// ====== EXPORT (Excel) ======
// export=1 & exam_id>0 => download Excel (HTML .xls)
$export = isset($_GET['export']) ? (int)$_GET['export'] : 0;
if ($export === 1 && $exam_id > 0) {
    // title for filename
    $st = $conn->prepare("SELECT title FROM exams WHERE id = ? LIMIT 1");
    $st->bind_param("i", $exam_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $examTitleForFile = $row['title'] ?? ("exam_" . $exam_id);

    // build query (respect search q)
    $where = ["a.exam_id = ?"];
    $params = [$exam_id];
    $types = "i";

    if ($q !== "") {
        $like = "%{$q}%";
        $searchParts = [];

        $searchParts[] = "a.access_code LIKE ?";
        $params[] = $like; $types .= "s";

        $searchParts[] = "CAST(a.user_id AS CHAR) LIKE ?";
        $params[] = $like; $types .= "s";

        $searchParts[] = "CAST(a.id AS CHAR) LIKE ?";
        $params[] = $like; $types .= "s";

        if ($hasUsers) {
            $searchParts[] = "u.username LIKE ?";
            $params[] = $like; $types .= "s";

            $searchParts[] = "u.full_name LIKE ?";
            $params[] = $like; $types .= "s";
        }

        $where[] = "(" . implode(" OR ", $searchParts) . ")";
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    $joinUsersSql = $hasUsers ? "LEFT JOIN users u ON u.id = a.user_id" : "";
    $selectUsers  = $hasUsers ? ", u.full_name AS user_full_name, u.username AS user_username" : "";
    $orderBySql   = $hasUsers ? "u.username ASC" : "a.user_id ASC";

    $sql = "
        SELECT
          a.id,
          a.user_id,
          a.access_code,
          a.score_total,
          a.score_max
          {$selectUsers}
        FROM exam_attempts a
        {$joinUsersSql}
        {$whereSql}
        ORDER BY {$orderBySql}
        LIMIT 20000
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // filename safe
    $safeTitle = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', (string)$examTitleForFile);
    $safeTitle = trim(preg_replace('/\s+/u', '_', $safeTitle));
    if ($safeTitle === '') $safeTitle = "exam_" . $exam_id;

    $filename = "scores_{$safeTitle}_{$exam_id}_" . date('Ymd_His') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    // UTF-8 BOM ช่วยให้ Excel อ่านภาษาไทยถูก
    echo "\xEF\xBB\xBF";

    echo "<html><head><meta charset='utf-8'></head><body>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";
    echo "<tr>";
    echo "<th>ชื่อ</th>";
    echo "<th>รหัส</th>";
    echo "<th>คะแนนที่ได้</th>";
    echo "<th>คะแนนเต็ม</th>";
    echo "</tr>";

    foreach ($rows as $r) {
        // ชื่อ / รหัส (ถ้ามีตาราง users จะใช้ full_name / username)
        $name = "user_id: " . (int)$r['user_id'];
        $code = (string)(int)$r['user_id'];

        if ($hasUsers) {
            $full = trim((string)($r['user_full_name'] ?? ""));
            $uname = trim((string)($r['user_username'] ?? ""));
            if ($full !== "") $name = $full;
            elseif ($uname !== "") $name = $uname;

            if ($uname !== "") $code = $uname;
        }

        $scoreTotal = (float)($r['score_total'] ?? 0);
        $scoreMax   = (float)($r['score_max'] ?? 0);

        echo "<tr>";
        echo "<td>" . h($name) . "</td>";
        echo "<td>" . h($code) . "</td>";
        echo "<td>" . h($scoreTotal) . "</td>";
        echo "<td>" . h($scoreMax) . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    echo "</body></html>";
    exit;
}

// ====== VIEW MODE ======
// 1) exam_id = 0  => แสดงรายการชุดข้อสอบแบบ "โฟลเดอร์"
// 2) exam_id > 0  => แสดง list ผู้เข้าสอบ/attempts ของชุดข้อสอบนั้น

$examFolders = [];
$attempts = [];
$examTitle = "";

// สำหรับปุ่ม "ตรวจอัตโนมัติทั้งหมด" (เฉพาะหน้า attempts ของ exam_id)
$pendingAttemptIds = [];

if ($exam_id <= 0) {
    // ====== FOLDER LIST (GROUP BY EXAM) ======
    $currentUserId = (int)($_SESSION['user_id'] ?? 0); // ดึง user ที่ login อยู่

    $types = "i";                          // มี created_by = ? เสมอ
    $params = [$currentUserId];
    $whereParts = ["e.created_by = ?"];    // filter เฉพาะข้อสอบของตัวเอง

    if ($q !== "") {
        $whereParts[] = "e.title LIKE ?";
        $types .= "s";
        $params[] = "%{$q}%";
    }

    $whereSql = "WHERE " . implode(" AND ", $whereParts);

    $sql = "
        SELECT
            e.id,
            e.title,
            COUNT(a.id) AS attempt_count,
            SUM(CASE WHEN a.submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS submitted_count,
            MAX(COALESCE(a.submitted_at, a.started_at)) AS last_activity
        FROM exams e
        LEFT JOIN exam_attempts a ON a.exam_id = e.id
        {$whereSql}
        GROUP BY e.id
        ORDER BY e.id DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $examFolders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

} else {
    // ====== CONFIG: written question types (Short answer / Essay) ======
    // ปรับรายชื่อ type ให้ตรงกับระบบของคุณได้ตามต้องการ
    $writtenTypes = [
        'short_answer',
        'short',
        'essay',
        'long_answer',
        'written',
        'text'
    ];
    $writtenTypeSql = "('" . implode("','", array_map(function($t){ return str_replace("'", "", $t); }, $writtenTypes)) . "')";

    // ====== EXAM TITLE ======
    $st = $conn->prepare("SELECT title FROM exams WHERE id = ? LIMIT 1");
    $st->bind_param("i", $exam_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $examTitle = $row['title'] ?? "";

    // ====== ATTEMPTS LIST ======
    $where = ["a.exam_id = ?"]; // always
    $params = [$exam_id];
    $types = "i";

    if ($q !== "") {
        $like = "%{$q}%";
        $searchParts = [];

        $searchParts[] = "a.access_code LIKE ?";
        $params[] = $like; $types .= "s";

        $searchParts[] = "CAST(a.user_id AS CHAR) LIKE ?";
        $params[] = $like; $types .= "s";

        $searchParts[] = "CAST(a.id AS CHAR) LIKE ?";
        $params[] = $like; $types .= "s";

        if ($hasUsers) {
            $searchParts[] = "u.username LIKE ?";
            $params[] = $like; $types .= "s";

            $searchParts[] = "u.full_name LIKE ?";
            $params[] = $like; $types .= "s";
        }

        $where[] = "(" . implode(" OR ", $searchParts) . ")";
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    $joinUsersSql = $hasUsers ? "LEFT JOIN users u ON u.id = a.user_id" : "";
    $selectUsers  = $hasUsers ? ", u.full_name AS user_full_name, u.username AS user_username, u.role AS user_role" : "";

    $sql = "
        SELECT
          a.id,
          a.exam_id,
          a.user_id,
          a.access_code,
          a.started_at,
          a.submitted_at,
          a.answered_questions,
          a.total_questions,
          a.score_total,
          a.score_max,
          a.client_ip,
          e.title AS exam_title,
          COALESCE(w.written_total,0)       AS written_total,
          COALESCE(w.written_answered,0)    AS written_answered,
          COALESCE(w.written_auto_graded,0) AS written_auto_graded
          {$selectUsers}
        FROM exam_attempts a
        JOIN exams e ON e.id = a.exam_id
        LEFT JOIN (
          SELECT
            ea.attempt_id,
            SUM(CASE WHEN q.type IN {$writtenTypeSql} THEN 1 ELSE 0 END) AS written_total,
            SUM(CASE WHEN q.type IN {$writtenTypeSql} AND ea.answer_text IS NOT NULL AND ea.answer_text <> '' THEN 1 ELSE 0 END) AS written_answered,
            SUM(CASE WHEN q.type IN {$writtenTypeSql} AND ea.feedback LIKE '%AUTO_GRADE_V1:%' THEN 1 ELSE 0 END) AS written_auto_graded
          FROM exam_answers ea
          JOIN questions q ON q.id = ea.question_id
          GROUP BY ea.attempt_id
        ) w ON w.attempt_id = a.id
        {$joinUsersSql}
        {$whereSql}
        ORDER BY a.id DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // ====== Build pending list for batch auto-grade ======
    foreach ($attempts as $a) {
        $submitted = !empty($a['submitted_at']);
        $writtenAnswered = (int)($a['written_answered'] ?? 0);
        $writtenAuto = (int)($a['written_auto_graded'] ?? 0);
        $autoDone = ($writtenAnswered > 0 && $writtenAuto >= $writtenAnswered);

        if ($submitted && $writtenAnswered > 0 && !$autoDone) {
            $pendingAttemptIds[] = (int)$a['id'];
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ผลการสอบ</title>
  <style>
    :root{
      --bg:#f4f7f6;
      --card:#ffffff;
      --text:#111827;
      --muted:#6b7280;
      --line:#e5e7eb;
      --accent:#0f766e;
      --shadow: 0 10px 30px rgba(17,24,39,.08);
      --radius: 14px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, "Noto Sans Thai", sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    .wrap{max-width:1100px; margin: 24px auto; padding: 0 14px;}
    .topbar{
      display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between;
      margin-bottom:12px;
    }
    .title{display:flex; flex-direction:column; gap:3px; min-width: 220px;}
    .title h1{margin:0; font-size:20px;}
    .title .sub{color:var(--muted); font-size:13px;}
    .filters{
      display:flex; gap:10px; flex-wrap:wrap; align-items:center;
      background: var(--card); padding: 10px; border-radius: var(--radius);
      box-shadow: var(--shadow); border: 1px solid var(--line);
    }
    input{
      padding:10px 12px; border:1px solid var(--line); border-radius: 10px; outline:none;
      background:#fff; min-width: 260px;
    }
    button{
      padding:10px 14px; border-radius: 10px; border:1px solid var(--accent);
      background: var(--accent); color:#fff; cursor:pointer;
    }
    button:hover{filter:brightness(.95)}
    .btn{
      display:inline-flex; align-items:center; gap:8px;
      padding:10px 14px; border-radius: 12px;
      border:1px solid var(--line); background:#fff; cursor:pointer;
      box-shadow: var(--shadow);
      color: var(--accent); text-decoration:none;
      white-space: nowrap;
    }
    .btn:hover{filter:brightness(.98)}

    .card{
      background: var(--card);
      border:1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow:hidden;
    }

    /* ===== Folder grid ===== */
    .folderGrid{
      display:grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 12px;
    }
    .folder{
      display:block;
      text-decoration:none;
      color: inherit;
      background: var(--card);
      border:1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 14px;
      transition: transform .08s ease, filter .08s ease;
      position: relative;
      overflow:hidden;
    }
    .folder:hover{transform: translateY(-1px); filter: brightness(.99)}
    .folderTop{display:flex; gap:10px; align-items:flex-start;}
    .folderIcon{
      width:40px; height:40px;
      border-radius: 12px;
      background: rgba(15,118,110,.10);
      border: 1px solid rgba(15,118,110,.18);
      display:flex; align-items:center; justify-content:center;
      flex: 0 0 auto;
    }
    .folderIcon svg{width:22px; height:22px; fill: var(--accent)}
    .folderTitle{font-weight:800; line-height:1.25;}
    .folderMeta{color: var(--muted); font-size:12px; margin-top:4px;}
    .folderBadges{display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;}

    .pill{
      display:inline-flex; align-items:center; gap:6px;
      padding:4px 10px; border-radius:999px; font-size:12px;
      border:1px solid var(--line); background:#fff;
    }
    .pill.ok{border-color: rgba(16,185,129,.35); background: rgba(16,185,129,.08); color: #065f46;}
    .pill.wait{border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); color:#92400e;}

    /* ===== Attempts table ===== */
    table{width:100%; border-collapse:collapse;}
    th,td{padding:12px 12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top;}
    th{font-size:12px; color:var(--muted); font-weight:600; background: #fafafa;}
    tr:hover td{background:#fcfcfc}
    .muted{color:var(--muted); font-size:12px;}
    a.rowlink{color:inherit; text-decoration:none; display:block;}
    .score{font-variant-numeric: tabular-nums; font-weight:700; color: var(--accent);}
    .right{white-space:nowrap; text-align:right;}
    .empty{padding:18px; color:var(--muted); text-align:center;}
    .who strong{font-weight:800}

    .btnMini{
      padding:8px 10px;
      border-radius: 10px;
      border:1px solid var(--line);
      background:#fff;
      cursor:pointer;
      color: var(--accent);
      box-shadow: var(--shadow);
      font-size: 13px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      text-decoration:none;
    }
    .btnMini:hover{filter:brightness(.98)}
    .btnMini.primary{background: var(--accent); border-color: var(--accent); color:#fff;}
    .btnMini:disabled{opacity:.55; cursor:not-allowed; filter:none}

    /* ===== Modal / Progress ===== */
    .modalMask{
      position:fixed; inset:0; background: rgba(17,24,39,.45);
      display:none; align-items:center; justify-content:center;
      padding: 16px;
      z-index: 9999;
    }
    .modal{
      width:min(520px, 100%);
      background: #fff;
      border:1px solid var(--line);
      border-radius: 16px;
      box-shadow: var(--shadow);
      overflow:hidden;
    }
    .modalHead{padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; border-bottom:1px solid var(--line); background:#fafafa;}
    .modalHead b{font-size:14px;}
    .modalBody{padding:16px;}
    .bar{height:12px; background:#eef2f7; border-radius: 999px; overflow:hidden; border:1px solid #e5e7eb;}
    .bar > i{display:block; height:100%; width:0%; background: var(--accent); transition: width .55s ease;}
    .modalFoot{padding:12px 16px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid var(--line); background:#fafafa;}
    .mono{font-variant-numeric: tabular-nums;}

    /* ให้ขึ้นบรรทัดใหม่ได้ใน modal message */
    #autoMsg{white-space: pre-line;}

    .crumb{
      display:flex; gap:10px; flex-wrap:wrap; align-items:center;
      margin-bottom: 12px;
    }
    .crumb .tag{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px; border-radius: 999px;
      border:1px solid var(--line); background:#fff;
      box-shadow: var(--shadow);
      font-size: 13px;
    }
    .tag b{color: var(--accent)}
  </style>
</head>
<body>
  <div class="wrap">

    <div class="topbar">
      <div class="title">
        <h1>ผลการสอบ</h1>
        <?php if ($exam_id <= 0): ?>
          <div class="sub">เลือกชุดข้อสอบ (เหมือนโฟลเดอร์) เพื่อเข้าไปดูรายชื่อผู้เข้าสอบ</div>
        <?php else: ?>
          <div class="sub">ชุดข้อสอบ: <?= h($examTitle !== "" ? $examTitle : ("#".$exam_id)) ?></div>
        <?php endif; ?>
      </div>

      <form class="filters" method="get">
        <?php if ($exam_id > 0): ?>
          <input type="hidden" name="exam_id" value="<?= (int)$exam_id ?>" />
          <input name="q" value="<?= h($q) ?>" placeholder="ค้นหาชื่อผู้สอบ" />
          <button type="submit">ค้นหา</button>
        <?php else: ?>
          <input name="q" value="<?= h($q) ?>" placeholder="ค้นหาชื่อชุดข้อสอบ" />
          <button type="submit">ค้นหา</button>
        <?php endif; ?>
      </form>

      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <?php if ($exam_id > 0): ?>
          <a class="btn" href="attempts_list.php">← ย้อนกลับ</a>
        <?php else: ?>
          <a class="btn" href="teacher_home.php">← ย้อนกลับ</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($exam_id <= 0): ?>
      <!-- ===== Folder view ===== -->
      <?php if (!$examFolders): ?>
        <div class="card" style="padding:18px; text-align:center; color:var(--muted);">
          <?= $q !== "" ? "ไม่พบชุดข้อสอบที่ตรงกับคำค้นหา" : "ยังไม่มีชุดข้อสอบ" ?>
        </div>
      <?php else: ?>
        <div class="folderGrid">
          <?php foreach ($examFolders as $ex): ?>
            <?php
              $attemptCount = (int)($ex['attempt_count'] ?? 0);
              $submittedCount = (int)($ex['submitted_count'] ?? 0);
              $last = trim((string)($ex['last_activity'] ?? ""));
            ?>
            <a class="folder" href="attempts_list.php?exam_id=<?= (int)$ex['id'] ?>">
              <div class="folderTop">
                <div class="folderIcon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <path d="M10 4l2 2h8a2 2 0 0 1 2 2v10a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V6a2 2 0 0 1 2-2h6z" />
                  </svg>
                </div>
                <div>
                  <div class="folderTitle"><?= h($ex['title']) ?></div>
                  <div class="folderMeta">exam_id: #<?= (int)$ex['id'] ?></div>
                  <div class="folderMeta">อัปเดตล่าสุด: <?= $last !== "" ? h($last) : "-" ?></div>
                </div>
              </div>

              <div class="folderBadges">
                <span class="pill"><?= $attemptCount ?> attempt</span>
                <span class="pill ok"><?= $submittedCount ?> submitted</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- ===== Attempts view ===== -->
      <div class="crumb">
        <div class="tag">ชุดข้อสอบ: <b><?= h($examTitle !== "" ? $examTitle : ("#".$exam_id)) ?></b></div>
        
        <div class="tag">ทั้งหมด: <b><?= count($attempts) ?></b> รายการ (แสดงสูงสุด 500)</div>
        <div class="tag">รอตรวจอัตโนมัติ: <b><?= count($pendingAttemptIds) ?></b> attempt</div>

        <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap;">
          <a class="btnMini" href="exam_stats.php?exam_id=<?= (int)$exam_id ?>" title="ดูสถิติแบบ Google Form">ดูสถิติ</a>
          <a class="btnMini" href="attempts_list.php?exam_id=<?= (int)$exam_id ?>&export=1<?= $q !== "" ? "&q=".urlencode($q) : "" ?>" title="ส่งออกคะแนนเป็นไฟล์ Excel">ส่งออก Excel</a>
          <button
            class="btnMini primary"
            id="btnAutoGradeAll"
            type="button"
            <?= count($pendingAttemptIds) <= 0 ? 'disabled title="ไม่มีรายการที่ต้องตรวจ"' : '' ?>
          >
            ตรวจอัตโนมัติทั้งหมด
          </button>
        </div>
      </div>

      <div class="card">
        <table>
          <thead>
            <tr>
              <th>ผู้เข้าสอบ</th>
              <th class="right">ความคืบหน้า</th>
              <th class="right">คะแนนรวม</th>
              <th class="right">สถานะ</th>
              <th class="right">ข้อเขียน / สถานะตรวจ</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$attempts): ?>
              <tr><td colspan="5" class="empty">ยังไม่มีผู้เข้าสอบในชุดข้อสอบนี้</td></tr>
            <?php else: ?>
              <?php foreach ($attempts as $a): ?>
                <?php
                  $submitted = !empty($a['submitted_at']);
                  $statusPill = $submitted ? '<span class="pill ok">Submitted</span>' : '<span class="pill wait">In progress</span>';

                  $progress = ((int)$a['answered_questions']) . "/" . ((int)$a['total_questions']);
                  $scoreTotal = (float)($a['score_total'] ?? 0);
                  $scoreMax = (float)($a['score_max'] ?? 0);
                  $scoreText = $scoreTotal . " / " . $scoreMax;

                  $whoMain = "user_id: " . (int)$a['user_id'];
                  $whoSub  = "-";

                  if ($hasUsers) {
                      $full = trim((string)($a['user_full_name'] ?? ""));
                      $uname = trim((string)($a['user_username'] ?? ""));
                      $role  = trim((string)($a['user_role'] ?? ""));

                      if ($full !== "") $whoMain = $full;
                      if ($uname !== "") $whoSub = $uname;
                      if ($role !== "") $whoSub .= " · " . $role;
                  }

                  // ====== Auto-grade summary (for written answers) ======
                  $writtenTotal = (int)($a['written_total'] ?? 0);
                  $writtenAnswered = (int)($a['written_answered'] ?? 0);
                  $writtenAuto = (int)($a['written_auto_graded'] ?? 0);
                  $autoDone = ($writtenAnswered > 0 && $writtenAuto >= $writtenAnswered);
                ?>
                <tr>
                  <td>
                    <a class="rowlink who" href="attempt_detail.php?attempt_id=<?= (int)$a['id'] ?>">
                      <div><strong><?= h($whoMain) ?></strong></div>
                      <div class="muted"><?= h($whoSub) ?></div>
                    </a>
                  </td>
                  <td class="right">
                    <a class="rowlink" href="attempt_detail.php?attempt_id=<?= (int)$a['id'] ?>">
                      <div class="muted">ตอบแล้ว</div>
                      <div><b><?= h($progress) ?></b></div>
                    </a>
                  </td>
                  <td class="right">
                    <a class="rowlink" href="attempt_detail.php?attempt_id=<?= (int)$a['id'] ?>">
                      <div class="muted">คะแนน</div>
                      <div class="score"><?= h($scoreText) ?></div>
                    </a>
                  </td>
                  <td class="right">
                    <a class="rowlink" href="attempt_detail.php?attempt_id=<?= (int)$a['id'] ?>">
                      <?= $statusPill ?>
                      <div class="muted" style="margin-top:6px;">
                        <?= $submitted ? "submitted: ".h($a['submitted_at']) : "started: ".h($a['started_at']) ?>
                      </div>
                    </a>
                  </td>
                  <td class="right">
                    <div class="muted" style="margin-bottom:6px;">
                      <?php if ($writtenTotal <= 0): ?>
                        ไม่มีข้อเขียน
                      <?php else: ?>
                        
                        <span class="muted">  ตรวจแล้ว</span> <span class="mono"><b><?= (int)$writtenAuto ?>/<?= (int)$writtenAnswered ?></b></span>
                      <?php endif; ?>
                    </div>

                    <?php if (!$submitted): ?>
                      <span class="pill wait">ยังไม่ส่ง</span>
                    <?php elseif ($writtenAnswered <= 0): ?>
                      <span class="pill">ไม่มีคำตอบ</span>
                    <?php elseif ($autoDone): ?>
                      <a class="btnMini" href="attempt_detail.php?attempt_id=<?= (int)$a['id'] ?>" title="ดูผลการตรวจในหน้า Attempt detail">ตรวจแล้ว ✓</a>
                    <?php else: ?>
                      <span class="pill wait" title="กดปุ่มด้านบนเพื่อเริ่มตรวจอัตโนมัติทั้งหมด">รอตรวจ</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ===== Modal for progress ===== -->
      <div class="modalMask" id="autoMask" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="autoTitle">
 
    <div class="modalHead">
      <b id="autoTitle">ตรวจอัตโนมัติ</b>
      <button class="btnMini" type="button" id="autoClose">ปิด</button>
    </div>
 
    <div class="modalBody">
 
      <!-- บรรทัดเดียว: X / Y attempt · Z ข้อ -->
      <div id="autoSummaryLine" style="
        font-size:13px; color:var(--muted);
        margin-bottom:10px;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      ">-</div>
 
      <!-- progress bar -->
      <div class="bar" aria-label="progress"><i id="autoBar"></i></div>
 
      <!-- เปอร์เซ็นต์ + ข้อความสั้นๆ บรรทัดเดียว -->
      <div style="display:flex; justify-content:space-between; align-items:baseline;
                  gap:10px; margin-top:10px;">
        <div id="autoMsg" style="
          font-size:13px; color:var(--muted);
          white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
          flex:1; min-width:0;
        ">กำลังเริ่มต้น…</div>
        <div class="mono" style="flex-shrink:0;"><b id="autoPct">0%</b></div>
      </div>
 
    </div>
 
    <div class="modalFoot">
      <button class="btnMini" type="button" id="autoReload" style="display:none;">รีเฟรชหน้า</button>
      <button class="btnMini primary" type="button" id="autoOk" style="display:none;">เสร็จแล้ว</button>
    </div>
 
  </div>
</div>

    <?php endif; ?>

  </div>

  <?php if ($exam_id > 0): ?>
  <script>
(function(){
  const EXAM_ID = <?= (int)$exam_id ?>;
  const PENDING = <?= json_encode(array_values($pendingAttemptIds), JSON_UNESCAPED_UNICODE) ?>;
 
  const mask        = document.getElementById('autoMask');
  const bar         = document.getElementById('autoBar');
  const pct         = document.getElementById('autoPct');
  const summaryLine = document.getElementById('autoSummaryLine');
  const msg         = document.getElementById('autoMsg');
  const btnClose    = document.getElementById('autoClose');
  const btnOk       = document.getElementById('autoOk');
  const btnReload   = document.getElementById('autoReload');
  const btnAll      = document.getElementById('btnAutoGradeAll');
 
  let batchRunning = false;
  let cancelled    = false;
 
  function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }
 
  function openModal(){
    if (!mask) return;
    cancelled = false;
    mask.style.display = 'flex';
    mask.setAttribute('aria-hidden','false');
  }
  function closeModal(){
    if (!mask) return;
    mask.style.display = 'none';
    mask.setAttribute('aria-hidden','true');
    cancelled = true;
  }
  function setProgress(p){
    const v = Math.max(0, Math.min(100, p|0));
    bar.style.width = v + '%';
    pct.textContent = v + '%';
  }
  function doneUI(text){
    setProgress(100);
    msg.textContent = text || 'เสร็จแล้ว';
    btnReload.style.display = 'inline-flex';
    btnOk.style.display     = 'inline-flex';
  }
 
  btnClose?.addEventListener('click', closeModal);
  btnOk?.addEventListener('click', closeModal);
  btnReload?.addEventListener('click', () => location.reload());
  mask?.addEventListener('click', (e) => { if (e.target === mask) closeModal(); });
 
  async function postStartBatch(attemptIds){
    const fd = new URLSearchParams();
    attemptIds.forEach(id => fd.append('attempt_ids[]', String(id)));
    const res = await fetch('auto_grade_start_batch.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: fd.toString()
    });
    return await res.json();
  }
 
  async function getProgress(jobId){
    const res = await fetch('auto_grade_progress.php?job_id=' + encodeURIComponent(jobId), { cache: 'no-store' });
    return await res.json();
  }
 
  // Poll ทุก job พร้อมกัน — อัปเดต UI ด้วยตัวเลขรวม
  // ใช้ fakePct ค่อยๆ คืบในช่วง warmup เพื่อไม่ให้บาร์กระโดด 0→100 ทันที
  let fakePct = 0;

  const PHASE_MSGS = {
    queued:  'กำลังเข้าคิว…',
    loading: 'กำลังโหลด Python model…',
    grading: null, // ใช้ตัวเลขจริง
  };

  async function pollAllJobs(jobIds, totalAttempts){
    const state = {};
    jobIds.forEach(id => { state[id] = { done: 0, total: 0, status: 'queued' }; });
    fakePct = 0;

    for (let tick = 0; tick < 100000; tick++){
      if (cancelled) return;

      const results = await Promise.all(
        jobIds.map(id => getProgress(id).catch(() => null))
      );

      let totalItems = 0, doneItems = 0, doneJobs = 0, errorJobs = 0;
      let anyRunning = false;

      results.forEach((j, i) => {
        const id = jobIds[i];
        if (!j || !j.ok) return;
        state[id].done   = Number(j.done_items  || 0);
        state[id].total  = Number(j.total_items || 0);
        state[id].status = j.status;
        totalItems += state[id].total;
        doneItems  += state[id].done;
        if (j.status === 'done')  doneJobs++;
        if (j.status === 'error') { doneJobs++; errorJobs++; }
        if (j.status === 'running') anyRunning = true;
      });

      const allFinished = doneJobs >= jobIds.length;

      // ── คำนวณเปอร์เซ็นต์ที่แสดง ────────────────────────────────────────
      let displayP;
      let phase;

      if (totalItems > 0 && doneItems > 0) {
        // มีข้อมูลจริงแล้ว — ใช้ตัวเลขจริง แต่ไม่ถอยหลัง
        const realP = Math.round((doneItems / totalItems) * 100);
        displayP = Math.max(fakePct, allFinished ? 100 : Math.min(realP, 95));
        fakePct  = displayP;
        phase    = 'grading';
      } else if (anyRunning || (totalItems > 0 && doneItems === 0)) {
        // model โหลดเสร็จแล้ว กำลังเริ่มตรวจ
        fakePct  = Math.min(fakePct + 3, 28);
        displayP = fakePct;
        phase    = 'loading';
      } else {
        // ยังอยู่ในคิว / Python ยังไม่ start
        fakePct  = Math.min(fakePct + 2, 14);
        displayP = fakePct;
        phase    = 'queued';
      }

      setProgress(displayP);

      // ── summary line ─────────────────────────────────────────────────────
      summaryLine.textContent = `${doneJobs} / ${totalAttempts} attempt เสร็จแล้ว`;

      // ── status message ───────────────────────────────────────────────────
      if (!allFinished) {
        msg.textContent = phase === 'grading'
          ? `ตรวจแล้ว ${doneItems} / ${totalItems} ข้อ`
          : PHASE_MSGS[phase];
      }

      if (allFinished) return { totalItems, doneItems, errorJobs };

      await sleep(900);
    }
  }
 
  async function runBatch(){
    if (batchRunning) return;
    if (!Array.isArray(PENDING) || PENDING.length === 0){
      alert('ไม่มีรายการที่ต้องตรวจอัตโนมัติ');
      return;
    }
 
    batchRunning = true;
    btnAll && (btnAll.disabled = true);
    openModal();
    setProgress(0);
    btnOk.style.display     = 'none';
    btnReload.style.display = 'none';
 
    summaryLine.textContent = `${PENDING.length} attempt รอตรวจ`;
    msg.textContent         = 'กำลังเข้าคิว…';
 
    try {
      // Step 1: ส่งทุก attempt พร้อมกันใน 1 request
      const batch = await postStartBatch(PENDING);
      if (!batch?.ok) throw new Error(batch?.message || 'batch start ล้มเหลว');
 
      const jobIds = (batch.jobs || [])
        .filter(j => j.job_id && j.status !== 'already_done' && j.status !== 'error')
        .map(j => j.job_id);
 
      const skipped = (batch.jobs || []).filter(j => j.status === 'already_done').length;
      const total   = PENDING.length;
 
      if (jobIds.length === 0){
        doneUI(`ทุก attempt ตรวจแล้ว (${skipped} ข้าม)`);
        return;
      }
 
      summaryLine.textContent = `0 / ${total} attempt เสร็จแล้ว`;
      msg.textContent         = 'กำลังโหลด Python model…';
 
      // Step 2: Poll — UI อัปเดตตัวเลขรวมเท่านั้น
      const result = await pollAllJobs(jobIds, total);
 
      if (cancelled) return;
 
      if (!result?.errorJobs){
        doneUI(`เสร็จแล้ว · ${total} attempt · ${result?.totalItems ?? 0} ข้อ`);
      } else {
        doneUI(`เสร็จแล้ว (${result.errorJobs} attempt พบข้อผิดพลาด)`);
      }
 
    } catch(err){
      if (!cancelled) doneUI('เกิดข้อผิดพลาด: ' + (err?.message || String(err)));
    } finally {
      batchRunning = false;
      btnAll && (btnAll.disabled = PENDING.length === 0);
    }
  }
 
  btnAll?.addEventListener('click', runBatch);
})();
</script>
  <?php endif; ?>

</body>
</html>