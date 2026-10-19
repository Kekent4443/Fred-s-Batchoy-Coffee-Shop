# Role-page inventory and consolidation boundary

All paths below are below `public/`. The wrappers use `require_login()` with a
literal role, never a request-supplied role. The shared views are included only
after that check. Existing service functions still enforce action permissions.

| Feature | Admin / Manager / Staff source | Shared behavior | Intentional differences and result |
| --- | --- | --- | --- |
| Bulk discount rules | `admin/discount-rules.php`, `manager/discount-rules.php`, none | Previously identical save/delete handlers and complete form/table body | Consolidated into `app/controllers/discount_rules.php` and `app/views/discount_rules/page.php`; each wrapper sets its own return URL and role guard. |
| Profile | `admin/profile.php`, `manager/profile.php`, `staff/profile.php` | Same account-edit validation and form | Consolidated into `app/controllers/profile.php` and `app/views/profile/page.php`; wrappers retain title, success text, URL, and role guard. |
| Support chat | `admin/support.php`, `manager/support.php`, `staff/support.php` | Same page shell and existing `app/includes/support_console.php` | Consolidated shell into `app/views/support/page.php`; role-specific subtitle retained, Admin-only FAQ link retained. Support action permissions remain in the existing console/service. |
| Contact messages | `admin/contact-messages.php`, `manager/contact-messages.php`, `staff/contact-messages.php` | Existing `contact_messages_actions.php` and `contact_messages_management.php` already shared the action/body | Consolidated repeated shell into `app/views/contact_messages/page.php`; each wrapper keeps its own role guard. |
| Promo codes | `admin/promo-codes.php`, `manager/promo-codes.php`, none | Already thin wrappers over `app/includes/promo_codes_management.php` | Kept existing shared implementation and role guards; no duplicate body to move. |
| Notifications | `admin/notifications.php`, `manager/notifications.php`, `staff/notifications.php` | Admin/Manager list already shared via `notification_page_panel.php` | Send audiences/actions differ; Staff has a personal view only. Kept role-specific handlers/forms and existing shared list. |
| Orders | `admin/orders.php`, `manager/orders.php`, `staff/orders.php` | Shared order services and status badges | Admin monitors filtered orders; Manager assigns riders; Staff performs preparation/status actions. Different action endpoints and columns; not equivalent page bodies, so kept separate. |
| Products | `admin/products.php`, `manager/products.php`, `staff/products.php` | Admin and Manager share `app/controllers/products.php`, `app/views/products/page.php`, and scoped product services | Admin has global add/edit/status/delete/export access. Manager has assigned-shop add/edit/status/delete access, without Admin export or the edit-form status field. Staff retains its separate read-only stock/availability view. |
| Deliveries | `admin/deliveries.php`, `manager/deliveries.php`, `staff/deliveries.php` | Shared delivery services | Admin card workflow, Manager table/assignment workflow, Staff URL redirects to its order queue. No Staff delivery body; kept distinct. |
| Records | `admin/records.php`, `manager/records.php`, `staff/records.php` | Shared badges/table styles | Admin archives/recovers business records; Manager sees limited order/delivery/verification records; Staff sees assigned historical records. Different data scopes and actions; kept distinct. Administrator authentication history remains a separate guarded route. |
| Transactions | `admin/transactions.php`, `manager/transactions.php`, `staff/transactions.php` | Shared transaction services | Admin audit/exports, Manager overview, Staff payment validation/rejection. Different filters and operations; kept distinct. |
| Reports | `admin/reports.php`, `manager/reports.php`, none | Same filter form and results table | Consolidated into `app/controllers/reports.php` and `app/views/reports/page.php`; wrappers retain literal role guards. Admin-only report types and summary metrics, role-specific titles, filters, and export filenames remain server-set context. |
| Shop locations | `admin/shop-location.php`, `manager/shop-location.php`, none | Shared shop services | Admin manages locations; Manager has restricted assigned-location workflow; kept distinct. |
| Dashboard | `admin/dashboard.php`, `manager/dashboard.php`, `staff/dashboard.php` | Shared layout components | Role-specific metrics/navigation; kept distinct. |

The existing route names, service permission checks, CSRF handling, and data
scopes were not replaced. The shared views do not use `role` query/form values
to decide privileges. Pages with genuinely different workflows are deliberately
not forced into a single conditional-heavy view.
