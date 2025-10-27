# Notifications manual QA script

Follow these steps to validate the end-to-end behaviour of the Notification Center, bell dropdown, and delivery fallbacks.

## Prerequisites
- Log in as an admin user in a browser session with developer tools available.
- Ensure the tenant has sample notifications covering unread, read, archived, and different types.
- Open two browser windows signed in as the same user to observe real-time updates.

## Bell dropdown and SSE
1. Open the main dashboard and watch the bell icon.
2. Trigger a new notification (e.g., assign a workout plan) from the second window.
3. Confirm the red badge increments without reloading and the dropdown prepends the new item.
4. Click the bell and verify the dropdown shows skeleton rows while loading, then the new notification with title, teaser, badge, age, and action buttons.
5. Use "Mark read" from the dropdown and confirm the badge decrements and the item styling updates in both windows.

## SSE failure fallback
1. In the browser dev tools, block the `/api/notifications/stream` request or simulate network errors.
2. Observe that polling begins (network panel shows `/api/notifications?…` requests every 15s).
3. Trigger another notification and confirm the dropdown updates after the polling interval.
4. Remove the block and ensure the EventSource reconnects, the polling requests stop, and live updates resume immediately.

## Notification Center page
1. Navigate to `notifications.php`.
2. Verify header/nav render correctly and the System > Notification Center item is highlighted.
3. Use filters (status, type, priority, date range, actor) and confirm the table updates and pagination metadata reflect the filtered result count.
4. Select multiple rows and run bulk actions (Mark read, Mark unread, Archive, Delete). Confirm toast/success messaging and badge counts update.
5. Open a notification to edit via the action menu and ensure the modal shows existing data, supports optimistic save, and rolls back on error.
6. Create a new notification targeting yourself, including an email toggle. Ensure it appears immediately in the list and bell dropdown.

## Settings and preferences
1. Open the Settings panel on the Notification Center page.
2. Toggle auto mark-as-read on open and badge includes muted types. Save and confirm preferences persist after refresh.
3. Mute a notification type and verify it is hidden in both the page list and bell dropdown (badge count respects the preference).

## Role-based access & isolation
1. Log in as a trainer and confirm they only see their own notifications, even when attempting to query another user via the UI filters.
2. Log in as an admin and fan-out a notification to a role. Verify only users within the same tenant receive it.

## SSE health endpoint
1. Visit `/api/notifications/health.php` directly while signed in. Confirm JSON `{ "ok": true, … }` with unread count is returned.
2. Sign out and revisit to ensure the endpoint reports `unauthenticated` with HTTP 401.

Document any anomalies encountered during the run and attach console/network logs when reporting issues.
