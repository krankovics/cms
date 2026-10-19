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


### AdOcean 4.x sandbox preview diagnostic

The AdOcean SDK reads document.cookie and localStorage when initializing the
MASTER. An opaque-origin sandbox raises SecurityError at the MASTER call.
Creative code generated by the provider also needs dynamic JavaScript evaluation.

The editor's opt-in test iframe STILL uses sandbox="allow-scripts allow-popups"
WITHOUT allow-same-origin. Only inside that isolated iframe, we install an
empty, non-persistent cookie interface and in-memory localStorage. No authenticated
CMS cookies or real browser storage are sent to the provider.
Only the editor-test iframe's CSP has 'unsafe-eval' enabled.

The parent uses a random channel and event.source validation to receive
SDK / MASTER / ad-request / creative-detected / no-creative statuses.
The iframe load event alone is no longer reported as a delivered advertisement.
Automated browser tests simulate cookie access and eval without making an
AdOcean ad impression. This does NOT alter production publisher ad delivery.

### Mobile preview and truthful creative statuses

The dedicated editor preview no longer treats a successful iframe `load`,
AdOcean tracking pixels, 1×1 beacons or any unrelated image as a rendered
advertisement. A visible, meaningful image/video/canvas/background/text inside
the chosen LEAF is required. A large cross-origin creative iframe is reported
as **unverified** rather than guaranteed visible.

The test frame computes a presentation-only scale factor for oversized
desktop creatives (such as 970×250) and resizes the outer editor frame via
authenticated-by-channel postMessage. This does not change actual AdOcean
campaign size, targeting, or the production frontend's CSS. A desktop-only
campaign may still serve no creative on mobile.


### Desktop AdOcean zones tested from phones

The editor reads the validated MASTER/LEAF source name, e.g. `MagyarNemzet_desktop_cimlap`, to determine whether a position is specifically desktop or mobile. When a desktop-only MASTER is previewed on a narrow screen, the iframe is *laid out* at 970 CSS pixels and then its whole surface is proportionally scaled to the parent viewport. This means AdOcean receives a desktop-width ad request, not a 390px mobile request. A mobile MASTER retains the actual phone viewport and is never falsely emulated as desktop. The live publisher frontend remains unchanged; these modes are restricted to opt-in editor previews. If no eligible campaign targets the source ID, device or test domain, the preview correctly remains empty. For production, select and place appropriate desktop and mobile positions separately.


### AdOcean delivered versus actually visible (diagnostic)

AdOcean 4.9 may replace a configured LEAF div's DOM id with an id ending in
`1`, and inject its creative under `ao-<LEAF>`. The editor detects both
forms, not unrelated analytics pixels. The provider's own callback
`onServerEmissionEmpty` identifies an empty ad selection; `onServerEmission`
only confirms that AdOcean selected creative code, **not** that a banner
was rendered. A third-party AdSense container or frame may remain 0 × 0 even
when code was selected. The editor must report this accurately.

The MASTER now includes AdOcean's supported `slaveIds` list, containing only
LEAF positions actually present on that page. This helps avoid unnecessary
self-exclusion when the same MASTER tree contains other positions.

The opaque-origin editor iframe still restricts cross-frame access.
Third-party Prebid scripts may attempt `parent.document` and fail with
a sandbox SecurityError. Never "fix" this by combining allow-same-origin
and allow-scripts for a same-origin authenticated CMS iframe.
A completely faithful publisher test must use a properly isolated
preview domain or actual publisher site with a configured CMP.

For a blank preview, check campaign targeting and publisher-domain
authorization within AdOcean (desktop MASTER/LEAF #7616/#7617,
mobile #7647/#7649 for the current Magyar Nemzet example).
HTTP 200 from an emitter is not an impression or proof of a creative.
