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
    },
  };

  global.Academy = Academy;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Academy.App.boot());
  } else {
    Academy.App.boot();
  }
})(window);
