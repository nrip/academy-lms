(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(function () {
    var root = document.getElementById('acad-razorpay-checkout');
    var launch = document.getElementById('acad-pay-launch');
    if (!root || !launch || typeof window.Razorpay !== 'function') {
      return;
    }

    launch.addEventListener('click', function () {
      var options = {
        key: root.getAttribute('data-key'),
        amount: Number(root.getAttribute('data-amount')),
        currency: root.getAttribute('data-currency'),
        name: root.getAttribute('data-name'),
        description: root.getAttribute('data-description'),
        order_id: root.getAttribute('data-order'),
        handler: function () {
          var form = document.createElement('form');
          form.method = 'POST';
          form.action = root.getAttribute('data-return-action') || '/';
          var csrf = document.createElement('input');
          csrf.type = 'hidden';
          csrf.name = '_csrf';
          csrf.value = root.getAttribute('data-csrf') || '';
          form.appendChild(csrf);
          document.body.appendChild(form);
          form.submit();
        },
        modal: {
          ondismiss: function () {
            // Learner closed checkout — still informational only.
          }
        }
      };

      var rzp = new window.Razorpay(options);
      rzp.open();
    });
  });
})();
