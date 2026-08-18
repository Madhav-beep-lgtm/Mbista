# M.Bista App - Feature Status Report
**Date**: 2026-08-17 | **Generated**: Comprehensive System Audit

---

## 🎯 JEWELLERY ORDER SYSTEM

### ✅ FIXED (Today)
1. **Stock Dropdown Structure** - Removed optgroup that was blocking clicks
   - Now properly clickable and responsive
   - Shows all available showroom pieces
   
2. **Inventory Levels Display** - Shows "Qty: X" for each stock item
   - Helps users see how many pieces available

3. **Test Data Created** - 3 sample showroom pieces in database
   - Bracelet: 45.5 GM
   - Ring: 8.5 GM
   - Necklace: 35.0 GM
   - Ready for testing in dropdown

### 🔴 ISSUES FOUND

#### 1. Missing Group/Category Master for Parties
- **Status**: ❌ NOT IMPLEMENTED
- **Issue**: Customer dropdown shows flat list with no grouping
- **Current**: Line 731-735 of jewellery-workshop.php
- **Fix Needed**: Add party_group or party_category field to organize parties by type
- **Impact**: Low - functional but UX could be improved

#### 2. No Validation for Weight Matching on Stock Selection
- **Status**: ⚠️ PARTIALLY IMPLEMENTED
- **Issue**: When selecting showroom stock, should verify order's weight requirements match stock weight
- **Current**: Fields auto-populate but no validation warnings
- **Fix Needed**: Add JavaScript to warn if selected stock weight doesn't match customer's requirements
- **Impact**: Medium - could cause mismatches

#### 3. Kaligadh Lock with readonly weight fields
- **Status**: ✅ WORKING
- **Current**: Lines 540-543 in jewellery_line_grid.php
- **Behavior**: When stock item selected, weights become readonly
- **Note**: This is correct - prevents accidental editing of stock measurements

---

## 💰 IFRS/IAS 2 INVENTORY ENGINE

### 📊 Current Status: SCHEMA READY, UI INCOMPLETE
- **Migrations**: ✅ 036 & 037 ready (tested)
- **Database Schema**: ✅ Complete
- **UI/Forms**: ❌ 0% - Needs build
- **Posting Logic**: ❌ 0% - Needs implementation
- **Reports**: ❌ 0% - Needs design

### 📝 TODO (Large Scope)
- [ ] Valuation selection UI (FIFO/Weighted Avg/Specific)
- [ ] Receipt valuation entry forms
- [ ] Manufacturing document linking
- [ ] Batch posting logic
- [ ] Inventory aging reports
- [ ] Valuation variance reports

**Effort**: ~2-3 weeks | **Priority**: HIGH

---

## 🏦 PAYMENT GATEWAY INTEGRATION

### 📊 Current Status: NOT STARTED
- **eSewa**: ❌ Not configured
- **Khalti**: ❌ Not configured  
- **Fonepay**: ❌ Not configured
- **Stripe**: ❌ Not configured

### 📝 TODO
- [ ] Config page (select active gateways)
- [ ] Intent/Token API endpoints
- [ ] Callback handlers
- [ ] Payment status tracking
- [ ] Invoice payment linking
- [ ] Refund processing
- [ ] Security: Webhook signature validation, rate limiting

**Files to Create**:
- `public_html/admin/payment-config.php`
- `app/payments/PaymentGateway.php` (abstract)
- `app/payments/ESewaGateway.php`
- `app/payments/KhaltiGateway.php`
- `app/payments/FonepayGateway.php`
- `app/payments/StripeGateway.php`

**Effort**: ~1 week | **Priority**: HIGH

---

## 🎨 MB WORLD PORTAL REDESIGN

### 📊 Current Status: SPEC EXISTS, NOT IMPLEMENTED
- **Spec**: 20-page design document exists in memory
- **Implementation**: 0%
- **Components**: Not created
- **Navigation**: Current uses tabbar, redesign wants sidebar

### 📝 TODO
- [ ] Study 20-page spec
- [ ] Create sidebar navigation component
- [ ] Design KPI dashboard page
- [ ] Implement drill-down analytics
- [ ] Create master data pages
- [ ] Build reports section

**Effort**: ~3-4 weeks | **Priority**: MEDIUM

---

## 🔐 ACCESS CONTROL HARDENING

### 📊 Current Status: PARTIALLY IMPLEMENTED
- **Tenant Isolation**: ✅ company_id checks exist
- **Session Hardening**: ⚠️ Needs review
- **authorized_company_ids**: ❌ Not fully enforced
- **Voucher Choke Points**: ⚠️ Needs verification

### 🔴 ISSUES FOUND
1. **authorized_company_ids Not Enforced**
   - Field exists but not used in queries
   - Users might see data from unauthorized companies
   
2. **Voucher Access Control**
   - Need to ensure voucher_id used as choke point
   - Prevent cross-tenant voucher access

3. **M.B. World Logo Helpers**
   - Logo display logic needs review
   - Ensure proper branding per company

**Effort**: ~1 week | **Priority**: CRITICAL

---

## 📊 ACCOUNTING INTEGRITY

### 📊 Current Status: IN PROGRESS
- **FK RESTRICT**: ⚠️ Partial - Some tables missing constraints
- **Atomic Posting**: ⚠️ Partial - Transaction handling exists
- **Mutation Guards**: ⚠️ Partial - Some entities protected
- **WAvg Re-cost**: ❌ Not implemented
- **Export IDOR**: ✅ Fixed (from memory notes)
- **Form Normalization**: ⚠️ Partial

### 🔴 KNOWN ISSUES (from memory)
1. Foreign key constraints not consistently applied
2. Weighted average recalculation logic missing
3. Some export endpoints lack permission checks
4. Form field name inconsistencies

**Effort**: ~2 weeks | **Priority**: HIGH

---

## 🎨 SKY REDESIGN (Blue Theme)

### 📊 Current Status: IN PROGRESS
- **Theme Variables**: ⚠️ Partial
- **Motion Layers**: ❌ Not started
- **Dark Mode Override**: ⚠️ Partial
- **Headless Screenshots**: ✅ Working

### 📝 TODO
- [ ] Complete blue color palette
- [ ] Add transition animations
- [ ] Test dark mode across all pages
- [ ] Performance optimization for animations

**Effort**: ~1-2 weeks | **Priority**: LOW

---

## 📋 PRIORITY MATRIX

| Feature | Effort | Impact | Status | Priority |
|---------|--------|--------|--------|----------|
| Payment Gateways | 1 week | Revenue-critical | 0% | 🔴 CRITICAL |
| IFRS Inventory | 2-3 wks | Compliance | Schema only | 🔴 CRITICAL |
| Access Control | 1 week | Security | Partial | 🔴 CRITICAL |
| Accounting | 2 weeks | Integrity | In Progress | 🟠 HIGH |
| Jewellery Orders | <1 day | Core feature | 95% ✅ | 🟠 HIGH |
| MB World Portal | 3-4 wks | Strategic | 0% | 🟡 MEDIUM |
| Sky Redesign | 1-2 wks | UX | 50% | 🟢 LOW |

---

## 🚀 NEXT STEPS

1. **TODAY**: Test jewellery order dropdown with test data
2. **WEEK 1**: 
   - Finalize jewellery order feature
   - Set up payment gateway config page
   - Begin IFRS posting logic
3. **WEEK 2-3**: 
   - Implement payment gateway integrations
   - Build IFRS UI and forms
   - Complete access control hardening
4. **WEEK 4+**: 
   - MB World portal redesign
   - Sky theme completion
   - Comprehensive testing

---

## ✅ TESTING CHECKLIST

- [ ] Jewellery order with showroom stock selection
- [ ] Weight field auto-population
- [ ] Kaligadh field disable/enable behavior
- [ ] Payment processing via all gateways
- [ ] IFRS valuation calculations
- [ ] Cross-tenant data isolation
- [ ] Export permission validation
- [ ] Dark mode rendering

---

*Report generated automatically. Last updated: 2026-08-17*
