# PrestaPro — Omniva for PrestaShop 9

**Omniva courier and parcel‑machine shipping module for PrestaShop 9.** Adds
Omniva (Baltic post) home/office courier delivery and Omniva parcel machines
(parcel terminals / lockers) to your PrestaShop 9 checkout, with an interactive
terminal map, cash‑on‑delivery support, age‑restricted (18+) product handling
and back‑office shipment management.

Built for **PrestaShop 9.0+** on the shared `prestapro/pp-common` carrier base,
as a sibling of the **PrestaPro — Venipak** module.

> **Status:** active development. The checkout front end and carrier wiring are
> implemented; the Omniva API request bodies and some back‑office screens are
> still being completed.

---

## Features

### Carriers
- **Omniva Courier** — door‑to‑door home and office delivery.
- **Omniva Parcel Machine** — self‑service parcel terminals / lockers.
- Automatic carrier creation, zone/group/price‑range assignment and tracking
  URL wiring on install.

### Checkout (front office)
- **Interactive parcel‑machine selector** with an OpenStreetMap/Leaflet map,
  searchable terminal list and marker pop‑ups.
- **Find nearest** — geocodes the customer's postcode and lists the closest
  terminals; the nearest is pre‑selected automatically.
- **COD availability badges** and terminal type badges, plus working hours.
- **Courier extra fields** — door code, preferred delivery time and "call before
  delivery" (each optional and configurable).
- **Theme‑agnostic** — verified on the default Classic theme, Hummingbird and
  any theme derived from them; no theme‑specific templates required.

### Age‑restricted (18+) products
- Flag products that require **age verification on delivery** and pass the 18+
  service flag to Omniva for those shipments.

### Cash on delivery (COD)
- Captures the COD amount on the order.
- Per‑terminal COD support: automatically hides COD payment methods when the
  chosen terminal does not accept cash on delivery (server‑side, cannot be
  bypassed with JavaScript).

### Terminals
- Syncs the Omniva parcel‑machine catalogue and caches it locally.
- Filters terminals by cart weight and product dimensions.

### Shipments & documents
- Shipment creation with pack‑number generation.
- Label generation and **manifests** with sequential numbering.

### Back office
- Dedicated admin area with dashboard/connection status, orders and a per‑order
  shipment panel, manifests, warehouses (multistore‑aware sender addresses),
  terminals browser, carrier and COD configuration, order‑state mapping,
  general configuration (API credentials, live/test mode, logging) and API logs.
- Built‑in connection self‑test / diagnostics.

### Operations
- **Cron endpoint** (token‑protected) for terminal sync and housekeeping.
- **Multilingual** — English, Lithuanian, Latvian and Estonian.
- **Multistore** aware.

---

## Requirements

- PrestaShop **9.0.0+**
- PHP **8.1+**
- An Omniva API account for live shipping operations.

## Installation

```bash
# from the module directory
composer install
```

Then install from the PrestaShop back office under **Modules → Module Manager**,
or via CLI:

```bash
php bin/console prestashop:module install ppomniva
```

The module registers its carriers and admin tab automatically on install.

## Configuration

1. Open **PrestaPro — Omniva** in the back office.
2. Enter your Omniva **API credentials** and run **Test connection**.
3. Configure the **sender address / warehouse**.
4. Enable the courier extra fields you need and mark any **18+** products.
5. Set the **cron token** and schedule the cron URL to keep terminals in sync.

## License

[AFL‑3.0](https://opensource.org/licenses/AFL-3.0) — PrestaPro, https://prestapro.lt

---

*Keywords: PrestaShop 9, PrestaShop carrier module, PrestaShop shipping module,
Omniva, parcel machine, parcel terminal, parcel locker, pickup points,
cash on delivery, COD, courier delivery, Baltic shipping, Lithuania, Latvia,
Estonia.*
