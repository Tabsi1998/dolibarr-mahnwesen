-- Mahnwesen 1.4.0 (#36): a case that still has to be re-evaluated after a
-- payment was removed. Dolibarr runs this file on every activation and
-- ignores a column that exists already.
ALTER TABLE llx_mahnwesen_case ADD COLUMN recheck INTEGER DEFAULT 0 NOT NULL;
