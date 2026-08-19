ALTER TABLE llx_mahnwesen_case ADD UNIQUE INDEX uk_mahnwesen_case_invoice (entity, fk_facture);
ALTER TABLE llx_mahnwesen_case ADD INDEX idx_mahnwesen_case_status (entity, status, paused);
ALTER TABLE llx_mahnwesen_case ADD INDEX idx_mahnwesen_case_next (entity, next_action_at);
