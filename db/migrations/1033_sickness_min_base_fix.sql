-- MyÚčto.cz — Oprava min. měsíčního VZ nemocenského OSVČ (audit 2026-07, nález H12)
--
-- Min. měsíční vyměřovací základ dobrovolného nemocenského OSVČ je od 2025
-- 9 000 Kč (2× rozhodný příjem 4 500 dle §5b/3 z. 589/1992 Sb.), min. pojistné
-- 9 000 × 2,7 % = 243 Kč/měs. Kód (TaxConstants.php) měl zastaralých 8 000 (= 216 Kč).
--
-- Defaulty jsou v TaxConstants.php; tabulka tax_constants (migrace 0079) drží jen
-- ADMIN OVERRIDE (celý JSON roku). Tato migrace proto NIC neseeduje — jen opraví
-- případný již uložený override pro 2025/2026, který stále nese starou hodnotu 8000.
-- Prázdná tabulka = no-op. Idempotence: WHERE hlídá starou hodnotu, re-run bezpečný.

SET NAMES utf8mb4;

UPDATE tax_constants
   SET data = JSON_SET(data, '$.sickness_min_monthly_base', 9000)
 WHERE year IN (2025, 2026)
   AND JSON_EXTRACT(data, '$.sickness_min_monthly_base') = 8000;
