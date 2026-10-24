# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.1.0] - 2026-10-24

### Added
- Contract of the order file: UTF-8, `;` separator, header, one line per order line, amounts in the minor unit, one uniquely named file per batch.
- Export of the pending `order.placed` events of the webhook outbox (types and batch size configurable) with the command `tmonier:erp-flatfile:export-orders`.
- Atomic deposit in the exchange directory (provisional file in `.incoming`, then rename): a reader never sees a partial file.
- Journal of the exported events (`tmonier_erp_flatfile_exported_event`, the event id is the primary key): an event is exported once; an unavailable directory leaves no file and the events stay to export.

[Unreleased]: https://github.com/thibmonier/sylius-erp-flatfile-plugin/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/thibmonier/sylius-erp-flatfile-plugin/releases/tag/v0.1.0
