-- MyÚčto.cz — přepínač „Vést účetnictví" na firmě.
--
-- PROČ: ne každá firma v instalaci chce účetní nadstavbu. Fakturace, DPH a sklad dávají
-- smysl samy o sobě; deník, výkazy, majetek, mzdy a uzávěrka jen zahlcují menu tomu, kdo
-- účetnictví řeší jinde. Dosud šlo účetní část schovat jen tím, že firma neměla licenci
-- na komerční funkce — což je hrubý nástroj a platí pro celou instalaci, ne pro firmu.
--
-- DEFAULT 1 PRO VŠECHNY: existující firmy účetnictví vidí a po migraci se jim nic nezmění.
-- NOT NULL s defaultem proto, aby „nevyplněno" nebyla třetí, nejednoznačná hodnota.
--
-- NA LICENCI TO NEMÁ VLIV — a to je funkční požadavek, ne opomenutí. Licencují se všechny
-- firmy a všichni ne-readonly uživatelé bez ohledu na tenhle přepínač; vypnuté účetnictví
-- je volba rozsahu UI, ne důvod k levnějšímu tarifu. Proto se sloupec ZÁMĚRNĚ neobjevuje
-- v LicenseService ani v počítání companies_active — kdyby se tam dostal, dala by se
-- licence obejít vypnutím účetnictví.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (vzor 1023, 1177, 1178).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS accounting_enabled TINYINT(1) NOT NULL DEFAULT 1
      COMMENT 'vést účetnictví (deník, výkazy, majetek, mzdy, uzávěrka); 0 = skryté z menu. Na licenci nemá vliv.'
      AFTER accounting_mode;
