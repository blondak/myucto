-- MyÚčto.cz — „Vést mzdy" je opt-in, ne opt-out.
--
-- Migrace 1187 zavedla `payroll_enabled` s DEFAULT 1, tedy modul zapnutý všem.
-- Mzdy jsou ale samostatná agenda, kterou většina firem nevede, a v menu i v API
-- se tak zbytečně otevírala každému. Sjednocujeme je se skladem (`stock_enabled`,
-- migrace 1023), který je opt-in odjakživa: DEFAULT 0 a v kódu `?? false`.
--
-- Existující řádky se překlápějí taky. Na rozdíl od `require_email_auth` to není
-- regrese: mzdový modul nebyl nikdy vydaný (migrace 1187 přišla s větví mzdy,
-- která ještě není na originu), takže se nikomu nevypíná nic, co by používal.
-- Data se nemažou a přepínač je kdykoliv zpět v Nastavení firmy.

SET NAMES utf8mb4;

ALTER TABLE supplier
  MODIFY COLUMN payroll_enabled TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'vést mzdy; 0 = modul skrytý a interní payroll API zakázané; na licenci nemá vliv';

UPDATE supplier
   SET payroll_enabled = 0
 WHERE payroll_enabled = 1;
