# Native iOS v0.5 — 25 October 2026

- Replaced experimental cookie-based `/api/mobile/...` integration with official `/api/v1` bearer-token API.
- Added native account authentication and API-linked operational features.
- Added opt-in local read-only caching, OFF by default, with per-account isolation, file protection and deletion on signout.
- Matched portal CSS (`#0b1420`, `#111e2c`, `#3de0cc`, `#f6b651`) and bundled the portal's logo as the app icon.
- Built-in Location Services + approximate British National Grid; optional external postcode and what3words lookup.
- GitHub Actions unsigned iOS IPA build included.

### Verification
Swift parser: PASS; British National Grid smoke: PASS; six backend-shape JSON decoding tests: PASS; Foundation API client and cache passed Linux type-checks with Apple-only framework placeholders. Not yet built with Xcode and not tested against live production API.

## v0.5.1 — Build fixes

- Fixed Swift implicit `catch error` shadowing (all reported occurrences).
- Replaced XcodeGen recursive source inclusion with an explicit source file list to avoid stale duplicate type declarations.
- Added preconcurrency annotation for CoreLocation delegate warning.

## v0.6 — Portal v0.3.4.1 compatibility (27 October 2026)

- Added member activation inbox and controller issue/roster/close via bearer API.
- Added published exercises, control updates, message pads and PDF viewing; controllers can publish briefs and submit messages.
- Added optional native event attendance (requires separate, expressly documented backend add-on).
- Expanded EA/NRW provider flood status and warning records in alerts screen, with optional maps of official geolocated features.
- Exposed Alerts on the tab bar and linked new screens from Operations.
- Preserved opt-in offline cache, logo branding, map and existing member features.
- Fixed URL query handling and invalidation of operational snapshots after writes.
- Syntax and response decoding tests passed; macOS Xcode build not available in this environment.
