INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'other_items',
       CASE WHEN r.system_key = 'readonly' THEN 1 ELSE 2 END
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant', 'readonly');
