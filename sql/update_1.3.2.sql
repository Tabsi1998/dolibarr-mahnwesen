-- Mahnwesen 1.3.2 (#33): late-payment interest per dunning profile. Dolibarr
-- runs this file on every activation and ignores columns that exist already.
ALTER TABLE llx_mahnwesen_profile ADD COLUMN interest_mode VARCHAR(16) DEFAULT 'none' NOT NULL;
ALTER TABLE llx_mahnwesen_profile ADD COLUMN interest_rate DOUBLE(24,8) DEFAULT 0 NOT NULL;
ALTER TABLE llx_mahnwesen_fee ADD COLUMN kind VARCHAR(16) DEFAULT 'fee' NOT NULL;
ALTER TABLE llx_mahnwesen_attempt ADD COLUMN amount_interest DOUBLE(24,8) DEFAULT 0 NOT NULL;
