**SCREEN INVENTORY**

Academy LMS - v1.0

Companion to SRS v6.0. Every screen below is traceable to a requirement ID or SRS section. "Wireframed?" marks the subset covered in the accompanying interactive wireframe file (Academy_LMS_Wireframes.html). The wireframe file now includes a Desktop/Mobile view toggle and a consolidated Mobile Adaptation Notes screen - see that file's "Mobile" nav group.

# 1\. Guest / Public

| **ID** | **Screen**                      | **Purpose**                                                                                  | **SRS Reference** | **Wireframed?** |
| ------ | ------------------------------- | -------------------------------------------------------------------------------------------- | ----------------- | --------------- |
| G-01   | Course Catalogue                | Browse and filter published courses                                                          | REQ-CAT-1         | -               |
| G-02   | Course Detail Page              | Syllabus, eligibility, fee (shown inclusive of GST, breakdown beneath), FAQ, Apply/Enrol CTA | REQ-CAT-2         | Yes             |
| G-03   | Registration                    | Sign up via email + mobile + password                                                        | REQ-REG-1         | -               |
| G-04   | Login                           | Email/mobile + password sign-in                                                              | REQ-REG-1         | -               |
| G-05   | Forgot Password                 | Secure reset flow                                                                            | REQ-REG-2         | -               |
| G-06   | Public Certificate Verification | QR/number lookup, minimal public fields                                                      | REQ-CERT-4        | Yes             |

# 2\. Applicant

| **ID** | **Screen**                       | **Purpose**                                                                                                                                                           | **SRS Reference**                | **Wireframed?** |
| ------ | -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- | --------------- |
| A-01   | Personal Profile                 | Legal name, contact, certificate-name confirmation                                                                                                                    | REQ-PROF-1                       | -               |
| A-02   | Professional Profile             | Configurable fields per Professional Category                                                                                                                         | REQ-PROF-2/3                     | -               |
| A-03   | Application Start / Batch Select | Choose course version + batch - critical entry point for REQ-CRS-4's batch-to-version binding                                                                         | REQ-APP-1, REQ-CRS-4             | Yes             |
| A-04   | Document Upload & Status Tracker | Per-requirement upload, live status, resubmission - stepper sequence is generated from the CourseVersion's admission mode, not fixed to Mode A                        | REQ-DOC-1/2, REQ-REV-1, §8.2-8.4 | Yes             |
| A-05   | Application Status Page          | Draft → ... → Admitted/Rejected tracker                                                                                                                               | REQ-APP-1, §18.1                 | -               |
| A-06   | Checkout / Payment               | Gateway selection, UPI/card/net banking; fee always shown inclusive of GST with breakdown                                                                             | REQ-PAY-1-7                      | Yes             |
| A-07   | Payment Receipt / Invoice        | GST invoice view/download                                                                                                                                             | REQ-INV-1/2                      | -               |
| A-08   | Waitlist Status                  | Position + 48-hour offer countdown - time-sensitive, needs to be visible not buried                                                                                   | REQ-BATCH-4, WaitlistEntry       | Yes             |
| A-09   | Payment Status                   | One screen, state-specific variants: Pending, Successful, Failed, Cancelled, Reconciliation Pending, Duplicate Detected, Refund Requested/Processing/Failed/Completed | REQ-PAY-3/4/7, REQ-REFUND-3      | Yes             |

# 3\. Active Learner

| **ID** | **Screen**                        | **Purpose**                                                                                                                                                                                                    | **SRS Reference**            | **Wireframed?** |
| ------ | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | --------------- |
| L-01   | Learner Dashboard                 | Courses, statuses, progress, next action, resume shortcut - includes card-state variants: Scheduled, Active, Suspended, Access Expired, Withdrawn, Batch Cancelled, Transferred, Extension Available/Requested | REQ-DASH-LRN-1, §18.2        | Yes             |
| L-02   | Course Player                     | Module/lesson navigation, video, resume position                                                                                                                                                               | REQ-MOD-2-4, ContentProgress | Yes             |
| L-03   | Live Session Schedule             | Upcoming sessions, join link, prep material                                                                                                                                                                    | REQ-LIVE-1/4                 | -               |
| L-04   | Assessment Attempt Screen         | Timed MCQ attempt, autosave, flag-for-review                                                                                                                                                                   | REQ-EVAL-1-4, REQ-EVAL-6     | Yes             |
| L-05   | Assessment Result / Review        | Score, per-question review where enabled                                                                                                                                                                       | REQ-EVAL-5, REQ-GRADE-4      | Yes             |
| L-06   | Certificates Page                 | Participation/Completion certs, download, verify link                                                                                                                                                          | REQ-CERT-1-3                 | Yes             |
| L-07   | Invoices & Receipts               | Payment history for the learner                                                                                                                                                                                | REQ-INV-1                    | -               |
| L-08   | Support: Ticket List & New Ticket | Raise/track tickets by category                                                                                                                                                                                | REQ-SUP-1/2                  | -               |
| L-09   | Notifications Centre              | In-app notification feed                                                                                                                                                                                       | REQ-NOTIF-1/2                | -               |

# 4\. Faculty & Academic Evaluator

| **ID** | **Screen**                      | **Purpose**                                                                                         | **SRS Reference** | **Wireframed?** |
| ------ | ------------------------------- | --------------------------------------------------------------------------------------------------- | ----------------- | --------------- |
| F-01   | Faculty Dashboard               | Assigned courses/batches, at-risk learners, queries                                                 | REQ-DASH-FAC-1    | -               |
| F-02   | Learner List & Progress         | Per-batch roster with progress/attendance - the primary intervention view for a six-month programme | REQ-DASH-FAC-1    | Yes             |
| F-03   | Attendance Marking              | Manual mark or spreadsheet import                                                                   | REQ-LIVE-2        | -               |
| F-04   | Question Bank Contribution      | Propose/edit questions for review                                                                   | REQ-EVAL-1/2      | Yes             |
| E-01   | Manual Grading / Feedback Queue | Non-MCQ marks, feedback, pass/fail recommendation                                                   | REQ-GRADE-6       | Yes             |

# 5\. Credential Reviewer

| **ID** | **Screen**                 | **Purpose**                                                                                                                                                   | **SRS Reference**         | **Wireframed?** |
| ------ | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------- | --------------- |
| R-01   | Document Review Queue      | Oldest-first default, SLA age, course/batch, document type, resubmission indicator, profession, assigned reviewer, filters, bulk assignment, escalation flags | REQ-REV-1, REQ-DASH-VER-1 | Yes             |
| R-02   | Document Review Detail     | Zoom, compare to profile, approve/reject + reason                                                                                                             | REQ-REV-1/2, REQ-DOC-4    | Yes             |
| R-03   | Verification Audit History | Append-only log per document                                                                                                                                  | REQ-REV-2                 | -               |

# 6\. Course Administrator

| **ID** | **Screen**                          | **Purpose**                                                                                                                                                               | **SRS Reference**                  | **Wireframed?** |
| ------ | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- | --------------- |
| CA-01  | Course List / Identity Builder      | Course code, master title, current version pointer                                                                                                                        | REQ-CRS-1                          | -               |
| CA-02  | Course Version Builder              | Multi-step wizard: Course Identity → Version Overview → Eligibility → Required Documents → Curriculum → Assessments & Grading → Certificates → Pricing → Review & Publish | REQ-CRS-2/5, REQ-ELIG-1, REQ-DOC-1 | Yes             |
| CA-03  | Batch Builder / Scheduler           | Dates, capacity, faculty, fee override, waitlist config                                                                                                                   | REQ-BATCH-1-5, REQ-CRS-4           | Yes             |
| CA-04  | Module & Content Builder            | Drag-reorder sequencing, lesson creation, release/completion rules, prerequisites                                                                                         | REQ-MOD-1-4                        | Yes             |
| CA-05  | Assessment Builder & Question Bank  | Question bank selection, passing model, grade weights, attempt/cooldown controls                                                                                          | REQ-EVAL-1/2, REQ-GRADE-1-5        | Yes             |
| CA-06  | Certificate Template & Rules Config | Layout, fields, grade bands, CME credits                                                                                                                                  | REQ-CERT-2/3, REQ-GRADE-5          | -               |
| CA-07  | Course Operations Dashboard         | Batch/capacity/delay overview                                                                                                                                             | REQ-DASH-OPS-1                     | -               |
| CA-08  | Certificate Administration          | Reissue, revocation, correction request, issue history, previous versions, reissue reason, verification status                                                            | REQ-CERT-5                         | Yes             |

# 7\. Finance Administrator

| **ID** | **Screen**                                  | **Purpose**                                       | **SRS Reference** | **Wireframed?** |
| ------ | ------------------------------------------- | ------------------------------------------------- | ----------------- | --------------- |
| FA-01  | Finance Dashboard                           | Payments, refunds, revenue, reconciliation status | REQ-DASH-FIN-1    | Yes             |
| FA-02  | Payment / Refund Detail                     | Manual refund action, status history              | REQ-REFUND-1-3    | -               |
| FA-03  | Offline Payment Entry                       | Bank transfer / institutional payment recording   | REQ-PAY-8         | -               |
| FA-04  | Corporate Account & Seat Allocation Manager | Seats purchased/consumed, vouchers                | REQ-CORP-1-3      | Yes             |
| FA-05  | GST Invoice Management                      | Invoice status, credit notes                      | REQ-INV-1/2       | -               |

# 8\. Support Executive

| **ID** | **Screen**    | **Purpose**                                                | **SRS Reference**      | **Wireframed?** |
| ------ | ------------- | ---------------------------------------------------------- | ---------------------- | --------------- |
| S-01   | Support Queue | All tickets, filter by category/SLA                        | REQ-SUP-1/2/6          | -               |
| S-02   | Ticket Detail | Internal notes vs. learner-visible thread, reassign/reopen | REQ-SUP-3-5, REQ-SUP-7 | Yes             |

# 9\. Super Administrator

| **ID** | **Screen**                          | **Purpose**                                                                                                                                         | **SRS Reference** | **Wireframed?** |
| ------ | ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | --------------- |
| SA-01  | Role Definition & Permission Matrix | Create/clone/edit roles, full permission matrix, sensitive-permission warnings, users-assigned-to-role view, impact-of-removal check, audit history | REQ-RBAC-2/3      | Yes             |
| SA-02  | User Role Assignment                | Assign one or more Roles to a specific User (many-to-many)                                                                                          | REQ-RBAC-1        | Yes             |
| SA-03  | Professional Category Manager       | Add/edit categories and required profile fields                                                                                                     | REQ-PROF-3        | -               |
| SA-04  | Notification Template Manager       | Edit template copy per event type                                                                                                                   | REQ-NOTIF-2       | -               |
| SA-05  | Audit Log Viewer                    | General AuditLog + VerificationAuditLog search                                                                                                      | §21               | -               |
| SA-06  | Gateway & System Configuration      | Razorpay/HDFC routing, global settings                                                                                                              | REQ-PAY-1         | -               |

# 10\. Reporting

| **ID** | **Screen**              | **Purpose**                                                                                        | **SRS Reference** | **Wireframed?** |
| ------ | ----------------------- | -------------------------------------------------------------------------------------------------- | ----------------- | --------------- |
| RPT-01 | Report Centre           | Landing page listing report categories (Applications, Enrolment, Academic, Finance, Certification) | REQ-RPT-1-5       | Yes             |
| RPT-02 | Report Filter & Preview | Filter builder + on-screen preview before export                                                   | REQ-RPT-1-5       | Yes             |
| RPT-03 | Export History          | Prior exports, useful once large reports run asynchronously                                        | REQ-RPT-1-5       | -               |

# 11\. Shared / Security

| **ID** | **Screen**                  | **Purpose**                                                      | **SRS Reference** | **Wireframed?** |
| ------ | --------------------------- | ---------------------------------------------------------------- | ----------------- | --------------- |
| SEC-01 | MFA Setup                   | Enrol an authenticator for Admin/Reviewer/Finance roles          | NFR-SEC-1         | -               |
| SEC-02 | MFA Challenge               | Second-factor prompt at login                                    | NFR-SEC-1         | -               |
| SEC-03 | Session / Device Management | Active sessions, revoke device, change password                  | NFR-SEC-1         | Yes             |
| SEC-04 | Change Password             | Current + new password, re-auth on sensitive change              | NFR-SEC-1         | Yes             |
| SEC-05 | Account Recovery            | Identity-verified recovery path distinct from Forgot Password    | NFR-SEC-1         | -               |
| SEC-06 | Access Denied               | Shown when a role lacks permission for a requested screen/action | §3 RBAC           | -               |
| SEC-07 | Suspicious Login / Lockout  | Throttling/lockout notice after repeated failed attempts         | NFR-SEC-1         | -               |

SEC-01/02/04/05/06/07 are inventoried for completeness per PRD/NFR security requirements; only SEC-03 (Session/Device Management, combined with Change Password) is wireframed in this revision as a representative pattern - the others follow standard, low-risk conventions and don't carry the same product-specific ambiguity as the flows wireframed elsewhere in this document.