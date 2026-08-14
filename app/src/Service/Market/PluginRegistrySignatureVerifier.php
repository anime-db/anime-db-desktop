<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Service\Market;

/**
 * Verifies a detached Ed25519 signature over the exact bytes of `plugins-registry.json` against
 * a short, hardcoded list of trusted public keys, via `sodium_crypto_sign_verify_detached()`
 * (libsodium, part of PHP core since 7.2 — no extra dependency). Mirrors the reference
 * verification logic the registry's own publishing pipeline ships
 * (`anime-db/anime-db-plugins`'s `PluginRegistrySigner::verify()`), which this list of keys must
 * stay in sync with.
 *
 * The list holds more than one key so the signing key can rotate without a flag day: publish a
 * client release trusting the current key plus the next one *before* the registry starts signing
 * with "next", then drop the old key from a later release once adoption is high enough. Today
 * only one key is issued, so the list has a single entry.
 */
final class PluginRegistrySignatureVerifier
{
    /**
     * Base64-encoded Ed25519 public keys, in the same shape
     * `anime-db/anime-db-plugins`'s `tools/generate-registry-keypair.php` writes to
     * `plugins-registry.pub` (safe to commit/hardcode — it is the public half of the keypair).
     */
    private const array TRUSTED_PUBLIC_KEYS_BASE64 = [
        '3gnP8IxSlVxitfxLO3iemvzg2wc48yk5nMG2xt1f3wE=',
    ];

    /**
     * @param list<string> $trustedPublicKeysBase64
     */
    public function __construct(
        private readonly array $trustedPublicKeysBase64 = self::TRUSTED_PUBLIC_KEYS_BASE64,
    ) {
    }

    public function verify(string $message, string $signatureBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || \strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        foreach ($this->trustedPublicKeysBase64 as $publicKeyBase64) {
            $publicKey = base64_decode($publicKeyBase64, true);
            if ($publicKey === false || \strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            if (sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                return true;
            }
        }

        return false;
    }
}
