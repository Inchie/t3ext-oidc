# OpenID Connect integration for TYPO3

## Introduction

Using this TYPO3 extension enables your TYPO3 instance to use external identity providers (IdPs)
to authenticate frontend and/or backend users using the OpenID Connect protocol.

This allows to leverage Single-Sign-On (SSO) scenarios.

After installing and configuring the extension, users will have a dedicated button
to login via the configured IdP and will be returned to TYPO3 with an active session.

Various configuration options allow to map information retrieved from the IdP to
the TYPO3 user record. For instance: Name, contact data, groups/roles

## Identity provider examples

This is an incomplete list of well-known identity providers:

- Keycloak
- Authentik
- WSO2 Identity Server
- Microsoft EntraID
- Google
- GitHub
- Gitlab
- ID Austria

## Usage for frontend

### Next to ext:felogin

The login can be triggered by a button, which can be placed next to your existing
felogin form. So you can use both login-mechanisms in parallel.

After the login process, the user will be redirected according to the rules
defined in the felogin plugin on the same page.

### Direct OIDC Login

If OpenID Connect is your only means of frontend login, you can use the included
"OIDC Login" plugin. Add it to your login page, where you would normally put the
felogin form. After adding the OIDC Login plugin, requests to the login page will
immediately be redirected to the identity provider.

After the login process, the user will be redirected:

- The OIDC Login supports the same `redirect_url` parameter as the felogin box
- If no parameter is set, OIDC Login will redirect the user to the page
  configured at `plugin.tx_oidc_login.defaultRedirectPid`.
- If that configuration is not set either, the user will be redirected to '/'.

## Usage for backend

If enabled, the extension provides a login provider for backend of the TYPO3 instance.

## Configuration

Configuration is done through a YAML file located in the TYPO3 system config directory : `config/system/oidc.yaml`

The extension requires at least a configuration for how user information from the IdP
is mapped to TYPO3 user records fe_users/be_users.

  ```yaml
authenticationServicePriority: 82
authenticationServiceQuality: 80
authenticationUrlRoute: oidc/authentication

providers:
  default:
    authorizeLanguageParameter: language
    backChannelLogoutPath: /oidc/backchannel-logout
    clientKey: '%env(TYPO3_OIDC_CLIENT_KEY)%'
    clientScopeSeparator: '%env(TYPO3_OIDC_ENABLE_CODE_VERIFIER)%'
    clientScopes: '%env(TYPO3_OIDC_CLIENT_SCOPES)%'
    clientSecret: '%env(TYPO3_OIDC_CLIENT_SECRET)%'
    disableCSRFProtection: 0
    enableBackendAuthentication: 1
    enableCodeVerifier: '%env(TYPO3_OIDC_ENABLE_CODE_VERIFIER)%'
    enableFrontendAuthentication: 0
    enablePasswordCredentials: 0
    endpointAuthorize: '%env(TYPO3_OIDC_ENDPOINT_AUTHORIZE)%'
    endpointJwks: '%env(TYPO3_OIDC_ENDPOINT_JWKS)%'
    endpointLogout: '%env(TYPO3_OIDC_ENDPOINT_LOGOUT)%'
    endpointRevoke: '%env(TYPO3_OIDC_ENDPOINTREVOKE)%'
    endpointToken: '%env(TYPO3_OIDC_ENDPOINT_TOKEN)%'
    endpointUserInfo: '%env(TYPO3_OIDC_ENDPOINT_USERINFO)%'
    frontendUserMustExistLocally: 0
    issuer: '%env(TYPO3_OIDC_ISSUER)%'
    mapping: []
    oauthProviderFactory:
    reEnableFrontendUsers: 0
    redirectUri: '%env(TYPO3_OIDC_REDIRECT_URI)%'
    revokeAccessTokenAfterLogin: 0
    undeleteFrontendUsers: 0
    useRequestPathAuthentication: 0
    usersDefaultGroup:
    usersStoragePid: 0
  ```

### Mapping user fields

- Configuration is done through YAML within the keys
  ```yaml
    mapping:
      be_users:
        realName: '<name> // <family_name> <given_name> // <email>'
      fe_users:
        name: '<name> // <family_name> <given_name> // <email>'
  ```
- Information about the user from OIDC attributes (ID Token or UserInfo-endpoint) will be recognized by the specific characters `<>`:

  ```yaml
  email: "<mail>"
  ```

- You may combine multiple markers as well, e.g.,

  ```yaml
  name: "<family_name>, <given_name>"
  ```

- Support for [TypoScript data fallback](https://docs.typo3.org/permalink/t3tsref:data-type-gettext)
  (`//`). This will check multiple field names and return the first one yielding
  some non-empty value. E.g.,

  ```yaml
  username: "<sub> // <contact_number> // <emailaddress> // <benutzername>"
  ```

### Mapping user groups

It is possible to assign TYPO3 user groups to a user based on the `Roles` attribute
sent via OIDC.

Steps to follow:

- Create your groups within TYPO3
- Use the additional pattern-field within the group record to relate each group to Roles within OpenID Connect
- Local TYPO3 groups (not related to some role) will not be added or removed from a user record
- Default TYPO3 group(s) as configured in the extension's global configuration will always be added

### OIDC Login plugin TypoScript configuration

- `plugin.tx_oidc_login.defaultRedirectPid` UID of the page that users will be
  redirected to, if no `redirect_url` parameter is set with the request.

### PKCE (Proof of Key for Code Exchange)

If your IdP supports _Proof of Key for Code Exchange_ you can enable it
by setting `enableCodeVerifier` in the extension configuration. A shared secret
will be sent along preventing _Authorization Code Interception Attacks_. See
[RFC7636](https://tools.ietf.org/html/rfc7636) for details.

## Back-Channel Logout

The extension implements the relying-party side of
[OpenID Connect Back-Channel Logout 1.0](https://openid.net/specs/openid-connect-backchannel-1_0.html):
when the identity provider reports that one of its sessions ended, the TYPO3 frontend
sessions that were created from that session are removed immediately. Backend sessions,
frontend logins through other mechanisms and the sessions of other identity-provider
sessions are never touched.

### Configuration

Three provider keys in `config/system/oidc.yaml`:

| Key | Default | Meaning |
|---|---|---|
| `issuer` | empty | The issuer identifier of the identity provider, compared verbatim with the `iss` claim. |
| `endpointJwks` | empty | The URL of the identity provider's JWK Set. No discovery document is read. |
| `backChannelLogoutPath` | `/oidc/backchannel-logout` | The absolute URL path of the logout endpoint. |

Back-Channel Logout is switched on exactly when `issuer` and `endpointJwks` are both set.
With neither or only one of them, the extension behaves as without the feature.

The database schema must be updated once: the extension stores which TYPO3 session belongs to
which identity-provider session (`sid`) in the table `tx_oidc_backchannel_session`. The TYPO3
session identifier is stored encrypted, under a key derived from the TYPO3 `encryptionKey`.
The PHP extensions `sodium` and `openssl` are required.

While Back-Channel Logout is switched on, a frontend user is authenticated through OpenID
Connect only on a login request, that is a request carrying `logintype=login`. The login link,
the OIDC Login plugin and a felogin form send it. A frontend login is then denied unless

- `endpointToken` and `endpointJwks` are `https` URLs and certificate validation
  (`$GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify']`) is not disabled,
- the token response carries an ID token whose `iss` is the configured `issuer`, whose `aud`
  names only the configured `clientKey`, which has not expired and which carries a `sid`,
- where `endpointUserInfo` is configured, the `sub` it returns equals the `sub` of the ID token,
- the session association could be stored.

Backend logins are not affected.

### Logout endpoint

The identity provider sends a `POST` request with the content type
`application/x-www-form-urlencoded` and the parameter `logout_token` to
`backChannelLogoutPath` on the TYPO3 frontend. The signed logout token is the only credential.
A request to that path with another method, a `GET` included, is not answered by the endpoint.

The logout token must be signed with `RS256` by a key of the JWK Set at `endpointJwks`, name
its key by `kid`, and carry `iss` (the configured `issuer`), `aud` (containing the configured
`clientKey`), `iat`, `exp`, `jti`, the back-channel logout event in `events`, a `sid` and no
`nonce`. No leeway is applied to the time claims. A logout token without `sid` ends no session.

The answer has no body, carries `Cache-Control: no-store` and one of two status codes:

- `200`: the sessions of the `sid` were removed, or there was none left to remove (unknown
  `sid`, repeated notification, session already expired or logged out in TYPO3).
- `400`: the request or the logout token is invalid, the keys could not be retrieved, or a
  session could not be removed. The reason is written to the log, without the token and
  without any session identifier.

### Cleanup command

A session that expires or is logged out in TYPO3 leaves its association behind. The command

```bash
vendor/bin/typo3 oidc:backchannel:cleanup
```

removes the associations whose frontend session no longer exists. An association is kept when
its session still exists, when the session backend could not be asked, and when its stored
value cannot be decrypted (for example after the `encryptionKey` changed); the latter two are
logged. The command takes no arguments and also runs while Back-Channel Logout is switched off.

It is meant to run periodically, through TYPO3's scheduling of console commands: the
Scheduler task "Execute console commands". The extension schedules nothing by itself.

### Known limitations

- The JWK Set is requested from `endpointJwks` for every logout notification and is not
  cached. A logout therefore depends on that endpoint being reachable and answering within 5
  seconds with a JWK Set that can be read as a whole; otherwise the notification is answered
  with `400` and the session stays.
- Back-Channel Logout is event-driven. There is no continuous synchronisation between the
  sessions of the identity provider and those of TYPO3: a notification that TYPO3 does not
  receive ends no session, and nothing is retried.
- A logout notification that arrives while a login is in progress, between the retrieval of
  the token and the storing of the session association, is lost: the TYPO3 session of that
  login stays.

- A login is ended when its session association cannot be stored. If the session backend then
  also fails to remove the session of that login, the request continues without a user and the
  session cookie is removed, but the session stays in the session backend: a client that
  already held the cookie of that session and keeps sending it remains logged in until the
  session expires or is removed. The failure is logged.

### Integration checklist

The feature is covered by automated tests against a simulated identity provider. Every
installation has to be verified against its real identity provider; this list is the
checklist for that.

Identity provider:

- [ ] The back-channel logout URL (scheme and host of the TYPO3 frontend followed by
      `backChannelLogoutPath`) is registered for the client.
- [ ] Logout tokens and ID tokens carry `sid`.
- [ ] Tokens are signed with `RS256`.
- [ ] TYPO3 and the identity provider reach each other over HTTPS with trusted certificates.
- [ ] The clocks of both systems are synchronised.
- [ ] A logout in an upstream system ends the session at the identity provider.

TYPO3:

- [ ] `issuer` and `endpointJwks` are configured.
- [ ] Certificate validation is enabled.
- [ ] The PHP extensions `sodium` and `openssl` are loaded.
- [ ] The database schema is updated.
- [ ] The cleanup command is scheduled.

Checks:

- [ ] A login creates a session association.
- [ ] An upstream logout ends the TYPO3 session immediately; the notification is answered
      with `200`.
- [ ] A logout directly at the identity provider ends the TYPO3 session immediately; the
      notification is answered with `200`.
- [ ] Only the TYPO3 sessions of the logged-out identity-provider session end, and all of them.
- [ ] A logout after a local TYPO3 logout is answered with `200`.
- [ ] Logins through other mechanisms are unaffected.
- [ ] Logout works after the identity provider rotated its signing key.
- [ ] With an unreachable `endpointJwks` the notification is answered with `400` and the
      session is kept.
- [ ] The cleanup command removes only the associations of sessions that no longer exist.
- [ ] With Back-Channel Logout not configured, login works as before.

## Logging

For debugging purposes it may be helpful to enable DEBUG-level logging.
In addition, rerouting all log lines to a dedicated file is also recommended.

The following instructions in your sitepackage's `ext_localconf.php`
or global `config/system/additional.php` file will configure the system
appropriately:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Causal']['Oidc']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
            'logFileInfix' => 'oidc'
        ],
    ],
];
```

**Hint:** Be sure to read
[Configuration of the Logging system](https://docs.typo3.org/permalink/t3coreapi:logging-configuration)
to fine-tune your configuration on any production website.

## Integrating your identity provider with specific packages

This extension uses an underlying PHP library for OAuth2, which can be extended for specific
identity providers by adding additional packages.

Example: For Microsoft EntraID (Azure) the package is [thenetworg/oauth2-azure](https://packagist.org/packages/thenetworg/oauth2-azure)

In order to use these kinds of packages, one needs to implement a custom
`OAuth2ProviderFactory`, which takes care of initializing the specific provider.

Here is an example for the aforementioned Azure package.

Register your custom factory class in the extension configuration `oauthProviderFactory = \Reelworx\Sitesetup\Authentication\OAuth2ProviderFactory`.
Put this class into your site-package, typically in the file `packages/<sitepackage>/Classes/Autentication/OAuth2ProviderFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Reelworx\Sitesetup\Authentication;

use Causal\Oidc\Factory\OAuthProviderFactoryInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use TheNetworg\OAuth2\Client\Provider\Azure;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/* requires some ENV variables to be set, see below */
final class OAuth2ProviderFactory implements OAuthProviderFactoryInterface
{
    public function create(array $settings): AbstractProvider
    {
        $options = [
            'clientId' => $settings['oidcClientKey'],
            'redirectUri' => $settings['oidcRedirectUri'],
            'urlAuthorize' => $settings['oidcEndpointAuthorize'],
            'urlAccessToken' => $settings['oidcEndpointToken'],
            'urlResourceOwnerDetails' => $settings['oidcEndpointUserInfo'],
            'scopes' => GeneralUtility::trimExplode(',', $settings['oidcClientScopes'], true),
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
            'tenant' => getenv('AZURE_OAUTH_CLIENT_TENANT'),
        ];
        if ($settings['oidcClientSecret']) {
            $options['clientSecret'] = $settings['oidcClientSecret'];
        } else {
            // https://learn.microsoft.com/en-us/entra/identity-platform/certificate-credentials
            // PEM certificate (newline potentially encoded as '\n'
            $options['clientCertificatePrivateKey'] = getenv('AZURE_OAUTH_CLIENT_CERTIFICATE');
            // SHA-1 thumbprint of the X.509 certificate's DER encoding.
            $options['clientCertificateThumbprint'] = getenv('AZURE_OAUTH_CLIENT_CERTIFICATE_THUMBPRINT');
        }
        return new Azure($options);
    }
}
```

## Developer information

For customization purposes this extension provides 8 events.
Please lookup their usage within the code to identify the right event(s) matching your requirements.

## Maintainer's corner

### Running acceptance tests

The `Build` folder contains a docker compose test environment for this oidc extension. It contains:
* TYPO3 v13 instance with ext-oidc installed
* TYPO3 v14 instance with ext-oidc installed
* mock oidc server
* Playwright test runner to run acceptance tests
* VNC Server to watch the playwright tests

To build the test environment and run the playwright tests run the following command:
```bash
cd Build
docker compose up --build --exit-code-from playwright && echo "Success" || echo "Fail"
```

The PHP version of the TYPO3 containers defaults to 8.5 and can be changed with the `PHP_VERSION` environment variable:
```bash
PHP_VERSION=8.2 docker compose up --build --exit-code-from playwright && echo "Success" || echo "Fail"
```

## Reference

### Implementation concept

This extension builds a wrapper around the `league/oauth2-client` PHP library.
It provides TYPO3 [AuthenticationServices](https://docs.typo3.org/permalink/t3coreapi:authentication-service)
which integrate the authentication with frontend and backend.

In order to encapsulate and store the "state" parameter recommended for the request towards the IdP,
the extensions creates a JWT stored in a cookie. This allows for state-less authentication flow,
helpful for multi-server setups, where shared PHP-sessions are hard to implement.
(Note the distinction between PHP- and anonymous TYPO3-session here. During the authentication phase
there is no TYPO3-session available yet, so the "state" would have to be stored within a PHP-session.)

### Specification

https://openid.net/specs/openid-connect-core-1_0.html

## Credits

This TYPO3 extension is created and maintained by:
 - Xavier Perseguers (https://www.causal.ch/)
 - Markus Klein (https://reelworx.at/)

A big "Thanks" goes out to all contributors.
