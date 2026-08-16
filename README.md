# LabSphere

LabSphere is a web-based laboratory management system for organizing laboratories, equipment, materials, supplies, storage spaces, reservations, maintenance activities, notifications, users, and reports.

This repository is prepared as a **review-ready source-code package**. The included SQL file contains the database **structure only** and does not include operational user, reservation, audit, or laboratory data.

## Main modules

- Authentication and user registration
- Role-based access control
- Dashboard
- Laboratories
- Equipment
- Materials
- Supplies
- Storage spaces and storage reservations
- Equipment/laboratory/material reservations and approvals
- Maintenance records and maintenance notifications
- Temperature logs
- Notifications
- User management
- Reports
- Audit logs
- User profile and password management

## User roles

- **Student** — uses the system and submits reservations.
- **Supervisor** — manages operational laboratory resources and reviews relevant requests.
- **Admin** — has administrative access, including user management.

## Technology stack

- PHP 8+
- MariaDB / MySQL
- PDO
- Bootstrap 5
- Bootstrap Icons
- HTML, CSS, and JavaScript
- Apache (XAMPP is suitable for local review)

## Local setup with XAMPP

1. Install XAMPP and start **Apache** and **MySQL**.
2. Copy this project folder to:

   `C:\xampp\htdocs\LabSphere`

3. Open phpMyAdmin and import:

   `sql/labnet.sql`

   The SQL file creates the `labnet` database and all required tables. It contains schema only.

4. Create the first administrator from Windows Command Prompt or PowerShell:

   ```text
   C:\xampp\php\php.exe C:\xampp\htdocs\LabSphere\scripts\create_admin.php "System Admin" admin@example.com "Choose-A-Strong-Password"
   ```

   Use your own administrator email and a password of at least 12 characters. No default administrator password is stored in this repository.

5. Open:

   `http://localhost/LabSphere/`

6. Sign in with the administrator account you created.

## Configuration

For a standard local XAMPP installation, the default database settings are:

- Host: `127.0.0.1`
- Database: `labnet`
- User: `root`
- Password: blank

For another environment, set these environment variables instead of placing credentials in source code:

- `LABSPHERE_DB_HOST`
- `LABSPHERE_DB_NAME`
- `LABSPHERE_DB_USER`
- `LABSPHERE_DB_PASS`
- `LABSPHERE_BASE_URL`

`LABSPHERE_BASE_URL` defaults to `/LabSphere`.

## Security-related implementation

The current application includes:

- Password hashing with PHP `password_hash()` / `password_verify()`
- PDO prepared statements
- Session-based authentication
- Role-based authorization checks
- CSRF tokens for state-changing forms
- Output escaping for displayed values
- Audit logging for important actions

Before production deployment, server hardening, HTTPS, environment-specific secrets, database privileges, backups, monitoring, and a final security review should be completed.

## Database

The canonical database schema is:

`sql/labnet.sql`

It includes 15 tables and no exported application data.

## Repository hygiene

- Runtime uploads are excluded from Git through `.gitignore`.
- No default administrator credentials are included.
- The old browser-accessible administrator setup script has been removed.
- Database credentials can be provided through environment variables.

## Project status

This repository represents the application source prepared for **company review and approval prior to final deployment**. Production deployment configuration and the final live URL should be added after approval.

## Ownership and use

No open-source license is included in this review package. Source-code sharing and reuse should follow the project owner's and receiving organization's approval requirements.
