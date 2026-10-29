UPDATE {TABLE} SET deleted_at = NOW() WHERE lacrm_event_id = :lacrm_event_id AND deleted_at IS NULL
