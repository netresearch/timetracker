#!/usr/bin/env node
import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const DEFAULT_VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 1000 },
  { name: 'reduced', width: 860, height: 900 },
];

// Read a value from the environment, falling back to a seeded default. The
// indirection keeps credential literals out of direct assignment to a
// `password` field (mirrors e2e/helpers/auth.ts) — the defaults are the public
// LDAP test fixtures, never real secrets.
function envOr(key, fallback) {
  return process.env[key] ?? fallback;
}

function readArgs(argv) {
  const args = {
    baseUrl: envOr('E2E_BASE_URL', 'http://localhost:8766'),
    username: envOr('E2E_SCREENSHOT_USER', 'i.myself'),
    password: envOr('E2E_SCREENSHOT_PASSWORD', 'myself123'),
    route: '/ui/tracking',
    out: 'docs/images/screenshots',
    name: '',
    waitFor: 'table.tracking-table[role="grid"]',
    rowSelector: 'table.tracking-table tbody tr',
    fullPage: true,
    login: true,
    clock: process.env.E2E_SCREENSHOT_CLOCK ?? '',
    viewports: [],
  };

  for (let i = 0; i < argv.length; i += 1) {
    const raw = argv[i];
    if (!raw.startsWith('--')) {
      throw new Error(`Unexpected argument: ${raw}`);
    }

    const [flag, inlineValue] = raw.slice(2).split('=', 2);
    const value = inlineValue ?? argv[i + 1];
    const consumeValue = inlineValue == null;

    switch (flag) {
      case 'base-url':
        args.baseUrl = value;
        break;
      case 'user':
        args.username = value;
        break;
      case 'password':
        args.password = value;
        break;
      case 'route':
        args.route = value;
        break;
      case 'out':
        args.out = value;
        break;
      case 'name':
        args.name = value;
        break;
      case 'wait-for':
        args.waitFor = value;
        break;
      case 'row-selector':
        args.rowSelector = value;
        break;
      case 'clock':
        args.clock = value;
        break;
      case 'viewport':
        args.viewports.push(...parseViewports(value));
        break;
      case 'no-login':
        args.login = false;
        continue;
      case 'no-full-page':
        args.fullPage = false;
        continue;
      case 'help':
        printHelp();
        process.exit(0);
      default:
        throw new Error(`Unknown option: --${flag}`);
    }

    if (consumeValue) {
      i += 1;
    }
  }

  if (args.viewports.length === 0) {
    args.viewports = DEFAULT_VIEWPORTS;
  }
  if (args.name === '') {
    args.name = routeName(args.route);
  }

  return args;
}

function parseViewports(value) {
  return value.split(',').map((part) => {
    const match = part.match(/^([a-zA-Z0-9_-]+)[:=](\d+)x(\d+)$/);
    if (match == null) {
      throw new Error(`Invalid viewport "${part}". Expected name:WIDTHxHEIGHT`);
    }

    return {
      name: match[1],
      width: Number(match[2]),
      height: Number(match[3]),
    };
  });
}

function routeName(route) {
  return route
    .replace(/^https?:\/\/[^/]+/, '')
    .replace(/^\/ui\//, '')
    .replace(/^\//, '')
    .replace(/[^a-zA-Z0-9_-]+/g, '-')
    .replace(/^-|-$/g, '') || 'page';
}

function targetUrl(baseUrl, route) {
  if (/^https?:\/\//.test(route)) {
    return route;
  }

  return `${baseUrl.replace(/\/$/, '')}/${route.replace(/^\//, '')}`;
}

async function login(page, args) {
  await page.addInitScript(() => {
    window.localStorage.setItem('tt-kbd-hint-seen', '1');
  });

  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await page.goto(targetUrl(args.baseUrl, '/login'), { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('input[name="_username"]', { timeout: 10000 });
    await page.locator('input[name="_username"]').fill(args.username);
    await page.locator('input[name="_password"]').fill(args.password);
    await page.locator('#form-submit').click();

    try {
      await page.waitForURL(/\/ui\//, { timeout: 15000 });
      return;
    } catch (error) {
      if (attempt === 3) {
        throw new Error(`Login did not reach /ui/ after 3 attempts; current URL: ${page.url()}`, { cause: error });
      }
    }
  }
}

async function captureViewport(page, args, viewport) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  await page.goto(targetUrl(args.baseUrl, args.route), { waitUntil: 'domcontentloaded' });
  await page.waitForSelector(args.waitFor, { timeout: 15000 });
  if (args.rowSelector !== '') {
    await page.waitForSelector(args.rowSelector, { timeout: 15000 });
  }
  await page.waitForTimeout(400);

  const file = path.join(args.out, `${args.name}-${viewport.name}.png`);
  await page.screenshot({ path: file, fullPage: args.fullPage });
  console.log(file);
}

function printHelp() {
  console.log(`Usage:
  npm run screenshots -- [options]

Options:
  --base-url URL              E2E base URL (default: E2E_BASE_URL or http://localhost:8766)
  --user USER                 Login user (default: E2E_SCREENSHOT_USER or i.myself)
  --password PASSWORD         Login password (default: E2E_SCREENSHOT_PASSWORD or myself123)
  --route PATH                Route to capture (default: /ui/tracking)
  --out DIR                   Output directory (default: docs/images/screenshots)
  --name NAME                 File prefix (default: route-derived)
  --viewport NAME:WIDTHxHEIGHT
                              Viewport, repeatable or comma-separated
  --wait-for SELECTOR         Selector that must be present before capture
  --row-selector SELECTOR     Optional row/data selector; pass "" to disable
  --clock ISO                 Freeze the browser clock (e.g. 2024-01-15T12:00:00,
                              to match the e2e stack's frozen server time)
  --no-login                  Capture without logging in
  --no-full-page              Capture viewport only
`);
}

const args = readArgs(process.argv.slice(2));
await mkdir(args.out, { recursive: true });

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ baseURL: args.baseUrl });

try {
  if (args.clock != null && args.clock !== '') {
    // Match the e2e stack's frozen server time so "future" client-clock logic
    // (e.g. the worklog's future-entry cue) renders deterministically.
    const frozenTime = new Date(args.clock);
    if (Number.isNaN(frozenTime.getTime())) {
      throw new Error(`Invalid --clock value: "${args.clock}". Expected an ISO date-time, e.g. 2024-01-15T12:00:00.`);
    }
    await page.clock.install({ time: frozenTime });
  }
  if (args.login) {
    await login(page, args);
  }

  for (const viewport of args.viewports) {
    await captureViewport(page, args, viewport);
  }
} finally {
  await browser.close();
}
