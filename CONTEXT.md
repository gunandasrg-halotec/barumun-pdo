# PDO Context

This context covers operational fund requests for estate units. Indonesian words are UI labels unless a term is explicitly accepted as canonical.

## Language

**PDO**:
Monthly operational fund request for one **Plantation Unit** period.
_Avoid_: Dana Operasional as canonical term, payroll request, cash transfer

**Plantation Unit**:
Estate unit that owns one PDO period and maps to external estate systems when needed. UI label: "Kebun".
_Avoid_: Estate as PDO canonical term, payroll scope

**Payroll Estate Mapping**:
External payroll estate identifier stored on a Plantation Unit for payroll cost pulls.
_Avoid_: name matching, code matching, shared estate row

**Expense Item**:
Master cost bucket selectable in PDO detail rows.
_Avoid_: Payroll Cost Component, item gaji, external component

**Auto External Expense Item**:
Expense Item whose amount must be pulled from another system before PDO submission.
_Avoid_: Manual item, live payroll link

**External Cost Pull**:
User-triggered action that captures an external amount into one draft PDO detail.
_Avoid_: Payroll sync, live binding, automatic recalculation

**Successful Empty Pull**:
**External Cost Pull** that returns external status `empty` and still overwrites PDO detail snapshot with zero amount and zero quantity.
_Avoid_: failed pull, skipped refresh, keep old value

**Stale External Snapshot**:
Previously pulled PDO detail snapshot whose **Master-Driven Cost Mapping** no longer matches current **Expense Item** mapping, so PDO must be pulled again before submit.
_Avoid_: valid last pull, reusable old snapshot

**Failed Refresh on Stale Snapshot**:
Failed re-pull keeps last external snapshot visible but still stale, so PDO remains blocked from submit until refresh succeeds.
_Avoid_: auto-clear old snapshot, silent refresh success, submit with failed refresh

**External Mapping Fingerprint**:
Copy of pull-time mapping fields stored in PDO detail external payload to detect whether current **Master-Driven Cost Mapping** has changed since last pull.
_Avoid_: live master mapping, inferred stale check, payload debug only

**External Snapshot Freshness Check**:
Comparison between **External Mapping Fingerprint** and current **Master-Driven Cost Mapping** that runs during detail load for warning and during PDO submit for hard blocking.
_Avoid_: submit-only check, warning-only check, manual guess

**Draft-Only External Freshness Enforcement**:
Hard enforcement of **External Snapshot Freshness Check** applies only while PDO is `draft`; non-draft PDO keeps existing snapshot as historical record.
_Avoid_: retroactive re-pull, mutable approved PDO, final-state invalidation

**Read-Only External Snapshot**:
After successful **External Cost Pull**, PDO detail `amount`, `quantity`, and `unit` stay read-only while row remains auto external.
_Avoid_: manual override after pull, mixed-source snapshot, silent audit break

**Pull-Owned External Fields**:
For auto external draft rows, PDO detail `amount`, `quantity`, and `unit` are owned by **External Cost Pull** from first load, not by manual entry.
_Avoid_: temporary manual fill before pull, shared write ownership

**Master Mode Reversion**:
When current **Expense Item** changes from auto external to manual, existing draft PDO rows using that item stop being eligible for **External Cost Pull** and become manually editable again.
_Avoid_: forced external mode after master removal, stale external lock

**Manual Starting Value After Reversion**:
Last external snapshot values stay on draft row as starting manual values after **Master Mode Reversion**.
_Avoid_: forced reset on reversion, loss of last pulled context

**Master Mode Promotion**:
When current **Expense Item** changes from manual to auto external, existing draft PDO rows using that item become auto external and must complete **External Cost Pull** before PDO submit.
_Avoid_: manual grandfathering, mixed source behavior across draft rows

**Pending External Replacement**:
Manual values already present on draft row may stay visible after **Master Mode Promotion**, but row remains blocked until first successful **External Cost Pull** replaces them.
_Avoid_: immediate value reset on promotion, treating old manual values as synced external snapshot

**Cost Mapping**:
Configuration connecting one **Expense Item** to one external cost component.
_Avoid_: hardcoded item name match, payroll ownership

**Master-Driven Cost Mapping**:
**Cost Mapping** that is always read from current **Expense Item** master when user runs **External Cost Pull**.
_Avoid_: mapping snapshot on PDO detail, frozen mapping

**Base Payroll Cost Mapping**:
**Cost Mapping** that targets payroll `base_payroll_total` and may optionally include one **Payroll Role Filter**.
_Avoid_: generic gaji mapping, total payroll mapping

**Payroll Role Filter**:
Optional payroll role selector used only by **Base Payroll Cost Mapping** to limit pulled payroll cost to one payroll role. Empty value means all employees in scope.
_Avoid_: mandatory role, PDO approver role, user role

**Payroll Role Filter Option**:
Admin-selected dropdown value for **Payroll Role Filter** limited to payroll-supported roles `pemanen`, `bhl`, `supir`, and `pegawai`.
_Avoid_: free-text role, derived user role, dynamic remote option

**PDO Detail Snapshot**:
Captured cost values inside a PDO, independent from later external-system changes after each successful pull. Cost mapping itself remains **Master-Driven Cost Mapping** until next pull.
_Avoid_: mapping snapshot, live external row, recalculated detail

**External Quantity Snapshot**:
Pulled external volume stored in PDO detail `quantity`.
_Avoid_: separate payroll-only volume field, live aggregate

**External Unit Snapshot**:
Pulled external unit stored in PDO detail `unit`.
_Avoid_: display-only payload unit, live external unit
