-- MyÚčto.cz — přehled o platbě pojistného a hromadné oznámení zaměstnavatele
-- jsou podané dodáním do datové schránky zdravotní pojišťovny.
--
-- Pojišťovna výsledek zpracování neposílá, takže povinnost dřív zůstala
-- „odeslaná" i po doručení a měsíc šlo uzavřít jen ručním „Označit za
-- vyřízené". Nově ji uzavírá samo doložené dodání
-- (PayrollSubmissionSettlementPolicy::settlesOnDelivery). Tahle migrace
-- dorovná podání doručená před změnou.
--
-- Doložené dodání = nejnovější řádek odchozí fronty ISDS k podání je ve stavu
-- `delivered`, nebo má připojenou doručenku (PayrollSubmissionDeliveryProof).
-- Osa vyřízení (`submission_outbox.acceptance_state`) ani stav podání se
-- nemění; uzavírá se jen povinnost.
--
-- Seznam agend musí odpovídat PayrollSubmissionSettlementPolicy::settlesOnDelivery();
-- shodu hlídá PayrollDeliverySettlementMigrationTest.
--
-- Idempotence: mění jen povinnosti, které ještě nejsou uzavřené.

SET NAMES utf8mb4;

UPDATE payroll_obligations
   SET status = 'fulfilled',
       row_version = row_version + 1
 WHERE agenda_code IN ('PPZ_2026', 'HOZ_2026')
   AND status NOT IN ('fulfilled', 'cancelled')
   AND (supplier_id, environment, id) IN (
       SELECT submission.supplier_id, submission.environment, submission.obligation_id
         FROM payroll_submissions submission
         JOIN (
              SELECT artifact.supplier_id,
                     artifact.environment,
                     artifact.submission_id,
                     outbox.dispatch_state,
                     outbox.receipt_document_id,
                     ROW_NUMBER() OVER (
                         PARTITION BY artifact.supplier_id, artifact.environment, artifact.submission_id
                         ORDER BY outbox.id DESC
                     ) AS latest_rank
                FROM submission_outbox outbox
                JOIN payroll_submission_artifacts artifact
                  ON artifact.supplier_id = outbox.supplier_id
                 AND artifact.environment = outbox.environment
                 AND artifact.id = outbox.artifact_id
               WHERE outbox.channel = 'isds'
                 AND outbox.artifact_kind = 'payroll_submission'
         ) latest
           ON latest.supplier_id = submission.supplier_id
          AND latest.environment = submission.environment
          AND latest.submission_id = submission.id
          AND latest.latest_rank = 1
        WHERE submission.status IN ('submitted', 'processing')
          AND (latest.dispatch_state = 'delivered' OR latest.receipt_document_id IS NOT NULL)
   );
