# Less Annoying CRM Service

Status (2026-10-29): **v0.4.0, installed on dev**, id `osmium-lacrm` (repo `Osmium-Services/osmium-lacrm`). Connects an Osmium
site to [Less Annoying CRM](https://www.lessannoyingcrm.com). It is a plain connection: it knows nothing about Trade or any other
service, and other services listen to its hooks (see [[Hooks Reference]]). Part of [[Osmium Architecture]]. Its main consumer is
[[Trade Accounts Service (design)|Trade Accounts]], whose LACRM adapter lives in Trade, not here.

## What it does

- **API connection:** each site enters its own API key under Settings > Less Annoying CRM (no key ships with the service), with a
  Test connection button and a List pipelines button. It never calls the real LACRM beyond what a person presses.
- **Webhook receiver** at `/api/lacrm-webhook/`, checked with LACRM's HMAC signature. *Register webhooks* saves the secret LACRM
  hands back, but only for five minutes after the button is pressed.
- **Hooks fired** (`dispatch`, payload includes `dataSource`): `lacrm.form.submitted`, `lacrm.sale.won`, `lacrm.sale.lost` (only for
  the pipeline and Won/Lost status IDs set in settings; blank means never) and `lacrm.contact.updated`.
- **Hooks answered** (`handle`, null when the connection is off): `lacrm.contact.get`, `lacrm.contacts.get_many`,
  `lacrm.contact.linked_people`, `lacrm.sales.won`, `lacrm.sales.lost`.

## Decided

- **No calendar copy.** A copy of the LACRM calendar events was built (0.3.x) and then removed in 0.4.0 at Gavin's call: the website
  does not need a copy, and SolarGo2's Shows are independent of those events. Migration `002_drop_lacrm_events.sql` drops the table;
  the webhook no longer registers `Event.*`. Do not re-propose it.
- **HubSpot is parked** until a client needs it. The research is in memory, with two unverified gaps: the won-deal `propertyValue`
  format and how a deal event links to a contact. A new source needs only to answer `trade.sources` and fire the neutral `trade.*`
  events, or be added as an adapter in Trade.

## Not yet verified

Real signed webhooks (they need a public address; dev has none) and the real theme views that sit on top of Trade.

## Gotchas

- Another service lists the hooks it wants in its own `service.json`; nothing here calls it.
- A site's CRM data is the site owner's decision (for example two customers sharing one email at ML Industries and AG Campers).
