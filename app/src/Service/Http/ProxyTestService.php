<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Http;

use App\Entity\Enum\ProxyTestOutcome;
use App\Entity\ValueObject\ProxySettings;
use App\Entity\ValueObject\ProxyTestResult;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs a one-off connectivity check against a proxy configuration that has not necessarily been
 * saved yet — the settings page's "Test" button (issue #328) builds this straight from the form,
 * so a user can verify a candidate host/port/credentials before committing them to config.json
 * via ProxyConfigProvider.
 *
 * Unlike ProxyAwareHttpClient (issue #327), this never reads ProxyConfigProvider: the caller
 * always supplies the exact ProxySettings to test, which may differ from what is currently
 * saved.
 */
final class ProxyTestService
{
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function test(ProxySettings $settings, string $url): ProxyTestResult
    {
        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
        ];

        $proxyUrl = $settings->toProxyUrl();
        if ($proxyUrl !== null) {
            $options['proxy'] = $proxyUrl;
        }

        $startedAt = microtime(true);

        try {
            $statusCode = $this->httpClient->request('GET', $url, $options)->getStatusCode();
        } catch (TimeoutExceptionInterface) {
            return ProxyTestResult::failure(ProxyTestOutcome::Timeout);
        } catch (TransportExceptionInterface $exception) {
            return ProxyTestResult::failure($this->classifyTransportError($exception));
        } catch (\Throwable) {
            // Covers e.g. an unparsable $url from the form — never surfaced beyond a category.
            return ProxyTestResult::failure(ProxyTestOutcome::UnknownError);
        }

        if ($statusCode === 407) {
            return ProxyTestResult::failure(ProxyTestOutcome::AuthFailed);
        }

        return ProxyTestResult::success((int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * The exception's message (curl error text) is only ever inspected here, never included in
     * the ProxyTestResult returned to the caller — matching the acceptance criteria that no raw
     * exception text, host, port or credentials leave this service (issue #328).
     */
    private function classifyTransportError(TransportExceptionInterface $exception): ProxyTestOutcome
    {
        $message = strtolower($exception->getMessage());

        if (preg_match('/\b407\b/', $message) === 1 || str_contains($message, 'authenticat')) {
            return ProxyTestOutcome::AuthFailed;
        }

        if (
            str_contains($message, 'refused')
            || str_contains($message, "couldn't connect")
            || str_contains($message, 'could not resolve')
            || str_contains($message, 'resolve host')
            || str_contains($message, 'resolve proxy')
        ) {
            return ProxyTestOutcome::ConnectionRefused;
        }

        return ProxyTestOutcome::UnknownError;
    }
}
