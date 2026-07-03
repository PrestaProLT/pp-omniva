# Omniva API — Reference Documentation

Reference material for the **ppomniva** PrestaShop 9 module (Omniva carrier & parcel-machine
shipping integration for OneLife).

> Primary source: <https://www.omniva.lt/en/business/api/api-examples/>
> Full source list at the bottom of this file.

Omniva is the Baltic postal/courier operator (Estonia, Latvia, Lithuania + Finland pickup
points). It offers courier delivery, parcel machines (pakomatai / pakiautomaadid), and post
offices. This module targets the **Lithuanian** business API but the API is shared across the
three Baltic tenants.

---

## 1. Getting access (prerequisites)

The API is **not open** — credentials are issued per business contract:

1. Sign a business customer contract with Omniva.
2. Notify your account manager that you want API integration.
3. Receive credentials (username + password) from the account manager.
4. Developers should also register to obtain an **integration agent id** (see auth below).

Support / integration contact: **integrations@omniva.ee**

---

## 2. What the API can do

- Forward shipment information (register shipments) — B2C and C2C flows.
- Request **labels / address cards** for shipments (PDF, base64, or emailed).
- Request **events and location/tracking** for shipments.
- Create and cancel **courier pickups**.
- Request **locations of parcel machines** across the Baltics and post offices in Estonia.

---

## 3. APIs available (two generations)

Omniva currently exposes **two** integration surfaces. New integrations should prefer OMX.

### 3.1 OMX REST API (current / recommended)

Modern JSON/REST API. Developer portal: <https://developer.omniva.ee/>

**Authentication**

- HTTP **Basic Authentication** over HTTPS/SSL (username + password from account manager).
- Developer identification header (required):
  - Header: `X-Integration-Agent-Id`
  - Format: `Developer_XXXXXX_YYYYYY`

**Base host:** `https://omx.omniva.eu` — **path prefix** `/api/v01/omx/…`

> ✅ **VERIFIED (2026-07-01)** with the test account (client-code `<your-code>`):
> `GET https://omx.omniva.eu/api/v01/omx/shipments/{barCode}` returned HTTP 200
> JSON tracking events using plain **HTTP Basic** auth. The `X-Integration-Agent-Id`
> header was **not** required for the read call (worked with and without it) —
> supply it for shipment registration if Omniva requires it. Same host serves
> test and production; the environment is determined by the credentials.
> This module is **REST-only** — no SOAP. (The legacy EPMX SOAP host
> `edixml.post.ee` also accepts these keys but is not used.)

**Endpoints**

Track & Trace
| Method | Path | Operation |
|--------|------|-----------|
| GET | `/api/v01/omx/shipments` | getTrackingInfo |
| GET | `/api/v01/omx/shipments/{barCode}` | getTrackingInfoByBarCode |
| GET | `/api/v01/omx/shipments/{barCode}/` | getTrackingInfoByBarCode2 |

Shipment management
| Method | Path | Operation |
|--------|------|-----------|
| POST | `/api/v01/omx/shipments` | updateShipment |
| POST | `/api/v01/omx/shipments/business-to-client` | saveBusinessToClientShipments (B2C) |
| POST | `/api/v01/omx/shipments/client-to-client` | saveClientToClientShipments (C2C) |
| POST | `/api/v01/omx/shipments/omniva-return` | omnivaReturn |
| POST | `/api/v01/omx/shipments/cancel` | cancelShipment |

Labels
| Method | Path | Operation |
|--------|------|-----------|
| POST | `/api/v01/omx/shipments/package-labels` | getAddressCards |

Courier pickups
| Method | Path | Operation |
|--------|------|-----------|
| POST | `/api/v01/omx/courierorders/pickup-availability` | findPickupAvailability |
| POST | `/api/v01/omx/courierorders/create-pickup-order` | createCourierPickupOrder |
| POST | `/api/v01/omx/courierorders/cancel-pickup-order` | cancelCourierPickupOrder |
| GET | `/api/v01/omx/courierorders/{courierOrderNumber}` | getCourierOrder |

### 3.2 Legacy XML/SOAP "Business XML" (EPMX)

Older HTTP/SOAP service that returns JSON data with barcodes and address cards.

- WSDL (production): `https://edixml.post.ee/epmx/services/messagesService.wsdl`
- Test/integration WSDL is provided by the account manager (historically a `tsengintg.post.ee`
  host — confirm the current value).
- Authentication: SOAP client username/password.

The `mijora/omniva-api` PHP library (see §5) wraps this generation and also provides the
OMX tracking/courier calls (`getTrackingOmx`, `cancelCourierOmx`).

---

## 4. Parcel-machine / pickup-point locations

Public, no auth required. Covers parcel machines in EE, LV, LT and post offices / pickup
points in Finland.

- JSON: `https://www.omniva.ee/locations.json`
- Also offered as CSV / XML on the locations page.
- **Refresh at most once every 24 h** (cache locally; don't hammer on every checkout).

Typical fields per location: `ZIP`, `NAME`, `TYPE` (0 = post office, 1 = parcel machine),
`A0_NAME` (country), `A1_NAME` (county), `A2_NAME` (municipality/city), `A3_NAME`,
`x_coordinate`, `y_coordinate`, `SERVICE_HOURS`, `TEMP_SERVICE_HOURS`.

For parcel-machine delivery the receiver **`offloadPostcode`** (destination terminal ZIP) is
**mandatory** on the shipment.

---

## 5. Reference PHP library (mijora / omniva-baltic)

Official open-source PHP library used by Omniva's own OpenCart/PrestaShop plugins. It is the
best concrete reference for request/response shapes and service codes.

- GitHub: <https://github.com/omniva-baltic/omniva-api-lib>
- Packagist: `composer require mijora/omniva-api`
- Requirements: PHP ≥ 5.6 (tested to 7.4). *For PS9 (PHP 8.x) test compatibility or port the
  relevant calls rather than depending on it directly.*

### Key classes

| Class | Purpose |
|-------|---------|
| `Shipment` | Top-level shipment container |
| `ShipmentHeader` | Sender metadata + main service selection |
| `Package` | One package (service code, measures, COD, additional services) |
| `Contact` / `Address` | Sender & receiver party details |
| `Measures` | Weight (kg) + optional dimensions |
| `Cod` | Cash-on-delivery amount / bank details |
| `AdditionalService` | Extra service codes |
| `Label` | Address-card / label PDF generation |
| `Manifest` | Courier manifest PDF |
| `Tracking` | `getTrackingOmx($barcode)` → array of events |
| `CallCourier` | `callCourier()`, `cancelCourierOmx($pickup_call_id)` |
| `PickupPoints` | `getFilteredLocations($country, $type, $county)` |
| `OmnivaException` | Thrown on any API error |

### Shipment essentials

- Sender + receiver `Contact` with `Address` (2-letter ISO country codes).
- Postcode format: `00000` for LT / EE / FI; `LV-0000` for Latvia.
- Package needs a **service code** and weight in kg.
- Parcel-machine shipments require the destination terminal ZIP (`offloadPostcode`).

### Labels (`Label::downloadLabels`)

```
downloadLabels($barcodes, $combine, $mode, $name)
```

- `$combine`: `true` = 4 labels per A4 page, `false` = 1 per page.
- `$mode`: `'I'` = inline preview, `'S'` = return string data, `'D'` = force download.
- Server-generated labels can alternatively be delivered by email or as base64 (PDF).

### Custom labels

If you print your own labels (not server-generated) they **must** contain:
- Sender & receiver contact + address data
- Service and additional-service data
- Parcel-machine name and ZIP (when applicable)
- Shipment **barcode**
- Omniva logo

---

## 6. Service codes (confirm against current manual)

Exact single-letter main-service codes and additional-service codes vary by contract/country
and change over time — **always confirm against the account-manager-supplied manual** and the
`mijora/omniva-api` example config. Do not hardcode guessed codes.

Concept map (what you must resolve to codes per country):
- Courier to address (home/office delivery)
- Parcel machine (terminal) delivery — requires `offloadPostcode`
- Post office pickup (EE)
- Additional services: COD (cash on delivery), fragile, doc return, SMS/email notifications,
  insurance, etc.

Authoritative code lists live in:
- OMX API manual (LT PDF): <https://www.omniva.lt/wp-content/uploads/sites/5/2024/10/OMX-API-documentation-09.2025.pdf>
- OMX API manual (EE PDF): <https://www.omniva.ee/wp-content/uploads/sites/7/2025/08/OMX-API-Manual-for-Customers_nov.pdf>
- Service availability / offload spec PDF: <https://www.omniva.lt/wp-content/uploads/sites/5/2024/10/offload-specification.pdf>

---

## 7. Integration checklist for ppomniva

- [ ] Store credentials (username, password, `X-Integration-Agent-Id`) as per-shop config
      (secret handling — do not commit). Support test vs production host toggle.
- [ ] Cron/scheduled fetch of `locations.json` → local table, ≤ once/24 h. Filter by country
      (LT) + type (parcel machine) for the checkout terminal selector.
- [ ] Terminal picker at checkout (map + search), stored as `offloadPostcode` on the order.
- [ ] On order → register shipment (B2C `saveBusinessToClientShipments`), store returned barcode.
- [ ] BO action: request label (`package-labels` / `getAddressCards`) → PDF, and manifest.
- [ ] Courier pickup create/cancel from BO.
- [ ] Tracking sync (`getTrackingInfoByBarCode`) → order status / tracking link in emails.
- [ ] COD support if the shop uses cash-on-delivery.

### Sibling reference in this repo
`modules/ppvenipak` is the closest existing carrier module (Venipak courier + pickup points).
Mirror its structure for ppomniva: `config/`, `controllers/`, `src/`, `sql/`, `views/`,
`translations/`, `upgrade/`, `composer.json`, `config.xml`. See also memory notes on
`ppvenipak` carrier id_reference handling and pack-counter behavior.

---

## 8. Sources

- Omniva LT API examples: <https://www.omniva.lt/en/business/api/api-examples/>
- Omniva LT API documentation: <https://www.omniva.lt/en/business/api/documentation/>
- Omniva developer portal (OMX REST): <https://developer.omniva.ee/>
- OMX API manual (LT PDF): <https://www.omniva.lt/wp-content/uploads/sites/5/2024/10/OMX-API-documentation-09.2025.pdf>
- OMX API manual (EE PDF): <https://www.omniva.ee/wp-content/uploads/sites/7/2025/08/OMX-API-Manual-for-Customers_nov.pdf>
- Offload / service-availability spec (PDF): <https://www.omniva.lt/wp-content/uploads/sites/5/2024/10/offload-specification.pdf>
- PHP library (source of truth for payloads): <https://github.com/omniva-baltic/omniva-api-lib> (`composer require mijora/omniva-api`)
- Legacy SOAP WSDL: `https://edixml.post.ee/epmx/services/messagesService.wsdl`
- Parcel-machine locations JSON: <https://www.omniva.ee/locations.json>

*Compiled 2026-07-01. Endpoint base hosts and service codes must be confirmed against the
credentials and manual supplied by the Omniva account manager before production use.*
