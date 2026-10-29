SELECT id, lacrm_event_id, name, description, location, start_date, end_date, is_all_day, is_recurring, lacrm_contact_ids, synced_at
FROM {TABLE}
WHERE deleted_at IS NULL AND start_date <= :range_end AND end_date >= :range_start
ORDER BY start_date ASC, id ASC
