<?php
session_name('TEACHERSESS');
session_start();
require_once 'config.php'; // เรียกใช้ $pdo

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: teacher_login.php');
    exit;
}

// [AJAX] ส่วนจัดการบันทึก
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_db') {
    header('Content-Type: application/json');


    // Ensure clean JSON
    ini_set('display_errors', '0');
    error_reporting(0);
    if (ob_get_length()) {
        ob_clean();
    }
    try {
        $inputJSON = file_get_contents('php://input');
        $inputData = json_decode($inputJSON, true);

        if (!$inputData || !isset($inputData['exam_id'])) {
            throw new Exception('Invalid input data or missing exam_id');
        }

        $examId = $inputData['exam_id'];
        $title = $inputData['exam_title'] ?? '';
        $instructions = $inputData['instructions'] ?? '';
        $sectionsData = $inputData['sections'] ?? [];
        $userId = $_SESSION['user_id'];

        // เริ่ม Transaction
        $pdo->beginTransaction();

        // ตรวจสอบสิทธิ์
        $stmtCheck = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND created_by = ?");
        $stmtCheck->execute([$examId, $userId]);
        if (!$stmtCheck->fetch()) {
            throw new Exception('Unauthorized: You do not own this exam.');
        }

        $stmtUpdateExam = $pdo->prepare("UPDATE exams SET title = ?, instructions_content = ? WHERE id = ?");
        $stmtUpdateExam->execute([$title, $instructions, $examId]);

        // รวบรวม section_id เดิมจาก DB
        $stmtGetSec = $pdo->prepare("SELECT id FROM exam_sections WHERE exam_id = ?");
        $stmtGetSec->execute([$examId]);
        $dbSectionIds = $stmtGetSec->fetchAll(PDO::FETCH_COLUMN);
        $keptSectionIds = [];

        foreach ($sectionsData as $sec) {
            $secOrder = $sec['section_order'];
            $secTitle = $sec['section_title'];

            // ตรวจสอบว่า section นี้มีอยู่ใน DB แล้วหรือยัง (จับคู่ด้วย section_order)
            $stmtFindSec = $pdo->prepare("SELECT id FROM exam_sections WHERE exam_id = ? AND section_order = ?");
            $stmtFindSec->execute([$examId, $secOrder]);
            $existingSecId = $stmtFindSec->fetchColumn();

            if ($existingSecId) {
                // UPDATE section เดิม
                $pdo->prepare("UPDATE exam_sections SET section_title = ? WHERE id = ?")
                    ->execute([$secTitle, $existingSecId]);
                $newSecId = $existingSecId;
            } else {
                // INSERT section ใหม่
                $pdo->prepare("INSERT INTO exam_sections (exam_id, section_order, section_title) VALUES (?, ?, ?)")
                    ->execute([$examId, $secOrder, $secTitle]);
                $newSecId = $pdo->lastInsertId();
            }
            $keptSectionIds[] = $newSecId;

            // รวบรวม question_id ที่ยังอยู่ใน section นี้
            $keptQuestionIds = [];

            if (isset($sec['questions']) && is_array($sec['questions'])) {
                foreach ($sec['questions'] as $q) {
                    $mainAnswer = '';
                    if ($q['type'] === 'essay') {
                        $mainAnswer = $q['essay_answer'] ?? '';
                    } elseif ($q['type'] === 'short_answer' || $q['type'] === 'short_answer_number') {
                        $mainAnswer = $q['main_answer'] ?? '';
                    }
                    $score = isset($q['score']) ? (float)$q['score'] : 1;
                    if (($q['type'] ?? '') === 'instruction') $score = 0;

                    $incomingQId = isset($q['id']) ? (int)$q['id'] : 0;

                    if ($incomingQId > 0) {
                        // UPDATE question เดิม
                        $pdo->prepare("UPDATE questions SET section_id=?, number=?, type=?, question=?, note=?, answer=?, score=? WHERE id=? AND exam_id=?")
                            ->execute([
                                $newSecId,
                                $q['number'],
                                $q['type'],
                                $q['question'],
                                $q['description'] ?? '',
                                $mainAnswer,
                                $score,
                                $incomingQId,
                                $examId
                            ]);
                        $newQId = $incomingQId;
                    } else {
                        // INSERT question ใหม่
                        $pdo->prepare("INSERT INTO questions (exam_id, section_id, number, type, question, note, answer, score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([
                                $examId,
                                $newSecId,
                                $q['number'],
                                $q['type'],
                                $q['question'],
                                $q['description'] ?? '',
                                $mainAnswer,
                                $score
                            ]);
                        $newQId = $pdo->lastInsertId();
                    }
                    $keptQuestionIds[] = $newQId;

                    // ---- UPSERT Choices ----
                    if ($q['type'] === 'multiple_choice' && isset($q['choices'])) {
                        $keptChoiceIds = [];
                        foreach ($q['choices'] as $c) {
                            $isCorrect = ($c['correct'] == true || $c['correct'] == 1) ? 1 : 0;
                            $incomingCId = isset($c['id']) ? (int)$c['id'] : 0;
                            if ($incomingCId > 0) {
                                // UPDATE choice เดิม
                                $pdo->prepare("UPDATE choices SET choice_text=?, is_correct=? WHERE id=? AND question_id=?")
                                    ->execute([$c['text'], $isCorrect, $incomingCId, $newQId]);
                                $keptChoiceIds[] = $incomingCId;
                            } else {
                                // INSERT choice ใหม่
                                $pdo->prepare("INSERT INTO choices (question_id, choice_text, is_correct) VALUES (?, ?, ?)")
                                    ->execute([$newQId, $c['text'], $isCorrect]);
                                $keptChoiceIds[] = $pdo->lastInsertId();
                            }
                        }
                        // ลบ choice ที่ถูกเอาออกจาก UI
                        if (!empty($keptChoiceIds)) {
                            $inQ = implode(',', array_fill(0, count($keptChoiceIds), '?'));
                            $params = array_merge($keptChoiceIds, [$newQId]);
                            $pdo->prepare("DELETE FROM choices WHERE id NOT IN ($inQ) AND question_id = ?")
                                ->execute($params);
                        } else {
                            $pdo->prepare("DELETE FROM choices WHERE question_id = ?")->execute([$newQId]);
                        }
                    }

                    // ---- UPSERT Sub-questions ----
                        if (
                            ($q['type'] === 'short_answer' || $q['type'] === 'short_answer_number')
                            && isset($q['sub_questions'])
                        ) {
                        $keptSubIds = [];
                        foreach ($q['sub_questions'] as $sub) {
                            $incomingSId = isset($sub['id']) ? (int)$sub['id'] : 0;
                            if ($incomingSId > 0) {
                                // UPDATE sub_question เดิม
                                $pdo->prepare("UPDATE sub_questions SET sub_question=?, sub_answer=? WHERE id=? AND question_id=?")
                                    ->execute([$sub['question'], $sub['answer'], $incomingSId, $newQId]);
                                $keptSubIds[] = $incomingSId;
                            } else {
                                // INSERT sub_question ใหม่
                                $pdo->prepare("INSERT INTO sub_questions (question_id, sub_question, sub_answer) VALUES (?, ?, ?)")
                                    ->execute([$newQId, $sub['question'], $sub['answer']]);
                                $keptSubIds[] = $pdo->lastInsertId();
                            }
                        }
                        // ลบ sub_question ที่ถูกเอาออกจาก UI
                        if (!empty($keptSubIds)) {
                            $inQ = implode(',', array_fill(0, count($keptSubIds), '?'));
                            $params = array_merge($keptSubIds, [$newQId]);
                            $pdo->prepare("DELETE FROM sub_questions WHERE id NOT IN ($inQ) AND question_id = ?")
                                ->execute($params);
                        } else {
                            $pdo->prepare("DELETE FROM sub_questions WHERE question_id = ?")->execute([$newQId]);
                        }
                    }
                }
            }

            // ลบ question ที่ถูกลบออกจาก UI (ใน section นี้)
            if (!empty($keptQuestionIds)) {
                $inQ = implode(',', array_fill(0, count($keptQuestionIds), '?'));
                // ดึง question ที่จะถูกลบ เพื่อลบ choices/sub_questions ก่อน (ไม่มี CASCADE อัตโนมัติสำหรับ choices)
                $params = array_merge($keptQuestionIds, [$newSecId]);
                $stmtOldQ = $pdo->prepare("SELECT id FROM questions WHERE id NOT IN ($inQ) AND section_id = ?");
                $stmtOldQ->execute($params);
                $removedQIds = $stmtOldQ->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($removedQIds)) {
                    $inR = implode(',', array_fill(0, count($removedQIds), '?'));
                    $pdo->prepare("DELETE FROM choices WHERE question_id IN ($inR)")->execute($removedQIds);
                    $pdo->prepare("DELETE FROM questions WHERE id IN ($inR)")->execute($removedQIds);
                }
            } else {
                // ลบทุก question ใน section นี้ (ลบหมดเลย)
                $stmtOldQ = $pdo->prepare("SELECT id FROM questions WHERE section_id = ?");
                $stmtOldQ->execute([$newSecId]);
                $removedQIds = $stmtOldQ->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($removedQIds)) {
                    $inR = implode(',', array_fill(0, count($removedQIds), '?'));
                    $pdo->prepare("DELETE FROM choices WHERE question_id IN ($inR)")->execute($removedQIds);
                    $pdo->prepare("DELETE FROM questions WHERE section_id = ?")->execute([$newSecId]);
                }
            }
        }

        // ลบ section ที่ถูกลบออกจาก UI
        if (!empty($keptSectionIds)) {
            $inS = implode(',', array_fill(0, count($keptSectionIds), '?'));
            $params = array_merge($keptSectionIds, [$examId]);
            $stmtOldSec = $pdo->prepare("SELECT id FROM exam_sections WHERE id NOT IN ($inS) AND exam_id = ?");
            $stmtOldSec->execute($params);
            $removedSecIds = $stmtOldSec->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($removedSecIds)) {
                $inR = implode(',', array_fill(0, count($removedSecIds), '?'));
                // ดึง question ใน section ที่ถูกลบ
                $stmtOldQInSec = $pdo->prepare("SELECT id FROM questions WHERE section_id IN ($inR)");
                $stmtOldQInSec->execute($removedSecIds);
                $qIdsInRemovedSec = $stmtOldQInSec->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($qIdsInRemovedSec)) {
                    $inQ2 = implode(',', array_fill(0, count($qIdsInRemovedSec), '?'));
                    $pdo->prepare("DELETE FROM choices WHERE question_id IN ($inQ2)")->execute($qIdsInRemovedSec);
                    $pdo->prepare("DELETE FROM questions WHERE id IN ($inQ2)")->execute($qIdsInRemovedSec);
                }
                $pdo->prepare("DELETE FROM exam_sections WHERE id IN ($inR)")->execute($removedSecIds);
            }
        }

        // Commit
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'บันทึกข้อมูลเรียบร้อยแล้ว']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // ส่ง Error message ชัดเจนกลับไป
        echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}

// [AJAX] ส่วนสุ่มรหัสเข้าสอบ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'publish_exam') {
    header('Content-Type: application/json');
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);

    if (!isset($inputData['exam_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing exam_id']);
        exit;
    }

    $examId = $inputData['exam_id'];
    $userId = $_SESSION['user_id'];

    // ฟังก์ชันสุ่มรหัส 8 หลัก (ตัวใหญ่ + ตัวเลข)
    function generateRandomString($length = 8)
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    try {
        // ตรวจสอบความเป็นเจ้าของ
        $stmtCheck = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND created_by = ?");
        $stmtCheck->execute([$examId, $userId]);
        if (!$stmtCheck->fetch()) {
            throw new Exception('Unauthorized');
        }

        // สุ่มรหัสใหม่
        $newCode = generateRandomString(8);

        // อัปเดตลง Database
        $stmtUpdate = $pdo->prepare("UPDATE exams SET access_code = ? WHERE id = ?");
        $stmtUpdate->execute([$newCode, $examId]);

        echo json_encode([
            'status' => 'success',
            'access_code' => $newCode,
            'message' => 'สร้างรหัสเข้าสอบเรียบร้อยแล้ว'
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// [AJAX] ส่วนปิดข้อสอบ (ล้างรหัสเข้าสอบ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'close_exam') {
    header('Content-Type: application/json');
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);

    $examId = $inputData['exam_id'];
    $userId = $_SESSION['user_id'];

    try {
        $stmtCheck = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND created_by = ?");
        $stmtCheck->execute([$examId, $userId]);
        if (!$stmtCheck->fetch()) {
            throw new Exception('Unauthorized');
        }

        // อัปเดต access_code ให้เป็น NULL
        $stmtUpdate = $pdo->prepare("UPDATE exams SET access_code = NULL WHERE id = ?");
        $stmtUpdate->execute([$examId]);

        echo json_encode(['status' => 'success', 'message' => 'ปิดการเข้าถึงข้อสอบเรียบร้อยแล้ว']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// [Cleanup]
if (isset($_GET['action']) && $_GET['action'] === 'cleanup') {
    unset($_SESSION['last_json_path']);
    unset($_SESSION['last_md_path']);
    unset($_SESSION['last_pdf_path']);
    header("Location: teacher_home.php");
    exit;
}

// ส่วนดึงข้อมูลจาก Database มาแสดง (Fetch Data)
$exam_id = $_GET['exam_id'] ?? 0;
$user_id = $_SESSION['user_id'];

// ดึงข้อมูลข้อสอบ (Exam Info)
$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND created_by = ?");
$stmt->execute([$exam_id, $user_id]);
$examData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$examData) {
    die("ไม่พบข้อสอบ หรือคุณไม่มีสิทธิ์เข้าถึงข้อสอบนี้ <a href='teacher_home.php'>กลับหน้าหลัก</a>");
}

$examTitle = $examData['title'];
$currentAccessCode = $examData['access_code'] ?? '-';
$instructions = $examData['instructions_content'] ?? "";

// ดึงข้อมูล Sections และ Questions แบบครบชุด
$sections = [];

// ดึง Section ทั้งหมดของข้อสอบนี้
$stmtSec = $pdo->prepare("SELECT * FROM exam_sections WHERE exam_id = ? ORDER BY section_order ASC");
$stmtSec->execute([$exam_id]);
$dbSections = $stmtSec->fetchAll(PDO::FETCH_ASSOC);

foreach ($dbSections as $secRow) {
    $secId = $secRow['id'];
    $questionsList = [];

    // ดึงคำถาม (Questions) ใน Section นี้
    $stmtQ = $pdo->prepare("SELECT * FROM questions WHERE section_id = ? ORDER BY number ASC");
    $stmtQ->execute([$secId]);
    $dbQuestions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dbQuestions as $qRow) {
        $qId = $qRow['id'];
        $qType = $qRow['type'];

        // เตรียม Object คำถาม
        $qObj = [
            "id" => $qRow['id'],        // <<< เพิ่ม id เพื่อใช้ UPSERT
            "number" => $qRow['number'],
            "question" => $qRow['question'],
            "description" => $qRow['note'],
            "type" => $qType,
            "answer" => $qRow['answer'],
            "essay_answer" => $qRow['answer'],
            "score" => $qRow['score'],
            "choices" => [],
            "sub_questions" => []
        ];

        // ดึงตัวเลือก (Choices) หรือ ข้อย่อย (Sub-questions) ตามประเภท
        if ($qType === 'multiple_choice') {
            $stmtC = $pdo->prepare("SELECT * FROM choices WHERE question_id = ? ORDER BY id ASC");
            $stmtC->execute([$qId]);
            $dbChoices = $stmtC->fetchAll(PDO::FETCH_ASSOC);

            foreach ($dbChoices as $c) {
                $qObj['choices'][] = [
                    "id" => $c['id'],   // <<< เพิ่ม id
                    "text" => $c['choice_text'],
                    "correct" => ($c['is_correct'] == 1)
                ];
            }
        } elseif ($qType === 'short_answer' || $qType === 'short_answer_number') {
            $stmtSub = $pdo->prepare("SELECT * FROM sub_questions WHERE question_id = ? ORDER BY id ASC");
            $stmtSub->execute([$qId]);
            $dbSub = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

            foreach ($dbSub as $sub) {
                $qObj['sub_questions'][] = [
                    "id" => $sub['id'],  // <<< เพิ่ม id
                    "question" => $sub['sub_question'],
                    "answer" => $sub['sub_answer']
                ];
            }
        }

        $questionsList[] = $qObj;
    }

    $sections[] = [
        "section_order" => $secRow['section_order'],
        "section_title" => $secRow['section_title'],
        "questions" => $questionsList
    ];
}

// นับจำนวนข้อรวม
$totalQ = 0;
foreach ($sections as $s) {
    $qs = $s['questions'] ?? [];
    if (is_array($qs))
        $totalQ += count($qs);
}

// Helper Functions สำหรับ PHP 
function normalize_choices($choices)
{
    return $choices;
}
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แก้ไข: <?= htmlspecialchars($examTitle) ?></title>

    <link rel="stylesheet" href="style.css">

    <style>
        /* CSS: ตำแหน่ง popup */
        .media-menu {
            position: absolute;
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

        /* สไตล์สำหรับ Tables */
        .editable-content table {
            border-collapse: collapse;
            width: 100%;
            margin: 15px 0;
            border: 1px solid #333 !important;
        }

        .editable-content table td,
        .editable-content table th {
            border: 1px solid #333 !important;
            padding: 12px;
            min-width: 50px;
            text-align: left;
        }

        /* สไตล์สำหรับ Code Blocks (PRE) */
        .editable-content pre {
            display: block;
            background-color: #f8f9fa;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            white-space: pre-wrap;
            font-family: 'Consolas', 'Monaco', monospace;
            line-height: 1.6;
            color: #24292e;
            position: relative;
        }

        .editable-content pre::before {
            content: "CODE";
            display: block;
            font-size: 10px;
            font-weight: bold;
            color: #9ca3af;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }

        /* ปรับปรุงช่องกรอกให้ดูเหมือน Textarea */
        .editable-content {
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 10px;
            background: #fff;
            min-height: 50px;
            outline: none;
        }

        .editable-content:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.1);
        }

        /* สไตล์สำหรับ Code Blocks (PRE) */
        .editable-content pre {
            display: block;
            background-color: #f8f9fa;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            white-space: pre-wrap;
            /* รักษาการเว้นวรรคและการขึ้นบรรทัดใหม่ */
            font-family: 'Consolas', 'Monaco', monospace;
            line-height: 1.6;
            color: #24292e;
            position: relative;
        }

        /* ส่วนหัวระบุว่าเป็นก้อน CODE */
        .editable-content pre::before {
            content: "CODE";
            display: block;
            font-size: 10px;
            font-weight: bold;
            color: #9ca3af;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
            pointer-events: none;
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div class="topbar-inner">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="page-title">แก้ไขข้อสอบ</div>
                <button type="button" id="deleteExamBtn" class="btn ghost"
                    style="color: #dc2626; border-color: #fecaca; background: #fef2f2; padding: 5px 12px; font-size: 13px;">
                    🗑️ ลบข้อสอบ
                </button>
            </div>

            <div class="top-actions">
                <div id="codeDisplayArea" onclick="copyAccessCode()" title="คลิกเพื่อคัดลอกรหัส"
                    style="display:<?= ($currentAccessCode === '-') ? 'none' : 'inline-flex' ?>; align-items:center; margin-right:15px; background:#e0f2fe; padding:4px 12px; border-radius:20px; color:#0284c7; font-weight:bold; font-size:14px; cursor:pointer;">
                    รหัสเข้าสอบ: <span id="accessCodeText" style="margin-left:5px; font-family:monospace; font-size:16px;"><?= htmlspecialchars($currentAccessCode) ?></span>
                </div>

                <a class="btn ghost" href="teacher_home.php">← กลับหน้าหลัก</a>

                <button class="btn" type="button" id="closeExamBtn"
                    style="background-color:#ef4444; color:white; border:none; margin-right:8px; display:<?= ($currentAccessCode === '-') ? 'none' : 'inline-block' ?>;">
                    ปิดข้อสอบ
                </button>

                <button class="btn" type="button" id="publishBtn"
                    style="background-color:#8b5cf6; color:white; border:none; margin-right:8px;">
                    <?= ($currentAccessCode === '-') ? 'เผยแพร่ข้อสอบ' : 'สุ่มรหัสใหม่' ?>
                </button>

                <button class="btn primary" type="button" id="saveBtn">บันทึกการแก้ไข</button>
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
                    $choices = $q['choices'] ?? [];

                    $hasDesc = !empty($qDesc);
                    $isInst = ($qType === 'instruction');
                    $mid = (int) $si . "_" . (int) $qi;

                    $subQuestions = $q['sub_questions'] ?? [];
                ?>
                    <div class="qCard <?= $isInst ? 'type-instruction' : '' ?>" data-qcard
                        data-qid="<?= (int)($q['id'] ?? 0) ?>"
                        data-qnumber="<?= htmlspecialchars((string) ($q['number'] ?? '')) ?>"
                        data-subjson='<?= htmlspecialchars(json_encode($subQuestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>

                        <div class="leftStripe"></div>

                        <div class="qFloatToolbar" data-qtoolbar>
                            <button type="button" class="qFloatBtn" title="เพิ่มรูปภาพ"
                                onclick="openMediaMenu(event, '<?= $mid ?>','image')"><svg viewBox="0 0 24 24">
                                    <path
                                        d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                                </svg></button>
                            <button type="button" class="qFloatBtn" title="เพิ่มวิดีโอ"
                                onclick="openMediaMenu(event, '<?= $mid ?>','video')"><svg viewBox="0 0 24 24">
                                    <path
                                        d="M10 8v8l6-4-6-4zm9-5H5c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12H5V5h14v10z" />
                                </svg></button>
                            <button type="button" class="qFloatBtn" title="เพิ่มเสียง"
                                onclick="openMediaMenu(event, '<?= $mid ?>','audio')"><svg viewBox="0 0 24 24">
                                    <path
                                        d="M12 3v9.28c-.47-.17-.97-.28-1.5-.28C8.01 12 6 14.01 6 16.5S8.01 21 10.5 21c2.31 0 4.16-1.75 4.45-4H15V6h4V3h-7z" />
                                </svg></button>
                            <div class="qFloatDivider"></div>
                            <button type="button" class="qFloatBtn" title="เพิ่มคำถาม (+)"
                                onclick="onToolbarAdd(this, 'question')"><svg viewBox="0 0 24 24">
                                    <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                                </svg></button>
                            <button type="button" class="qFloatBtn" title="เพิ่มชื่อเรื่อง/รายละเอียด (Tt)"
                                onclick="onToolbarAdd(this, 'title')"><svg viewBox="0 0 24 24">
                                    <path d="M2.5 4v3h5v12h3V7h5V4h-13zm19 5h-9v3h3v7h3v-7h3V9z" />
                                </svg></button>
                            <button type="button" class="qFloatBtn" title="เพิ่มส่วนใหม่ (Section)"
                                onclick="onToolbarAdd(this, 'section')"><svg viewBox="0 0 24 24">
                                    <path d="M19 13H5v-2h14v2zM5 15h14v2H5v-2z" />
                                </svg></button>
                        </div>

                        <input type="file" id="media-file-<?= $mid ?>" style="display:none" accept="image/*,video/*,audio/*"
                            onchange="onMediaPicked(this,'<?= $mid ?>')">

                        <div class="qBody">
                            <div class="qGrid">
                                <div style="width:100%">
                                    <div class="qText editable-content" data-qtext contenteditable="true"
                                        placeholder="<?= $isInst ? 'พิมพ์ข้อความ/คำชี้แจง...' : 'พิมพ์คำถาม...' ?>">
                                        <?= $qText ?> </div>

                                    <div class="qDesc editable-content" data-qdesc contenteditable="true"
                                        placeholder="คำอธิบาย (ระบุหรือไม่ก็ได้)"
                                        style="margin-top:8px; <?= $hasDesc ? '' : 'display:none' ?>">
                                        <?= $qDesc ?>
                                    </div>
                                </div>

                                <select class="qType" data-qtype>
                                    <option value="multiple_choice" <?= $qType === 'multiple_choice' ? 'selected' : '' ?>>ตัวเลือก
                                    </option>
                                    <option value="short_answer" <?= $qType === 'short_answer' ? 'selected' : '' ?>>คำตอบสั้น
                                    </option>
                                    <option value="short_answer_number" <?= $qType === 'short_answer_number' ? 'selected' : '' ?>>คำตอบสั้น (ตัวเลข)
                                    </option>
                                    <option value="essay" <?= $qType === 'essay' ? 'selected' : '' ?>>คำตอบยาว</option>
                                    <option value="instruction" <?= $qType === 'instruction' ? 'selected' : '' ?>>ข้อความ/คำชี้แจง
                                    </option>
                                </select>
                            </div>

                            <div class="opts" data-opts style="<?= $qType === 'multiple_choice' ? '' : 'display:none' ?>">
                                <?php
                                if (count($choices) === 0 && !$isInst) {
                                    $choices = [["text" => "ตัวเลือก 1", "correct" => false], ["text" => "ตัวเลือก 2", "correct" => false]];
                                }
                                foreach ($choices as $ci => $cObj):
                                    $ct = $cObj['text'];
                                    $isCorrect = $cObj['correct'];
                                    $cId = (int)($cObj['id'] ?? 0);
                                ?>
                                    <div class="optRow" data-optrow data-cid="<?= $cId ?>">
                                        <div class="optBullet <?= $isCorrect ? 'correct' : '' ?>" title="คลิกเพื่อตั้งเป็นเฉลย">
                                        </div>
                                        <input class="optInput" data-optinput value="<?= htmlspecialchars($ct) ?>" />
                                        <button type="button" class="trash" data-delopt title="ลบตัวเลือก">🗑️</button>
                                    </div>
                                <?php endforeach; ?>
                                <button type="button" class="btn" style="margin-top:8px" data-addopt>+ เพิ่มตัวเลือก</button>
                            </div>

                            <div class="answerBox" data-answer
                                style="<?= ($qType !== 'multiple_choice' && $qType !== 'instruction') ? '' : 'display:none' ?>">

                                <div class="subWrap" data-subwrap
                                    style="<?= ($qType === 'short_answer' || $qType === 'short_answer_number') ? '' : 'display:none' ?>">
                                    <div style="margin-bottom:12px; border-bottom:1px solid #eee; padding-bottom:12px;">
                                        <div class="mutedSmall" style="margin-bottom:6px;">เฉลยคำตอบ (กรณีไม่มีข้อย่อย
                                            หรือเป็นคำตอบรวม):</div>
                                        <input class="optInput" data-main-answer
                                            type="<?= $qType === 'short_answer_number' ? 'number' : 'text' ?>"
                                            <?= $qType === 'short_answer_number' ? 'step="any"' : '' ?>
                                            placeholder="<?= $qType === 'short_answer_number' ? 'ระบุคำตอบ (ตัวเลข)...' : 'ระบุคำตอบที่ถูกต้อง...' ?>"
                                            value="<?= htmlspecialchars((string) ($q['answer'] ?? '')) ?>"
                                            style="width:100%;" />
                                    </div>

                                    <div class="mutedSmall" style="margin:0 0 8px;">โจทย์ย่อย (ถ้ามี):</div>
                                    <div data-sublist></div>
                                    <button type="button" class="btn" data-addsub style="margin-top:10px">+
                                        เพิ่มโจทย์ย่อย</button>
                                </div>

                                <div class="essayWrap" data-essaywrap style="<?= $qType === 'essay' ? '' : 'display:none' ?>">
                                    <div class="mutedSmall" style="margin:0 0 8px;">คำตอบยาว</div>
                                    <textarea class="auto-textarea" data-essayanswer
                                        placeholder="คำตอบ"
                                        style="min-height:90px"><?= htmlspecialchars((string) ($q['essay_answer'] ?? '')) ?></textarea>
                                </div>
                            </div>

                            <div class="cardFooter">
                                <div style="flex:1"></div>
                                <div class="scoreWrap">
                                    <div class="mutedSmall" style="margin-bottom:6px;">คะแนน</div>
                                    <input type="number" class="scoreInput" data-score min="0" step="0.5"
                                        value="<?= htmlspecialchars($q['score'] ?? 1) ?>" />
                                </div>
                                <div style="position:relative">
                                    <button type="button" class="iconBtn" onclick="toggleMoreMenu(event, this)">⋮</button>
                                    <div class="more-menu">
                                        <button type="button" class="<?= $hasDesc ? 'checked' : '' ?>"
                                            onclick="toggleDescription(this)">
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
                // Reset main-answer input to text mode
                const mainAns = card.querySelector('[data-main-answer]');
                if (mainAns) { mainAns.type = 'text'; mainAns.removeAttribute('step'); mainAns.placeholder = 'ระบุคำตอบที่ถูกต้อง...'; }
                ensureAtLeastOneSub(card);
            } else if (type === 'short_answer_number') {
                if (subWrap) subWrap.style.display = '';
                if (essayWrap) essayWrap.style.display = 'none';
                // Switch main-answer input to number mode
                const mainAns = card.querySelector('[data-main-answer]');
                if (mainAns) { mainAns.type = 'number'; mainAns.step = 'any'; mainAns.placeholder = 'ระบุคำตอบ (ตัวเลข)...'; }
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

        function addSubQuestion(card, qText = '', aText = '', sid = 0) {
            const list = card.querySelector('[data-sublist]');
            if (!list) return;
            const isNumber = card.querySelector('[data-qtype]')?.value === 'short_answer_number';
            const row = document.createElement('div');
            row.className = 'optRow';
            row.setAttribute('data-subrow', '');
            row.setAttribute('data-sid', sid);
            row.innerHTML = `
      <div style="width:18px;height:18px;border-radius:6px;border:2px solid var(--line);flex:0 0 auto"></div>
      <div class="optInput editable-content" data-subq contenteditable="true" 
           placeholder="โจทย์ย่อย (ถ้ามี)" style="flex:1; min-height:30px;">
           ${qText}
      </div>
      <input class="optInput" data-suba type="${isNumber ? 'number' : 'text'}" ${isNumber ? 'step="any"' : ''}
             placeholder="${isNumber ? 'คำตอบ (ตัวเลข)' : 'คำตอบ'}" value="${escapeHtml(aText)}" style="max-width:220px" />
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
              <div class="qText editable-content" data-qtext contenteditable="true" 
                   placeholder="${ph}"></div>
              <div class="qDesc editable-content" data-qdesc contenteditable="true" 
                   placeholder="คำอธิบาย (ระบุหรือไม่ก็ได้)" style="display:none; margin-top:8px;"></div>
          </div>
          <select class="qType" data-qtype>
            <option value="multiple_choice" ${defaultType === 'multiple_choice' ? 'selected' : ''}>ตัวเลือก</option>
            <option value="short_answer" ${defaultType === 'short_answer' ? 'selected' : ''}>คำตอบสั้น</option>
            <option value="short_answer_number" ${defaultType === 'short_answer_number' ? 'selected' : ''}>คำตอบสั้น (ตัวเลข)</option>
            <option value="essay" ${defaultType === 'essay' ? 'selected' : ''}>คำตอบยาว</option>
            <option value="instruction" ${defaultType === 'instruction' ? 'selected' : ''}>ข้อความ/คำชี้แจง</option>
          </select>
        </div>
        <div class="opts" data-opts style="${defaultType !== 'multiple_choice' ? 'display:none' : ''}">
          <button type="button" class="btn" style="margin-top:8px" data-addopt>+ เพิ่มตัวเลือก</button>
        </div>
        <div class="answerBox" data-answer style="${(defaultType === 'multiple_choice' || defaultType === 'instruction') ? 'display:none' : ''}">
          <div class="subWrap" data-subwrap style="${(defaultType === 'short_answer' || defaultType === 'short_answer_number') ? '' : 'display:none'}">
            <div style="margin-bottom:12px; border-bottom:1px solid #eee; padding-bottom:12px;">
                <div class="mutedSmall" style="margin-bottom:6px;">เฉลยคำตอบ (กรณีไม่มีข้อย่อย หรือเป็นคำตอบรวม):</div>
                <input class="optInput" data-main-answer
                  type="${defaultType === 'short_answer_number' ? 'number' : 'text'}"
                  ${defaultType === 'short_answer_number' ? 'step="any"' : ''}
                  placeholder="${defaultType === 'short_answer_number' ? 'ระบุคำตอบ (ตัวเลข)...' : 'ระบุคำตอบที่ถูกต้อง...'}"
                  style="width:100%;" />
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
    const { mid, type } = activeMediaContext;

    if (action === 'upload') {
        // สร้าง input ใหม่ทุกครั้ง แทนการ reuse ของเดิม
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = type + '/*';
        input.style.display = 'none';
        document.body.appendChild(input);

        input.addEventListener('change', function() {
            onMediaPicked(input, mid);
            document.body.removeChild(input); // ลบทิ้งหลังใช้งาน
        });

        input.click();

    }
}

        function onMediaPicked(input, mid) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const formData = new FormData();
                formData.append('file', file);

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
                                // สร้าง Tag Video พร้อมปุ่มควบคุม
                                mediaHtml = `
                        <video controls style="max-width:100%; display:block; margin:10px 0;">
                            <source src="${data.filePath}" type="${fType}">
                            เบราว์เซอร์ของคุณไม่รองรับการเล่นวิดีโอ
                        </video>`;
                            } else if (fType.startsWith('audio/')) {
                                // สร้าง Tag Audio
                                mediaHtml = `
                        <div style="margin:10px 0;">
                            <audio controls style="width:100%;">
                                <source src="${data.filePath}" type="${fType}">
                                เบราว์เซอร์ของคุณไม่รองรับการเล่นเสียง
                            </audio>
                        </div>`;
                            }

                            const qText = document.querySelector(`[onclick*="'${mid}'"]`).closest('.qCard').querySelector('.qText');
                            qText.innerHTML += mediaHtml;
                        } else {
                            alert("Upload failed: " + data.message);
                        }
                    })
            }
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', () => {
            initAutoResize(document);

            // จัดการแสดงข้อย่อย (Sub-questions) ที่ดึงมาจาก DB
            document.querySelectorAll('[data-qcard]').forEach(card => {
                const jsonStr = card.dataset.subjson;
                const type = card.querySelector('[data-qtype]').value;
                applyTypeUI(card, type);
                if ((type === 'short_answer' || type === 'short_answer_number') && jsonStr) {
                    try {
                        const arr = JSON.parse(jsonStr);
                        if (Array.isArray(arr) && arr.length > 0) {
                            arr.forEach(item => addSubQuestion(card, item.question, item.answer, item.id || 0));
                        }
                    } catch (e) {}
                }
            });

            // ปุ่มบันทึก (Logic UPDATE)
            document.getElementById('saveBtn').addEventListener('click', function() {
                const thisBtn = this;
                // แจ้งเตือน Update
                if (!confirm('ต้องการบันทึกการแก้ไขใช่หรือไม่? (ข้อมูลเดิมจะถูกอัปเดต)')) return;

                thisBtn.disabled = true;
                thisBtn.textContent = 'กำลังบันทึก...';

                // [แก้ไข] ดึงค่า exam_id จาก PHP
                const currentExamId = <?= json_encode($exam_id) ?>;

                const payload = {
                    exam_id: currentExamId, // ส่ง ID กลับไปบอก
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
                            if (qTextEl) {
                                let content = qTextEl.innerHTML;

                                // ถ้าข้อมูลมีสัญลักษณ์ ``` แต่ยังไม่มี <pre> ให้แปลงเป็น <pre> (กรณีข้อมูลดิบหลุดมา)
                                if (content.includes('```') && !content.includes('<pre>')) {
                                    content = content.replace(/```(?:[a-zA-Z]+)?\s*([\s\S]*?)\s*```/g, '<pre>$1</pre>');
                                    qTextEl.innerHTML = content;
                                }
                            }
                            const qText = qTextEl.innerHTML.trim();
                            const qDescEl = card.querySelector('[data-qdesc]');
                            const qDesc = qDescEl.innerHTML.trim();
                            const qType = card.querySelector('[data-qtype]').value;
                            const rawNo = (card.dataset.qnumber || '').trim();
                            const parsedNo = parseInt(rawNo, 10);
                            const qNo = (!Number.isNaN(parsedNo) && parsedNo > 0) ? parsedNo : (qIdx + 1);

                            const scoreRaw = card.querySelector('[data-score]')?.value ?? '1';
                            const scoreVal = parseFloat(scoreRaw);
                            const qScore = Number.isFinite(scoreVal) ? scoreVal : 1;

                            const qObj = {
                                id: parseInt(card.dataset.qid || '0', 10), // <<< ส่ง id กลับไป
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
                                            id: parseInt(row.dataset.cid || '0', 10), // <<< ส่ง choice id กลับไป
                                            text: inp.value.trim(),
                                            correct: bullet.classList.contains('correct')
                                        });
                                    }
                                });
                            } else if (qType === 'short_answer' || qType === 'short_answer_number') {
                                qObj.main_answer = card.querySelector('[data-main-answer]')?.value?.trim() ?? '';
                                qObj.sub_questions = [];
                                card.querySelectorAll('[data-subrow]').forEach((row) => {
                                    const sqEl = row.querySelector('[data-subq]');
                                    // ดึงค่า HTML จากโจทย์ย่อย
                                    const sq = sqEl.innerHTML.trim();
                                    const sa = row.querySelector('[data-suba]')?.value?.trim() ?? '';
                                    if (sq || sa) qObj.sub_questions.push({
                                        id: parseInt(row.dataset.sid || '0', 10), // <<< ส่ง sub_question id กลับไป
                                        question: sq,
                                        answer: sa
                                    });
                                });
                            } else if (qType === 'essay') {
                                qObj.essay_answer = card.querySelector('[data-essayanswer]')?.value?.trim() ?? '';
                            }
                            secObj.questions.push(qObj);
                        });
                        payload.sections.push(secObj);
                    }
                }

                fetch('?action=save_db&exam_id=' + currentExamId, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            alert('อัปเดตข้อมูลสำเร็จเรียบร้อย!');
                            window.location.reload();
                        } else {
                            throw new Error(data.message || 'Unknown error occurred');
                        }
                    })
                    .catch(error => {
                        console.error('Save Error:', error);
                        alert('เกิดข้อผิดพลาดในการบันทึก:\n' + error.message);
                        thisBtn.disabled = false;
                        thisBtn.textContent = 'บันทึกการแก้ไข';
                    });
            });
        });
        // ฟังก์ชันลบข้อสอบ (ยิงไปไฟล์แยก delete_exam.php)
        document.getElementById('deleteExamBtn').addEventListener('click', function() {
            if (!confirm('⚠️ คำเตือน: คุณต้องการลบข้อสอบชุดนี้อย่างถาวรใช่หรือไม่?\n\nการกระทำนี้ไม่สามารถย้อนกลับได้')) {
                return;
            }

            const btn = this;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = 'กำลังลบ...';

            // ดึงค่า ID จาก PHP ด้านบน
            const currentExamId = <?= json_encode($exam_id) ?>;

            fetch('delete_exam.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        exam_id: currentExamId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('ลบข้อสอบเรียบร้อยแล้ว');
                        window.location.href = 'teacher_home.php'; // กลับหน้าหลัก
                    } else {
                        throw new Error(data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('เกิดข้อผิดพลาด: ' + err.message);
                    btn.disabled = false;
                    btn.innerHTML = originalText;
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

        document.getElementById('publishBtn').addEventListener('click', function() {
            const btn = this;
            const originalText = btn.innerText;
            btn.disabled = true;
            btn.innerText = 'กำลังประมวลผล...';

            const currentExamId = <?= json_encode($exam_id) ?>;

            fetch('?action=publish_exam', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        exam_id: currentExamId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        //  อัปเดตข้อความรหัส
                        document.getElementById('accessCodeText').innerText = data.access_code;
                        document.getElementById('codeDisplayArea').style.display = 'inline-flex';
                        btn.innerText = 'สุ่มรหัสใหม่';
                        //  สั่งให้ปุ่มปิดข้อสอบกลับมาแสดงผล (inline-block)
                        const closeBtn = document.getElementById('closeExamBtn');
                        if (closeBtn) {
                            closeBtn.style.display = 'inline-block';
                        }

                        alert('สร้างรหัสสำเร็จ: ' + data.access_code);
                    } else {
                        alert('Error: ' + data.message);
                        btn.innerText = originalText;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                    btn.innerText = originalText;
                })
                .finally(() => {
                    btn.disabled = false;
                });
        });
        document.getElementById('closeExamBtn').addEventListener('click', function() {
            if (!confirm('คุณต้องการปิดการเข้าถึงข้อสอบชุดนี้ใช่หรือไม่? \n(นักเรียนจะไม่สามารถเข้าสอบด้วยรหัสเดิมได้)')) return;

            const btn = this;
            const currentExamId = <?= json_encode($exam_id) ?>;

            fetch('?action=close_exam', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        exam_id: currentExamId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        // อัปเดต UI
                        document.getElementById('accessCodeText').innerText = '-';
                        document.getElementById('codeDisplayArea').style.display = 'none';
                        btn.style.display = 'none'; // ซ่อนปุ่มปิดข้อสอบ
                        document.getElementById('publishBtn').innerText = 'เผยแพร่ข้อสอบ';
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                });
        });

        function copyAccessCode() {
            // ดึงข้อความรหัสจาก span
            const codeText = document.getElementById('accessCodeText').innerText;
            // ตรวจสอบว่ามีรหัสหรือไม่
            if (codeText === '-') return;
            // ใช้ Clipboard API เพื่อคัดลอกข้อความ
            navigator.clipboard.writeText(codeText).then(() => {
                const area = document.getElementById('codeDisplayArea');
                const originalBg = area.style.background;
                area.style.background = '#bbf7d0';
                console.log('Copied: ' + codeText);

                setTimeout(() => {
                    area.style.background = originalBg;
                }, 500);
            }).catch(err => {
                console.error('ไม่สามารถคัดลอกรหัสได้: ', err);
            });
        }
    </script>

</body>

</html>