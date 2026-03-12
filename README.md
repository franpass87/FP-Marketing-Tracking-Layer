# FP Marketing Tracking Layer

Layer centralizzato per il tracking marketing. Inietta GTM, gestisce Consent Mode v2, riceve eventi da tutti i plugin FP e li instrada verso GA4 Measurement Protocol e Meta Conversions API (server-side).

[![Version](https://img.shields.io/badge/version-1.0.2-blue.svg)](https://github.com/franpass87/FP-Marketing-Tracking-Layer)
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
- **UTM Cookie Handler**: salvataggio e lettura parametri UTM per attribuzione

### Configurazione
1. Vai su **Impostazioni → FP Tracking** nel pannello WordPress
2. Inserisci il **GTM Container ID** (es. `GTM-XXXXXXX`)
3. Configura **GA4 Measurement ID** e **API Secret** per eventi server-side
4. Configura **Meta Pixel ID** e **Access Token** per Conversions API
5. Imposta le preferenze Consent Mode

### Requisiti
- WordPress 6.0+
- PHP 8.0+

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
│   ├── Attribution/
│   │   └── UTMCookieHandler.php        # Gestione cookie UTM
│   ├── Admin/
│   │   ├── Settings.php                # Pagina impostazioni
│   │   └── GTMExporter.php             # Export configurazione GTM
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
        transaction_id: 'ORDER-123',
        value: 49.00,
        currency: 'EUR',
        items: [{ item_id: '1', item_name: 'Tour Roma', price: 49.00 }]
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
| `fp_tracking_event` | action | Invia un evento tracking (PHP → server-side) |
| `fp_tracking_before_push` | filter | Modifica dati evento prima del push al dataLayer |
| `fp_tracking_gtm_config` | filter | Modifica configurazione GTM |
| `fp_tracking_consent_defaults` | filter | Modifica consensi default Consent Mode v2 |

### REST Endpoints
| Endpoint | Metodo | Descrizione |
|----------|--------|-------------|
| `/wp-json/fp-tracking/v1/event` | POST | Riceve eventi dal frontend per invio server-side |

---

## Changelog
Vedi [CHANGELOG.md](CHANGELOG.md)
---

## Autore

**Francesco Passeri**
- Sito: [francescopasseri.com](https://francescopasseri.com)
- Email: [info@francescopasseri.com](mailto:info@francescopasseri.com)
- GitHub: [github.com/franpass87](https://github.com/franpass87)
