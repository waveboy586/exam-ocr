<?php
/**
 * auto_grade_worker.php — Batch Multi-Job (Windows Apache compatible)
 * ─────────────────────────────────────────────────────────────────────────────
 * เมื่อ worker เริ่มทำงาน จะ claim ทุก job ที่ยังเป็น 'queued' พร้อมกันทันที
 * แล้วรวมทุก item จากทุก job ส่ง Python ใน call เดียว
 * Python โหลด model ครั้งเดียว → ตรวจทุก job ทีเดียว → จบ
 *
 * ✅ ไม่ต้องโหลด Python model ซ้ำระหว่าง job เลย
 * ✅ ไม่ต้องมี daemon หรือ background process
 * ✅ ทำงานได้บน Windows Apache / XAMPP ทุก config
 */

declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ══════════════════════════════════════════════════════════════
// CONFIG
// ══════════════════════════════════════════════════════════════
require_once 'config.php';

// Python binary — priority: PYTHON_BIN env → Windows venv → python3 → python
$PYTHON_CLI = getenv('PYTHON_BIN') ?: '';
if ($PYTHON_CLI === '' || !@file_exists($PYTHON_CLI)) {
    $winVenv = 'C:\\xampp\\htdocs\\exam-ocr\\venv\\Scripts\\python.exe';
    if (@file_exists($winVenv)) {
        $PYTHON_CLI = $winVenv;
    } elseif (PHP_OS_FAMILY === 'Windows') {
        $PYTHON_CLI = 'python';
    } else {
        $PYTHON_CLI = '/usr/bin/python3';
    }
}

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════

function nowStr(): string { return date('Y-m-d H:i:s'); }

function clamp(float $v, float $lo, float $hi): float
{
    return max($lo, min($hi, $v));
}

function ensureJobsTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS auto_grade_jobs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id    INT NOT NULL,
        status        VARCHAR(16) NOT NULL DEFAULT 'queued',
        total_items   INT NOT NULL DEFAULT 0,
        done_items    INT NOT NULL DEFAULT 0,
        message       VARCHAR(255) NULL,
        last_error    MEDIUMTEXT NULL,
        force_regrade TINYINT(1) NOT NULL DEFAULT 0,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at    DATETIME NULL,
        finished_at   DATETIME NULL,
        INDEX (attempt_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function jobUpdate(mysqli $conn, int $jobId, array $fields): void
{
    if (!$fields) return;
    $sets = $types = '';
    $params = [];
    foreach ($fields as $k => $v) {
        $sets .= ($sets ? ',' : '') . "`{$k}` = ?";
        $types .= is_int($v) ? 'i' : 's';
        $params[] = $v;
    }
    $types   .= 'i';
    $params[] = $jobId;
    $st = $conn->prepare("UPDATE auto_grade_jobs SET {$sets} WHERE id = ?");
    $st->bind_param($types, ...$params);
    $st->execute();
}

function stripAutoLines(string $fb): string
{
    return trim(implode("\n", array_filter(
        preg_split('/\R/u', $fb),
        fn($l) => !preg_match('/^\s*AUTO_GRADE_V1\s*:/u', $l)
    )));
}

function buildFeedback(string $oldFb, int $force, array $meta): string
{
    $oldFb = trim($oldFb);
    if ($force === 1 && $oldFb !== '') $oldFb = stripAutoLines($oldFb);
    $tag = 'AUTO_GRADE_V1: ' . json_encode($meta, JSON_UNESCAPED_UNICODE);
    return $oldFb !== '' ? "{$oldFb}\n{$tag}" : $tag;
}

function calcScore(float $sim, float $maxScore): float
{
    // Threshold rule (ใช้กับทุก q_type เหมือนกัน):
    //   sim < 0.50  → 0 คะแนน   (ตอบผิด / ไม่เกี่ยวข้อง)
    //   sim >= 0.90 → เต็ม      (ตอบถูกต้องมาก)
    //   0.50–0.90   → proportional linear
    // หมายเหตุ: PHP คำนวณ score ใหม่จาก similarity เสมอ
    // (score ที่ Python คืนมาถูก override ที่นี่)
    if ($sim >= 0.9) return $maxScore;
    if ($sim <  0.5) return 0.0;
    $x = ($sim - 0.5) / (0.9 - 0.5);
    return round(clamp($maxScore * $x, 0.0, $maxScore), 2);
}

function phpSimilarity(string $a, string $b): float
{
    $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $a)), 'UTF-8');
    $b = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $b)), 'UTF-8');
    if ($a === '' && $b === '') return 1.0;
    if ($a === '' || $b === '') return 0.0;
    if (mb_strlen($a,'UTF-8') <= 200 && mb_strlen($b,'UTF-8') <= 200
        && preg_match('/^[\x00-\x7F]*$/', $a.$b)) {
        $dist = levenshtein($a, $b);
        $max  = max(strlen($a), strlen($b));
        return $max > 0 ? max(0.0, 1.0 - $dist/$max) : 1.0;
    }
    $ta = array_unique(array_filter(explode(' ', $a)));
    $tb = array_unique(array_filter(explode(' ', $b)));
    if (!$ta || !$tb) return 0.0;
    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    return $union > 0 ? $inter/$union : 0.0;
}

function detectSubSchema(mysqli $conn): ?array
{
    try {
        $r = $conn->query("SHOW TABLES LIKE 'sub_questions'");
        if (!$r || $r->num_rows === 0) return null;
        $cols = array_column(
            $conn->query("SHOW COLUMNS FROM sub_questions")->fetch_all(MYSQLI_ASSOC),
            'Field'
        );
        $keyCol = null;
        foreach (['sub_answer','answer','key_answer','correct_answer','answer_text'] as $c) {
            if (in_array($c, $cols, true)) { $keyCol = $c; break; }
        }
        if ($keyCol === null) return null;
        $typeCol = null;
        foreach (['type','q_type','question_type'] as $c) {
            if (in_array($c, $cols, true)) { $typeCol = $c; break; }
        }
        return ['key_col' => $keyCol, 'type_col' => $typeCol];
    } catch (Throwable) { return null; }
}

// ══════════════════════════════════════════════════════════════
// PYTHON — FILE-BASED (ไม่ใช้ pipe เลย)
// ══════════════════════════════════════════════════════════════

/**
 * รัน Python ครั้งเดียวกับ $items จากทุก job ที่รวมกัน
 * Python โหลด model 1 ครั้ง → ตรวจทุก item → คืนผลลัพธ์
 */
function runPythonFileBased(
    string $pythonCli,
    string $pyScript,
    array  $items,
    int    $primaryJobId,
    mysqli $conn
): array {
    $tmp      = sys_get_temp_dir();
    $inFile   = $tmp . DIRECTORY_SEPARATOR . "ag_in_{$primaryJobId}.json";
    $outFile  = $tmp . DIRECTORY_SEPARATOR . "ag_out_{$primaryJobId}.json";
    $errFile  = $tmp . DIRECTORY_SEPARATOR . "ag_err_{$primaryJobId}.txt";

    if (file_put_contents($inFile, json_encode($items, JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException("เขียน temp input file ไม่ได้: {$inFile}");
    }

    $isWin   = PHP_OS_FAMILY === 'Windows';
    $pathSep = $isWin ? ';' : ':';
    $venvDir = dirname(dirname($pythonCli));
    $venvBin = $venvDir . DIRECTORY_SEPARATOR . ($isWin ? 'Scripts' : 'bin');
    $hfHome  = $tmp . DIRECTORY_SEPARATOR . 'hf_cache';
    if (!is_dir($hfHome)) @mkdir($hfHome, 0777, true);

    $env = [
        'PYTHONHOME'            => '',
        'PYTHONPATH'            => '',
        'VIRTUAL_ENV'           => is_dir($venvBin) ? $venvDir : '',
        'PATH'                  => (is_dir($venvBin) ? $venvBin . $pathSep : '') . (getenv('PATH') ?: ''),
        'PYTHONIOENCODING'      => 'utf-8',
        'PYTHONUNBUFFERED'      => '1',
        'TEMP'                  => $tmp,
        'TMP'                   => $tmp,
        'SYSTEMROOT'            => getenv('SYSTEMROOT') ?: ($isWin ? 'C:\\Windows' : ''),
        'SystemRoot'            => getenv('SystemRoot')  ?: ($isWin ? 'C:\\Windows' : ''),
        'HF_HOME'               => $hfHome,
        'HUGGINGFACE_HUB_CACHE' => $hfHome . DIRECTORY_SEPARATOR . 'hub',
        'TRANSFORMERS_CACHE'    => $hfHome . DIRECTORY_SEPARATOR . 'hub',
        'USERPROFILE'           => $tmp,
        'APPDATA'               => $tmp,
        'HOME'                  => $tmp,
        'HF_DATASETS_OFFLINE'   => '1',
        'TRANSFORMERS_OFFLINE'  => '1',
    ];

    $cmd = escapeshellarg($pythonCli)
         . ' -u '
         . escapeshellarg($pyScript)
         . ' ' . escapeshellarg($inFile)
         . ' ' . escapeshellarg($outFile);

    $nullDev     = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['file', $nullDev, 'r'],
        1 => ['file', $nullDev, 'w'],
        2 => ['file', $errFile, 'w'],
    ];

    jobUpdate($conn, $primaryJobId, ['message' => 'กำลังโหลด Python model…']);

    $proc = @proc_open($cmd, $descriptors, $pipes, dirname($pyScript), $env);
    if (!is_resource($proc)) {
        $e = error_get_last();
        @unlink($inFile); @unlink($errFile);
        throw new RuntimeException('proc_open ล้มเหลว: ' . ($e['message'] ?? 'unknown') . ' | cmd: ' . $cmd);
    }

    $exitCode = proc_close($proc); // block จนกว่า Python จะ exit

    $stderr = is_file($errFile) ? trim((string)@file_get_contents($errFile)) : '';
    @unlink($inFile);
    @unlink($errFile);

    $raw     = is_file($outFile) ? @file_get_contents($outFile) : '';
    $decoded = json_decode((string)$raw, true);
    @unlink($outFile);

    if ($exitCode !== 0) {
        $errDetail = '';
        if (is_array($decoded)) {
            $code   = $decoded['error']  ?? 'unknown';
            $detail = $decoded['detail'] ?? '';
            $hint = match($code) {
                'missing_numpy'                 => 'pip install numpy',
                'missing_sentence_transformers' => 'pip install sentence-transformers',
                'model_load_failed'             => 'ตรวจ internet/disk (~270MB)',
                'input_not_found'               => 'temp dir write permission?',
                default => ''
            };
            $errDetail = "Python error [{$code}]"
                . ($detail ? ": {$detail}" : '')
                . ($hint   ? " | แก้: {$hint}" : '');
        }
        throw new RuntimeException(
            ($errDetail ?: "Python exit code: {$exitCode}")
            . ($stderr   ? " | stderr: {$stderr}" : '')
            . " | cmd: {$cmd}"
        );
    }

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'output file ไม่ใช่ JSON: ' . mb_substr((string)$raw, 0, 200)
            . ($stderr ? " | stderr: {$stderr}" : '')
        );
    }
    if (isset($decoded['fatal'])) {
        $code   = $decoded['error']  ?? 'unknown';
        $detail = $decoded['detail'] ?? '';
        $hint = match($code) {
            'missing_numpy'                 => 'pip install numpy',
            'missing_sentence_transformers' => 'pip install sentence-transformers',
            'model_load_failed'             => 'ตรวจ internet/disk (~270MB)',
            default => ''
        };
        throw new RuntimeException(
            "Python fatal [{$code}]"
            . ($detail ? ": {$detail}" : '')
            . ($hint   ? " | แก้: {$hint}" : '')
        );
    }

    // index ผลลัพธ์ด้วย answer_id
    $resultMap = [];
    foreach ($decoded as $r) {
        if (isset($r['answer_id'])) {
            $resultMap[(int)$r['answer_id']] = $r;
        }
    }
    return $resultMap;
}

// ══════════════════════════════════════════════════════════════
// COLLECT ITEMS FOR ONE JOB
// ══════════════════════════════════════════════════════════════

/**
 * ดึง exam_answers + exam_sub_answers สำหรับ 1 job
 * คืน ['main' => [...], 'sub' => [...], 'sub_schema' => [...]]
 */
function collectJobItems(mysqli $conn, int $attemptId, int $force, ?array $subSchema): array
{
    $writtenTypes = "('short_answer','short','essay','long_answer','written','text')";
    $noRegrade    = $force !== 1
        ? "AND (ea.feedback IS NULL OR ea.feedback NOT LIKE '%AUTO_GRADE_V1:%')"
        : '';

    $st = $conn->prepare("
        SELECT ea.id AS answer_id, ea.attempt_id, ea.question_id,
               ea.answer_text, ea.max_score, ea.feedback,
               q.type AS q_type, q.answer AS key_answer
        FROM exam_answers ea
        JOIN questions q ON q.id = ea.question_id
        WHERE ea.attempt_id = ?
          AND q.type IN {$writtenTypes}
          AND ea.answer_text IS NOT NULL AND ea.answer_text <> ''
          AND q.answer IS NOT NULL AND q.answer <> ''
          {$noRegrade}
        ORDER BY ea.id ASC
    ");
    $st->bind_param('i', $attemptId);
    $st->execute();
    $mainItems = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    $subItems = [];
    if ($subSchema !== null) {
        $kc       = $subSchema['key_col'];
        $tc       = $subSchema['type_col'];
        $typeExpr = $tc ? "sq.`{$tc}`" : "'short_answer'";
        $noRegSub = $force !== 1
            ? "AND (esa.feedback IS NULL OR esa.feedback NOT LIKE '%AUTO_GRADE_V1:%')"
            : '';
        try {
            $st = $conn->prepare("
                SELECT esa.id AS answer_id, esa.attempt_id, esa.question_id,
                       esa.sub_question_id, esa.answer_text, esa.max_score, esa.feedback,
                       sq.`{$kc}` AS key_answer, {$typeExpr} AS q_type
                FROM exam_sub_answers esa
                JOIN sub_questions sq ON sq.id = esa.sub_question_id
                WHERE esa.attempt_id = ?
                  AND esa.answer_text IS NOT NULL AND esa.answer_text <> ''
                  AND sq.`{$kc}` IS NOT NULL AND sq.`{$kc}` <> ''
                  {$noRegSub}
                ORDER BY esa.id ASC
            ");
            $st->bind_param('i', $attemptId);
            $st->execute();
            $subItems = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable) {}
    }

    return ['main' => $mainItems, 'sub' => $subItems];
}

// ══════════════════════════════════════════════════════════════
// SAVE RESULTS FOR ONE JOB
// ══════════════════════════════════════════════════════════════

/**
 * เขียนผลตรวจกลับ DB สำหรับ 1 job
 * คืนจำนวน item ที่ save แล้ว
 */
function saveJobResults(
    mysqli $conn,
    array  $jobMeta,       // ['job_id', 'attempt_id', 'force', 'main', 'sub']
    array  $nlpResultMap,
    bool   $useNLP,
    string $nlpMode,
    int    $doneOffset,    // done count ก่อนหน้าทุก job ที่ผ่านมา
    int    $globalTotal,   // total ทุก item ทุก job
    int    $primaryJobId,  // job_id หลัก (สำหรับ jobUpdate progress)
    mysqli $conn2          // ใช้ connection เดิม
): int {
    $jobId     = (int) $jobMeta['job_id'];
    $force     = (int) $jobMeta['force'];
    $mainItems = $jobMeta['main'];
    $subItems  = $jobMeta['sub'];
    $done      = 0;

    $getResult = function(array $it) use ($useNLP, $nlpResultMap): array {
        $aid      = (int)$it['answer_id'];
        $maxScore = max(1.0, (float)($it['max_score'] ?? 1));
        if ($useNLP && isset($nlpResultMap[$aid])) {
            return $nlpResultMap[$aid];
        }
        $sim = phpSimilarity((string)($it['answer_text']??''), (string)($it['key_answer']??''));
        return [
            'answer_id'        => $aid,
            'similarity'       => $sim,
            'score'            => calcScore($sim, $maxScore),
            'model'            => 'php_fallback',
            'lang_student'     => null,
            'lang_key'         => null,
            'translated'       => false,
            'translation_note' => null,
            'scoring'          => null,
        ];
    };

    $upMain = $conn->prepare("UPDATE exam_answers SET score=?, is_correct=?, feedback=? WHERE id=? LIMIT 1");

    // ── PASS 1: exam_answers ─────────────────────────────────────
    foreach ($mainItems as $it) {
        $aid      = (int)$it['answer_id'];
        $maxScore = max(1.0, (float)($it['max_score'] ?? 1));
        $res      = $getResult($it);
        $sim      = (float)($res['similarity'] ?? 0);
        $score    = calcScore($sim, $maxScore);
        $isCorr   = $sim >= 0.9 ? 1 : 0;
        $meta = [
            'source'           => 'exam_answers',
            'model'            => $res['model']            ?? 'unknown',
            'similarity'       => round($sim, 6),
            'lang_student'     => $res['lang_student']     ?? null,
            'lang_key'         => $res['lang_key']         ?? null,
            'translated'       => (bool)($res['translated'] ?? false),
            'translation_note' => $res['translation_note'] ?? null,
            'scoring'          => $res['scoring']          ?? null,
            'timestamp'        => nowStr(),
        ];
        $fb = buildFeedback((string)($it['feedback']??''), $force, $meta);
        $upMain->bind_param('disi', $score, $isCorr, $fb, $aid);
        $upMain->execute();
        $done++;

        // อัปเดต progress ให้ job ปัจจุบัน
        $globalDone = $doneOffset + $done;
        jobUpdate($conn, $jobId, [
            'done_items' => $done,
            'message'    => "{$nlpMode} ตรวจแล้ว {$done}/" . (count($mainItems) + count($subItems))
                          . " (รวมทุก job: {$globalDone}/{$globalTotal}, sim=" . round($sim,3) . ")",
        ]);
    }

    // ── PASS 2: exam_sub_answers ─────────────────────────────────
    if (!empty($subItems)) {
        $upSub = $conn->prepare("UPDATE exam_sub_answers SET score=?, is_correct=?, feedback=? WHERE id=? LIMIT 1");

        foreach ($subItems as $it) {
            $aid      = (int)$it['answer_id'];
            $maxScore = max(1.0, (float)($it['max_score'] ?? 1));
            $res      = $getResult($it);
            $sim      = (float)($res['similarity'] ?? 0);
            $score    = calcScore($sim, $maxScore);
            $isCorr   = $sim >= 0.9 ? 1 : 0;
            $meta = [
                'source'           => 'exam_sub_answers',
                'sub_question_id'  => (int)($it['sub_question_id'] ?? 0),
                'model'            => $res['model']            ?? 'unknown',
                'similarity'       => round($sim, 6),
                'lang_student'     => $res['lang_student']     ?? null,
                'lang_key'         => $res['lang_key']         ?? null,
                'translated'       => (bool)($res['translated'] ?? false),
                'translation_note' => $res['translation_note'] ?? null,
                'scoring'          => $res['scoring']          ?? null,
                'timestamp'        => nowStr(),
            ];
            $fb = buildFeedback((string)($it['feedback']??''), $force, $meta);
            $upSub->bind_param('disi', $score, $isCorr, $fb, $aid);
            $upSub->execute();

            // Rollup → exam_answers
            $subAid = (int)($it['attempt_id']  ?? 0);
            $subQid = (int)($it['question_id'] ?? 0);
            if ($subAid > 0 && $subQid > 0) {
                $r = $conn->prepare("
                    SELECT COALESCE(SUM(score),0) AS s, COALESCE(SUM(max_score),0) AS m
                    FROM exam_sub_answers WHERE attempt_id=? AND question_id=?
                ");
                $r->bind_param('ii', $subAid, $subQid);
                $r->execute();
                $sums = $r->get_result()->fetch_assoc();
                $ss = (float)($sums['s'] ?? 0);
                $sm = (float)($sums['m'] ?? 0);
                $u  = $conn->prepare("UPDATE exam_answers SET score=?, max_score=? WHERE attempt_id=? AND question_id=? LIMIT 1");
                $u->bind_param('ddii', $ss, $sm, $subAid, $subQid);
                $u->execute();
            }

            $done++;
            $globalDone = $doneOffset + $done;
            jobUpdate($conn, $jobId, [
                'done_items' => $done,
                'message'    => "{$nlpMode} ตรวจโจทย์ย่อย {$done}/" . (count($mainItems) + count($subItems))
                              . " (รวมทุก job: {$globalDone}/{$globalTotal}, sim=" . round($sim,3) . ")",
            ]);
        }
    }

    // ── คะแนนรวม attempt ─────────────────────────────────────────
    $attemptId = (int)($mainItems[0]['attempt_id'] ?? ($subItems[0]['attempt_id'] ?? 0));
    if ($attemptId > 0) {
        $su = $conn->prepare("
            UPDATE exam_attempts
            SET score_total=(SELECT COALESCE(SUM(score),0)     FROM exam_answers WHERE attempt_id=?),
                score_max  =(SELECT COALESCE(SUM(max_score),0) FROM exam_answers WHERE attempt_id=?)
            WHERE id=? LIMIT 1
        ");
        $su->bind_param('iii', $attemptId, $attemptId, $attemptId);
        $su->execute();
    }

    return $done;
}

// ══════════════════════════════════════════════════════════════
// MAIN
// ══════════════════════════════════════════════════════════════

$triggerJobId = PHP_SAPI === 'cli'
    ? (int)($argv[1] ?? 0)
    : (int)($_GET['job_id'] ?? 0);

if ($triggerJobId <= 0) {
    if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain'); echo 'invalid job_id'; }
    exit;
}

ignore_user_abort(true);
set_time_limit(0);

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
    ensureJobsTable($conn);

    // ── ตรวจ job ที่ trigger มา ──────────────────────────────────────────────
    $st = $conn->prepare("SELECT id, attempt_id, status, force_regrade FROM auto_grade_jobs WHERE id = ? LIMIT 1");
    $st->bind_param('i', $triggerJobId);
    $st->execute();
    $triggerJob = $st->get_result()->fetch_assoc();

    if (!$triggerJob || in_array($triggerJob['status'], ['done','error','running'], true)) exit;

    // ── Claim ทุก job ที่ยัง 'queued' — รวม job ที่ trigger มาด้วย ────────────
    // ใช้ single UPDATE เพื่อ atomic claim กัน race condition
    // (ถ้ามี worker 2 ตัวรัน trigger พร้อมกัน จะ claim ได้แค่ตัวเดียว)
    $conn->begin_transaction();

    // ล็อก rows ก่อน claim เพื่อกัน worker อื่นมา claim ซ้อน
    $lockResult = $conn->query("
        SELECT id, attempt_id, force_regrade
        FROM   auto_grade_jobs
        WHERE  status = 'queued'
        ORDER  BY id ASC
        FOR    UPDATE
    ");
    $queuedJobs = $lockResult->fetch_all(MYSQLI_ASSOC);

    if (empty($queuedJobs)) {
        $conn->rollback();
        exit;
    }

    $jobIds = array_column($queuedJobs, 'id');

    // ── Mark ทุก job เป็น 'running' พร้อมกัน ────────────────────────────────
    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    $types        = str_repeat('i', count($jobIds));
    $markSt       = $conn->prepare("
        UPDATE auto_grade_jobs
        SET    status = 'running', started_at = ?, message = 'รวมคิวทุก job ก่อนตรวจพร้อมกัน…'
        WHERE  id IN ({$placeholders})
    ");
    $now = nowStr();
    $markSt->bind_param('s' . $types, $now, ...$jobIds);
    $markSt->execute();

    $conn->commit();

    $subSchema = detectSubSchema($conn);
    $pyScript  = __DIR__ . DIRECTORY_SEPARATOR . 'auto_grade_nlp_stream.py';

    // ── รวบรวม items ทุก job ─────────────────────────────────────────────────
    $jobDataMap   = [];  // job_id → ['job_id', 'attempt_id', 'force', 'main', 'sub']
    $allPyItems   = [];  // รายการทั้งหมดที่ส่ง Python
    $globalTotal  = 0;

    foreach ($queuedJobs as $qj) {
        $jid       = (int)$qj['id'];
        $attemptId = (int)$qj['attempt_id'];
        $force     = (int)($qj['force_regrade'] ?? 0);

        $items = collectJobItems($conn, $attemptId, $force, $subSchema);

        $jobItemCount = count($items['main']) + count($items['sub']);

        // อัปเดต total_items ของแต่ละ job
        jobUpdate($conn, $jid, [
            'total_items' => $jobItemCount,
            'message'     => 'รอตรวจรวมกัน ' . count($queuedJobs) . ' job (total: ' . $jobItemCount . ' ข้อ)…',
        ]);

        // เก็บข้อมูลของ job นี้
        $jobDataMap[$jid] = [
            'job_id'     => $jid,
            'attempt_id' => $attemptId,
            'force'      => $force,
            'main'       => $items['main'],
            'sub'        => $items['sub'],
        ];

        // รวม items สำหรับ Python (ใส่ job_id ไว้ใน payload เพื่อ track)
        foreach (array_merge($items['main'], $items['sub']) as $it) {
            $ms = max(1.0, (float)($it['max_score'] ?? 1));
            $allPyItems[] = [
                'answer_id' => (int)$it['answer_id'],
                'student'   => (string)($it['answer_text'] ?? ''),
                'key'       => (string)($it['key_answer']  ?? ''),
                'max_score' => $ms,
                'q_type'    => (string)($it['q_type']      ?? ''),
                '_job_id'   => $jid,  // ใช้ track ภายใน PHP เท่านั้น
            ];
        }
        $globalTotal += $jobItemCount;
    }

    // อัปเดต job หลัก (trigger job) ให้แสดง summary
    $jobCount = count($queuedJobs);
    jobUpdate($conn, $triggerJobId, [
        'message' => "กำลังโหลด Python model… (รวม {$jobCount} job, {$globalTotal} ข้อ)",
    ]);

    // ── รัน Python ครั้งเดียว กับทุก item ทุก job ───────────────────────────
    $nlpResultMap = [];
    $useNLP  = false;
    $nlpMode = '🔤 PHP';

    if ($globalTotal > 0 && is_file($pyScript)) {
        // ลบ field _job_id ออกก่อนส่ง Python (Python ไม่รู้จัก field นี้)
        $pyPayload = array_map(function($it) {
            $clean = $it;
            unset($clean['_job_id']);
            return $clean;
        }, $allPyItems);

        try {
            $nlpResultMap = runPythonFileBased($PYTHON_CLI, $pyScript, $pyPayload, $triggerJobId, $conn);
            $useNLP  = true;
            $nlpMode = '🤖 NLP';
        } catch (Throwable $pyErr) {
            // Python ล้มเหลว → mark error message ทุก job แล้ว fallback PHP
            foreach ($jobIds as $jid) {
                jobUpdate($conn, $jid, [
                    'last_error' => $pyErr->getMessage(),
                    'message'    => '⚠️ Python ล้มเหลว ใช้ PHP แทน | ' . mb_substr($pyErr->getMessage(), 0, 200),
                ]);
            }
        }
    }

    // ── Save ผลลัพธ์แต่ละ job ────────────────────────────────────────────────
    $doneOffset = 0;

    foreach ($jobDataMap as $jid => $jobData) {
        $jobItemCount = count($jobData['main']) + count($jobData['sub']);

        if ($jobItemCount === 0) {
            jobUpdate($conn, $jid, [
                'status'      => 'done',
                'total_items' => 0,
                'done_items'  => 0,
                'message'     => 'ไม่มีรายการให้ตรวจ',
                'finished_at' => nowStr(),
            ]);
            continue;
        }

        $savedCount = saveJobResults(
            $conn,
            $jobData,
            $nlpResultMap,
            $useNLP,
            $nlpMode,
            $doneOffset,
            $globalTotal,
            $triggerJobId,
            $conn
        );

        jobUpdate($conn, $jid, [
            'status'      => 'done',
            'done_items'  => $savedCount,
            'message'     => "เสร็จแล้ว ({$savedCount}/{$jobItemCount}) [{$nlpMode}]",
            'finished_at' => nowStr(),
        ]);

        $doneOffset += $savedCount;
    }

} catch (Throwable $e) {
    try {
        if (isset($conn) && $conn instanceof mysqli) {
            ensureJobsTable($conn);
            // mark error ทุก job ที่ claim ไว้
            if (!empty($jobIds)) {
                foreach ($jobIds as $jid) {
                    jobUpdate($conn, $jid, [
                        'status'      => 'error',
                        'message'     => 'เกิดข้อผิดพลาด',
                        'last_error'  => $e->getMessage(),
                        'finished_at' => nowStr(),
                    ]);
                }
            } else {
                jobUpdate($conn, $triggerJobId, [
                    'status'      => 'error',
                    'message'     => 'เกิดข้อผิดพลาด',
                    'last_error'  => $e->getMessage(),
                    'finished_at' => nowStr(),
                ]);
            }
        }
    } catch (Throwable) {}
}