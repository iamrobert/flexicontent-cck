# Compatibility and operational changes

- Existing serialized arrays/scalars remain supported. Decoding accepts only the native data tokens for arrays, strings, numbers, booleans, null and data references. Class-bearing tokens, trailing data, recursive object graphs, and excessive depth/size are rejected. No database migration is required. Custom code that directly calls PHP unserialize() must use the hardened helper for stored field data. Objects already created by Joomla APIs are unaffected.
- Contact forms resolve the recipient, item metadata, field configuration, access and publication from the database. The rendered form carries an HMAC binding the intended recipient to its item and field. Update overridden contact form layouts to include the new hidden context. Old cached forms must be refreshed.
- Contact CAPTCHA and consent are checked on the server. Missing configured CAPTCHA fails closed.
- Contact attachments are limited to five files, 5 MiB each and 10 MiB total; configured per-field limits may be stricter. Supported extensions are PDF, TXT, CSV, JPG/JPEG, PNG, GIF and WebP, with matching detected MIME types and the configured accept list. PHP upload paths are used directly; no public staging directory is created.
- Mail uses the site sender and a validated visitor Reply-To. Anonymous sender copies are disabled; a copy may go to a signed-in user's matching email. Administrator copies use the configured address.
- Contact/file/media sharing share a limit of five attempts per 15 minutes per IP bucket. This requires a writable Joomla temporary directory. The fixed bucket count bounds storage; hash collisions share a limit.
- Direct file-sharing submissions require POST, a valid CSRF token, enabled sharing, and access to the item, field, type and file.
- New download/edit coupons use 256-bit random tokens. Old weak tokens are invalid. Download coupons authorize only their associated file. Edit links expire after seven days; existing session editing behavior remains in place.
- Existing-account submissions require signing in; supplying an email address no longer grants authorship. Both self-activation and administrator-activation accounts start blocked, and blank settings inherit Joomla registration configuration.
- Image folder copies require source-item edit permission; temporary folder moves require a session-issued identifier and contained paths.
- Relation selectors enforce item, category, type and appended-field visibility.
- Existing configured PHP remains available. Enabling or changing executable field/type settings requires Super User permission. Title PHP comes from saved type configuration. Filter values are passed as data instead of being interpolated into executable code.

Follow-up fixes for save and download regressions:

- Basic search indexing initializes the field's search array before reading it; Apply no longer emits an undefined-property warning.
- The shared security helper is registered with Joomla's loader and explicitly loaded by the download controller and file/media sharing plugins. A fresh download request no longer depends on the database helper loading first.
- Invalid non-empty weblinks fail normal field validation, retaining the submitted form rather than silently clearing a saved link. Spaces must be encoded as %20; the URL scheme and character restrictions remain.
- If CAPTCHA is required but missing or its plugin fails to render, the contact form displays an unavailable message. The rest of the item page renders and the server still refuses unverified submissions.
- Image folder errors return through normal field validation. A new configured base directory can be created after authorization; folder copy/move failures stop the save. Temporary IDs use the issuing component's session namespace. Retries cannot register an arbitrary client-supplied ID. Old or expired forms may still need their images reselected.
- Ordinary editors can preserve PHP settings when the browser only changes line endings in PHP whitespace or comments. Actual code changes, including changes inside string literals, still require Super User permission.

The protected mail and selector endpoints still enforce their publication/access policy. Ordinary visitors cannot use them for archived items; authorized editors retain the existing edit-permission exception. Rejected direct HTTP requests retain their error status instead of redirecting to a success page.
