(function () {
    'use strict';

    const actionUrl = window.LacrmEventsConfig.basePath + 'lacrm/events/action/';

    function range() {
        return { start: document.getElementById('rangeStart').value, end: document.getElementById('rangeEnd').value };
    }

    async function call(action, extra) {
        const response = await fetch(actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action: action, csrf_token: window.csrfToken }, range(), extra || {}))
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed');
        return result;
    }

    function show(message, kind) {
        const box = document.getElementById('importResult');
        box.className = 'alert alert-' + kind;
        box.style.whiteSpace = 'pre-line';
        box.textContent = message;
    }

    function row(label, value) {
        const wrap = document.createElement('div');
        wrap.className = 'row mb-2';
        const key = document.createElement('div');
        key.className = 'col-sm-3 text-muted';
        key.textContent = label;
        const val = document.createElement('div');
        val.className = 'col-sm-9';
        val.style.whiteSpace = 'pre-line';
        val.textContent = value;
        wrap.append(key, val);
        return wrap;
    }

    function attendeeText(attendee) {
        return typeof attendee === 'string' ? attendee : (attendee.Name || attendee.Email || JSON.stringify(attendee));
    }

    async function view(lacrmEventId) {
        try {
            const result = await call('get_event', { lacrm_event_id: lacrmEventId });
            const event = result.event;
            document.getElementById('eventModalTitle').textContent = event.name;

            const body = document.getElementById('eventModalBody');
            body.replaceChildren(
                row('When', event.start_date + (event.end_date !== event.start_date ? ' to ' + event.end_date : '') + (Number(event.is_all_day) ? ' (all day)' : '')),
                row('Location', event.location || '-'),
                row('Description', event.description || '-'),
                row('Recurring', Number(event.is_recurring) ? (event.recurrence_rule || 'Yes') : 'No'),
                row('Attendees', (event.attendees || []).map(attendeeText).join('\n') || '-'),
                row('Synced', event.synced_at)
            );

            new bootstrap.Modal(document.getElementById('eventModal')).show();
        } catch (error) {
            adminAlert(error.message);
        }
    }

    async function scan() {
        const button = document.getElementById('scanButton');
        const list = document.getElementById('newEvents');
        button.disabled = true;
        list.replaceChildren();
        show('Asking Less Annoying CRM...', 'info');

        try {
            const result = await call('scan');
            result.events.forEach(function (event) {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';
                const text = document.createElement('span');
                text.textContent = event.name + ' - ' + event.time;
                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'btn btn-sm btn-outline-success';
                add.textContent = 'Import';
                add.addEventListener('click', function () { importOne(event.lacrm_event_id, li, add); });
                li.append(text, add);
                list.appendChild(li);
            });
            show(result.events.length === 0 ? 'No new events in this range.' : result.events.length + ' new event' + (result.events.length === 1 ? '' : 's') + ' found.', 'info');
        } catch (error) {
            show(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    }

    async function importOne(lacrmEventId, li, button) {
        button.disabled = true;

        try {
            const result = await call('import_one', { lacrm_event_id: lacrmEventId });
            const ok = result.outcome === 'created' || result.outcome === 'updated';
            button.textContent = ok ? 'Imported' : 'Not imported (' + result.outcome + ')';
            if (ok) li.classList.add('list-group-item-success');
        } catch (error) {
            button.disabled = false;
            show(error.message, 'danger');
        }
    }

    async function importRange() {
        const button = document.getElementById('importRangeButton');
        button.disabled = true;
        show('Importing. This can take a minute...', 'info');

        try {
            const r = await call('import_range');
            const lines = [r.fetched + ' events found: ' + r.created + ' new, ' + r.updated + ' updated.'];
            if (r.skipped) lines.push(r.skipped + ' skipped (no id).');
            if (r.errors) lines.push(r.errors + ' could not be saved.');
            show(lines.join('\n') + '\nReload the page to see them in the list.', r.errors ? 'warning' : 'success');
            document.getElementById('newEvents').replaceChildren();
        } catch (error) {
            show(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.getElementById('importToggleButton');
        if (toggle) toggle.addEventListener('click', function () { document.getElementById('importCard').classList.toggle('d-none'); });

        const scanButton = document.getElementById('scanButton');
        if (scanButton) scanButton.addEventListener('click', scan);

        const rangeButton = document.getElementById('importRangeButton');
        if (rangeButton) rangeButton.addEventListener('click', importRange);

        // The View row action fires list:action with the row's id; the list's rows carry the LACRM event id by row index
        document.addEventListener('list:action', function (e) {
            if (e.detail.action !== 'view') return;
            const lacrmId = window.LacrmEventsConfig.events && window.LacrmEventsConfig.events[e.detail.id];
            if (lacrmId) view(lacrmId);
        });
    });
})();
