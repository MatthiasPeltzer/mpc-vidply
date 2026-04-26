# VidPly Listview & Detail Page

The extension ships two additional content elements that build an
Mediathek-style browsing experience on top of the existing VidPly media
library:

- **VidPly Listview** (`mpc_vidply_listview`) — renders one or more named
  "shelves" (grid or horizontal scroller) of media cards.
- **VidPly Detail** (`mpc_vidply_detail`) — renders the VidPly player plus
  metadata for a single media record, resolved from the URL slug.

```
Editor picks media/categories
      │
      ▼
 mpc_vidply_listview (Content Element)
   ├── row 1  (headline, layout, selection)
   ├── row 2
   └── row n
      │           each card: <a href="/mediathek/{slug}">
      ▼
 mpc_vidply_detail (Content Element, on routed page)
      │
      ▼
 Reuses VidPlyProcessor + VidPly.html player
```

## 1. Editor guide

### 1.1 Prepare the detail page

1. Create a standard page (e.g. *Mediathek Detail*) at any location in the
   page tree.
2. Insert a single **VidPly Detail** content element on that page. The
   element has no backend configuration — it only resolves the requested
   media record from the URL at runtime.
3. Remember the page UID; you will reference it from every listview element
   so card links know where to point.

### 1.2 Create the overview page

1. On your landing page (e.g. */mediathek*) insert a **VidPly Listview**
   content element.
2. Under *Detail Page* choose the page created in 1.1.
3. Under *Listview Rows* add one row per shelf, and configure it:
   - **Row Headline** — visible above the row.
   - **"Show all" Link** (optional) — rendered next to the headline.
   - **Layout** — *Horizontal shelf* or *Responsive grid*.
   - **Card style** — *Poster (16:9)*, *Compact (3:4)* or *Landscape*.
   - **Max items** — upper bound of items loaded for the row.
   - **Client-side pagination** (optional, **grid layout only**) — the
     horizontal **shelf** always uses in-row scrolling, not paginated
     “pages”. For **responsive grid** only, you can enable pagination and
     set **Items per page** (default 12); if there are *more* items than
     this number, a pager appears. Turn pagination off to always show the
     full set (up to *Max items*). Each row is independent, including
     when one content element has multiple listview rows.
   - **Sort order** (backend default for loading items) — manual/MM order,
     newest first, or title A–Z. The frontend can offer a *Sort* control
     so visitors can change the order in the browser without a reload.
   - **Selection mode** — *Manual* (hand-pick records) or
     *Automatic (by category)*. The corresponding picker field appears
     below.

Cards show title, optional artist line, and **category chips** (from the
media record’s own categories) when available.

### 1.3 Slugs

Every record in `tx_mpcvidply_media` automatically receives a URL-friendly
slug generated from its title. Editors may override the slug on the
*Metadata* tab. Slugs are validated as **unique per site** to avoid
routing ambiguity.

Records **without a slug** remain reachable: the listview falls back to
`?media=<uid>&cHash=…` automatically, so editors can publish a record
first and add a nicer URL later without breaking existing links.

### 1.4 Translated (connected) listview rows

For multilingual sites, listview *shelf* definitions follow the
**default-language** content element (inline `tx_mpcvidply_listview_row`
records hang off the source `tt_content` uid), so a translation does not
“win” with empty placeholder rows. Configure shelves once on the default
language; translated pages use the same rows unless you maintain separate
row data. See the extension’s `ListviewProcessor` and TCA for details.

### 1.5 Media: short and long copy on the detail page

On each **VidPly Media** record, editors can use:

- **Description** — short text.
- **Long description** — rich text (CKEditor) for the detail page; rendered
  below the short description with normal HTML formatting.

### 1.6 Detail-page title and breadcrumb

On a valid detail view the extension automatically:

- replaces the current breadcrumb item's title with the media's title so
  the breadcrumb reads *Home › Mediathek › My Great Episode*;
- sets the HTML `<title>` tag to the media's title via a dedicated
  `PageTitleProvider` (precedence: *before* the SEO and record providers).

Both overrides kick in only when the current page hosts a **VidPly
Detail** CE and the URL carries a resolvable `media` parameter. On
regular pages the default behaviour is untouched.

If you set the detail page to **Hide in menu** (so it does not appear in
the main navigation), enable the Site Set’s TypoScript addition
`page.10.dataProcessing.70.includeNotInMenu = 1` — it is shipped with
`mpc-vidply` so the **breadcrumb** rootline still lists the hidden page
and its parents (e.g. *Home › Mediathek › …*). Without it, TYPO3’s default
HMENU drops `nav_hide` pages from the breadcrumb and the path can look
wrong.

## 2. Administrator guide

### 2.1 Automatic route enhancer (TYPO3 14.1+)

The extension ships a Site Set
(`Configuration/Sets/mpc-vidply/route-enhancers.yaml`) that registers a
`Simple` route enhancer plus the `MpcVidplyMediaRoute` aspect (slug optional,
query fallback) on the `slug` field
of `tx_mpcvidply_media`. If your site uses the `mpc-vidply` site set, the
enhancer is picked up automatically — URLs of the form

```
https://example.com/mediathek/my-great-episode
```

resolve to the detail page and load the referenced media record.

### 2.2 Manual fallback (TYPO3 < 14.1 or custom scoping)

If you need to scope the enhancer to a specific page or are running on an
older TYPO3 version, paste the following into
`config/sites/<site>/config.yaml`:

```yaml
routeEnhancers:
  VidPlyDetail:
    type: Simple
    routePath: '/{media}'
    limitToPages:
      - 42   # UID of the detail page
    defaultController: 'VidPly::detail'
    requirements:
      media: '[a-z0-9\-]+'
    aspects:
      media:
        type: MpcVidplyMediaRoute
        tableName: tx_mpcvidply_media
        routeFieldName: slug
```

### 2.3 Analyze DB structure

After deploying the extension, run *Analyze Database Structure* from the
install tool (or `typo3 database:updateschema`). The update adds, among
other fields:

- `tx_mpcvidply_media.slug`, and over time e.g. `long_description` (RTE) for
  detail text
- `tx_mpcvidply_listview_row` (table) and columns such as
  `enable_pagination` / `pagination_per_page` for per-row client-side pagination
- `tx_mpcvidply_listview_row_media_mm` (MM for manual selection)
- `tt_content.tx_mpcvidply_listview_rows` and
  `tt_content.tx_mpcvidply_detail_page`

### 2.4 Cache flush

Flush the TYPO3 cache once after updating the extension so the new
TypoScript, TCA and icon registrations are picked up.

## 3. Frontend assets

The listview content element always loads (grid and shelf):

- `Resources/Public/Css/listview.min.css` — listview, cards, detail-related
  layout, pager UI
- `Resources/Public/JavaScript/Listview.min.js` (ES module) — horizontal
  shelf scrolling and arrows, *Sort* `<select>`, *client-side pagination*
  (when a row is configured to paginate and has more items than the
  page size), and optional card fade-in

Both assets respect `prefers-reduced-motion` and expose CSS custom
properties for theming (see `listview.css` for the full list).

## 4. Accessibility

- Each card is a real `<a>` element (not a JS-only click target) with a
  visible focus ring; category chips and caption sit above the
  full-card link overlay for readability.
- The shelf's scroll container is keyboard-accessible (`tabindex="0"`,
  Arrow / Home / End navigation). Arrow buttons expose `aria-controls`
  and are disabled at the ends.
- The sort control and pagination `<nav>` use native `<select>` and
  `<button>` elements with associated labels; the pager can switch to
  a compact *Page x of y* when there are many pages.
- Poster images use native lazy loading (`loading="lazy"`,
  `decoding="async"`) and always have an `alt` text derived from the
  media title.
- The detail template emits `schema.org` JSON-LD (`VideoObject` or
  `AudioObject`) for SEO / rich-result eligibility.
