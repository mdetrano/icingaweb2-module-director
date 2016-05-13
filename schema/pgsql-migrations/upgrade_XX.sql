ALTER TABLE sync_rule
  ADD purge_filter_expression TEXT DEFAULT NULL;

-- INSERT INTO director_schema_migration
--   (schema_version, migration_time)
--   VALUES (XX, NOW());
