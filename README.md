# FP Marketing Tracking Layer

Layer centralizzato per il tracking marketing. Inietta GTM, gestisce Consent Mode v2, riceve eventi da tutti i plugin FP e li instrada verso GA4 Measurement Protocol e Meta Conversions API (server-side).

[![Version](https://img.shields.io/badge/version-1.2.0-blue.svg)](https://github.com/franpass87/FP-Marketing-Tracking-Layer)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)]()

---

## Per l'utente

### Cosa fa
FP Marketing Tracking Layer è il punto centrale di raccolta e distribuzione degli eventi di tracking per tutti i plugin FP. Invece di avere GTM/Meta/GA4 configurati separatamente in ogni plugin, questo plugin gestisce tutto in un unico posto.

### Funzionalità principali
- **GTM**: iniezione snippet GTM in `<head>` e `<body>` con supporto Consent Mode v2
- **Consent Mode v2**: gestione consensi Google (analytics_storage, ad_storage, ecc.)
- **dataLayer**: routing centralizzato di tutti gli eventi verso `window.dataLayer`
- **GA4 Measurement Protocol**: invio eventi server-side a Google Analytics 4
- **Meta Conversions API**: invio eventi server-side a Meta (Facebook/Instagram)
- **Brevo Event Bridge**: invio eventi server-side a Brevo Events API v3 (`/v3/events`) (opzionale)
- **UTM Cookie Handler**: salvataggio e lettura parametri UTM per attribuzione
- **Queue affidabile**: coda persistente con retry/backoff, replay e health metrics
- **Rule Engine + Inspector**: regole no-code eventi, validator e audit operativo
- **Catalogo eventi centralizzato**: sorgente unica per eventi, mapping Meta, required fields e server-side eligibility
- **Catalog Health**: pannello admin di coerenza catalogo + export JSON con fingerprint SHA256 per QA

### Configurazione
1. Vai su **Impostazioni → FP Tracking** nel pannello WordPress
2. Inserisci il **GTM Container ID** (es. `GTM-XXXXXXX`)
3. Configura **GA4 Measurement ID** e **API Secret** per eventi server-side
4. Configura **Meta Pixel ID** e **Access Token** per Conversions API
5. (Opzionale) attiva **Brevo Server-Side** e inserisci **Brevo API Key**
6. Imposta le preferenze Consent Mode

### Nota importante: browser vs server-side
- Inserendo **GA4 Measurement ID** e/o **Meta Pixel ID**, il file JSON esportato da GTM viene popolato con questi valori.
- Gli eventi client-side avvengono nel browser tramite `window.dataLayer` -> GTM (quindi funzionano anche senza canale server-side).
- **GA4 API Secret** e **Meta Access Token** servono solo per l'invio server-side (Measurement Protocol / CAPI).
- Per Brevo, il plugin usa endpoint ufficiale `https://api.brevo.com/v3/events` con header `api-key`.

### Requisiti
- WordPress 6.0+
- PHP 8.1+

---

## Per lo sviluppatore

### Struttura
```
FP-Marketing-Tracking-Layer/
├── fp-marketing-tracking-layer.php     # File principale
├── src/
│   ├── Core/Plugin.php                 # Bootstrap e DI
│   ├── GTM/
│   │   ├── GtmSnippet.php              # Iniezione snippet GTM
│   │   └── ClaritySnippet.php          # Microsoft Clarity
│   ├── Consent/
│   │   └── ConsentBridge.php           # Gestione Consent Mode v2
│   ├── DataLayer/
│   │   ├── DataLayerManager.php        # Gestione window.dataLayer
│   │   └── EventSchema.php             # Schema validazione eventi
│   ├── Events/
│   │   ├── BaseEvent.php               # Classe base evento
│   │   ├── PageViewEvent.php
│   │   ├── PurchaseEvent.php
│   │   ├── LeadEvent.php
│   │   ├── BookingEvent.php
│   │   └── ClickEvent.php
│   ├── ServerSide/
│   │   ├── GA4MeasurementProtocol.php  # Invio eventi a GA4
│   │   ├── MetaConversionsAPI.php      # Invio eventi a Meta
│   │   └── ServerSideDispatcher.php    # Dispatcher server-side
│   ├── Brevo/
│   │   ├── BrevoClient.php
│   │   ├── BrevoMapper.php
│   │   └── BrevoDispatcher.php
│   ├── Queue/
│   │   ├── EventQueueRepository.php
│   │   ├── QueueWorker.php
│   │   └── RetryPolicy.php
│   ├── Health/
│   │   └── EventHealthService.php
│   ├── Rules/
│   │   └── EventRuleEngine.php
│   ├── Validation/
│   │   └── EventValidator.php
│   ├── Inspector/
│   │   └── EventInspector.php
│   ├── Audit/
│   │   └── ConsentAuditService.php
│   ├── Attribution/
│   │   └── UTMCookieHandler.php        # Gestione cookie UTM
│   ├── Admin/
│   │   ├── Settings.php                # Pagina impostazioni
│   │   ├── GTMExporter.php             # Export configurazione GTM
│   │   └── MappingManager.php          # Export/import mapping
│   └── Integrations/
│       ├── WordPressIntegration.php    # Hook WordPress nativi
│       └── WooCommerceIntegration.php  # Hook WooCommerce
├── assets/
│   ├── css/admin.css
│   └── js/fp-tracking.js              # Script frontend tracking
└── vendor/
```

### Come integrarsi (per altri plugin FP)
I plugin FP non chiamano direttamente GTM/GA4/Meta. Emettono un `CustomEvent` JavaScript che viene intercettato da `fp-tracking.js`:

```javascript
// Emetti un evento dal tuo plugin
document.dispatchEvent(new CustomEvent('fpTrackingEvent', {
    detail: {
        event: 'purchase',
        params: {
            transaction_id: 'ORDER-123',
            value: 49.00,
            currency: 'EUR',
            items: [{ item_id: '1', item_name: 'Tour Roma', price: 49.00 }]
        }
    }
}));
```

Oppure via hook PHP per eventi server-side:

```php
do_action('fp_tracking_event', 'purchase', [
    'transaction_id' => 'ORDER-123',
    'value'          => 49.00,
    'currency'       => 'EUR',
]);
```

### Flusso evento (in breve)
1. Un plugin FP emette `do_action('fp_tracking_event', $event, $params)` (oppure `CustomEvent` frontend).
2. `DataLayerManager` normalizza il payload e lo accoda.
3. Nel browser, gli eventi vengono pushati su `window.dataLayer` e gestiti da GTM (client-side).
4. Se evento/idoneo e canale attivo, parte anche il dispatch server-side verso GA4 MP e/o Meta CAPI.

### Consent Mode v2
Il plugin gestisce automaticamente i consensi. Prima che l'utente esprima preferenze, tutti i tag GTM sono in stato `denied`. Dopo il consenso tramite FP Privacy and Cookie Policy, il Bridge aggiorna lo stato:

```javascript
// Aggiornamento automatico dopo consenso
gtag('consent', 'update', {
    'analytics_storage': 'granted',
    'ad_storage': 'granted'
});
```

### Hooks disponibili
| Hook | Tipo | Descrizione |
|------|------|-------------|
| `fp_tracking_event` | action | Event bus centrale: accoda evento per dataLayer e canale server-side (se abilitato) |
| `fp_tracking_server_side` | action | Trigger interno per dispatch server-side (GA4 MP / Meta CAPI) |
| `fp_tracking_event_payload` | filter | Modifica il payload evento normalizzato prima del push in coda |
| `fp_tracking_server_side_enabled` | filter | Abilita/disabilita invio server-side per singolo evento |
| `fp_tracking_meta_test_event_code` | filter | Imposta `test_event_code` per debug Meta CAPI |
| `fp_tracking_registered_integrations` | filter | Popola l'elenco integrazioni mostrato in admin |
| `fp_tracking_queue_worker` | action | Hook cron worker coda server-side |
| `fp_tracking_queue_heartbeat` | action | Hook cron heartbeat salute coda |

### REST Endpoints
Attualmente il plugin non espone endpoint REST pubblici dedicati.

---

## Changelog
Vedi [CHANGELOG.md](CHANGELOG.md)
---

## Autore

**Francesco Passeri**
- Sito: [francescopasseri.com](https://francescopasseri.com)
- Email: [info@francescopasseri.com](mailto:info@francescopasseri.com)
- GitHub: [github.com/franpass87](https://github.com/franpass87)
