<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional;

use Causal\Oidc\BackChannelLogout\SessionAssociation;
use Causal\Oidc\BackChannelLogout\SessionAssociationRepository;
use Causal\Oidc\Tests\Functional\Fixtures\FailingSessionBackend;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

final class BackChannelLogoutTest extends AbstractFunctionalTestBase
{
    private const LOGOUT_URL = self::SITE_URL . 'oidc/backchannel-logout';
    private const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /** @var array<string, ResponseInterface|\Throwable> */
    private array $answersByUrl = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureProvider(['issuer' => self::ISSUER, 'endpointJwks' => self::ENDPOINT_JWKS]);
        $this->answer(self::ENDPOINT_JWKS, $this->jwkSetResponse());
    }

    #[Test]
    public function validLogoutTokenEndsTheSessionAndDeletesItsAssociation(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertNull($this->frontendSession($sessionIdentifier));
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
        self::assertSame(3.0, (float)$this->outboundRequestOptions[self::ENDPOINT_JWKS]['connect_timeout']);
        self::assertSame(5.0, (float)$this->outboundRequestOptions[self::ENDPOINT_JWKS]['timeout']);
    }

    #[Test]
    public function logoutEndsEverySessionOfTheSidAndNoOtherSession(): void
    {
        $firstSession = $this->logIn('idp-session-1');
        $secondSession = $this->logIn('idp-session-1');
        $sessionOfAnotherSid = $this->logIn('idp-session-2');
        $this->frontendSessionBackend()->set('session-of-a-login-without-oidc', ['ses_userid' => 1, 'ses_data' => '']);

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertNull($this->frontendSession($firstSession));
        self::assertNull($this->frontendSession($secondSession));
        self::assertNotNull($this->frontendSession($sessionOfAnotherSid));
        self::assertNotNull($this->frontendSession('session-of-a-login-without-oidc'));
        self::assertSame(['idp-session-2'], array_column($this->getAllRecords('tx_oidc_backchannel_session'), 'sid'));
    }

    #[Test]
    public function repeatedNotificationIsAnsweredAsSuccessful(): void
    {
        $this->logIn('idp-session-1');

        $firstResponse = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));
        $secondResponse = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(200, $firstResponse);
        $this->assertLogoutWasAnsweredWith(200, $secondResponse);
    }

    #[Test]
    public function notificationForASidWithoutAssociationIsAnsweredAsSuccessful(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-unknown')));

        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertNotNull($this->frontendSession($sessionIdentifier));
    }

    /**
     * A session that expired or was logged out locally is gone from the session backend while
     * its association is still there. That is also what a second notification finds which
     * arrives after the first one removed the session and before it deleted the association.
     */
    #[Test]
    public function notificationForASessionTheBackendNoLongerHoldsIsAnsweredAsSuccessful(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $this->frontendSessionBackend()->remove($sessionIdentifier);

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertSame([], $this->getAllRecords('tx_oidc_backchannel_session'));
    }

    #[Test]
    public function sessionWriteOfARequestStillHoldingTheRemovedSessionDoesNotBringBackALoggedInSession(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $sessions = UserSessionManager::create('FE');
        $sessionHeldByARunningRequest = $sessions->createSessionFromStorage($sessionIdentifier);
        self::assertGreaterThan(0, $sessionHeldByARunningRequest->getUserId());

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        // The running request now writes its session data, the way the Core's frontend user does at the end of a request
        $sessions = UserSessionManager::create('FE');
        $sessionHeldByARunningRequest->set('written-after-logout', 'value');
        if ($sessions->isSessionPersisted($sessionHeldByARunningRequest)) {
            $sessions->updateSession($sessionHeldByARunningRequest);
        } else {
            $sessions->fixateAnonymousSession($sessionHeldByARunningRequest);
        }

        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertSame([], $this->authenticatedFrontendSessions());
    }

    #[Test]
    public function tokenWithAnInvalidSignatureEndsNoSession(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $associations = $this->getAllRecords('tx_oidc_backchannel_session');
        $token = $this->signToken($this->logoutTokenClaims('idp-session-1'), self::SIGNING_KEY_ID, 'key-of-somebody-else');

        $response = $this->executeFrontendSubRequest($this->logoutRequest($token));

        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertNotNull($this->frontendSession($sessionIdentifier));
        self::assertSame($associations, $this->getAllRecords('tx_oidc_backchannel_session'));
    }

    #[Test]
    public function postWithAWrongContentTypeIsRejected(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $request = $this->logoutRequest($this->logoutToken('idp-session-1'))->withHeader('Content-Type', 'application/json');

        $response = $this->executeFrontendSubRequest($request);

        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertNotNull($this->frontendSession($sessionIdentifier));
        self::assertNotContains(self::ENDPOINT_JWKS, $this->outboundRequestUrls);
    }

    /**
     * @return \Generator<string, array{array<string, mixed>}>
     */
    public static function malformedRequestBodies(): \Generator
    {
        yield 'no logout_token' => [['token' => 'value']];
        yield 'empty logout_token' => [['logout_token' => '']];
        yield 'logout_token not a string' => [['logout_token' => ['value']]];
        yield 'logout_token not a JWS' => [['logout_token' => 'not-a-token']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedRequestBodies')]
    public function malformedRequestIsRejectedWithoutAnOutboundRequest(array $body): void
    {
        $response = $this->executeFrontendSubRequest($this->logoutRequest('')->withParsedBody($body));

        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertSame([], $this->outboundRequestUrls);
    }

    #[Test]
    public function requestWithAnotherMethodIsNotAnsweredByTheEndpoint(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $associations = $this->getAllRecords('tx_oidc_backchannel_session');
        $request = $this->logoutRequest($this->logoutToken('idp-session-1'))->withMethod('GET');

        $response = $this->executeFrontendSubRequest($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertNotNull($this->frontendSession($sessionIdentifier));
        self::assertSame($associations, $this->getAllRecords('tx_oidc_backchannel_session'));
        self::assertNotContains(self::ENDPOINT_JWKS, $this->outboundRequestUrls);
    }

    #[Test]
    public function postToTheDefaultPathPassesThroughWhenBackChannelLogoutIsNotConfigured(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $associations = $this->getAllRecords('tx_oidc_backchannel_session');
        $this->configureProvider(['issuer' => self::ISSUER]);

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        self::assertSame(404, $response->getStatusCode());
        self::assertNotNull($this->frontendSession($sessionIdentifier));
        self::assertSame($associations, $this->getAllRecords('tx_oidc_backchannel_session'));
        self::assertNotContains(self::ENDPOINT_JWKS, $this->outboundRequestUrls);
    }

    #[Test]
    public function endpointAnswersAtTheConfiguredPathOnly(): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $this->configureProvider([
            'issuer' => self::ISSUER,
            'endpointJwks' => self::ENDPOINT_JWKS,
            'backChannelLogoutPath' => '/logout-notifications',
        ]);
        $token = $this->logoutToken('idp-session-1');

        $responseAtTheDefaultPath = $this->executeFrontendSubRequest($this->logoutRequest($token));
        self::assertSame(404, $responseAtTheDefaultPath->getStatusCode());
        self::assertNotNull($this->frontendSession($sessionIdentifier));

        $response = $this->executeFrontendSubRequest($this->logoutRequest($token, self::SITE_URL . 'logout-notifications'));
        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertNull($this->frontendSession($sessionIdentifier));
    }

    /**
     * @return \Generator<string, array{ResponseInterface|\Throwable}>
     */
    public static function failedKeyRetrievals(): \Generator
    {
        yield 'server error' => [new JsonResponse(['error' => 'unavailable'], 503)];
        yield 'timeout' => [new ConnectException('Connection timed out', new Request('GET', self::ENDPOINT_JWKS))];
        yield 'redirect' => [new RedirectResponse('https://idp.example/moved-jwks', 302)];
        yield 'body not JSON' => [new HtmlResponse('<html></html>')];
        yield 'body not a JWK Set' => [new JsonResponse(['error' => 'not_found'])];
    }

    #[Test]
    #[DataProvider('failedKeyRetrievals')]
    public function failedKeyRetrievalKeepsTheSession(ResponseInterface|\Throwable $jwksAnswer): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $this->answer(self::ENDPOINT_JWKS, $jwksAnswer);
        // Where a redirect was followed, the logout would succeed
        $this->answer('https://idp.example/moved-jwks', $this->jwkSetResponse());

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertNotNull($this->frontendSession($sessionIdentifier));
        self::assertCount(1, $this->getAllRecords('tx_oidc_backchannel_session'));
        self::assertNotContains('https://idp.example/moved-jwks', $this->outboundRequestUrls);
    }

    /**
     * @return \Generator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function untrustedTransports(): \Generator
    {
        yield 'non-HTTPS endpointJwks' => [['endpointJwks' => 'http://idp.example/jwks'], []];
        yield 'HTTP.verify disabled' => [[], ['HTTP' => ['verify' => false]]];
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $instanceConfiguration
     */
    #[Test]
    #[DataProvider('untrustedTransports')]
    public function keysAreNotRetrievedOverAnUntrustedTransport(array $provider, array $instanceConfiguration): void
    {
        $sessionIdentifier = $this->logIn('idp-session-1');
        $this->configureProvider(array_replace(['issuer' => self::ISSUER, 'endpointJwks' => self::ENDPOINT_JWKS], $provider));
        self::$instanceConfiguration = array_replace_recursive(self::$instanceConfiguration, $instanceConfiguration);
        $this->answer('http://idp.example/jwks', $this->jwkSetResponse());
        $this->outboundRequestUrls = [];

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertNotNull($this->frontendSession($sessionIdentifier));
        self::assertSame([], $this->outboundRequestUrls);
    }

    #[Test]
    public function associationThatCannotBeDecryptedIsPreservedAndFailsTheLogout(): void
    {
        $this->logIn('idp-session-1');
        $sessionOfAnIntactAssociation = $this->logIn('idp-session-1');
        $this->getConnectionPool()->getConnectionForTable('tx_oidc_backchannel_session')->insert(
            'tx_oidc_backchannel_session',
            ['sid' => 'idp-session-1', 'session_identifier' => 'not-a-stored-session-identifier']
        );

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertSame(
            [['sid' => 'idp-session-1', 'session_identifier' => 'not-a-stored-session-identifier']],
            $this->getAllRecords('tx_oidc_backchannel_session')
        );
        // The associations that can be decrypted are still processed
        self::assertNull($this->frontendSession($sessionOfAnIntactAssociation));
    }

    #[Test]
    public function failingSessionBackendKeepsTheAssociationAndIsLoggedWithoutTheSessionIdentifier(): void
    {
        $logFile = $this->instancePath . '/typo3temp/var/log/back-channel-logout-test.log';
        self::$instanceConfiguration['LOG']['writerConfiguration'][LogLevel::DEBUG][FileWriter::class] = ['logFile' => $logFile];
        $sessionIdentifier = $this->logIn('idp-session-1');
        $associations = $this->getAllRecords('tx_oidc_backchannel_session');
        self::$instanceConfiguration['SYS']['session']['FE']['backend'] = FailingSessionBackend::class;
        file_put_contents($logFile, '');

        $response = $this->executeFrontendSubRequest($this->logoutRequest($this->logoutToken('idp-session-1')));

        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertSame($associations, $this->getAllRecords('tx_oidc_backchannel_session'));
        $logOutput = (string)file_get_contents($logFile);
        self::assertStringContainsString('Back-Channel Logout failed', $logOutput);
        self::assertStringNotContainsString($sessionIdentifier, $logOutput);
    }

    #[Test]
    public function tokenSignedWithANewlyPublishedKeyIsAcceptedAndOneSignedWithAWithdrawnKeyRejected(): void
    {
        $firstSession = $this->logIn('idp-session-1');
        $secondSession = $this->logIn('idp-session-2');
        $thirdSession = $this->logIn('idp-session-3');
        $this->answer(self::ENDPOINT_JWKS, $this->jwkSetResponse('key-before-rotation'));
        $response = $this->executeFrontendSubRequest(
            $this->logoutRequest($this->signToken($this->logoutTokenClaims('idp-session-1'), 'key-before-rotation'))
        );
        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertNull($this->frontendSession($firstSession));

        $this->answer(self::ENDPOINT_JWKS, $this->jwkSetResponse('key-after-rotation'));

        $response = $this->executeFrontendSubRequest(
            $this->logoutRequest($this->signToken($this->logoutTokenClaims('idp-session-2'), 'key-after-rotation'))
        );
        $this->assertLogoutWasAnsweredWith(200, $response);
        self::assertNull($this->frontendSession($secondSession));

        $response = $this->executeFrontendSubRequest(
            $this->logoutRequest($this->signToken($this->logoutTokenClaims('idp-session-3'), 'key-before-rotation'))
        );
        $this->assertLogoutWasAnsweredWith(400, $response);
        self::assertNotNull($this->frontendSession($thirdSession));
    }

    #[Test]
    public function logOutputOfLogoutProcessingCarriesNeitherTheLogoutTokenNorASessionIdentifier(): void
    {
        $logFile = $this->instancePath . '/typo3temp/var/log/back-channel-logout-test.log';
        self::$instanceConfiguration['LOG']['writerConfiguration'][LogLevel::DEBUG][FileWriter::class] = ['logFile' => $logFile];
        $endedSession = $this->logIn('idp-session-1');
        $keptSession = $this->logIn('idp-session-2');
        $this->logIn('idp-session-3');
        $this->getConnectionPool()->getConnectionForTable('tx_oidc_backchannel_session')->update(
            'tx_oidc_backchannel_session',
            ['session_identifier' => 'not-a-stored-session-identifier'],
            ['sid' => 'idp-session-3']
        );
        // Only the log output of logout processing is examined
        file_put_contents($logFile, '');

        $acceptedToken = $this->logoutToken('idp-session-1');
        $rejectedToken = $this->signToken($this->logoutTokenClaims('idp-session-2'), self::SIGNING_KEY_ID, 'key-of-somebody-else');
        $failingToken = $this->logoutToken('idp-session-3');
        $this->executeFrontendSubRequest($this->logoutRequest($acceptedToken));
        $this->executeFrontendSubRequest($this->logoutRequest($rejectedToken));
        $this->executeFrontendSubRequest($this->logoutRequest($failingToken));

        $logOutput = (string)file_get_contents($logFile);
        self::assertStringContainsString('Back-Channel Logout', $logOutput);
        foreach ([$acceptedToken, $rejectedToken, $failingToken] as $token) {
            self::assertStringNotContainsString($token, $logOutput);
            // Neither the token as a whole nor its payload or signature
            self::assertStringNotContainsString(explode('.', $token)[1], $logOutput);
            self::assertStringNotContainsString(explode('.', $token)[2], $logOutput);
        }
        self::assertStringNotContainsString($endedSession, $logOutput);
        self::assertStringNotContainsString($keptSession, $logOutput);
    }

    private function assertLogoutWasAnsweredWith(int $status, ResponseInterface $response): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', (string)$response->getBody());
    }

    /**
     * @param ResponseInterface|\Throwable $answer A throwable is the transport failure of the request
     */
    private function answer(string $url, ResponseInterface|\Throwable $answer): void
    {
        $this->answersByUrl[$url] = $answer;
        $this->answerOutboundRequests($this->answersByUrl);
    }

    /**
     * Logs a user in through the fork, in a browser of its own.
     *
     * @return string The identifier of the frontend session the login created
     */
    private function logIn(string $sid): string
    {
        $knownSessionIdentifiers = $this->associatedSessionIdentifiers($sid);
        $this->answer(self::ENDPOINT_TOKEN, $this->tokenResponse(['sid' => $sid]));
        $this->answer(self::ENDPOINT_USERINFO, $this->userInfoResponse());

        $this->executeFrontendSubRequest($this->loginRequest());

        $newSessionIdentifiers = array_values(array_diff($this->associatedSessionIdentifiers($sid), $knownSessionIdentifiers));
        self::assertCount(1, $newSessionIdentifiers);
        self::assertNotNull($this->frontendSession($newSessionIdentifiers[0]));
        return $newSessionIdentifiers[0];
    }

    /**
     * @return list<string>
     */
    private function associatedSessionIdentifiers(string $sid): array
    {
        return array_map(
            static fn(SessionAssociation $association): string => (string)$association->sessionIdentifier,
            $this->get(SessionAssociationRepository::class)->findBySid($sid)
        );
    }

    /**
     * @return array<string, mixed>|null The session record, or null when the session backend does not hold the session
     */
    private function frontendSession(string $sessionIdentifier): ?array
    {
        try {
            return $this->frontendSessionBackend()->get($sessionIdentifier);
        } catch (SessionNotFoundException) {
            return null;
        }
    }

    private function logoutToken(string $sid): string
    {
        return $this->signToken($this->logoutTokenClaims($sid));
    }

    /**
     * @return array<string, mixed>
     */
    private function logoutTokenClaims(string $sid): array
    {
        return [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT,
            'iat' => time(),
            'exp' => time() + 120,
            'jti' => bin2hex(random_bytes(8)),
            'events' => [self::LOGOUT_EVENT => new \stdClass()],
            'sid' => $sid,
        ];
    }

    private function logoutRequest(string $logoutToken, string $url = self::LOGOUT_URL): InternalRequest
    {
        return (new InternalRequest($url))
            ->withMethod('POST')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['logout_token' => $logoutToken]);
    }
}
