-- MyÚčto.cz — doba trvání vztahu po neplatném skončení (§ 16 odst. 4 písm. j)
-- zákona č. 155/1995 Sb.).
--
-- Pravomocné rozhodnutí soudu (nebo mimosoudní dohoda po podání žaloby)
-- určí, že skončení vztahu bylo neplatné a vztah trval dál, ale náhrada mzdy
-- za tu dobu přiznána nebyla. Takové dny jsou vyloučenou dobou důchodového
-- pojištění a měsíční hlášení je nese v atributu 10536 (`vyloucenePar16`).
-- Aplikace pro ně neměla vstup a 10536 vždy vykázala nulou. Nový druh
-- nepřítomnosti `invalid_termination` je ten vstup. Hodnota přibývá na konec
-- výčtu, existující řádky se nemění. MODIFY na úplný výčet je idempotentní.

SET NAMES utf8mb4;

ALTER TABLE payroll_absences
  MODIFY COLUMN absence_type ENUM(
    'vacation','dpn','quarantine','ocr','long_term_care','ppm','paternity',
    'parental','unpaid_leave','employee_obstacle','employer_obstacle',
    'compensatory_time_off','unexcused','other',
    'public_function','employee_obstacle_unpaid','invalid_termination'
  ) NOT NULL;
