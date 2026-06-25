# CODE REVIEW: KERANI UNIT-SCOPED ACCESS CONTROL

**Review Date:** 2026-06-23  
**Reviewer:** Claude Code  
**Status:** ⚠️ **PARTIAL IMPLEMENTATION** - Security gap identified

---

## EXECUTIVE SUMMARY

The unit-scoped access control for KERANI role is **partially implemented** with good infrastructure but **missing explicit validation** in the realization recording service.

**Key Findings:**
- ✅ Middleware correctly binds unit_id
- ✅ PdoHeader has global scope filter
- ❌ PdoDetail missing global scope
- ❌ RealizationEntryService not validating unit ownership
- ⚠️ Frontend filtering sufficient for UI but backend has security gap

---

## ARCHITECTURE OVERVIEW

```
Request → EnsureUnitAccess Middleware
         ↓
         Bind current_unit_id to container
         ↓
PdoHeader Global Scope applies filter
         ↓
RealizationEntryService processes request
         ↓
Database (filtered by unit at query level)
```

---

## DETAILED FINDINGS

### 1️⃣ MIDDLEWARE: EnsureUnitAccess ✅ CORRECT

**File:** `apps/api/app/Http/Middleware/EnsureUnitAccess.php`

**Current Implementation:**
```php
// Lines 31-32: Cross-unit roles bypass filter
if (in_array($user->role?->code, Role::CROSS_UNIT_ROLES)) {
    return $next($request);
}

// Lines 36-37: KERANI/ASISTEN get unit-scoped filtering
if ($user->plantation_unit_id) {
    app()->instance('current_unit_id', $user->plantation_unit_id);
}
```

**Assessment:** ✅ **CORRECT**
- Properly identifies unit-scoped roles (KERANI, ASISTEN_KEBUN)
- Binds current_unit_id to Laravel service container
- Cross-unit roles (ADMIN, MANAJER_KEUANGAN) bypass filter correctly
- Applied to all protected routes (line 36: v1 middleware)

---

### 2️⃣ PDOHEADER MODEL: Global Scope ✅ CORRECT

**File:** `apps/api/app/Models/PdoHeader.php` (lines 75-86)

**Current Implementation:**
```php
protected static function booted(): void
{
    static::addGlobalScope('unit_access', function (Builder $builder) {
        if (app()->bound('current_unit_id')) {
            $builder->where('plantation_unit_id', app('current_unit_id'));
        }
    });
}
```

**Assessment:** ✅ **CORRECT**
- Global scope automatically filters PdoHeader queries by unit
- Checks if current_unit_id is bound before applying filter
- Applies to ALL PdoHeader queries (find, get, list, etc.)
- Frontend benefit: `GET /pdo` returns only user's unit PDOs

---

### 3️⃣ PDODETAIL MODEL: No Global Scope ❌ MISSING

**File:** `apps/api/app/Models/PdoDetail.php`

**Current Implementation:**
```php
// No global scope defined
// No unit-based filtering mechanism
```

**Assessment:** ❌ **SECURITY GAP**

**Risk:** If someone knows a pdo_detail_id from another unit, they could potentially:
```bash
POST /api/v1/realization-entries
{
  "pdo_detail_id": "from-other-unit-pdodetail",
  "amount": 500000,
  ...
}
```

The RealizationEntryService does:
```php
$detail = PdoDetail::findOrFail($data['pdo_detail_id']);
```

**Problem:** This will load the PdoDetail WITHOUT checking if it belongs to user's unit.

**Recommendation:** Add global scope to PdoDetail:
```php
protected static function booted(): void
{
    static::addGlobalScope('unit_access', function (Builder $builder) {
        if (app()->bound('current_unit_id')) {
            $builder->whereHas('pdoHeader', fn ($q) => 
                $q->where('plantation_unit_id', app('current_unit_id'))
            );
        }
    });
}
```

---

### 4️⃣ REALIZATION ENTRY SERVICE: No Explicit Validation ❌ MISSING

**File:** `apps/api/app/Services/Realization/RealizationEntryService.php` (lines 64-100)

**Current Implementation:**
```php
public function store(array $data, User $actor): RealizationEntry
{
    $detail = PdoDetail::findOrFail($data['pdo_detail_id']);
    $pdo    = $detail->pdoHeader;

    // Checks PDO is final, validates amounts, etc.
    // But NO validation that PDO belongs to user's unit
    ...
}
```

**Assessment:** ❌ **EXPLICIT VALIDATION MISSING**

**What's validated:**
- ✅ PDO is in FINAL status (line 70)
- ✅ STAFF_PURCHASING funding source restrictions (line 78)
- ✅ Realization doesn't exceed transfer amount (line 91)

**What's NOT validated:**
- ❌ PDO belongs to user's unit
- ❌ PdoDetail belongs to user's unit

**Current Reliance:** Depends entirely on:
1. Frontend only sending valid PDO IDs (UI filtering)
2. PdoDetail global scope (MISSING)

**Risk Level:** ⚠️ **MEDIUM** - Mitigated by frontend filtering but not defense-in-depth

**Recommendation:** Add explicit validation:
```php
// Verify PDO belongs to user's unit
if ($pdo->plantation_unit_id !== $actor->plantation_unit_id) {
    abort(response()->json([
        'success' => false,
        'error' => [
            'code' => 'UNIT_MISMATCH',
            'message' => 'PDO does not belong to your unit'
        ]
    ], 403));
}
```

---

### 5️⃣ ROUTES: Middleware Applied ✅ CORRECT

**File:** `apps/api/routes/api.php` (line 36)

**Current Implementation:**
```php
Route::prefix('v1')->middleware(['auth:sanctum', 'ensure.unit.access'])->group(function () {
    // All routes in this group have both auth and unit access
    Route::post('realization-entries', [RealizationEntryController::class, 'store']);
    ...
}
```

**Assessment:** ✅ **CORRECT**
- All protected routes have 'ensure.unit.access' middleware
- Realization endpoints properly guarded

---

### 6️⃣ FRONTEND: PDO Filtering ✅ CORRECT

**File:** `apps/web/src/pages/RealizationPage.tsx` (lines 52-58)

**Current Implementation:**
```tsx
const { data: pdoList } = useQuery({
    queryKey: ['pdo-active'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoHeader[]>>('/pdo', { 
        params: { status: 'final' } 
      })
      return res.data.data
    },
})
```

**Assessment:** ✅ **CORRECT**
- Fetches `/pdo` endpoint which has ensure.unit.access middleware
- Backend returns only PDOs for user's unit
- Dropdown only shows user's own unit PDOs

**Limitation:**
- Frontend filtering is user-friendly but not security-critical
- Backend should not rely on this for authorization

---

## SECURITY ASSESSMENT MATRIX

| Layer | Component | Status | Risk | Impact |
|-------|-----------|--------|------|--------|
| **Middleware** | EnsureUnitAccess | ✅ OK | None | Properly binds unit_id |
| **Model Layer** | PdoHeader Scope | ✅ OK | None | Auto-filters queries |
| **Model Layer** | PdoDetail Scope | ❌ MISSING | Medium | No auto-filter on detail |
| **Service Layer** | Store Validation | ❌ MISSING | Medium | No explicit check |
| **API Layer** | Routes | ✅ OK | None | Middleware applied |
| **UI Layer** | Dropdown Filter | ✅ OK | Low | Frontend only |

---

## VULNERABILITY SCENARIOS

### Scenario 1: Direct API Call with Other Unit PdoDetail ⚠️ POSSIBLE

**Attack Vector:**
```bash
curl -X POST https://pdo.barumun-plantation.com/api/v1/realization-entries \
  -H "Authorization: Bearer [KERANI_BN_TOKEN]" \
  -d '{
    "pdo_detail_id": "[DETAIL_FROM_OR_UNIT]",
    "amount": 500000,
    ...
  }'
```

**Current Behavior:**
- ❌ Might succeed (no explicit validation)
- Depends on whether PdoDetail has global scope

**Expected Behavior:**
- ✅ Should fail with 403 Forbidden
- Error: "PDO does not belong to your unit"

---

### Scenario 2: Modification of Request Data ⚠️ POSSIBLE

**Attack Vector:**
```javascript
// User opens DevTools, intercepts request, modifies pdo_detail_id
const data = {
  pdo_detail_id: "[OTHER_UNIT_ID]", // Changed from their own unit
  amount: 500000
}
```

**Current Behavior:**
- ❌ Might succeed (frontend can't prevent clever users)
- Backend validation is required

**Expected Behavior:**
- ✅ Backend should reject with explicit error

---

## RECOMMENDATIONS

### PRIORITY 1: Add PdoDetail Global Scope (CRITICAL)

**File:** `apps/api/app/Models/PdoDetail.php`

**Add after line 43:**
```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

protected static function booted(): void
{
    static::addGlobalScope('unit_access', function (Builder $builder) {
        if (app()->bound('current_unit_id')) {
            $builder->whereHas('pdoHeader', fn ($q) => 
                $q->where('plantation_unit_id', app('current_unit_id'))
            );
        }
    });
}
```

**Effort:** 5 minutes  
**Impact:** Prevents unauthorized access to other unit details

---

### PRIORITY 2: Add Explicit Validation in Service (CRITICAL)

**File:** `apps/api/app/Services/Realization/RealizationEntryService.php`

**Add after line 67 (after $detail and $pdo assignment):**
```php
// Verify PDO and detail belong to user's unit
if ($pdo->plantation_unit_id !== $actor->plantation_unit_id) {
    abort(response()->json([
        'success' => false,
        'error' => [
            'code' => 'UNIT_MISMATCH',
            'message' => 'Realisasi hanya bisa dicatat untuk PDO unit Anda sendiri.'
        ]
    ], 403));
}
```

**Effort:** 10 minutes  
**Impact:** Defense-in-depth validation, explicit error message

---

### PRIORITY 3: Add Test Coverage (IMPORTANT)

See: `TEST_PLAN_KERANI_UNIT_SCOPE.md`

**Key Tests:**
- Test direct API call with other unit pdo_detail_id (should fail)
- Test KERANI from different units (data isolation)
- Test that dropdown only shows own unit PDOs

---

## IMPLEMENTATION CHECKLIST

- [ ] Add PdoDetail global scope (PRIORITY 1)
- [ ] Add explicit unit validation in RealizationEntryService (PRIORITY 2)
- [ ] Do same for TransferEntryService (related flow)
- [ ] Run unit tests to verify fixes
- [ ] Run TEST_PLAN_KERANI_UNIT_SCOPE.md test cases
- [ ] Code review of changes
- [ ] Deploy to production

---

## RISK ASSESSMENT

**Current State:** ⚠️ **PARTIALLY SECURE**
- Frontend filtering works correctly
- Middleware properly set up
- PdoHeader global scope active
- But: Missing PdoDetail scope and explicit validation

**After Fixes:** ✅ **SECURE**
- Multi-layered defense (middleware + global scope + explicit validation)
- Follows principle of defense-in-depth
- Prevents unauthorized cross-unit access

---

## RELATED CODE AREAS

| File | Lines | Purpose |
|------|-------|---------|
| EnsureUnitAccess.php | 30-40 | Bind unit_id based on role |
| PdoHeader.php | 75-86 | Global scope filtering |
| PdoDetail.php | 1-82 | **NEEDS GLOBAL SCOPE** |
| RealizationEntryService.php | 64-100 | **NEEDS VALIDATION** |
| RealizationEntryController.php | 27-33 | Entry point |
| api.php | 36-100 | Route middleware |
| RealizationPage.tsx | 52-58 | Frontend filtering |

---

## SIGN-OFF

**Code Review Status:** ⚠️ **ISSUES FOUND - REQUIRES FIXES**

**Ready for Testing?** ❌ **NO** - Fix security gaps first

**Next Steps:**
1. Implement PRIORITY 1 & 2 fixes
2. Run TEST_PLAN_KERANI_UNIT_SCOPE.md
3. If all tests pass → ✅ Approved for production

