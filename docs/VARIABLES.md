# Mahnwesen template variables

Mahnwesen templates can use normal Dolibarr email substitutions plus module-specific values.

## Preferred Mahnwesen variables

| Variable | Meaning |
|---|---|
| `__MAHNWESEN_STAGE__` | rendered dunning stage |
| `__MAHNWESEN_OPEN_AMOUNT__` | remaining invoice amount |
| `__MAHNWESEN_FEE__` | configured dunning fee |
| `__MAHNWESEN_TOTAL__` | open amount + dunning fee |
| `__MAHNWESEN_CUSTOMER_CLASS__` | private/business/unknown classification |
| `__MAHNWESEN_NEXT_STAGE_DATE__` | next threshold date |
| `__MAHNWESEN_FEE_PARAGRAPH__` | optional rendered fee paragraph |
| `__MAHNWESEN_PAYMENT_DEADLINE__` | payment deadline: the day the notice is written plus the stage's payment period, in the customer's date format; empty when the stage has no period |
| `__MAHNWESEN_PAYMENT_DAYS__` | the stage's payment period in days; empty when the stage has none |

The payment period is set per stage under *Setup > Stages & fees* (0 = none, the default). The dunning PDF shows the same deadline below the total, the history of a sent notice records it, and the next stage becomes due no earlier than the day after it. `__MAHNWESEN_NEXT_STAGE_DATE__` takes that into account.

## Compatibility aliases

The current renderer also supports aliases such as:

- `{INVOICE_REF}`
- `{CUSTOMER_NAME}`
- `{INVOICE_DATE}`
- `{DUE_DATE}`
- `{OPEN_AMOUNT}`
- `{DUNNING_FEE}`
- `{DUNNING_TOTAL}`
- `{DUNNING_STAGE}`
- `{TODAY}`
- `{COMPANY_NAME}`
- `{NEXT_STAGE_DATE}`
- `{FEE_PARAGRAPH}`

## Guidance

For new templates, prefer the `__MAHNWESEN_*__` form because it is visually distinct from normal text and aligns better with Dolibarr-style substitution variables.

Template variables must always be rendered from current invoice/case data at preview/send time; stored HTML should not contain customer-specific values.
