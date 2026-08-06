-- MyÚčto.cz — MZ-30-W01: chybějící kompozitní indexy mzdového modulu.
--
-- Schéma důsledně indexuje vzorem (supplier_id, employment_id, <rozsah>) — správně
-- pro obrazovku jedné osoby, ale k ničemu pro firemní dotazy přes všechny
-- zaměstnance, protože employment_id/employee_id sedí mezi tenantním klíčem
-- a rozsahovým predikátem. Každý index níž je ověřený proti konkrétnímu dotazu
-- v repository vrstvě (viz komentář u indexu), ne přidaný naslepo.
--
-- Čistě DDL, bez dopadu na výsledek výpočtu.

SET NAMES utf8mb4;

-- F-12: PayrollTimeRepository::shifts()/entries() — firemní (ne osobní) dotaz na
-- publikované směny/odpracovaný čas za CELÝ měsíc přes všechny zaměstnance:
-- WHERE supplier_id = ? AND status <> 'superseded' AND starts_at_utc BETWEEN ? AND ?
-- Stávající idx_payroll_shift_month/idx_payroll_time_entry_month vede přes
-- employment_id, který tu není ve WHERE — nepoužitelný.
ALTER TABLE payroll_shifts
  ADD KEY IF NOT EXISTS idx_payroll_shift_company_period
    (supplier_id, starts_at_utc, status);

ALTER TABLE payroll_time_entries
  ADD KEY IF NOT EXISTS idx_payroll_time_entry_company_period
    (supplier_id, starts_at_utc, status);

-- F-24: PayrollRecurringComponentRepository::effectiveForPeriod() — firemní dotaz
-- (CTE přes VŠECHNY zaměstnance dodavatele) na aktivní opakované složky platné
-- v měsíci: WHERE supplier_id = ? AND is_active = 1 AND valid_from <= ?
-- AND (valid_to IS NULL OR valid_to >= ?). employment_id ve WHERE není.
ALTER TABLE payroll_recurring_components
  ADD KEY IF NOT EXISTS idx_payroll_recurring_company_active
    (supplier_id, is_active, valid_from, valid_to);

-- F-25: PayrollAbsenceRepository::list() bez $employmentId (firemní kalendář
-- absencí) — interval overlap WHERE supplier_id = ? AND date_from <= ?
-- AND date_to >= ?. idx_payroll_absence_period vede přes employment_id.
ALTER TABLE payroll_absences
  ADD KEY IF NOT EXISTS idx_payroll_absence_company_period
    (supplier_id, date_to, date_from);

-- F-35: PayrollDocumentRepository::latestForRevisionKind() — WHERE supplier_id = ?
-- AND revision_id = ? AND employee_id <=> ? AND document_kind = ?
-- ORDER BY document_revision_no DESC, id DESC LIMIT 1. Žádný z 11 existujících
-- indexů nemá tuhle přesnou sloupcovou shodu (uq_payroll_document_revision vede
-- přes document_kind PŘED employee a přes employee_scope_id, ne syrový
-- employee_id, který dotaz filtruje) — bez indexu se řadí filesortem.
ALTER TABLE payroll_generated_documents
  ADD KEY IF NOT EXISTS idx_payroll_document_revision_employee_kind
    (supplier_id, revision_id, employee_id, document_kind, document_revision_no);

-- F-47: PayrollStatutoryResultRepository::find() čte
-- payroll_statutory_relationship_results přes WHERE supplier_id = ?
-- AND statutory_result_id = ? ORDER BY person_result_id, employment_id, id.
-- Na rozdíl od payroll_statutory_person_results (kde uq_payroll_statutory_person
-- (supplier_id, statutory_result_id, employee_id) dotaz plně pokrývá) tahle
-- tabulka žádný index vedoucí statutory_result_id nemá — jediný FK index
-- (fk_payroll_statutory_relationship_parent) vede přes person_result_id.
-- Auditem navržený index vedoucí employment_id/revision_id v kódu nemá oporu
-- (žádný dotaz tak nefiltruje) — index below je podle skutečného přístupu.
ALTER TABLE payroll_statutory_relationship_results
  ADD KEY IF NOT EXISTS idx_payroll_statutory_relationship_result
    (supplier_id, statutory_result_id, person_result_id);

-- F-51: idx_pmr_supplier_period (supplier_id, year) nemá month — doplněk vedle
-- (starý index se nechává, viz zadání). V současném kódu ho žádný firemní dotaz
-- nepoužívá (PayrollMonthlyRecordRepository je vždy employee_id-scoped), jde
-- o levnou přípravu na plánovaný firemní přehled/uzávěrku za měsíc.
ALTER TABLE payroll_monthly_records
  ADD KEY IF NOT EXISTS idx_pmr_supplier_period_month
    (supplier_id, year, month);
