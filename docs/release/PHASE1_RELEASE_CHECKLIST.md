# Phase 1 Release Candidate Audit & Checklist

**Audited HEAD:** `af763b0` (`feat(lms): add external and embedded video content items`)  
**Branch:** `demo/mode-a-user-demo` (base: `main`)  
**Date:** 2026-09-06  
**Scope:** Admissions · Payments · Notifications · Video · Learning · Certificates · Branding  
**Nature:** Release-candidate readiness for a **facilitated Phase 1 customer demo / UAT**, not a claim of full production readiness.

**Related docs:**  
[DEMO_SCRIPT.md](../demo/DEMO_SCRIPT.md) · [PHASE1_CUSTOMER_DEMO_ACCEPTANCE_CHECKLIST.md](../demo/PHASE1_CUSTOMER_DEMO_ACCEPTANCE_CHECKLIST.md) · [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) · [RC01_RELEASE_CHECKLIST.md](../releases/RC01_RELEASE_CHECKLIST.md) · [RAZORPAY_CONFIGURATION.md](../deployment/RAZORPAY_CONFIGURATION.md) · [EMAIL_CONFIGURATION.md](../deployment/EMAIL_CONFIGURATION.md)

---

## Verdict

| Question | Answer |
|---|---|
| Can a skilled facilitator demo Mode A admit → Phase 1 player (incl. video) → MCQ → certificate → public verify? | **Yes**, with seeded personas, `demo:prepare` / `demo:process`, and careful batch choice. |
| Are customer-facing flows free of traps? | **No** — several UX and demo-script traps remain (listed below). |
| Is this deployable as a production pilot? | **No** — open gates: real Razorpay, mail, S3/malware, supervised workers, MFA UI, alerts/backup. |

**Release posture:** Acceptable as a **demo/UAT RC** after clearing the checklist below and disposing documented majors. **Not** a production go-live candidate.

---

## 1. Customer-facing bugs & fragile UX

Severity key: **Blocker** (breaks demo/pilot), **Major** (confuses customer / wrong outcome), **Minor** (polish).

### Admissions

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| ADM-1 | Major | `learner@` is **already admitted** on Phase 1 batch — cannot re-apply for a live Mode A walkthrough on that course | `UatSeedService` (`UAT-PHASE1-LEARN-001`); DraftApplication conflict |
| ADM-2 | Major | Obesity catalogue open batch (`WP02-DEMO-OBESITY-101-OPEN`) has **future `starts_at`** → Admit creates **Scheduled** enrolment → no “Continue learning” | `Wp02DemoCatalogueSeeder`; dashboard copy |
| ADM-3 | Major | Document scan gate: after upload/submit, reviewer queue stalls until `document:scan` / `demo:process` | Demo/ops docs; easy freeze for non-technical facilitator |
| ADM-4 | Minor | No learner **Cancel** from `payment_pending` (known product deferral) | `PRODUCTION_READINESS_REGISTER` / OD-APP-CANCELLED |
| ADM-5 | Minor | Login has Forgot password but **no Register link**; self-serve onboarding is awkward | `templates/pages/auth/login.php` |
| ADM-6 | Minor | Live Course Admin path lacks UI for eligibility / document requirements → new courses can be zero-doc Mode A | Prior readiness audit; weak verify-then-pay story |

### Payments

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| PAY-1 | Blocker* | **“Confirming payment…”** does not auto-advance; without webhook worker / `demo:process`, screen appears stuck | `payment_result.php`; by design (Rule 4) but demo-fragile |
| PAY-2 | Major | Capacity-after-pay → `reconciliation_pending` / no Enrolment; learner sees generic “under verification” | WP-06 notes; opaque finance follow-up |
| PAY-3 | Minor | Second payment initiate blocked while prior is `created`/`pending` — retry only after fail/cancel/expire | WP-05 notes |
| PAY-4 | — | Browser return is informational only (correct) — still easy for customers to misread as “paid” | AGENTS Rule 4 |

\*Blocker for **unattended** demo; **Major** if facilitator runs `demo:process` reliably.

### Notifications

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| NTF-1 | Major | Email delivery depends on workers (`notification:deliver` / `demo:process`); local_file adapter writes files, not inbox | `EMAIL_CONFIGURATION.md`, `WORKERS_AND_SCHEDULES.md` |
| NTF-2 | Major | `certificate.issued` email (when enqueued on live issuance) links to **auth-gated** `/certificates/{id}`, not public verify URL — forwarded links hit login | Notification templates / context resolver |
| NTF-3 | Major | Seeded cert persona inserts DB rows **without** outbox email — Path B demo does **not** prove certificate email | `UatSeedService` Phase 1 cert scenario |
| NTF-4 | Minor | Docs drift: `EMAIL_ARCHITECTURE_REVIEW.md` still claims no SMTP / no certificate email — **false** vs current code + `EMAIL_CONFIGURATION.md` | Ops cutover risk if review is treated as truth |

### Video

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| VID-1 | Major | Completion is **honor-system** — Mark complete without watching (accepted Phase 1 scope; still customer-visible) | `MarkContentCompleteService`, learner `item.php` |
| VID-2 | Minor | External link opens new tab; no in-LMS player (by design) | Delivery mode `external_link` |
| VID-3 | Major† | AGENTS.md stack says Mux/Cloudflare Stream; Phase 1 ships **YouTube/Vimeo embed + HTTPS links** only — needs product sign-off as intentional exception | `AGENTS.md`, `SafeVideoEmbedBuilder` |
| VID-4 | — | CSP `frame-src` allows approved embed hosts (OK for YouTube/Vimeo) | `SecurityHeaderPolicy` |

†Process/compliance risk, not a runtime bug.

### Learning

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| LRN-1 | Major | **PDF content type** shows placeholder; no download/viewer — authors can still Mark complete | `templates/pages/learning/item.php` |
| LRN-2 | Major | After MCQ pass/fail: **Back to outline** only — no certificate CTA | `templates/pages/learning/attempt.php` |
| LRN-3 | Major | Certificate issuance is **silent** (no flash / weak dashboard CTA) — easy to miss | Issuance on mark-complete / assessment pass |
| LRN-4 | Major | Assessment answers: **manual Save only** — no autosave / `beforeunload` | Conflicts with AGENTS high-risk expectation #9 |
| LRN-5 | Minor | Attempt timers / cooldown not exposed on admin config path (null) | `AssessmentConfigService` |
| LRN-6 | Minor | Seeded `learner@` has **first item (video) pre-completed** — happy-path script may skip embed UX | `UatSeedService` markable-lessons progress |

### Certificates

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| CERT-1 | Major | Missing `certificate_name` blocks issuance while eligibility may look “done” — player does not nudge | `CertificateIssuanceService` / list messaging |
| CERT-2 | Minor | Public verify works logged out but has **no discovery** (no guest nav / search) | `/verify/certificates/{number}` |
| CERT-3 | Minor | No revoke UI / QR / designer (out of Phase 1) | Demo out-of-scope lists |
| CERT-4 | Minor | PDF branding: remote logo disabled; HTML show page thinner than PDF | `SimpleCertificatePdfRenderer`, cert templates |

### Branding

| ID | Sev | Issue | Evidence / notes |
|---|---|---|---|
| BR-1 | Major | CSP `img-src 'self' data:` **blocks remote** `ACADEMY_LOGO_URL` (https CDN logo will not render) | `SecurityHeaderPolicy` vs `AcademyBranding` |
| BR-2 | Minor | Branding is **env-only** — wrong/missing `ACADEMY_*` → default name/color on UI + PDF issuer | `.env.example`, `config/app.php` |
| BR-3 | Minor | Obesity catalogue FAQ still claims player/certs out of scope | `Wp02DemoCatalogueSeeder` FAQ drift |

---

## 2. Missing demo flows

| Gap | Impact | Mitigation today |
|---|---|---|
| Live Mode A on Phase 1 batch with `learner@` | Cannot show fresh apply→pay→admit on Phase 1 course | Use a **new registrant**, or obesity Mode A + explain Scheduled, or skip to seeded learner |
| Script prefers “fast path: skip live admit” | Weakens customer proof of verify→pay→admit | Force live path with fresh user + `demo:process` |
| Demo docs still list **video as out of demo** | Facilitator omits video; seed already has YouTube lesson | Update `DEMO_SCRIPT.md` / acceptance checklist / readiness checklist |
| No seeded **external_link** / Vimeo / youtube-nocookie | Admin dual modes not shown unless improvised | Course Admin creates second video item live |
| Seeded learner skips video embed | First markable item already complete | Reset progress, use second browser as new learner, or uncomplete first item in DB (facilitation only) |
| Real Razorpay Checkout not in demo script | Customer may assume fake button = production | Keep fake for demo; schedule separate Razorpay test-mode beat |
| Correction / reject / resubmit not in Phase 1 customer script | Markers exist (`UAT-CORRECT-001`, `UAT-REJECT-001`) but unused | Optional UAT beat, not customer script |
| Capacity-exhausted story not in customer script | `UAT-FULLBATCH-001` unused in demo beats | Optional finance beat |
| Certificate **email** not demonstrated by Path B | Email only on live issuance + workers | Complete pathway live with mail adapter configured |
| Live-built course Mode A with real docs | No Course Admin doc-requirement UI | Prefer seeded catalogue courses for admissions |
| Self-register → apply → pay E2E | Not a first-class demo beat | Add if customer cares about acquisition UX |

**Still well covered by seed:** Course Admin curriculum on Phase 1 course · Reviewer approve · Fake pay + Confirming · Active enrolment learning · MCQ · Issued cert + public verify · Finance SoD denial.

---

## 3. Deployment blockers

### Must clear before customer-hosted demo (non-local)

| ID | Gate | Blocker detail |
|---|---|---|
| DEP-1 | Migrations | Run Phinx through **`20260906000001_wp_l10_video_content_items`** before video seed/admin create |
| DEP-2 | Workers / cron (`PR-CRON`) | Schedule or manually run: `payment:webhook-process`, `document:scan`, `outbox:relay`, `notification:deliver` (local: `composer demo-process`) |
| DEP-3 | Payments (`PR-RZP`) | Staging/production: real `RAZORPAY_*` secrets; `PAYMENTS_FAKE_GATEWAY` forbidden; public webhook URL |
| DEP-4 | Email (`PR-EMAIL`) | Staging/production reject `local_file`/`recording`; configure `MAIL_DRIVER=smtp|ses` + from-address |
| DEP-5 | Documents (`PR-S3`, `PR-MALWARE`) | Private S3 + malware scanner required for pilot; local disk / fake scanner UAT-only |
| DEP-6 | Secrets | UAT/staging: explicit peppers/keys — soft defaults not allowed outside local |
| DEP-7 | Branding env | Set `ACADEMY_NAME`, `ACADEMY_LOGO_URL` (prefer **same-origin** path until CSP fixed), `ACADEMY_PRIMARY_COLOR`, `ACADEMY_SUPPORT_EMAIL`, `ACADEMY_CERTIFICATE_ISSUER_NAME` |
| DEP-8 | Seed guards | `uat:seed` / `uat:reset` refuse staging/production — demo data must be prepared in allowed envs only |
| DEP-9 | Health | `GET /health/live` and `/health/ready` green under configured adapters |

### Must clear before production pilot (not Phase 1 demo)

| ID | Gate | Notes |
|---|---|---|
| PROD-1 | MFA UI for privileged roles | AGENTS §7.3; enrol/challenge UI incomplete |
| PROD-2 | Alerts / backup / load / pen-test | `PR-ALERT`, `PR-BACKUP`, `PR-LOAD`, `PR-SEC` |
| PROD-3 | Refunds / cancel application | `PR-REFUND`, `PR-CANCEL` |
| PROD-4 | SMS OTP provider | `PR-SMS` if mobile verification required |
| PROD-5 | Register / Decision Log drift | Player/assess/cert still marked “future” in places; email architecture review stale — triage before go-live claims |
| PROD-6 | Video product exception | Sign off YouTube/Vimeo/external vs Mux/CF Stream architecture rule |

---

## 4. Area status summary

| Area | Demo RC | Pilot | Notes |
|---|---|---|---|
| Admissions (Mode A) | Ready with traps | Partial | Workers + batch timing + seed conflicts |
| Payments | Ready with fake gateway | Blocked | Real Razorpay + webhook workers |
| Notifications | Partial | Blocked | Workers + real mail; cert email path fragile |
| Video | Ready (embed/link) | Partial | Honor-system complete; no hosting; AGENTS exception |
| Learning | Ready with traps | Partial | PDF placeholder; weak post-MCQ CTAs; no autosave |
| Certificates | Ready | Partial | Silent issue; public verify; email/PDF branding gaps |
| Branding | Ready if logo same-origin | Partial | Remote logo vs CSP |

---

## 5. Release checklist (execute before demo/UAT handoff)

### A. Engineering gates

| # | Gate | Pass? | Notes |
|---|---|---|---|
| A1 | Approved commit/tag recorded (`af763b0` or later) | ☐ | |
| A2 | CI green (PHPUnit / PHPStan / CS as required) | ☐ | Include `VideoContentHttpTest`, demo seeder test |
| A3 | Fresh migrate includes WP-L10 video columns + CHECKs | ☐ | |
| A4 | `demo-prepare` / `uat:seed` succeeds on target env | ☐ | `APP_ENV` ∈ local\|testing\|ci\|uat |
| A5 | Phase 1 course content types = `video`, `text_lesson`, `mcq_assessment` | ☐ | After migrate + reseed if DB predated WP-L10 |
| A6 | `demo-process` (or supervised workers) documented for facilitator | ☐ | After docs + after payment |
| A7 | Health endpoints green | ☐ | |
| A8 | Production readiness register reviewed — nothing silently closed | ☐ | |

### B. Demo beat verification

| # | Beat | Pass? | Notes |
|---|---|---|---|
| B1 | Course Admin: create/edit **Video** (embedded YouTube) + text + MCQ | ☐ | |
| B2 | Course Admin: reject unsafe embed / HTTP URL (friendly error) | ☐ | |
| B3 | Learner: embedded player renders (iframe) | ☐ | May need incomplete video item |
| B4 | Learner: external link shows **Watch Video** | ☐ | Live-create if not seeded |
| B5 | Learner: Mark complete on video (manual) | ☐ | |
| B6 | Learner: text lesson + MCQ attempt/pass | ☐ | |
| B7 | Certificate list → show → PDF → public verify (logged out) | ☐ | |
| B8 | Mode A: review → pay → Confirming → process → Admitted + Enrolment | ☐ | Use fresh learner or non-Phase-1 batch; expect Scheduled on future batch |
| B9 | Finance cannot open document URLs (SoD) | ☐ | |
| B10 | Branding: academy name/color visible on chrome + cert | ☐ | Logo same-origin or accept missing |

### C. Doc / facilitator sync (pre-demo)

| # | Action | Pass? |
|---|---|---|
| C1 | Update demo script/checklists: video is **in** Phase 1 demo | ☐ |
| C2 | Call out PDF type as non-downloadable | ☐ |
| C3 | Call out Confirming… + `demo:process` dependency | ☐ |
| C4 | Prefer Phase 1 course (`PHASE1-DEMO-CME-101`) for learning beats | ☐ |
| C5 | Do not use `EMAIL_ARCHITECTURE_REVIEW.md` as cutover truth until rewritten | ☐ |

### D. Sign-off

| Role | Name | Date | Ack |
|---|---|---|---|
| Engineering | | | ☐ |
| Demo facilitator / QA | | | ☐ |
| Product Owner | | | ☐ |
| Operations (if hosted) | | | ☐ |

**Pass rule for demo RC:** No open **Blockers**; every **Major** either fixed, mitigated in facilitator notes, or PO-accepted with disposition.  
**Pass rule for production pilot:** All DEP-\* and PROD-\* gates closed or explicitly deferred in Decision Log — **not claimed by this checklist**.

---

## 6. Explicitly out of Phase 1 (do not treat as defects)

- Video upload, transcoding, CDN, DRM, adaptive streaming, watch-time analytics  
- Mux / Cloudflare Stream hosting integration  
- Certificate designer, revoke UI, QR codes  
- Mode B/C admissions, automated refunds, invoices, offline mark-paid  
- Faculty role, CourseVersion under-review workflow  
- Assessment proctoring / randomisation UX polish  
- Multi-tenant branding admin UI  

---

## 7. Recommended next actions (priority)

1. **Facilitator doc sync** — video in-scope; PDF trap; Confirming… + workers.  
2. **Branding CSP** — allow configured logo host or ship logo as same-origin asset.  
3. **Demo seed tweak** — leave first video incomplete for `learner@`, or add a second incomplete video for embed demo.  
4. **Post-MCQ / certificate CTAs** — reduce silent-issuance miss rate.  
5. **Rewrite or quarantine** `EMAIL_ARCHITECTURE_REVIEW.md` to match SMTP + `certificate.issued` reality.  
6. **Product sign-off** — YouTube/Vimeo/external vs AGENTS Mux/CF Stream rule.  
7. **Fresh-learner Mode A beat** — document exact persona/batch for live admit without seed conflict.

---

*Audit only — no code changes in this document’s production. Checklist boxes are for human execution at release time.*
