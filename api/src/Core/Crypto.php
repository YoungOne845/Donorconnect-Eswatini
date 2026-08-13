<?php

declare(strict_types=1);

namespace App\Core;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private string $encryptionKey;
    private string $hashKey;

    public function __construct(string $encryptionSecret, string $hashSecret)
    {
        if (strlen($encryptionSecret) < 32 || strlen($hashSecret) < 32) {
            throw new \RuntimeException('Security keys must each contain at least 32 characters.');
        }
        $this->encryptionKey = hash('sha256', $encryptionSecret, true);
        $this->hashKey = $hashSecret;
    }

    public function encrypt(string $plainText): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new \RuntimeException('Encryption cipher is unavailable.');
        }
        $iv = random_bytes($ivLength);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode(json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($cipherText),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $payload): string
    {
        $json = base64_decode($payload, true);
        if ($json === false) {
            throw new \RuntimeException('Encrypted payload is invalid.');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $plainText = openssl_decrypt(
            base64_decode((string) $data['data'], true),
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            base64_decode((string) $data['iv'], true),
            base64_decode((string) $data['tag'], true)
        );
        if ($plainText === false) {
            throw new \RuntimeException('Decryption failed.');
        }
        return $plainText;
    }

    public function searchHash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashKey);
    }

    public function mask(string $value): string
    {
        return str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4);
    }
}
