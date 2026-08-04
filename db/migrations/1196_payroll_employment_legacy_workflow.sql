-- MyÚčto.cz — MZ-05: dohledatelný lifecycle a kontrolní povinnosti legacy vztahů.

SET NAMES utf8mb4;

INSERT INTO payroll_employment_events (
  supplier_id,
  employment_id,
  event_type,
  from_status,
  to_status,
  effective_on,
  note,
  diff_json
)
SELECT employment.supplier_id,
       employment.id,
       'created',
       NULL,
       employment.status,
       COALESCE(employment.start_date, CURRENT_DATE),
       'Legacy projekce',
       JSON_OBJECT(
         'relation_type',
         JSON_OBJECT('from', NULL, 'to', employment.relation_type)
       )
  FROM payroll_employments employment
 WHERE employment.is_legacy_projection = 1
   AND NOT EXISTS (
     SELECT 1
       FROM payroll_employment_events event
      WHERE event.supplier_id = employment.supplier_id
        AND event.employment_id = employment.id
        AND event.event_type = 'created'
   );

INSERT IGNORE INTO payroll_employment_checklist_items (
  supplier_id,
  employment_id,
  phase,
  item_key,
  due_date
)
SELECT employment.supplier_id,
       employment.id,
       checklist.phase,
       checklist.item_key,
       CURRENT_DATE
  FROM payroll_employments employment
  JOIN (
    SELECT 'onboarding' AS phase, 'employment_contract' AS item_key
    UNION ALL SELECT 'onboarding', 'health_insurance_registration'
    UNION ALL SELECT 'onboarding', 'social_jmhz_registration'
    UNION ALL SELECT 'onboarding', 'tax_declaration'
    UNION ALL SELECT 'offboarding', 'termination_document'
    UNION ALL SELECT 'offboarding', 'health_insurance_deregistration'
    UNION ALL SELECT 'offboarding', 'social_jmhz_deregistration'
    UNION ALL SELECT 'offboarding', 'enforcement_insolvency_review'
    UNION ALL SELECT 'offboarding', 'later_income_review'
  ) checklist
    ON checklist.phase = 'onboarding'
    OR employment.status IN ('ended', 'archived')
 WHERE employment.is_legacy_projection = 1;
