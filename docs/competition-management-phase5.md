# Competition Management — Phase 5 (current implementation)

## Ownership boundary

`App\Modules\SchoolOperations\Competitions` is the source of truth for competition-specific state: competition/edition/category configuration, registration status, eligibility decisions, competition participants/teams, rounds/fixtures, judge assignments, scorecards and amendments, results/rankings, awards, appeals/incidents and audit/outbox records.

It does **not** own the student, guardian, employee, attendance, finance, facility, certificate, or achievement master. Student and guardian identity use the existing Activity provider contracts; approved student awards are sent to the existing Student Activity achievement service. The old Student Activity competition UI now routes to the core Competition workspace; its legacy API/schema remains available for compatibility and should be migrated/deprecated in a separate compatibility phase.

## Installation and seed data

The Competition service provider is registered in `backend/bootstrap/providers.php`. It registers the 14 module migrations, API routes, rate limits, and provider bindings.

```bash
cd backend
php artisan migrate
php artisan db:seed --class='App\Modules\SchoolOperations\Competitions\Database\Seeders\CompetitionPermissionSeeder'
php artisan db:seed --class='App\Modules\SchoolOperations\Competitions\Database\Seeders\CompetitionMasterSeeder'
# Opsional: contoh 6 kompetisi + kategori/rubric; hanya jalan di local/testing.
php artisan db:seed --class='App\Modules\SchoolOperations\Competitions\Database\Seeders\CompetitionDemoSeeder'
```

The master seeder adds domains, types, extensible formats, levels/divisions, and six reusable templates (math olympiad, debate, basketball, tahfidz, speech, coding). It deliberately does not add fake live competitions to a production database.

## Core REST API

All authenticated endpoints are under `/api/v1`; bearer authentication is Sanctum and authorization is Spatie RBAC. Paginated Laravel responses are wrapped in `{success,message,data:{data,meta...}}`; validation errors use HTTP 422 and the ERP error envelope.

| Area | Routes |
|---|---|
| Catalog/dashboard | `GET /competitions`, `GET /competitions/meta`, `GET /competitions/dashboard`, `POST /competitions`, `GET/PATCH/DELETE /competitions/{id}` |
| Editions/templates | `GET /competition-templates`, `GET/POST /competitions/{id}/editions`, `POST /competitions/{id}/editions/from-template`, `GET/PATCH /competition-editions/{id}`, `POST .../publish`, `POST .../open-registration` |
| Categories/rubrics | `GET/POST /competition-editions/{id}/categories`, `GET/POST /competition-editions/{id}/rubrics` |
| Discover/register | `GET /competition-discover`, `GET /my-competitions`, `GET/POST /competition-editions/{id}/registrations`, `POST /competition-registrations/{id}/submit`, `.../evaluate-eligibility`, `/approve`, `/reject`, `/needs-revision`, `/withdraw` |
| Consent/documents/payment | `POST /competition-consents/{id}/decide`, registration `/documents`, document `/review` and authenticated `/download`, payment `/sync-payment` and audited `/waive-payment` |
| Participants/teams | edition `/participants`, `/teams`, team `/lock-roster`, waitlist `/confirm` |
| Operations | edition `/rounds`, round `/generate-fixtures`, edition `/matches` and `/sessions`, match `/schedule`, `/start`, `/pause`, `/resume`, `/complete`, `/postpone`, `/cancel`, edition `/schedule-conflicts` |
| Judges/scoring | `GET/POST /competition-judges`, edition `/judge-assignments`, `/judge/assignments`, match `/scorecards`, scorecard `/submit`, `/finalize`, `/amendments`, amendment `/decide`, `POST /competition-penalties` |
| Results/awards | edition `/results`, `/results/finalize`, `/results/publish`, `/awards`, award `/results/{result}`, recipient `/certificate` |
| Governance | edition `/protests`, protest `/appeals`, edition `/appeals`, appeal `/decide`, edition `/incidents`, incident `/resolve`, appeal committees and members, edition `/audit` |
| Public | `GET /api/v1/public/competitions/{slug}` returns only a published public edition, active categories and published results |

## Business rules implemented

- Edition registration windows and status are checked server-side.
- Registration is idempotent and duplicate participants are blocked by default; category settings can explicitly allow duplicates.
- Eligibility rules are data-driven (operator/value/type) and evaluations retain rule-by-rule evidence. Missing provider evidence yields `NEEDS_REVIEW`, not an automatic pass.
- Approval is transactional and checks eligibility, required documents, parent consent, Finance payment status, category/edition capacity and roster lock before creating the competition participant.
- Team roster constraints and roster locking are enforced. Withdrawals offer an available slot to the next priority waitlist entry; acceptance is an explicit step.
- Knockout, round-robin, and first-round Swiss fixture generation are implemented. Match/session room conflicts consult the existing Facility provider when a room is selected; in-competition participant/venue conflicts are also checked.
- Judge assignments are checked for active status, declared conflicts and overlapping assignments. Judges can score only their own assigned match/round/session/category unless acting with review permissions.
- Numeric/weighted rubric scores validate criterion membership, range, step, required fields and required comments. Scorecards submit, finalize and lock; amendments preserve prior snapshots in `competition_score_versions`.
- Result finalization aggregates judge scorecards, penalties, category aggregation and tie-break settings. Published result rows are locked. Result versions and audit snapshots are retained when a not-yet-published result is recalculated.
- Ranking-based awards can be auto-assigned from finalized results. Individual student award references are synchronized through the Activity achievement provider. Audit rows are append-only at the model layer; domain events are persisted to an outbox table and dispatched after commit.

## Permissions

The permission seeder creates the requested competition, registration, participant/team, scheduling, judge, score, result, award, certificate, appeal, report/export/audit and portal permissions. It adds grants to Competition roles and compatible existing roles without replacing unrelated grants.

## Verification

- `php artisan test --filter=CompetitionFlowTest`: 13 tests, 46 assertions, passing. This covers the golden flow, score validation/amendment versions, registration idempotency, eligibility override, guardian consent, student discovery/self-registration, template instantiation, knockout fixtures, judge conflict review, schedule conflict rejection and achievement-provider handoff.
- `npm run build`: passing (Vite reports existing large-bundle and asset-import chunking warnings).
- Browser smoke tests: desktop admin overview/workspace, mobile tabs, student discover and public competition page rendered. Final layout audits reported no overflow, clipping, or contrast issues.

## Explicit remaining integration work

This is a tested vertical slice, **not the entirety of the 113-point phase**. Provider contracts exist, but a production adapter is still required/configured for Certificate generation, Finance charges/status, Notification delivery, Attendance context, Event orchestration, Inventory/Assets, Transportation, Catering, Academic calendar, Clinic, Identity/RFID, plagiarism/file scanning, weather and offline sync. Until a Certificate provider is configured, the system creates a `PENDING_PROVIDER` reference record rather than fabricating a certificate number or QR. External result CSV/XLSX imports, full Swiss re-pairing, group-to-knockout progression, live realtime scoreboard/control-center, full scheduling integration across academic/boarding/religious/event calendars, and a complete appeal evidence UI remain follow-up work.

The repository also has an unrelated existing `route:list` failure because an HRD route points to the missing `App\Modules\Hrd\Http\Controllers\HrdEmployeeController`; Competition HTTP feature tests run directly and pass, but that global route-list issue was not changed here.
