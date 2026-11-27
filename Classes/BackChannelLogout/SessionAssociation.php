<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

/**
 * The link between one identity-provider session and one TYPO3 frontend session.
 */
final readonly class SessionAssociation
{
    /**
     * @param string $sid The identity-provider session identifier
     * @param string $storedSessionIdentifier The encrypted TYPO3 session identifier as it is stored
     * @param string|null $sessionIdentifier The TYPO3 session identifier, or null when the stored value cannot be decrypted
     */
    public function __construct(
        public string $sid,
        public string $storedSessionIdentifier,
        #[\SensitiveParameter]
        public ?string $sessionIdentifier,
    ) {}
}
