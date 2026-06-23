# TEST PLAN: REALISASI DANA (Fund Realization)

**Objective:** Comprehensive testing of the Realisasi Dana menu covering all payment method × funding source combinations, multiple realizations per item, and cumulative limit validation.

---

## SETUP DATA

### Prerequisite PDO
- **PDO Number:** PDO-2026-10-BN-001
- **Status:** Final
- **Unit:** BN (Binanga)
- **Period:** October 2026

### Test Items (PdoDetail)

| Item Name | Amount | Transfer Status | Status |
|-----------|--------|---|---|
| Upah Potong Buah TBS | Rp 1.000.000 | Fully Transferred | Final |
| Herbisida Sistemik | Rp 2.000.000 | Fully Transferred | Final |
| Upah Babat Piringan | Rp 1.500.000 | Fully Transferred | Final |

**Total Transfer Amount:** Rp 4.500.000

---

## TEST MATRIX: Payment Method × Funding Source

| # | Metode Pembayaran | Sumber Dana | Test Case |
|---|---|---|---|
| 1 | Tunai (Cash) | Kas Kebun (Estate Cash) | Record cash payment from estate cash |
| 2 | Tunai | Rekening Kebun (Estate Account) | Record cash withdrawn from estate account |
| 3 | Tunai | Rekening Utama (Main Account) | Record cash withdrawn from main account |
| 4 | Transfer Bank | Kas Kebun | Transfer from cash (invalid/edge case) |
| 5 | Transfer Bank | Rekening Kebun | Direct transfer from estate account |
| 6 | Transfer Bank | Rekening Utama | Direct transfer from main account |
| 7 | Kas Kecil (Petty Cash) | Kas Kebun | Petty cash from estate cash |
| 8 | Kas Kecil | Rekening Kebun | Petty cash replenished from estate account |
| 9 | Kas Kecil | Rekening Utama | Petty cash replenished from main account |

**Expected Behavior:** All 9 combinations should be accepted and recorded without validation errors.

---

## TEST CASE 1: Multiple Realizations on Same Item

**Scenario:** Record 3 separate realization entries for same item (Upah Potong Buah TBS, Amount = Rp 1.000.000)

| # | Date | Amount | Payment | Funding | Proof # | Expected | Cumulative |
|---|---|---|---|---|---|---|---|
| 1A | 2026-10-05 | Rp 300.000 | Tunai | Kas Kebun | REF-001 | ✓ Accepted | Rp 300.000 |
| 1B | 2026-10-10 | Rp 400.000 | Transfer | Rekening Kebun | REF-002 | ✓ Accepted | Rp 700.000 |
| 1C | 2026-10-15 | Rp 300.000 | Kas Kecil | Rekening Utama | REF-003 | ✓ Accepted | Rp 1.000.000 |

**Validations:**
- ✓ Each entry records separately
- ✓ Total realization per item shown = Rp 1.000.000 (equals allocated amount)
- ✓ No errors on individual entries
- ✓ All proof numbers unique and recorded

---

## TEST CASE 2: Realization > Item Amount (with Cumulative Limit Check)

**Scenario:** Try to record realization exceeding single item amount, but respecting cumulative transfer limit.

**Setup:**
- Item: Upah Babat Piringan
- Allocated Amount: Rp 1.500.000
- Transferred Amount: Rp 1.500.000 (available for realization)
- Previous Realizations: Rp 0

**Test Entries:**

| # | Date | Amount | Payment | Funding | Proof # | Expected Outcome |
|---|---|---|---|---|---|---|
| 2A | 2026-10-08 | Rp 900.000 | Transfer | Rekening Kebun | REF-DT-001 | ✓ Accepted (< item amount) |
| 2B | 2026-10-12 | **Rp 800.000** | Transfer | Rekening Kebun | REF-DT-002 | ? Behavior Test |
| 2C | 2026-10-16 | **Rp 200.000** | Tunai | Kas Kebun | REF-DT-003 | ? Behavior Test |

**Validation Rules to Check:**

| Rule | Value |
|---|---|
| Single Item Cap | Entry 2B (Rp 900k + Rp 800k = Rp 1.7M) exceeds item amount (Rp 1.5M) |
| Cumulative Cap | Entry 2B + 2A + 2C = Rp 1.9M exceeds cumulative transfer (Rp 1.5M) |
| Expected: | ❌ REF-DT-002 should be REJECTED (exceeds cumulative transfer) |
| Alternative: | ✓ REF-DT-002 ACCEPTED, then REF-DT-003 for remaining Rp 600k |

**Document Actual Behavior:**
- Does system allow entry 2B? YES / NO
- If rejected, what error message?
- If accepted, does 2C still get accepted? YES / NO
- What is final cumulative realization after all 3 entries?

---

## TEST CASE 3: Cumulative Across Multiple Items

**Scenario:** Distribute realization across different items, respecting total transfer limit.

**Items & Transfers:**
- Item A (Upah Potong): Amount = Rp 1.0M, Transferred = Rp 1.0M
- Item B (Herbisida): Amount = Rp 2.0M, Transferred = Rp 2.0M
- Item C (Upah Babat): Amount = Rp 1.5M, Transferred = Rp 1.5M
- **Total Transfer Available:** Rp 4.5M

**Test Sequence:**

| Entry | Item | Amount | Proof # | Item Cumulative | Global Cumulative | Expected |
|---|---|---|---|---|---|---|
| 3A | A | Rp 500.000 | REF-A-01 | Rp 500k / Rp 1M | Rp 500k / Rp 4.5M | ✓ OK |
| 3B | B | Rp 1.500.000 | REF-B-01 | Rp 1.5M / Rp 2M | Rp 2M / Rp 4.5M | ✓ OK |
| 3C | A | Rp 600.000 | REF-A-02 | Rp 1.1M / Rp 1M | Rp 2.6M / Rp 4.5M | ? Test |
| 3D | C | Rp 1.900.000 | REF-C-01 | Rp 1.9M / Rp 1.5M | Rp 4.5M / Rp 4.5M | ? Test |

**Validations:**
- Entry 3C: Does system allow item A realization (Rp 1.1M) > item amount (Rp 1M)? Document YES/NO + error
- Entry 3D: Item C = Rp 1.9M > Rp 1.5M item amount. Does system:
  - ❌ REJECT? Then entry 3D is invalid, global cumulative stays Rp 2.6M
  - ✓ ACCEPT? Then global cumulative = Rp 4.5M (exactly matching total transfer)
- Final State: Document actual global cumulative and per-item cumulative

---

## TEST CASE 4: Form Validation & Edge Cases

### 4.1 Required Fields
| Field | Status | Test |
|---|---|---|
| PDO | Required | Try submit without selecting PDO → expect validation error |
| Item | Required | Try submit without selecting item → expect validation error |
| Transaction Date | Required | Try submit with empty date → expect validation error |
| Amount | Required, > 0 | Try with 0 or negative → expect validation error |
| Payment Method | Required | Try without selecting → expect validation error |
| Funding Source | Required | Try without selecting → expect validation error |
| Proof Number | Required | Try without proof # → expect validation error |
| Explanation | Optional | Leave blank → should be accepted |

### 4.2 Data Type & Format Validation
| Test | Input | Expected |
|---|---|---|
| Amount with decimals | Rp 1.234.567,50 | System behavior? Accept/reject/convert |
| Future date | 2026-11-01 (next month) | Accept/reject future transaction? |
| Past date | 2026-09-01 (last month) | Accept/reject past transaction? |
| Proof # format | "REF-" + random numbers | Accept any format? |
| Duplicate proof # | Submit two entries with same proof # | Accept/reject duplicate? |

### 4.3 Business Logic Validation
| Test | Scenario | Expected |
|---|---|---|
| Realization > Transfer | Item has Rp 1M transfer, try Rp 1.2M realization | Accept/reject? Document behavior |
| Zero Transfer | Item with Rp 0 transfer, try any realization | Reject? Require transfer first? |
| Draft PDO | Try to add realization to Draft PDO | Reject (only Final PDOs allowed)? |
| Closed PDO | Try to add realization to Closed PDO | Reject? |

---

## TEST CASE 5: UI & User Workflow

### 5.1 Item Dropdown Population
- Select PDO → check item dropdown shows all items from that PDO
- Switch PDO → dropdown refreshes correctly
- Empty PDO selection → item dropdown shows "Pilih PDO dulu"

### 5.2 Form State & Reset
- Fill form completely
- Click "Batal" → form should reset to defaults
- Submit → form resets and modal closes
- Check table updates with new entry

### 5.3 Proof Upload Workflow
- After save → modal shows attachment upload section (if implemented)
- Upload file → document accepted formats (PDF, JPG, etc.)
- View uploaded proof → check link/preview works

---

## EXPECTED OUTPUTS TO DOCUMENT

After running tests, document:

| Category | Finding |
|---|---|
| **Payment × Funding Combinations** | Which combinations work? Any blocked? Any validation issues? |
| **Multiple Realizations** | Can same item be realized multiple times? Any limit? |
| **Single Item > Allocated Amount** | System behavior when entry amount > item amount? |
| **Cumulative Limit** | Does system enforce global transfer ≤ cumulative realization? How? |
| **Validation Errors** | What error messages shown for edge cases? Clear/unclear? |
| **Data Integrity** | After multiple entries, verify totals calculated correctly |
| **UI/UX Issues** | Any confusing workflows? Missing validations? |
| **Performance** | Time to submit? Load time when selecting large PDO? |

---

## TESTING CHECKLIST

- [ ] All 9 payment × funding combinations tested
- [ ] Multiple realizations on same item (Case 1) completed
- [ ] Realization > item amount edge case (Case 2) documented
- [ ] Cumulative realization limit (Case 3) documented
- [ ] Form validation rules (Case 4) verified
- [ ] UI workflow (Case 5) tested
- [ ] Proof numbers unique and recorded correctly
- [ ] Table updates after each entry
- [ ] No duplicate entries created
- [ ] Error messages clear and actionable
- [ ] All findings documented with screenshots (if applicable)

---

## NOTES FOR HAIKU 4.5

**Instructions for Running Test:**
1. Use the test data from SETUP DATA section
2. For each test case, follow the sequence exactly
3. For "? Test" entries, try submitting and document what happens
4. For "? Behavior Test", document ACTUAL behavior vs EXPECTED
5. If validation rejects entry, copy full error message
6. If entry accepted but seems wrong, note for escalation
7. Always check table updates correctly after entries
8. Verify cumulative totals match the numbers in test table
