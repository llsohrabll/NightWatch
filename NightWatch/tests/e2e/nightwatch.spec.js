const { test, expect } = require('@playwright/test');

test('public landing page and auth pages load under Docker Compose', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('link', { name: /night watch home/i })).toBeVisible();
  await expect(page.getByRole('heading', { name: /log in, register/i })).toBeVisible();

  await page.goto('/login');
  await expect(page.getByRole('heading', { name: /welcome back/i })).toBeVisible();
  await expect(page.getByPlaceholder(/username or email/i)).toBeVisible();

  await page.goto('/register');
  await expect(page.getByRole('heading', { name: /join the map/i })).toBeVisible();
  await expect(page.getByPlaceholder('Email')).toBeVisible();
});

test('protected panel redirects anonymous users to login', async ({ page }) => {
  await page.goto('/panel');
  await expect(page).toHaveURL(/\/login\/?$/);
});

test('csrf endpoint issues a same-origin token', async ({ request }) => {
  const response = await request.get('/functions/csrf.php');
  expect(response.ok()).toBeTruthy();
  const body = await response.json();
  expect(body.success).toBe(true);
  expect(body.csrf_token).toMatch(/^[A-Fa-f0-9]{64}$/);
});

test('admin dashboard is present but rejects anonymous API access', async ({ page, request }) => {
  await page.goto('/admin');
  await expect(page.getByRole('heading', { name: /review writeups/i })).toBeVisible();

  const response = await request.get('/functions/admin_writeups.php?status=pending');
  expect(response.status()).toBe(401);
});
