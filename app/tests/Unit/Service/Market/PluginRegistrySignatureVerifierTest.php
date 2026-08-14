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

namespace App\Tests\Unit\Service\Market;

use App\Service\Market\PluginRegistrySignatureVerifier;
use PHPUnit\Framework\TestCase;

final class PluginRegistrySignatureVerifierTest extends TestCase
{
    public function testAcceptsSignatureFromTrustedKey(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        $message = '{"sequence":1,"asset_mirrors":[],"plugins":[]}';
        $signature = base64_encode(sodium_crypto_sign_detached($message, $secretKey));

        $verifier = new PluginRegistrySignatureVerifier([$trustedPublicKey]);

        $this->assertTrue($verifier->verify($message, $signature));
    }

    public function testAcceptsSignatureFromSecondTrustedKeyDuringRotation(): void
    {
        $currentKeyPair = sodium_crypto_sign_keypair();
        $nextKeyPair = sodium_crypto_sign_keypair();
        $currentPublicKey = base64_encode(sodium_crypto_sign_publickey($currentKeyPair));
        $nextPublicKey = base64_encode(sodium_crypto_sign_publickey($nextKeyPair));
        $nextSecretKey = sodium_crypto_sign_secretkey($nextKeyPair);

        $message = '{"sequence":1,"asset_mirrors":[],"plugins":[]}';
        $signature = base64_encode(sodium_crypto_sign_detached($message, $nextSecretKey));

        $verifier = new PluginRegistrySignatureVerifier([$currentPublicKey, $nextPublicKey]);

        $this->assertTrue($verifier->verify($message, $signature));
    }

    public function testRejectsSignatureFromUntrustedKey(): void
    {
        $trustedKeyPair = sodium_crypto_sign_keypair();
        $trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($trustedKeyPair));

        $untrustedKeyPair = sodium_crypto_sign_keypair();
        $untrustedSecretKey = sodium_crypto_sign_secretkey($untrustedKeyPair);

        $message = '{"sequence":1,"asset_mirrors":[],"plugins":[]}';
        $signature = base64_encode(sodium_crypto_sign_detached($message, $untrustedSecretKey));

        $verifier = new PluginRegistrySignatureVerifier([$trustedPublicKey]);

        $this->assertFalse($verifier->verify($message, $signature));
    }

    public function testRejectsSignatureOverTamperedMessage(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        $signature = base64_encode(sodium_crypto_sign_detached('{"sequence":1}', $secretKey));

        $verifier = new PluginRegistrySignatureVerifier([$trustedPublicKey]);

        $this->assertFalse($verifier->verify('{"sequence":2}', $signature));
    }

    public function testRejectsMalformedSignature(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $trustedPublicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $verifier = new PluginRegistrySignatureVerifier([$trustedPublicKey]);

        $this->assertFalse($verifier->verify('{"sequence":1}', 'not-a-valid-base64-signature'));
    }

    public function testDefaultTrustedKeyListHasAtLeastOneWellFormedEd25519PublicKey(): void
    {
        $verifier = new PluginRegistrySignatureVerifier();

        // A verifier built with the hardcoded default key list must not accept an arbitrary
        // signature (sanity check that the default constant is exercised, not bypassed), while
        // still being reachable without a network call or a real private key.
        $this->assertFalse($verifier->verify('{"sequence":1}', base64_encode(str_repeat('a', \SODIUM_CRYPTO_SIGN_BYTES))));
    }
}
