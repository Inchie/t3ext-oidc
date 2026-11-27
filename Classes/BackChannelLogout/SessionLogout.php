<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Session\SessionManager;

/**
 * Ends the TYPO3 frontend sessions associated with an identity-provider session.
 */
final readonly class SessionLogout
{
    public function __construct(
        private SessionAssociationRepository $associations,
        private SessionManager $sessionManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Removes every frontend session associated with the sid, and the association of each
     * removed session. A sid without association has nothing left to end.
     *
     * @return bool Whether no association failed
     */
    public function endSessionsOf(string $sid): bool
    {
        try {
            $associations = $this->associations->findBySid($sid);
        } catch (\Throwable $e) {
            $this->logFailure('the session associations could not be read', $e);
            return false;
        }

        $everySessionEnded = true;
        foreach ($associations as $association) {
            if (!$this->endSessionOf($association)) {
                $everySessionEnded = false;
            }
        }
        return $everySessionEnded;
    }

    private function endSessionOf(SessionAssociation $association): bool
    {
        if ($association->sessionIdentifier === null) {
            // The failure does not establish that the session is gone, so the association stays.
            $this->logger->error('Back-Channel Logout failed: a session association cannot be decrypted and was kept');
            return false;
        }

        try {
            // A session the backend no longer holds counts as removed.
            $this->sessionManager->getSessionBackend('FE')->remove($association->sessionIdentifier);
            $this->associations->delete($association);
        } catch (\Throwable $e) {
            $this->logFailure('a session or its association could not be removed', $e);
            return false;
        }
        return true;
    }

    /**
     * Logs the kind of the failure only: the message of a session backend exception carries the session identifier.
     */
    private function logFailure(string $failure, \Throwable $e): void
    {
        $this->logger->error('Back-Channel Logout failed: ' . $failure, ['exception' => $e::class]);
    }
}
