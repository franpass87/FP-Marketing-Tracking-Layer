# FP Tracking - Catalog Health

Guida operativa rapida per il controllo qualità del catalogo eventi.

## Dove si trova

- WordPress Admin -> `Impostazioni` -> `FP Tracking`
- Card: `Catalog Health`

## Cosa controlla

- coerenza tra `EVENTS` e `META_EVENT_MAP`
- coerenza tra `EVENTS` e `SERVER_SIDE_EVENTS`
- coerenza tra `EVENTS` e `REQUIRED_FIELDS`
- validità minima delle definizioni (chiavi, label/type, campi richiesti)

## Export JSON

La card espone il pulsante `Esporta Catalog Health JSON`.

Campi principali nel JSON:

- `generated_at`
- `plugin_version`
- `catalog_fingerprint_sha256`
- `catalog_health.healthy`
- `catalog_health.issues`

## Uso del fingerprint SHA256

Il campo `catalog_fingerprint_sha256` è un hash deterministico del catalogo:

- se due ambienti hanno lo stesso hash -> catalogo identico
- se hash diverso -> almeno una definizione evento/mapping/server-side/required fields è diversa

## Checklist pre-release

1. `healthy = true`
2. `issues` vuoto
3. hash salvato in report release
4. test runtime di almeno un evento per flusso critico:
   - lead (`generate_lead`)
   - purchase (`purchase`/`gift_voucher_purchased`)
   - discount (`discount_applied`)

