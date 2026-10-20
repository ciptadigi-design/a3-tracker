-- Phase 3.3E - Production READ-ONLY audit of Pengalaman Lapangan eligibility.
-- NOT EXECUTED. Run only after explicit approval, in a read-only session:
--   SET SESSION TRANSACTION READ ONLY;
-- Aggregates only: no names, free text, user ids or emails are selected.
-- Eligibility mirrors MaintenanceFieldExperienceSearch::eligibleTickets():
--   type=breakdown, status=DONE, test result normal|monitoring, trimmed cause and action >= 5 chars.

SET SESSION TRANSACTION READ ONLY;

-- 1. Eligible ticket count per account, and ticket totals for context.
SELECT t.account_id,
       COUNT(*) AS tickets,
       SUM(t.type = 'breakdown' AND t.status = 'DONE'
           AND t.resolution_test_result IN ('normal', 'monitoring')
           AND CHAR_LENGTH(TRIM(t.resolution_cause)) >= 5
           AND CHAR_LENGTH(TRIM(t.resolution_action)) >= 5) AS eligible
FROM maintenance_tickets t GROUP BY t.account_id;

-- 2. Missing resolution data among finished breakdown tickets (excluded from search).
SELECT SUM(resolution_test_result IS NULL) AS no_test_result,
       SUM(resolution_test_result = 'not_normal') AS not_normal,
       SUM(CHAR_LENGTH(TRIM(COALESCE(resolution_cause, ''))) < 5) AS short_cause,
       SUM(CHAR_LENGTH(TRIM(COALESCE(resolution_action, ''))) < 5) AS short_action
FROM maintenance_tickets WHERE type = 'breakdown' AND status = 'DONE';

-- 3. Eligible tickets by branch and test result.
SELECT t.account_id, b.code AS branch_code, t.resolution_test_result, COUNT(*) AS eligible
FROM maintenance_tickets t JOIN branches b ON b.id = t.branch_id AND b.account_id = t.account_id
WHERE t.type = 'breakdown' AND t.status = 'DONE' AND t.resolution_test_result IN ('normal', 'monitoring')
  AND CHAR_LENGTH(TRIM(t.resolution_cause)) >= 5 AND CHAR_LENGTH(TRIM(t.resolution_action)) >= 5
GROUP BY t.account_id, b.code, t.resolution_test_result;

-- 4. Eligible tickets by code family (official > account reference > downtime text).
SELECT CASE
         WHEN od.id IS NOT NULL AND oe.code LIKE 'C-%' THEN 'official C'
         WHEN od.id IS NOT NULL AND oe.code LIKE 'J-%' THEN 'official J'
         WHEN ec.code LIKE 'C-%' THEN 'account C' WHEN ec.code LIKE 'J-%' THEN 'account J'
         WHEN UPPER(d.error_code_text) REGEXP '^C' THEN 'downtime C' WHEN UPPER(d.error_code_text) REGEXP '^J' THEN 'downtime J'
         ELSE 'no code' END AS family,
       COUNT(*) AS eligible
FROM maintenance_tickets t
LEFT JOIN machine_error_codes ec ON ec.id = t.error_code_id
LEFT JOIN maintenance_official_error_entries oe ON oe.id = t.official_error_entry_id
LEFT JOIN maintenance_documents od ON od.id = oe.document_id AND (od.account_id IS NULL OR od.account_id = t.account_id)
LEFT JOIN downtime_events d ON d.id = t.downtime_event_id
WHERE t.type = 'breakdown' AND t.status = 'DONE' AND t.resolution_test_result IN ('normal', 'monitoring')
  AND CHAR_LENGTH(TRIM(t.resolution_cause)) >= 5 AND CHAR_LENGTH(TRIM(t.resolution_action)) >= 5
GROUP BY family;

-- 5. Potentially test/dummy records among eligible tickets (counts only; review ids separately if > 0).
--    Includes the documented dummy-downtime cleanup window (docs/PRODUCTION_DUMMY_DOWNTIME_CLEANUP.md).
SELECT COUNT(*) AS suspicious_eligible
FROM maintenance_tickets t
WHERE t.type = 'breakdown' AND t.status = 'DONE' AND t.resolution_test_result IN ('normal', 'monitoring')
  AND CHAR_LENGTH(TRIM(t.resolution_cause)) >= 5 AND CHAR_LENGTH(TRIM(t.resolution_action)) >= 5
  AND (LOWER(t.title) REGEXP 'test|tes |dummy|coba|asdf|xxx'
       OR LOWER(t.resolution_cause) REGEXP 'test|tes |dummy|coba|asdf|xxx'
       OR LOWER(t.resolution_action) REGEXP 'test|tes |dummy|coba|asdf|xxx'
       OR TIMESTAMPDIFF(MINUTE, t.opened_at, t.resolved_at) < 1);
