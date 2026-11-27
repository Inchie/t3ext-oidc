<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;

/**
 * The Core's database session backend with a storage that fails when a session is removed,
 * naming the session in its message the way the Core's session exceptions do.
 */
final class FailingSessionBackend extends DatabaseSessionBackend
{
    public function remove(string $sessionId): bool
    {
        throw new \RuntimeException('Session with id ' . $sessionId . ' could not be removed (0c6b3f0e-58a0-4b0b-9d3b-5b3a3f1f1c8e)');
    }
}
