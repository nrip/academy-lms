<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\SealedSecret;
use RuntimeException;

final class SealedSecretBox
{
    public function __construct(
        private readonly NotificationKeyMaterial $keys,
    ) {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('ext-sodium is required for sealed notification delivery.');
        }
    }

    public function seal(string $plaintext, string $aad): SealedSecret
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $this->keys->currentKey(),
        );

        return new SealedSecret($ciphertext, $nonce, $this->keys->currentVersion());
    }

    public function unseal(SealedSecret $sealed, string $aad): string
    {
        try {
            $key = $this->keys->keyForVersion($sealed->keyVersion);
        } catch (\InvalidArgumentException) {
            throw new DomainRuleException('Unable to open sealed delivery.');
        }

        if (strlen($sealed->nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new DomainRuleException('Unable to open sealed delivery.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $sealed->ciphertext,
            $aad,
            $sealed->nonce,
            $key,
        );

        if ($plaintext === false) {
            throw new DomainRuleException('Unable to open sealed delivery.');
        }

        return $plaintext;
    }

    public static function tokenAad(int $verificationTokenId, string $purpose, int $userId): string
    {
        return $verificationTokenId . '|' . $purpose . '|' . $userId;
    }

    public static function challengeAad(int $verificationChallengeId, string $channel, int $userId): string
    {
        return $verificationChallengeId . '|' . $channel . '|' . $userId;
    }
}
