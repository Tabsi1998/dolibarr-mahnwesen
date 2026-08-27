ALTER TABLE llx_mahnwesen_attempt_file ADD INDEX idx_mahnwesen_attempt_file_attempt (entity, fk_attempt, file_role);
ALTER TABLE llx_mahnwesen_attempt_file ADD INDEX idx_mahnwesen_attempt_file_name (entity, fk_attempt, display_name);
