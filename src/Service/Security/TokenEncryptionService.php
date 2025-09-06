<?php

declare(strict_types=1);

namespace App\Service\Security;

use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function is_string;
use function strlen;

use const OPENSSL_RAW_DATA;

/**
 * Service for encrypting and decrypting sensitive tokens
 * Uses AES-256-GCM for authenticated encryption.
 */
class TokenEncryptionService
{
    private const string CIPHER_METHOD = 'aes-256-gcm';
    private const int TAG_LENGTH = 16;
    private string $encryptionKey;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        // Get encryption key from environment or generate if not set
        $key = $parameterBag->get('app.encryption_key') ?? $parameterBag->get('APP_SECRET');

        // Ensure we have a valid key
        if (!is_string($key) || '' === $key) {
            throw new RuntimeException('Encryption key not configured. Set APP_ENCRYPTION_KEY in environment.');
        }

        // Derive a proper encryption key from the secret
        // hash() with binary=true and valid algorithm always returns string
        $this->encryptionKey = hash('sha256', $key, true);
    }

    /**
     * Encrypts a token using AES-256-GCM.
     *
     * @param string $token The plain text token to encrypt
     *
     * @throws RuntimeException If encryption fails
     *
     * @return string Base64 encoded encrypted token with IV and auth tag
     */
    public function encryptToken(string $token): string
    {
        if (empty($token)) {
            return '';
        }

        // Generate a random IV for each encryption
        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        if (false === $ivLength) {
            throw new RuntimeException('Failed to get IV length for cipher method');
        }

        $iv = openssl_random_pseudo_bytes($ivLength);
        // openssl_random_pseudo_bytes returns string in PHP 8+
        if ('' === $iv) {
            throw new RuntimeException('Failed to generate IV');
        }

        // Encrypt the token
        $tag = '';
        $encrypted = openssl_encrypt(
            $token,
            self::CIPHER_METHOD,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if (false === $encrypted) {
            throw new RuntimeException('Token encryption failed');
        }

        // Combine IV, tag and encrypted data
        $combined = $iv . $tag . $encrypted;

        // Return base64 encoded for safe storage
        return base64_encode($combined);
    }

    /**
     * Decrypts a token encrypted with encryptToken.
     *
     * @param string $encryptedToken Base64 encoded encrypted token
     *
     * @throws RuntimeException If decryption fails
     *
     * @return string The decrypted plain text token
     */
    public function decryptToken(string $encryptedToken): string
    {
        if (empty($encryptedToken)) {
            return '';
        }

        // Decode from base64
        $combined = base64_decode($encryptedToken, true);
        if (false === $combined) {
            throw new RuntimeException('Invalid encrypted token format');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        if (false === $ivLength) {
            throw new RuntimeException('Failed to get IV length for cipher method');
        }

        // Extract IV, tag and encrypted data
        if (strlen($combined) < $ivLength + self::TAG_LENGTH) {
            throw new RuntimeException('Encrypted token too short');
        }

        $iv = substr($combined, 0, $ivLength);
        $tag = substr($combined, $ivLength, self::TAG_LENGTH);
        $encrypted = substr($combined, $ivLength + self::TAG_LENGTH);

        // Decrypt the token
        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER_METHOD,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if (false === $decrypted) {
            throw new RuntimeException('Token decryption failed - token may be corrupted or tampered');
        }

        return $decrypted;
    }

    /**
     * Rotates an encrypted token with a new IV while keeping the same content
     * Useful for periodic token rotation for security.
     *
     * @param string $encryptedToken The current encrypted token
     *
     * @return string The newly encrypted token with fresh IV
     */
    public function rotateToken(string $encryptedToken): string
    {
        $plainToken = $this->decryptToken($encryptedToken);

        return $this->encryptToken($plainToken);
    }
}
