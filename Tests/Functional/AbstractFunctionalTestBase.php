<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Functional;

use Firebase\JWT\JWT;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Session\Backend\SessionBackendInterface;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A TYPO3 instance with one site, one frontend user group and an identity provider that exists
 * only as answers to the outbound HTTP requests of the instance.
 */
abstract class AbstractFunctionalTestBase extends FunctionalTestCase
{
    protected const SITE_URL = 'https://website.example/';
    protected const CLIENT = 't3ext-oidc';
    protected const ISSUER = 'https://idp.example/realms/test';
    protected const ENDPOINT_TOKEN = 'https://idp.example/token';
    protected const ENDPOINT_USERINFO = 'https://idp.example/userinfo';
    protected const ENDPOINT_JWKS = 'https://idp.example/jwks';
    protected const SIGNING_KEY_ID = 'test-key';

    /**
     * Merged into TYPO3_CONF_VARS by Fixtures/additional.php whenever the instance boots.
     *
     * @var array<string, mixed>
     */
    public static array $instanceConfiguration = [];

    private static ?\OpenSSLAsymmetricKey $signingKey = null;

    // Both are requirements of the extension; the instance does not boot without them.
    protected array $coreExtensionsToLoad = ['install', 'lowlevel'];

    protected array $testExtensionsToLoad = ['causal/oidc'];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/oidc/Tests/Functional/Fixtures/oidc.yaml' => 'typo3conf/system/oidc.yaml',
        'typo3conf/ext/oidc/Tests/Functional/Fixtures/additional.php' => 'typo3conf/system/additional.php',
    ];

    protected array $configurationToUseInTestInstance = [
        // Denied logins would otherwise lock the login for the tests that follow.
        'FE' => ['loginRateLimit' => 0],
    ];

    /** @var list<string> */
    protected array $outboundRequestUrls = [];

    protected function setUp(): void
    {
        self::$instanceConfiguration = [];
        parent::setUp();

        $connectionPool = $this->getConnectionPool();
        $connectionPool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/']);
        $connectionPool->getConnectionForTable('fe_groups')->insert('fe_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Users']);
        $this->setUpFrontendRootPage(1, [], ['config' => "page = PAGE\npage.10 = TEXT\npage.10.value = Root"]);
        GeneralUtility::mkdir_deep($this->instancePath . '/typo3conf/sites/website');
        file_put_contents(
            $this->instancePath . '/typo3conf/sites/website/config.yaml',
            Yaml::dump([
                'rootPageId' => 1,
                'base' => self::SITE_URL,
                'languages' => [[
                    'languageId' => 0,
                    'title' => 'English',
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'enabled' => true,
                ]],
            ])
        );
    }

    protected function tearDown(): void
    {
        self::$instanceConfiguration = [];
        parent::tearDown();
    }

    /**
     * Writes the provider configuration of the instance: a frontend provider for the test
     * identity provider, with the given keys added or replaced.
     *
     * @param array<string, mixed> $provider
     */
    protected function configureProvider(array $provider = []): void
    {
        $configuration = [
            'providers' => [
                'test' => array_replace([
                    'enableFrontendAuthentication' => 1,
                    'clientKey' => self::CLIENT,
                    'clientSecret' => 't3ext-oidc-secret',
                    'endpointAuthorize' => 'https://idp.example/authorize',
                    'endpointToken' => self::ENDPOINT_TOKEN,
                    'endpointUserInfo' => self::ENDPOINT_USERINFO,
                    'usersDefaultGroup' => '1',
                    'mapping' => ['fe_users' => ['name' => '<name>']],
                ], $provider),
            ],
        ];
        file_put_contents($this->instancePath . '/typo3conf/system/oidc.yaml', Yaml::dump($configuration, 4));
    }

    /**
     * Answers the outbound HTTP requests of the instance through TYPO3's HTTP.handler
     * mechanism. A request to a URL without an answer fails, so no request leaves the process.
     *
     * @param array<string, ResponseInterface> $answersByUrl
     */
    protected function answerOutboundRequests(array $answersByUrl): void
    {
        self::$instanceConfiguration['HTTP']['handler']['oidc-functional-test']
            = fn(): \Closure => function (RequestInterface $request) use ($answersByUrl): PromiseInterface {
                $url = (string)$request->getUri();
                $this->outboundRequestUrls[] = $url;
                if (!isset($answersByUrl[$url])) {
                    throw new \RuntimeException('Unexpected outbound request to ' . $url . ' (f41c1271-9953-4d50-b124-3d08ba464a94)');
                }
                return Create::promiseFor($answersByUrl[$url]);
            };
    }

    /**
     * @param array<string, mixed> $idTokenClaims Added to or replacing the claims of a valid ID token; a null value removes a claim
     */
    protected function tokenResponse(array $idTokenClaims = []): ResponseInterface
    {
        $claims = array_replace([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT,
            'sub' => 'subject-1',
            'iat' => time(),
            'exp' => time() + 300,
            'sid' => 'idp-session-1',
        ], $idTokenClaims);
        $claims = array_filter($claims, static fn(mixed $value): bool => $value !== null);

        return new JsonResponse([
            'access_token' => 'access-token-1',
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'id_token' => $this->signToken($claims),
        ]);
    }

    protected function userInfoResponse(string $sub = 'subject-1'): ResponseInterface
    {
        return new JsonResponse(['sub' => $sub, 'name' => 'Test User']);
    }

    /**
     * Signs a token with RSA key material that exists only for this test run.
     *
     * @param array<string, mixed> $claims
     */
    protected function signToken(array $claims): string
    {
        self::$signingKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        return JWT::encode($claims, self::$signingKey, 'RS256', self::SIGNING_KEY_ID);
    }

    protected function loginRequest(): InternalRequest
    {
        return new InternalRequest(self::SITE_URL . '?logintype=login&tx_oidc[code]=authorization-code');
    }

    protected function frontendSessionBackend(): SessionBackendInterface
    {
        return GeneralUtility::makeInstance(SessionManager::class)->getSessionBackend('FE');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function authenticatedFrontendSessions(): array
    {
        return array_values(array_filter(
            $this->frontendSessionBackend()->getAll(),
            static fn(array $session): bool => (int)$session['ses_userid'] > 0
        ));
    }
}
