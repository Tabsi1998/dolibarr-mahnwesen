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
