/**
 * Document upload/replace flow for the application documents workspace.
 * No business-state transitions happen here — this only orchestrates calls
 * to the server-authoritative JSON endpoints; every state change is decided
 * and persisted server-side.
 */
(function (global, $) {
  'use strict';

  if (!$) {
    return;
  }

  function csrfToken(root) {
    return root.data('csrf');
  }

  function applicationId(root) {
    return root.data('application-id');
  }

  // Form-encoded, not JSON: the server has no JSON body-parsing middleware —
  // every endpoint in this app (see QualificationController, ProfileController,
  // etc.) reads getParsedBody(), which Diactoros only populates for
  // application/x-www-form-urlencoded and multipart bodies.
  function csrfHeaders(root) {
    return { 'X-CSRF-Token': csrfToken(root) };
  }

  function pickFile() {
    return new Promise((resolve) => {
      const input = document.createElement('input');
      input.type = 'file';
      input.onchange = () => resolve(input.files && input.files[0] ? input.files[0] : null);
      input.click();
    });
  }

  function sha256Hex(buffer) {
    return crypto.subtle.digest('SHA-256', buffer).then((hash) => {
      return Array.from(new Uint8Array(hash))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
    });
  }

  function uploadAndConfirm(root, requirementId, replaceSubmissionId) {
    const appId = applicationId(root);

    pickFile().then((file) => {
      if (!file) {
        return;
      }

      const authorizeUrl = replaceSubmissionId
        ? '/applications/' + appId + '/documents/' + replaceSubmissionId + '/replace'
        : '/applications/' + appId + '/documents/upload-authorizations';

      $.ajax({
        url: authorizeUrl,
        method: 'POST',
        headers: csrfHeaders(root),
        data: { requirement_id: requirementId, filename: file.name, mime_type: file.type, size_bytes: file.size },
        dataType: 'json',
      }).then((authorization) => {
        file.arrayBuffer().then((buffer) => {
          Promise.all([sha256Hex(buffer), Promise.resolve(buffer)]).then(([checksum]) => {
            $.ajax({
              url: authorization.upload_url,
              method: authorization.method || 'PUT',
              // Local dev/testing upload endpoint is a same-origin POST route and
              // therefore still CSRF-checked; a real S3 presigned PUT would not
              // carry this header at all (it is bearer-signature authenticated).
              headers: Object.assign({}, authorization.headers || {}, csrfHeaders(root)),
              data: buffer,
              processData: false,
              contentType: file.type || 'application/octet-stream',
            }).then(() => {
              return $.ajax({
                url: '/applications/' + appId + '/documents/confirm',
                method: 'POST',
                headers: csrfHeaders(root),
                dataType: 'json',
                data: {
                  requirement_id: authorization.requirement_id,
                  object_key: authorization.object_key,
                  checksum_sha256: checksum,
                },
              });
            }).then(() => {
              global.location.reload();
            }, (xhr) => {
              global.alert('Upload failed: ' + (xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'unknown error'));
            });
          });
        });
      }, (xhr) => {
        global.alert('Could not start upload: ' + (xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'unknown error'));
      });
    });
  }

  $(function () {
    const root = $('.acad-application-documents');
    if (root.length === 0) {
      return;
    }

    if (root.data('correction-mode') === 1 || root.data('correction-mode') === '1') {
      root.addClass('acad-application-documents--correction-mode');
    }

    root.on('click', '.acad-document-upload-btn', function () {
      const requirementId = $(this).closest('.acad-document-requirement').data('requirement-id');
      uploadAndConfirm(root, requirementId, null);
    });

    root.on('click', '.acad-document-replace-btn', function () {
      const requirementId = $(this).closest('.acad-document-requirement').data('requirement-id');
      const submissionId = $(this).data('submission-id');
      uploadAndConfirm(root, requirementId, submissionId);
    });
  });
})(window, window.jQuery);
