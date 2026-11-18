<?php

namespace Paymenter\Extensions\Gateways\Orboni;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orboni for Paymenter: an invoice is paid on Orboni's payment page, in the
 * invoice's own currency when Orboni takes it, to the wallets the merchant set
 * in the Orboni dashboard. Orboni never holds the money; it watches the chain
 * and tells Paymenter with a signed webhook, and the invoice is read back when
 * the buyer returns.
 */
#[ExtensionMeta(
    name: 'Orboni',
    description: 'Crypto payments (USDT, USDC, BTC, ETH and more) straight to your own wallets. Orboni never holds your money.',
    version: '1.0.1',
    author: 'Orboni',
    url: 'https://orboni.com/docs/paymenter',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiIgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0Ij48cGF0aCBkPSJNMjcgMTIuNjRBMTEuNSAxMS41IDAgMSAxIDE5LjM2IDUiIGZpbGw9Im5vbmUiIHN0cm9rZT0iIzFmYjVjMCIgc3Ryb2tlLXdpZHRoPSI1Ii8+PGNpcmNsZSBjeD0iMjMuNzEiIGN5PSI4LjI5IiByPSI0LjQiIGZpbGw9IiNmZmIzOGEiLz48L3N2Zz4K',
)]
class Orboni extends Gateway
{
    public const VERSION = '1.0.1';

    /** The extension's name in Paymenter's gateways table (`ExtensionHelper::addPayment`). */
    public const NAME = 'Orboni';

    /** How long the invoice's payment page is remembered, to send the buyer back to it. */
    private const SESSION_DAYS = 7;

    /**
     * Keys tried for one Pay. A cache cleared since the last Pay starts again
     * at the first key, and Orboni answers each key used within a day with its
     * old page, run out or not, until a new one.
     */
    private const MAX_TRIES = 10;

    /**
     * Time a payment page must still have to be sent to again: less, and the
     * buyer gets a new one. A page Orboni still calls open may be past its
     * time (held open while a network cannot be read).
     */
    private const REUSE_MIN_SECONDS = 300;

    public function boot()
    {
        require __DIR__.'/routes.php';
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'secret_key',
                'label' => 'Secret key',
                'type' => 'password',
                'encrypted' => true,
                'required' => true,
                'validation' => ['regex:/^sk_(live|test)_[A-Za-z0-9]+$/'],
                'description' => 'In the Orboni dashboard: Developers → API keys. A test key (sk_test_…) makes test payments only; try the whole flow with one first.',
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook signing secret',
                'type' => 'password',
                'encrypted' => true,
                'required' => true,
                'validation' => ['regex:/^whsec_[A-Za-z0-9]+$/'],
                'description' => 'In the Orboni dashboard: Developers → Webhooks, add an endpoint at '.self::webhookUrl().' and paste its signing secret (whsec_…).',
            ],
        ];
    }

    public static function webhookUrl(): string
    {
        return url('/extensions/gateways/orboni/webhook');
    }

    /**
     * Offered only in a currency Orboni prices in now, and for at least $1
     * (Orboni's least payment); in any other currency the buyer simply does
     * not see it.
     */
    public function canUseGateway($total, $currency, $type, $items = [])
    {
        $secret = (string) $this->config('secret_key');
        if ($secret === '') {
            return false;
        }

        $known = Currencies::available(new Api($secret), (string) $currency);
        if ($known === null) {
            return false;
        }
        $usd = $known['usd'] ?? null;

        return $usd === null || (float) $total * $usd >= 1;
    }

    /**
     * The buyer goes to Orboni's payment page for what is left on the
     * invoice. Pressing Pay again while that page is open sends them back to
     * it, never to a second one for the same amount.
     */
    public function pay(Invoice $invoice, $total)
    {
        $api = new Api((string) $this->config('secret_key'));
        $currency = strtoupper((string) $invoice->currency_code);
        $known = Currencies::available($api, $currency);
        if ($known === null) {
            throw new DisplayException(__('Crypto payments are not taken in :currency.', ['currency' => $currency]));
        }
        $amount = Money::minor($total, $known['decimals']);

        $key = self::cacheKey($invoice);
        $kept = Cache::get($key);
        $kept = is_array($kept) ? $kept : [];
        if (($kept['amount'] ?? null) === $amount && ($kept['currency'] ?? null) === $currency && is_string($kept['session'] ?? null)) {
            try {
                $open = $api->getSession($kept['session']);
            } catch (ApiException $e) {
                Log::error('Orboni: invoice '.$invoice->id.': could not read session '.$kept['session'].': '.$e->getMessage());

                throw new DisplayException(self::unavailable());
            }
            if (self::payable($open)) {
                return $open['url'];
            }
        }

        // A try whose answer was lost made a session under its key: the next
        // try gets that one. A session that is no longer open (the buyer let
        // it run out) takes a new key.
        $attempt = (int) ($kept['attempt'] ?? 0);
        $body = $this->sessionBody($invoice, $amount, $currency);
        $session = null;
        for ($tries = 0; $tries < self::MAX_TRIES; $tries++) {
            $attempt++;
            try {
                $session = $api->createSession($body, self::idempotencyKey($invoice, $amount, $currency, $attempt));
            } catch (ApiException $e) {
                if ($e->type === 'idempotency_error' && $e->status === 400) {
                    // Sent before with other details (the buyer's language, an edited item).
                    continue;
                }
                Log::error('Orboni: invoice '.$invoice->id.': Orboni refused a payment page ('.$e->type.'): '.$e->getMessage());
                if ($e->type === 'amount_too_small' || ($e->type === 'invalid_request_error' && $e->param === 'amount')) {
                    throw new DisplayException(__('This invoice is below the minimum amount for a crypto payment.'));
                }

                throw new DisplayException(self::unavailable());
            }
            $replayed = (bool) ($session['_replayed'] ?? false);
            if ($replayed) {
                try {
                    $session = $api->getSession((string) ($session['id'] ?? ''));
                } catch (ApiException $e) {
                    Log::error('Orboni: invoice '.$invoice->id.': could not read back a session made before: '.$e->getMessage());

                    throw new DisplayException(self::unavailable());
                }
            }
            // A new page is open for the project's whole time; one made
            // before under this key must still have time left on it.
            if ($replayed ? self::payable($session) : ($session['status'] ?? null) === 'open') {
                break;
            }
            $session = null;
        }

        if (! is_array($session) || ! is_string($session['id'] ?? null) || ! is_string($session['url'] ?? null)) {
            Log::error('Orboni: invoice '.$invoice->id.': no open payment page after '.self::MAX_TRIES.' tries.');

            throw new DisplayException(self::unavailable());
        }

        Cache::put($key, ['session' => $session['id'], 'amount' => $amount, 'currency' => $currency, 'attempt' => $attempt], now()->addDays(self::SESSION_DAYS));
        Log::info('Orboni: invoice '.$invoice->id.': session '.$session['id'].' for '.$amount.' '.$currency.' (smallest units).');

        return $session['url'];
    }

    /**
     * `POST /extensions/gateways/orboni/webhook`. Answers 200 for an event
     * taken or one that is not this site's (Orboni sends a project's events to
     * every endpoint, and a non-2xx is sent again for days), 401 for a bad
     * signature, 400 for a body that is no event, 503 while it cannot decide
     * yet (Orboni not answering) so Orboni retries later.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) $this->config('webhook_secret');
        if ($secret === '') {
            return response()->json(['result' => 'not_configured'], 503);
        }

        $body = $request->getContent();
        if (! Signature::verify($secret, $body, $request->header('Webhook-Signature'))) {
            Log::warning('Orboni: a webhook was refused: its signature does not match this site\'s signing secret.');

            return response()->json(['result' => 'bad_signature'], 401);
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! is_string($event['type'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            return response()->json(['result' => 'bad_event'], 400);
        }
        if (! str_starts_with($event['type'], 'checkout.session.')) {
            return response()->json(['result' => 'ignored']);
        }

        $object = $event['data']['object'];
        $invoice = Sync::invoiceOf($object);
        if ($invoice === null) {
            // Another site's, or a payment link's: not ours to act on.
            return response()->json(['result' => 'ignored']);
        }

        $api = new Api((string) $this->config('secret_key'));
        try {
            $session = $api->getSession((string) $object['id']);
        } catch (ApiException $e) {
            Log::error('Orboni: webhook '.$event['type'].', invoice '.$invoice->id.': could not read the session back: '.$e->getMessage());

            return response()->json(['result' => 'retry_later'], 503);
        }

        Sync::apply($invoice, $session, $event['type']);

        return response()->json(['result' => 'received']);
    }

    /**
     * The buyer is back from the payment page: the session is read once, so
     * the invoice is right before the webhook comes. Then the invoice page,
     * which waits for the payment while one is on its way.
     */
    public function back(Request $request, string $invoice): RedirectResponse
    {
        $found = Invoice::query()->find($invoice);
        if (! $found instanceof Invoice) {
            abort(404);
        }

        $kept = Cache::get(self::cacheKey($found));
        if (is_array($kept) && is_string($kept['session'] ?? null) && $found->status === 'pending') {
            $api = new Api((string) $this->config('secret_key'));
            try {
                Sync::apply($found, $api->getSession($kept['session']));
            } catch (ApiException $e) {
                Log::warning('Orboni: invoice '.$found->id.': could not read session '.$kept['session'].' on the buyer\'s return: '.$e->getMessage());
            }
        }

        return redirect()->to(route('invoices.show', $found).'?checkPayment=true');
    }

    /** @return array<string, mixed> */
    private function sessionBody(Invoice $invoice, int $amount, string $currency): array
    {
        $items = [];
        foreach ($invoice->items as $item) {
            $name = trim(strip_tags((string) $item->description));
            $quantity = (int) $item->quantity;
            $items[] = $quantity > 1 ? $name.' × '.$quantity : $name;
        }
        $email = (string) ($invoice->user?->email ?? '');

        return array_filter([
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => Text::fit(implode(', ', array_filter($items)), 250),
            'details' => Text::fit(__('Invoice :number at :site', ['number' => $invoice->number ?: $invoice->id, 'site' => (string) config('app.name')]), 500),
            'client_reference_id' => (string) $invoice->id,
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'locale' => str_replace('_', '-', app()->getLocale()),
            'success_url' => url('/extensions/gateways/orboni/return/'.$invoice->id),
            'cancel_url' => route('invoices.show', $invoice),
            'metadata' => [
                'paymenter_invoice_id' => (string) $invoice->id,
                'paymenter_invoice_number' => (string) ($invoice->number ?: $invoice->id),
                'paymenter_site' => Sync::siteKey(),
            ],
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The site, the invoice, the amount and the try. Orboni keeps a key a day,
     * and a site restored from a backup numbers its invoices again: the
     * invoice's creation time tells the two apart.
     */
    /**
     * A page the buyer can still pay on: open, with its address, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param  array<string, mixed>  $session
     */
    private static function payable(array $session): bool
    {
        return ($session['status'] ?? null) === 'open'
            && is_string($session['url'] ?? null)
            && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS;
    }

    private static function idempotencyKey(Invoice $invoice, int $amount, string $currency, int $attempt): string
    {
        $instance = substr(hash('sha256', (string) $invoice->id.'|'.$invoice->created_at?->getTimestamp()), 0, 8);

        return sprintf('pm-%s-%d-%s-%d%s-%d', Sync::siteKey(), $invoice->id, $instance, $amount, strtolower($currency), $attempt);
    }

    private static function unavailable(): string
    {
        return __('Crypto payment is not available right now. Please try again in a minute or choose another payment method.');
    }

    public static function cacheKey(Invoice $invoice): string
    {
        return 'orboni:invoice:'.$invoice->id;
    }
}
