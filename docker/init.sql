-- Database bootstrap for exam-ocr
-- The MySQL container auto-runs this once on first start (empty data volume)
-- Import your existing schema dump after this by either:
--   1) Placing additional *.sql files next to this one (all run alphabetically on first init), OR
--   2) Running: docker exec -i exam_ocr_db mysql -uroot -p<password> exam_ocr < your_dump.sql

CREATE DATABASE IF NOT EXISTS exam_ocr
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE exam_ocr;

-- auto_grade_jobs table is also created on-demand by auto_grade_worker.php,
-- but we create it here too so teachers can query it immediately.
CREATE TABLE IF NOT EXISTS auto_grade_jobs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
