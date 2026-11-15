/** ScreenshotReportField prints the same texts; PinCountTextMatchesBrowserTest keeps the two alike. */
export const PINS_NONE = 'Pins are optional, but they help the developer find the problem fast.';
export const PINS_BECOME_CHECKS = 'Each pin becomes a checklist item for the developer and the tester.';

/** The line under the pins of a reported screenshot: how many there are and what they become, or why to add one. */
export function pinCountText(count) {
  if (0 === count) {
    return PINS_NONE;
  }
  return `${count} ${1 === count ? 'pin' : 'pins'}. ${PINS_BECOME_CHECKS}`;
}
