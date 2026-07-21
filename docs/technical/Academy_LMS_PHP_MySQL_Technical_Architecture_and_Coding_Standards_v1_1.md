**TECHNICAL ARCHITECTURE  
AND CODING STANDARDS**

**Academy Learning Management System**

PHP 8.4 | MySQL 8.4 LTS | jQuery 3.x | Bootstrap 5.3

| **Document**        | **Value**                                                                                  |
| ------------------- | ------------------------------------------------------------------------------------------ |
| Version             | 1.1                                                                                        |
| Status              | Approved for Phase 0 Foundation Build and Build Preparation. The broader functional build remains subject to the open decisions and conditional approvals identified in the SRS and Decision Log. |
| Companion documents | PRD v1.0, SRS v6.0, approved low-fidelity UX baseline, high-fidelity designs under `/docs/design/high-fidelity/`, Decision Log |

| Audience            | Product Management, Engineering, QA, DevOps, Security, AI coding agents                    |
| Prepared for        | Academy LMS Product and Engineering Team                                                   |
| Date                | July 2026                                                                                  |

**Architecture Position  
**The system will be built as a modular PHP monolith with clear domain boundaries, a relational MySQL data model, private object storage, external video delivery, a queue/worker layer, webhook-first payment processing, and stateless web servers. The objective is production scalability and maintainability, not a prototype.

# Document Control

| **Version** | **Date**  | **Status**                 | **Summary**                                                                                                                                                                            |
| ----------- | --------- | -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1.0         | July 2026 | Draft for Technical Review | Initial architecture, coding, security, database, integration, deployment and quality standards.                                                                                       |
| 1.1         | July 2026 | Approved for Phase 0 Foundation Build and Build Preparation | Added explicit state-machine enforcement, CourseVersion immutability controls, rate-limit defaults, timezone conversion rules, database connection management, and upload-size limits. Status reconciled for Phase 0 foundation build; broader functional build remains subject to SRS conditional approvals and the Decision Log. |

## Approval Roles

| **Role**                     | **Approval responsibility**                                                |
| ---------------------------- | -------------------------------------------------------------------------- |
| Product Owner                | Confirms product rules, scope and SRS interpretation.                      |
| Technical Lead               | Owns architecture, code review, schema integrity and production readiness. |
| Security/Compliance Reviewer | Approves security, privacy, retention and privileged-access controls.      |
| Finance Representative       | Approves payment, invoice, reconciliation and refund behaviour.            |
| QA Lead                      | Approves test strategy, UAT traceability and release quality gates.        |

# Table of Contents

1\. Purpose and Architecture Principles

2\. Approved Technology Stack

3\. System Context and Logical Architecture

4\. Application Structure and Module Boundaries

4.3 State Machine Enforcement

5\. HTTP, Routing and Middleware Standards

6\. Authentication, Sessions, MFA and RBAC

7\. Database Architecture and MySQL Standards

8\. Transactions, Concurrency and Idempotency

9\. Payment, Invoice and Refund Architecture

10\. Queue, Worker, Cron and Scheduled Processing

11\. Document Storage and Malware-Scanning Architecture

12\. Video Delivery and Content Progress

13\. Assessment, Certificate and Reporting Architecture

14\. Security and Privacy Standards

15\. Frontend and UX Coding Standards

16\. API and Response Conventions

17\. Error Handling, Logging and Monitoring

18\. Testing and Quality Gates

19\. Environments, CI/CD and Deployment

20\. Performance and Scaling

21\. AI-Assisted Development Rules

22\. Initial Implementation Roadmap

23\. Definition of Done and Architecture Decisions

# 1\. Purpose and Architecture Principles

This document translates the Academy LMS SRS v6.0 and approved UX baseline into technical rules that the engineering team and any AI coding agents must follow. It defines how the application will be structured, how data and permissions will be protected, how integrations will operate, and how changes will be tested and deployed.

## 1.1 Goals

- Build a scalable production system rather than a disposable prototype.
- Preserve the SRS state machines, segregation of duties and CourseVersion immutability.
- Keep the solution understandable and maintainable by the existing PHP/MySQL team.
- Support horizontal scaling without redesigning the application.
- Make financial, credential, assessment and certificate actions auditable.
- Enable controlled AI-assisted coding without allowing AI tools to invent architecture or requirements.

## 1.2 Core architecture principles

| **Principle**      | **Required interpretation**                                                                                 |
| ------------------ | ----------------------------------------------------------------------------------------------------------- |
| Modular monolith   | One deployable PHP application initially, divided into explicit domain modules. No premature microservices. |
| Server authority   | Validation, permissions, workflow transitions, pricing and completion rules are enforced server-side.       |
| Stateless web tier | No permanent local uploads, local-only sessions or server-specific scheduled state.                         |
| Database integrity | Foreign keys, unique constraints, transactions and row locks enforce critical rules.                        |
| Webhook first      | Payment webhooks are processed in real time; cron performs reconciliation and recovery.                     |
| Async by design    | Email, certificate generation, report exports, scanning and reconciliation run through workers.             |
| Private by default | Documents, administrative data and paid content are inaccessible unless explicitly authorised.              |
| Traceability       | Requirements, tickets, migrations, tests and releases remain linked to SRS requirement IDs.                 |

**Non-negotiable  
**No implementation may replace the approved Application, Payment and Enrolment model with a single combined status or create an Enrolment before the Application reaches Admitted.

# 2\. Approved Technology Stack

| **Layer**        | **Approved choice**                | **Key conditions**                                                                    |
| ---------------- | ---------------------------------- | ------------------------------------------------------------------------------------- |
| Browser UI       | jQuery 3.x, Bootstrap 5.3.x        | Internal component standards; no page-specific visual drift.                          |
| Admin tables     | DataTables                         | Server-side processing for large datasets.                                            |
| Enhanced selects | Select2                            | AJAX loading for large lists; accessible labelling.                                   |
| Dialogs/alerts   | SweetAlert2                        | Simple confirmations only; complex workflows use dedicated pages or Bootstrap modals. |
| Backend          | PHP 8.4.x                          | Composer, PSR-4, modular architecture, strict coding and testing standards.           |
| Database         | Managed MySQL 8.4 LTS, InnoDB      | Transactions, foreign keys, PITR and production backups.                              |
| Database access  | PDO prepared statements            | No dynamic unbound values; allow-listed dynamic identifiers only.                     |
| Object storage   | Private AWS S3                     | Signed URLs, encryption, scanning and lifecycle policies.                             |
| Queue            | Amazon SQS preferred               | Reliable workers, retries and dead-letter handling.                                   |
| Video            | Mux or Cloudflare Stream preferred | Signed/tokenised playback; no self-hosting.                                           |
| Source control   | GitHub                             | Protected main branch and pull-request-only changes.                                  |

## 2.1 Libraries and platform utilities

- Composer for dependency management and reproducible lock files.
- Monolog for structured application logging.
- PHPUnit or Pest for automated tests.
- PHPStan for static analysis.
- PHP-CS-Fixer or PHP_CodeSniffer for formatting and style enforcement.
- Phinx or Doctrine Migrations for database migrations.
- A PSR-compatible router and middleware package rather than custom URL parsing.
- AWS SDK for PHP for S3 and SQS integrations.

Libraries must be pinned to tested versions. Production deployments must use composer install --no-dev --prefer-dist --no-interaction with the committed composer.lock.

# 3\. System Context and Logical Architecture

Users  
|  
v  
Load Balancer / Web Application Firewall  
|  
+--> PHP Web Node 1  
+--> PHP Web Node 2 ... N  
|  
+--> Managed MySQL 8.4 LTS  
+--> Shared Session Store / Database  
+--> Amazon SQS  
+--> Private S3  
+--> Transactional Email Provider  
+--> Razorpay / HDFC  
+--> Video Provider  
+--> Central Logs and Monitoring  
<br/>Worker Nodes  
|  
+--> SQS jobs: email, scan, certificate, report, reconciliation, reminders

## 3.1 Deployable units

| **Unit**        | **Responsibility**                                                                                 | **Scaling model**                                         |
| --------------- | -------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| Web application | HTML pages, JSON endpoints, validation, authorisation and synchronous domain commands.             | Horizontally scaled behind a load balancer.               |
| Worker process  | Queue consumption, retries, certificates, exports, notifications, reconciliation and scan results. | One or more independently scaled workers.                 |
| Scheduler       | Creates due jobs and performs periodic reconciliation.                                             | Single elected scheduler or managed scheduled invocation. |
| Database        | Authoritative transactional state.                                                                 | Managed primary with backups/read replicas where needed.  |
| Object storage  | Documents, resources and generated PDFs.                                                           | Managed S3; no local dependency.                          |

## 3.2 Request boundaries

- A browser request should complete quickly and must not perform long-running media, export or scanning work.
- All state changes must pass through a domain service or application command, not direct SQL inside templates.
- External callbacks must verify signatures, persist the event idempotently and acknowledge quickly.
- Workers may retry jobs; therefore every job handler must be safe to run more than once.

# 4\. Application Structure and Module Boundaries

academy-lms/  
|-- public/  
| \`-- index.php  
|-- src/  
| |-- Domain/  
| | |-- Identity/  
| | |-- RBAC/  
| | |-- Courses/  
| | |-- Admissions/  
| | |-- Credentials/  
| | |-- Payments/  
| | |-- Learning/  
| | |-- Assessments/  
| | |-- Certificates/  
| | |-- Notifications/  
| | |-- Support/  
| | \`-- Reporting/  
| |-- Application/  
| | |-- Commands/  
| | |-- Queries/  
| | |-- Services/  
| | \`-- DTO/  
| |-- Infrastructure/  
| | |-- Database/  
| | |-- Queue/  
| | |-- Storage/  
| | |-- Payments/  
| | |-- Video/  
| | \`-- Mail/  
| \`-- Http/  
| |-- Controllers/  
| |-- Middleware/  
| |-- Requests/  
| \`-- Responses/  
|-- templates/  
|-- config/  
|-- database/migrations/  
|-- tests/  
|-- bin/  
|-- composer.json  
\`-- AGENTS.md

## 4.1 Responsibilities

| **Layer**      | **May contain**                                                             | **Must not contain**                                          |
| -------------- | --------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Http           | Routing, request parsing, response mapping, CSRF and HTTP concerns.         | SQL or business-state decisions.                              |
| Application    | Use-case orchestration, commands, queries, DTOs and transaction boundaries. | HTML output or vendor-specific storage code.                  |
| Domain         | Business rules, state transitions, policies and domain exceptions.          | HTTP globals, PDO calls or template rendering.                |
| Infrastructure | PDO repositories, S3, SQS, payment gateway and email adapters.              | Product-rule decisions.                                       |
| Templates      | Escaped presentation and reusable view components.                          | Permission decisions, direct queries or workflow transitions. |

## 4.2 Mandatory domain modules

- Identity and RBAC
- Course, CourseVersion, Batch and curriculum
- Application, admission, waitlist and enrolment
- Credentials and document verification
- Payments, invoices, refunds and corporate allocations
- Learning content, progress, sessions and attendance
- Assessment, scoring and grading
- Certificates and public verification
- Notifications
- Support and grievance handling
- Reporting and exports
- Audit

## 4.3 State Machine Enforcement

Application and Enrolment lifecycle changes must be enforced through dedicated state-machine services. Controllers, repositories, workers and templates must never assign lifecycle status fields directly. The allowed transitions are defined once, version-controlled and unit tested.

### Required implementation pattern

final class ApplicationStateMachine  
{  
private const ALLOWED = \[  
'Draft' => \['Submitted', 'Withdrawn'\],  
'Submitted' => \['Documents Incomplete', 'Under Review', 'Payment Pending'\],  
'Documents Incomplete' => \['Under Review'\],  
'Under Review' => \['Resubmission Requested', 'Payment Pending', 'Rejected'\],  
'Resubmission Requested' => \['Under Review', 'Expired'\],  
'Payment Pending' => \['Awaiting Verification', 'Admitted', 'Cancelled'\],  
'Awaiting Verification' => \['Admitted', 'Rejected'\],  
'Admitted' => \[\],  
'Rejected' => \['Withdrawn'\],  
'Withdrawn' => \[\],  
'Expired' => \[\],  
\];  
<br/>public function assertCanTransition(string \$from, string \$to): void  
{  
if (!in_array(\$to, self::ALLOWED\[\$from\] ?? \[\], true)) {  
throw new InvalidStateTransition(  
sprintf('Application cannot transition from %s to %s.', \$from, \$to)  
);  
}  
}  
}

Enrolment must use a separate EnrolmentStateMachine with the SRS §18.2 transitions: Scheduled -> Active/Cancelled; Active -> Suspended/Withdrawn/Access Expired; Suspended -> Active/Withdrawn; and eligible terminal states -> Refunded only through an approved refund workflow.

### Enforcement rules

- Only an application service/command may call transition(); repositories expose no general updateStatus() method.
- transition() must execute inside the same database transaction as dependent writes, audit records and outbox/domain events.
- The current row is loaded with SELECT ... FOR UPDATE for payment-, admission-, capacity- or refund-sensitive transitions.
- Every transition records from_state, to_state, actor, reason, source, timestamp and correlation ID in AuditLog or a dedicated status-transition history.
- Workers and webhook handlers use the same state-machine service as browser requests; they may not implement parallel transition logic.
- Invalid transitions return a domain conflict and HTTP 409; they are logged as rejected attempts and do not partially update related records.
- Unit tests must cover every allowed transition and every disallowed state pair. Integration tests must prove transaction rollback when any dependent step fails.

# 5\. HTTP, Routing and Middleware Standards

## 5.1 Front controller

All requests must enter through public/index.php. Web-server configuration must prevent direct access to src, config, database, tests and storage directories.

## 5.2 Middleware order

- Trusted proxy and HTTPS enforcement
- Request ID creation
- Security headers
- Session loading
- Authentication
- Rate limiting
- CSRF validation for browser state-changing requests
- Permission enforcement
- Controller dispatch
- Central exception handling and structured logging

> **WP01-G (Approved 2026-07-20):** Rate limiting runs **before** CSRF. This supersedes the earlier §5.2 draft that listed CSRF before RateLimit. Rationale: throttling must also cover invalid/missing CSRF traffic. Route-level permission middleware continues to execute only after League\Route has matched the route. CSRF validation on mutating requests is unchanged. See Decision Log `WP01-G`.

### 5.2.1 Rate-limiting defaults

Rate limits are configurable by environment and may be tightened in response to abuse. Limits must be enforced server-side using a shared store so that horizontal web nodes apply one combined limit. Return HTTP 429 with a Retry-After header.

- Login: 5 failed attempts per 15 minutes per account and per IP; after 10 failed attempts, impose a 30-minute account lock and create a security event.
- OTP send: 3 requests per 15 minutes and 10 per 24 hours per mobile/email; minimum 60 seconds between sends.
- OTP verification: 10 attempts per 15 minutes per verification challenge.
- Forgot/reset password: 3 requests per hour per account/email and 10 per hour per IP.
- Registration: 10 attempts per hour per IP, with additional bot protection when abuse is detected.
- Public certificate verification: 60 requests per minute per IP; lower sustained-rate limits may apply.
- Authenticated JSON/form actions: default 120 requests per minute per user, with stricter endpoint-specific limits for uploads, exports and destructive actions.
- Document upload initiation: 20 presigned-upload requests per hour per user/application unless an administrator grants an exception.
- Payment checkout/order creation: 5 attempts per 30 minutes per Application; unresolved payment states block unnecessary duplicate attempts.
- Gateway webhooks are not subject to user limits; they require signature verification, payload-size limits and idempotency controls.

## 5.3 Controller standard

final class ReviewDocumentController  
{  
public function \_\_invoke(ServerRequestInterface \$request, array \$args): ResponseInterface  
{  
\$input = ReviewDocumentRequest::fromRequest(\$request);  
\$result = \$this->reviewDocument->execute(\$this->currentUser->id(), \$args\['id'\], \$input);  
<br/>return \$this->responses->json(\$result, 200);  
}  
}

- Controllers must remain thin.
- Input DTOs perform syntactic validation; domain services enforce business validity.
- Every protected endpoint declares a required permission.
- State-changing GET requests are prohibited.
- Redirects after form submissions must use POST-Redirect-GET.

# 6\. Authentication, Sessions, MFA and RBAC

## 6.1 Password and account standards

- Use password_hash() with Argon2id where available; otherwise the current PHP recommended algorithm.
- Never store or log plaintext passwords, OTPs, reset tokens or MFA secrets.
- Password-reset tokens must be single-use, random, hashed at rest and time-limited.
- Email and mobile verification are required before an Application can be submitted.
- Login, OTP and recovery endpoints require rate limiting and abuse monitoring.

## 6.2 Session standards

- Use secure, HttpOnly, SameSite cookies; require HTTPS in staging and production.
- Regenerate session IDs after login, MFA completion and privilege changes.
- Store active sessions centrally so users and administrators can revoke devices.
- Idle and absolute timeouts must be configurable by role sensitivity.
- Do not store role/permission truth only in a long-lived client token; re-evaluate server-side.

## 6.3 MFA

MFA is mandatory for Super Administrator, Course Administrator, Credential Reviewer and Finance Administrator accounts. TOTP authenticator support is the preferred first implementation. Recovery codes must be hashed and single-use.

## 6.4 RBAC enforcement

Permission check = UNION(RolePermission.permission_id)  
for all active UserRole rows  
belonging to the current user

| **Rule**                | **Requirement**                                                                                                |
| ----------------------- | -------------------------------------------------------------------------------------------------------------- |
| Server-side enforcement | Every protected route and action must check permissions on the server.                                         |
| Object scope            | Permission alone is insufficient; assigned batch/course or ownership scope must also be checked.               |
| Segregation of duties   | Finance cannot view qualification documents; Reviewers cannot refund; Course Admin cannot approve credentials. |
| Changes                 | Role and permission changes are audited with before/after values.                                              |
| Cache                   | Permission caches must be invalidated immediately after role changes.                                          |

# 7\. Database Architecture and MySQL Standards

## 7.1 General rules

- Use MySQL 8.4 LTS with InnoDB, utf8mb4 and a consistent collation.
- All schema changes must be applied through committed, reviewable migrations.
- Foreign keys are mandatory unless a documented exception is approved.
- Use BIGINT unsigned identifiers where expected volume or interoperability warrants it; otherwise use a consistent integer strategy.
- Store all database timestamps in UTC. Timezone conversion is performed in the PHP presentation/API layer, not in SQL and never solely by the browser.

Each User may have an IANA timezone preference; the system default is Asia/Kolkata. Each Batch and LiveSession also stores an IANA timezone. User-entered local dates/times are interpreted using the relevant Batch/Session timezone, validated for daylight-saving ambiguity where applicable, converted to UTC before persistence, and converted back only for display or export. Deadlines, waitlist expiry, assessment windows and scheduled jobs use server-side UTC instants as the authority.

- Use DECIMAL for money; never FLOAT or DOUBLE.
- Do not store files, large PDFs or videos in MySQL.
- Use JSON only for genuinely variable metadata, not as a substitute for relational modelling.

## 7.2 Naming standards

| **Object**         | **Standard**                        | **Example**                   |
| ------------------ | ----------------------------------- | ----------------------------- |
| Tables             | lowercase plural snake_case         | course_versions               |
| Columns            | lowercase snake_case                | access_expires_at             |
| Primary key        | singular entity + \_id              | application_id                |
| Foreign key        | referenced key name                 | course_version_id             |
| Indexes            | idx\_&lt;table&gt;\_&lt;columns&gt; | idx_applications_batch_status |
| Unique constraints | uq\_&lt;table&gt;\_&lt;columns&gt;  | uq_user_roles_user_role       |
| Foreign keys       | fk\_&lt;table&gt;\_&lt;column&gt;   | fk_batches_course_version_id  |

## 7.3 Required constraints

UNIQUE courses(course_code)  
UNIQUE course_versions(course_id, version_number)  
UNIQUE enrolments(application_id)  
UNIQUE content_progress(enrolment_id, content_id)  
UNIQUE attendance_records(session_id, enrolment_id)  
UNIQUE user_roles(user_id, role_id)  
UNIQUE role_permissions(role_id, permission_id)  
UNIQUE certificate_entitlements(enrolment_id, certificate_type)

### 7.3.1 CourseVersion immutability enforcement

A CourseVersion becomes immutable when it is Published or when any Application references it, whichever occurs first. From that point, the version and its owned configuration cannot be updated or deleted; changes require cloning to Version N+1.

Required controls:  
\- course_versions: published_at, locked_at, locked_reason  
\- Application service refuses update/delete when locked_at IS NOT NULL  
\- Child services apply the same guard to modules, content_items,  
eligibility_rules, course_document_requirements, assessment policies,  
certificate rules and completion rules  
\- Database BEFORE UPDATE / BEFORE DELETE triggers reject changes to a  
locked CourseVersion and its protected child records  
\- Foreign keys use RESTRICT, not cascading deletion, for published versions  
\- Publish and clone actions are audited

The database trigger is defence in depth, not the primary business interface. The application must still return a clear conflict message and offer "Create Version N+1"; it must not rely on a raw trigger exception as the user experience. A migration test must demonstrate that direct SQL attempts to alter a locked version are rejected.

## 7.4 Indexing strategy

Indexes must follow real query patterns. A status column with low cardinality is rarely useful alone; combine it with batch, owner or time fields.

INDEX applications(batch_id, status, submitted_at)  
INDEX document_submissions(status, created_at)  
INDEX enrolments(batch_id, lifecycle_status)  
INDEX payments(application_id, status, created_at)  
INDEX payments(gateway, status, created_at)  
INDEX waitlist_entries(batch_id, status, position)  
INDEX assessment_attempts(enrolment_id, assessment_id)  
INDEX support_tickets(status, sla_due_at)  
INDEX notifications(user_id, read_at, created_at)

## 7.5 PDO standards

\$stmt = \$pdo->prepare(  
'SELECT application_id, status  
FROM applications  
WHERE application_id = :application_id  
AND user_id = :user_id'  
);  
\$stmt->execute(\[  
'application_id' => \$applicationId,  
'user_id' => \$userId,  
\]);

- Use exceptions for PDO errors in non-production and mapped safe errors in production.
- Never concatenate user input into SQL.
- Dynamic ORDER BY or column names must be mapped from explicit allow-lists.
- Repositories return typed records/DTOs, not raw PDO statements.
- N+1 query patterns must be identified during review and testing.

# 8\. Transactions, Concurrency and Idempotency

## 8.1 Transaction boundaries

The application service owns the transaction boundary for a complete business operation. Repositories must not silently commit independent partial work.

## 8.2 Seat confirmation

BEGIN;  
<br/>SELECT batch_id, max_capacity  
FROM batches  
WHERE batch_id = :batch_id  
FOR UPDATE;  
<br/>SELECT COUNT(\*) AS occupied  
FROM enrolments  
WHERE batch_id = :batch_id  
AND lifecycle_status IN ('Scheduled', 'Active');  
<br/>\-- Validate occupied < max_capacity  
\-- Insert enrolment / accept waitlist offer  
\-- Write audit event  
<br/>COMMIT;

## 8.3 Idempotency rules

| **Operation**          | **Idempotency key / safeguard**                                               |
| ---------------------- | ----------------------------------------------------------------------------- |
| Razorpay webhook       | Gateway event ID or payload hash with unique constraint.                      |
| Payment order creation | Application + payable version/order key.                                      |
| Enrolment creation     | Unique enrolments.application_id.                                             |
| Certificate generation | Enrolment + certificate type + entitlement/version.                           |
| Notification           | Event type + recipient + domain event ID where duplicate delivery is harmful. |
| Queue job              | Job idempotency key and handler-side state check.                             |
| Waitlist acceptance    | Row lock plus status transition Offered -> Accepted.                          |

## 8.4 Optimistic and pessimistic locking

- Use row locks for scarce resources and money-sensitive operations.
- Use updated_at/version checks for long multi-step editing such as CourseVersion drafts.
- Published CourseVersions are immutable; changes require Version N+1.

# 9\. Payment, Invoice and Refund Architecture

## 9.1 Payment flow

- Server calculates price, discounts, GST and payable total.
- Server creates a Payment attempt linked to Application.
- Server creates the gateway order and stores the gateway order reference.
- Browser launches the hosted gateway checkout.
- Browser return displays Confirming Payment, not Successful.
- Webhook verifies signature and persists the event.
- Worker or synchronous safe handler fetches/validates captured status and amount.
- Application transitions only if the event is valid and idempotent.
- If all admission conditions are complete, Application becomes Admitted and Enrolment is created.

## 9.2 Webhook endpoint

POST /webhooks/razorpay  
<br/>Requirements:  
\- Read raw request bytes.  
\- Verify Razorpay signature.  
\- Reject invalid signatures.  
\- Persist gateway event with unique event key.  
\- Return HTTP 200 rapidly after durable receipt.  
\- Queue processing.  
\- Never trust browser redirect as payment confirmation.

## 9.3 Cron reconciliation

Cron is a recovery and reconciliation mechanism, not the primary webhook processor. It periodically finds Pending, Reconciliation Pending and stale Processing records, calls gateway APIs, and safely repairs local state.

## 9.4 Financial validation

- Captured amount and currency must equal the server-generated order.
- Only one net successful payable outcome may exist per Application unless Finance resolves a duplicate.
- Cumulative completed refunds may not exceed the captured refundable amount.
- Every manual financial action requires permission, reason and AuditLog entry.
- Credit-note creation must follow the approved GST policy.

## 9.5 Refund workflow

Requested -> Processing -> Completed  
|-> Failed  
Requested -> Denied  
Requested/Processing -> Cancelled (where allowed)

Refund processing must be asynchronous and reconciliation-aware. A failed gateway refund must not remain indefinitely in Processing.

# 10\. Queue, Worker, Cron and Scheduled Processing

## 10.1 Preferred queue architecture

Amazon SQS is preferred because AWS S3 is already part of the architecture and SQS provides durable delivery, retries and dead-letter queues without maintaining queue infrastructure.

| **Job**                      | **Trigger**                    | **Retry policy**                                          |
| ---------------------------- | ------------------------------ | --------------------------------------------------------- |
| Send notification            | Domain event                   | Exponential retry; dead-letter after configured attempts. |
| Generate certificate         | Eligibility completed          | Idempotent regeneration; retain error details.            |
| Generate report export       | User export request            | Retry transient failures; notify on completion/failure.   |
| Scan uploaded document       | S3 upload completed            | Quarantine until scan succeeds.                           |
| Reconcile payment/refund     | Scheduler or webhook ambiguity | Gateway-aware retry and Finance escalation.               |
| Activate scheduled enrolment | Batch start time               | Idempotent Scheduled -> Active transition.                |
| Expire waitlist offer        | Offer deadline                 | Atomic expiry and next-offer creation.                    |
| Access expiry reminders      | Scheduled                      | Deduplicated notification events.                         |

## 10.2 Job record and observability

- Every job has type, payload reference, idempotency key, attempt count, timestamps and last error.
- Worker logs include request/job correlation IDs.
- Dead-letter items create an operational alert.
- Workers must use graceful shutdown and visibility-timeout renewal for long jobs.

## 10.3 Scheduler

Only one logical scheduler may enqueue each due task. In a multi-server environment, use a managed scheduler, leader election or a database lock to prevent duplicate cron execution.

# 11\. Document Storage and Malware-Scanning Architecture

## 11.1 Storage model

Files are stored in private S3. MySQL stores metadata, status and object keys only. Signed URLs are generated on demand and are never stored as permanent fields.

document_submissions  
\- document_id  
\- application_id  
\- requirement_id  
\- object_key  
\- original_filename  
\- mime_type  
\- size_bytes  
\- checksum_sha256  
\- scan_status  
\- status  
\- uploaded_at  
\- approved_at

## 11.2 Upload flow

- Server validates requirement, permission, extension, size and intended MIME type.
- Server issues a short-lived presigned upload URL for a random object key.
- Client uploads directly to S3.
- Client or S3 event notifies the application of completion.
- Application records Uploaded / Scan Pending.
- Scanner inspects the object and records Clean or Failed Security Scan.
- Only clean files enter the reviewer queue.

### 11.2.1 Platform upload limits

The CourseDocumentRequirement may configure a lower limit, but it may not exceed the platform cap without an approved architecture/security change. Limits are enforced before issuing the presigned URL and again against the completed S3 object metadata.

- Credential and identity documents: 10 MB per file; PDF, JPEG and PNG by default.
- Front/back credential requirements: maximum 2 files unless the requirement explicitly permits more.
- Support-ticket attachments: 10 MB per file and 5 files per ticket message.
- Course downloadable resources: 100 MB per file; larger learning media must use the approved video/media provider.
- Profile and promotional images: 5 MB per file.
- Webhook and ordinary JSON request bodies: 1 MB unless an endpoint-specific exception is documented.
- Multipart uploads and executable/archive formats are denied by default; any exception requires malware scanning and an allow-listed requirement.

Web server, PHP, reverse proxy and S3 presigned-policy limits must be aligned. The application must display accepted formats and limits before upload and provide a specific validation error when exceeded.

## 11.3 Security controls

- Block all public bucket access.
- Use server-side encryption and separate environment prefixes/buckets.
- Generate signed download/view URLs with 10-15 minute expiry.
- Validate file signatures, not only client MIME declarations.
- Use random object keys and preserve original names only as metadata.
- Use lifecycle rules based on approved retention policy.
- Log reviewer access and administrative downloads.

# 12\. Video Delivery and Content Progress

## 12.1 Provider decision

Mux or Cloudflare Stream is preferred for paid course content. Vimeo may be considered for operational simplicity. Public YouTube should be limited to marketing or genuinely public material.

## 12.2 Data model

content_items  
\- provider  
\- provider_asset_id  
\- playback_id  
\- duration_seconds  
\- processing_status  
\- thumbnail_ref  
\- caption_ref  
<br/>content_progress  
\- enrolment_id  
\- content_id  
\- resume_position_seconds  
\- watch_percentage  
\- completion_status  
\- first_accessed_at  
\- last_accessed_at  
\- completed_at  
\- completion_source

## 12.3 Completion integrity

- Video completion is computed from configured watch threshold and trusted playback/progress events.
- A manual Mark Complete action is shown only for content types configured for learner acknowledgement.
- Progress updates are throttled and idempotent.
- Completion rules belong to CourseVersion/Module configuration and remain locked for enrolled learners.
- Signed playback limits casual sharing but does not prevent screen recording; the product must not claim otherwise.

# 13\. Assessment, Certificate and Reporting Architecture

## 13.1 Assessment attempt

- Only one active attempt per learner per assessment.
- Question versions shown in an attempt are persisted.
- Each answer writes or updates an AssessmentResponse immediately.
- Autosave endpoints are idempotent and tolerate reconnection.
- Final submission uses a transaction to close the attempt and compute score.
- Attempt, result and manual adjustment events are audited.

## 13.2 Certificate generation

- Certificate entitlement is determined from ContentProgress, attendance and assessment results.
- PDF generation is a queue job.
- Only one active entitlement per enrolment and certificate type.
- Reissues preserve prior issue history and use a new issue record.
- Revocation immediately affects the public verification endpoint.

## 13.3 Reporting

Interactive admin tables use server-side pagination. Large exports are asynchronous and stored temporarily in private S3 with expiry. Report queries must apply role scope and privacy filters before aggregation.

- Never export credential documents through general reports.
- Log report generation and sensitive exports.
- Use read replicas or reporting tables only when production query load justifies them.

# 14\. Security and Privacy Standards

| **Control area**    | **Mandatory standard**                                                                 |
| ------------------- | -------------------------------------------------------------------------------------- |
| Transport           | TLS in all non-local environments; HSTS in production.                                 |
| Headers             | CSP, frame restrictions, referrer policy, MIME sniffing protection and secure caching. |
| CSRF                | Token required on browser state-changing requests.                                     |
| XSS                 | Escape output by context; prohibit raw untrusted HTML.                                 |
| SQL injection       | PDO prepared statements and allow-listed identifiers.                                  |
| Secrets             | Environment/secret manager only; never source control.                                 |
| Uploads             | Private S3, scan gate, MIME/signature validation and size limits.                      |
| Authorisation       | Server-side permission plus object-scope check.                                        |
| Audit               | Append-only administrative and sensitive-action logs.                                  |
| Data minimisation   | Collect only fields required by policy and CourseVersion.                              |
| Non-production data | Synthetic or anonymised data only.                                                     |

## 14.1 Secure coding prohibitions

- No eval(), dynamic include paths from user input or unserialize() on untrusted data.
- No direct exposure of stack traces or SQL errors to users.
- No credentials in JavaScript, HTML source, logs or repository files.
- No reliance on disabled buttons or hidden links for security.
- No unrestricted administrative impersonation.
- No permanent signed URLs.

# 15\. Frontend and UX Coding Standards

## 15.1 Component discipline

Although the frontend is jQuery and Bootstrap, it must use reusable templates, partials and JavaScript modules. AI agents must not copy-and-modify entire screens when a shared component exists.

| **Component**      | **Examples**                                                               |
| ------------------ | -------------------------------------------------------------------------- |
| StatusBadge        | Application, payment, refund, enrolment and document states.               |
| DataTable wrapper  | Server-side pagination, filters, empty state and permission-safe exports.  |
| ApplicationStepper | Mode A/B/C sequence generated from CourseVersion.                          |
| DocumentStatusRow  | Upload, scan, rejection and resubmission states.                           |
| PaymentStatusPanel | Pending, reconciliation, duplicate and refund variants.                    |
| ConfirmationDialog | Destructive action, reason and consequence summary.                        |
| AuditTimeline      | Verification, certificate and financial history.                           |
| FormField          | Label, help, required marker, validation and accessibility attributes.     |
| SystemState        | Loading, empty, error, permission denied, session expired and maintenance. |

## 15.2 JavaScript standards

- Use ES modules or clearly namespaced modules; avoid global variables.
- Use delegated event handlers for dynamic DataTables content.
- All AJAX requests include CSRF token and handle 401, 403, 409, 422 and 500 consistently.
- Client validation improves usability but never replaces server validation.
- Use data-\* attributes for IDs; never parse identifiers from visible text.
- Debounce search and autosave requests.
- Do not embed business-state transitions solely in JavaScript.

## 15.3 DataTables

- Use serverSide: true for operational tables beyond small bounded datasets.
- The server owns filter validation and sort-column allow-lists.
- Exports above the synchronous threshold become background jobs.
- Never send inaccessible columns to the browser and hide them visually.

## 15.4 Accessibility and responsive design

- All form controls require programmatic labels.
- Keyboard navigation and visible focus are mandatory.
- Colour is not the sole status indicator.
- Mobile course navigation and assessment navigation use dedicated patterns rather than compressed desktop tables.
- Error messages are linked to fields and announced where practical.

# 16\. API and Response Conventions

## 16.1 JSON response envelope

Success:  
{  
"data": {...},  
"meta": {...},  
"request_id": "..."  
}  
<br/>Validation error:  
{  
"error": {  
"code": "VALIDATION_FAILED",  
"message": "Please correct the highlighted fields.",  
"fields": {  
"registration_number": \["Registration number is required."\]  
}  
},  
"request_id": "..."  
}

## 16.2 HTTP status standards

| **Status** | **Use**                                           |
| ---------- | ------------------------------------------------- |
| 200        | Successful read or update.                        |
| 201        | Resource created.                                 |
| 202        | Accepted for asynchronous processing.             |
| 204        | Successful action with no body.                   |
| 400        | Malformed request.                                |
| 401        | Authentication required or expired.               |
| 403        | Authenticated but not authorised.                 |
| 404        | Resource absent or deliberately concealed.        |
| 409        | State conflict, duplicate or concurrency failure. |
| 422        | Validation/business-input failure.                |
| 429        | Rate limit exceeded.                              |
| 500        | Unexpected server failure; safe message only.     |

## 16.3 API standards

- Use nouns for resources and explicit action endpoints where state transitions require them.
- Use request IDs and structured errors.
- Paginate all potentially large collections.
- Document every endpoint with permission, state preconditions and idempotency behaviour.
- Do not expose internal database errors or sequential-object existence across permission boundaries.

# 17\. Error Handling, Logging and Monitoring

## 17.1 Exception classes

- ValidationException
- AuthenticationException
- AuthorizationException
- NotFoundException
- ConflictException
- DomainRuleException
- ExternalServiceException

## 17.2 Logging standards

- Use structured JSON logs in staging and production.
- Every request and job has a correlation/request ID.
- Log user ID, route, domain action and affected entity where appropriate.
- Never log passwords, OTPs, tokens, raw documents, card data or full sensitive payloads.
- Payment webhook logs record event identifiers and verification result, not secrets.

## 17.3 Monitoring

| **Signal**   | **Alert examples**                                                           |
| ------------ | ---------------------------------------------------------------------------- |
| Application  | 5xx rate, latency, login failures, permission errors.                        |
| Database     | Slow queries, connection exhaustion, lock waits, replication lag.            |
| Queue        | Backlog, age of oldest job, retry rate, dead-letter volume.                  |
| Payments     | Webhook failures, reconciliation backlog, duplicate captures, stuck refunds. |
| Documents    | Scan failures, upload failures, signed URL errors.                           |
| Learning     | Video callback failures, progress-save failures.                             |
| Business SLA | Reviewer queue >48h, support SLA breaches, certificate generation delay.     |

# 18\. Testing and Quality Gates

## 18.1 Test layers

| **Layer**              | **Purpose**                                                             |
| ---------------------- | ----------------------------------------------------------------------- |
| Unit                   | Domain rules, calculations, state-transition policies and validators.   |
| Repository integration | SQL, constraints, migrations, transaction and locking behaviour.        |
| HTTP integration       | Authentication, CSRF, permissions, validation and response contracts.   |
| Worker integration     | Retries, idempotency, dead-letter and external-service adapters.        |
| Browser/E2E            | Critical learner and administrator journeys.                            |
| Security               | Authorisation matrix, upload controls, injection and session behaviour. |
| Performance            | Peak course access, assessment autosave, admin tables and exports.      |

## 18.2 Mandatory high-risk tests

- Finance users cannot access document metadata or signed document URLs.
- Reviewer cannot issue or approve refunds.
- Course Administrator cannot approve credentials.
- Mode B rejection produces no Enrolment and closes/refunds the Application-linked Payment.
- Duplicate webhook delivery does not duplicate payment, invoice, enrolment or notification.
- Two users cannot accept the last batch seat.
- Published CourseVersion configuration cannot be modified.
- Cumulative refunds cannot exceed captured payment.
- Assessment answers survive transient connection failure.
- Certificate cannot issue before configured participation/completion conditions.
- Revoked certificates return revoked state publicly without contact data.

## 18.3 Pull-request gates

composer validate  
php -l / syntax check  
coding-standard check  
PHPStan  
unit tests  
integration tests  
migration validation  
security/dependency scan  
secret scan  
build/package smoke test

A failed mandatory gate blocks merge. AI-generated code is subject to the same gates as human-written code.

# 19\. Environments, CI/CD and Deployment

| **Environment**    | **Purpose**                                       | **Data**                               |
| ------------------ | ------------------------------------------------- | -------------------------------------- |
| Local development  | Developer and AI-agent work.                      | Synthetic only.                        |
| Shared development | Integration of active branches.                   | Synthetic fixtures.                    |
| Staging/UAT        | Production-like validation and business sign-off. | Synthetic/anonymised; payment sandbox. |
| Production         | Real learners, documents and payments.            | Strictly controlled.                   |

## 19.1 Deployment pipeline

- Pull request created.
- Automated quality and security gates run.
- Human technical review and domain-owner review where necessary.
- Merge to protected branch.
- Build immutable release artifact.
- Apply backward-compatible database migrations.
- Deploy to staging and run smoke/UAT checks.
- Approve production release.
- Deploy application and workers.
- Run health checks and post-deployment verification.
- Rollback application if necessary; database rollback only through approved migration strategy.

## 19.2 Migration standards

- Migrations must be forward-safe and reviewed for lock impact.
- Large data backfills run as controlled jobs, not inside a blocking schema migration.
- Deploy code compatible with both old and new schema during zero-downtime changes.
- Never edit an already-applied production migration.

## 19.3 Secrets and configuration

- Use a secret manager or protected environment configuration.
- Separate credentials for each environment.
- Production secrets are unavailable to coding agents and local development.
- Rotate keys after suspected exposure and on an approved schedule.

# 20\. Performance and Scaling

## 20.1 Stateless scaling

- Web nodes store no permanent files and no local-only jobs.
- Sessions use a shared store/database.
- Generated documents go to S3.
- Workers are separately scalable.
- Cron runs once logically, not once per web server.

### 20.1.1 Database connection management and pooling

PHP-FPM workers must use short-lived PDO connections by default. Do not enable PDO persistent connections merely as a performance shortcut: leaked transaction/session state and uncontrolled per-worker persistence can create correctness and connection-exhaustion risks.

- Use a managed database proxy or pooler such as Amazon RDS Proxy or a reviewed ProxySQL deployment when horizontal scale or connection pressure requires it.
- Set an explicit maximum PHP-FPM worker count per web/worker node based on the database connection budget; total possible application connections must remain below the managed MySQL limit with operational headroom.
- Use separate, least-privilege database users and connection budgets for web requests, workers, migrations and reporting.
- Open connections lazily, close/return them promptly, and never hold a transaction open while calling S3, video, email or payment APIs.
- Configure connection, read and statement timeouts; retry only safe transient connection failures and never blindly retry a partially completed transaction.
- Monitor active connections, wait time, aborted connections, pool saturation and long-running transactions. Add a pool/proxy before increasing database max_connections without analysis.

## 20.2 Caching

- Cache public catalogue and stable CourseVersion summaries with explicit invalidation.
- Do not cache permission decisions beyond safe short intervals without invalidation.
- Use CDN/provider caching for static assets and video delivery.
- Cache keys include CourseVersion where version-specific.

## 20.3 Query and page standards

- No unbounded SELECT \* for user-facing lists.
- Use pagination and explicit column lists.
- Profile slow queries and inspect execution plans.
- Avoid synchronous generation of large reports or certificates.
- Load testing targets will be completed in the Capacity & NFR Addendum.

## 20.4 Scale progression

| **Stage**          | **Likely architecture**                                                                     |
| ------------------ | ------------------------------------------------------------------------------------------- |
| Initial production | 2 web nodes or autoscaled group, managed MySQL, S3, SQS, 1-2 workers.                       |
| Growing usage      | More web/worker nodes, connection pooling, query tuning, cache, read replica for reporting. |
| High scale         | Dedicated reporting pipeline and selective service extraction only where proven necessary.  |

# 21\. AI-Assisted Development Rules

**AI role  
**AI agents may accelerate implementation, testing, refactoring and documentation. They do not own product decisions, architecture changes, production secrets, merge approval or production deployment.

## 21.1 AGENTS.md mandatory rules

- Never invent a missing requirement; raise it explicitly.
- Never change approved status machines without product approval.
- Never create Enrolment before Application is Admitted.
- Never treat a browser payment callback as trusted confirmation.
- Never modify a published CourseVersion.
- Never weaken RBAC or expose credential documents to Finance.
- Every schema change requires a migration.
- Every sensitive state change requires an audit event.
- Every asynchronous handler must be idempotent.
- Every feature must include automated tests.
- Never use real learner data or production secrets in an AI tool.

## 21.2 Work-package format

Title:  
SRS references:  
UX screen IDs:  
Business preconditions:  
Expected state transitions:  
Database changes:  
Permissions:  
Acceptance criteria:  
Tests required:  
Out of scope:  
Files/modules allowed to change:

## 21.3 Review model

- One agent may implement; a different model or human should review high-risk changes.
- Human technical approval is mandatory for auth, RBAC, payments, documents, refunds, assessment scoring and certificates.
- No agent may merge its own high-risk pull request.

# 22\. Initial Implementation Roadmap

## 22.1 Foundation phase

- Repository, Composer, coding standards, CI and environment configuration.
- Router, middleware, central error handling and logging.
- Database migration framework and base audit tables.
- Authentication, sessions, MFA and RBAC.
- SQS worker and scheduler foundation.
- S3 adapter and secure upload spike.
- Razorpay sandbox webhook and reconciliation spike.

## 22.2 First production vertical slice

The first complete slice should be:

**Vertical slice  
**Course detail -> batch selection -> applicant registration/profile -> document upload -> reviewer approval -> payment -> webhook confirmation -> admission -> scheduled/active learner dashboard.

## 22.3 Subsequent epics

| **Order** | **Epic**                                       |
| --------- | ---------------------------------------------- |
| 1         | Course and CourseVersion administration        |
| 2         | Batch, capacity and waitlist                   |
| 3         | Credential review and audit                    |
| 4         | Payments, invoices, refunds and reconciliation |
| 5         | Corporate seats and vouchers                   |
| 6         | Learning content, progress and video           |
| 7         | Live sessions and attendance                   |
| 8         | Assessments and grading                        |
| 9         | Certificates and public verification           |
| 10        | Notifications, support and reports             |

## 22.4 Pre-production reviews

- Architecture review
- Database and migration review
- RBAC matrix review
- Payment/refund review with Finance
- Privacy and retention review
- Penetration/security assessment
- Load and concurrency testing
- Backup restoration test
- UAT and release-readiness review

# 23\. Definition of Done and Architecture Decisions

## 23.1 Feature definition of done

| **Requirement** | **Done when**                                        |
| --------------- | ---------------------------------------------------- |
| Traceability    | Ticket references SRS requirement and UX screen.     |
| Implementation  | Code follows module and layering rules.              |
| Database        | Migration, constraints and indexes reviewed.         |
| Security        | Permission and object scope tested.                  |
| Audit           | Required audit events verified.                      |
| Tests           | Unit/integration/E2E tests pass as applicable.       |
| UX              | Loading, empty, validation and error states handled. |
| Operations      | Logs, metrics and failure recovery defined.          |
| Documentation   | API, configuration and runbook updated.              |
| Review          | Pull request approved and all gates pass.            |

## 23.2 Architecture decision register

| **Decision**                           | **Status**         | **Rationale**                                                                                     |
| -------------------------------------- | ------------------ | ------------------------------------------------------------------------------------------------- |
| Modular monolith                       | Approved           | Simpler transactions, testing and operations; scalable horizontally.                              |
| PHP 8.4                                | Approved           | Current supported baseline for a new build.                                                       |
| MySQL 8.4 LTS/InnoDB                   | Approved           | Team familiarity plus transactional integrity.                                                    |
| jQuery/Bootstrap                       | Approved           | Team proficiency; sufficient for product UX and browser scale.                                    |
| Webhook-first payments                 | Approved           | Real-time trustworthy state; cron reserved for reconciliation.                                    |
| SQS queue                              | Recommended        | Durable managed async processing alongside AWS S3.                                                |
| Private S3                             | Approved           | Secure document storage and signed access.                                                        |
| Mux/Cloudflare Stream                  | To select          | Protected adaptive video; avoid self-hosting.                                                     |
| Corporate contact portal               | Product decision   | Must be confirmed as Phase 1 portal or manual reporting.                                          |
| Descriptive grading                    | Product decision   | Conditional Phase 1 / Phase 2.                                                                    |
| Central state-machine services         | Approved           | Single transition maps and transactional services prevent scattered status changes.               |
| CourseVersion application + DB locking | Approved           | Published/referenced versions and owned configuration are immutable; changes require Version N+1. |
| Shared rate limiter                    | Approved           | Consistent protection across horizontally scaled nodes.                                           |
| Managed DB proxy when required         | Approved direction | Controls connection growth without unsafe PDO persistence.                                        |

## 23.3 Outstanding technical inputs

- Capacity and NFR addendum figures.
- Final hosting provider and India-region topology.
- Final queue choice and worker deployment model.
- Video provider selection.
- Transactional email provider.
- Malware-scanning implementation.
- Approved retention and backup policy.
- Final high-fidelity design system and component specification.

**Formal disposition  
**This document is approved for Phase 0 Foundation Build and Build Preparation. The broader functional build remains subject to the open decisions and conditional approvals identified in the SRS and Decision Log. No unresolved product policy may be silently implemented.

# Appendix A - Coding Style Summary

- Use declare(strict_types=1); in PHP source files.
- One class per file; PSR-4 namespaces.
- Prefer final classes unless inheritance is required.
- Use typed parameters, return types and properties.
- Use immutable DTOs/value objects where practical.
- Avoid static global state and service-locator patterns.
- Constructor injection for dependencies.
- Use domain-specific exception types.
- Functions and methods should remain small and single-purpose.
- Comments explain why, not what obvious code does.

<?php  
declare(strict_types=1);  
<br/>namespace Academy\\Domain\\Admissions;  
<br/>final class AdmitApplication  
{  
public function \__construct(  
private ApplicationRepository \$applications,  
private EnrolmentRepository \$enrolments,  
private TransactionManager \$transactions,  
private AuditWriter \$audit,  
) {}  
}

# Appendix B - Pull Request Checklist

- Requirement and screen IDs included.
- No product rule invented or changed.
- Migration included and reviewed.
- Permissions and object scope enforced.
- CSRF and validation applied.
- Audit event included where required.
- Idempotency considered.
- Tests cover success, failure and concurrency.
- Logs contain no sensitive data.
- UI states and accessibility checked.
- Documentation and runbook updated.
- No production secret or real learner data included.

# Appendix C - Example Critical Workflow Ownership

| **Workflow** | **Primary module** | **Supporting modules** |
| --- | --- | --- |
| Application admission | Admissions | Courses, Credentials, Payments, Audit, Notifications |
| Document verification | Credentials | Admissions, Storage, Audit, Notifications |
| Payment confirmation | Payments | Admissions, Enrolment, Invoice, Audit, Notifications |
| Waitlist acceptance | Admissions/Batch | Payments, Notifications, Audit |
| Assessment submission | Assessments | Learning, Audit, Notifications |
| Certificate issue | Certificates | Learning, Assessments, Attendance, Storage, Notifications |
| Refund | Payments | Invoice, Audit, Notifications, Support |