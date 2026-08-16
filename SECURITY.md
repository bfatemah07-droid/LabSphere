# Security Notes

This repository is intended for controlled project review.

## Do not commit

- Real database passwords
- Production environment variables
- Real user exports
- Reservation or audit-log exports
- API keys or service credentials
- Runtime uploaded files that contain internal information

## Before deployment

Use HTTPS, a least-privilege database account, secure environment-managed secrets, server-side backups, restricted file permissions, and a deployment-specific security review.
