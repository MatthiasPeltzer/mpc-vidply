# VidPly Documentation

Documentation for the **mpc-vidply** TYPO3 extension. Start here to find the right guide for your role.

## Quick paths

| I want to… | Read |
|------------|------|
| Add a video or audio player to a page | [Editors Guide → Two-Step Workflow](Editors-Guide.md#two-step-workflow) |
| Import media from a URL (YouTube, HLS, …) | [Editors Guide → Import from URL](Editors-Guide.md#fastest-way-import-from-url) |
| Build a mediathek (list + detail pages) | [Listview & Detail](Listview.md) |
| Configure GDPR privacy texts | [Editors Guide → Privacy Layer](Editors-Guide.md#privacy-layer-gdpr) · [PrivacyLayer.md](PrivacyLayer.md) |
| Understand where settings live | [Settings Architecture](SettingsArchitecture.md) |
| Optimize frontend asset loading | [Asset Loading](AssetLoading.md) |
| Set up HLS or DASH streaming | [HLS Implementation](HLS-Implementation.md) |
| Override Fluid templates | [Partials](Partials.md) |
| Embed players in Vue / Swiper sliders | [Integrations](Integrations.md) |
| Configure JSON-LD / SEO structured data | [Developers Quickstart → Structured data](Developers-Quickstart.md#structured-data-json-ld) |
| Run tests or extend PHP/JS | [Developers Quickstart](Developers-Quickstart.md) |

## By audience

### Content editors

- **[Editors Guide](Editors-Guide.md)** — Create media records, add players, captions, playlists, privacy behaviour, troubleshooting.
- **[Listview](Listview.md)** (sections 1.1–1.6) — Mediathek overview pages, detail pages, slugs, categories.

### Site administrators

- **[Settings Architecture](SettingsArchitecture.md)** — Extension Configuration, privacy settings, player CE options, per-media fields.
- **[Listview](Listview.md)** (section 2) — Route enhancers, database updates, cache flush, hidden detail pages in breadcrumbs.
- **[PrivacyLayer.md](PrivacyLayer.md)** — How external-service consent works site-wide.

### Developers & integrators

- **[Developers Quickstart](Developers-Quickstart.md)** — Installation, processors, TCA, tests, structured data.
- **[Integrations](Integrations.md)** — Dynamic content (Vue), CSP, mp-core JSON-LD merge, theme sync.
- **[Partials](Partials.md)** — Template override points.
- **[Asset Loading](AssetLoading.md)** · **[HLS Implementation](HLS-Implementation.md)** — Performance and streaming internals.

## All documents

| Document | Summary |
|----------|---------|
| [Editors-Guide.md](Editors-Guide.md) | End-user guide for backend editors |
| [Listview.md](Listview.md) | Mediathek listview + detail content elements |
| [Developers-Quickstart.md](Developers-Quickstart.md) | Developer reference and quick start |
| [Integrations.md](Integrations.md) | Vue/Swiper, CSP, mp-core, theme API |
| [SettingsArchitecture.md](SettingsArchitecture.md) | Configuration tiers and field reference |
| [AssetLoading.md](AssetLoading.md) | Conditional JavaScript loading |
| [PrivacyLayer.md](PrivacyLayer.md) | GDPR consent for YouTube/Vimeo/SoundCloud |
| [HLS-Implementation.md](HLS-Implementation.md) | HLS & DASH streaming setup and CSP |
| [Partials.md](Partials.md) | Fluid partial structure and overrides |

## Related

- Extension root: [README.md](../README.md)
- Changelog: [CHANGELOG.md](../CHANGELOG.md)
