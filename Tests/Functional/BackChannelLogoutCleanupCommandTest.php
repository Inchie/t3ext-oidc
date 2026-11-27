<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional;

use Causal\Oidc\BackChannelLogout\SessionAssociationRepository;
use Causal\Oidc\Tests\Functional\Fixtures\UnreadableSessionBackend;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

final class BackChannelLogoutCleanupCommandTest extends AbstractFunctionalTestBase
{
    private const TABLE = 'tx_oidc_backchannel_session';

    #[Test]
    public function associationOfASessionThatNoLongerExistsIsRemoved(): void
    {
        $this->associate('idp-session-1', 'session-that-vanished');

        $command = $this->executeCleanupCommand();

        self::assertSame(0, $command->getStatusCode());
        self::assertSame([], $this->getAllRecords(self::TABLE));
    }

    #[Test]
    public function commandCanBeScheduled(): void
    {
        self::assertArrayHasKey(
            'oidc:backchannel:cleanup',
            $this->get(CommandRegistry::class)->getSchedulableCommandsConfiguration()
        );
    }

    #[Test]
    public function associationOfALiveSessionIsKept(): void
    {
        $this->frontendSessionBackend()->set('live-session', ['ses_userid' => 1, 'ses_data' => '']);
        $this->associate('idp-session-1', 'live-session');
        $associations = $this->getAllRecords(self::TABLE);

        $command = $this->executeCleanupCommand();

        self::assertSame(0, $command->getStatusCode());
        self::assertSame($associations, $this->getAllRecords(self::TABLE));
    }

    #[Test]
    public function associationThatCannotBeDecryptedIsKept(): void
    {
        $undecryptable = ['sid' => 'idp-session-1', 'session_identifier' => 'not-a-stored-session-identifier'];
        $this->getConnectionPool()->getConnectionForTable(self::TABLE)->insert(self::TABLE, $undecryptable);

        $this->executeCleanupCommand();

        self::assertSame([$undecryptable], $this->getAllRecords(self::TABLE));
    }

    #[Test]
    public function associationOfASessionWhoseBackendAccessFailsIsKept(): void
    {
        // The command runs in the process of the test, so the instance is configured here and not for a sub-request
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['FE']['backend'] = UnreadableSessionBackend::class;
        $this->frontendSessionBackend()->set('live-session', ['ses_userid' => 1, 'ses_data' => '']);
        $this->associate('idp-session-1', 'live-session');
        $this->associate('idp-session-2', 'session-that-vanished');
        $associations = $this->getAllRecords(self::TABLE);

        $this->executeCleanupCommand();

        self::assertSame($associations, $this->getAllRecords(self::TABLE));
    }

    #[Test]
    public function neitherLogNorCommandOutputCarriesASessionIdentifier(): void
    {
        $logFile = $this->instancePath . '/typo3temp/var/log/back-channel-logout-cleanup-test.log';
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'][LogLevel::DEBUG][FileWriter::class] = ['logFile' => $logFile];
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['FE']['backend'] = UnreadableSessionBackend::class;
        $this->associate('idp-session-1', 'session-whose-backend-access-fails');
        $this->getConnectionPool()->getConnectionForTable(self::TABLE)->insert(
            self::TABLE,
            ['sid' => 'idp-session-2', 'session_identifier' => 'not-a-stored-session-identifier']
        );

        $command = $this->executeCleanupCommand();

        $logOutput = (string)file_get_contents($logFile);
        self::assertStringContainsString('cannot be decrypted', $logOutput);
        self::assertStringContainsString('session backend', $logOutput);
        self::assertStringNotContainsString('session-whose-backend-access-fails', $logOutput);
        self::assertStringNotContainsString('session-whose-backend-access-fails', $command->getDisplay());
    }

    private function associate(string $sid, string $sessionIdentifier): void
    {
        $this->get(SessionAssociationRepository::class)->store($sid, $sessionIdentifier);
    }

    private function executeCleanupCommand(): CommandTester
    {
        $command = new CommandTester($this->get(CommandRegistry::class)->getCommandByIdentifier('oidc:backchannel:cleanup'));
        $command->execute([]);
        return $command;
    }
}
