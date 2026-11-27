<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotCreatedException;

/**
 * The Core's database session backend with a storage that fails when a session is written.
 */
final class UnwritableSessionBackend extends DatabaseSessionBackend
{
    public function set(string $sessionId, array $sessionData): array
    {
        throw new SessionNotCreatedException('The session could not be written (b1b0f1a5-3f0c-4f43-9a52-6d2c8a4f9e17)');
    }
}
