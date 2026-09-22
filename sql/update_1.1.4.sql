-- Mahnwesen 1.1.4 (#20): one place for the settings of a stage, large mail bodies,
-- timestamps that follow changes. Dolibarr runs this file on every activation
-- and ignores columns that exist already or are gone already.
ALTER TABLE llx_mahnwesen_rule ADD COLUMN payment_days INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE llx_mahnwesen_rule DROP COLUMN minimum_amount;
ALTER TABLE llx_mahnwesen_rule DROP COLUMN generate_pdf;
ALTER TABLE llx_mahnwesen_attempt MODIFY body_html MEDIUMTEXT;
ALTER TABLE llx_mahnwesen_attempt MODIFY tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE llx_mahnwesen_case MODIFY tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE llx_mahnwesen_fee MODIFY tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE llx_mahnwesen_pause MODIFY tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE llx_mahnwesen_rule MODIFY tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
