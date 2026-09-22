-- Mahnwesen 1.3.1 (#32): the stages belong to a dunning profile. Dolibarr runs
-- this file on every activation and ignores columns and keys that exist already
-- or are gone already. The module moves the stages of earlier versions into the
-- default profile when it is activated.
ALTER TABLE llx_mahnwesen_rule ADD COLUMN fk_profile INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE llx_mahnwesen_rule DROP INDEX uk_mahnwesen_rule_code;
ALTER TABLE llx_mahnwesen_rule ADD UNIQUE INDEX uk_mahnwesen_rule_profile (entity, fk_profile, level);
