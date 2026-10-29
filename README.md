# Less Annoying CRM

Connects an Osmium site to [Less Annoying CRM](https://www.lessannoyingcrm.com). No keys ship with the service: each site enters its own API key under Settings > Less Annoying CRM.

It does three things and knows nothing about any other service:

- **API connection** with a Test connection button and a List pipelines button.
- **Webhook receiver** at `/api/lacrm-webhook/`, checked with LACRM's HMAC signature. Register webhooks saves the secret LACRM hands back, but only for five minutes after the button is pressed.

## Hooks it fires (`dispatch`; payload always includes `dataSource`)

| Hook | Payload |
|---|---|
| `lacrm.form.submitted` | `formId`, `fields`, `submission`, `raw` (the whole payload). A Form ID in settings limits it to that form |
| `lacrm.sale.won`, `lacrm.sale.lost` | `contactId`, `item`. Only for the pipeline and Won/Lost status IDs set in settings; blank means never |
| `lacrm.contact.updated` | `contacts` (the LACRM contact objects) |

## Hooks it answers (`handle`; null when the connection is off)

| Hook | Payload | Returns |
|---|---|---|
| `lacrm.contact.get` | `contactId` | the LACRM contact |
| `lacrm.contacts.get_many` | `contactIds` | `{results, errors}` keyed by id |
| `lacrm.contact.linked_people` | `companyId`, `companyName` | people linked to that company |
| `lacrm.sales.won`, `lacrm.sales.lost` | none | pipeline items at the configured status |

Another service lists the hooks it wants in its own `service.json`; nothing here calls it.
