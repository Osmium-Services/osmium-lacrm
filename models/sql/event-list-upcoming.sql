SELECT id, lacrm_event_id, name, description, location, start_date, end_date, is_all_day
FROM {TABLE}
WHERE deleted_at IS NULL AND end_date >= NOW()
ORDER BY start_date ASC
LIMIT :row_limit
