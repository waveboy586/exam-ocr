<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ====== CONFIG DB ======
require_once 'config.php';
session_name('TEACHERSESS');
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
  header('Location: teacher_login.php');
  exit;
}

$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
if ($attempt_id <= 0) {
  http_response_code(400);
  exit("Missing attempt_id");
}

function h($s): string
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Render block โจทย์ย่อยจาก exam_sub_answers
 */
function render_sub_answers(array $subRows, array $labels, array $subAnswerKeyMap = []): void
{
  if (empty($subRows)) return;
  echo '<div class="subAnswersSection">';
  echo '<div class="subAnswersLabel">&#128196; โจทย์ย่อย</div>';
  foreach ($subRows as $i => $sa) {
    $label    = $labels[$i] ?? ($i + 1);
    $saId     = (int)($sa['id'] ?? 0);
    $sqText   = trim((string)($sa['sub_question_text'] ?? ''));
    $ansText  = trim((string)($sa['answer_text'] ?? ''));
    $saScore  = ($sa['score']     !== null) ? (float)$sa['score']     : 0.0;
    $saMax    = ($sa['max_score'] !== null) ? (float)$sa['max_score'] : 0.0;
    $correct  = ($sa['is_correct'] !== null) ? (bool)$sa['is_correct'] : null;
    $feedback = trim((string)($sa['feedback'] ?? ''));
    $ansIsHtml = $ansText !== strip_tags($ansText);
    $sqIsHtml  = $sqText  !== strip_tags($sqText);

    echo '<div class="subAnswerItem" data-sub-answer-id="' . $saId . '" data-sub-max-score="' . h((string)$saMax) . '">';

    // header: label + โจทย์ย่อย + badge
    echo '<div class="subAnswerHeader">';
    echo '<span class="subLabel">' . h((string)$label) . '</span>';
    if ($sqText !== '') {
      echo '<span class="subQText' . ($sqIsHtml ? ' has-html' : '') . '">' . safe_html($sqText) . '</span>';
    }
    if ($correct !== null) {
      $cls = $correct ? 'correct' : 'incorrect';
      $txt = $correct ? '✓ ถูก'   : '✗ ผิด';
    }
    echo '</div>'; // .subAnswerHeader

    // คำตอบ
    echo '<div class="answerBox' . ($ansIsHtml ? ' has-html' : '') . '">';
    echo safe_html($ansText !== '' ? $ansText : '-');
    echo '</div>';

    // ── เฉลยโจทย์ย่อย ──
    $subQId  = (int)($sa['sub_question_id'] ?? 0);
    $subAKey = trim((string)($subAnswerKeyMap[$subQId] ?? ''));
    if ($subAKey !== '') {
      $subAKIsHtml = $subAKey !== strip_tags($subAKey);
      echo '<div class="answerKeyBox' . ($subAKIsHtml ? ' has-html' : '') . '">';
      echo '<span class="answerKeyLabel"> เฉลย:</span> ';
      echo safe_html($subAKey);
      echo '</div>';
    }

    if ($feedback !== '') {
    }

    // score input
    echo '<div class="subScoreRow">';
    echo '<span class="muted" style="font-size:12px;">คะแนน</span>';
    echo '<input class="scoreInput subScoreInput" type="number" step="any" min="0"'
      . ' name="sub_scores[' . $saId . ']"'
      . ' value="' . h((string)$saScore) . '"'
      . ' data-sub-score-input />';
    echo '<span class="mono" style="font-size:13px;">/ ' . h((string)$saMax) . '</span>';
    echo '<span class="dirtyTag subDirtyTag">แก้ไขแล้ว</span>';
    echo '</div>'; // .subScoreRow
    echo '<div class="muted warn subWarn" style="margin-top:4px; display:none;" data-sub-warn>ปรับให้อยู่ในช่วง 0 ถึงคะแนนเต็มแล้ว</div>';

    echo '</div>'; // .subAnswerItem
  }
  echo '</div>'; // .subAnswersSection
}


function safe_html(string $s): string
{
  $s = trim($s);
  if ($s === '') return '-';
  if ($s === strip_tags($s)) {
    // ไม่มี HTML — escape ปกติ
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
  // มี HTML — strip tag อันตราย เหลือเฉพาะ tag ที่ปลอดภัย
  $allowed = '<b><i><u><strong><em><s><br><p><ul><ol><li>'
    . '<span><div><table><thead><tbody><tr><td><th>'
    . '<h1><h2><h3><h4><h5><h6><a><img><hr><blockquote><pre><code><sub><sup>'
    . '<video><audio><source>';
  $clean = strip_tags($s, $allowed);
  // ลบ javascript: ใน href/src
  $clean = (string)preg_replace('/(\s(?:href|src)\s*=\s*["\']?)javascript:[^"\'>\s]*/iu', '$1#', $clean);
  // เพิ่ม controls ให้ <video> และ <audio> ที่ไม่มี attribute นี้ (เพื่อให้ผู้ใช้ควบคุม player ได้)
  $clean = (string)preg_replace_callback('/<(video|audio)(\s[^>]*)?>/iu', function ($m) {
    $tag   = $m[1];
    $attrs = isset($m[2]) ? $m[2] : '';
    if (!preg_match('/\bcontrols\b/i', $attrs)) {
      $attrs .= ' controls';
    }
    return '<' . $tag . $attrs . '>';
  }, $clean);
  return $clean;
}


$flashError = "";

// ====== SAVE ALL SCORES (single button) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_all') {
  $postAttemptId = isset($_POST['attempt_id']) ? (int)$_POST['attempt_id'] : 0;
  if ($postAttemptId !== $attempt_id || $postAttemptId <= 0) {
    $flashError = "ข้อมูลไม่ถูกต้อง (attempt_id)";
  } else {
    $scores = $_POST['scores'] ?? [];
    if (!is_array($scores)) $scores = [];

    try {
      // โหลด answer ใน attempt นี้ (เพื่อ clamp max_score + กันยิง id มั่ว)
      $st = $conn->prepare("SELECT id, COALESCE(max_score,0) AS max_score FROM exam_answers WHERE attempt_id = ?");
      $st->bind_param('i', $attempt_id);
      $st->execute();
      $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

      $maxMap = [];
      foreach ($rows as $r) {
        $maxMap[(int)$r['id']] = (float)$r['max_score'];
      }

      $conn->begin_transaction();

      $up = $conn->prepare("UPDATE exam_answers SET score = ? WHERE id = ? AND attempt_id = ?");

      foreach ($scores as $answerIdRaw => $scoreRaw) {
        $answerId = (int)$answerIdRaw;
        if ($answerId <= 0) continue;
        if (!array_key_exists($answerId, $maxMap)) continue;

        $maxScore = (float)$maxMap[$answerId];
        $score = is_numeric($scoreRaw) ? (float)$scoreRaw : 0.0;
        if ($score < 0) $score = 0.0;
        if ($score > $maxScore) $score = $maxScore;

        $up->bind_param('dii', $score, $answerId, $attempt_id);
        $up->execute();
      }

      // ── บันทึกคะแนนโจทย์ย่อย (exam_sub_answers) ──
      $subScores = $_POST['sub_scores'] ?? [];
      if (is_array($subScores) && !empty($subScores)) {
        // โหลด max_score ของ sub_answers ใน attempt นี้ (กัน clamp + กันยิง id มั่ว)
        $stSub = $conn->prepare("SELECT id, COALESCE(max_score,0) AS max_score FROM exam_sub_answers WHERE attempt_id = ?");
        $stSub->bind_param('i', $attempt_id);
        $stSub->execute();
        $subMaxMap = [];
        foreach ($stSub->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
          $subMaxMap[(int)$r['id']] = (float)$r['max_score'];
        }

        $upSub = $conn->prepare("UPDATE exam_sub_answers SET score = ? WHERE id = ? AND attempt_id = ?");
        foreach ($subScores as $subIdRaw => $subScoreRaw) {
          $subId = (int)$subIdRaw;
          if ($subId <= 0) continue;
          if (!array_key_exists($subId, $subMaxMap)) continue;
          $subMax   = $subMaxMap[$subId];
          $subScore = is_numeric($subScoreRaw) ? (float)$subScoreRaw : 0.0;
          if ($subScore < 0)        $subScore = 0.0;
          if ($subScore > $subMax)  $subScore = $subMax;
          $upSub->bind_param('dii', $subScore, $subId, $attempt_id);
          $upSub->execute();
        }
      }

      // คำนวณคะแนนรวมใหม่
      $sum = $conn->prepare("SELECT COALESCE(SUM(COALESCE(score,0)),0) AS score_total, COALESCE(SUM(COALESCE(max_score,0)),0) AS score_max FROM exam_answers WHERE attempt_id = ?");
      $sum->bind_param('i', $attempt_id);
      $sum->execute();
      $tot = $sum->get_result()->fetch_assoc();

      $scoreTotal = (float)($tot['score_total'] ?? 0);
      $scoreMax = (float)($tot['score_max'] ?? 0);

      $up2 = $conn->prepare("UPDATE exam_attempts SET score_total = ?, score_max = ? WHERE id = ?");
      $up2->bind_param('ddi', $scoreTotal, $scoreMax, $attempt_id);
      $up2->execute();

      $conn->commit();

      // PRG pattern กันกด refresh แล้ว submit ซ้ำ
      $self = basename((string)($_SERVER['PHP_SELF'] ?? 'attempt_detail.php'));
      header('Location: ' . $self . '?attempt_id=' . $attempt_id . '&saved=1');
      exit;
    } catch (Throwable $e) {
      try {
        $conn->rollback();
      } catch (Throwable $e2) {
      }
      $flashError = "เกิดข้อผิดพลาดในการบันทึกคะแนน";
    }
  }
}

// ตรวจว่ามีตาราง users ไหม
$hasUsers = false;
try {
  $res = $conn->query("SHOW TABLES LIKE 'users'");
  $hasUsers = ($res->num_rows > 0);
} catch (Throwable $e) {
  $hasUsers = false;
}

$sqlAttempt = "
SELECT
  a.*,
  e.title AS exam_title,
  e.instructions_content
  " . ($hasUsers ? ", u.full_name AS user_full_name, u.username AS user_username, u.role AS user_role" : "") . "
FROM exam_attempts a
JOIN exams e ON e.id = a.exam_id
" . ($hasUsers ? "LEFT JOIN users u ON u.id = a.user_id" : "") . "
WHERE a.id = ?
LIMIT 1
";
$stmt = $conn->prepare($sqlAttempt);
$stmt->bind_param("i", $attempt_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();

if (!$attempt) {
  http_response_code(404);
  exit("Attempt not found");
}

// back to attempts list of this exam
$backUrl = 'attempts_list.php?exam_id=' . (int)($attempt['exam_id'] ?? 0);

// ── detect ว่าตาราง questions มีคอลัมน์ parent_id ไหม ──
$hasParentId = false;
try {
  $chk = $conn->query("SHOW COLUMNS FROM questions LIKE 'parent_id'");
  $hasParentId = ($chk && $chk->num_rows > 0);
} catch (Throwable $e) {
  $hasParentId = false;
}

if ($hasParentId) {
  // ดึงพร้อม parent question text + เรียงให้ group อยู่ด้วยกัน
  $sqlAnswers = "
  SELECT
    ea.id              AS answer_id,
    ea.question_id,
    ea.selected_choice_id,
    ea.answer_text,
    ea.is_correct,
    ea.score,
    ea.max_score,
    ea.feedback,
    q.question         AS question_text,
    q.type             AS question_type,
    q.parent_id        AS parent_id,
    pq.question        AS parent_question_text,
    c.choice_text      AS selected_choice_text
  FROM exam_answers ea
  JOIN  questions q  ON q.id  = ea.question_id
  LEFT JOIN questions pq ON pq.id = q.parent_id
  LEFT JOIN choices   c  ON c.id  = ea.selected_choice_id
  WHERE ea.attempt_id = ?
  ORDER BY COALESCE(q.parent_id, q.id) ASC,
           (q.parent_id IS NULL) DESC,
           q.id ASC
  ";
} else {
  // fallback: ไม่มี parent_id ใช้ query เดิม
  $sqlAnswers = "
  SELECT
    ea.id              AS answer_id,
    ea.question_id,
    ea.selected_choice_id,
    ea.answer_text,
    ea.is_correct,
    ea.score,
    ea.max_score,
    ea.feedback,
    q.question         AS question_text,
    q.type             AS question_type,
    NULL               AS parent_id,
    NULL               AS parent_question_text,
    c.choice_text      AS selected_choice_text
  FROM exam_answers ea
  JOIN  questions q ON q.id = ea.question_id
  LEFT JOIN choices c ON c.id = ea.selected_choice_id
  WHERE ea.attempt_id = ?
  ORDER BY ea.id ASC
  ";
}
$stmt2 = $conn->prepare($sqlAnswers);
$stmt2->bind_param("i", $attempt_id);
$stmt2->execute();
$answers = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

// ══════════════════════════════════════════════════════════════
// ── ดึง exam_sub_answers (โจทย์ย่อย) ──
// ══════════════════════════════════════════════════════════════
// Step 1: ดึง rows จาก exam_sub_answers แบบไม่ JOIN ก่อน
$subAnswersMap    = [];   // [question_id => [rows...]]
$subAnswersError  = '';   // เก็บ error message สำหรับ debug
$subAnswersRaw    = [];

try {
  $stRaw = $conn->prepare(
    "SELECT * FROM exam_sub_answers WHERE attempt_id = ? ORDER BY question_id ASC, sub_question_id ASC"
  );
  $stRaw->bind_param("i", $attempt_id);
  $stRaw->execute();
  $subAnswersRaw = $stRaw->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
  $subAnswersError = 'ไม่สามารถ query exam_sub_answers: ' . $e->getMessage();
}

// Step 2: รวบรวม sub_question_id ที่ต้องการหาข้อความ
$subQTextMap = [];  // [sub_question_id => text]
if (!empty($subAnswersRaw)) {
  $subQIds = array_unique(array_column($subAnswersRaw, 'sub_question_id'));
  $subQIds = array_filter($subQIds, fn($v) => $v !== null && $v > 0);

  if (!empty($subQIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($subQIds), '?'));
    $bindTypes = str_repeat('i', count($subQIds));

    // ลอง table sub_questions ก่อน
    $triedTables = [];
    foreach (['sub_questions', 'questions'] as $tbl) {
      if (in_array($tbl, $triedTables, true)) continue;
      $triedTables[] = $tbl;
      try {
        // ตรวจว่ามีตาราง
        $chkT = $conn->query("SHOW TABLES LIKE '{$tbl}'");
        if (!$chkT || $chkT->num_rows === 0) continue;

        // ตรวจชื่อคอลัมน์ข้อความ
        $textCol = null;
        $colRes = $conn->query("SHOW COLUMNS FROM `{$tbl}`");
        $colNames = [];
        while ($colRow = $colRes->fetch_assoc()) {
          $colNames[] = $colRow['Field'];
        }
        foreach (['sub_question', 'question_text', 'question', 'title', 'content', 'text'] as $candidate) {
          if (in_array($candidate, $colNames, true)) {
            $textCol = $candidate;
            break;
          }
        }
        if ($textCol === null) continue;

        $stSQ = $conn->prepare(
          "SELECT id, `{$textCol}` AS sub_question_text FROM `{$tbl}` WHERE id IN ({$inPlaceholders})"
        );
        $stSQ->bind_param($bindTypes, ...$subQIds);
        $stSQ->execute();
        foreach ($stSQ->get_result()->fetch_all(MYSQLI_ASSOC) as $sq) {
          $subQTextMap[(int)$sq['id']] = (string)($sq['sub_question_text'] ?? '');
        }
        break; // พบตารางแล้ว ออกลูป
      } catch (Throwable $e) {
        // ลอง table ถัดไป
      }
    }
  }

  // Step 3: ประกอบ subAnswersMap โดยใส่ sub_question_text เข้าไป
  foreach ($subAnswersRaw as $sr) {
    $sqid = (int)($sr['sub_question_id'] ?? 0);
    $sr['sub_question_text'] = $subQTextMap[$sqid] ?? '';
    $subAnswersMap[(int)$sr['question_id']][] = $sr;
  }
}

// ── จัดกลุ่ม answers → $groups ──

$groups = [];
foreach ($answers as $row) {
  $pid = isset($row['parent_id']) ? (int)$row['parent_id'] : 0;
  if ($pid > 0) {
    $key = 'p_' . $pid;
    if (!isset($groups[$key])) {
      $groups[$key] = [
        'parent_text' => (string)($row['parent_question_text'] ?? ''),
        'is_group'    => true,
        'rows'        => [],
      ];
    }
    $groups[$key]['rows'][] = $row;
  } else {
    // standalone — ใช้ answer_id เป็น key ไม่ชนกัน
    $groups['a_' . $row['answer_id']] = [
      'parent_text' => '',
      'is_group'    => false,
      'rows'        => [$row],
    ];
  }
}

// ══════════════════════════════════════════════════════════════
// ── ดึงเฉลยคำตอบ ──
// ══════════════════════════════════════════════════════════════
$correctChoiceMap  = [];  // [question_id => choice_text]   — เฉลยปรนัย
$questionAnswerMap = [];  // [question_id => answer]         — เฉลยอัตนัย
$subAnswerKeyMap   = [];  // [sub_question_id => sub_answer] — เฉลยโจทย์ย่อย

if (!empty($answers)) {
  $allQIds = array_values(array_unique(array_filter(
    array_column($answers, 'question_id'),
    fn($v) => (int)$v > 0
  )));

  if (!empty($allQIds)) {
    $inP   = implode(',', array_fill(0, count($allQIds), '?'));
    $types = str_repeat('i', count($allQIds));

    // เฉลยปรนัย: ดึง choice ที่ is_correct = 1
    try {
      $stC = $conn->prepare(
        "SELECT question_id, choice_text FROM choices WHERE question_id IN ({$inP}) AND is_correct = 1"
      );
      $stC->bind_param($types, ...$allQIds);
      $stC->execute();
      foreach ($stC->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $correctChoiceMap[(int)$c['question_id']] = (string)$c['choice_text'];
      }
    } catch (Throwable $e) {}

    // เฉลยอัตนัย: ดึง answer จาก questions
    try {
      $stA = $conn->prepare(
        "SELECT id, answer FROM questions WHERE id IN ({$inP})"
      );
      $stA->bind_param($types, ...$allQIds);
      $stA->execute();
      foreach ($stA->get_result()->fetch_all(MYSQLI_ASSOC) as $q) {
        $questionAnswerMap[(int)$q['id']] = (string)($q['answer'] ?? '');
      }
    } catch (Throwable $e) {}
  }
}

// เฉลยโจทย์ย่อย: ดึง sub_answer จาก sub_questions
if (!empty($subAnswersRaw)) {
  $allSubQIds = array_values(array_unique(array_filter(
    array_column($subAnswersRaw, 'sub_question_id'),
    fn($v) => $v !== null && (int)$v > 0
  )));

  if (!empty($allSubQIds)) {
    $inP2   = implode(',', array_fill(0, count($allSubQIds), '?'));
    $types2 = str_repeat('i', count($allSubQIds));
    try {
      // ตรวจก่อนว่าคอลัมน์ sub_answer มีอยู่
      $chkCol = $conn->query("SHOW COLUMNS FROM `sub_questions` LIKE 'sub_answer'");
      if ($chkCol && $chkCol->num_rows > 0) {
        $stSA = $conn->prepare(
          "SELECT id, sub_answer FROM sub_questions WHERE id IN ({$inP2})"
        );
        $stSA->bind_param($types2, ...$allSubQIds);
        $stSA->execute();
        foreach ($stSA->get_result()->fetch_all(MYSQLI_ASSOC) as $sq) {
          $subAnswerKeyMap[(int)$sq['id']] = (string)($sq['sub_answer'] ?? '');
        }
      }
    } catch (Throwable $e) {}
  }
}

// สร้างข้อความแสดงผู้เข้าสอบ
$userMeta = "";
if ($hasUsers) {
  $full = trim((string)($attempt['user_full_name'] ?? ""));
  $role = trim((string)($attempt['user_role'] ?? ""));

  if ($full !== "") $userDisplay = $full;
  if ($role !== "") $userMeta = "role: " . $role;
}

$usernameSafe = $hasUsers ? trim((string)($attempt['user_username'] ?? '')) : '';

$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>รายละเอียดผลสอบ</title>
  <style>
    :root {
      --bg: #f4f7f6;
      --card: #ffffff;
      --text: #111827;
      --muted: #6b7280;
      --line: #e5e7eb;
      --accent: #0f766e;
      --accent2: #10b981;
      --danger: #ef4444;
      --shadow: 0 10px 30px rgba(17, 24, 39, .08);
      --radius: 14px;
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, "Noto Sans Thai", sans-serif;
      background: var(--bg);
      color: var(--text);
    }

    .wrap {
      max-width: 1100px;
      margin: 24px auto;
      padding: 0 14px;
    }

    a {
      color: var(--accent);
      text-decoration: none
    }

    a:hover {
      text-decoration: underline
    }

    .top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .top h1 {
      margin: 0;
      font-size: 20px;
    }

    .sub {
      color: var(--muted);
      font-size: 13px;
      margin-top: 4px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 12px;
      border: 1px solid var(--line);
      background: #fff;
      cursor: pointer;
      box-shadow: var(--shadow);
    }

    .btn:hover {
      filter: brightness(.98)
    }

    .btnPrimary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 12px;
      border: 1px solid rgba(15, 118, 110, .35);
      background: var(--accent);
      color: #fff;
      cursor: pointer;
      box-shadow: var(--shadow);
      font-weight: 800;
    }

    .btnPrimary:hover {
      filter: brightness(.95)
    }

    .btnPrimary:disabled {
      opacity: .55;
      cursor: not-allowed;
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
    }

    @media (min-width: 980px) {
      .grid {
        grid-template-columns: 1fr 1fr;
      }

      .grid .full {
        grid-column: 1 / -1;
      }
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 14px;
    }

    .card h2 {
      margin: 0 0 10px;
      font-size: 16px;
    }

    .kv {
      display: grid;
      grid-template-columns: 140px 1fr;
      gap: 8px 12px;
      font-size: 13px;
    }

    .k {
      color: var(--muted)
    }

    .mono {
      font-variant-numeric: tabular-nums;
    }

    .scoreBig {
      font-size: 22px;
      font-weight: 800;
      color: var(--accent);
      font-variant-numeric: tabular-nums;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 12px;
      overflow: hidden;
    }

    th,
    td {
      padding: 12px 12px;
      border-bottom: 1px solid var(--line);
      text-align: left;
      vertical-align: top;
    }

    th {
      font-size: 12px;
      color: var(--muted);
      font-weight: 700;
      background: #fafafa;
    }

    .qtext {
      font-weight: 650
    }

    .muted {
      color: var(--muted);
      font-size: 12px
    }

    .answerBox {
      background: #fbfbfb;
      border: 1px dashed var(--line);
      padding: 10px;
      border-radius: 12px;
      white-space: pre-wrap;
      word-break: break-word;
    }

    /* ถ้ามี HTML ให้ปิด pre-wrap และเปิด line-height ปกติ */
    .answerBox.has-html {
      white-space: normal;
      line-height: 1.6;
    }

    .answerBox.has-html img {
      max-width: 100%;
      border-radius: 8px;
    }

    .answerBox.has-html video,
    .answerBox.has-html audio {
      max-width: 100%;
      border-radius: 8px;
      display: block;
      margin: 6px 0;
    }

    .answerBox.has-html table {
      border-collapse: collapse;
      width: 100%;
      font-size: 13px;
    }

    .answerBox.has-html td,
    .answerBox.has-html th {
      padding: 5px 8px;
      border: 1px solid var(--line);
    }

    /* qtext ที่มี HTML */
    .qtext.has-html {
      font-weight: 650;
      line-height: 1.6;
    }

    .qtext.has-html img {
      max-width: 100%;
      border-radius: 8px;
    }

    .qtext.has-html video,
    .qtext.has-html audio {
      max-width: 100%;
      border-radius: 8px;
      display: block;
      margin: 4px 0;
    }

    /* ── group / sub-question ── */
    .groupHeaderRow>td {
      background: #f0fdf8;
      border-left: 4px solid var(--accent);
      padding-top: 14px;
      padding-bottom: 10px;
    }

    .groupHeaderText {
      font-weight: 700;
      font-size: 14px;
      line-height: 1.6;
    }

    .groupHeaderText.has-html {
      line-height: 1.7;
    }

    .groupHeaderText.has-html img {
      max-width: 100%;
      border-radius: 8px;
    }

    .groupHeaderText.has-html video,
    .groupHeaderText.has-html audio {
      max-width: 100%;
      border-radius: 8px;
      display: block;
      margin: 4px 0;
    }

    .subRow>td {
      background: #fafafa;
      padding-left: 32px;
      border-left: 4px solid var(--line);
    }

    .subLabel {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 26px;
      height: 26px;
      border-radius: 6px;
      background: rgba(15, 118, 110, .12);
      color: var(--accent);
      font-size: 12px;
      font-weight: 800;
      flex-shrink: 0;
    }

    .scoreInput {
      width: 90px;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid var(--line);
      outline: none;
      font-variant-numeric: tabular-nums;
    }

    .scoreInput:focus {
      border-color: rgba(15, 118, 110, .55);
      box-shadow: 0 0 0 4px rgba(15, 118, 110, .12);
    }

    .dirtyTag {
      display: none;
      font-size: 12px;
      color: #b45309;
    }

    tr.is-dirty .dirtyTag {
      display: inline-flex;
    }

    .saveBadge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: var(--muted);
    }

    .toast {
      position: fixed;
      right: 16px;
      bottom: 16px;
      background: #111827;
      color: #fff;
      padding: 10px 12px;
      border-radius: 12px;
      opacity: 0;
      transform: translateY(8px);
      transition: .2s ease;
      pointer-events: none;
      font-size: 13px;
    }

    .toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .alert {
      border: 1px solid rgba(239, 68, 68, .25);
      background: rgba(239, 68, 68, .08);
      color: #7f1d1d;
      padding: 10px 12px;
      border-radius: 12px;
      margin-bottom: 12px;
      box-shadow: var(--shadow);
      font-size: 13px;
    }

    .warn {
      color: #b45309
    }

    /* ── sub-answers (exam_sub_answers) ── */
    .subAnswersSection {
      margin-top: 12px;
      border-top: 1px dashed #d1d5db;
      padding-top: 8px;
    }

    .subAnswersLabel {
      font-size: 11px;
      font-weight: 700;
      color: #6b7280;
      margin-bottom: 6px;
      letter-spacing: .03em;
    }

    .subAnswerItem {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-left: 3px solid #9ca3af;
      border-radius: 10px;
      padding: 10px 12px;
      margin-top: 6px;
    }

    .subAnswerHeader {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin-bottom: 6px;
    }

    .subQText {
      font-weight: 650;
      font-size: 13px;
      flex: 1;
      color: #374151;
    }

    .subScore {
      font-size: 13px;
      color: #374151;
      font-weight: 700;
      white-space: nowrap;
    }

    .correctBadge {
      font-size: 11px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 20px;
      white-space: nowrap;
    }

    .correctBadge.correct {
      background: #dcfce7;
      color: #15803d;
    }

    .correctBadge.incorrect {
      background: #fee2e2;
      color: #b91c1c;
    }

    .subAnswerItem .answerBox {
      background: #f3f4f6;
      border-color: #d1d5db;
    }

    .subScoreRow {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 10px;
      padding-top: 8px;
      border-top: 1px dashed #d1d5db;
    }

    .subDirtyTag {
      font-size: 11px;
    }

    .subAnswerItem.is-sub-dirty {
      border-left-color: #b45309;
      background: #fffbeb;
    }

    /* ── answer key (เฉลย) — minimal ── */
    .answerKeyBox {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 4px;
      margin-top: 6px;
      font-size: 12px;
      color: #6b7280;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .answerKeyBox.has-html {
      white-space: normal;
      line-height: 1.6;
    }

    .answerKeyBox.has-html img {
      max-width: 100%;
      border-radius: 6px;
    }

    .answerKeyLabel {
      font-weight: 700;
      color: #306e47;
      flex-shrink: 0;
    }

    .summaryRow {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      align-items: flex-end;
      justify-content: space-between;
    }

    .summaryLeft {
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      align-items: flex-end;
    }
  </style>
</head>

<body>
  <div class="wrap">

    <?php if ($flashError !== ""): ?>
      <div class="alert"><?= h($flashError) ?></div>
    <?php endif; ?>

    <div class="top">
      <div>
        <h1>รายละเอียดผลสอบ</h1>
      </div>
      <a class="btn" href="<?= h($backUrl) ?>">← ย้อนกลับ</a>
    </div>

    <div class="grid">
      <div class="card">
        <h2>ข้อมูลชุดข้อสอบ</h2>
        <div class="kv">
          <div class="k">ข้อสอบ</div>
          <div>
            <b><?= h($attempt['exam_title']) ?></b>
          </div>
          <div class="k">คำชี้แจง</div>
          <div class="answerBox"><?= h($attempt['instructions_content'] ?? "-") ?></div>
        </div>
      </div>

      <div class="card">
        <h2>ข้อมูลผู้เข้าสอบ</h2>
        <div class="kv">
          <div class="k">ผู้สอบ</div>
          <div><b><?= h($userDisplay) ?></b></div>
          <div class="k">รหัส</div>
          <div class="mono"><?= h($usernameSafe !== '' ? $usernameSafe : '-') ?></div>
          <div class="k">IP</div>
          <div class="mono"><?= h($attempt['client_ip'] ?? "-") ?></div>
          <div class="k">เริ่ม</div>
          <div class="mono"><?= h($attempt['started_at'] ?? "-") ?></div>
          <div class="k">ส่ง</div>
          <div class="mono"><?= h($attempt['submitted_at'] ?? "-") ?></div>
        </div>
      </div>

      <div class="card full">
        <div class="summaryRow">
          <div class="summaryLeft">
            <div>
              <div class="muted">คะแนนรวม</div>
              <div id="totalScore" class="scoreBig">
                <?= (float)($attempt['score_total'] ?? 0) ?> / <?= (float)($attempt['score_max'] ?? 0) ?>
              </div>
            </div>
            <div>
              <div class="muted">ตอบแล้ว</div>
              <div class="mono" style="font-size:16px; font-weight:750;">
                <?= (int)($attempt['answered_questions'] ?? 0) ?> / <?= (int)($attempt['total_questions'] ?? 0) ?>
              </div>
            </div>
            <div class="saveBadge" id="saveBadge">พร้อมแก้คะแนน</div>
          </div>

          <!-- SINGLE SAVE BUTTON -->
          <div>
            <button class="btnPrimary" type="submit" form="saveAllForm" id="saveAllBtn" disabled>บันทึกคะแนน</button>
          </div>
        </div>
        <div class="muted" style="margin-top:8px;"></div>
      </div>

      <div class="card full" style="padding:0;">
        <div style="padding:14px 14px 0;">
          <h2>ข้อที่ทำและคะแนนรายข้อ</h2>

        </div>

        <form id="saveAllForm" method="post" style="padding:14px;">
          <input type="hidden" name="action" value="save_all" />
          <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>" />

          <table>
            <thead>
              <tr>
                <th style="width:70px">#</th>
                <th>คำถาม / คำตอบ</th>
                <th style="width:160px">ชนิด</th>
                <th style="width:220px">คะแนน</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$groups): ?>
                <tr>
                  <td colspan="4" class="muted" style="padding:16px; text-align:center;">ไม่พบคำตอบใน attempt นี้</td>
                </tr>
              <?php else: ?>
                <?php
                $qNum = 0; // ลำดับข้อหลัก (นับทั้ง standalone + group)
                $subLabels = range('a', 'z');
                ?>
                <?php foreach ($groups as $group): ?>
                  <?php $qNum++; ?>

                  <?php if ($group['is_group']): ?>
                    <!-- ══ GROUP: แสดง parent question เป็น header ══ -->
                    <?php
                    $ptxt   = (string)$group['parent_text'];
                    $ptxtH  = $ptxt !== strip_tags($ptxt);
                    ?>
                    <tr class="groupHeaderRow">
                      <td class="mono"><b><?= $qNum ?></b></td>
                      <td colspan="3">
                        <div class="groupHeaderText<?= $ptxtH ? ' has-html' : '' ?>">
                          <?= safe_html($ptxt ?: '(ไม่มีโจทย์แม่)') ?>
                        </div>
                      </td>
                    </tr>
                    <!-- sub-question rows -->
                    <?php foreach ($group['rows'] as $si => $row): ?>
                      <?php
                      $sl       = $subLabels[$si] ?? ($si + 1);
                      $type     = $row['question_type'] ?? '-';
                      $answerShow = '-';
                      if (!empty($row['selected_choice_id'])) {
                        $answerShow = 'ตัวเลือก: ' . ($row['selected_choice_text'] ?? '(ไม่พบข้อความตัวเลือก)');
                      } elseif (($row['answer_text'] ?? '') !== '') {
                        $answerShow = $row['answer_text'];
                      }
                      $scoreVal = is_null($row['score'])     ? 0 : (float)$row['score'];
                      $maxVal   = is_null($row['max_score']) ? 0 : (float)$row['max_score'];
                      $answerId = (int)($row['answer_id'] ?? 0);
                      $qRaw     = (string)($row['question_text'] ?? '');
                      $qIsHtml  = $qRaw !== strip_tags($qRaw);
                      $ansIsHtml = $answerShow !== strip_tags($answerShow);
                      ?>
                      <tr class="subRow" data-answer-id="<?= $answerId ?>" data-max-score="<?= h($maxVal) ?>">
                        <td class="mono">
                          <span class="subLabel"><?= h((string)$sl) ?></span>
                        </td>
                        <td>
                          <?php if ($qRaw !== ''): ?>
                            <div class="qtext<?= $qIsHtml ? ' has-html' : '' ?>" style="margin-bottom:6px;">
                              <?= safe_html($qRaw) ?>
                            </div>
                          <?php endif; ?>
                          <div class="muted" style="margin:4px 0 6px;">คำตอบ</div>
                          <div class="answerBox<?= $ansIsHtml ? ' has-html' : '' ?>"><?= safe_html($answerShow) ?></div>
                          <?php
                          // ── เฉลย (GROUP row) ──
                          $gQid = (int)($row['question_id'] ?? 0);
                          $gSubRows = $subAnswersMap[$gQid] ?? [];
                          if (!empty($row['selected_choice_id'])) {
                            // ปรนัย
                            $gKey = $correctChoiceMap[$gQid] ?? '';
                            if ($gKey !== ''):
                              $gKeyIsHtml = $gKey !== strip_tags($gKey);
                          ?>
                            <div class="answerKeyBox<?= $gKeyIsHtml ? ' has-html' : '' ?>">
                              <span class="answerKeyLabel"> เฉลย:</span> <?= safe_html($gKey) ?>
                            </div>
                          <?php
                            endif;
                          } elseif (empty($gSubRows)) {
                            // อัตนัยแบบข้อเดี่ยว (ไม่มีโจทย์ย่อย)
                            $gKey = $questionAnswerMap[$gQid] ?? '';
                            if ($gKey !== ''):
                              $gKeyIsHtml = $gKey !== strip_tags($gKey);
                          ?>
                            <div class="answerKeyBox<?= $gKeyIsHtml ? ' has-html' : '' ?>">
                              <span class="answerKeyLabel"> เฉลย:</span> <?= safe_html($gKey) ?>
                            </div>
                          <?php
                            endif;
                          }
                          ?>
                          <?php if (($row['feedback'] ?? '') !== ''): ?>

                          <?php endif; ?>
                          <?php render_sub_answers(
                            $subAnswersMap[(int)($row['question_id'] ?? 0)] ?? [],
                            $subLabels,
                            $subAnswerKeyMap
                          ); ?>
                        </td>
                        <td class="mono"><?= h($type) ?></td>
                        <td>
                          <div class="muted">คะแนนที่ได้ / คะแนนเต็ม</div>
                          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:6px;">
                            <input class="scoreInput" type="number" step="any" min="0"
                              name="scores[<?= $answerId ?>]"
                              value="<?= h($scoreVal) ?>"
                              data-score-input />
                            <div class="mono">/ <?= h($maxVal) ?></div>
                            <span class="dirtyTag">แก้ไขแล้ว (ยังไม่บันทึก)</span>
                          </div>
                          <div class="muted warn" style="margin-top:6px; display:none;" data-warn>ปรับให้อยู่ในช่วง 0 ถึงคะแนนเต็มแล้ว</div>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                  <?php else: ?>
                    <!-- ══ STANDALONE ROW (ข้อปกติ ไม่มีโจทย์แม่) ══ -->
                    <?php
                    $row      = $group['rows'][0];
                    $type     = $row['question_type'] ?? '-';
                    $answerShow = '-';
                    if (!empty($row['selected_choice_id'])) {
                      $answerShow = 'ตัวเลือก: ' . ($row['selected_choice_text'] ?? '(ไม่พบข้อความตัวเลือก)');
                    } elseif (($row['answer_text'] ?? '') !== '') {
                      $answerShow = $row['answer_text'];
                    }
                    $scoreVal  = is_null($row['score'])     ? 0 : (float)$row['score'];
                    $maxVal    = is_null($row['max_score']) ? 0 : (float)$row['max_score'];
                    $answerId  = (int)($row['answer_id'] ?? 0);
                    $qRaw      = (string)($row['question_text'] ?? '');
                    $qIsHtml   = $qRaw !== strip_tags($qRaw);
                    $ansIsHtml = $answerShow !== strip_tags($answerShow);
                    ?>
                    <tr data-answer-id="<?= $answerId ?>" data-max-score="<?= h($maxVal) ?>">
                      <td class="mono"><b><?= $qNum ?></b></td>
                      <td>
                        <div class="qtext<?= $qIsHtml ? ' has-html' : '' ?>"><?= safe_html($qRaw ?: '-') ?></div>
                        <div class="muted" style="margin:6px 0 6px;">คำตอบ</div>
                        <div class="answerBox<?= $ansIsHtml ? ' has-html' : '' ?>"><?= safe_html($answerShow) ?></div>
                        <?php
                        // ── เฉลย (STANDALONE row) ──
                        $sQid = (int)($row['question_id'] ?? 0);
                        $sSubRows = $subAnswersMap[$sQid] ?? [];
                        if (!empty($row['selected_choice_id'])) {
                          // ปรนัย
                          $sKey = $correctChoiceMap[$sQid] ?? '';
                          if ($sKey !== ''):
                            $sKeyIsHtml = $sKey !== strip_tags($sKey);
                        ?>
                          <div class="answerKeyBox<?= $sKeyIsHtml ? ' has-html' : '' ?>">
                            <span class="answerKeyLabel"> เฉลย:</span> <?= safe_html($sKey) ?>
                          </div>
                        <?php
                          endif;
                        } elseif (empty($sSubRows)) {
                          // อัตนัยแบบข้อเดี่ยว (ไม่มีโจทย์ย่อย)
                          $sKey = $questionAnswerMap[$sQid] ?? '';
                          if ($sKey !== ''):
                            $sKeyIsHtml = $sKey !== strip_tags($sKey);
                        ?>
                          <div class="answerKeyBox<?= $sKeyIsHtml ? ' has-html' : '' ?>">
                            <span class="answerKeyLabel"> เฉลย:</span> <?= safe_html($sKey) ?>
                          </div>
                        <?php
                          endif;
                        }
                        ?>
                        <?php if (($row['feedback'] ?? '') !== ''): ?>

                        <?php endif; ?>
                        <?php render_sub_answers(
                          $subAnswersMap[(int)($row['question_id'] ?? 0)] ?? [],
                          $subLabels,
                          $subAnswerKeyMap
                        ); ?>
                      </td>
                      <td class="mono"><?= h($type) ?></td>
                      <td>
                        <div class="muted">คะแนนที่ได้ / คะแนนเต็ม</div>
                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:6px;">
                          <input class="scoreInput" type="number" step="any" min="0"
                            name="scores[<?= $answerId ?>]"
                            value="<?= h($scoreVal) ?>"
                            data-score-input />
                          <div class="mono">/ <?= h($maxVal) ?></div>
                          <span class="dirtyTag">แก้ไขแล้ว (ยังไม่บันทึก)</span>
                        </div>
                        <div class="muted warn" style="margin-top:6px; display:none;" data-warn>ปรับให้อยู่ในช่วง 0 ถึงคะแนนเต็มแล้ว</div>
                      </td>
                    </tr>
                  <?php endif; ?>

                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </form>
      </div>

    </div>
  </div>

  <div class="toast" id="toast"></div>

  <script>
    (function() {
      const saveBtn = document.getElementById('saveAllBtn');
      const badge = document.getElementById('saveBadge');
      const toast = document.getElementById('toast');
      const saved = <?= $saved ? 'true' : 'false' ?>;

      function showToast(msg) {
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 1600);
      }

      function setBadge(text) {
        badge.textContent = text;
      }

      function clampScore(val, max) {
        if (isNaN(val)) val = 0;
        if (val < 0) val = 0;
        if (val > max) val = max;
        return val;
      }

      function refreshDirty() {
        const dirtyCount = document.querySelectorAll('tr.is-dirty').length;
        if (dirtyCount > 0) {
          saveBtn.disabled = false;
          setBadge('มีคะแนนที่แก้ไข (' + dirtyCount + ')');
        } else {
          saveBtn.disabled = true;
          setBadge('พร้อมแก้คะแนน');
        }
      }

      // mark dirty + clamp on blur
      document.querySelectorAll('tr[data-answer-id]').forEach(tr => {
        const input = tr.querySelector('[data-score-input]');
        const warn = tr.querySelector('[data-warn]');
        if (!input) return;

        input.addEventListener('input', () => {
          tr.classList.add('is-dirty');
          refreshDirty();
        });

        input.addEventListener('blur', () => {
          const max = parseFloat(tr.getAttribute('data-max-score')) || 0;
          const v = parseFloat(input.value);
          const clamped = clampScore(v, max);
          if (clamped !== v) {
            input.value = clamped;
            warn.style.display = 'block';
            setTimeout(() => warn.style.display = 'none', 1300);
            tr.classList.add('is-dirty');
            refreshDirty();
          }
        });

        // Enter => submit save all
        input.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            if (!saveBtn.disabled) {
              document.getElementById('saveAllForm').requestSubmit();
            }
          }
        });
      });

      // submit feedback
      const form = document.getElementById('saveAllForm');
      if (form) {
        form.addEventListener('submit', () => {
          saveBtn.disabled = true;
          setBadge('กำลังบันทึกคะแนน...');
        });
      }

      if (saved) showToast('บันทึกคะแนนทั้งหมดแล้ว');
      refreshDirty();

      // ── sub-score inputs (exam_sub_answers) ──

      /**
       * รวมคะแนน sub ทั้งหมดใน <tr> เดียวกัน แล้วอัปเดต parent score input
       * ถ้าไม่มี parent score input (กรณี group row ไม่มีช่องคะแนนแม่) ก็ข้ามไป
       */
      function rollupSubScores(parentTr) {
        if (!parentTr) return;
        const parentInput = parentTr.querySelector('[data-score-input]');
        if (!parentInput) return;

        // รวมค่า sub inputs ทั้งหมดใน tr นี้
        const subInputs = parentTr.querySelectorAll('[data-sub-score-input]');
        if (subInputs.length === 0) return;

        let sum = 0;
        subInputs.forEach(si => {
          const v = parseFloat(si.value);
          sum += isNaN(v) ? 0 : v;
        });

        // clamp ไม่ให้เกิน max ของ parent
        const parentMax = parseFloat(parentTr.getAttribute('data-max-score')) || 0;
        const clamped = parentMax > 0 ? Math.min(sum, parentMax) : sum;

        parentInput.value = Math.round(clamped * 10000) / 10000; // ป้องกัน floating point เกิน
        parentTr.classList.add('is-dirty');
        refreshDirty();
      }

      document.querySelectorAll('.subAnswerItem[data-sub-answer-id]').forEach(item => {
        const input = item.querySelector('[data-sub-score-input]');
        const warn = item.querySelector('[data-sub-warn]');
        const parentTr = item.closest('tr');
        if (!input) return;

        input.addEventListener('input', () => {
          item.classList.add('is-sub-dirty');
          rollupSubScores(parentTr);
        });

        input.addEventListener('blur', () => {
          const max = parseFloat(item.getAttribute('data-sub-max-score')) || 0;
          const v = parseFloat(input.value);
          const clamped = clampScore(v, max);
          if (clamped !== v) {
            input.value = clamped;
            if (warn) {
              warn.style.display = 'block';
              setTimeout(() => warn.style.display = 'none', 1300);
            }
            item.classList.add('is-sub-dirty');
          }
          // rollup เสมอหลัง blur (รวมทั้งกรณีที่ clamp แล้วค่าเปลี่ยน)
          rollupSubScores(parentTr);
        });

        input.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            if (!saveBtn.disabled) {
              document.getElementById('saveAllForm').requestSubmit();
            }
          }
        });
      });
    })();
  </script>
</body>

</html>