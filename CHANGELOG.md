# Changelog

All notable changes to **PrestaPro — Omniva** are documented here.
This project adheres to [Semantic Versioning](https://semver.org/) and the
[Keep a Changelog](https://keepachangelog.com/) format.

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
