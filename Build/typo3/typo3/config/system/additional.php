<?php

(function () {
    // configure TYPO3
    $GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive($GLOBALS['TYPO3_CONF_VARS'], [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'dbname' => getenv('TYPO3_DB_DBNAME'),
                    'host' => getenv('TYPO3_DB_HOST'),
                    'password' => getenv('TYPO3_DB_PASSWORD'),
                    'port' => getenv('TYPO3_DB_PORT'),
                    'user' => getenv('TYPO3_DB_USERNAME'),
                ],
            ],
        ],
        'GFX' => [
            'processor_path' => getenv('TYPO3_GFX_PROCESSOR_PATH'),
            'processor_path_lzw' => getenv('TYPO3_GFX_PROCESSOR_PATH_LZW'),
        ],
    ]);

    // SMTP mailserver
    if (getenv('TYPO3_SMTP_HOST')) {
        $port = getenv('TYPO3_SMTP_PORT') ? (int)getenv('TYPO3_SMTP_PORT') : 25;
        $server = sprintf('%s:%s', getenv('TYPO3_SMTP_HOST'), $port);

        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = array_replace($GLOBALS['TYPO3_CONF_VARS']['MAIL'], [
            'transport' => 'smtp',
            'transport_smtp_server' => $server,
        ]);

        $properties = ['encrypt', 'username', 'password'];
        foreach ($properties as $property) {
            $envVariableName = 'TYPO3_SMTP_' . strtoupper($property);
            $configurationKey = 'transport_smtp_' . strtolower($property);

            $envValue = getenv($envVariableName);
            if ($envValue !== false) {
                $GLOBALS['TYPO3_CONF_VARS']['MAIL'][$configurationKey] = $envValue;
            }
        }
    }

    // install tool
    if (getenv('TYPO3_INSTALL_TOOL_PASSWORD')) {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['installToolPassword'] = getenv('TYPO3_INSTALL_TOOL_PASSWORD');
    }

    // Development configuration
    if (\TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment()) {
        foreach (glob(TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/../configuration/*.php') as $configurationFile) {
            require_once($configurationFile);
        }
    }

    $GLOBALS['TYPO3_CONF_VARS']['BE']['passwordPolicy'] = '';

    // The backend login via OIDC returns from the IdP (another site) with a chain of redirects.
    // Browsers do not send the backend session cookie within that chain, if it is set to "strict" (TYPO3 default).
    $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax';

    // Write the debug log of EXT:oidc to var/log/typo3_oidc_*.log to ease the analysis of failing acceptance tests
    $GLOBALS['TYPO3_CONF_VARS']['LOG']['Causal']['Oidc']['writerConfiguration'] = [
        \Psr\Log\LogLevel::DEBUG => [
            \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                'logFileInfix' => 'oidc',
            ],
        ],
    ];
})();
