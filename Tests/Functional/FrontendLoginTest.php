<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional;

use Causal\Oidc\BackChannelLogout\SessionAssociationRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\JsonResponse;

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

        $this->executeFrontendSubRequest($this->loginRequest());

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

        $this->executeFrontendSubRequest($this->loginRequest());

        $this->assertLoginWasDenied();
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

    private function assertLoginWasDenied(): void
    {
        self::assertSame([], $this->authenticatedFrontendSessions());
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
        self::assertNotContains(self::ENDPOINT_JWKS, $this->outboundRequestUrls);
    }
}
