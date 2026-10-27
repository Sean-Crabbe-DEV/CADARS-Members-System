# v0.6 feature alignment (27 October 2026)

Compared user-provided `Chepstow_RAYNET_Portal_v0.3.4.1_Full_Source_Code(2).zip` against existing mobile v0.5.2 source.

## Native and already exposed by the supplied backend

| Portal source | Endpoint | iOS v0.6 |
|---|---|---|
| `app/operations_activation.py` | GET `/api/v1/my/activations` | Member inbox + unanswered count |
| same | POST `/api/v1/my/activations/{id}/respond` | Acknowledge, Available, Unavailable, En route, On scene |
| same | GET/POST `/api/v1/control/activations` | Controller issue and list |
| same | GET `/api/v1/control/activations/{id}/responses` | Recipient response roster |
| same | POST `/api/v1/control/activations/{id}/close` | Close activation |
| same | GET `/api/v1/exercises` | Exercise listing |
| same | GET `/api/v1/exercises/{event_id}` | Brief, control updates, message records |
| same | GET `/api/v1/exercises/{event_id}/messages/{id}/pdf` | Private in-memory PDF viewer |
| same | PUT `/api/v1/control/exercises/{event_id}/brief` | Draft / publish briefing |
| same | POST `/api/v1/control/exercises/{event_id}/updates` | Add control update |
| same | POST `/api/v1/control/exercises/{event_id}/messages` | Structured RAYNET message pad |
| `app/mobile_api.py` | GET `/api/v1/alerts/flood` | EA / NRW feed status, warnings list and geolocated map overlays |
| `app/mobile_api.py` | Existing `/api/v1/*` | All previous v0.5.2 app sections retained |

## Requires optional server extension

| Feature | Existing portal | iOS implementation |
|---|---|---|
| Event attendance month, event records, member status | Browser only in `app/attendance_extension.py` | Added controller view and optional bearer API module `server/optional_mobile_attendance/`; **not functional until deployed** |
| Live operational zones | Provisional `reference/operational_zones.default.geojson` exists; no `/api/v1/operational-zones` in baseline | Bundled provisional reference, optional previously written zones endpoint patch; **not live until installed** |

## Web-only limitations (no equivalent API in source)

- Upload a completed PDF message pad (web only)
- Edit attendance using browser forms before the optional mobile attendance add-on is installed
- Administer user roles/passwords (web only)
- No APNs push/SMS/email delivery, so alerts require app-open polling and do not guarantee mobilisation

## Test evidence

- `swiftc -frontend -parse`: passed for all project Swift files on Linux.
- API decoder smoke: 6 v0.3.4 shapes passed, including activation, exercises, attendance and EA/NRW wrapper.
- Grid coordinate smoke: passed existing fixtures.
- `python -m py_compile` passed for optional attendance module.
- Optional operational-zones diff dry-run passed on supplied reconstructed server.
- Full iOS Xcode compiler, device installation and production login **not tested**.

**Source qualification:** The provided v0.3.4.1 README says the source ZIP is reconstructed from updates and is not an exact export of the live server. Avoid replacing your deployed source wholesale.
