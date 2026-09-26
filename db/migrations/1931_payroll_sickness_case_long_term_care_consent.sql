-- MyUcto.cz - souhlas zaměstnavatele s dlouhodobým ošetřovným (§ 191a ZP).
--
-- § 191a zákoníku práce: zaměstnavatel je povinen vyhovět žádosti
-- zaměstnance o nepřítomnost v práci z důvodu poskytování dlouhodobé péče,
-- „ledaže mu v tom brání vážné provozní důvody"; odmítnutí musí zaměstnanci
-- písemně zdůvodnit. Aplikace dosud neměla kam rozhodnutí zapsat, takže
-- chyběl doklad, kdy a proč zaměstnavatel souhlasil nebo odmítl — a případ
-- DLO šel připravit i tehdy, když zaměstnavatel nepřítomnost odmítl a nárok
-- tedy nevznikl.
--
-- Rozhodnutí je jen u dlouhodobého ošetřovného; odmítnutí vždy nese důvod,
-- rozhodnutí vždy den.

SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS long_term_care_consent ENUM('granted','refused') NULL
    AFTER alternation,
  ADD COLUMN IF NOT EXISTS long_term_care_consent_on DATE NULL
    AFTER long_term_care_consent,
  ADD COLUMN IF NOT EXISTS long_term_care_refusal_reason VARCHAR(500) NULL
    AFTER long_term_care_consent_on;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se omezení nejdřív zahodí.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_ltc_consent;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_ltc_consent
    CHECK (
      (long_term_care_consent IS NULL
        AND long_term_care_consent_on IS NULL
        AND long_term_care_refusal_reason IS NULL)
      OR (benefit_kind = 'DLO'
        AND long_term_care_consent_on IS NOT NULL
        AND (
          (long_term_care_consent = 'granted' AND long_term_care_refusal_reason IS NULL)
          OR (long_term_care_consent = 'refused' AND long_term_care_refusal_reason IS NOT NULL)
        ))
    );
