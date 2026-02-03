# Flutterwave for FluentCart – Code Review

**Reviewer:** Senior software engineer (automated review)  
**Scope:** Critical issues, improvements, suggestions for the Flutterwave payment addon.

---

## Summary

The addon is well-structured and follows FluentCart’s addon gateway pattern (Settings, Helper, API, Gateway, Processor, Confirmations, Webhook, Refund, Subscriptions). Several **critical** bugs were fixed in this pass; the rest are improvements and suggestions.

---

## Critical issues (fixed in this pass)

### 1. Webhook: null `$transactionModel` causing fatal error

**File:** `includes/Webhook/FlutterwaveWebhook.php` – `handleChargeCompleted()`

When the webhook had no valid `tx_ref` or the transaction was not found, `$transactionModel` stayed `null`. The code then did `$transactionModel->status` and `confirmPaymentSuccessByCharge($transactionModel, ...)`, causing a fatal error.

**Fix:** Guard with `if (!$transactionModel) { $this->sendResponse(200, '...'); }` before using `$transactionModel`.

---

### 2. Webhook: `php://input` read twice (signature verification)

**File:** `includes/Webhook/FlutterwaveWebhook.php` – `verifySignature()` → `verifySignatureWithSignature()`

When Flutterwave sends `HTTP_FLUTTERWAVE_SIGNATURE` (HMAC), the code called `$this->getWebhookPayload()` again inside `verifySignature()`. In PHP, `php://input` can only be read once in many environments, so the second read could be empty and signature verification could fail or behave incorrectly.

**Fix:** Pass the already-read raw payload from `verifyAndProcess()` into `verifySignature($payload)` and use it for HMAC verification instead of reading the body again.

---

### 3. Helper: null on `getFirstTransactionByVendorChargeId()`

**File:** `includes/FlutterwaveHelper.php`

`OrderTransaction::query()->...->first()` can return `null`. The code did `return $transaction->vendor_charge_id ?? ''`, which triggers on `null` when no transaction exists.

**Fix:** `return $transaction ? ($transaction->vendor_charge_id ?? '') : '';`

---

### 4. Settings: overwriting the other mode’s secret when saving

**File:** `includes/FlutterwaveGateway.php` – `beforeSettingsUpdate()`

Only the current tab’s secret was encrypted; the form often sends the other tab’s field as empty. That could overwrite the stored live (or test) secret with an empty value when saving the other tab.

**Fix:** When the submitted value for the current mode’s secret is empty, keep the existing value from `$oldSettings` instead of overwriting.

---

### 5. Production: `console.log` in checkout JS

**File:** `assets/flutterwave-checkout.js`

`console.log('flutterwaveData', ...)` and `console.log('config', ...)` were left in the checkout flow, exposing payment/order data in the browser console.

**Fix:** Removed both `console.log` calls.

---

## Improvements (optional)

### 6. Currency and amount handling (JPY and zero-decimal currencies)

**Files:** `includes/FlutterwaveHelper.php` – `formatAmountForFlutterwave()`, `convertToLowestUnit()`

Both methods take `$currency` but ignore it. Flutterwave uses “main unit” amounts (e.g. 100.50 USD). For zero-decimal currencies (e.g. JPY, KRW), you should not multiply/divide by 100.

**Suggestion:** Use a list of zero-decimal currencies and branch:

- `formatAmountForFlutterwave`: for zero-decimal, return `(int) round($amount / 100)` (or as needed by API).
- `convertToLowestUnit`: for zero-decimal, return `(int) $amount` (no × 100).

Align with [Flutterwave’s docs](https://developer.flutterwave.com/docs) for each currency.

---

### 7. Webhook: no signature when secret not configured

**File:** `includes/Webhook/FlutterwaveWebhook.php` – `verifySignature()`, `verifySignatureWithSignature()`

When no webhook secret hash is stored, the code logs a warning and **allows** the webhook (`return true`). That makes the endpoint accept unverified requests.

**Suggestion:** In production, **reject** when the secret is not configured (e.g. `return false` and send 401), and only allow in a defined “development” or “test” mode if you need to. Keep the log in all cases.

---

### 8. Promo / “Install” entry in FluentCart core

When the Flutterwave addon is **not** installed, FluentCart does not show an “Install Flutterwave” option in payment methods, because `AddonGatewaysHandler` only registers Paystack, Razorpay, and Mercado Pago by default.

**Options:**

- **A)** Add a `FlutterwaveAddon` promo class in `fluent-cart` (e.g. under `PromoGateways/Addons/`) and register it in `AddonGatewaysHandler::$defaultGateways` (core change).
- **B)** Document that site owners can register Flutterwave via the `fluent_cart/addon_gateways` filter from a must-use plugin or theme.

---

### 9. API error handling and idempotency

**File:** `includes/API/FlutterwaveAPI.php`

- Consider logging `WP_Error` and response details (without sensitive data) for failed requests to help support and debugging.
- For refunds and other mutating calls, check Flutterwave’s idempotency recommendations and add idempotency keys if supported.

---

### 10. Refund: partial vs full and currency

**File:** `includes/Refund/FlutterwaveRefund.php` – `processRemoteRefund()`

- Ensure Flutterwave’s refund API is called with the correct amount and currency (main unit vs minor unit) and that partial refunds are supported and tested.
- Confirm that `createOrUpdateIpnRefund` is only used with webhook payloads that you trust after signature verification.

---

## Suggestions (non-blocking)

- **i18n:** All user-facing strings use the `flutterwave-for-fluent-cart` text domain; keep this consistent and run a string extraction (e.g. `wp i18n make-pot`) for translations.
- **Security:** Confirm that `getOrderInfo` and confirmation AJAX only return data that is safe for the current user/context and that nonce and capability checks match FluentCart’s expectations.
- **Tests:** Add at least one PHPUnit test for webhook signature verification and for `getFirstTransactionByVendorChargeId` (null and found cases) to avoid regressions.
- **Docs:** In README or dev-docs, document the webhook URL, required Flutterwave events, and that the webhook secret hash must be set for production.

---

## Checklist (verification)

- [x] Gateway appears in FluentCart payment settings when addon is active
- [x] Webhook URL is correct and documented in UI
- [x] Webhook signature verification uses single read of request body (fixed)
- [x] Null-safe handling for transaction/subscription lookups (fixed)
- [x] Settings encryption does not overwrite the other mode’s secret (fixed)
- [ ] Promo “Install” entry in core (optional; see §8)
- [ ] Zero-decimal currency handling (optional; see §6)
- [ ] Reject webhooks when secret not set in production (optional; see §7)

---

*Review applied: critical fixes in Webhook, Helper, Gateway, and JS. Optional items can be done in a follow-up.*
