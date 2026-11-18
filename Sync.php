<?php

namespace Paymenter\Extensions\Gateways\Orboni;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An invoice follows its Orboni session as Orboni has it now (read back,
 * never an event's old copy). The session's id is the transaction's id, so a
 * webhook sent twice, or one beside the buyer's return, changes one
 * transaction:
 *
 * - on its way (confirming on the chain, partly paid, held for the
 *   merchant's review): a processing transaction, and the invoice page waits;
 * - paid in full and final: the transaction succeeds, and Paymenter marks the
 *   invoice paid;
 * - expired with nothing paid: a processing transaction, if one was made,
 *   fails.
 */
class Sync
{
    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** This Paymenter, among every site that uses the same Orboni project. */
    public static function siteKey(): string
    {
        return substr(hash('sha256', rtrim((string) config('app.url'), '/')), 0, 12);
    }

    /**
     * The invoice an event's session was made for, by this site; null for
     * another site's session or a payment link's.
     *
     * @param  array<string, mixed>  $session
     */
    public static function invoiceOf(array $session): ?Invoice
    {
        $reference = (string) ($session['client_reference_id'] ?? '');
        if (preg_match(Api::SESSION_ID, (string) ($session['id'] ?? '')) !== 1 || ! ctype_digit($reference)
            || (string) (((array) ($session['metadata'] ?? []))['paymenter_site'] ?? '') !== self::siteKey()) {
            return null;
        }

        return Invoice::query()->find((int) $reference);
    }

    /**
     * Under a lock on the invoice, so a webhook and the buyer's return at
     * the same moment decide one after the other. A read older than what is
     * on record never takes a paid transaction back: only Orboni's own
     * `payment_reversed`, read back as not paid, does.
     *
     * @param  array<string, mixed>  $session
     */
    public static function apply(Invoice $invoice, array $session, string $event = ''): void
    {
        $id = (string) ($session['id'] ?? '');
        if (self::invoiceOf($session)?->id !== $invoice->id) {
            Log::warning('Orboni: session '.$id.' is not invoice '.$invoice->id.'\'s; left alone.');

            return;
        }

        $currency = strtoupper((string) ($session['currency'] ?? ''));
        if ($currency !== strtoupper((string) $invoice->currency_code)) {
            Log::error('Orboni: session '.$id.' is in '.$currency.', invoice '.$invoice->id.' in '.$invoice->currency_code.'; left alone.');

            return;
        }

        DB::transaction(static function () use ($invoice, $session, $id, $event): void {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $existing = InvoiceTransaction::query()->where('invoice_id', $locked->id)->where('transaction_id', $id)->lockForUpdate()->first();
            self::decide($locked, $session, $existing, $event);
        });
    }

    /** @param  array<string, mixed>  $session */
    private static function decide(Invoice $invoice, array $session, ?InvoiceTransaction $existing, string $event): void
    {
        $id = (string) $session['id'];
        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $amount = self::total($session);
        $succeeded = $existing?->status === InvoiceTransactionStatus::Succeeded;

        if ($status === 'complete' && $payment === 'paid') {
            if ($succeeded) {
                if ($event === self::EXTRA) {
                    Log::warning('Orboni: more money arrived after invoice '.$invoice->id.' was paid ('.self::received($session).' in all). Send the difference back from your wallet if the buyer asks.');
                }

                return;
            }
            if ($invoice->status === 'paid') {
                Log::warning('Orboni: invoice '.$invoice->id.' was already paid when session '.$id.' paid it again; the payment is recorded. Send it back from your wallet or keep it as credit.');
            }
            ExtensionHelper::addPayment($invoice->id, Orboni::NAME, $amount, null, $id);
            Log::info('Orboni: invoice '.$invoice->id.' paid by session '.$id.'.');

            return;
        }

        if ($succeeded) {
            if ($event === self::REVERSED) {
                // Money that paid it left the chain (a reorganisation, a double
                // spend). Paymenter has no "unpaid again": the transaction waits
                // until Orboni shows it paid once more.
                ExtensionHelper::addProcessingPayment($invoice->id, Orboni::NAME, $amount, null, $id);
                Log::error('Orboni: money that paid invoice '.$invoice->id.' left the chain (session '.$id.', '.self::received($session).' still counted). Do not deliver until the Orboni dashboard shows it paid again.');
            }

            return;
        }

        if (in_array($payment, ['processing', 'partially_paid', 'held'], true)) {
            ExtensionHelper::addProcessingPayment($invoice->id, Orboni::NAME, $amount, null, $id);
            if ($payment === 'held') {
                Log::warning('Orboni: invoice '.$invoice->id.': the money is in your wallet but held for your review in the Orboni dashboard (reason: '.($session['review_reason'] ?? '-').'). Accept it there and the invoice is marked paid.');
            } elseif ($payment === 'partially_paid' && $status === 'expired') {
                Log::warning('Orboni: invoice '.$invoice->id.': the payment page closed partly paid ('.self::received($session).' arrived). Settle it with the buyer; Orboni will not complete this payment.');
            }

            return;
        }

        if ($status === 'expired' && $payment === 'unpaid' && $existing?->status === InvoiceTransactionStatus::Processing) {
            ExtensionHelper::addFailedPayment($invoice->id, Orboni::NAME, $amount, null, $id);
        }
    }

    /**
     * The session's total as a decimal in its currency: `amount_due`, which
     * Orboni writes with the currency's own decimals ("25.00", "1500").
     *
     * @param  array<string, mixed>  $session
     */
    private static function total(array $session): string
    {
        $due = $session['amount_due'] ?? null;

        return is_numeric($due) ? (string) $due : Money::major((int) ($session['amount_total'] ?? 0), 2);
    }

    /** @param  array<string, mixed>  $session */
    private static function received(array $session): string
    {
        return ((string) ($session['amount_received'] ?? '0')).' '.strtoupper((string) ($session['currency'] ?? ''));
    }
}
