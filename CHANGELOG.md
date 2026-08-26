# Changelog

All notable changes to **PrestaPro — Omniva** are documented here.
This project adheres to [Semantic Versioning](https://semver.org/) and the
[Keep a Changelog](https://keepachangelog.com/) format.

## [1.0.9] — 2026-08-26

### Fixed
- **The pickup-point selector no longer depends on JavaScript to appear.**
  The terminal list is now resolved server-side in
  `displayCarrierExtraContent` and rendered into the template as a real
  `<select>`, grouped by city. Previously the template shipped an empty
  container that `checkout.js` filled over AJAX, so a blocked asset, a
  checkout that never ran our JS, or an unreachable module route all showed
  the customer the same thing: a carrier row with nothing under it. The map
  and filterable list still replace the `<select>` as soon as the JS
  initialises; the plain control is then hidden and disabled so it cannot
  submit a competing value.
- **Carrier identity no longer breaks on non-core checkouts.** The hook read
  only `$params['carrier']['id']`, which core supplies but hand-built
  one-page-checkout payloads often do not — they pass `id_carrier` and/or
  `id_reference` instead, and the hook returned an empty string. Resolution
  now accepts all three, via `AbstractPPCarrier::getCarrierKeyFromHookParams()`.
- **Carrier identity no longer breaks in multistore.** `*_ID` / `*_ID_REF`
  are written globally as well as shop-scoped, and lookups fall back to any
  non-zero row. A module installed or configured in one shop's context was
  invisible to every sibling shop: the carrier rendered, its extra content
  did not.
- **Carrier identity no longer goes stale after a back-office carrier edit.**
  Matching is done on the stable `id_reference` first; PrestaShop reassigns
  `id_carrier` on every edit. Related: `hookActionCarrierUpdate` read
  PrestaShop's parameters the wrong way round and wrote the dead carrier id
  back over the live one. `upgrade-1.0.9.php` repairs installs affected by this.
- **Checkout assets now register on one-page-checkout modules.** Registration
  tested `php_self` against a fixed list, but a `ModuleFrontController` leaves
  `php_self` null, so every OPC module fell through and the selector's CSS/JS
  never loaded. `AbstractPPCarrier::isCheckoutContext()` now also matches the
  controller parameter, known OPC module names, and module front controllers
  whose class name says "checkout".

### Added
- Terminals are fetched from the API once, throttled to every 15 minutes per
  country, when the local table is empty — a fresh install whose maintenance
  cron was never wired up no longer shows an empty picker.
- `actionValidateStepComplete` captures the terminal submitted by the plain
  `<select>`, so a selection made without JavaScript is persisted rather than
  silently dropped.

### Changed
- Leaflet is bundled with the module (`views/js/lib/`, `views/css/lib/`)
  instead of being pulled from unpkg.com at checkout. The CDN request was the
  first thing a Content-Security-Policy or restricted egress killed, and it
  took the map with it.

## [1.0.8] — 2026-07-03

### Changed
- Refreshed the carrier logo with the official Omniva logo (vertical lockup, brand orange) from Omniva's brand assets.

## [1.0.7] — 2026-07-03

### Added
- **Lithuanian, Latvian and Estonian translations** for the full checkout
  front end: template labels/buttons, the delivery-time options, and the
  JavaScript-rendered strings (map/list statuses, Locker/Shop badges, error
  messages) — the JS now receives its wording from the server via a data-i18n
  dictionary. Catalogs live in `translations/<locale>/ModulesPpomnivaShop.<locale>.xlf`.

## [1.0.6] — 2026-07-03

### Changed
- The configuration page now renders the migration card from the shared
  `pp-common` partial (loading `prestapro-carrier.css` via the admin menu),
  removing the module-local duplicate markup/CSS. No change in behaviour.

## [1.0.5] — 2026-07-03

### Changed
- Internal: carrier-logo installation and the migration-banner UI were moved
  into the shared `prestapro/pp-common` library (bundled `vendor/` updated), so
  they're maintained in one place. No change in behaviour for merchants.

## [1.0.4] — 2026-07-03

### Fixed
- Parcel-machine map: dragging/panning the map no longer snaps it back to the
  initial view. The map is now framed only when the picker is first shown (and
  on "Change"/"Find nearest"), not on every click inside the carrier row, so
  panning is preserved.

## [1.0.3] — 2026-07-02

### Added
- **Carrier logos** — newly created Omniva carriers now ship with a logo
  instead of PrestaShop's blank placeholder in the carrier list and at checkout.
  Existing installs get the logo back-filled on upgrade.

### Changed
- **Redesigned the legacy-migration UI** on the configuration page. The
  "Preview" action no longer opens a raw JSON page; what will be imported
  (orders, warehouses, manifests, settings, carriers) is shown as a clear
  summary, with dry-run / import actions and a readable result panel.

## [1.0.2] — 2026-07-02

### Fixed
- Opening the module (**Configure**) right after an install or upgrade could
  throw `RouteNotFoundException` for `ps_ppomniva_dashboard` because the
  module's admin routes were not yet compiled into the Symfony router. The
  Symfony cache is now cleared on install and upgrade, and `getContent()` fails
  gracefully (clears the cache and returns to the module list) instead of
  showing an error page.

## [1.0.1] — 2026-07-02

### Added
- **Disable individual parcel machines** from the Terminals admin screen. Each
  point has an Enable/Disable toggle and a Status column; disabled points are
  hidden from the checkout terminal list but stay visible and manageable in the
  back office. Adds an `enabled` column to the terminal cache.
- Bundled `vendor/` so the module installs without running `composer install`.

### Changed
- **Terminal sync is now incremental.** `syncCountry()` upserts terminals
  (`INSERT … ON DUPLICATE KEY UPDATE`) and only deletes points the API no longer
  returns, instead of wiping and re-inserting every row on each sync — faster,
  keeps row IDs stable, and preserves the merchant's enabled/disabled choices.
- **Checkout picker is theme-agnostic.** Works on the default Classic theme,
  Hummingbird and any theme derived from them: courier field labels no longer
  inherit the theme's right-aligned labels; pickup-selector visibility falls
  back to `.delivery-option` / `.carrier-extra-content`; and the pickup map
  re-frames its markers once visible so pins never render off-screen.

## [1.0.0] — 2026-07-02

- Initial release: Omniva courier and parcel-machine carriers, checkout terminal
  selector with map, cash-on-delivery, age-restricted (18+) product handling,
  shipment labels, manifests, terminal sync, order-state mapping and localized
  carrier names.
