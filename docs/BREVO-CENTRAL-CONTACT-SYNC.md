# Sincronizzazione contatti Brevo centralizzata (FP Tracking)

## Scopo

L’HTTP `POST https://api.brevo.com/v3/contacts` per creare/aggiornare contatti non deve essere duplicato in ogni plugin FP. Quando **Brevo è abilitato** in **FP Marketing Tracking Layer** (API key valida + toggle attivo), i plugin consumatori usano la funzione globale `fp_tracking_brevo_upsert_contact()` che delega a `FPTracking\Brevo\BrevoClient::upsert_contact()`.

## API pubblica

### `fp_tracking_brevo_upsert_contact( array $body, string $list_source = '', string $language = 'it' ): array`

- **`$body`**: payload Brevo (`email` obbligatoria, opzionalmente `attributes`, `listIds`, `tags`, `updateEnabled`, …). `email` viene sanificata con `sanitize_email`.
- **`$list_source`**: se `listIds` è assente o vuoto dopo la normalizzazione, viene chiamato `fp_tracking_get_brevo_list_id( $list_source, $language )` per compilare una lista (es. `forms`, `restaurant`, `experiences`).
- **`$language`**: `it` o `en` per la risoluzione lista.

**Ritorno:** `['success' => bool, 'code' => int, 'message' => string, 'contact_id' => int|null]`

### Filtro

- **`fp_tracking_brevo_upsert_contact_body`**: `(array $body, string $list_source, string $language)` — modifica il payload prima dell’invio.

## Plugin integrati

| Plugin | `list_source` | Note |
|--------|---------------|------|
| FP Restaurant Reservations | `restaurant` | Upsert contatti quando Brevo è attivo nel ristorante; con Tracking attivo l’HTTP usa la chiave del layer. `isEnabled()` è true anche senza API key locale se Tracking Brevo è abilitato. |
| FP Experiences | `experiences` | Stesso modello; `is_enabled()` considera Tracking se manca la chiave in `fp_exp_brevo`. Template transazionali e `/v3/events` restano sulla chiave locale finché configurata. |
| FP Forms | `forms` | `create_or_update_contact()` usa il layer quando Tracking Brevo è enabled; altrimenti fallback a `make_request` (chiave da Tracking settings o legacy `fp_forms_brevo_settings`). |

## Comportamento di fallback

- Se **Tracking Brevo non è abilitato** o la funzione non esiste, ogni plugin mantiene il proprio flusso precedente (chiave e `wp_remote_post` / client dedicato).
- Non viene eseguito un doppio invio: o percorso centralizzato o percorso legacy, in base a `fp_tracking_get_brevo_settings()['enabled']`.

## Configurazione

Liste per provenienza e lingua: impostazioni Brevo in FP Tracking (`fp_tracking_get_brevo_list_id`, `source_lists`).
