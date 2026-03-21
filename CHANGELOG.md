# Changelog

All notable changes to FP Marketing Tracking Layer will be documented in this file.

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
