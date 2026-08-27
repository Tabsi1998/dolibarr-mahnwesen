ALTER TABLE llx_mahnwesen_pause ADD INDEX idx_mahnwesen_pause_case (entity, fk_case, status);
ALTER TABLE llx_mahnwesen_pause ADD INDEX idx_mahnwesen_pause_due (entity, status, pause_until);
