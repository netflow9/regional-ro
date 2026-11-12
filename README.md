# RegionalRO

A [Rail22](https://example.com) plugin that adds steps and credentials for Romania's public institutions and for its invoicing and courier services:

| Step | Service | Credential |
|---|---|---|
| **ANAF Company Lookup** | ANAF's public register (PlatitorTvaRest v9) | none |
| **BNR Exchange Rates** | National Bank of Romania reference rates (curs.bnr.ro) | none |
| **ANAF e-Factura** | RO e-Factura (FCTEL/rest) | ANAF (SPV); validation and PDF need none |
| **ANAF e-Transport** | RO e-Transport (ETRANSPORT/ws/v1) | ANAF (SPV) |
| **SmartBill** | SmartBill Cloud API V1 | SmartBill |
| **FGO** | FGO API v7 | FGO |
| **Sameday** | Sameday courier API | Sameday |
| **FAN Courier** | FAN Courier API 2.0 (selfAWB) | FAN Courier |
| **Cargus** | UrgentCargus API | Cargus |

The steps appear in the designer's **Regional** group for every account on the installation. The credentials are in the **Romania** group. Each invoicing and courier step also has a **Call the API** operation, which makes any call to the service through the credential.

## Installation

The plugin lives in its own repository and is cloned into the core's `app/plugins/` folder (which the core's `.gitignore` ignores):

```sh
cd app/plugins
git clone git@github.com:toolmk/regional-ro.git RegionalRO
```

Turn it on in the installation's `plugins.json`, which sits next to `app.env` (or in `NFN_CONFIG_DIR`). The entry is the same as `plugins.json.example`:

```json
{
    "RegionalRO": {
        "enabled": true
    }
}
```

Restart the daemon so that it loads the steps.

## Public steps

### ANAF Company Lookup

Looks up Romanian companies by CUI / CIF on a given date (today if no date is given). You can pass one or more codes, with or without `RO`. Separate them with commas or new lines, or pass a list of strings or of objects with `cui` / `cif`. Each code's control digit is checked before the code is sent.

Each company includes its name, address, trade register number, CAEN code, VAT payer status (and period), VAT on collection, split VAT, inactive status, e-Factura registration, and head office and fiscal address. The raw ANAF record is also included as `anaf`.

Result: `{ items, count, company (the first), notFound, invalid }`. Codes that ANAF doesn't know, or that aren't valid, aren't treated as errors. The step fails only when none of the codes is valid.

ANAF allows at most 100 codes per request and 1 request per second, so longer lists are sent in batches of 100, a second apart.

### BNR Exchange Rates

All rates are in RON for 1 unit of the currency (BNR's multipliers are already applied). They're published each banking day at around 13:00 Romania time; history goes back to 2005.

- **Get exchange rates**: the rates for a day, or the latest if no day is given. Days without rates use the last earlier banking day. Result: `{ date, requested, base, rates }`
- **Convert an amount**: between RON and any BNR currency. Result: `{ amount, from, to, rate, result, date, requested }`

## ANAF e-Factura and e-Transport

Both steps use the **ANAF (SPV)** credential. The CIF can be set on the step; otherwise the credential's CIF is used. The invoice or declaration XML can be given as text (an expression) or as a project file (`{{ file }}` from another step, or a file id).

**ANAF e-Factura**
- **Upload an invoice**: UBL 2.1 / CIUS-RO, a credit note, CII, or a buyer's message. Options cover external invoices and self-invoicing. Returns `{ uploadId, date }`; ANAF checks the invoice afterwards.
- **Get an upload's status**: `{ state, ready, ok, downloadId, errors }`
- **List messages**: the CIF's messages from the last 1 to 60 days (invoices sent and received, errors, buyers' messages).
- **Download a message**: saves the files from the message's ZIP to the project's Files and returns the invoice XML as text.
- **Validate an invoice (without sending)**: public, no credential needed. An invalid invoice is a result, not an error.
- **Make an invoice's PDF**: public, no credential needed. Saves the PDF to the project's Files.

**ANAF e-Transport**
- **Upload a declaration**: eTransport v2 (notification, correction, deletion, confirmation, vehicle change). Returns `{ uploadId, uit, date, warning, traceId }`
- **Get an upload's status**
- **List declarations**: the CIF's declarations from the last 1 to 60 days.

### The ANAF (SPV) credential

ANAF uses OAuth that requires the holder's qualified certificate. To set it up:

1. The certificate holder registers an OAuth app in ANAF's portal (anaf.ro > Servicii online > Înregistrare utilizatori > Editare profil Oauth). Select E-Factura and/or E-Transport, and use the redirect URI shown in the credential's form.
2. **Connect** opens logincert.anaf.ro, which asks the browser for the certificate (the token must be plugged in).

The access token lasts 90 days and the refresh token 365 days, so after a year you'll need to Connect again.

Other settings:
- **CIF**: the default company the steps act for.
- **ANAF test environment**: `api.anaf.ro/test`. Invoices and declarations sent there aren't real.

## Invoicing

### SmartBill

Credential: account email, API token (from SmartBill: Integrari > API), and the company's CIF.

Operations:
- Issue an invoice, a proforma or a payment (receipt). The body uses SmartBill's own field names; the company and series are added from the credential if missing.
- Reverse an invoice
- Cancel, restore or delete a document
- Get a document's PDF (saved to the project's Files)
- Get an invoice's payment status
- List a proforma's invoices
- Send a document by email
- List document series, VAT rates and stocks
- Call the API

### FGO

Credential: the company's CUI, the API user's private key (from FGO: Setari > Utilizatori), an optional platform URL (if empty, this installation's address is used), and FGO's test environment (`api-testuat.fgo.ro`).

Operations:
- Issue an invoice (the body uses FGO's own field names)
- Get an invoice's PDF or status
- Cancel, delete or reverse an invoice
- Attach an AWB
- Record a payment (PREMIUM / ENTERPRISE plans)
- List a nomenclature, articles or warehouses
- Call the API (`CodUnic`, `Hash` and `PlatformaUrl` are added for you)

## Couriers

The courier steps that need a sign-in (Sameday, FAN Courier, Cargus) cache their tokens in the process; the tokens are never saved.

### Sameday

Credential: API user and password, plus a **Demo environment** option (`sameday-api.demo.zitec.com`).

Operations:
- Create an AWB or estimate its cost (to an address or an easybox)
- Get an AWB's status
- Get an AWB's label (A4 / A6, saved to Files)
- Delete an AWB
- List pickup points, services, easyboxes and cities
- Call the API

### FAN Courier

Credential: selfAWB user and password, and the client id the AWBs are made for.

Operations:
- Create an AWB or get its tariff
- Track AWBs
- Get AWB labels (A4 / A5 / A6, as one PDF)
- Delete an AWB
- List services, counties and localities
- Call the API

### Cargus

Credential: subscription key (StandardUrgentOnlineAPI product, Primary Key), WebExpress user and password, the default pickup location, and an optional API address.

Operations:
- Create an AWB or calculate a shipment's price
- Get an AWB
- Trace AWBs
- Get AWB documents (A4 or 10x14 label)
- Delete an AWB
- List pickup locations, counties and localities
- Call the API

## AI steps

The steps can also be used as tools by AI steps:

| Family | Tools | Credential |
|---|---|---|
| `roCompany` | `ro_company_lookup` | none |
| `roBnr` | `ro_bnr_rates`, `ro_bnr_convert` | none |
| `roAnaf` | `anaf_efactura_<operation>`, `anaf_etransport_<operation>` | ANAF (SPV) |
| `roSmartBill` | `smartbill_<operation>` | SmartBill |
| `roFgo` | `fgo_<operation>` | FGO |
| `roSameday` | `sameday_<operation>` | Sameday |
| `roFanCourier` | `fan_<operation>` | FAN Courier |
| `roCargus` | `cargus_<operation>` | Cargus |

## Layout

```
Plugin.php           registers the steps, the credentials and the AI families (hook agent:families)
models/node/         NodeAppRo (base) and one step per service
models/credential/   CredentialRoAnaf, CredentialRoSmartBill, CredentialRoFgo, CredentialRoSameday, CredentialRoFanCourier, CredentialRoCargus
util/                Anaf, Bnr, SmartBill, Fgo, Sameday, FanCourier, Cargus: the clients; Rest: their shared request
```
