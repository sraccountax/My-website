# Tegh Accounting Knowledge

Tegh 3.1.0 uses a versioned, allow-listed knowledge manifest for accounting, bookkeeping and tax retrieval. Retrieval is deliberately selective: navigation questions do not load standards material; GST/HST and payroll questions prioritize CRA sources; framework-specific policy questions retrieve only relevant ASPE or IFRS source metadata for the company’s selected reporting framework.

The bundled manifest contains only public-source metadata, links and original Tegh summaries. It does **not** contain the full text of IFRS Accounting Standards or the CPA Canada Handbook – Accounting. IFRS Foundation states that integration of IFRS Standards into products and services requires a licence; authoritative ASPE Handbook text similarly remains outside this deployment unless appropriate product-use rights are obtained.

## Updating knowledge without redeploying Tegh

The server first checks `knowledge.manifest_path` from private server configuration, then `storage/knowledge/approved-sources-v1.json`, and finally the bundled `knowledge/approved-sources-v1.json`. This allows an authorized server administrator to replace the approved manifest independently of an application deployment. Browser JavaScript never receives the filesystem path, API credentials or licensed-source credentials.

Every approved source carries authority, jurisdiction, framework, topics, dates and licence classification. AI guidance and journal-composition audit events record the active manifest version and retrieved source IDs. High-authority source changes therefore remain versioned and auditable.

A future licensed ASPE or IFRS knowledge pack can use the same server-side retrieval layer. Licensed full text must never be placed in browser JavaScript or shipped in a deployment ZIP unless the licence explicitly permits redistribution and product integration.
