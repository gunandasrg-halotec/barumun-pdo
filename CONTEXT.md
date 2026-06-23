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

**Cost Mapping**:
Configuration connecting one **Expense Item** to one external cost component.
_Avoid_: hardcoded item name match, payroll ownership

**PDO Detail Snapshot**:
Captured cost line inside a PDO, independent from later master or external-system changes.
_Avoid_: live external row, recalculated detail
