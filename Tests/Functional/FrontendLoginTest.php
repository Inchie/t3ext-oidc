<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional;

use Causal\Oidc\BackChannelLogout\SessionAssociationRepository;
use Causal\Oidc\Tests\Functional\Fixtures\TransientlyUnremovableSessionBackend;
use Causal\Oidc\Tests\Functional\Fixtures\UnwritableSessionBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotCreatedException;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

final class FrontendLoginTest extends AbstractFunctionalTestBase
{
    private const BACK_CHANNEL_LOGOUT = ['issuer' => self::ISSUER, 'endpointJwks' => self::ENDPOINT_JWKS];

    #[Test]
    public function loginStoresEncryptedSessionAssociationWhenBackChannelLogoutIsConfigured(): void
    {
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => 'idp-session-1']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $response = $this->executeFrontendSubRequest($this->loginRequest());

        self::assertStringContainsString('logged-in=1', (string)$response->getBody());
        self::assertStringContainsString('groups=0,-2,1;', (string)$response->getBody());
        $rows = $this->getAllRecords('tx_oidc_backchannel_session');
        self::assertCount(1, $rows);
        self::assertSame('idp-session-1', $rows[0]['sid']);

        $associations = $this->get(SessionAssociationRepository::class)->findBySid('idp-session-1');
        self::assertCount(1, $associations);
        $sessionIdentifier = $associations[0]->sessionIdentifier;
        self::assertIsString($sessionIdentifier);
        self::assertNotSame($sessionIdentifier, $rows[0]['session_identifier']);
        self::assertStringNotContainsString($sessionIdentifier, $rows[0]['session_identifier']);

        $sessions = $this->authenticatedFrontendSessions();
        self::assertCount(1, $sessions);
        $session = $this->frontendSessionBackend()->get($sessionIdentifier);
        self::assertSame($sessions[0]['ses_id'], $session['ses_id']);
        self::assertNotContains(self::ENDPOINT_JWKS, $this->outboundRequestUrls);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function subjectsOfARepeatedLogin(): \Generator
    {
        yield 'the same user' => ['subject-1'];
        yield 'another user' => ['subject-2'];
    }

    /**
     * The Core authenticates again in an existing session only where it is configured to always
     * fetch the user, and then keeps the session identifier.
     */
    #[Test]
    #[DataProvider('subjectsOfARepeatedLogin')]
    public function repeatedLoginInAnExistingSessionLeavesOnlyTheAssociationOfTheLatestLogin(string $subject): void
    {
        $alwaysFetchUser = ['SVCONF' => ['auth' => ['setup' => ['FE_alwaysFetchUser' => true]]]];
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        self::$instanceConfiguration = $alwaysFetchUser;
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => 'idp-session-1']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);
        $response = $this->executeFrontendSubRequest($this->loginRequest());
        self::assertSame(1, preg_match('/fe_typo_user=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $sessionCookie));

        self::$instanceConfiguration = $alwaysFetchUser;
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => 'idp-session-2', 'sub' => $subject]),
            self::ENDPOINT_USERINFO => $this->userInfoResponse($subject),
        ]);
        $this->executeFrontendSubRequest($this->loginRequest()->withCookieParams(['fe_typo_user' => $sessionCookie[1]]));

        $associations = $this->get(SessionAssociationRepository::class)->findAll();
        self::assertCount(1, $associations);
        self::assertSame('idp-session-2', $associations[0]->sid);
        $sessions = $this->authenticatedFrontendSessions();
        self::assertCount(1, $sessions);
        self::assertSame($sessions[0]['ses_id'], $this->frontendSessionBackend()->get((string)$associations[0]->sessionIdentifier)['ses_id']);
    }

    /**
     * @return \Generator<string, array{provider?: array<string, mixed>, idTokenClaims?: array<string, mixed>, userInfoSub?: string, instanceConfiguration?: array<string, mixed>}>
     */
    public static function deniedLogins(): \Generator
    {
        yield 'wrong iss' => ['idTokenClaims' => ['iss' => 'https://other-idp.example/realms/test']];
        yield 'wrong aud' => ['idTokenClaims' => ['aud' => 'other-client']];
        yield 'aud naming a further client' => ['idTokenClaims' => ['aud' => [self::CLIENT, 'other-client']]];
        yield 'expired' => ['idTokenClaims' => ['exp' => time() - 1]];
        yield 'exp not a number' => ['idTokenClaims' => ['exp' => (string)(time() + 300)]];
        yield 'missing sid' => ['idTokenClaims' => ['sid' => null]];
        yield 'sid not a string' => ['idTokenClaims' => ['sid' => 42]];
        yield 'UserInfo sub differing from the ID token sub' => ['userInfoSub' => 'subject-2'];
        yield 'non-HTTPS endpointToken' => ['provider' => ['endpointToken' => 'http://idp.example/token']];
        yield 'non-HTTPS endpointJwks' => ['provider' => ['endpointJwks' => 'http://idp.example/jwks']];
        yield 'HTTP.verify disabled' => ['instanceConfiguration' => ['HTTP' => ['verify' => false]]];
        yield 'HTTP.verify disabled as string' => ['instanceConfiguration' => ['HTTP' => ['verify' => '0']]];
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $idTokenClaims
     * @param array<string, mixed> $instanceConfiguration
     */
    #[Test]
    #[DataProvider('deniedLogins')]
    public function loginIsDeniedBeforeAnyUserRecordIsTouched(
        array $provider = [],
        array $idTokenClaims = [],
        string $userInfoSub = 'subject-1',
        array $instanceConfiguration = [],
    ): void {
        $provider = array_replace(self::BACK_CHANNEL_LOGOUT, $provider);
        $this->configureProvider($provider);
        self::$instanceConfiguration = $instanceConfiguration;
        $this->answerOutboundRequests([
            $provider['endpointToken'] ?? self::ENDPOINT_TOKEN => $this->tokenResponse($idTokenClaims),
            self::ENDPOINT_USERINFO => $this->userInfoResponse($userInfoSub),
        ]);
        // A returning user whose name a successful login would update, beside the user a successful login would create
        $this->getConnectionPool()->getConnectionForTable('fe_users')->insert('fe_users', [
            'pid' => 0,
            'username' => 'subject-1',
            'tx_oidc' => 'subject-1',
            'name' => 'Name before login',
            'usergroup' => '1',
        ]);
        $frontendUsersBeforeLogin = $this->getAllRecords('fe_users');

        $this->executeFrontendSubRequest($this->loginRequest());

        $this->assertLoginWasDenied();
        self::assertSame($frontendUsersBeforeLogin, $this->getAllRecords('fe_users'));
    }

    #[Test]
    public function loginIsDeniedWhenTheTokenResponseCarriesNoIdToken(): void
    {
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => new JsonResponse(['access_token' => 'access-token-1', 'token_type' => 'Bearer']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $this->executeFrontendSubRequest($this->loginRequest());

        $this->assertLoginWasDenied();
        self::assertSame([], $this->getAllRecords('fe_users'));
    }

    #[Test]
    public function loginIsDeniedWhenTheAssociationCannotBeStored(): void
    {
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        $this->answerOutboundRequests([
            // One character more than the association table holds
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => str_repeat('s', 256)]),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $response = $this->executeFrontendSubRequest($this->loginRequest());

        $this->assertLoginWasDenied();
        // The session the Core persisted for the login is gone, and the request itself went on without a user
        self::assertSame([], $this->frontendSessionBackend()->getAll());
        self::assertStringNotContainsString('logged-in=1', (string)$response->getBody());
        self::assertStringContainsString('groups=0,-1;', (string)$response->getBody());
        self::assertSame([], $this->sessionCookiesHandedOutBy($response));
    }

    /**
     * With the Core configured to always fetch the user, a login of another user re-creates the
     * session of the request under the identifier it had.
     */
    #[Test]
    public function loginInAnExistingSessionEndsThatSessionWhenTheAssociationCannotBeStored(): void
    {
        $alwaysFetchUser = ['SVCONF' => ['auth' => ['setup' => ['FE_alwaysFetchUser' => true]]]];
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        self::$instanceConfiguration = $alwaysFetchUser;
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => 'idp-session-1']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);
        $response = $this->executeFrontendSubRequest($this->loginRequest());
        $sessionCookies = $this->sessionCookiesHandedOutBy($response);
        self::assertCount(1, $sessionCookies);

        self::$instanceConfiguration = $alwaysFetchUser;
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => str_repeat('s', 256), 'sub' => 'subject-2']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse('subject-2'),
        ]);
        $response = $this->executeFrontendSubRequest($this->loginRequest()->withCookieParams(['fe_typo_user' => $sessionCookies[0]]));

        self::assertSame([], $this->authenticatedFrontendSessions());
        self::assertStringNotContainsString('logged-in=1', (string)$response->getBody());
        self::assertSame([], $this->sessionCookiesHandedOutBy($response));
    }

    /**
     * The association cannot be stored, and the session backend then fails to remove the session
     * the Core persisted for the login. A browser that held no session receives none.
     */
    #[Test]
    public function loginOfABrowserWithoutSessionIsNotEffectiveWhenNeitherAssociationNorSessionRemovalSucceeds(): void
    {
        $logFile = $this->instancePath . '/typo3temp/var/log/frontend-login-test.log';
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        self::$instanceConfiguration = [
            'SYS' => ['session' => ['FE' => ['backend' => TransientlyUnremovableSessionBackend::class]]],
            'LOG' => ['writerConfiguration' => [LogLevel::DEBUG => [FileWriter::class => ['logFile' => $logFile]]]],
        ];
        // The Core removes once while it creates the session of the login
        TransientlyUnremovableSessionBackend::$removalsBeforeTheStorageFails = 1;
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => str_repeat('s', 256)]),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $response = $this->executeFrontendSubRequest($this->loginRequest());

        self::assertStringNotContainsString('logged-in=1', (string)$response->getBody());
        self::assertSame([], $this->sessionCookiesHandedOutBy($response));
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
        // The session stays in the backend, under an identifier no browser was given
        $sessions = $this->authenticatedFrontendSessions();
        self::assertCount(1, $sessions);
        $logOutput = (string)file_get_contents($logFile);
        self::assertStringContainsString('its session could not be removed', $logOutput);
        self::assertStringNotContainsString('Session with id', $logOutput);
    }

    /**
     * The same failure for a browser that already holds the cookie of the session: with the Core
     * configured to always fetch the user, the login of another user re-creates the session of
     * the request under the identifier it had. The request goes on without a user and the
     * response removes the cookie, but the extension cannot guarantee that the session is gone
     * from the backend: a later request with the kept cookie is logged in.
     */
    #[Test]
    public function loginInAnExistingSessionKeepsItsBackendSessionWhenNeitherAssociationNorSessionRemovalSucceeds(): void
    {
        $logFile = $this->instancePath . '/typo3temp/var/log/frontend-login-test.log';
        $instanceConfiguration = [
            'SVCONF' => ['auth' => ['setup' => ['FE_alwaysFetchUser' => true]]],
            'SYS' => ['session' => ['FE' => ['backend' => TransientlyUnremovableSessionBackend::class]]],
            'LOG' => ['writerConfiguration' => [LogLevel::DEBUG => [FileWriter::class => ['logFile' => $logFile]]]],
        ];
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        self::$instanceConfiguration = $instanceConfiguration;
        TransientlyUnremovableSessionBackend::$removalsBeforeTheStorageFails = 1;
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => 'idp-session-1']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);
        $response = $this->executeFrontendSubRequest($this->loginRequest());
        $sessionCookies = $this->sessionCookiesHandedOutBy($response);
        self::assertCount(1, $sessionCookies);

        self::$instanceConfiguration = $instanceConfiguration;
        TransientlyUnremovableSessionBackend::$removalsBeforeTheStorageFails = 1;
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => str_repeat('s', 256), 'sub' => 'subject-2']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse('subject-2'),
        ]);
        // Only the log output of the failing login is examined
        file_put_contents($logFile, '');
        $response = $this->executeFrontendSubRequest($this->loginRequest()->withCookieParams(['fe_typo_user' => $sessionCookies[0]]));

        // The request is anonymous and the response removes the session cookie
        self::assertStringNotContainsString('logged-in=1', (string)$response->getBody());
        self::assertStringContainsString('groups=0,-1;', (string)$response->getBody());
        self::assertSame([], $this->sessionCookiesHandedOutBy($response));
        self::assertStringContainsString('fe_typo_user=deleted', $response->getHeaderLine('Set-Cookie'));
        // The failure is logged, without the session identifier the backend names in its message
        $logOutput = (string)file_get_contents($logFile);
        self::assertStringContainsString('its session could not be removed', $logOutput);
        self::assertStringNotContainsString('Session with id', $logOutput);
        self::assertStringNotContainsString($sessionCookies[0], $logOutput);

        // The accepted limitation (D21): the backend still holds the session under the identifier of the cookie,
        // and only the association of the earlier login is left.
        self::assertCount(1, $this->authenticatedFrontendSessions());
        self::assertSame(['idp-session-1'], array_column($this->getAllRecords('tx_oidc_backchannel_session'), 'sid'));

        // A browser that keeps sending the cookie although the response removed it stays logged in.
        self::$instanceConfiguration = $instanceConfiguration;
        $this->answerOutboundRequests([]);
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest(self::SITE_URL))->withCookieParams(['fe_typo_user' => $sessionCookies[0]])
        );
        self::assertStringContainsString('logged-in=1', (string)$response->getBody());
    }

    /**
     * An association exists only for a session the Core has persisted: where the Core fails to
     * persist the session of a login, nothing is left that the cleanup or a logout could act on.
     */
    #[Test]
    public function noAssociationIsStoredWhenTheCoreCannotPersistTheSessionOfTheLogin(): void
    {
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        self::$instanceConfiguration = ['SYS' => ['session' => ['FE' => ['backend' => UnwritableSessionBackend::class]]]];
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(['sid' => 'idp-session-1']),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $sessionWasNotCreated = false;
        try {
            $this->executeFrontendSubRequest($this->loginRequest());
        } catch (SessionNotCreatedException) {
            // The Core does not handle the failing session storage
            $sessionWasNotCreated = true;
        }

        self::assertTrue($sessionWasNotCreated);
        self::assertContains(self::ENDPOINT_TOKEN, $this->outboundRequestUrls);
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
        self::assertSame([], $this->frontendSessionBackend()->getAll());
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function coreSettingsAskingForAUserWithoutLoginRequest(): \Generator
    {
        yield 'FE_fetchUserIfNoSession' => ['FE_fetchUserIfNoSession'];
        yield 'FE_alwaysFetchUser' => ['FE_alwaysFetchUser'];
    }

    /**
     * With these Core settings the Core asks for a user on a request that is no login request.
     */
    #[Test]
    #[DataProvider('coreSettingsAskingForAUserWithoutLoginRequest')]
    public function requestWithoutLoginTypeDoesNotAuthenticateWhenBackChannelLogoutIsConfigured(string $setting): void
    {
        $this->configureProvider(self::BACK_CHANNEL_LOGOUT);
        self::$instanceConfiguration = ['SVCONF' => ['auth' => ['setup' => [$setting => true]]]];
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $response = $this->executeFrontendSubRequest(new InternalRequest(self::SITE_URL . '?tx_oidc[code]=authorization-code'));

        $this->assertLoginWasDenied();
        self::assertSame([], $this->outboundRequestUrls);
        self::assertStringNotContainsString('logged-in=1', (string)$response->getBody());
        self::assertSame([], $this->getAllRecords('fe_users'));
    }

    #[Test]
    #[DataProvider('coreSettingsAskingForAUserWithoutLoginRequest')]
    public function requestWithoutLoginTypeAuthenticatesAsBeforeWhenBackChannelLogoutIsNotConfigured(string $setting): void
    {
        $this->configureProvider();
        self::$instanceConfiguration = ['SVCONF' => ['auth' => ['setup' => [$setting => true]]]];
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $response = $this->executeFrontendSubRequest(new InternalRequest(self::SITE_URL . '?tx_oidc[code]=authorization-code'));

        self::assertCount(1, $this->authenticatedFrontendSessions());
        self::assertStringContainsString('logged-in=1', (string)$response->getBody());
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
    }

    /**
     * @return \Generator<string, array{array<string, string>}>
     */
    public static function incompleteBackChannelLogoutConfigurations(): \Generator
    {
        yield 'neither key' => [[]];
        yield 'issuer only' => [['issuer' => self::ISSUER]];
        yield 'endpointJwks only' => [['endpointJwks' => self::ENDPOINT_JWKS]];
    }

    /**
     * @param array<string, string> $provider
     */
    #[Test]
    #[DataProvider('incompleteBackChannelLogoutConfigurations')]
    public function loginSucceedsWithoutAssociationWhenBackChannelLogoutIsNotConfigured(array $provider): void
    {
        $this->configureProvider($provider);
        $this->answerOutboundRequests([
            self::ENDPOINT_TOKEN => $this->tokenResponse(),
            self::ENDPOINT_USERINFO => $this->userInfoResponse(),
        ]);

        $this->executeFrontendSubRequest($this->loginRequest());

        self::assertCount(1, $this->authenticatedFrontendSessions());
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
    }

    /**
     * @return list<string> The values of the frontend session cookies the response sets, without the ones it deletes
     */
    private function sessionCookiesHandedOutBy(ResponseInterface $response): array
    {
        $values = [];
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            $nameAndValue = explode('=', explode(';', $cookie)[0], 2);
            if ($nameAndValue[0] === 'fe_typo_user' && $nameAndValue[1] !== 'deleted') {
                $values[] = $nameAndValue[1];
            }
        }
        return $values;
    }

    private function assertLoginWasDenied(): void
    {
        self::assertSame([], $this->authenticatedFrontendSessions());
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
        self::assertNotContains(self::ENDPOINT_JWKS, $this->outboundRequestUrls);
    }
}
