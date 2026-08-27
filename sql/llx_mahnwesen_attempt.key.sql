ALTER TABLE llx_mahnwesen_attempt ADD INDEX idx_mahnwesen_attempt_case (entity, fk_case, level);
ALTER TABLE llx_mahnwesen_attempt ADD INDEX idx_mahnwesen_attempt_status (entity, status, reserved_at);
ALTER TABLE llx_mahnwesen_attempt ADD INDEX idx_mahnwesen_attempt_invoice (entity, fk_facture, reserved_at);
