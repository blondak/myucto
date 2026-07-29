-- 1157: § 33a ZoÚ — zřetězení auditní stopy hashem (ochrana proti přepsání)
--
-- `activity_log` byla běžná tabulka. `JournalIntegrityService` detekuje nekonzistence
-- deníku, ale přepsání SAMOTNÉHO LOGU nezjistí nic — kdo má přístup k databázi, může
-- záznam změnit nebo smazat a nezůstane po tom stopa. Audit to vedl mezi vysokými riziky.
--
-- ── Co hash-chain dokáže a co ne ────────────────────────────────────────────────────
-- Každý nový záznam nese hash svého obsahu ZŘETĚZENÝ s hashem předchozího. Změna nebo
-- smazání kteréhokoli záznamu tím rozbije všechny následující — nedetekuje to zápis, ale
-- učiní ho PROKAZATELNÝM.
--
-- Nedokáže to zabránit tomu, aby útočník s právem zápisu přepočítal celý řetěz znovu.
-- Proti tomu chrání až kotva mimo databázi (export otisku, podepsaná záloha) — a tu
-- tahle migrace nepřináší. Předstírat neprolomitelnost by bylo horší než nic.
--
-- ── Historie se ZÁMĚRNĚ nedopočítává ────────────────────────────────────────────────
-- 11 484 existujících záznamů zůstane bez hashe. Dopočítat je zpětně by vytvořilo
-- řetěz, který nedokazuje NIC: hash spočtený dnes nad daty, která mohla být kdykoli
-- změněna, jen dodá zdání důvěryhodnosti. Řetěz proto začíná od prvního nového záznamu
-- a ověřovací nástroj to musí rozlišovat.

SET NAMES utf8mb4;

ALTER TABLE activity_log
    ADD COLUMN IF NOT EXISTS prev_hash CHAR(64) NULL
        COMMENT 'hash předchozího záznamu řetězu; NULL u prvního a u historie před migrací 1157',
    ADD COLUMN IF NOT EXISTS hash CHAR(64) NULL
        COMMENT 'SHA-256 obsahu záznamu zřetězený s prev_hash',
    ADD KEY IF NOT EXISTS idx_activity_hash (hash);
