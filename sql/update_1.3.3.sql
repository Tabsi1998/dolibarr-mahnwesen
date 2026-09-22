-- Mahnwesen 1.3.3 (#34): open fees and interest can be put on their own
-- invoice. Dolibarr runs this file on every activation and ignores a column
-- that exists already.
ALTER TABLE llx_mahnwesen_fee ADD COLUMN fk_claim_invoice INTEGER DEFAULT 0 NOT NULL;
