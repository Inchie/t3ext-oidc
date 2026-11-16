TRUNCATE pages;
TRUNCATE tt_content;
TRUNCATE sys_template;
TRUNCATE fe_groups;
TRUNCATE be_groups;

# Users created by EXT:oidc must be member of at least one group,
# see "usersDefaultGroup" in config/system/oidc.yaml
INSERT INTO fe_groups SET
    uid = 1,
    pid = 2,
    title = "OIDC users"
;

INSERT INTO be_groups SET
    uid = 1,
    pid = 0,
    title = "OIDC users"
;

# Backend users must be located on pid 0, whereas EXT:oidc creates new users in the first
# configured storage pid. Hence the backend user for "User1" (sub = 1) of the mock IdP is provided upfront.
INSERT INTO be_users SET
    uid = 100,
    pid = 0,
    username = "oidc-user1",
    usergroup = "1",
    tx_oidc = "1"
;

INSERT INTO pages SET
    uid = 1,
    pid = 0,
    title = "oidc",
    is_siteroot = 1,
    slug = "/",
    doktype = 1
;

INSERT INTO pages
SET
    uid = 2,
    pid = 1,
    title = "Frontend Users",
    slug = "/frontend-users/",
    doktype = 254,
    module = "fe_users",
		sorting = 16
;

INSERT INTO `tt_content` (`uid`, `pid`, `CType`, `header`, `pi_flexform`)
VALUES
    ('1', '1', 'felogin_login', 'Login', '<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\" ?>\n<T3FlexForms>\n    <data>\n        <sheet index=\"sDEF\">\n            <language index=\"lDEF\">\n                <field index=\"settings.showForgotPassword\">\n                    <value index=\"vDEF\">0</value>\n                </field>\n                <field index=\"settings.showPermaLogin\">\n                    <value index=\"vDEF\">1</value>\n                </field>\n                <field index=\"settings.showLogoutFormAfterLogin\">\n                    <value index=\"vDEF\">0</value>\n                </field>\n                <field index=\"settings.pages\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.recursive\">\n                    <value index=\"vDEF\"></value>\n                </field>\n            </language>\n        </sheet>\n        <sheet index=\"s_redirect\">\n            <language index=\"lDEF\">\n                <field index=\"settings.redirectMode\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.redirectFirstMethod\">\n                    <value index=\"vDEF\">0</value>\n                </field>\n                <field index=\"settings.redirectPageLogin\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.redirectPageLoginError\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.redirectPageLogout\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.redirectDisable\">\n                    <value index=\"vDEF\">0</value>\n                </field>\n            </language>\n        </sheet>\n        <sheet index=\"s_messages\">\n            <language index=\"lDEF\">\n                <field index=\"settings.welcome_header\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.welcome_message\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.success_header\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.success_message\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.error_header\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.error_message\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.status_header\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.status_message\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.logout_header\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.logout_message\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.forgot_header\">\n                    <value index=\"vDEF\"></value>\n                </field>\n                <field index=\"settings.forgot_reset_message\">\n                    <value index=\"vDEF\"></value>\n                </field>\n            </language>\n        </sheet>\n    </data>\n</T3FlexForms>');
;

INSERT INTO pages SET
		uid = 3,
    pid = 1,
    title = "Login",
    slug = "/login/",
    doktype = 1,
    sorting = 1
;

INSERT INTO `tt_content` (`uid`, `pid`, `CType`, `header`)
VALUES
	('2', '3', 'oidc_login', 'Login');
;

INSERT INTO pages SET
    uid = 4,
    pid = 1,
    title = "Login Redirect Target",
    slug = "/login-redirect-target/",
    doktype = 1,
    sorting = 8
;
