# Changelog

All notable changes to FP Marketing Tracking Layer will be documented in this file.

## [1.2.31] - 2026-04-04

### Changed
- Bridge FP Experiences: `experience_paid` include **`items`** (righe `fp_experience_item`, prezzo unitario), **`coupon`** se presente, **`event_id`** con `uniqid` per coerenza con altri eventi.

## [1.2.30] - 2026-04-04

### Changed
- `EventSchema`: normalizzazione esplicita di `meal_label` e `price_per_person` negli eventi booking (dataLayer coerente con FP Restaurant).

## [1.2.29] - 2026-04-04

### Changed
- `fp-tracking.js`: listener `fpCtaBarClick` usa `detail.eventName` (validato) per il nome evento nel dataLayer, così coincide con l’impostazione FP CTA Bar (default `cta_bar_click`).

## [1.2.28] - 2026-04-04

### Changed
- README: checklist produzione (layer sempre attivo nello stack FP) e nota operativa su click client-only (GTM) vs enqueue server-side opzionale.

## [1.2.27] - 2026-04-04

### Added
- `fp_tracking_enqueue_server_event()`: accoda GA4 MP / Meta / Brevo da contesti senza `wp_footer` (beacon REST), con `event_id` allineato al client.
- Catalogo eventi: `form_payment_completed`, `cart_recovery`, `cart_recovery_email_sent`, `accrediti_request_*`; mapping Meta `Purchase` per `form_payment_completed`; export GTM aggiornato (incl. `cta_category`).

### Changed
- `fp-tracking.js`: inoltra `event_id` da `fpCtaBarClick` / `fpBioLinkClick` quando presente nel `detail`.

## [1.2.26] - 2026-03-24

### Added

- Impostazione **Tag transactional per sito** (`brevo_transactional_site_tag`) e inclusione in `fp_tracking_get_brevo_settings()['transactional_site_tag']`.
- `fp_tracking_brevo_resolve_transactional_site_tag()` e `fp_tracking_brevo_merge_transactional_tags()` per unificare i tag su `POST /v3/smtp/email` (FP Mail log via API, multi-sito su stesso account Brevo).
- Filtro `fp_tracking_brevo_transactional_payload`.

## [1.2.25] - 2026-03-24
### Added
- `fp_tracking_brevo_upsert_contact()`: upsert contatti `POST /v3/contacts` con la stessa API key del layer; filtro `fp_tracking_brevo_upsert_contact_body`; integrazione con `fp_tracking_get_brevo_list_id` se `listIds` vuoti.
- `BrevoClient::upsert_contact()` per l’invio HTTP centralizzato.
- Documentazione `docs/BREVO-CENTRAL-CONTACT-SYNC.md` (matrice plugin e fallback).

## [1.2.24] - 2026-03-24
### Added
- `fp-tracking.js`: ogni push client-side aggiunge `affiliation` (nome sito), `page_url` (URL corrente) se mancanti — utile per FP-Bio / FP-CTA-Bar e click vari.
- `fpTrackingConfig.siteName` in `wp_localize_script`.
- Bridge FP-Experiences (`WordPressIntegration`): `affiliation`, `page_url` completo (o `order->get_checkout_order_received_url()` quando c’è ordine), filtro `fp_tracking_experiences_bridge_params`.

## [1.2.23] - 2026-03-24
### Fixed
- Impostazioni: se Brevo API Key (e segreti GA4/Meta) arrivano vuoti al salvataggio, viene mantenuto il valore precedente — evita 401 "Key not found" dopo aver salvato senza reinserire la chiave.

## [1.2.22] - 2026-03-24
### Fixed
- Brevo: normalizzazione API key (trim spazi e virgolette) per test connessione e invio eventi.
- Brevo: messaggio HTTP 401 più esplicito (API key da SMTP & API > API Keys).

## [1.2.21] - 2026-03-23
### Changed
- Menu position 56.6 per ordine alfabetico FP.

## [1.2.20] - 2026-03-23
### Fixed
- Integrazioni: rilevamento automatico plugin FP attivi (Forms, Restaurant, Experiences, CTA Bar, Discount-Gift, Bio) tramite costanti/defined. Nessun plugin implementava il filtro `fp_tracking_registered_integrations`.

## [1.2.19] - 2026-03-23
### Changed
- Admin: sezione Brevo estratta da Impostazioni Avanzate e collocata in una card dedicata con badge stato.

## [1.2.18] - 2026-03-23
### Fixed
- BrevoMapper: supporto `user_data.email` come fallback oltre a `user_data.em` per identificazione contatto negli eventi.

## [1.2.17] - 2026-03-23
### Added
- Liste Brevo ITA e ENG centralizzate: campi `brevo_list_id_it` e `brevo_list_id_en` nella sezione Avanzate.
- Funzione helper `fp_tracking_get_brevo_settings()` per altri plugin FP (Forms, Restaurant, Experiences).
- Servizio `BrevoListsService` per caricare le liste da Brevo API e testare la connessione.
- AJAX `fp_tracking_load_brevo_lists` e `fp_tracking_test_brevo` nella pagina admin.
- Filtro `fp_tracking_brevo_settings` per override delle impostazioni centralizzate.

## [1.2.16] - 2026-03-22
### Fixed
- GTM export JSON: evitata la generazione di variabili costanti con valore vuoto (`FP - GA4 Measurement ID`, `FP - Google Ads ID`) usando fallback non vuoti, per superare la validazione GTM su `vendorTemplate.parameter.value`.

## [1.2.15] - 2026-03-22
### Fixed
- GTM export JSON: i tag Google Ads (`awct`) vengono generati solo quando `conversionLabel` è valorizzato, evitando l'errore import GTM "conversionLabel: Il valore non deve essere vuoto".

## [1.2.14] - 2026-03-22
### Fixed
- GTM export JSON: per i tag GA4 Event (`type: gaawe`) usato `measurementIdOverride` con valore esplicito del Measurement ID, evitando l'errore "vendorTemplate.parameter.measurementIdOverride: Il valore non deve essere vuoto" in import GTM.

## [1.2.13] - 2026-03-22
### Fixed
- GTM export JSON: rimossi i caratteri `:` e il simbolo di warning dai nomi generati di trigger/tag (`FP - Event`, `FP - GA4 Event`, `FP - Google Ads`, `FP - Meta`) per compatibilità con la validazione nomi in import GTM.

## [1.2.12] - 2026-03-22
### Fixed
- GTM export JSON: normalizzati i `Parameter.type` nel formato enum atteso da GTM import (`TEMPLATE`, `INTEGER`, `BOOLEAN`, `LIST`, `MAP`) per eliminare gli errori "Error deserializing enum type [Type]".

## [1.2.11] - 2026-03-22
### Fixed
- GTM export JSON: ripristinato `dataLayerVersion` come parametro `integer` e normalizzato `priority` del tag Consent Mode come parametro numerico con `key`, per evitare errori enum in import.

## [1.2.10] - 2026-03-22
### Changed
- GTM export JSON: sostituito il tipo parametro `integer` con `template` per `dataLayerVersion` e priorità tag Consent Mode, per compatibilità con l'import GTM.
- Google Ads Conversion Labels: aggiunti gli eventi `click_phone`, `click_email` e `click_whatsapp` nella configurazione admin e nell'export container.

## [1.2.9] - 2026-03-21
### Added
- Admin: card collassabili (toggle su ogni card); messaggio contestuale quando GA4 o Meta non configurati; tooltip sugli hint Rule Engine; esempi JSON ampliati negli hint.
### Changed
- Admin: hint Rule Engine con title per dettagli al passaggio del mouse; esempi più completi (rename, enrich, Brevo mapping).

## [1.2.8] - 2026-03-21
### Added
- Admin: indicatore setup (X/6) con barra di progresso; link Documentazione in header; pulsanti Copia per GTM ID, GA4 ID, Meta Pixel ID, Google Ads ID, Clarity ID; messaggio contestuale quando GTM non configurato.

## [1.2.7] - 2026-03-21
### Changed
- Admin: testi chiariti in ogni sezione — descrizioni card (Catalog Health, Queue Health, GA4, Meta, Export, Rule Engine, Validator, Consent, Integrazioni), intro sezioni e hint più chiari.

## [1.2.6] - 2026-03-21
### Changed
- Admin: nav rapida con jump-to-sezioni, accento laterale viola sugli header, grid 2 colonne per Rule Engine + Validator, empty state migliorato per Validator (nessun warning).
- Smooth scroll e scroll-margin per link interni; rispetto di prefers-reduced-motion.

## [1.2.5] - 2026-03-21
### Changed
- Admin: pagina riorganizzata con sezioni gerarchiche (Monitoraggio, Configurazione, Export, Regole/debug, Integrazioni) e grid 2 colonne per Catalog Health + Queue Health.

## [1.2.4] - 2026-03-19
### Changed
- Bridge `fpCtaBarClick` (FP-CTA-Bar): aggiunto parametro `cta_category` nel payload verso `dataLayer` (da `detail.category` / campo categoria admin).

## [1.2.3] - 2025-03-19
### Fixed
- Admin: `h1.screen-reader-text` come primo heading nel `.wrap` e titolo visibile nel banner come `h2` (compat notice iniettate in JS con `.wrap h1`); regole CSS su `.notice` figlie dirette del `.wrap`.

## [1.2.2] - 2025-03-19
### Fixed
- Admin CSS: rimosso flex/`order` su `#wpbody-content` che spostava le notice WordPress sotto la pagina; allineato a `fp-admin-ui-design-system.mdc`.

## [1.2.1] - 2026-03-18
### Changed
- Documentazione tecnica ampliata in `README.md` con sezioni su catalogo eventi centralizzato, Catalog Health, integrazione FP-Discount-Gift e checklist QA release.
- Aggiunta guida operativa dedicata `docs-catalog-health.md` con uso del fingerprint SHA256 e flusso pre-release.

## [1.2.0] - 2026-03-18
### Added
- Integrazione end-to-end eventi `FP-Discount-Gift` nel tracking layer (`discount_*`, `gift_voucher_*`) con supporto GTM/GA4 e coda server-side.
- Catalogo eventi centralizzato (`EventCatalog`) come single source of truth per eventi, mapping Meta, eligibility server-side e required fields.
- Nuova card admin **Catalog Health** con controlli automatici di coerenza e export JSON.
- Fingerprint `SHA256` del catalogo nell'export Catalog Health per confronto veloce tra ambienti.
### Changed
- Refactor UI admin: rimossi stili inline da `Settings.php` e sostituiti con classi CSS dedicate nel design system FP.
- Mapping Meta aggiornato con supporto `gift_voucher_purchased` su dispatcher server-side e export GTM.
### Fixed
- Ridotto rischio di divergenza tra componenti (GTMExporter/Dispatcher/Validator/DataLayerManager) centralizzando definizioni evento.
- Validata pipeline runtime `fp_tracking_event -> queue -> worker` per eventi discount/gift senza warning validator.

## [1.1.1] - 2026-03-13
### Changed
- Integrazione Brevo allineata alla documentazione ufficiale corrente: endpoint `POST https://api.brevo.com/v3/events`.
- Payload Brevo aggiornato a `event_name`, `identifiers`, `event_properties`, `contact_properties`.
- Endpoint default Brevo in admin aggiornato a `https://api.brevo.com/v3/events`.
### Fixed
- Rimossa incongruenza autenticazione legacy (`trackEvent`) usando ora `api-key` sulla Events API v3 ufficiale.

## [1.1.0] - 2026-03-13
### Added
- Queue persistente server-side (`pending/processing/failed/dead`) con worker cron e retry/backoff.
- Dashboard admin "Queue Health" con metriche 24h/7d e replay manuale eventi falliti/dead.
- Rule Engine no-code (disable/rename/enrich), Event Validator, Event Inspector PII-safe.
- Export/Import mapping configurazione e Consent Audit trail aggregato.
- Nuovo canale server-side Brevo (client/mapper/dispatcher) integrato al dispatcher centrale.
### Changed
- Dispatcher server-side esteso con esito strutturato (`dispatch_with_result`) per supportare queue worker.
- `fp_tracking_server_side` ora instrada in coda con fallback a dispatch diretto in caso di errore enqueue.
### Fixed
- Rilascio lock worker su retry/dead per evitare blocco eventi in coda.

## [1.0.4] - 2026-03-12
### Changed
- Vendor incluso nel repository (rimosso da .gitignore)
- Check vendor mancante con admin notice invece di fatal
### Fixed
- Fatal "Class FPTracking\Core\Plugin not found" quando vendor non deployato

## [1.0.3] - 2026-03-12
### Changed
- Require PHP 8.1+ (plugin usa `readonly`, introdotto in PHP 8.1)

## [1.0.2] - 2026-03-12
### Fixed
- Rimosso trailing comma in array `$server_side_events` (DataLayerManager) per compatibilità PHP

## [1.0.1] - 2026-03-09
### Changed
- Sync README, composer, Plugin, GTM/Events

## [1.0.0] - 2026-03-08
### Added
- Initial release
- Centralized marketing tracking layer
- GTM injection and Consent Mode v2 support
- Event routing from FP plugins to `window.dataLayer`
- Server-side events to GA4 Measurement Protocol
- Meta Conversions API integration
- PSR-4 autoloading via Composer
s API integration
- PSR-4 autoloading via Composer

s API integration
- PSR-4 autoloading via Composer
r

s API integration
- PSR-4 autoloading via Composer
er
r

s API integration
- PSR-4 autoloading via Composer
ser
er
r

s API integration
- PSR-4 autoloading via Composer
oser
ser
er
r

s API integration
- PSR-4 autoloading via Composer
poser
oser
ser
er
r

s API integration
- PSR-4 autoloading via Composer
mposer
poser
oser
ser
er
r

s API integration
- PSR-4 autoloading via Composer
omposer
mposer
poser
oser
ser
er
r

s API integration
- PSR-4 autoloading via Composer
