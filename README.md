# ppomniva

Omniva (Baltic post) courier & parcel-machine shipping integration for
PrestaShop 9 (OneLife). Modeled on the sibling **ppvenipak** module and the
shared **`prestapro/pp-common`** carrier base.

**Status:** scaffold — installable skeleton with wiring in place; Omniva API
call bodies and several admin screens are stubbed (marked `TODO`).

## Architecture

- **Base class:** `PPOmniva extends PrestaPro\Common\Carrier\AbstractPPCarrier`
  (provides carrier create/delete, `actionCarrierUpdate` id-tracking, localized
  carrier names, zone/group/range assignment).
- **Namespace:** `PrestaShop\Module\PPOmniva\` → `src/` (PSR-4).
- **Config prefix:** `PPOMNIVA_*` (per-shop; a few global keys).
- **Two carriers:** `courier` + `pickup` (parcel machine).
- **Traits:** `OrderStateTrait`, `AdminAssetsTrait` (pp-common) + local
  `CheckoutHooks`, `AdminHooks`.

## Layout

```
ppomniva.php                     main module class (CarrierModule)
composer.json / config.xml       PSR-4 autoload + pp-common dep / metadata
config/                          services.yml, routes.yml (admin routes)
src/
  Api/                           OmnivaApiClient, ShipmentBuilder, PostcodeFormatter, OmnivaErrorMapper
  Carrier/                       OmnivaShippingCalculator, TerminalSync, Pack/ManifestNumberGenerator
  Controller/Admin/             Dashboard, Configuration, Order(+List), Manifest, Terminal, Warehouse, Cod, Log, Carriers
  Cron/                          MaintenanceRunner (shared by HTTP + CLI)
  Form/                          Configuration + Carrier form types & data providers
  Hooks/                         CheckoutHooks, AdminHooks
  Module/                        Installer, Uninstaller, CarrierOverrideManager
  Service/                       ApiLogger, LabelGenerationService
  Diagnostics/                   OmnivaConfigCheck
sql/                             install.sql / uninstall.sql (6 tables)
controllers/front/               ajax.php (terminal picker), cron.php (token HTTP)
cron/run.php                     server-side CLI maintenance entry (uncapped)
views/                           admin Twig, checkout Smarty + JS/CSS
data/overrides/Carrier.php       shared localized-carrier-name override template
docs/OMNIVA_API.md               Omniva OMX API reference
```

## Database (6 tables)

`ppomniva_order`, `ppomniva_warehouse`, `ppomniva_manifest`, `ppomniva_terminal`,
`ppomniva_log`, `ppomniva_18_plus_product`.

## Features carried over from the legacy `omnivaltshipping` module

Courier call + manifest, cash-on-delivery (with terminal COD gating),
multi-warehouse sender origins, and per-product 18+ age-restricted flag.

## Before it works (build phase)

1. Fill `OmnivaApiClient` request bodies against the OMX manual + credentials
   from the Omniva account manager (see `docs/OMNIVA_API.md` §3, §6). Confirm
   the **API base host** and **service codes** — left as `TODO`, not guessed.
2. Complete `ShipmentBuilder`, `LabelGenerationService`, and the checkout
   terminal-picker JS (`views/js/front/checkout.js`) + AJAX controller.
3. Implement the stubbed admin controllers (Order/Manifest/Terminal/Warehouse/
   Cod/Log list + CRUD screens).
4. Replace the **placeholder `logo.png`** (copied from the old module).
5. Add a OneLife theme override for the checkout templates
   (`themes/onelife/modules/ppomniva/...`, BEM re-skin).

## Cron

Parcel-machine locations refresh + log cleanup share `MaintenanceRunner`:

- Server-side (preferred, uncapped): `php modules/ppomniva/cron/run.php`
- HTTP (token, ~100s proxy cap): `?fc=module&module=ppomniva&controller=cron&token=...`

## Install notes

- Run `composer dump-autoload --no-plugins` in this dir after adding `src/`
  classes (already done for the scaffold; `vendor/` is seeded with pp-common).
- No `index.php` inside `src/` — it would break PS9 Symfony DI compilation.

Source: <https://www.omniva.lt/en/business/api/api-examples/>
