# TrustInvoice

A small PHP/MySQL hackathon proof of concept for invoice-to-purchase-order checks, deterministic discrepancy detection, and Hedera Consensus Service fingerprints. It uses no PHP framework or frontend build step.

## What works

- Upload an invoice and a purchase order as PDF, PNG, or JPEG (10 MB maximum each).
- In live mode, send those documents to the Gemini API for structured extraction. The PHP app validates the extracted fields; PHP—not the model—decides status.
- Compare supplier, currency, item quantities, unit prices, and totals deterministically.
- SHA-256 document and normalized supplier/invoice identity fingerprints, and MySQL duplicate detection.
- Submit only those fingerprints, status, and event metadata to a configured Hedera Testnet topic. No document text, supplier name, invoice number, or amount is included in the HCS message.
- Save every result, including confirmed HCS transaction/topic IDs, in MySQL. A failed or unconfigured HCS integration is shown as not registered, never as success.
- Run synthetic approved, duplicate, and inflated-price demonstrations without external API keys. When MySQL is unavailable, the demo keeps a short in-memory session history; live submissions require MySQL.
- Clear stored invoice records and the current browser's demo history with the **Clear history** button.

Uploaded file contents are sent to Google Gemini only when you use live extraction. They are read from PHP's temporary upload location and are not saved under `uploads/`.

## Requirements

- PHP 8.3 or newer with `pdo_mysql`, `openssl`, and `json` enabled.
- MySQL 8+ (or a compatible MySQL server).
- Node.js 18+ and npm for Hedera submissions.
- A Gemini API key and Hedera Testnet account/topic for live integrations.

No XAMPP is required. The built-in PHP web server works for local development.

## Setup

1. Create a MySQL database and table by running `database.sql` in MySQL Workbench, phpMyAdmin, or:

   ```sh
   mysql -u root -p < database.sql
   ```

2. Keep credentials outside the web root. Copy `.env.example` to a file named `.env` **one directory above this project folder**, then fill in database credentials and, when ready, `GEMINI_API_KEY`, `HEDERA_ACCOUNT_ID`, `HEDERA_PRIVATE_KEY`, and `HEDERA_TOPIC_ID`. For example, from the project directory in PowerShell:

   ```powershell
   Copy-Item .env.example ..\.env
   notepad ..\.env
   ```

   The PHP process also accepts those settings as operating-system environment variables. `.env.example` is only a template and contains no credentials.

3. Install the one Node dependency for the Hedera helper from the project directory:

   ```sh
   npm install --no-save --package-lock=false @hashgraph/sdk
   ```

   There is intentionally no Node application or package manifest; `hedera.js` is only a small command-line helper invoked by PHP. `node` must be available to the PHP web-server process. Set `NODE_BINARY` to its absolute path in the parent `.env` if necessary.

4. Start the PHP development server in the project directory:

   ```sh
   php -S 127.0.0.1:8000
   ```

5. Open <http://127.0.0.1:8000>.

## Demo walkthrough

1. Click **Valid invoice**. It should show `APPROVED` and a zero difference.
2. Click **Duplicate**. It uses the same synthetic supplier/invoice number and should show `DUPLICATE`.
3. Click **Inflated price**. It should show `FLAGGED`, the unit-price discrepancy, and a positive total difference.

Without MySQL, the demo ledger and duplicate lookup live only in the current PHP session. With MySQL configured, records persist and the duplicate check uses `identity_hash`.

## Live verification

After MySQL, Gemini credentials, and the Hedera helper are configured, upload both documents and submit. The extraction request sends PDF and image bytes inline to Gemini and asks for schema-constrained JSON. Unsupported API responses and malformed extracted JSON are reported as errors; the app does not fall back to invented invoice contents.

The Gemini model is configurable with `GEMINI_MODEL` and defaults to `gemini-2.5-flash`. Gemini and Hedera API keys are read only by PHP/server-side code and are never included in browser JavaScript.

Hedera requires an existing Testnet topic. Create/configure one in the Hedera portal or with the Hedera SDK, then set `HEDERA_TOPIC_ID`. The helper waits for a real HCS receipt and transaction record before returning a transaction ID and consensus timestamp.

## Schema and data

The single `invoices` table is defined in `database.sql`. Extracted invoice/PO data and issue lists are stored as JSON. MySQL should be accessible from the PHP process at the configured `DB_HOST`/`DB_PORT`.

The app validates uploads by file signature as well as extension. It does not retain uploaded documents. Hashes are fingerprints, not encryption; treat them as sensitive metadata and use only documents you are authorized to process.
