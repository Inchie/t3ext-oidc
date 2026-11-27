<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

use Causal\Oidc\Exception\SessionAssociationNotStoredException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Owns the session association table and the encryption of the TYPO3 session identifiers in it.
 */
final readonly class SessionAssociationRepository
{
    private const TABLE = 'tx_oidc_backchannel_session';
    private const COLUMN_LENGTH = 255;
    private const KEY_CONTEXT = 'causal/oidc back-channel logout session association';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @throws SessionAssociationNotStoredException
     */
    public function store(string $sid, #[\SensitiveParameter] string $sessionIdentifier): void
    {
        $this->insert($sid, $sessionIdentifier);
    }

    /**
     * Stores the association of a session that existed before this login and removes the
     * associations its identifier had until now, so the session belongs to the latest login only.
     *
     * @throws SessionAssociationNotStoredException
     */
    public function storeAsOnlyAssociationOfSession(string $sid, #[\SensitiveParameter] string $sessionIdentifier): void
    {
        $storedSessionIdentifier = $this->insert($sid, $sessionIdentifier);
        try {
            foreach ($this->findAll() as $association) {
                if (
                    $association->sessionIdentifier === $sessionIdentifier
                    && $association->storedSessionIdentifier !== $storedSessionIdentifier
                ) {
                    $this->delete($association);
                }
            }
        } catch (\Throwable $e) {
            throw new SessionAssociationNotStoredException(
                'The earlier associations of the session could not be removed. (3126c20d-997d-43f0-b450-bb5aa5e22deb)',
                0,
                $e
            );
        }
    }

    /**
     * @return string The encrypted session identifier as it is stored
     * @throws SessionAssociationNotStoredException
     */
    private function insert(string $sid, #[\SensitiveParameter] string $sessionIdentifier): string
    {
        if ($sid === '' || mb_strlen($sid) > self::COLUMN_LENGTH) {
            throw new SessionAssociationNotStoredException(
                'The sid is empty or does not fit the session association table. (9ae984d6-d3c2-4262-9736-d853856ee44e)'
            );
        }
        if ($sessionIdentifier === '') {
            throw new SessionAssociationNotStoredException(
                'There is no session identifier to associate. (16a28ff7-36ed-43bb-bdec-164b42ae4a0e)'
            );
        }

        try {
            $storedSessionIdentifier = $this->encrypt($sessionIdentifier);
            if (strlen($storedSessionIdentifier) > self::COLUMN_LENGTH) {
                throw new SessionAssociationNotStoredException(
                    'The encrypted session identifier does not fit the session association table. (0eb0bb8e-4592-4056-bed1-c0f932ced601)'
                );
            }
            $this->connection()->insert(self::TABLE, [
                'sid' => $sid,
                'session_identifier' => $storedSessionIdentifier,
            ]);
        } catch (SessionAssociationNotStoredException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Key derivation and encryption fail with errors, the database with exceptions.
            throw new SessionAssociationNotStoredException(
                'The session association could not be stored. (fc0d779a-a24d-4f42-9f28-0ffcccfe0e5d)',
                0,
                $e
            );
        }

        return $storedSessionIdentifier;
    }

    /**
     * @return list<SessionAssociation>
     */
    public function findBySid(string $sid): array
    {
        $rows = $this->connection()->select(['sid', 'session_identifier'], self::TABLE, ['sid' => $sid])->fetchAllAssociative();
        // A database collation may match a sid that differs in case; an association belongs to exactly its sid.
        $rows = array_filter($rows, static fn(array $row): bool => $row['sid'] === $sid);
        return array_values(array_map($this->associationFromRow(...), $rows));
    }

    /**
     * @return list<SessionAssociation>
     */
    public function findAll(): array
    {
        $rows = $this->connection()->select(['sid', 'session_identifier'], self::TABLE)->fetchAllAssociative();
        return array_map($this->associationFromRow(...), $rows);
    }

    public function delete(SessionAssociation $association): void
    {
        $this->connection()->delete(self::TABLE, [
            'sid' => $association->sid,
            'session_identifier' => $association->storedSessionIdentifier,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function associationFromRow(array $row): SessionAssociation
    {
        return new SessionAssociation(
            sid: (string)$row['sid'],
            storedSessionIdentifier: (string)$row['session_identifier'],
            sessionIdentifier: $this->decrypt((string)$row['session_identifier']),
        );
    }

    private function encrypt(#[\SensitiveParameter] string $sessionIdentifier): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($sessionIdentifier, $nonce, $this->deriveKey()));
    }

    /**
     * @return string|null Null when the stored value cannot be decrypted
     */
    private function decrypt(string $storedSessionIdentifier): ?string
    {
        $nonceAndCiphertext = base64_decode($storedSessionIdentifier, true);
        if ($nonceAndCiphertext === false || strlen($nonceAndCiphertext) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        try {
            $sessionIdentifier = sodium_crypto_secretbox_open(
                substr($nonceAndCiphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($nonceAndCiphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $this->deriveKey()
            );
        } catch (\Throwable) {
            // A missing encryption key makes the key derivation fail with an error.
            return null;
        }
        return $sessionIdentifier === false ? null : $sessionIdentifier;
    }

    /**
     * Derives the key the way the cookie signing key is derived, under a context of its own.
     */
    private function deriveKey(): string
    {
        return hash_hkdf(
            algo: 'sha256',
            key: (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''),
            length: SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            info: self::KEY_CONTEXT,
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
