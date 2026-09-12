-- Historické sazby DPH 2004–2012 pro převod starých účetních agend
--
-- Číselník sazeb zná sazby od roku 2013 (0049). Doklady starších let (převod z jiného
-- účetního programu) nesou sazby 20/14 % (2010–2012), 19/9 % (2008–2009) a 19/5 %
-- (5/2004–2007) a bez nich je nejde uložit (purchase_invoice_items.vat_rate_id je NOT NULL).
--
-- Idempotentní: code je UNIQUE → ON DUPLICATE KEY UPDATE.

SET NAMES utf8mb4;

INSERT INTO vat_rates
    (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, valid_to, display_order)
VALUES
    ('CZ-20', 20.00, 'CZ', 'Základní 20 % (2012)',        'Standard 20 % (2012)',        0, 0, '2012-01-01', '2012-12-31', 23),
    ('CZ-14', 14.00, 'CZ', 'Snížená 14 % (2012)',         'Reduced 14 % (2012)',         0, 0, '2012-01-01', '2012-12-31', 24),
    ('CZ-20-2010', 20.00, 'CZ', 'Základní 20 % (2010–2011)', 'Standard 20 % (2010–2011)', 0, 0, '2010-01-01', '2011-12-31', 25),
    ('CZ-10-2010', 10.00, 'CZ', 'Snížená 10 % (2010–2011)',  'Reduced 10 % (2010–2011)',  0, 0, '2010-01-01', '2011-12-31', 26),
    ('CZ-19', 19.00, 'CZ', 'Základní 19 % (2004–2009)',   'Standard 19 % (2004–2009)',   0, 0, '2004-05-01', '2009-12-31', 27),
    ('CZ-9',   9.00, 'CZ', 'Snížená 9 % (2008–2009)',     'Reduced 9 % (2008–2009)',     0, 0, '2008-01-01', '2009-12-31', 28),
    ('CZ-5',   5.00, 'CZ', 'Snížená 5 % (2004–2007)',     'Reduced 5 % (2004–2007)',     0, 0, '2004-05-01', '2007-12-31', 29)
ON DUPLICATE KEY UPDATE
    rate_percent  = VALUES(rate_percent),
    valid_from    = VALUES(valid_from),
    valid_to      = VALUES(valid_to),
    label_cs      = VALUES(label_cs),
    label_en      = VALUES(label_en),
    display_order = VALUES(display_order);
