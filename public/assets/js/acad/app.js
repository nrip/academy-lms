/**
 * Academy LMS application JavaScript entry (Phase 0).
 * Namespaced module — no business-state transitions.
 */
(function (global) {
  'use strict';

  const Academy = global.Academy || {};

  Academy.Lessons = {
    boot() {
      document.querySelectorAll('[data-acad-lesson-form]').forEach((form) => {
        const kindControl = form.querySelector('[data-acad-lesson-kind]');
        if (kindControl === null) {
          return;
        }
        const apply = () => {
          const kind = kindControl.value;
          form.querySelectorAll('[data-acad-lesson-panel]').forEach((panel) => {
            const kinds = (panel.getAttribute('data-acad-lesson-panel') || '').split(/\s+/);
            const show = kinds.includes(kind);
            panel.hidden = !show;
            panel.querySelectorAll('input, textarea, select, button').forEach((field) => {
              if (field instanceof HTMLButtonElement) {
                return;
              }
              field.disabled = !show;
              if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                field.required = show && field.getAttribute('data-acad-required') === '1';
              }
            });
          });
        };
        kindControl.addEventListener('change', apply);
        apply();
        form.addEventListener('click', (event) => {
          const target = event.target;
          if (!(target instanceof Element)) {
            return;
          }
          const button = target.closest('[data-acad-insert]');
          if (button === null || !form.contains(button)) {
            return;
          }
          const textarea = form.querySelector('[data-acad-rich]');
          if (!(textarea instanceof HTMLTextAreaElement) || textarea.disabled) {
            return;
          }
          const tag = button.getAttribute('data-acad-insert') || '';
          const snippets = {
            strong: '<strong></strong>',
            em: '<em></em>',
            h2: '<h2></h2>',
            ul: '<ul><li></li></ul>',
            a: '<a href="https://"></a>',
          };
          const snippet = snippets[tag] || '';
          const start = textarea.selectionStart;
          textarea.setRangeText(snippet, start, textarea.selectionEnd, 'end');
          textarea.focus();
        });
      });
    },
  };

  Academy.App = {
    boot() {
      document.documentElement.setAttribute('data-acad-app', 'ready');
      if (Academy.Lessons) {
        Academy.Lessons.boot();
      }
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

      document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
          return;
        }
        const toggle = target.closest('[data-acad-password-toggle]');
        if (toggle === null) {
          return;
        }
        event.preventDefault();
        const controls = toggle.getAttribute('aria-controls');
        const input = controls
          ? document.getElementById(controls)
          : toggle.parentElement && toggle.parentElement.querySelector('[data-acad-password-input]');
        if (!(input instanceof HTMLInputElement)) {
          return;
        }
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggle.textContent = show ? 'Hide' : 'Show';
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
