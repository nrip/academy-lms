# WP-01B-2d — Learner Profile (implementation note)

**Branch:** `slice/wp01b-2d-learner-profile`  
**Authority:** SRS REQ-PROF-1/2, PRD §9.2–9.3, Screen Inventory A-01/A-02, existing `profile.*` RBAC keys, Binding decision: active users only may edit.

## Scope choices (no Blocker)

| Topic | Decision |
|---|---|
| Wireframes A-01/A-02 | Not in wireframe HTML; minimal Bootstrap forms from inventory + hi-fi certificate-name confirm. |
| `professional_categories` | Deferred (SA-03 / REQ-PROF-3). Store `profession` as nullable VARCHAR. |
| Photo / billing / emergency contact | Out of B-2d (uploads → Application WP; billing → Payment; emergency soft-required). `alternate_mobile` included as optional. |
| Gender / nationality | Optional nullable fields listed in WP brief; not named in SRS — collected as optional, never required for completeness. |
| Pending verification | Unchanged allow-list; profile `*.edit_own` / `*.view_own` remain denied until `active`. |
| Completeness | Computed only; not an auth or login gate. |
| Admin read | `profile.view_any` / `profile.edit_any` already seeded; B-2d routes are own-profile only. Service enforces ownership; no admin profile UI. |

## Authoritative field list (implemented)

**Personal (`learner_profiles`):** `first_name`, `middle_name`, `last_name`, `preferred_display_name`, `certificate_name`, `certificate_name_confirmed`, `date_of_birth`, `gender`, `nationality`, `address_line_1`, `address_line_2`, `city`, `state`, `postal_code`, `country`, `alternate_mobile`.

**Professional:** `profession`, `speciality`, `current_designation`, `organization_name`, `years_of_experience`, `medical_council_name`, `medical_council_registration_number`, `medical_council_registration_state`, `registration_valid_from`, `registration_valid_until`.

**Qualifications (`learner_qualifications`):** `qualification_type`, `qualification_name`, `institution_name`, `university_or_board`, `country`, `completion_year`, `registration_or_certificate_number`, `display_order`, `row_version`.

**Not collected:** Aadhaar/PAN/passport/bank/health/caste/religion, profile photo binary, degree/council document uploads.

## Completeness matrix (required keys)

- **core_personal:** first_name, last_name, preferred_display_name, date_of_birth, certificate_name, certificate_name_confirmed=true  
- **contact_address:** address_line_1, city, state, postal_code, country  
- **professional:** profession, current_designation, organization_name, years_of_experience, speciality  
- **medical_registration:** medical_council_name, medical_council_registration_number, medical_council_registration_state, registration_valid_from, registration_valid_until  
- **qualifications:** ≥1 row with qualification_type, qualification_name, institution_name, completion_year  

Percentage = completed required keys / total required keys (deterministic allow-list).

## Optimistic locking

CAS on `row_version` for profile and qualification mutations; `rowCount=0` → `ConflictException` → HTTP 409.
