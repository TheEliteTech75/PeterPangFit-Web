# PeterPangFit-Web
Development for Peter Pang Fit online platform.

## Demo Mode configuration

Set the following environment variables (e.g., in `.env`) to control database routing:

| Key | Description |
| --- | ----------- |
| `PPF_DB_HOST` / `PPF_DB_NAME` / `PPF_DB_USER` / `PPF_DB_PASS` | Primary database connection settings. Defaults match the legacy hard-coded credentials. |
| `PPF_DB_PORT` / `PPF_DB_SOCKET` | Optional port or socket overrides for the primary connection. |
| `PPF_DEMO_DB_HOST` / `PPF_DEMO_DB_NAME` / `PPF_DEMO_DB_USER` / `PPF_DEMO_DB_PASS` | Sandbox database credentials used when Demo Mode is enabled (`system_settings.demo_mode_enabled = 1`). Defaults mirror the primary credentials with the database name suffixed by `_demo`. |
| `PPF_DEMO_DB_PORT` / `PPF_DEMO_DB_SOCKET` | Optional port or socket overrides for the sandbox connection. |

When Demo Mode is enabled but the sandbox connection fails, the application falls back to the primary database, logs an alert, and automatically clears the flag to protect future requests.

### Demo sandbox accounts

The demo seed (`demo_seed.sql`) provisions three sample identities with predictable credentials so walkthroughs can sign in immediately after a reset:

| Role    | Email                      | Password          |
|---------|----------------------------|-------------------|
| Admin   | `demo.admin@example.com`   | `DemoAdmin!2024`  |
| Trainer | `demo.trainer@example.com` | `DemoTrainer!2024`|
| Client  | `demo.client@example.com`  | `DemoClient!2024` |

Update the seed and rerun the reset if you prefer different sandbox credentials.
