-- MyÚčto.cz — Daňový doklad k poskytnuté záloze (DDKP) na přijaté straně
--
-- Na přijaté straně dosud chyběl druh dokladu pro „daňový doklad k přijaté platbě"
-- (§ 28 ZDPH) z pohledu odběratele = daňový doklad k POSKYTNUTÉ záloze. Bez něj se
-- takový doklad ukládal jako běžná přijatá faktura (document_kind='invoice') a
-- zaúčtoval se jako předpis nákladu (518/343 vs 321), takže:
--   • náklad 518 vznikl PŘEDČASNĚ (znovu pak u vyúčtovací faktury → dvojí náklad),
--   • saldo poskytnutých záloh (314) se nikdy nesnížilo o odečtené DPH.
--
-- Správně DDKP účtuje POUZE odpočet DPH ze zálohy: 343 MD / 314 D (zrcadlo vydané
-- strany, kde DDKP k přijaté záloze účtuje 324 MD / 343 D). Základ zůstává na 314,
-- náklad vzniká teprve u vyúčtovací faktury. Účtovací pravidlo `advance.paid.vatdocument`
-- (343/314) už je naseedované (migrace 1006), tato migrace jen zavádí druh dokladu,
-- který ho spustí.
--
-- Vazba DDKP → záloha se drží ve stávajícím `parent_purchase_invoice_id` (migrace 1096,
-- přetížená dle document_kind — jako parent_invoice_id na vydané straně): u dobropisu
-- míří na opravovanou fakturu, u DDKP na zálohu (document_kind='advance').
--
-- Idempotentní: MODIFY ENUM je bezpečné opakovat (nastaví tentýž typ). Přidání hodnoty
-- na KONEC enumu nemění ordinální hodnoty existujících řádků (žádná migrace dat).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    MODIFY COLUMN document_kind
        ENUM('invoice','receipt','credit_note','advance','tax_document')
        NOT NULL DEFAULT 'invoice'
        COMMENT 'Druh dokladu: invoice/receipt/credit_note/advance + tax_document = daňový doklad k poskytnuté záloze (DDKP, §28); DDKP účtuje jen odpočet DPH 343/314';
