SET NAMES utf8mb4;

-- `user_id` vstupuje do hashe auditního řetězu. `ON DELETE SET NULL` by při smazání
-- uživatele přepsal zapečetěné záznamy a ověření by je hlásilo jako pozměněné.
-- Bez cizího klíče zůstane ID autora v záznamu i poté, co uživatel zanikne.
ALTER TABLE activity_log
  DROP FOREIGN KEY IF EXISTS fk_al_user;
