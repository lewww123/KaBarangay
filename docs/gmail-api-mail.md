# Gmail API delivery on Render

This mail transport uses HTTPS for both OAuth refresh and Gmail `messages.send`.
It does not use SMTP ports or a Gmail App Password. All existing Laravel mail
flows using the default mailer, including approval and password-reset emails,
use it when `MAIL_MAILER=gmail`.

## Render environment

Store the following in Render's Environment settings, never in Git or screenshots:

```dotenv
MAIL_MAILER=gmail
GMAIL_CLIENT_ID=your-own-oauth-client-id
GMAIL_CLIENT_SECRET=your-own-oauth-client-secret
GMAIL_REFRESH_TOKEN=your-own-sender-refresh-token
MAIL_FROM_ADDRESS=your-authorized-sender@gmail.com
MAIL_FROM_NAME=KaBarangay
APP_URL=https://kabarangay.onrender.com
LOG_CHANNEL=stderr
```

The refresh token must belong to the sender account and grant
`https://www.googleapis.com/auth/gmail.send`. Enable Gmail API in the same
Google Cloud project. A recipient does not need to authorize the application.

Use your own OAuth client credentials in OAuth Playground and set access type
to Offline when authorizing. Keep the OAuth app in the appropriate production
state and address any verification requirements shown by Google. Publishing
does not mean Google has verified the application.

If credentials appeared in a screenshot, revoke the old token, rotate the client
secret in Google Cloud, then authorize again and replace the Render values.

Deploy the code together with the environment update. The Docker startup command
caches configuration using Render's environment. Restart/redeploy after changing
credentials. There is no additional Composer package or migration for this change.

## Check delivery

Request a password reset for an existing active resident account with an active
login account. Check that resident's inbox and spam folder. The form's generic
response is intentionally not a delivery receipt or account-existence check.

Password-reset sending uses the `background` connection by default. It starts a
separate PHP process; it is not a durable queue and can be interrupted by a
container shutdown. A resident can request another link if necessary. A durable
database queue requires a separately running worker. Do not change to `database`
without arranging that worker.

The transport reports sanitized errors for invalid credentials, rejected or
expired refresh tokens, disabled API/insufficient permission, sending limits,
and HTTPS connection failures. It never includes Google's raw error body in the
transport exception. Existing reset/approval flows may record only the exception
class, so do not treat a generic form confirmation as proof of delivery.

Google sending quotas still apply. Gmail acceptance does not guarantee inbox
placement. An uncertain send timeout is not automatically retried to avoid
duplicate emails. An explicit HTTP 401 is refreshed and retried once.

## References

- https://developers.google.com/workspace/gmail/api/guides/sending
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send
- https://developers.google.com/identity/protocols/oauth2/web-server#offline
