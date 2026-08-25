<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use RuntimeException;

final class Crypto
{
    private string $encryptionKey;
    private string $hashKey;

    public function __construct(string $encryptionSecret, string $hashSecret)
    {
        if ($encryptionSecret === '' || $hashSecret === '') {
            throw new RuntimeException('Encryption and hash secrets must not be empty.');
        }
        $this->encryptionKey = hash('sha256', $encryptionSecret, true);
        $this->hashKey = hash('sha256', $hashSecret, true);
    }

    public function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->hashKey, true);
    }

    public function encryptIp(string $ip): string
    {
        return $this->encrypt($ip);
    }

    public function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plainText, $nonce, $this->encryptionKey);
            return "S" . $nonce . $cipher;
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt(
                $plainText,
                'aes-256-gcm',
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if ($cipher === false) {
                throw new RuntimeException('Encryption failed.');
            }
            return "O" . $iv . $tag . $cipher;
        }

        throw new RuntimeException('Neither Sodium nor OpenSSL is available.');
    }

    public function decryptIp(?string $payload): string
    {
        return $this->decrypt($payload);
    }

    public function decrypt(?string $payload): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }

        $mode = $payload[0];
        $data = substr($payload, 1);

        if ($mode === 'S' && function_exists('sodium_crypto_secretbox_open')) {
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($data) < $nonceLength + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                return '';
            }
            $nonce = substr($data, 0, $nonceLength);
            $cipher = substr($data, $nonceLength);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->encryptionKey);
            return $plain === false ? '' : $plain;
        }

        if ($mode === 'O' && function_exists('openssl_decrypt')) {
            if (strlen($data) < 29) {
                return '';
            }
            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $cipher = substr($data, 28);
            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            return $plain === false ? '' : $plain;
        }

        return '';
    }
}
