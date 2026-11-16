import { test, expect } from '@playwright/test';

// see \Causal\Oidc\LoginProvider\OidcLoginProvider::IDENTIFIER
const loginProvider = '1742888452490';

test('Backend login via OidcLoginProvider', async ({ page }) => {
  await page.goto('/typo3/login?loginProvider=' + loginProvider);
  await expect(page.getByText('OpenId Connect Login')).toBeVisible();
  await page.getByRole('button', { name: 'Login' }).click();
  await expect(page).toHaveURL(new RegExp('http://oidc.t3ext-oidc.ddev.site/'));

  await page.getByLabel('Username').fill('User1');
  await page.getByLabel('Password').fill('pwd');
  await page.getByRole('button', {name: 'Login'}).click()
  await expect(page).toHaveURL(new RegExp('/typo3/(main|module)'));
  await expect(page.locator('.scaffold-header')).toBeVisible();
});
