ALTER TABLE llx_mahnwesen_fee ADD UNIQUE INDEX uk_mahnwesen_fee_attempt (entity, fk_attempt);
ALTER TABLE llx_mahnwesen_fee ADD INDEX idx_mahnwesen_fee_case (entity, fk_case, status);
ALTER TABLE llx_mahnwesen_fee ADD INDEX idx_mahnwesen_fee_invoice (entity, fk_facture, status);
