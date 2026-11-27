<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;

/**
 * The Core's database session backend with a storage that fails when a session is read,
 * naming the session in its message the way the Core's session exceptions do.
 */
final class UnreadableSessionBackend extends DatabaseSessionBackend
{
    public function get(string $sessionId): array
    {
        throw new \RuntimeException('Session with id ' . $sessionId . ' could not be read (5d0f4c0a-6a57-4b0e-9a55-3c1b7f0e2d41)');
    }
}
