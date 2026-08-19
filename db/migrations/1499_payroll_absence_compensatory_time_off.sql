-- MyÚčto.cz — náhradní volno za přesčas dostává vlastní druh absence.
--
-- PROČ NE ODVOZENÍ Z JEDNOHO MÍSTA
--
-- Čerpání náhradního volna se dosud evidovalo dvakrát: jako absence druhu
-- `other` (kdo v který den nepracoval) a zároveň v `payroll_overtime_compensations`
-- (kolik minut přesčasu se tím vyrovnalo). Nabízelo se to sjednotit — a je to
-- past, protože každý zápis odpovídá na jinou otázku a je klíčovaný jiným dnem:
--
--   * `payroll_absences`                — den ČERPÁNÍ. Vstup do docházky, fondu
--     pracovní doby a mzdy; za dobu čerpání náhradního volna mzda nepřísluší
--     (§ 114 odst. 3 zákoníku práce), proto `compensation_policy = 'none'`.
--   * `payroll_overtime_compensations`  — den PŘESČASU. Vstup do vyrovnávacího
--     období podle § 93 odst. 4; odst. 5 vyjímá „práci přesčas, za kterou bylo
--     poskytnuto náhradní volno", tedy hodiny na straně přesčasu.
--
-- Den přesčasu z absence odvodit nejde (absence ho nenese a jeden den čerpání
-- může vyrovnávat přesčas z několika různých dnů, i naopak). Odvozením by se
-- proto ztratil klíč, na kterém stojí celé vynětí — a vynětí by se posunulo do
-- nesprávného týdne. Obojí tedy zůstává; tahle migrace jen dává čerpání
-- ROZPOZNATELNÝ druh, aby šlo obě evidence porovnat.
--
-- Bez vlastního druhu porovnat nešly: `other` je „nerozlišená absence" a patří
-- do něj i všechno ostatní, takže součet za měsíc by nic nedokazoval.
--
-- ZMĚNA ENUMU JE ADITIVNÍ
--
-- Přidává se hodnota, žádná se neruší ani nepřejmenovává. Existující řádky
-- `other` se NEPŘEVÁDĚJÍ: z uložených dat nejde poznat, které z nich náhradní
-- volno byly, a tichý převod by vyrobil nepravdivou evidenci u firem, které
-- `other` používají na něco jiného. Přeřazení je ruční úkon účetní.
--
-- MODIFY COLUMN je z povahy idempotentní — opakované spuštění zapíše tutéž
-- definici sloupce.

SET NAMES utf8mb4;

ALTER TABLE payroll_absences
  MODIFY COLUMN absence_type ENUM(
    'vacation','dpn','quarantine','ocr','long_term_care','ppm','paternity',
    'parental','unpaid_leave','employee_obstacle','employer_obstacle',
    'compensatory_time_off','other'
  ) NOT NULL;
