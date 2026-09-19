/**
 * Minimal browser globals for the console smoke test.
 *
 * Imported (statically) before React so that react-dom sees a DOM on load.
 */
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', {
  url: 'http://localhost:8080/superadmin/',
  pretendToBeVisual: true,
});

const { window } = dom;

const define = (key, value) => Object.defineProperty(globalThis, key, { value, writable: true, configurable: true });

define('window', window);
define('document', window.document);
define('navigator', window.navigator);
for (const key of ['HTMLElement', 'HTMLInputElement', 'Node', 'Event', 'MouseEvent', 'KeyboardEvent', 'Element', 'Text', 'CustomEvent', 'MutationObserver']) {
  if (window[key]) define(key, window[key]);
}
define('getComputedStyle', window.getComputedStyle.bind(window));
define('requestAnimationFrame', (callback) => setTimeout(() => callback(Date.now()), 0));
define('cancelAnimationFrame', (handle) => clearTimeout(handle));
define('IS_REACT_ACT_ENVIRONMENT', true);
define('scrollTo', () => {});

export { dom };
