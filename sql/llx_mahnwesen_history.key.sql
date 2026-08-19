ALTER TABLE llx_mahnwesen_history ADD INDEX idx_mahnwesen_history_invoice (entity, fk_facture, date_creation);
ALTER TABLE llx_mahnwesen_history ADD INDEX idx_mahnwesen_history_case (entity, fk_case);
