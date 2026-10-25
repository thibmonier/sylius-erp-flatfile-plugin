# Sylius ERP flat-file plugin

[English version](README.md)

Échange par fichiers plats avec un ERP pour Sylius 2. Cette première tranche (version 0.x) **exporte les commandes** en fichiers CSV, construits à partir des événements de [`tmonier/sylius-webhook-outbox-plugin`](https://github.com/thibmonier/sylius-webhook-outbox-plugin), selon un contrat documenté, et **les dépose de façon atomique** dans un répertoire d'échange que votre ERP lit.

**C'est un contrat neutre, pas un connecteur.** Le plugin ne se connecte à aucun ERP et n'en nomme aucun : un ERP, ou l'intégrateur qui le branche, lit les fichiers du répertoire. L'import des prix et du stock est une tranche ultérieure.

## Ce qu'il fait

- Une commande console prend les événements `order.placed` en attente de l'outbox et écrit **un fichier par lot** dans le répertoire d'échange.
- Le fichier est dérivé des événements, **pas d'une seconde lecture des tables de la boutique** : l'outbox reste l'unique source de vérité. Le plugin d'outbox n'est pas modifié.
- Le fichier est écrit dans le sous-répertoire `.incoming` puis renommé : un lecteur du répertoire d'échange **ne voit jamais de fichier partiel**.
- Chaque événement est exporté **une seule fois** : l'identifiant de l'événement (le `webhook_id` de l'outbox) est la clé primaire d'une table de journal, donc deux exports simultanés ne peuvent pas écrire deux fois la même commande.
- Si le répertoire est absent, non inscriptible ou plein, **aucun fichier ne reste**, l'erreur est consignée avec un message clair, la commande échoue et les événements **restent à exporter**.
- Les événements effacés pour le RGPD par le plugin d'outbox (`anonymized_at`) sont ignorés.

## Prérequis

- PHP 8.2 ou plus
- Sylius 2.2 ou 2.3 (Symfony 6.4, 7.4 ou 8.0)
- `tmonier/sylius-webhook-outbox-plugin` ^1.0, installé et configuré

## Installation

```bash
composer require tmonier/sylius-erp-flatfile-plugin
```

Déclarez les bundles dans `config/bundles.php` (celui de l'outbox d'abord) :

```php
return [
    // ...
    Tmonier\SyliusWebhookOutboxPlugin\TmonierSyliusWebhookOutboxPlugin::class => ['all' => true],
    Tmonier\SyliusErpFlatfilePlugin\TmonierSyliusErpFlatfilePlugin::class => ['all' => true],
];
```

Exécutez la migration (le plugin enregistre ses migrations lui-même) :

```bash
bin/console doctrine:migrations:migrate
```

## Configuration

```yaml
# config/packages/tmonier_sylius_erp_flatfile.yaml
tmonier_sylius_erp_flatfile:
    # Le répertoire que lit votre ERP. Il doit exister et être inscriptible par le processus qui lance l'export.
    exchange_directory: '%kernel.project_dir%/var/erp-exchange'   # défaut
    # Nombre maximal de commandes par fichier
    batch_size: 500                                               # défaut
    # Types d'événements de l'outbox qui sont exportés
    event_types: ['order.placed']                                 # défaut
```

Lancez l'export au rythme où votre ERP lit le répertoire (cron, Symfony Scheduler, une boucle à vous) :

```bash
bin/console tmonier:erp-flatfile:export-orders
```

Un appel écrit un lot (au plus `batch_size` commandes). La commande affiche le nom du fichier, le nombre de commandes et de lignes, ou `Nothing to export.`. Code de sortie 1 quand le répertoire est indisponible.

## Contrat du fichier de commandes

| Propriété | Valeur |
|-----------|--------|
| Nom | `orders-<date et heure UTC>-<8 caractères hexadécimaux>.csv`, par exemple `orders-20261024T101500Z-3fa9c1d2.csv`, unique par lot |
| Encodage | UTF-8, sans marque d'ordre des octets |
| Séparateur | `;` ; un champ qui contient `;`, `"` ou un saut de ligne est entouré de `"` (guillemets doublés) |
| Lignes | Une ligne d'en-tête, puis **une ligne par ligne de commande** ; chaque ligne se termine par `\n` |
| Montants | Entiers dans l'**unité mineure** de la devise (1250 vaut 12,50 EUR) ; pas de point décimal |

| Colonne | Contenu |
|---------|---------|
| `order_number` | Numéro de la commande |
| `webhook_id` | Identifiant de l'événement de l'outbox (UUID) ; **la clé d'idempotence** côté lecteur |
| `placed_at` | Date de fin du tunnel de commande, en **UTC**, `AAAA-MM-JJThh:mm:ssZ` |
| `currency` | Code de la devise de la commande |
| `customer_email` | E-mail du client |
| `variant_code` | Code de la variante de produit |
| `quantity` | Quantité commandée |
| `unit_price_minor` | Prix unitaire, unité mineure |
| `line_total_minor` | Total de la ligne, unité mineure |
| `ship_country` | Code pays de l'adresse de livraison |

```text
order_number;webhook_id;placed_at;currency;customer_email;variant_code;quantity;unit_price_minor;line_total_minor;ship_country
000000042;0192a000-0000-7000-8000-000000000001;2026-10-24T10:15:00Z;EUR;buyer@example.com;MUG-RED;2;1250;2500;FR
000000042;0192a000-0000-7000-8000-000000000001;2026-10-24T10:15:00Z;EUR;buyer@example.com;MUG-BLUE;1;990;990;FR
```

Les colonnes ne portent pas le type d'événement : avec plusieurs types dans `event_types`, la même commande apparaît une fois par type (avec un `webhook_id` différent). Gardez `order.placed`, valeur par défaut, sauf si votre lecteur sait le gérer.

## Lire le répertoire

Lisez les fichiers dont le nom se termine par `.csv` dans le répertoire d'échange ; ignorez le sous-répertoire `.incoming`. Un fichier est complet dès qu'il apparaît. Supprimez ou déplacez les fichiers lus : le plugin ne purge jamais le répertoire.

## Garanties et limites

- **Au moins une fois.** Le journal et le fichier sont liés dans une transaction de base de données, et le fichier est renommé avant la validation. Si le processus meurt entre les deux (ou si la validation échoue), les événements restent à exporter et un fichier ultérieur les répète : **dédupliquez sur `webhook_id`** côté lecteur.
- **Ordre.** Les événements sont pris par date, du plus ancien au plus récent ; un lot ne peut arriver dans le répertoire après un lot plus récent que si vous lancez plusieurs exports en parallèle.
- **Données personnelles.** Les fichiers contiennent l'e-mail des clients et un pays. **La rétention du répertoire d'échange n'est pas décidée par le plugin** : il ne purge pas les fichiers, et vous fixez la rétention qu'exige votre registre de traitements.
- **Tableurs.** Les fichiers sont faits pour un programme. Un tableur qui les ouvre peut interpréter comme une formule un champ qui commence par `=`, `+`, `-` ou `@` : ne les ouvrez pas dans un tableur sans précaution.
- La table de journal (`tmonier_erp_flatfile_exported_event`) n'a pas de clé étrangère vers l'outbox : purger l'outbox ne la touche pas.
- Aucun ERP n'est nommé, testé ni pris en charge : le plugin écrit seulement des fichiers.


## Import des prix et des stocks

Le plugin importe aussi deux types de fichiers plats depuis le répertoire d'échange, `prices-*.csv` et `stock-*.csv` :

```bash
bin/console tmonier:erp-flatfile:import-batches
```

- **Prix** `variant_code;price_minor;currency;channel` : un prix par variante et par canal (`ChannelPricing`, sans groupe de clients). **Tout ou rien** : une ligne invalide rejette le lot et rien n'est écrit. La devise doit être la devise de base du canal.
- **Stock** `variant_code;on_hand` : la quantité en stock, en valeur absolue. La quantité réservée (`on_hold`) n'est jamais modifiée. **Ligne par ligne** : une ligne valide est appliquée, une ligne invalide est signalée. Une quantité inférieure à la quantité réservée est refusée (`below_on_hold`).
- Les deux : UTF-8 sans marque d'ordre des octets, séparateur `;`, en-tête du contrat, entiers, saut de ligne final (un fichier tronqué est refusé).
- Un **lot** est son nom de fichier et le SHA-256 de son contenu. Un lot déjà traité est ignoré (`duplicate`) : rien n'est écrit deux fois. Le journal (`tmonier_erp_flatfile_import_batch`) garde une ligne par lot, avec son résultat et son rapport.
- Une fois importé, un fichier est déplacé dans le sous-répertoire `processed` du répertoire d'échange (un renommage, donc atomique). Un fichier du même nom déjà présent est conservé : le nouveau est préfixé par le début de son empreinte.
- Motifs du rapport : `columns`, `duplicate`, `unknown_variant`, `channel`, `currency`, `price`, `on_hand`, `below_on_hold` ; pour un fichier entier (ligne 0) : `empty`, `encoding`, `truncated`, `header`.

Hors périmètre : pas d'API, pas de rétention (le journal et les fichiers traités restent jusqu'à leur suppression par l'exploitant), pas de prix par groupe de clients, pas de logique de réservation (la quantité réservée n'est que lue).

## Tests et qualité

```bash
vendor/bin/phpunit --testsuite=unit
vendor/bin/phpunit --testsuite=non-unit   # demande la base de l'application de test
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

## Licence

MIT.
