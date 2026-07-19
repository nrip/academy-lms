# Product Requirements Document

## Academy Learning Management System for Obesity and Metabolic Health Education

**Document Version:** 1.0  
**Document Status:** Draft for Product Review  
**Product Owner:** Academy Management  
**Primary Market:** India  
**Primary Users:** Doctors, nurses, allied healthcare professionals and academy staff

# 1\. Executive Summary

The Academy Learning Management System will be a dedicated digital education platform for delivering structured courses in obesity, metabolic disorders and related areas of clinical practice.

The platform must support a wide range of academic offerings, including:

- Short self-paced courses of approximately four hours
- Multi-module certificate programmes
- Cohort-based programmes
- Six-month diploma or advanced professional programmes
- Recorded, live and blended learning formats

Unlike a generic video-course platform, the Academy LMS must support controlled admission based on professional qualifications. Certain courses may be open to all healthcare professionals, while advanced programmes may be restricted to doctors or other defined professional groups.

Where eligibility restrictions apply, applicants must upload the required qualification and professional-registration documents. Academy reviewers must verify these documents before the learner is granted admission.

The platform must also support online payments, modular assessments, learner-progress tracking and two distinct certificates:

- **Certificate of Participation**, issued when the learner completes all mandatory learning requirements.
- **Certificate of Completion**, issued when the learner completes the course and passes the required assessments. This certificate will include the learner's final grade or percentage.

The initial product will be delivered as a responsive web application suitable for desktop computers, tablets and mobile browsers.

# 2\. Product Vision

To create a trusted digital medical-education platform through which the academy can deliver clinically relevant, academically structured and professionally credible education in obesity and metabolic health.

The system should make it simple for eligible healthcare professionals to:

- Discover suitable programmes
- Apply and establish eligibility
- Pay course fees
- Learn at their own pace or as part of a cohort
- Attend scheduled sessions
- Complete assessments
- Track their academic progress
- Receive verifiable certificates

At the same time, the platform should allow the academy to operate courses efficiently without depending on manual spreadsheets, email chains, shared drives or disconnected payment and certificate systems.

# 3\. Product Objectives

The product must achieve the following objectives:

## 3.1 Academic delivery

Support structured learning programmes ranging from short courses to six-month diploma programmes.

## 3.2 Controlled admission

Ensure that learners are admitted only to courses for which they meet the specified professional and academic eligibility requirements.

## 3.3 Operational efficiency

Provide academy teams with organised workflows for applications, document verification, payments, course management, assessments, certificates and learner support.

## 3.4 Academic credibility

Provide structured assessments, transparent passing rules, auditable academic records and verifiable certificates.

## 3.5 Commercial enablement

Support direct online enrolment, Indian payment methods, GST-compliant invoices, discounts and future instalment plans.

## 3.6 Learner convenience

Provide a mobile-friendly learning experience suited to doctors, nurses and healthcare staff who may study between clinical responsibilities.

# 4\. Product Principles

The following principles will guide product decisions.

## 4.1 Clinical credibility before convenience

The platform must not admit learners to restricted programmes merely because payment has been completed.

## 4.2 Configuration over hard-coding

Professional categories, document requirements, passing thresholds, completion rules and certificate formats must be configurable wherever practical.

## 4.3 Clear academic rules

Learners must be able to understand what they need to complete, what they need to pass and which certificate they will receive.

## 4.4 Minimal operational ambiguity

Applications, payments, enrolments, assessments and certificates must use clearly defined statuses.

## 4.5 Mobile-first usability

Core learner actions must work effectively on modern mobile browsers.

## 4.6 Privacy by design

Professional credentials and identity documents must be accessible only to authorised users and retained only for defined purposes.

## 4.7 Realistic content protection

The product should deter casual downloading and redistribution without making impossible promises that viewable material can never be captured.

# 5\. Scope

## 5.1 Phase 1 scope

Phase 1 will include:

- Public academy and course catalogue
- Learner registration and profile
- Configurable professional categories and qualifications
- Self-paced and cohort-based courses
- Course and batch management
- Course-specific eligibility requirements
- Qualification-document upload and review
- Approval-before-payment and payment-before-approval enrolment modes
- Razorpay and HDFC payment-gateway integration
- GST-compliant invoice and receipt generation
- Video, text, PDF, presentation, link and downloadable-resource lessons
- Basic live-session scheduling
- Manual attendance management
- Modular MCQ assessments
- Configurable attempts, time limits and passing rules
- Learner-progress tracking
- Participation and completion certificates
- Public certificate verification
- Email and in-application notifications
- Admin, faculty, reviewer and finance workflows
- Basic learner-support requests
- Academic, enrolment, verification and payment reports
- Role-based access control
- Audit logging

## 5.2 Future scope

Future phases may include:

- Native Android and iOS applications
- Automated medical-council verification
- Zoom, Google Meet or Microsoft Teams attendance synchronisation
- WhatsApp and SMS notifications
- Instalment and EMI plans
- Advanced assignments and descriptive examinations
- Online proctoring
- SCORM and xAPI content
- Multilingual courses
- Institutional learning portals
- Corporate self-service dashboards
- AI-assisted question-bank creation
- Adaptive learning pathways
- Continuing Medical Education credit integrations

## 5.3 Explicit Phase 1 exclusions

Unless separately approved, Phase 1 will not include:

- Native mobile applications
- Automated professional-registry verification
- Advanced remote proctoring
- Full SCORM authoring
- AI-based grading
- International multi-currency payment processing
- Marketplace functionality for third-party instructors
- White-labelled portals for multiple academies
- Complex learning recommendation engines

# 6\. User Types and Roles

## 6.1 Guest or prospective learner

A public visitor who can:

- Browse courses
- Review eligibility requirements
- View fees, duration and syllabus
- Review faculty information
- Register or begin an application

## 6.2 Applicant

A registered user who has started or submitted an application but has not yet received active enrolment.

The applicant can:

- Complete a personal and professional profile
- Upload required documents
- Track document-verification status
- Correct or resubmit rejected documents
- Complete payment when eligible
- View application status
- Contact academy support

## 6.3 Active learner

An admitted and enrolled student who can:

- Access authorised course content
- Attend scheduled sessions
- Track progress
- Complete assessments
- View results
- Submit support requests
- Download certificates
- Review invoices and payment receipts

## 6.4 Faculty or instructor

A faculty member assigned to one or more courses or batches.

Depending on permissions, faculty can:

- View assigned courses and batches
- Upload or review learning content
- Conduct or schedule sessions
- View learner progress
- Create or review assessment questions
- Review academic performance
- Post announcements
- Mark attendance
- Recommend additional assessment attempts
- Respond to academic queries

## 6.5 Academic evaluator

A faculty or academic user authorised to:

- Review manually evaluated submissions
- Enter marks
- Add feedback
- Recommend pass, fail or reassessment decisions
- View relevant academic records

## 6.6 Credential reviewer

An authorised academy user who can:

- View submitted eligibility documents
- Approve documents
- Reject documents with reasons
- Request resubmission
- Add internal review notes
- Approve or reject applications
- View the document-review audit history

## 6.7 Course administrator

An academy user who can:

- Create courses
- Create batches
- Add modules and lessons
- Configure eligibility rules
- Configure completion and certificate rules
- Assign faculty
- Manage schedules
- Publish or archive courses
- View course reports

## 6.8 Finance administrator

An academy user who can:

- View payments
- Review failed or pending transactions
- Generate invoices
- record approved offline payments
- Process or track refunds
- Reconcile gateway transactions
- Export finance reports

A finance administrator should not automatically have access to uploaded qualification documents.

## 6.9 Support executive

A user who can:

- View learner support requests
- Respond to queries
- Escalate academic, finance or verification issues
- Track issue resolution

## 6.10 Super administrator

A user with access to global settings, roles, permissions, gateways, templates, audit logs and system configuration.

# 7\. Course and Delivery Model

## 7.1 Course

A course is the master academic offering.

Each course must support:

- Course title
- Course code
- Short description
- Detailed description
- Learning objectives
- Intended audience
- Professional eligibility
- Required qualifications
- Required documents
- Delivery type
- Expected learning duration
- Course validity
- Fee and applicable taxes
- Certificate rules
- Assessment rules
- Faculty profiles
- Course brochure or prospectus
- Promotional image
- Status
- Version

Course statuses should include:

- Draft
- Under review
- Published
- Enrolment closed
- Unpublished
- Archived
- Cancelled

## 7.2 Course delivery types

The system must support:

### Self-paced course

The learner receives access after successful enrolment and may proceed according to configured module and access rules.

### Cohort-based course

The learner joins a defined batch with a common:

- Start date
- End date
- Enrolment period
- Content-release schedule
- Faculty
- Live-session schedule
- Assessment calendar

### Blended course

A course combining recorded content, reading material, assessments and scheduled live sessions.

## 7.3 Batch or cohort

A batch is a specific delivery instance of a course.

Each batch should support:

- Batch name
- Course
- Start date
- End date
- Enrolment opening date
- Enrolment closing date
- Maximum capacity
- Minimum capacity
- Assigned faculty
- Batch-specific fee, where applicable
- Batch-specific schedule
- Learner list
- Waiting list
- Status
- Access-expiry date
- Certificate issue date or rules

Batch statuses should include:

- Planned
- Open for applications
- Open for enrolment
- Full
- In progress
- Completed
- Cancelled
- Archived

## 7.4 Course versioning

Where academic content or assessment rules change materially, the academy must be able to create a new course version without altering the records of learners enrolled in an earlier version.

# 8\. Course Catalogue and Discovery

The public course catalogue must allow visitors to browse and filter courses.

Each course listing should display:

- Course name
- Course type
- Duration
- Delivery format
- Intended audience
- Key eligibility criteria
- Upcoming batch, where applicable
- Fee
- Application or enrolment status
- Certificate type
- Faculty
- Call to action

The course detail page should include:

- Programme overview
- Learning objectives
- Course syllabus
- Module summary
- Faculty
- Eligibility
- Required documents
- Duration and workload
- Course dates
- Fee and taxes
- Payment policy
- Refund policy
- Assessment requirements
- Certificate rules
- Frequently asked questions
- Application or enrolment button

The system should prevent applications after the configured closing date unless an administrator grants an exception.

# 9\. Learner Registration and Profile

## 9.1 Registration

Learners should be able to register using:

- Email address
- Mobile number
- Password

The system should support:

- Email verification
- Mobile OTP verification
- Forgot-password workflow
- Secure password reset
- Acceptance of terms and privacy notice

## 9.2 Personal profile

The learner profile should include:

- Full legal name
- Preferred display name
- Date of birth, where required
- Mobile number
- Email address
- Address
- City
- State
- Country
- Profile photograph
- Billing details
- Emergency or alternate contact, if required

The name used on certificates should be clearly identified and confirmed before certificate issuance.

## 9.3 Professional profile

The professional profile should support configurable fields such as:

- Profession
- Primary qualification
- Additional qualifications
- Speciality
- Current role
- Years of experience
- Employer or institution
- Medical or professional council
- Registration number
- Registration state
- Registration issue date
- Registration expiry date

Professional categories must be configurable and must not be limited to only Doctor, Nurse and Allied Medical Staff.

# 10\. Eligibility and Document Verification

## 10.1 Eligibility rules

Each course must support configurable eligibility rules based on one or more of the following:

- Profession
- Qualification
- Speciality
- Professional registration
- Registration validity
- Experience
- Employment category
- Country or jurisdiction
- Specific document approval

Example:

Applicant must be classified as a medical doctor, possess an approved MBBS degree and hold a valid medical-council registration.

## 10.2 Required-document configuration

For every course, the administrator must be able to configure:

- Document name
- Description
- Mandatory or optional
- Accepted file types
- Maximum file size
- Single or multiple files
- Front and back requirement
- Issue-date field
- Expiry-date field
- Registration-number field
- Issuing-authority field
- Whether the document may be reused for other applications
- Reviewer instructions

## 10.3 Document statuses

Each document should have its own status:

- Not uploaded
- Uploaded
- Under review
- Approved
- Rejected
- Resubmission requested
- Expired
- Superseded
- Failed security scan

The applicant must be able to see the status of every required document.

## 10.4 Reviewer workflow

The reviewer must be able to:

- Open the document securely
- Zoom and inspect the document
- Compare it with profile data
- Approve it
- Reject it
- Select a rejection reason
- Enter learner-facing corrective instructions
- Add a private internal note
- Request resubmission
- View previous submissions
- View the reviewer and review date
- Approve or reject the overall application

## 10.5 Rejection and resubmission

Typical rejection reasons should include:

- Image unclear
- Incorrect document
- Incomplete document
- Expired registration
- Name mismatch
- Qualification does not meet eligibility
- Registration number not visible
- Issuing authority not identifiable
- Other

The system must notify the applicant of the precise reason and required corrective action.

The academy should be able to configure a maximum number of document-resubmission attempts or refer the application for manual intervention.

## 10.6 Reuse of verified credentials

The academy should be able to permit previously approved documents to be reused for another application, provided that:

- The document has not expired
- The new course accepts that document type
- The academy has not marked re-verification as mandatory

# 11\. Application, Payment and Enrolment Workflow

## 11.1 Configurable admission mode

Each course or batch must support one of the following admission modes.

### Mode A: Verification before payment

Recommended for high-value or restricted programmes.

- Learner creates an account.
- Learner completes the application.
- Learner uploads required documents.
- Academy reviews eligibility.
- Application is approved.
- Learner receives a payment request.
- Learner pays.
- Enrolment becomes active.

### Mode B: Payment before verification

Suitable for selected low-risk courses.

- Learner creates an account.
- Learner pays the course fee.
- Learner uploads required documents.
- Academy reviews eligibility.
- Course access is activated after approval.
- If the applicant is ineligible, the academy follows the configured refund, transfer or resubmission policy.

### Mode C: Direct enrolment

Suitable for unrestricted courses that do not require document verification.

- Learner registers.
- Learner pays.
- Enrolment is activated.

## 11.2 Application statuses

Application statuses should include:

- Draft
- Submitted
- Documents incomplete
- Under review
- Resubmission requested
- Approved
- Rejected
- Withdrawn
- Expired

## 11.3 Payment statuses

Payment statuses should include:

- Not initiated
- Pending
- Successful
- Failed
- Cancelled
- Expired
- Refunded
- Partially refunded
- Disputed

## 11.4 Enrolment statuses

Enrolment statuses should include:

- Awaiting payment
- Payment received
- Awaiting verification
- Enrolled
- Access active
- In progress
- Academically completed
- Passed
- Not passed
- Suspended
- Withdrawn
- Cancelled
- Refunded
- Access expired

The product must avoid relying on a single access Boolean where it can conflict with the enrolment status.

## 11.5 Enrolment confirmation

The learner should receive a confirmation containing:

- Course name
- Batch
- Start date
- Access date
- Course-end date
- Payment receipt
- Invoice
- Login link
- Academy support information

# 12\. Payments, Invoicing and Refunds

## 12.1 Payment gateways

Phase 1 must support:

- Razorpay
- HDFC Payment Gateway

Only one gateway should process an individual payment attempt.

The administrator may select the active gateway globally or for a particular course. Changing the active gateway should affect new payment attempts only.

## 12.2 Supported payment methods

Subject to gateway support, the platform should support:

- UPI
- Debit cards
- Credit cards
- RuPay
- Visa
- Mastercard
- Netbanking

## 12.3 Payment handling

The platform must support:

- Creation of a payment order
- Redirect or checkout initiation
- Server-side payment confirmation
- Webhook validation
- Payment failure
- User cancellation
- Pending payment
- Retry
- Duplicate-callback handling
- Prevention of duplicate enrolment
- Recording of gateway references
- Reconciliation reporting

Course access must not be activated merely because the learner's browser shows a payment-success page. Payment must be confirmed through a trusted server-side gateway response.

## 12.4 Pricing

Each course or batch may have:

- Base fee
- GST
- Discount
- Coupon
- Scholarship
- Complimentary enrolment
- Corporate sponsorship
- Revised fee for a specific batch

## 12.5 GST invoice

The system should generate a digital invoice containing applicable details such as:

- Academy legal name
- Registered address
- GSTIN
- Invoice number
- Invoice date
- Learner or organisation name
- Billing address
- Customer GSTIN, where applicable
- Course description
- Taxable amount
- Applicable GST
- Total amount
- Payment reference

## 12.6 Refunds

The academy should be able to configure refund rules by course.

Refund workflows should support:

- Full refund
- Partial refund
- Refund after verification rejection
- Refund after course cancellation
- Administrative deduction, where policy permits
- Refund status tracking
- Credit-note generation, where applicable
- Learner notification

The PRD does not determine the legal or commercial refund policy. The academy must approve a formal refund policy before development sign-off.

## 12.7 Offline payments

Authorised finance users should be able to record approved offline payments such as bank transfers or institutional payments. Such records must include supporting references and an audit trail.

# 13\. Curriculum, Modules and Learning Content

## 13.1 Course structure

The course hierarchy should be:

Course → Batch → Modules → Lessons or Activities → Assessments

## 13.2 Module configuration

Each module should support:

- Title
- Description
- Sequence
- Learning objectives
- Estimated duration
- Mandatory or optional status
- Release rule
- Prerequisite
- Completion rule
- Module assessment
- Faculty

## 13.3 Content types

Phase 1 should support:

- Video
- Text lesson
- PDF
- Presentation
- Audio
- Downloadable resource
- External link
- Recorded webinar
- Live session
- MCQ assessment
- Feedback form

Assignments and faculty-evaluated submissions may be included in Phase 1 if required for the first diploma programme; otherwise, they may be scheduled for Phase 2.

## 13.4 Content release

The system should support:

- Immediate access
- Sequential release
- Release after completion of a previous module
- Release a specified number of days after enrolment
- Release on a fixed calendar date
- Manual release by administrator

## 13.5 Completion rules

Completion must be based on configurable activity rules.

Examples:

- Video watched to a specified percentage
- Text lesson marked complete
- PDF opened and acknowledged
- Live session attended
- Assessment submitted
- Assessment passed
- Feedback form submitted
- Administrator or faculty marks complete

Course completion should require completion of all mandatory activities.

## 13.6 Video learning experience

The video player should support:

- Resume from last position
- Playback-speed control
- Full-screen viewing
- Adaptive streaming
- Captions, where available
- Watch-progress recording
- Mobile-browser playback

The platform should use secure, time-limited delivery and reasonable anti-sharing deterrents. It should not claim that screen capture can be completely prevented.

Optional visible watermarking may display the learner's name or email during video playback.

# 14\. Live Sessions and Attendance

Basic live-session management will be included in Phase 1.

The academy should be able to:

- Create a live session
- Assign it to a course or batch
- Set date, time and duration
- Assign faculty
- Add a meeting link
- Add preparation material
- Send reminders
- Mark attendance
- Record attendance percentage
- Add a session recording
- Reschedule or cancel a session

Attendance may be:

- Manually recorded
- Imported through a spreadsheet
- Automatically synchronised in a future phase

Course or certificate eligibility may include a configurable minimum attendance requirement.

# 15\. Assessments and Grading

## 15.1 Assessment types

Phase 1 must support MCQ assessments.

The architecture should allow later addition of:

- Multiple-response questions
- True or false
- Case-based questions
- Short answers
- Descriptive answers
- File-upload assignments
- Practical evaluations

## 15.2 Assessment configuration

Administrators should be able to configure:

- Assessment title
- Linked module
- Question bank
- Number of questions presented
- Marks per question
- Passing percentage
- Time limit
- Opening date
- Closing date
- Maximum attempts
- Cooldown between attempts
- Question randomisation
- Answer-option randomisation
- Whether navigation is free or sequential
- Whether unanswered questions are permitted
- Automatic submission when time expires
- Whether results are displayed immediately
- Whether correct answers or explanations are displayed
- Whether the highest, latest or average attempt is counted

## 15.3 Assessment continuity

The system should:

- Autosave responses
- Preserve progress during temporary connectivity interruption
- Prevent more than one active attempt for the same learner
- Record attempt start and submission times
- Record timeout events
- Record administrative overrides

## 15.4 Attempt exhaustion

When the learner uses all permitted attempts:

- The assessment should be locked.
- The learner should be notified.
- The learner may submit a reassessment request.
- An authorised faculty member or administrator may grant an additional attempt.
- The override must be recorded in the audit trail.

## 15.5 Passing model

Each course should support one of the following configurable models:

### Model A: Every mandatory assessment must be passed

The learner must meet the passing threshold for each required module.

### Model B: Aggregate passing

The learner must achieve the configured final aggregate, even if an individual module score is lower.

### Model C: Combined rule

The learner must pass specified critical assessments and also achieve the required overall aggregate.

## 15.6 Final percentage

Where weighted grading is used, every mandatory assessment may have a configurable contribution to the final percentage.

The system should use the selected attempt rule-such as highest attempt or latest attempt-when calculating the final result.

## 15.7 Grade scale

Each course should support a configurable grade scale.

Example:

- 85% and above: Distinction
- 70% to 84.99%: Grade A
- 60% to 69.99%: Grade B
- Below 60%: Not passed

The academy must approve the actual grading scheme for each programme.

## 15.8 Manual adjustment

Only specifically authorised academic users may alter a result or final grade.

Every adjustment must record:

- Original value
- Revised value
- Reason
- Authorising user
- Date and time

# 16\. Certificates

## 16.1 Certificate of Participation

A Certificate of Participation will be issued when the learner:

- Completes all mandatory learning activities
- Meets any configured attendance requirement
- Completes other configured participation requirements

Assessment performance will not prevent issuance of this certificate unless the course specifically defines assessment submission as a mandatory participation activity.

## 16.2 Certificate of Completion

A Certificate of Completion will be issued when the learner:

- Meets all participation requirements
- Completes all mandatory assessments
- Meets the course's passing rules

The certificate should display the final grade, classification or percentage according to course configuration.

## 16.3 Certificate content

Certificates should support:

- Academy name and logo
- Learner's confirmed certificate name
- Course title
- Batch, where relevant
- Course duration or credit hours
- Completion date
- Grade or percentage, where applicable
- Certificate number
- QR code
- Authorised signatures
- Issuance date
- Public verification URL

## 16.4 Public verification

A third party should be able to verify a certificate using its QR code or certificate number.

The public verification page should display only necessary information, such as:

- Certificate validity
- Learner name
- Course
- Certificate type
- Grade, where applicable
- Issue date
- Revoked status

## 16.5 Reissue and revocation

Authorised administrators should be able to:

- Correct a learner's name
- Reissue a certificate
- Revoke a certificate
- Record a reason
- Preserve previous issuance history

One enrolment may produce both a Participation Certificate and a Completion Certificate.

# 17\. Learner Dashboard

The learner dashboard should display:

- Current courses
- Application statuses
- Required document statuses
- Upcoming sessions
- Course progress
- Next recommended activity
- Assessments due
- Assessment results
- Course-expiry date
- Certificates
- Invoices and receipts
- Notifications
- Support requests

Learners should be able to resume their most recent course activity directly from the dashboard.

# 18\. Faculty Dashboard

Faculty should be able to view:

- Assigned courses
- Assigned batches
- Upcoming live sessions
- Learner lists
- Attendance
- Progress summaries
- Assessment performance
- Learners requiring academic intervention
- Announcements
- Academic queries

Permissions to edit course content or assessments should depend on the faculty member's assigned role.

# 19\. Administrative Dashboard

## 19.1 Application and verification dashboard

The dashboard should show:

- Applications awaiting review
- Documents awaiting review
- Resubmissions
- Rejected applications
- Applications nearing expiry
- Average review turnaround time

## 19.2 Course operations dashboard

The dashboard should show:

- Active courses
- Active batches
- Upcoming courses
- Learner counts
- Capacity utilisation
- Courses nearing completion
- Delayed modules
- Upcoming live sessions

## 19.3 Academic dashboard

The dashboard should show:

- Learner progress
- Module completion
- Assessment attempts
- Pass and fail rates
- Learners at risk of non-completion
- Certificates pending issuance

## 19.4 Finance dashboard

The dashboard should show:

- Successful payments
- Pending payments
- Failed payments
- Refunds
- Revenue by course
- Revenue by batch
- Gateway reconciliation status
- Offline payments
- Outstanding institutional invoices

# 20\. Notifications and Communication

Phase 1 should support email and in-application notifications.

Notifications should be generated for events such as:

- Registration
- Email or mobile verification
- Application saved
- Application submitted
- Missing documents
- Document approval
- Document rejection
- Application approval
- Application rejection
- Payment request
- Payment success
- Payment failure
- Enrolment confirmation
- Course start
- New module release
- Upcoming live session
- Session rescheduled
- Assessment opening
- Assessment deadline
- Assessment result
- Attempt limit reached
- Course expiry approaching
- Certificate issuance
- Refund initiation
- Refund completion
- Support response

Notification templates should be editable by authorised administrators.

# 21\. Learner Support and Grievances

Phase 1 should include a basic support-request facility.

A learner should be able to raise a request under categories such as:

- Technical issue
- Payment issue
- Refund issue
- Document-verification issue
- Course-access issue
- Academic query
- Assessment issue
- Certificate correction
- Other

Each request should have:

- Ticket number
- Learner
- Course or enrolment
- Category
- Description
- Attachments
- Status
- Assigned team
- Responses
- Resolution
- Closure date

Ticket statuses should include:

- Open
- Assigned
- Awaiting learner
- Escalated
- Resolved
- Closed

# 22\. Reporting and Exports

The system should provide filterable reports for:

## 22.1 Applications and verification

- Applications by status
- Pending documents
- Rejection reasons
- Verification turnaround time
- Reviewer activity
- Expiring professional registrations

## 22.2 Enrolment

- Enrolments by course
- Enrolments by batch
- Enrolments by profession
- Active learners
- Withdrawals
- Suspensions
- Completion rates

## 22.3 Academic performance

- Course progress
- Module completion
- Assessment scores
- Attempt counts
- Pass and fail rates
- Grade distribution
- Learners requiring intervention
- Attendance

## 22.4 Finance

- Payments
- Payment failures
- Refunds
- Discounts
- GST
- Revenue by course
- Revenue by batch
- Gateway reconciliation
- Offline payments
- Institutional invoices

## 22.5 Certification

- Participation certificates
- Completion certificates
- Reissued certificates
- Revoked certificates
- Certificate verification activity

Reports should support export to CSV or Excel.

# 23\. Corporate and Sponsored Enrolments

Phase 1 may include academy-managed corporate enrolment.

The system should support:

- Organisation record
- Organisation contact
- Purchase order or agreement reference
- Course or batch
- Number of sponsored seats
- Named or unnamed seats
- Seat assignment
- Seat expiry
- Offline or online payment
- Invoice
- Voucher or invitation code
- Seat utilisation report
- Learner progress report, subject to privacy and consent rules

A sponsored learner must still meet the academic and professional eligibility requirements of the course.

# 24\. Security and Privacy Requirements

The platform must implement appropriate controls for:

- Encryption in transit
- Encryption at rest
- Secure password storage
- Role-based access
- Administrator multi-factor authentication
- Session expiry
- Login throttling
- Secure password reset
- Malware scanning of uploaded files
- File-type validation
- Time-limited document access
- Access logging
- Audit trails
- Secure backups
- Controlled data export
- Secure deletion

Sensitive qualification documents should be visible only to authorised reviewers and administrators.

The product should support:

- Privacy notice
- Consent capture
- Purpose limitation
- Data-correction requests
- Account and data requests
- Retention rules
- Grievance contact
- Breach-response procedures

Specific legal interpretations, retention periods and hosting requirements must be reviewed and approved by appropriate legal and privacy advisers.

# 25\. Audit Requirements

The system must maintain audit records for important actions, including:

- Document approval and rejection
- Application approval and rejection
- Payment and refund actions
- Offline-payment entry
- Course publication
- Course configuration changes
- Learner access changes
- Attendance overrides
- Additional assessment attempts
- Grade changes
- Certificate issuance
- Certificate reissue
- Certificate revocation
- Permission changes

Audit records should capture:

- User performing the action
- Action
- Affected record
- Previous value
- New value
- Reason
- Date and time

# 26\. Non-Functional Product Requirements

## 26.1 Responsive access

The platform must provide an effective experience on:

- Desktop browsers
- Tablets
- Modern Android mobile browsers
- iPhone Safari

## 26.2 Performance

Pages should load within reasonable time under expected operating conditions.

Video should use adaptive delivery suitable for variable Indian network conditions.

The SRS will define measurable performance thresholds after expected learner volumes are confirmed.

## 26.3 Availability

The production platform should have a defined uptime target, planned-maintenance policy and support-escalation process.

## 26.4 Backup and recovery

The system must support:

- Regular backups
- Recovery testing
- Defined recovery-point objective
- Defined recovery-time objective

## 26.5 Accessibility

The platform should support:

- Clear typography
- Adequate contrast
- Keyboard-accessible navigation
- Captions where available
- Readable assessment layouts
- Screen-reader-compatible core workflows where practical

## 26.6 Scalability

The architecture should support growth in:

- Registered users
- Concurrent learners
- Video views
- Courses
- Batches
- Uploaded documents
- Assessments
- Certificates

Capacity assumptions will be defined before technical architecture approval.

# 27\. Product Success Metrics

The academy should monitor:

- Course-detail-page to application conversion
- Application completion rate
- Average document-verification time
- Application approval rate
- Payment success rate
- Enrolment activation time
- Course completion rate
- Assessment pass rate
- Learner dropout rate
- Certificate issuance success rate
- Support-ticket response time
- Refund turnaround time
- Learner satisfaction
- Faculty satisfaction
- Administrative effort per learner

Initial targets should be approved after baseline operational data is available.

# 28\. Dependencies

The product depends on:

- Approved academic policies
- Approved course eligibility rules
- Approved assessment and grading policies
- Approved certificate designs
- Approved refund policy
- Approved privacy and data-retention policy
- Razorpay merchant credentials
- HDFC gateway credentials
- GST and invoicing configuration
- Email-delivery service
- Video-hosting or content-delivery service
- Academy domain and branding
- Faculty and course content
- Support and escalation processes

# 29\. Key Risks

## 29.1 Slow document verification

Delayed reviews may reduce enrolment conversion and create support requests.

**Mitigation:** Verification queues, turnaround targets, reminders and workload reporting.

## 29.2 Unclear academic rules

Inconsistent pass, reattempt and certificate decisions may cause learner disputes.

**Mitigation:** Course-level academic configuration and approved written policies.

## 29.3 Refund disputes

Payment-before-verification courses may create refund requests.

**Mitigation:** Prefer approval-before-payment for restricted programmes and publish clear refund terms.

## 29.4 Learner drop-off

Long programmes may experience declining participation.

**Mitigation:** Progress reminders, faculty intervention reports, scheduled sessions and learner support.

## 29.5 Content redistribution

Learners may attempt to download or share course material.

**Mitigation:** Secure streaming, signed access, visible watermarking, terms of use and monitoring.

## 29.6 Poor connectivity

Healthcare professionals may study from locations with inconsistent internet access.

**Mitigation:** Adaptive video, resumable learning, autosaved assessments and mobile optimisation.

## 29.7 Excessive administrative complexity

Overly complicated configuration may burden academy staff.

**Mitigation:** Simple defaults, reusable templates and clear operational dashboards.

# 30\. Open Product Decisions

The academy must approve the following before the SRS is finalised:

- Which courses will use verification before payment?
- Which courses may use payment before verification?
- What is the refund policy for eligibility rejection?
- Will Phase 1 include assignments and descriptive assessments?
- What video-watch percentage constitutes completion?
- Must every module assessment be passed individually?
- Which attempt-highest, latest or average-counts toward the final grade?
- How many assessment attempts are normally permitted?
- What cooldown applies between attempts?
- What grade bands will be used?
- Is minimum live-session attendance required for long courses?
- How long will learners retain access after course completion?
- Will verified credentials be reusable across applications?
- What is the retention period for uploaded documents?
- Will corporate enrolment be part of the initial release?
- Will coupons, scholarships and complimentary enrolments be required in Phase 1?
- Is English the only initial platform language?
- Are learners outside India expected in Phase 1?
- Will CME credits be displayed on certificates?
- Which reports are essential for the first operational launch?

# 31\. Acceptance of the PRD

This PRD will be considered approved when the academy's academic, operational, finance and management stakeholders have confirmed:

- Phase 1 scope
- User roles
- Course and batch model
- Admission workflows
- Eligibility rules
- Payment and refund rules
- Assessment policies
- Grading policies
- Certificate rules
- Reporting needs
- Security and privacy expectations
- Open product decisions

Following PRD approval, the next deliverables will be:

- End-to-end workflow diagrams

- Role and permission matrix

- Screen inventory and wireframes

- Functional SRS

- Acceptance criteria and test scenarios

- Technical architecture and data model

- Development estimate and release plan