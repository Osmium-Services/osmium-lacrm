INSERT INTO {TABLE} (
    lacrm_event_id, name, description, location, start_date, end_date,
    is_all_day, is_recurring, recurrence_rule, recurrence_parent_id, series_number,
    lacrm_user_ids, lacrm_contact_ids, attendees, raw_payload
) VALUES (
    :lacrm_event_id, :name, :description, :location, :start_date, :end_date,
    :is_all_day, :is_recurring, :recurrence_rule, :recurrence_parent_id, :series_number,
    :lacrm_user_ids, :lacrm_contact_ids, :attendees, :raw_payload
)
