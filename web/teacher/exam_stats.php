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

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ลบ media elements
function stripMedia(string $s): string {
  $s = preg_replace('/<(video|audio|iframe|figure)[^>]*>.*?<\/\1>/is', '', $s);
  $s = preg_replace('/<img\b[^>]*>/i', '', $s);
  return trim(strip_tags($s));
}

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$passPct = isset($_GET['pass_pct']) ? max(0, min(100, (int)$_GET['pass_pct'])) : 50;

if ($exam_id <= 0) {
  http_response_code(400);
  echo "missing exam_id";
  exit;
}

// ---------- schema helpers (robust across your DB variations) ----------
$colsCache = [];
function tableCols(mysqli $conn, string $schema, string $table): array {
  global $colsCache;
  $k = $schema . "." . $table;
  if (isset($colsCache[$k])) return $colsCache[$k];

  $st = $conn->prepare("
    SELECT COLUMN_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
  ");
  $st->bind_param("ss", $schema, $table);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

  $cols = [];
  foreach ($rows as $r) $cols[] = $r['COLUMN_NAME'];
  $colsCache[$k] = $cols;
  return $cols;
}

function colExists(mysqli $conn, string $schema, string $table, string $col): bool {
  $cols = tableCols($conn, $schema, $table);
  return in_array($col, $cols, true);
}

function firstCol(mysqli $conn, string $schema, string $table, array $cands): ?string {
  $cols = tableCols($conn, $schema, $table);
  foreach ($cands as $c) if (in_array($c, $cols, true)) return $c;
  return null;
}

function median(array $nums): ?float {
  $n = count($nums);
  if ($n === 0) return null;
  sort($nums, SORT_NUMERIC);
  $mid = intdiv($n, 2);
  if ($n % 2 === 1) return (float)$nums[$mid];
  return ((float)$nums[$mid - 1] + (float)$nums[$mid]) / 2.0;
}

function fmtSec(?float $sec): string {
  if ($sec === null) return "-";
  $s = (int)round($sec);
  $h = intdiv($s, 3600); $s %= 3600;
  $m = intdiv($s, 60);   $s %= 60;
  if ($h > 0) return sprintf("%dh %02dm %02ds", $h, $m, $s);
  if ($m > 0) return sprintf("%dm %02ds", $m, $s);
  return sprintf("%ds", $s);
}

function pct(float $x, int $dp=1): string {
  return number_format($x, $dp) . "%";
}

// ---------- exam title ----------
$st = $conn->prepare("SELECT title FROM exams WHERE id = ? LIMIT 1");
$st->bind_param("i", $exam_id);
$st->execute();
$exam = $st->get_result()->fetch_assoc();
$examTitle = (string)($exam['title'] ?? ("#".$exam_id));

// ---------- overall attempt stats (submitted-based for scoring) ----------
$stats = [
  'attempt_total' => 0,
  'submitted_total' => 0,
  'inprogress_total' => 0,
  'distinct_users_submitted' => 0,
  'avg_score' => null,
  'avg_pct' => null,
  'min_score' => null,
  'max_score' => null,
  'avg_duration_sec' => null,
  'min_duration_sec' => null,
  'max_duration_sec' => null,
];

$st = $conn->prepare("
  SELECT
    COUNT(*) AS attempt_total,
    SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS submitted_total,
    SUM(CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END) AS inprogress_total,
    COUNT(DISTINCT CASE WHEN submitted_at IS NOT NULL THEN user_id ELSE NULL END) AS distinct_users_submitted,
    AVG(CASE WHEN submitted_at IS NOT NULL THEN score_total ELSE NULL END) AS avg_score,
    AVG(CASE WHEN submitted_at IS NOT NULL AND score_max > 0 THEN (score_total/score_max)*100 ELSE NULL END) AS avg_pct,
    MIN(CASE WHEN submitted_at IS NOT NULL THEN score_total ELSE NULL END) AS min_score,
    MAX(CASE WHEN submitted_at IS NOT NULL THEN score_total ELSE NULL END) AS max_score,
    AVG(CASE WHEN submitted_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, submitted_at) ELSE NULL END) AS avg_duration_sec,
    MIN(CASE WHEN submitted_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, submitted_at) ELSE NULL END) AS min_duration_sec,
    MAX(CASE WHEN submitted_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, submitted_at) ELSE NULL END) AS max_duration_sec
  FROM exam_attempts
  WHERE exam_id = ?
");
$st->bind_param("i", $exam_id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if ($row) {
  foreach ($stats as $k => $_) $stats[$k] = $row[$k] ?? $stats[$k];
  $stats['attempt_total'] = (int)$stats['attempt_total'];
  $stats['submitted_total'] = (int)$stats['submitted_total'];
  $stats['inprogress_total'] = (int)$stats['inprogress_total'];
  $stats['distinct_users_submitted'] = (int)$stats['distinct_users_submitted'];
}

// ---------- collect submitted score percentages for median + histogram + pass rate ----------
$scorePcts = [];
$scorePairs = []; // [score_total, score_max]
$st = $conn->prepare("
  SELECT score_total, score_max
  FROM exam_attempts
  WHERE exam_id = ? AND submitted_at IS NOT NULL
  ORDER BY id ASC
  LIMIT 20000
");
$st->bind_param("i", $exam_id);
$st->execute();
$res = $st->get_result();
while ($r = $res->fetch_assoc()) {
  $t = (float)($r['score_total'] ?? 0);
  $m = (float)($r['score_max'] ?? 0);
  $scorePairs[] = [$t, $m];
  if ($m > 0) $scorePcts[] = ($t / $m) * 100.0;
}
$medianPct = median($scorePcts);

$passCount = 0;
foreach ($scorePcts as $p) if ($p >= $passPct) $passCount++;
$passRate = count($scorePcts) > 0 ? ($passCount / count($scorePcts)) * 100.0 : null;

// histogram 10 buckets: 0-9, 10-19, ... 90-100
$hist = array_fill(0, 10, 0);
foreach ($scorePcts as $p) {
  $idx = (int)floor($p / 10.0);
  if ($idx < 0) $idx = 0;
  if ($idx > 9) $idx = 9;
  $hist[$idx]++;
}
$histMax = max($hist) ?: 1;


$days = 14;
$startDate = date('Y-m-d', strtotime("-{$days} days"));
$dailyMap = []; // Y-m-d => count
$st = $conn->prepare("
  SELECT DATE(submitted_at) AS d, COUNT(*) AS c
  FROM exam_attempts
  WHERE exam_id = ? AND submitted_at IS NOT NULL AND submitted_at >= ?
  GROUP BY DATE(submitted_at)
  ORDER BY DATE(submitted_at) ASC
");
$startDateTime = $startDate . " 00:00:00";
$st->bind_param("is", $exam_id, $startDateTime);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) {
  $d = (string)($r['d'] ?? '');
  if ($d !== '') $dailyMap[$d] = (int)($r['c'] ?? 0);
}
$daily = [];
$dailyMax = 1;
for ($i=$days; $i>=0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} days"));
  $c = $dailyMap[$d] ?? 0;
  $daily[] = ['d'=>$d, 'c'=>$c];
  if ($c > $dailyMax) $dailyMax = $c;
}

// ---------- section stats (optional if your schema has exam_section) ----------
$hasSectionTable = false;
try {
  $r = $conn->query("SHOW TABLES LIKE 'exam_section'");
  $hasSectionTable = ($r->num_rows > 0);
} catch (Throwable $e) { $hasSectionTable = false; }

$sectionRows = [];
$sectionTitleCol = null;
$sectionIdCol = null;
$sectionExamCol = null;

$questionTextCol = firstCol($conn, $db, "questions", ["question_text","question","text","title","prompt","content"]);
$questionTypeCol = firstCol($conn, $db, "questions", ["type","question_type","q_type"]);
$questionOrderCol = firstCol($conn, $db, "questions", ["sort_order","order_no","question_order","position","seq","id"]);
$questionMaxCol = firstCol($conn, $db, "questions", ["max_score","score_max","points","point","full_score"]);

$questionExamCol = firstCol($conn, $db, "questions", ["exam_id"]);
$questionSectionCol = firstCol($conn, $db, "questions", ["section_id","exam_section_id"]);

if ($hasSectionTable) {
  $sectionTitleCol = firstCol($conn, $db, "exam_section", ["title","name","section_title","section_name"]);
  $sectionIdCol = firstCol($conn, $db, "exam_section", ["id"]);
  $sectionExamCol = firstCol($conn, $db, "exam_section", ["exam_id"]);

  if ($sectionIdCol && $sectionExamCol) {
    // section list + question counts
    $sql = "
      SELECT s.{$sectionIdCol} AS section_id,
             ".($sectionTitleCol ? "s.{$sectionTitleCol} AS section_title," : "CONCAT('Section #', s.{$sectionIdCol}) AS section_title,")."
             COUNT(q.id) AS question_count
      FROM exam_section s
      LEFT JOIN questions q ON ".($questionSectionCol ? "q.{$questionSectionCol} = s.{$sectionIdCol}" : "1=0")."
      WHERE s.{$sectionExamCol} = ?
      GROUP BY s.{$sectionIdCol}
      ORDER BY s.{$sectionIdCol} ASC
    ";
    $st = $conn->prepare($sql);
    $st->bind_param("i", $exam_id);
    $st->execute();
    $sectionRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  }
}

// ---------- question list ----------
$questions = [];
$joinSection = "";
$selectSection = "'' AS section_title";
if ($hasSectionTable && $sectionIdCol && $sectionExamCol && $questionSectionCol) {
  $joinSection = " LEFT JOIN exam_section s ON s.{$sectionIdCol} = q.{$questionSectionCol} ";
  $selectSection = ($sectionTitleCol ? "COALESCE(s.{$sectionTitleCol}, '') AS section_title" : "CONCAT('Section #', s.{$sectionIdCol}) AS section_title");
}

$whereExam = "";
if ($questionExamCol) {
  $whereExam = " WHERE q.{$questionExamCol} = ? ";
} elseif ($hasSectionTable && $sectionExamCol && $questionSectionCol) {
  $whereExam = " WHERE s.{$sectionExamCol} = ? ";
} else {
  // fallback (worst-case): no way to filter
  $whereExam = " WHERE 1=0 ";
}

$qText = $questionTextCol ? "q.{$questionTextCol} AS question_text" : "CONCAT('Question #', q.id) AS question_text";
$qType = $questionTypeCol ? "q.{$questionTypeCol} AS q_type" : "'' AS q_type";
$qOrder = $questionOrderCol ? "q.{$questionOrderCol}" : "q.id";
$qMax  = $questionMaxCol ? "q.{$questionMaxCol} AS max_score" : "NULL AS max_score";

$sql = "
  SELECT
    q.id AS question_id,
    {$qText},
    {$qType},
    {$qMax},
    {$selectSection},
    {$qOrder} AS q_order
  FROM questions q
  {$joinSection}
  {$whereExam}
  ORDER BY {$qOrder} ASC, q.id ASC
  LIMIT 2000
";
$st = $conn->prepare($sql);
$st->bind_param("i", $exam_id);
$st->execute();
$questions = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------- answer aggregation per question ----------
$ansChoiceCol = firstCol($conn, $db, "exam_answers", ["choice_id","answer_choice_id","selected_choice_id"]);
$ansTextCol   = firstCol($conn, $db, "exam_answers", ["answer_text","answer","text","response_text"]);
$ansScoreCol  = firstCol($conn, $db, "exam_answers", ["score","score_value","points","point"]);
$ansCorrectCol= firstCol($conn, $db, "exam_answers", ["is_correct","correct","is_true"]);

$choiceTextCol= firstCol($conn, $db, "choices", ["choice_text","text","label","title","choice"]);
$choiceCorrectCol = firstCol($conn, $db, "choices", ["is_correct","correct","is_true"]);

$answeredExprParts = [];
if ($ansChoiceCol) $answeredExprParts[] = "(ea.{$ansChoiceCol} IS NOT NULL)";
if ($ansTextCol)   $answeredExprParts[] = "(ea.{$ansTextCol} IS NOT NULL AND ea.{$ansTextCol} <> '')";
$answeredExpr = $answeredExprParts ? "(" . implode(" OR ", $answeredExprParts) . ")" : "0";

$needJoinChoicesForCorrect = (!$ansCorrectCol && $ansChoiceCol && $choiceCorrectCol);
$joinChoicesForCorrect = $needJoinChoicesForCorrect ? " LEFT JOIN choices c ON c.id = ea.{$ansChoiceCol} " : "";

$correctExpr = "NULL";
if ($ansCorrectCol) {
  $correctExpr = "CASE WHEN ea.{$ansCorrectCol} = 1 THEN 1 ELSE 0 END";
} elseif ($needJoinChoicesForCorrect) {
  $correctExpr = "CASE WHEN c.{$choiceCorrectCol} = 1 THEN 1 ELSE 0 END";
}

$scoreExpr = $ansScoreCol ? "ea.{$ansScoreCol}" : "NULL";
$lenExpr = $ansTextCol ? "CHAR_LENGTH(ea.{$ansTextCol})" : "NULL";

$agg = []; // question_id => stats
$st = $conn->prepare("
  SELECT
    ea.question_id,
    COUNT(*) AS rows_total,
    SUM(CASE WHEN {$answeredExpr} THEN 1 ELSE 0 END) AS answered_count,
    AVG({$scoreExpr}) AS avg_score,
    AVG({$lenExpr}) AS avg_len,
    ".($correctExpr !== "NULL" ? "SUM({$correctExpr}) AS correct_count," : "NULL AS correct_count,")."
    ".($correctExpr !== "NULL" ? "SUM(CASE WHEN {$answeredExpr} THEN 1 ELSE 0 END) AS answered_for_correct" : "NULL AS answered_for_correct")."
  FROM exam_answers ea
  JOIN exam_attempts a ON a.id = ea.attempt_id
  {$joinChoicesForCorrect}
  WHERE a.exam_id = ? AND a.submitted_at IS NOT NULL
  GROUP BY ea.question_id
");
$st->bind_param("i", $exam_id);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) {
  $qid = (int)$r['question_id'];
  $agg[$qid] = [
    'rows_total' => (int)($r['rows_total'] ?? 0),
    'answered_count' => (int)($r['answered_count'] ?? 0),
    'avg_score' => $r['avg_score'] !== null ? (float)$r['avg_score'] : null,
    'avg_len' => $r['avg_len'] !== null ? (float)$r['avg_len'] : null,
    'correct_count' => $r['correct_count'] !== null ? (int)$r['correct_count'] : null,
    'answered_for_correct' => $r['answered_for_correct'] !== null ? (int)$r['answered_for_correct'] : null,
  ];
}

// choice distribution: question_id => choice_id => count
$choiceDist = [];
if ($ansChoiceCol) {
  $st = $conn->prepare("
    SELECT ea.question_id, ea.{$ansChoiceCol} AS choice_id, COUNT(*) AS c
    FROM exam_answers ea
    JOIN exam_attempts a ON a.id = ea.attempt_id
    WHERE a.exam_id = ? AND a.submitted_at IS NOT NULL
      AND ea.{$ansChoiceCol} IS NOT NULL
    GROUP BY ea.question_id, ea.{$ansChoiceCol}
  ");
  $st->bind_param("i", $exam_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  foreach ($rows as $r) {
    $qid = (int)$r['question_id'];
    $cid = (int)$r['choice_id'];
    $c = (int)$r['c'];
    if (!isset($choiceDist[$qid])) $choiceDist[$qid] = [];
    $choiceDist[$qid][$cid] = $c;
  }
}

// preload choices by question
$choicesByQ = []; 
if ($choiceTextCol) {
  
  $qids = array_map(fn($q)=> (int)$q['question_id'], $questions);
  $qids = array_values(array_unique($qids));
  if (count($qids) > 0) {
    
    $in = implode(",", array_map("intval", $qids));
    $sql = "
      SELECT
        id AS choice_id,
        question_id,
        {$choiceTextCol} AS choice_text
        ".($choiceCorrectCol ? ", {$choiceCorrectCol} AS is_correct" : ", NULL AS is_correct")."
      FROM choices
      WHERE question_id IN ({$in})
      ORDER BY question_id ASC, id ASC
    ";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
      $qid = (int)$r['question_id'];
      if (!isset($choicesByQ[$qid])) $choicesByQ[$qid] = [];
      $choicesByQ[$qid][] = [
        'choice_id' => (int)$r['choice_id'],
        'choice_text' => (string)($r['choice_text'] ?? ''),
        'is_correct' => ($r['is_correct'] !== null ? (int)$r['is_correct'] : null),
      ];
    }
  }
}

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>สถิติข้อสอบ</title>
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
    .title .sub{color:var(--muted); font-size:20px;}
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
    .grid{
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap: 12px;
    }
    .stat{
      padding:14px;
      border:1px solid var(--line);
      border-radius: var(--radius);
      background:#fff;
      box-shadow: var(--shadow);
      grid-column: span 4;
      min-height: 86px;
    }
    .stat .k{color:var(--muted); font-size:12px;}
    .stat .v{font-size:22px; font-weight:800; margin-top:6px; color: var(--accent);}
    .stat .s{color:var(--muted); font-size:12px; margin-top:6px;}
    @media (max-width: 900px){ .stat{grid-column: span 6;} }
    @media (max-width: 560px){ .stat{grid-column: span 12;} }

    .sec{margin-top:14px;}
    .secHead{
      display:flex; align-items:flex-end; justify-content:space-between; gap:10px;
      margin: 6px 2px 10px;
    }
    .secHead h2{margin:0; font-size:16px;}
    .secHead .hint{color:var(--muted); font-size:12px;}
    .inner{padding:14px;}

    .bars .row{display:flex; gap:10px; align-items:center; margin:8px 0;}
    .bars .lab{width:82px; color:var(--muted); font-size:12px;}
    .bars .bar{
      flex:1; height:12px; border-radius:999px;
      background:#eef2f7; border:1px solid var(--line); overflow:hidden;
    }
    .bars .bar > i{display:block; height:100%; width:0%; background: var(--accent);}
    .bars .val{width:52px; text-align:right; font-variant-numeric: tabular-nums; color: var(--muted); font-size:12px;}

    table{width:100%; border-collapse:collapse;}
    th,td{padding:10px 12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top;}
    th{font-size:12px; color:var(--muted); font-weight:600; background: #fafafa;}
    .muted{color:var(--muted); font-size:12px;}
    .pill{
      display:inline-flex; align-items:center; gap:6px;
      padding:4px 10px; border-radius:999px; font-size:12px;
      border:1px solid var(--line); background:#fff;
      margin-left: 8px;
    }
    .pill.ok{border-color: rgba(16,185,129,.35); background: rgba(16,185,129,.08); color: #065f46;}
    .pill.wait{border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); color:#92400e;}
    details{border-top:1px solid var(--line);}
    summary{
      cursor:pointer;
      list-style:none;
      padding: 12px 14px;
      display:flex; gap:10px; align-items:flex-start; justify-content:space-between;
    }
    summary::-webkit-details-marker{display:none;}
    .qTitle{font-weight:800;}
    .qMeta{color:var(--muted); font-size:12px; margin-top:4px;}
    .qBody{padding: 0 14px 14px;}
    .qGrid{display:grid; grid-template-columns: repeat(12, 1fr); gap: 12px; margin-top: 10px;}
    .qBox{grid-column: span 3; border:1px solid var(--line); border-radius: 14px; padding: 10px; background:#fff;}
    .qBox .k{color:var(--muted); font-size:12px;}
    .qBox .v{font-weight:800; margin-top:6px; color:var(--accent)}
    @media (max-width: 900px){ .qBox{grid-column: span 6;} }
    @media (max-width: 560px){ .qBox{grid-column: span 12;} }

    .choiceRow{display:flex; gap:10px; align-items:flex-start; margin:8px 0;}
    .choiceText{flex: 1; font-size:13px;}
    .choiceText .small{display:block; color:var(--muted); font-size:12px; margin-top:2px;}
    .choiceBar{width: 44%; min-width: 180px;}
    .choiceBar .bar{height:10px;}
    .choicePct{width:60px; text-align:right; font-variant-numeric: tabular-nums; color:var(--muted); font-size:12px;}
    .good{color:#065f46;}
  </style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div class="title">
      <h1>สถิติข้อสอบ</h1>
      <div class="sub">ชุดข้อสอบ: <?= h($examTitle) ?></div>

    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a class="btn" href="attempts_list.php?exam_id=<?= (int)$exam_id ?>">← กลับไปหน้ารายชื่อ</a>
      
    </div>
  </div>

  <div class="grid">
    <div class="stat">
      <div class="k">Responses</div>
      <div class="v"><?= (int)$stats['submitted_total'] ?></div>
      <div class="s">จากทั้งหมด <?= (int)$stats['attempt_total'] ?> attempt</div>
    </div>
    <div class="stat">
      <div class="k">จำนวนผู้เข้าสอบ</div>
      <div class="v"><?= (int)$stats['distinct_users_submitted'] ?></div>
      <div class="s">คน</div>
    </div>

    <div class="stat">
      <div class="k">Average score</div>
      <div class="v"><?= $stats['avg_score'] !== null ? number_format((float)$stats['avg_score'], 2) : "-" ?></div>
      <div class="s">คะแนน</div>
    </div>
    <div class="stat">
      <div class="k">Median %</div>
      <div class="v"><?= $medianPct !== null ? pct($medianPct, 1) : "-" ?></div>
      <div class="s">ค่ากลางของเปอร์เซ็นต์คะแนน</div>
    </div>


    <div class="stat">
      <div class="k">Min / Max score</div>
      <div class="v">
        <?= $stats['min_score'] !== null ? number_format((float)$stats['min_score'], 2) : "-" ?>
        /
        <?= $stats['max_score'] !== null ? number_format((float)$stats['max_score'], 2) : "-" ?>
      </div>
      <div class="s">คะแนนต่ำเเละสูงสุด</div>
    </div>

    <div class="stat">
      <div class="k">Questions</div>
      <div class="v"><?= count($questions) ?></div>
      <div class="s">คำถาม</div>
    </div>
  </div>

  <div class="sec">
    <div class="secHead">
      <h2>การกระจายคะแนน (Score distribution)</h2>
      <div class="hint">10 ช่วง (0–9% … 90–100%)</div>
    </div>
    <div class="card">
      <div class="inner bars">
        <?php for ($i=0; $i<10; $i++):
          $from = $i*10;
          $to = ($i===9) ? 100 : ($i*10+9);
          $c = $hist[$i];
          $w = ($c / $histMax) * 100;
        ?>
        <div class="row">
          <div class="lab"><?= $from ?>–<?= $to ?>%</div>
          <div class="bar"><i style="width: <?= number_format($w, 2) ?>%"></i></div>
          <div class="val"><?= (int)$c ?></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  


  <div class="sec">
    <div class="secHead">
      <h2>วิเคราะห์รายข้อ (Questions)</h2>
      <button class="btn" id="toggleAllBtn" onclick="toggleAllDetails()">▶ เปิดทั้งหมด</button>
    </div>
    <div class="card">
      <?php if (count($questions) === 0): ?>
        <div class="inner muted">ไม่พบคำถามในชุดข้อสอบนี้</div>
      <?php else: ?>
        <?php foreach ($questions as $idx => $q):
          $qid = (int)$q['question_id'];
          $qt  = trim((string)($q['question_text'] ?? ''));
          $type= trim((string)($q['q_type'] ?? ''));
          $sec = trim((string)($q['section_title'] ?? ''));
          $max = $q['max_score'] !== null ? (float)$q['max_score'] : null;

          $a = $agg[$qid] ?? ['rows_total'=>0,'answered_count'=>0,'avg_score'=>null,'avg_len'=>null,'correct_count'=>null,'answered_for_correct'=>null];
          $answered = (int)$a['answered_count'];
          $rowsTotal= (int)$a['rows_total'];

          $correctRate = null;
          if ($a['correct_count'] !== null && $a['answered_for_correct'] !== null && (int)$a['answered_for_correct'] > 0) {
            $correctRate = ((int)$a['correct_count'] / (int)$a['answered_for_correct']) * 100.0;
          }

          $isMCQ = ($ansChoiceCol && isset($choicesByQ[$qid]) && count($choicesByQ[$qid]) > 0);
          $totalAnsweredForDist = $answered > 0 ? $answered : 1;
        ?>
          <details>
            <summary>
              <div>
                <div class="qTitle">ข้อ <?= ($idx+1) ?>: <?= h(mb_strimwidth(stripMedia($qt), 0, 120, "…", "UTF-8")) ?></div>
                <div class="qMeta">
                  <?php if ($sec !== ""): ?>Section: <?= h($sec) ?> · <?php endif; ?>
                  <?php if ($type !== ""): ?>Type: <?= h($type) ?>  <?php endif; ?>
                  
                  <?php if ($max !== null): ?> · คะแนนเต็ม (ตาม questions): <?= number_format($max, 2) ?><?php endif; ?>
                </div>
              </div>
              <div>
                
              </div>
            </summary>

            <div class="qBody">
              <div class="qGrid">
                <div class="qBox">
                  <div class="k">Answered</div>
                  <div class="v"><?= $answered ?></div>
                </div>
                <div class="qBox">
                  <div class="k">Avg score (per question)</div>
                  <div class="v"><?= $a['avg_score'] !== null ? number_format((float)$a['avg_score'], 2) : "-" ?></div>
                </div>
                <div class="qBox">
                  <div class="k">Correct rate</div>
                  <div class="v"><?= $correctRate !== null ? pct($correctRate, 1) : "-" ?></div>
                </div>
              </div>

              <?php if ($isMCQ): ?>
                <div style="margin-top: 10px;">
                  <div class="muted" style="margin-bottom:8px;">การเลือกตัวเลือก (Choice distribution)</div>
                  <?php
                    $dist = $choiceDist[$qid] ?? [];
                    // find max for local scaling
                    $localMax = 1;
                    foreach ($choicesByQ[$qid] as $ch) {
                      $c = (int)($dist[$ch['choice_id']] ?? 0);
                      if ($c > $localMax) $localMax = $c;
                    }
                    foreach ($choicesByQ[$qid] as $ch):
                      $cid = (int)$ch['choice_id'];
                      $txt = (string)$ch['choice_text'];
                      $c = (int)($dist[$cid] ?? 0);
                      $p = ($answered > 0) ? ($c / $answered) * 100.0 : 0.0;
                      $w = ($c / $localMax) * 100.0;
                      $isCorrect = ($ch['is_correct'] !== null && (int)$ch['is_correct'] === 1);
                  ?>
                    <div class="choiceRow">
                      <div class="choiceText">
                        <b class="<?= $isCorrect ? 'good' : '' ?>"><?= h(stripMedia($txt) !== "" ? stripMedia($txt) : ("Choice #".$cid)) ?></b>
                        <span class="small">
                          <?= $isCorrect ? "✓ เฉลย " : "" ?>
                          <?= $c ?> คนเลือก
                        </span>
                      </div>
                      <div class="choiceBar bars">
                        <div class="bar"><i style="width: <?= number_format($w, 2) ?>%"></i></div>
                      </div>
                      <div class="choicePct"><?= pct($p, 1) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="muted" style="margin-top:10px;">
                  
                </div>
              <?php endif; ?>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function toggleAllDetails() {
  const details = document.querySelectorAll('.card details');
  const btn = document.getElementById('toggleAllBtn');
  const allOpen = [...details].every(d => d.open);

  details.forEach(d => d.open = !allOpen);
  btn.textContent = allOpen ? '▶ เปิดทั้งหมด' : '▼ ปิดทั้งหมด';
}
</script>
</body>
</html>