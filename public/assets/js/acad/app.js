/**
 * Academy LMS application JavaScript entry (Phase 0).
 * Namespaced module — no business-state transitions.
 */
(function (global) {
  'use strict';

  const Academy = global.Academy || {};

  Academy.App = {
    boot() {
      document.documentElement.setAttribute('data-acad-app', 'ready');
      // CSP script-src 'self' blocks inline onclick; delegate print controls here.
      document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
          return;
        }
        const trigger = target.closest('[data-acad-print]');
        if (trigger === null) {
          return;
        }
        event.preventDefault();
        global.print();
      });
    },
  };

  global.Academy = Academy;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Academy.App.boot());
  } else {
    Academy.App.boot();
  }
})(window);
