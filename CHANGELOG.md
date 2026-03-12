# Changelog

All notable changes to FP Marketing Tracking Layer will be documented in this file.

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
