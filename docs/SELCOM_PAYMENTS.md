# Selcom — Malipo ya Safari Hub

Mchakato wa kukusanya malipo kupitia **Selcom Payment Gateway**. Pesa kutoka wateja inaingia kwenye **akaunti ya merchant (SELCOM_VENDOR)** ya Safari Hub kwenye Selcom.

## Mtiririko

1. Mteja anachagua njia ya malipo (M-Pesa, Airtel, kadi, n.k.) kwenye checkout.
2. Backend inaunda order kwenye Selcom (`create-order-minimal`).
3. **Mobile money:** USSD push inatumwa kwa simu (`wallet-payment`).
4. **Kadi / hosted checkout:** mteja anaelekezwa kwenye ukurasa wa Selcom (`payment_url`).
5. Selcom inaita webhook: `POST {APP_URL}/api/payments/webhooks/selcom`.
6. Backend inathibitisha hali kupitia `order-status`, kisha inagawa komisheni (~10%) na salio kwa wallet ya mwenye safari/garage.

## Mazingira (.env)

```env
PAYMENT_PROVIDER=selcom
SELCOM_BASE_URL=https://apigw.selcommobile.com
SELCOM_VENDOR=SHOPxxxx          # Vendor ID kutoka Selcom
SELCOM_API_KEY=...
SELCOM_API_SECRET=...
```

Usiweke funguo hizi kwenye frontend au mobile — ziko backend pekee.

## Webhook

Sajili URL hii kwenye Selcom dashboard:

```
https://www.safarihub.space/api/payments/webhooks/selcom
```

(Lokal: `https://your-ngrok-url/api/payments/webhooks/selcom`)

Selcom inatumia `Digest` + `Signed-Fields` — backend inathibitisha saini kiotomatiki.

Kwa majaribio pekee (si production):

```env
SELCOM_WEBHOOK_ALLOW_UNSIGNED=true
```

## Baada ya deploy

```bash
php artisan db:seed --class=PaymentSeeder
php artisan config:cache
```

Hakikisha `payment_gateways` ina rekodi ya `selcom` na `is_default=1` wakati `PAYMENT_PROVIDER=selcom`.

## Majaribio ya lokal

1. Weka credentials za Selcom sandbox kwenye `backend/.env`.
2. `PAYMENT_PROVIDER=selcom`
3. Tumia ngrok au Cloudflare tunnel kwa webhook ikiwa unajaribu push halisi.
4. Kwa development bila Selcom, acha `PAYMENT_PROVIDER=stub`.

## Akaunti inayopokea pesa

| Hatua | Mahali pesa inapoenda |
|-------|------------------------|
| Malipo ya mteja | Akaunti ya **SELCOM_VENDOR** (Safari Hub merchant) |
| Komisheni ya jukwaa (~10%) | Inarekodiwa kwenye `payment_allocations` |
| Salio kwa mtoa huduma | Wallet ya transport/garage owner — payout kupitia admin |

## API ya Selcom inayotumika

- `POST /v1/checkout/create-order-minimal`
- `POST /v1/checkout/wallet-payment` (mobile money push)
- `GET /v1/checkout/order-status?order_id=`
- Webhook callback (`order_id`, `payment_status: COMPLETED`)

Package: `selcom/selcom-apigw-client` (Composer).
