# Private registration images

Registration and correction uploads keep an encrypted backup in
`registration_document_contents`, separate from the review metadata. The admin
document endpoint checks barangay ownership before serving either the local file
or its backup. Keep the same `APP_KEY` across deployments so backups remain
decryptable. Database capacity must account for the encrypted file contents.

## Deployment

Run `php artisan migrate --force` before serving this release, then build and
deploy the frontend and PHP changes together. The migration must run before new
registrations are accepted. No public storage link is required for these files.

This protects new uploads and replacement documents. Existing records without a
backup still require their original local files. If those files were lost during
a deployment, use Request Correction to ask the applicant to upload them again.
The review screen now explains failed previews instead of showing a broken image.

Document links use the current origin, avoiding a mismatched scheme or host in
generated image URLs behind a proxy.
