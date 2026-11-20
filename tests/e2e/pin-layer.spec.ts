import { readFileSync } from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

// The pin-layer and picture-shape controllers on their own, in a page of this spec's making with no app behind it: the
// controller files are answered from assets/ and Stimulus from the importmap's vendor copy. The pages they serve in the
// app (a Turbo frame, a live component re-render) are proven in pin-placement.spec.ts and draft-round.spec.ts.

const ASSETS_DIR = path.join(__dirname, '..', '..', 'assets');
const ORIGIN = 'http://pin-layer.test';
const LAYER_WIDTH_PX = 400;
const LAYER_HEIGHT_PX = 200;

// The server's markup (PinLayer.html.twig, Screenshot.html.twig), cut to what the controllers read.
const HARNESS = `<!doctype html>
<html><head>
<style>
  .screenshot__frame { position: relative; width: ${LAYER_WIDTH_PX}px; height: ${LAYER_HEIGHT_PX}px; }
  .pin-layer { position: absolute; inset: 0; }
  .pin { position: absolute; width: 10px; height: 10px; padding: 0; }
  #shaped { display: block; width: 100%; height: auto; }
</style>
<script type="importmap">{"imports": {"@hotwired/stimulus": "/assets/vendor/@hotwired/stimulus/stimulus.index.js"}}</script>
</head><body>
<div class="screenshot__frame">
  <div class="pin-layer" id="layer" data-controller="pin-layer">
    <button type="button" class="pin pin--n1" data-pin-layer-target="pin" data-pin-number="1" data-x-percent="25.00" data-y-percent="30.00"></button>
    <button type="button" class="pin pin--n2 pin--bubble-left" data-pin-layer-target="pin" data-pin-number="2" data-x-percent="70.50" data-y-percent="100.00"></button>
    <button type="button" class="pin pin--n3" data-pin-layer-target="pin" data-pin-number="3" data-x-percent="0.00" data-y-percent="12.25"></button>
  </div>
</div>
<div class="screenshot__frame" id="shaped" data-controller="picture-shape" data-picture-shape-width-value="800" data-picture-shape-height-value="450"></div>
<script type="module">
  import { Application } from '@hotwired/stimulus';
  import PinLayer from '/assets/controllers/pin-layer_controller.js';
  import PictureShape from '/assets/controllers/picture-shape_controller.js';
  const application = Application.start();
  application.register('pin-layer', PinLayer);
  application.register('picture-shape', PictureShape);
  window.stimulusStarted = true;
</script>
</body></html>`;

// The app's own review.css, with one layer pin-layer places and one it does not, as a pin looks before it connects.
const STYLED_HARNESS = `<!doctype html>
<html><head>
<link rel="stylesheet" href="/assets/styles/review.css">
<style>.frame { position: relative; width: ${LAYER_WIDTH_PX}px; height: ${LAYER_HEIGHT_PX}px; }</style>
<script type="importmap">{"imports": {"@hotwired/stimulus": "/assets/vendor/@hotwired/stimulus/stimulus.index.js"}}</script>
</head><body>
<div class="frame">
  <div class="pin-layer" id="layer" data-controller="pin-layer">
    <button type="button" class="pin pin--n1" data-pin-layer-target="pin" data-pin-number="1" data-x-percent="25.00" data-y-percent="30.00"></button>
  </div>
</div>
<div class="frame">
  <div class="pin-layer" id="unplaced">
    <button type="button" class="pin pin--n1" data-pin-number="1" data-x-percent="60.00" data-y-percent="40.00"></button>
  </div>
</div>
<script type="module">
  import { Application } from '@hotwired/stimulus';
  import PinLayer from '/assets/controllers/pin-layer_controller.js';
  Application.start().register('pin-layer', PinLayer);
  window.stimulusStarted = true;
</script>
</body></html>`;

async function openHarness(page: Page, html: string = HARNESS): Promise<void> {
  await page.route(`${ORIGIN}/**`, (route) => {
    const { pathname } = new URL(route.request().url());
    if ('/' === pathname) {
      return route.fulfill({ contentType: 'text/html', body: html });
    }
    const file = path.join(ASSETS_DIR, pathname.replace(/^\/assets\//, ''));
    if (!pathname.startsWith('/assets/') || !file.startsWith(ASSETS_DIR + path.sep)) {
      return route.fulfill({ status: 404 });
    }
    const contentType = pathname.endsWith('.css') ? 'text/css' : 'text/javascript';
    return route.fulfill({ contentType, body: readFileSync(file, 'utf8') });
  });
  await page.goto(`${ORIGIN}/`);
  await page.waitForFunction(() => true === (window as unknown as { stimulusStarted?: boolean }).stimulusStarted);
}

/** The pin's spot in pixels from the layer's top left corner, as the browser lays it out. */
async function spotOf(page: Page, number: number): Promise<{ left: number; top: number }> {
  return page.locator(`#layer .pin[data-pin-number="${number}"]`).evaluate((marker) => {
    const style = getComputedStyle(marker);
    return { left: parseFloat(style.left), top: parseFloat(style.top) };
  });
}

// Layout rounds to a 64th of a pixel, so a spot is right within a tenth of one.
const LAYOUT_TOLERANCE_PX = 0.1;

/** How far, in pixels, the pin is from where its percents put it. */
async function pixelsOff(page: Page, number: number, xPercent: number, yPercent: number): Promise<number> {
  const spot = await spotOf(page, number);
  return Math.max(Math.abs(spot.left - (xPercent / 100) * LAYER_WIDTH_PX), Math.abs(spot.top - (yPercent / 100) * LAYER_HEIGHT_PX));
}

async function expectAt(page: Page, number: number, xPercent: number, yPercent: number): Promise<void> {
  await expect.poll(() => pixelsOff(page, number, xPercent, yPercent), { message: `pin ${number} at ${xPercent} % / ${yPercent} %` })
    .toBeLessThanOrEqual(LAYOUT_TOLERANCE_PX);
}

test('on connect every pin of the layer sits at its data-x-percent and data-y-percent', async ({ page }) => {
  await openHarness(page);

  await expectAt(page, 1, 25, 30);
  await expectAt(page, 2, 70.5, 100);
  await expectAt(page, 3, 0, 12.25);
  await expect(page.locator('#layer .pin--n2')).toHaveClass(/pin--bubble-left/);
  await expect(page.locator('#layer .pin--n1')).not.toHaveClass(/pin--bubble-left/);
});

test('a pin put into the layer later, as a live component re-render does, is placed too', async ({ page }) => {
  await openHarness(page);
  await expectAt(page, 1, 25, 30);

  await page.locator('#layer').evaluate((layer) => {
    layer.insertAdjacentHTML('beforeend', '<button type="button" class="pin pin--n4" data-pin-layer-target="pin" data-pin-number="4" data-x-percent="62.00" data-y-percent="44.40"></button>');
  });

  await expectAt(page, 4, 62, 44.4);
  await expect(page.locator('#layer .pin--n4')).toHaveClass(/pin--bubble-left/);
});

test('a pin whose percents change, or whose spot a re-render wipes, goes back to its percents', async ({ page }) => {
  await openHarness(page);
  await expectAt(page, 1, 25, 30);

  await page.locator('#layer .pin--n1').evaluate((marker) => {
    marker.setAttribute('data-x-percent', '80.00');
    marker.setAttribute('data-y-percent', '5.50');
  });
  await expectAt(page, 1, 80, 5.5);
  await expect(page.locator('#layer .pin--n1')).toHaveClass(/pin--bubble-left/);

  await page.locator('#layer .pin--n2').evaluate((marker) => marker.removeAttribute('style'));
  await expectAt(page, 2, 70.5, 100);
});

test('a pin is hidden until it is placed, so it never shows at the layer corner, and shows once placed', async ({ page }) => {
  await openHarness(page, STYLED_HARNESS);
  const placed = page.locator('#layer .pin--n1');

  await expect(page.locator('#unplaced .pin--n1')).toBeHidden();
  await expectAt(page, 1, 25, 30);
  await expect(placed).toBeVisible();

  // As pin_board.js adds a pin in the browser: build, append, then place.
  await page.addScriptTag({
    type: 'module',
    content: `
      import { buildMarker, placeMarker } from '/assets/review/pin_markup.js';
      const marker = buildMarker(2);
      document.getElementById('layer').append(marker);
      placeMarker(marker, 62, 44.4);
      window.liveMarkerAdded = true;`,
  });
  await page.waitForFunction(() => true === (window as unknown as { liveMarkerAdded?: boolean }).liveMarkerAdded);
  await expect(page.locator('#layer .pin--n2')).toBeVisible();
  await expectAt(page, 2, 62, 44.4);
});

test('a frame of a picture not shown takes the picture shape: its width at most and its ratio', async ({ page }) => {
  await openHarness(page);
  const frame = page.locator('#shaped');

  await expect(frame).toHaveCSS('max-width', '800px');
  await expect(frame).toHaveCSS('aspect-ratio', '800 / 450');
  const box = (await frame.boundingBox())!;
  expect(box.height).toBeCloseTo((box.width * 450) / 800, 0);
});
