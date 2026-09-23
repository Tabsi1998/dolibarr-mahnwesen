-- Mahnwesen 1.4.2 (#59): every change of a case counts up its revision, so
-- an event names exactly one transition. Dolibarr runs this file on every
-- activation and ignores a column that exists already.
ALTER TABLE llx_mahnwesen_case ADD COLUMN revision INTEGER DEFAULT 0 NOT NULL;
