<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;

/**
 * The Core's database session backend with a storage that removes a given number of sessions
 * and fails from then on, naming the session in its message the way the Core's session
 * exceptions do. The Core removes once itself when it creates the session of a login.
 */
final class TransientlyUnremovableSessionBackend extends DatabaseSessionBackend
{
    public static int $removalsBeforeTheStorageFails = 0;

    public function remove(string $sessionId): bool
    {
        if (self::$removalsBeforeTheStorageFails === 0) {
            throw new \RuntimeException('Session with id ' . $sessionId . ' could not be removed (7c2f2f0b-8c53-4e6e-b0f6-2f6a1c3f8d54)');
        }
        self::$removalsBeforeTheStorageFails--;
        return parent::remove($sessionId);
    }
}
