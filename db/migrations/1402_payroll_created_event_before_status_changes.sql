-- 1402 — založení vztahu nesmí ležet za zpětně datovaným nástupem
--
-- Efektivní stav pracovního vztahu se čte jako poslední lifecycle událost podle
-- `effective_on` (PayrollEmploymentLifecycleSql). Vztah zapsaný dnes, ale
-- s nástupem loni, dostal událost `created → planned` s dnešním datem, zatímco
-- potvrzení nástupu (`status_changed → active`) se datovalo zpětně ke skutečnému
-- nástupu. Časová osa pak tvrdila „aktivní loni … a dnes zase plánovaný":
-- v seznamu lidí vztah svítil jako aktivní (ten čte sloupec `status`), ale
-- z rychlých vstupů, docházky, opakovaných složek i z karet zaměstnanců na
-- přehledu mezd vypadl, protože ty filtrují efektivní stav.
--
-- Zápisová cesta to od téhle verze drží sama (PayrollEmploymentRepository::
-- alignCreatedEvent volaná při každém přechodu stavu); tahle migrace srovnává
-- záznamy, které vznikly dřív.
--
-- Idempotence: UPDATE posouvá jen událost založení, která leží ZA první změnou
-- stavu. Po prvním průchodu už podmínka neplatí a opakování nic nezmění —
-- idempotence je povinná, testy pouštějí migrace znovu.

UPDATE payroll_employment_events created_event
  JOIN (
        SELECT supplier_id, employment_id, MIN(effective_on) AS first_change
          FROM payroll_employment_events
         WHERE event_type = 'status_changed'
         GROUP BY supplier_id, employment_id
       ) status_change
    ON status_change.supplier_id = created_event.supplier_id
   AND status_change.employment_id = created_event.employment_id
   SET created_event.effective_on = status_change.first_change
 WHERE created_event.event_type = 'created'
   AND created_event.effective_on > status_change.first_change;
