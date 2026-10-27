# Chepstow RAYNET iOS Companion — v0.6 (source)

A native SwiftUI iPhone/iPad client for **Chepstow RAYNET MIS portal v0.3.4.1** and its bearer-token Mobile API v1. Source is based on the provided **reconstructed** v0.3.4.1 portal archive; it is not verified to match the deployed server byte-for-byte.

## New in v0.6

- Member **My alerts** inbox via `GET /api/v1/my/activations`, with acknowledgements and availability responses (`POST .../respond`). Polls every 60 seconds **only while the view is active**. Includes dashboard unanswered count and an Alerts tab.
- Controller/admin **Activation control**: issue to selected members or all active members, view response roster, close activations. All actions use the existing server-side role checks.
- **Exercise** index/detail, published briefing, control updates, structured RAYNET message pads and authorised PDF download (online/in-memory only). Controllers can edit/publish briefs, post updates and add structured messages.
- **Attendance**: a native controller screen with month/event/individual attendance recording. **IMPORTANT: native attendance requires an optional, separately deployed server add-on** included at `server/optional_mobile_attendance/`. The stock v0.3.4.1 attendance routes are browser-session forms and will NOT work using API bearer tokens.
- Improved **EA/NRW flood warnings** screen showing per-provider feed state, count, text, official source link and unlocated warning totals where the API supplies them. Optional flood-warning map layers plot only official Point/Polygon/MultiPolygon geometries returned by those sources.
- Existing operational map, provisional operational boundary, optional live zones, weather warning overlays, MIS pins and NGED power markers remain intact.
- Fixed query-string URL construction in the client (required by native attendance), added snapshot invalidation for new operations, preserved encrypted-at-rest device-only credentials and offline cache design.

## Existing features retained

- Native portal username/password sign-in; bearer token stored in iOS Keychain and validated by `/api/v1/me` on startup, automatic sign-out on HTTP 401.
- Dashboard with UTC clock, current GPS, approximate OS grid reference, easting/northing, GPS accuracy, optional postcode lookup, key-based what3words lookup and a no-key link to the official what3words app.
- Event calendar/RSVPs, equipment and availability, profile edits, training/experience, group actions, manuals/PDFs, controller member directory and approvals.
- Dark navy and teal portal colour palette, RAYNET logo app icon.
- **Opt-in read-only offline cache, OFF by default**, and deletion on logout or when disabled. Cached feeds may be stale; no offline write queue or PDFs, and offline mode is not an alert-dispatch mechanism.

## Deployment: Windows + GitHub Actions + sideloading

1. Download/extract `Chepstow_RAYNET_iOS_v0.6_GitHub_Update.zip`.
2. Replace the complete `ChepstowRAYNET/` folder in your cloned `Sean-Crabbe-DEV/Chepstow-RAYNET-IOS` repository; copy `project.yml`, `.github/workflows/build-unsigned-ipa.yml` and `tests/` to the repo root. Don't merge old Swift files — earlier source contained duplicate types.
3. Remove obsolete `.github/workflows/swift.yml` if it builds a Swift Package instead of the iOS app.
4. Commit to `main`, run **Build unsigned iOS IPA** in GitHub Actions, then download `ChepstowRAYNET-unsigned-IPA` from a successful run.
5. Use your existing Windows signer/sideloading method to sign/install the IPA. A normal Apple ID's free provisioning is temporary and may need refreshing.
6. Test login, member inbox, exercise detail, controller functions (if authorised), alerts and offline-cache toggle on the iPhone.

The existing server must have the v0.3.4 operations API actually **deployed**. Verify routes with a valid bearer token and use the production API specification: `/api/v1/operations/openapi.json` (the older `/api/v1/openapi.json` does not include the new extension).

## Optional server changes — NOT installed by this ZIP

**Attendance:** see `server/optional_mobile_attendance/INSTALL.txt` for a separate controller-only attendance API. This is a new add-on, not part of the attached portal. Back up PostgreSQL, check actual deployed source, test in staging, and only then deploy on the live server.

**Operational zones:** `server/mobile-api-operational-zones.patch` is an optional extension to expose verified GeoJSON polygons to the iOS app. The bundled reference boundary is labelled provisional; zones do not exist unless defined and served by the backend. This patch applies cleanly to the supplied reconstructed v0.3.4.1 source with `patch -p0`, but live-source differences may prevent it from applying. Never overwrite the production portal with the reconstructed ZIP.

## Limitations

- **No Apple APNs push**: background or closed-app mobilisation notifications are NOT implemented by the backend. Follow normal RAYNET radio and phone procedures for urgent alerts.
- No new API support for PDF **uploads** (web page only), account administration, SMS/email notifications, or direct access to PostgreSQL. Do not put admin credentials or tokens in the repository.
- what3words addresses cannot be computed locally from GPS using this project without a licensed SDK/service. Optional API key or external official app is still required.
- The OS grid uses an approximate transformation; not OSTN15 surveying accuracy. External postcode/w3w lookups only occur when requested.
- No macOS/Xcode SDK exists in this environment. Source syntax and API payload tests pass; **a complete Xcode/iPhone build and the live server API were not verified**.

## Validation commands on Swift/Linux

```bash
swiftc -frontend -parse $(find ChepstowRAYNET -name '*.swift')
swiftc ChepstowRAYNET/Core/MobileModels.swift ChepstowRAYNET/Core/OperationsModels.swift tests/operations-api-smoke.swift -o /tmp/ops  # top-level test source should be copied/renamed main.swift for Linux
cp tests/operations-api-smoke.swift /tmp/main.swift
swiftc ChepstowRAYNET/Core/MobileModels.swift ChepstowRAYNET/Core/OperationsModels.swift /tmp/main.swift -o /tmp/ops && /tmp/ops
swiftc ChepstowRAYNET/Core/BritishNationalGrid.swift tests/main.swift -o /tmp/grid && /tmp/grid
python -m py_compile server/optional_mobile_attendance/mobile_attendance_api.py
```

See `CHANGELOG_v0.6.md` for the source-derived endpoint comparison.
