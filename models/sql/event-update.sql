UPDATE {TABLE} SET
    name = :name,
    description = :description,
    location = :location,
    start_date = :start_date,
    end_date = :end_date,
    is_all_day = :is_all_day,
    is_recurring = :is_recurring,
    recurrence_rule = :recurrence_rule,
    recurrence_parent_id = :recurrence_parent_id,
    series_number = :series_number,
    lacrm_user_ids = :lacrm_user_ids,
    lacrm_contact_ids = :lacrm_contact_ids,
    attendees = :attendees,
    raw_payload = :raw_payload,
    deleted_at = NULL
WHERE id = :id
