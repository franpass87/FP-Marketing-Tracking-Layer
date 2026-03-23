# Changelog

All notable changes to FP Marketing Tracking Layer will be documented in this file.

## [1.2.20] - 2026-03-22
### Fixed
- `error_log` GA4 Measurement Protocol condizionato anche a WP_DEBUG oltre a debug_mode (no-debug-in-production).

## [1.2.19] - 2026-03-23
### Added
- Google Ads conversion: `gift_voucher_purchased` e `gift_card_redeemed` in ADS_EVENTS (tag awct con value/currency).

## [1.2.18] - 2026-03-23
### Added
- Eventi gift card FP-Discount-Gift nel catalogo: `gift_card_applied`, `gift_card_redeemed`, `gift_card_removed`, `gift_card_issued`, `gift_card_expiring_soon`, `gift_card_expired`.
- Variabili GTM `gift_card_code`, `gift_card_id`, `expires_at`, `remaining_balance` per parametri eventi gift card.
- Mapping Meta Purchase per `gift_card_redeemed` (evento con value).

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
