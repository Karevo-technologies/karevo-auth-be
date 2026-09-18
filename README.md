# K-ID Backend - Patient + Organisation - PHP + MySQL

## Patient - All Endpoints
- POST /api/patient/register - auto-generates KID-20260001 format, never patient-editable
- POST /api/patient/login (email/phone/kid_number)
- GET /api/patient/profile | PATCH /api/patient/profile (forbidden fields 403)
- GET /api/patient/records | GET /api/patient/records/:id (RECORD_NOT_OWNED check)
- GET /api/patient/requests?status=&type=
- POST /api/patient/requests/:id/approve -> QR 10min + organisation_access insert + activity + notification
- POST /api/patient/requests/:id/decline
- GET /api/patient/qr/:requestId -> QR_USED / QR_EXPIRED 410
- POST /api/qr/scan -> marks used BEFORE returning record (security critical), ACCESS_REVOKED check for org, invalidates
- GET /api/patient/activity?limit=&offset= (append-only)
- GET /api/patient/notifications | PATCH read / read-all
- GET /api/patient/organisations | DELETE /api/patient/organisations/:orgId/revoke -> invalidates QR immediately
- GET /api/patient/dashboard -> total_records, pending, approved, unread, 3 recent

## Organisation - All Endpoints
- POST /api/org/register -> pending, CAC UNIQUE validation
- POST /api/org/login -> works regardless verification_status
- GET /api/org/me
- GET /api/org/patients/search?kid=KID-20260001 -> only first_name, last_name, kid_number (Rule 14,36)
- POST /api/org/requests -> requester_type='organisation'
- GET /api/org/requests?status= -> filtered by own org_id only
- GET /api/org/requests/:id/record -> checks BOTH request status AND organisation_access.active -> ACCESS_REVOKED
- GET /api/org/dashboard -> active_access_count from organisation_access not history

## Provider Helper (to send records)
- POST /api/provider/register/login/records/requests - only verified providers can send (Rule 10)

## Shared Tables (single DB)
patients, providers, organisations, health_records, record_requests, qr_codes, activity_log, notifications, organisation_access

## Security Rules Enforced
- Patients never upload/edit own health data (403)
- Only verified providers send records
- QR single-use + 10 min expiry, marked used BEFORE return
- No record content until patient approves
- Revocation immediate + QR invalidation
- Organisation never sends records, only request
- requireVerifiedOrg middleware everywhere sensitive
- Ownership checks, no other org's requests visible
- activity_log append-only, no UPDATE/DELETE in app layer
- Standard response format + error codes: UNAUTHORIZED, FORBIDDEN, NOT_FOUND, VALIDATION_ERROR, QR_EXPIRED, QR_USED, RECORD_NOT_OWNED, REQUEST_NOT_APPROVED, ORG_NOT_VERIFIED, ACCESS_REVOKED, PROVIDER_NOT_VERIFIED

## Render Production
1. Push to Github
2. Render -> New Web Service -> Docker -> reads Dockerfile + render.yaml
3. Set ENV: DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET
4. Run schema.sql once on prod DB
5. Health: /health

## Pre-Handoff Checklist Patient + Org
- All 6+2 tables created with FKs
- K-ID auto-generated
- activity_log no UPDATE/DELETE
- All endpoints listed above work
- QR scan marks used before return
- Revoke invalidates QR
- Org search only name+KID
- Org record view checks BOTH statuses
- Dashboard counts correct

Seed:
- Patient: KID-20260001 / amara@email.com / 08012345678 / password123
- Provider: lab@helixbiogen.ng / password123 (verified)
- Org: studentaffairs@lautech.edu.ng / password123 (verified), pending org also seeded
"# karevo-auth-be" 
