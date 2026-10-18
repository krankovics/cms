# AdOcean inventory import (comesy)

The AdOcean inventory integration is deliberately **opt-in**. Importing or mapping a source node never places a tracking script onto a publisher's page. Existing native comesy campaigns and iframe slots are unchanged.

## Data model and import

- Admin: `/ad-external.php` (platform admin only). Source: AdOcean `nodes_*.xlsx`.
- `database/ads-external.sql` stores immutable import snapshots, the complete FOLDER/MASTER/LEAF tree, source node IDs, original tag text **as data**, and source-folder-to-comesy-site mappings.
- Upload starts **unmapped / disabled**. Re-uploading identical bytes (SHA-256) is idempotent.
- Records with unknown / duplicate IDs, invalid leaf/master IDs, cross-folder MASTER references or cycles are rejected.
- Only data from the configured site's enabled mapping is returned by the public feed; an authorized admin may disable a mapping at any time.
- Import is synchronous and capped at 15 MB and 30,000 nodes; it requires `ZipArchive`, `XMLReader`, `SimpleXML`, `mbstring`, and PDO/MySQL in PHP. Typical 7,559-row exports may need up to 120s execution time.
- Migration is manual via `/migrate.php` after **backup**. FTP deployment does not create tables or enable mappings.

## Deploy checklist

1. Merge only when PHP lint, XLSX unit, MySQL integration, and browser-smoke checks are green.
2. Back up production MySQL, deploy the new code, and manually run `/migrate.php`.
3. Open `/ad-external.php`; upload the AdOcean XLSX. Inspect the node and MASTER counts.
4. Map **one** source publication folder to its real comesy site; keep it disabled until staging validation.
5. In the publisher's CMP, obtain explicit consent for the relevant vendor/purpose. Do not request the feed or SDK before that.
6. Enable only that mapped publication in the admin and test a known MASTER and a **single** visible SLAVE placement. Validate mobile and desktop, CSP, billing, and AdOcean reporting.
7. Expand to other source publications gradually. Monitor provider errors. Revert by unchecking **Engedélyezve** (also remove client integrations if necessary).

## Publisher JavaScript (explicit CMP gate)

This SDK is intended for a **top-level publisher page**, **not** inside the existing `/ad-slot.php` iframe: all MASTER and SLAVE scripts must execute in the same page context.

The publisher loads the inert helper once:

```html
<script defer src="https://cms.8media.hu/assets/ad-external.js"></script>
<div id="header-ad"></div>
<div id="article-ad"></div>
```

**After the CMP has granted the appropriate advertising/vendor consent**, call:

```js
// This callback is an integration point, not a universal CMP API.
// Never call with consentGranted:true before obtaining valid permission.
async function onAdsConsentGranted() {
  await window.comesyAdOcean.mount({
    apiBase: "https://cms.8media.hu",
    siteId: 1,             // comesy site ID (must match enabled mapping)
    masterNodeId: 13,      // AdOcean source MASTER node ID for this page
    placements: [
      {nodeId: 14, element: "#header-ad"},
      {nodeId: 15, element: "#article-ad"}
    ],
    consentGranted: true
  });
}
```

Call `mount` with only the SLAVE nodes **actually present on that page**. A page normally has one appropriate MASTER context (e.g., article/mobile/desktop), not every MASTER in the import. Source node IDs in the code sample are illustrative: use IDs from the actual mapped publication.

For independent LEAFs use `masterNodeId: 0` and `folderNodeId: <source publication folder ID>`.

The adapter requests `/ad-external-feed.php` with site, MASTER and node IDs, constructs validated DOM placeholders and calls AdOcean's official `ado.master`, `ado.slave` or `ado.placement` APIs with the sourced code IDs. **Stored HTML/JS snippets are never eval'd.** The adapter loads the provider library only when called with explicit consent. It deduplicates placements within a page load.

Browser CORS allows the HTTPS origin whose hostname matches `sites.domain` (with optional `www.`). Configure the publisher CSP for script execution from its AdOcean server (`https://hu.adocean.pl` in the supplied export), helper script from `https://cms.8media.hu`, API `connect-src` and any audited provider dependencies. CSP and cookie/consent needs should be reviewed with the site's CMP and AdOcean vendor configuration.

### Important limitations

- The new connector does **not** import AdOcean campaigns, line items, revenue, budgets, impressions or frequency caps; it imports the **inventory** and maps provider tags.
- External AdOcean analytics remain in AdOcean; comesy native ad metrics do not reflect them.
- The initial adapter uses the `ado.js` APIs present in this export. Verify with the publisher's exact AdOcean account configuration in staging.
- Cross-page targeting and AdOcean business rules remain the provider's responsibility. In particular, importing all SLAVEs but rendering those absent on a page may cause campaign underdelivery.
- If multiple import versions are uploaded, activating a new source mapping disables older active versions for the same comesy site; other mappings from the same snapshot are not affected.
- `ad-external-feed.php` is intentionally public only for **enabled** inventory. It returns provider IDs, not original snippets, and has exact-site CORS restrictions. Treat IDs as public advertising configuration, not credentials.
- A pure CLI dry run of the source can be performed by requiring `app/ad-external-import.php` and calling `external_xlsx_rows` + `external_validate_nodes`; no database changes are required.

## Regression coverage

- `php tests/ad-external.php`: XLSX parsing, typed node hierarchy, duplicate/unsafe ID rejection, cross-MASTER rejection.
- `php tests/integration.php` (disposable `comesy_test` database only): external table creation, imported node preservation, disabled mapping, SHA idempotency, and existing native ad tests.
- `node --check assets/ad-external.js`: JS syntax.
- `php -l ad-external.php ad-external-feed.php app/ad-external-import.php`: PHP syntax (run each file separately).

Official reference: [AdOcean user manual, section 4.2.4.2: Master-Slave codes](https://int.adocean-global.com/adman/manual/AO_manual_en.pdf).


## Native comesy page-builder integration (next phase)

Use \`/builder.php?site_id=...&template=home\` or article/section/tag:
select **AdOcean pozíció**, choose an explicitly mapped source publication,
then a MASTER and one of its LEAF nodes. Standalone LEAFs use **Nincs MASTER**.
The editor stores import snapshot, folder, MASTER and LEAF IDs as typed references
and rejects references belonging to a different site or unrelated MASTER.

Save a draft and view the page in **Előnézet**: you will only see a neutral placeholder.
Publish when the layout is correct. Production \`/public.php\` returns a position
only while the mapping, nodes and site's **Hirdetési pozíciók** feature are enabled.

**CMP integration remains explicit:** nothing is fetched from AdOcean on page load,
even for already published templates. The publisher's consent handler must call:

\`\`\`js
await window.comesyAdOceanPage.activate({consentGranted: true});
\`\`\`

only after the CMP explicitly confirms appropriate purposes and vendor authorization.
A consent withdrawal must be handled by the CMP and generally requires reloading
the page to stop existing provider scripts. Existing iframe-native comesy advertising
remains a separate system. Domain-level routing, cache, canonical URLs, SEO,
CMP, vendor contracts and production adserver verification are required before
using a branded live publication domain. The \`public.php\` staging URLs
continue to send \`noindex, nofollow\`.


## Opt-in real creative preview (editor-only)

Open **Oldalépítő → Előnézet** on a site with **Natív hirdetési zónák**
enabled and an active AdOcean source assignment. The saved draft's
AdOcean block shows the expected MASTER/LEAF IDs. Tick the explicit
AdOcean network-request authorization checkbox, then click
**Valódi AdOcean-hirdetés megjelenítése**.

This creates a sandboxed, authenticated \`ad-external-preview.php\` iframe
containing only the validated provider server and source ID. It uses
\`ado.config({mode:'new', ...})\`, \`ado.preview({enabled:true})\`, then
the MASTER and the chosen LEAF (or standalone placement).

- No third-party ad SDK is requested on initial editorial preview load.
- The ad is rendered within the **opaque-origin iframe** with scripts enabled,
  not in the authenticated admin page. This iframe deliberately omits
  \`allow-same-origin\`.
- Server checks current user permission, source mapping, site ownership,
  node activity and MASTER/LEAF relationship before writing any tags.
- The provider's **Live Preview** option may support uncounted campaign
  previews when configured in the AdOcean account; ordinary active campaign
  ads can still be eligible and count normally. Never assume all test
  impressions are excluded from billing or statistics.
- The preview may be empty when no creative targets the comesy domain,
  device, location, or account. Empty creative is not the same as broken
  inventory. Browser blockers or CSP may also prevent delivery.
- This is per-placement **diagnostic preview**, not an exact test of page-wide
  Surround coordination. Production deployment continues to require proper
  page-level CMP gating via \`comesyAdOceanPage.activate(...)\`.

Tests use a mock AdOcean SDK; they never contact a real provider.
