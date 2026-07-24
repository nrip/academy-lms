# UAT Defect Log Template — Academy LMS (RC-01)

**Release candidate:** RC-01  
**UAT cycle / environment:** _(e.g. uat-2026-07-24)_  
**Build / commit:** _(SHA or tag)_  
**Log owner:** _

Severity definitions and release rules: [UAT_OVERVIEW.md](./UAT_OVERVIEW.md).

---

## Summary counts

| Severity | Open | Fixed | Deferred | Waived |
|---|---:|---:|---:|---:|
| Blocker | 0 | 0 | 0 | 0 |
| Critical | 0 | 0 | 0 | 0 |
| Major | 0 | 0 | 0 | 0 |
| Minor | 0 | 0 | 0 | 0 |
| Cosmetic | 0 | 0 | 0 | 0 |

**UAT exit check:** Open Blocker + Critical must be **0**. Majors need Product Owner disposition.

---

## Defect entries

Copy a block per defect.

### DEF-___

| Field | Value |
|---|---|
| **ID** | DEF-___ |
| **Title** | |
| **Severity** | Blocker / Critical / Major / Minor / Cosmetic |
| **Security / integrity override?** | Yes / No |
| **Test ID** | e.g. UAT-L-15 |
| **Persona** | |
| **Environment** | local / uat / … |
| **Build / commit** | |
| **Found by** | |
| **Found on** | YYYY-MM-DD |
| **Status** | Open / In progress / Fixed / Deferred / Waived / Duplicate |
| **Disposition owner** | |
| **Related readiness ID** | e.g. PR-RZP (if gap, not a defect) |

**Steps to reproduce**

1.
2.
3.

**Expected**

**Actual**

**Evidence** (request IDs, public refs, screenshots — no secrets/PII dumps)

**Workaround**

**Fix / verification notes**

**Deferred / waiver rationale** (required if not Fixed before sign-off)

---

## Deferred / waived index

| ID | Severity | Rationale | Approver | Target |
|---|---|---|---|---|
| | | | | |

---

## Notes

- Do not paste passwords, OTP/TOTP, session cookies, CSRF tokens, webhook signatures, document contents, or production data.
- Prefer UAT emails (`*@uat.example.test`) and public references (`UAT-*`).
- Link readiness gaps to [PRODUCTION_READINESS_REGISTER.md](../product/PRODUCTION_READINESS_REGISTER.md) instead of closing them as “fixed”.
