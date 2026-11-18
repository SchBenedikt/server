# Security policy

## Reporting a vulnerability

Please do not open a public issue for a vulnerability. Use GitHub's private
vulnerability reporting on this repository
(`Security` → `Report a vulnerability`) or contact the maintainer privately.

Include the affected version, a short reproduction, and the impact you expect.
You will get an acknowledgement as soon as possible. Please give us time to
prepare a fix before publishing details.

## Supported versions

The latest release on `main` and the latest tagged release receive security
fixes. Older Core releases are not supported once a newer release is published.

## Deployment notes

- Run the application over HTTPS; session cookies are marked `Secure` in
  production.
- Keep `ALOHASOCIAL_SECRET_KEY` secret and back it up. It encrypts stored
  Bluesky tokens and the native PDS signing key.
- Restrict access to the `.env` file and the database to the service account.
- The admin console is only available to accounts marked as admin.
