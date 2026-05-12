# Supervisor Module Compliance Audit

Date checked: 2026-05-11

This audit compares the current implementation against the supervisor improvement guide. It focuses on implemented runtime behavior, database support, and remaining gaps that still matter for evaluation.

## Current Compliance Snapshot

| Supervisor Area | Current Status | Evidence / Notes |
| --- | --- | --- |
| User authentication and onboarding | Partial | Login/register/profile exist. Guest demo mode was removed so only registered and active users can use the system. Remember-me support remains. OAuth is intentionally not expanded into a separate module yet. |
| Traveller preference personalization | Mostly implemented after latest patch | 3-step wizard exists. Added traveller type, pace, budget tier, dietary preference, preferred visit time, accessibility notes. Normalized junction tables now exist and are used for new saves/generation. |
| Preference database normalization | Implemented with compatibility | Existing `preference_interests` / `preference_states` remain for backward compatibility. Added supervisor-style `traveller_preference_interests(preference_id, interest_id)` and `traveller_preference_states(preference_id, state_id)` with lookup tables. |
| Smart itinerary generator | Mostly implemented | Uses place FK, entrance fees, visit durations, opening-hour checks, state/district filtering, nearest-next route ordering, food priority, no duplicate place reuse, review/replace workflow. |
| Geographic practicality | Partial to good | Places are grouped by state/district and day distance is capped by transport mode. Still not a full route optimizer, but functionally addresses the supervisor's impractical-distance issue. |
| Time-slotted schedule | Implemented | `itinerary_items.start_time` and `end_time` are stored using visit duration and travel time. Preferred visit time now affects daily start time. |
| Opening hours awareness | Implemented with best-effort parser | Closed records are skipped when a recognizable opening-hour range conflicts with the schedule. Non-standard opening-hour text is not rejected. |
| Maps and route integration | Mostly implemented | Google Maps JS route uses transport mode mapping. Transit route service exists. Public transport still depends on Google API availability and valid routes. |
| Cost estimation and trip summary | Implemented | Cost service includes attractions, transport, accommodation, and meals. Selected hotel cost is included. Budget tier now affects default hotel/meal estimates. |
| Cultural guide content quality | Mostly implemented | Image URL/path, place images, rating/review UI, halal badge, visit duration, best time, dress code, nearby food/hotel support are present. Wishlist table exists; verify UI coverage before final demo. |
| Crowdsourcing | Mostly implemented | Traveller suggestion form has Google Maps pin picker, Google Places autocomplete, image upload, duplicate warning, and admin approval/rejection workflow. |
| Admin panel and knowledge base | Mostly implemented | Dashboard analytics, data lists, CSV bulk import, duplicate detection, reports, AI analysis, audit table, and user management exist. |
| Export and sharing | Mostly implemented | PDF export and public share token links exist. WhatsApp sharing exists in shared view. PDF now attempts a Google Static Maps thumbnail when the API is enabled. Google Calendar export is left out to keep scope simple. |
| AI features | Implemented as controlled assistant | Ollama-backed AI draft helper is embedded inside Smart Itinerary Generator, and AI chatbox remains inside itinerary review/view/summary pages. Admin report analysis also uses Ollama. No separate traveller AI module is exposed. |

## Remaining High-Value Gaps

1. Authentication polish: forgot password and email verification can be added later if the evaluator asks, but avoid expanding into OAuth unless required.
2. Keep the demo focused on core flow: preference wizard -> itinerary generation -> review/replace -> map/cost -> AI chat -> admin report.
3. Final report text must be updated: it still describes older functionality such as entrance-fee-only cost estimation and legacy preference storage.

## Latest Patch Summary

- Added personalization columns to `traveller_preferences`.
- Added lookup tables `travel_interests`, `malaysia_states`.
- Added supervisor-style junction tables `traveller_preference_interests` and `traveller_preference_states`.
- Updated preference saving to write both compatibility tables and normalized junction tables.
- Updated itinerary generation to read normalized interests/states first.
- Added halal filtering for food when halal preference is selected.
- Added pace and preferred visit time influence to generated schedules.
- Added budget-tier defaults for hotel and meal estimates.
- Updated admin reports to count preference interests/states from normalized tables.
- Updated `travel_itinerary_db.sql` so a fresh import contains the new schema.
- Removed guest demo path to keep system access restricted to verified/login users.
- Added remember-me support without adding extra dashboard modules.
- Added conditional Google Static Maps thumbnail support to PDF export.
