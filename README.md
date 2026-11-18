# Orboni for Paymenter

Take crypto for your Paymenter invoices: USDT, USDC, BTC, ETH, SOL, TRX and more, on the networks your buyers use. The money goes straight to the wallets you set in the Orboni dashboard; Orboni never holds it. An invoice is marked paid by itself once the payment is final on the chain.

- The invoice is priced in its own currency (dollars, euros, pounds, yen and more): the buyer sees that total and pays its worth in the coin they pick.
- Pressing Pay again while the payment page is open brings the buyer back to the same page, never a second one.
- A payment that is still confirming, partly paid or held for your review keeps the invoice waiting. It is marked paid only once Orboni says it is paid in full.
- Several Paymenter sites can share one Orboni project: each acts only on its own invoices.

Full guide: https://orboni.com/docs/paymenter

## Requirements

- Paymenter 1.4 or later (tested on 1.5.9)
- An Orboni account: https://orboni.com

## Install

1. Copy this `Orboni` folder to `extensions/Gateways/Orboni` in your Paymenter installation. The folder must be named `Orboni`.
2. In Paymenter's admin, go to Extensions → Gateways, add a gateway and choose **Orboni**.
3. In the Orboni dashboard, set the wallets you want to be paid to under Wallets. Then copy a secret key under Developers → API keys. Start with a test key (`sk_test_…`).
4. Paste the key into the gateway's **Secret key**.
5. In the Orboni dashboard, under Developers → Webhooks, add an endpoint at `https://your-paymenter-site.com/extensions/gateways/orboni/webhook`. Choose every event, or at least the `checkout.session.*` ones. Paste the endpoint's signing secret (`whsec_…`) into the gateway's **Webhook signing secret** and save.
6. Pay an invoice with Orboni. On its payment page, while signed in to the Orboni dashboard, press **Simulate payment**: the invoice turns paid. Then switch to your live key and the live endpoint's secret.

## How an invoice moves

| Orboni says | The invoice |
| --- | --- |
| complete, paid | Paid |
| processing (on the chain, not final yet) | Waits: a processing transaction |
| partially_paid | Waits: the payment page asks the buyer for the rest |
| held (for your review in the dashboard) | Waits. Accept the payment in the dashboard and the invoice is paid |
| expired, nothing paid | The processing transaction, if any, fails. The invoice stays unpaid |
| payment_reversed (money that paid it left the chain) | Its transaction goes back to processing |

Before it changes an invoice, the extension reads the payment back from Orboni. The webhook and the buyer's return can come in any order, or twice, and the result is the same. The session's id is the transaction's id in Paymenter. Every change is written to Paymenter's log, prefixed "Orboni:".

## Good to know

- The gateway is offered only in a currency Orboni takes now, and for at least $1.
- Refunds: the money is in your wallet, so send it back from there and record the refund in the Orboni dashboard.
- Keys and signing secrets are stored encrypted by Paymenter and never logged.

## Changelog

- **1.0.1**: Pay pressed again sends the buyer to a new payment page when the last one has run out or has under five minutes left, instead of to the old one.
- **1.0.0**: First release.

## Support

The support chat in the Orboni dashboard.

## License

MIT, see LICENSE.
