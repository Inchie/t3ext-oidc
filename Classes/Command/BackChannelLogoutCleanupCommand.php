<?php

declare(strict_types=1);

namespace Causal\Oidc\Command;

use Causal\Oidc\BackChannelLogout\SessionAssociation;
use Causal\Oidc\BackChannelLogout\SessionAssociationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;
use TYPO3\CMS\Core\Session\Backend\SessionBackendInterface;
use TYPO3\CMS\Core\Session\SessionManager;

/**
 * Removes the session associations whose TYPO3 frontend session no longer exists.
 */
#[AsCommand(
    name: 'oidc:backchannel:cleanup',
    description: 'Removes Back-Channel Logout session associations whose frontend session no longer exists.',
)]
final class BackChannelLogoutCleanupCommand extends Command
{
    public function __construct(
        private readonly SessionAssociationRepository $associations,
        private readonly SessionManager $sessionManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionBackend = $this->sessionManager->getSessionBackend('FE');
        $associations = $this->associations->findAll();

        $removed = 0;
        foreach ($associations as $association) {
            if ($this->removeIfSessionIsGone($association, $sessionBackend)) {
                $removed++;
            }
        }

        $output->writeln(sprintf(
            'Removed %d of %d session associations; %d kept.',
            $removed,
            count($associations),
            count($associations) - $removed
        ));
        return Command::SUCCESS;
    }

    /**
     * Only the session backend's answer that it does not hold the session removes an
     * association; no failure establishes that the session is gone.
     *
     * @return bool Whether the association was removed
     */
    private function removeIfSessionIsGone(SessionAssociation $association, SessionBackendInterface $sessionBackend): bool
    {
        if ($association->sessionIdentifier === null) {
            $this->logger->warning('Back-Channel Logout cleanup: a session association cannot be decrypted and was kept');
            return false;
        }

        try {
            $sessionBackend->get($association->sessionIdentifier);
            return false;
        } catch (SessionNotFoundException) {
            // The session is gone; its association follows below.
        } catch (\Throwable $e) {
            // The class only: the message of a session backend exception carries the session identifier.
            $this->logger->warning(
                'Back-Channel Logout cleanup: the session backend could not be asked for a session, its association was kept',
                ['exception' => $e::class]
            );
            return false;
        }

        $this->associations->delete($association);
        return true;
    }
}
