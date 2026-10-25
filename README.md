# Sylius ERP flat-file plugin

[Version française](README.fr.md)

Flat-file exchange with an ERP for Sylius 2. This first slice (version 0.x) **exports the orders** as CSV files, built from the events of [`tmonier/sylius-webhook-outbox-plugin`](https://github.com/thibmonier/sylius-webhook-outbox-plugin), according to a documented contract, and **deposits them atomically** in an exchange directory that your ERP reads.

**It is a neutral contract, not a connector.** It connects to no ERP and names none: an ERP, or the integrator who connects it, reads the files from the directory. Importing prices and stock is a later slice.

## What it does

- A console command takes the pending `order.placed` events of the outbox and writes **one file per batch** in the exchange directory.
- The file is derived from the events, **not from a second read of the shop tables**: the outbox stays the single source of truth. The webhook outbox plugin is not modified.
- The file is written in the `.incoming` subdirectory then renamed: a reader of the exchange directory **never sees a partial file**.
- Each event is exported **once**: the id of the event (the `webhook_id` of the outbox) is the primary key of a journal table, so two exports running at the same time cannot write the same order twice.
- If the directory is missing, not writable or full, **no file is left behind**, the error is logged with a clear message, the command fails and the events **stay to export**.
- Events erased for the GDPR by the outbox plugin (`anonymized_at`) are left out.

## Requirements

- PHP 8.2 or later
- Sylius 2.2 or 2.3 (Symfony 6.4, 7.4 or 8.0)
- `tmonier/sylius-webhook-outbox-plugin` ^1.0, installed and configured

## Installation

```bash
composer require tmonier/sylius-erp-flatfile-plugin
```

Register the bundles in `config/bundles.php` (the outbox plugin first):

```php
return [
    // ...
    Tmonier\SyliusWebhookOutboxPlugin\TmonierSyliusWebhookOutboxPlugin::class => ['all' => true],
    Tmonier\SyliusErpFlatfilePlugin\TmonierSyliusErpFlatfilePlugin::class => ['all' => true],
];
```

Run the migration (the plugin registers its migrations by itself):

```bash
bin/console doctrine:migrations:migrate
```

## Configuration

```yaml
# config/packages/tmonier_sylius_erp_flatfile.yaml
tmonier_sylius_erp_flatfile:
    # The directory your ERP reads. It must exist and be writable by the process that runs the export.
    exchange_directory: '%kernel.project_dir%/var/erp-exchange'   # default
    # Maximum number of orders per file
    batch_size: 500                                               # default
    # Outbox event types that are exported
    event_types: ['order.placed']                                 # default
```

Run the export at the rhythm your ERP reads the directory (cron, Symfony Scheduler, a loop of your own):

```bash
bin/console tmonier:erp-flatfile:export-orders
```

One call writes one batch (at most `batch_size` orders). The command prints the file name, the number of orders and of lines, or `Nothing to export.`. Exit code 1 when the directory is unavailable.

## Contract of the order file

| Property | Value |
|----------|-------|
| Name | `orders-<UTC date and time>-<8 hexadecimal characters>.csv`, for example `orders-20261024T101500Z-3fa9c1d2.csv`, unique per batch |
| Encoding | UTF-8, no byte order mark |
| Separator | `;` ; a field that contains `;`, `"` or a line break is enclosed in `"` (quotes doubled) |
| Lines | A header line, then **one line per order line**; each line ends with `\n` |
| Amounts | Integers in the **minor unit** of the currency (1250 is 12.50 EUR); no decimal point |

| Column | Content |
|--------|---------|
| `order_number` | Number of the order |
| `webhook_id` | Id of the outbox event (UUID); **the idempotence key** on the reader side |
| `placed_at` | Date of the end of the checkout, **UTC**, `YYYY-MM-DDTHH:MM:SSZ` |
| `currency` | Currency code of the order |
| `customer_email` | E-mail of the customer |
| `variant_code` | Code of the product variant |
| `quantity` | Quantity ordered |
| `unit_price_minor` | Unit price, minor unit |
| `line_total_minor` | Total of the line, minor unit |
| `ship_country` | Country code of the shipping address |

```text
order_number;webhook_id;placed_at;currency;customer_email;variant_code;quantity;unit_price_minor;line_total_minor;ship_country
000000042;0192a000-0000-7000-8000-000000000001;2026-10-24T10:15:00Z;EUR;buyer@example.com;MUG-RED;2;1250;2500;FR
000000042;0192a000-0000-7000-8000-000000000001;2026-10-24T10:15:00Z;EUR;buyer@example.com;MUG-BLUE;1;990;990;FR
```

The columns do not carry the event type: with several types in `event_types`, the same order appears once per type (with a different `webhook_id`). Keep the default `order.placed` unless your reader handles it.

## Reading the directory

Read the files whose name ends with `.csv` in the exchange directory; ignore the `.incoming` subdirectory. A file is complete as soon as it appears. Delete or move the files you have read: the plugin never purges the directory.

## Guarantees and limits

- **At least once.** The journal and the file are tied in one database transaction, and the file is renamed before the commit. If the process dies in between (or the commit fails), the events stay to export and a later file repeats them: **deduplicate on `webhook_id`** on the reader side.
- **Order.** Events are taken by date, oldest first; a batch can end up in the directory after a later one only if you run several exports in parallel.
- **Personal data.** The files hold the e-mail of the customers and a country. **The retention of the exchange directory is not decided by the plugin**: it does not purge the files, and you set the retention that your processing record requires.
- **Spreadsheets.** The files are meant for a program. A spreadsheet that opens them may interpret a field that starts with `=`, `+`, `-` or `@` as a formula; do not open them there without care.
- The journal table (`tmonier_erp_flatfile_exported_event`) has no foreign key to the outbox: purging the outbox does not touch it.
- No ERP is named, tested or supported: the plugin only writes files.


## Importing prices and stock

The plugin also imports two kinds of flat files from the exchange directory, `prices-*.csv` and `stock-*.csv`:

```bash
bin/console tmonier:erp-flatfile:import-batches
```

- **Prices** `variant_code;price_minor;currency;channel`: one price per variant and channel (`ChannelPricing`, no customer group). **All or nothing**: one invalid line rejects the batch and nothing is written. The currency must be the base currency of the channel.
- **Stock** `variant_code;on_hand`: the quantity on hand, as an absolute value. The quantity on hold is never touched. **Line by line**: a valid line is applied, an invalid one is reported. A quantity below the quantity on hold is refused (`below_on_hold`).
- Both: UTF-8 without byte order mark, `;` separator, the header of the contract, integers, a line break at the end of the file (a file cut in the middle is refused).
- A **batch** is its file name and the SHA-256 of its content. A batch already processed is ignored (`duplicate`): nothing is written twice. The journal (`tmonier_erp_flatfile_import_batch`) keeps one line per batch, with its outcome and its report.
- Once imported, a file moves to the `processed` subdirectory of the exchange directory (a rename, so atomic). A file of the same name already there is kept: the new one is prefixed with the start of its checksum.
- Reasons in the report: `columns`, `duplicate`, `unknown_variant`, `channel`, `currency`, `price`, `on_hand`, `below_on_hold`; for a whole file (line 0): `empty`, `encoding`, `truncated`, `header`.

Not done: no API, no retention (the journal and the processed files are kept until the operator removes them), no customer group prices, no reservation logic (the quantity on hold is only read).

## Tests and quality

```bash
vendor/bin/phpunit --testsuite=unit
vendor/bin/phpunit --testsuite=non-unit   # needs the database of the test application
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

## License

MIT.
