<?php

namespace Paymenter\Extensions\Gateways\Orboni;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Orboni's API, as much of it as Paymenter needs: the currencies a price can
 * be set in, a checkout session made and read back. `Authorization: Bearer
 * sk_…`, JSON both ways.
 */
class Api
{
    public const SESSION_ID = '/^cs_(live|test)_[A-Za-z0-9]+$/';

    /** Seconds Orboni's own "updating, retry" answer may make a buyer wait, once. */
    private const MAX_RETRY_AFTER = 5;

    private string $base;

    public function __construct(private readonly string $secret)
    {
        // Only for trying the extension against another Orboni (config/services.php).
        $this->base = rtrim((string) config('services.orboni.url', 'https://orboni.com'), '/');
    }

    /** Whose key this is, to keep each key's answers apart. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->base.'|'.$this->secret), 0, 16);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function currencies(): array
    {
        return $this->request('GET', '/v1/currencies');
    }

    /**
     * A new checkout session. The key makes a retry return the session the
     * first try made (`_replayed` is then true) instead of a second one.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function createSession(array $body, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/checkout/sessions', $body, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function getSession(string $id): array
    {
        if (preg_match(self::SESSION_ID, $id) !== 1) {
            throw new ApiException('Not an Orboni checkout session id.', 'bad_session_id', 0);
        }

        return $this->request('GET', '/v1/checkout/sessions/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = ['User-Agent' => 'orboni-paymenter/'.Orboni::VERSION];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        // A GET, or a POST under an idempotency key, can be sent twice safely.
        $retryable = $method === 'GET' || $idempotencyKey !== null;

        $response = null;
        for ($try = 0; $try < 2; $try++) {
            try {
                $pending = Http::withToken($this->secret)->acceptJson()->timeout(20)->withoutRedirecting()->withHeaders($headers);
                $response = $method === 'GET' ? $pending->get($this->base.$path) : $pending->post($this->base.$path, $body ?? []);
            } catch (ConnectionException $e) {
                if ($retryable && $try === 0) {
                    continue;
                }

                throw new ApiException('Orboni could not be reached: '.$e->getMessage(), 'connection_error', 0);
            }
            // Orboni's own deploy: a few seconds' "503, retry after".
            if ($response->status() === 503 && $retryable && $try === 0) {
                sleep(min(max((int) $response->header('Retry-After'), 1), self::MAX_RETRY_AFTER));

                continue;
            }

            break;
        }

        return self::answer($response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private static function answer(?Response $response): array
    {
        if ($response === null) {
            throw new ApiException('Orboni could not be reached.', 'connection_error', 0);
        }
        $data = $response->json();
        if ($response->successful() && is_array($data)) {
            if ($response->header('Idempotent-Replayed') === 'true') {
                $data['_replayed'] = true;
            }

            return $data;
        }

        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];

        throw new ApiException(
            is_string($error['message'] ?? null) ? $error['message'] : 'Orboni answered HTTP '.$response->status().'.',
            is_string($error['type'] ?? null) ? $error['type'] : 'http_'.$response->status(),
            $response->status(),
            is_string($error['param'] ?? null) ? $error['param'] : null,
        );
    }
}
