-- 1263: MZ-18 — posted payroll journals are corrected only by a new revision.

SET NAMES utf8mb4;

ALTER TABLE payroll_posting_batches
  MODIFY COLUMN status ENUM('prepared','posted','no_change')
    NOT NULL DEFAULT 'prepared';
