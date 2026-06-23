# TEST PLAN: KERANI UNIT-SCOPED REALIZATION ACCESS

**Objective:** Verify that KERANI role can only record realizations (Realisasi Dana) for PDOs belonging to their assigned plantation unit. KERANI from one unit should NOT be able to record realizations for PDOs from other units.

---

## BUSINESS REQUIREMENT

- Each KERANI user is assigned to exactly ONE plantation unit (e.g., BN = Binanga, OR = Orangkasih, etc.)
- KERANI should only see and access PDOs from their own unit
- KERANI should NOT be able to:
  - See PDOs from other units
  - Select PDOs from other units in the dropdown
  - Record realizations on PDOs from other units
  - Access PDO data via direct API calls for other units

---

## SETUP DATA

### Test Accounts (KERANI users)

| User | Email | Password | Unit | Unit Code |
|------|-------|----------|------|-----------|
| Aswan Updated | aswan@barumun-plantation.com | TestPass123! | Binanga | BN |
| [Other KERANI] | [TBD] | TestPass123! | [Other Unit] | [TBD] |

### Test PDOs

| PDO Number | Unit | Unit Code | Status | Expense Items |
|-----------|------|-----------|--------|----------------|
| PDO-2026-09-BN-001 | Binanga | BN | Final | ✓ Has transfers |
| PDO-2026-08-OR-001 | [Other Unit] | OR | Final | ✓ Has transfers |
| [Other PDOs] | [Various] | [Various] | Final | ✓ For testing |

---

## TEST CASES

### TEST 1: KERANI FROM BN UNIT - ACCESS CONTROL

**Setup:** Login as KERANI from BN (Binanga) unit
- User: aswan@barumun-plantation.com
- Expected Unit Assignment: BN (Binanga)

**Test 1A: List PDOs in Realisasi Dana menu**

1. Navigate to Realisasi Dana page
2. Check visible PDOs in dropdown
3. **Expected Result:**
   - ✅ Can see PDO-2026-09-BN-001 (own unit)
   - ❌ CANNOT see PDOs from other units (OR, KH, etc.)
   - Only PDOs from BN unit should appear in dropdown

**Test 1B: Attempt to record realisasi on own unit PDO**

1. Login as KERANI BN
2. Navigate to Realisasi Dana
3. Click "Input Realisasi"
4. Select PDO-2026-09-BN-001 (BN unit PDO)
5. Select item, enter amount, payment method, etc.
6. Submit entry
7. **Expected Result:**
   - ✅ Form accepts PDO selection
   - ✅ Item dropdown populates with BN PDO items
   - ✅ Entry can be submitted successfully
   - ✅ Entry appears in table
   - **Status: SHOULD ACCEPT**

**Test 1C: Attempt to access other unit PDO via form selection**

1. Login as KERANI BN
2. Navigate to Realisasi Dana
3. Click "Input Realisasi"
4. Open PDO dropdown
5. Try to find/select OR unit PDO (e.g., PDO-2026-08-OR-001)
6. **Expected Result:**
   - ❌ OR PDO NOT visible in dropdown
   - ❌ Cannot select PDO from other unit
   - **Status: SHOULD NOT APPEAR**

**Test 1D: Attempt direct API call to record realisasi on other unit PDO**

1. Login as KERANI BN (get auth token)
2. Identify a PDO detail from OR unit
3. Make POST request to `/api/v1/realization-entries` with:
   - `pdo_detail_id`: from OR unit PDO
   - Other required fields (amount, date, proof_number, etc.)
4. **Expected Result:**
   - ❌ REJECTED with 403 Forbidden error
   - Error message: "Unauthorized - PDO does not belong to user's unit" or similar
   - **Status: SHOULD REJECT**

**Test 1E: Attempt direct API call to view other unit PDO details**

1. Login as KERANI BN
2. Make GET request to `/api/v1/pdo/[OR_UNIT_PDO_ID]/details`
3. **Expected Result:**
   - ❌ REJECTED with 403 Forbidden error
   - Cannot retrieve PDO details from other units
   - **Status: SHOULD REJECT**

---

### TEST 2: DIFFERENT KERANI FROM OTHER UNIT - ACCESS CONTROL

**Setup:** Login as KERANI from different unit (e.g., OR = Orangkasih)
- User: [Other KERANI account]
- Expected Unit Assignment: OR (or other unit)

**Test 2A: Verify only own unit PDOs visible**

1. Login as KERANI OR
2. Navigate to Realisasi Dana
3. Check PDO dropdown
4. **Expected Result:**
   - ✅ Can see OR unit PDOs
   - ❌ CANNOT see BN unit PDOs
   - ❌ CANNOT see PDOs from other units
   - Only OR unit PDOs appear in dropdown

**Test 2B: Record realisasi on own unit PDO**

1. Login as KERANI OR
2. Navigate to Realisasi Dana
3. Select OR unit PDO
4. Fill and submit realisasi entry
5. **Expected Result:**
   - ✅ Entry accepted for OR unit PDO
   - ✅ Entry visible in table
   - **Status: SHOULD ACCEPT**

**Test 2C: Attempt access to BN unit PDO**

1. Login as KERANI OR
2. Try to open form and select BN PDO
3. **Expected Result:**
   - ❌ BN PDO NOT visible in dropdown
   - ❌ Cannot select BN PDO
   - **Status: SHOULD NOT APPEAR**

**Test 2D: API call attempt on BN unit PDO**

1. Login as KERANI OR (get token)
2. Identify BN unit PDO detail
3. POST to `/api/v1/realization-entries` with BN PDO detail
4. **Expected Result:**
   - ❌ REJECTED with 403 Forbidden
   - Cannot record realisasi for other unit
   - **Status: SHOULD REJECT**

---

### TEST 3: DATA ISOLATION VERIFICATION

**Setup:** Compare data visible to different KERANI users

**Test 3A: Realisasi entries isolation**

1. KERANI BN records: REF-BN-001 on BN PDO
2. KERANI OR records: REF-OR-001 on OR PDO
3. Login as KERANI BN, view Realisasi Dana table
4. **Expected Result:**
   - ✅ Can see REF-BN-001 (own entry on own unit)
   - Question: Can see REF-OR-001? (depends on business logic)
   - Typically: Show all entries but filter by unit context

**Test 3B: List PDOs after multiple unit entries**

1. Multiple KERANI from different units record realizations
2. Login as KERANI BN
3. Check PDO dropdown
4. **Expected Result:**
   - ✅ Only BN PDOs visible in dropdown
   - ❌ Other unit PDOs never appear in dropdown

---

## VALIDATION RULES TO CHECK

| Rule | Implementation | Test Case |
|------|---------------|-----------| 
| **Unit Scope Filter** | Dropdown only shows PDOs for user's unit | TEST 1A, 2A |
| **Form Validation** | Can submit for own unit PDO | TEST 1B, 2B |
| **Dropdown Isolation** | Cannot select other unit PDO | TEST 1C, 2C |
| **API Authorization** | Backend rejects other unit PDO | TEST 1D, 2D |
| **PDO Detail Access** | Cannot fetch other unit PDO details | TEST 1E |
| **Data Integrity** | Entries only recorded for own unit | TEST 3A, 3B |

---

## EXPECTED ERRORS

When KERANI tries to access other unit PDO:

### Frontend (Form Level)
- Other unit PDO simply not visible in dropdown
- User cannot select what doesn't exist

### Backend (API Level)
- **Status Code:** 403 Forbidden
- **Error Code:** `UNAUTHORIZED` or `UNIT_MISMATCH`
- **Error Message:** 
  - "PDO does not belong to your unit"
  - "Unauthorized to record realisasi for this unit"
  - "You can only record realisasi for your assigned unit"

---

## SUCCESS CRITERIA

✅ **All Tests PASS if:**

1. ✅ KERANI can ONLY see PDOs from their assigned unit in dropdown
2. ✅ KERANI can successfully record realizations on own unit PDOs
3. ✅ KERANI CANNOT select PDOs from other units (dropdown empty)
4. ✅ KERANI API calls to other unit PDOs return 403 Forbidden
5. ✅ Backend enforces unit scope even for direct API access
6. ✅ No cross-unit data contamination or visibility

---

## TESTING NOTES

### Prerequisite Checks
- [ ] Verify Aswan is assigned to BN unit in system
- [ ] Verify [Other KERANI] is assigned to different unit
- [ ] Verify test PDOs exist for multiple units
- [ ] Verify test PDOs have transfers (needed for realisasi)

### Manual Testing Steps
1. Test with UI - dropdown visibility and form submission
2. Test with API directly - authorization header and unit validation
3. Test with multiple KERANI accounts - data isolation
4. Test edge cases - direct URL access, API calls with different tokens

### Debugging If Tests Fail
- Check User model: Does it have plantation_unit_id field?
- Check Query Builder: Is it filtering by unit in PDO queries?
- Check Middleware: Is there ensure.unit.access middleware on routes?
- Check Controller: Are endpoints checking user unit vs PDO unit?
- Check Database: Are relationships properly set up?

---

## RELATED CODE AREAS TO REVIEW

| File | Responsibility |
|------|-----------------|
| `apps/web/src/pages/RealizationPage.tsx` | Frontend PDO dropdown filtering |
| `apps/api/app/Http/Controllers/Realization/RealizationEntryController.php` | Store/update authorization |
| `apps/api/app/Models/User.php` | Unit assignment, unit access methods |
| `apps/api/app/Models/PdoHeader.php` | PDO-unit relationship |
| `apps/api/routes/api.php` | Middleware application on routes |
| `apps/api/app/Http/Middleware/` | Unit access verification logic |

---

## TICKET TEMPLATE FOR FAILURES

If any test fails, document:

```
Test Case: [TEST 1A / 1B / 1C / etc.]
Status: FAILED
Expected: [What should happen]
Actual: [What actually happened]
Error: [Any error message shown]
Evidence: [Screenshot or API response]
Root Cause: [Investigation findings]
Severity: [CRITICAL / HIGH / MEDIUM]
```

---

## SIGN-OFF

Once all tests pass:

- [ ] Unit-scoped access control working correctly
- [ ] No security gaps for cross-unit access
- [ ] KERANI users isolated to their units
- [ ] Ready for production deployment

