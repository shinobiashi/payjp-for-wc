# PAY.JP v2 API Overview

Source: https://docs.pay.jp/v2/guide
Last updated: 2026-04-24

## Core Objects

| Object | Description | Key fields |
|--------|-------------|------------|
| **Payment Flow** | Manages complete payment lifecycle | `id`, `status`, `amount`, `currency`, `client_secret` |
| **Checkout Session** | Hosted payment page session | `id`, `status`, `url`, `success_url`, `cancel_url` |
| **Payment Method** | Stored payment instrument (card) | `id`, `type`, `card` |
| **Customer** | Merchant's customer record | `id`, `default_payment_method_id` |
| **Setup Flow** | Registers payment method without charging | `id`, `status`, `client_secret` |
| **Refund** | Refund against a Payment Flow | `id`, `amount`, `payment_flow`, `reason` |

## API Base URL

```
https://api.pay.jp/v2/
```

## Authentication

All server-side requests use HTTP Basic Auth or Bearer token:
```
Authorization: Bearer sk_live_xxxxxxxxxxxx
```

Public key (`pk_live_xxx`) is used only in payments.js (frontend).

## Payment Flow Status Lifecycle

```
requires_payment_method
        ↓
requires_confirmation
        ↓
requires_action (3DS etc.)
        ↓
    processing
        ↓
requires_capture (if capture_method=manual)
        ↓
    succeeded
    
(any state → canceled)
```

| Status | Description |
|--------|-------------|
| `requires_payment_method` | Awaiting payment method setup |
| `requires_confirmation` | Awaiting confirm call |
| `requires_action` | Customer action needed (3DS redirect) |
| `processing` | Processing in progress |
| `requires_capture` | Auth complete, awaiting manual capture |
| `succeeded` | Payment complete |
| `canceled` | Canceled (terminal state) |

## Key API Endpoints

### Payment Flow
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/v2/payment_flows` | Create Payment Flow |
| GET | `/v2/payment_flows/{id}` | Retrieve Payment Flow |
| POST | `/v2/payment_flows/{id}/capture` | Capture (manual mode) |
| POST | `/v2/payment_flows/{id}/cancel` | Cancel |

### Checkout Session
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/v2/checkout_sessions` | Create session |
| GET | `/v2/checkout_sessions/{id}` | Retrieve session |

### Refund
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/v2/refunds` | Create refund |
| GET | `/v2/refunds/{id}` | Retrieve refund |

### Customer & Payment Method
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/v2/customers` | Create customer |
| GET | `/v2/customers/{id}` | Retrieve customer |
| POST | `/v2/payment_methods/{id}/attach` | Attach PM to customer |
| POST | `/v2/setup_flows` | Create Setup Flow |

## Create Payment Flow — Request Body

```json
{
  "amount": 1000,
  "currency": "jpy",
  "payment_method_types": ["card", "paypay"],
  "capture_method": "automatic",
  "customer_id": "cus_xxx",
  "confirm": false,
  "metadata": { "order_id": "12345" }
}
```

`capture_method`:
- `"automatic"` (default) — capture on confirmation
- `"manual"` — requires separate `/capture` call; PayPay requires prior approval

## Checkout Session — Request Body

```json
{
  "mode": "payment",
  "line_items": [
    { "price": "price_xxx", "quantity": 1 }
  ],
  "payment_method_types": ["card", "paypay"],
  "success_url": "https://example.com/success",
  "cancel_url": "https://example.com/cancel",
  "customer_id": "cus_xxx",
  "metadata": { "order_id": "12345" }
}
```

`mode` values:
- `"payment"` — one-time payment
- `"setup"` — payment method registration only

## Supported Payment Methods

| Method | Reusable | 3DS | Min | Max |
|--------|----------|-----|-----|-----|
| `card` | Yes | Required on first use | ¥50 | ¥9,999,999 |
| `paypay` | No | N/A | ¥50 | User limit |
| `apple_pay` | No | N/A | ¥50 | ¥9,999,999 |

## Error Response Format (RFC 9457)

```json
{
  "status": 400,
  "code": "invalid_status",
  "detail": "Human-readable message",
  "errors": [
    { "field": "amount", "message": "..." }
  ]
}
```

## Webhook Events

| Event | Trigger |
|-------|---------|
| `payment_flow.succeeded` | Payment completed |
| `payment_flow.payment_failed` | Payment failed |
| `refund.created` | Refund created |
| `checkout.session.completed` | Checkout session completed |

Webhook auth: `X-Payjp-Webhook-Token` header — compare with registered secret.
Retry: 3 attempts at 3-minute intervals on non-2xx response.

## Idempotency

Add `Idempotency-Key` header to prevent duplicate charges on network failure:
```
Idempotency-Key: order-12345-attempt-1
```

## Card Constraints

- Refunds: within 180 days of payment creation
- Authorization hold: 7 days (extendable to 30 days via extend API)
- 3DS: mandatory on first card use in v2

## PayPay Constraints

- One-time payments only (cannot bind to customer)
- `capture_method: 'manual'` requires prior PAY.JP approval
- Test limit: ¥100 per transaction; must fully refund after each test
- Test accounts: 080-1111-5912 to 080-1111-5921
- Refund window: 365 days from confirmation
- Fees: 3.5% (physical) / 9.0% (digital)
