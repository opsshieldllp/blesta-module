# cPGuard Reseller for Blesta

Sell and manage cPGuard licenses from your own Blesta installation using your OPSSHIELD reseller account. This is a **provisioning module**, installed under `components/modules`, rather than a plugin. It connects Blesta's normal service lifecycle to the reseller API.

## Install

1. Extract the installable release ZIP into the root of your Blesta installation. If using a GitHub source archive, copy only its `components` directory into the Blesta root. The resulting path must be `components/modules/cpguard_reseller/cpguard_reseller.php`.
2. In Blesta, open **Settings → Company → Modules → Available** and install **cPGuard Reseller**.
3. Click **Manage → Add Reseller Account**. Enter an account name and the reseller API key supplied by OPSSHIELD. Saving performs a read-only connection check. The key is stored encrypted by Blesta; leaving the key blank when editing retains it.
4. Maintain enough credit in your OPSSHIELD account. The module's account page displays upstream credit, amount due and service counts.

Only one reseller account can be configured per installed module/company. Use **Edit Reseller Account** to rename it or rotate the key for the same OPSSHIELD account. Do not replace it with another reseller identity while licenses are attached. **Delete Account** is on the edit page; Blesta blocks deletion while packages or non-canceled services use the connection. Deleting the configuration does not cancel upstream licenses.

Requires PHP 7.4 or newer, PHP cURL, a working certificate trust store, and outbound HTTPS access to `manage.opsshield.com`. Live installation and package configuration verified on Blesta 5.10.1 with PHP 8.2.34. Confirm compatibility before production rollout on other versions.

## Create license packages

1. Open **Packages → Create Package**. Select **cPGuard Reseller** as the module.
2. The configured reseller account is assigned automatically; no account or group selection is required.
3. Select **OPSSHIELD reseller package**. Each option shows the upstream package, monthly wholesale price, currency and pricing ID. The currency shown depends on your company's **Default Country**, as explained below.
4. Configure your own retail prices and billing periods. Monthly, yearly, or multiple retail periods can use the same monthly OPSSHIELD reseller package. Blesta customer billing and OPSSHIELD monthly renewal are independent.
5. Set service quantity to one and disable quantity changes. Create a separate Blesta service for each license.
6. Configure the package welcome email, package groups/order form, and Blesta payment/provisioning automation normally. Provision only after payment or staff approval as appropriate.

**Suspension and cancellation must reach OPSSHIELD.** Keep **Use module** enabled when performing these actions in Blesta. The module sends suspend, unsuspend and cancel requests upstream and reports API failures to Blesta. A local-only status change does not stop OPSSHIELD billing. Scheduled cancellation takes effect when Blesta executes it; until then an active upstream license can continue renewing from reseller credit.

Package setup and provisioning both check upstream pricing availability. This avoids choosing the first pricing entry with a matching currency and accidentally ordering the wrong term.

### Why reseller packages show INR or USD

The module uses **Settings → Company → General → Localization → Default Country** to show reseller pricing in the currency intended for your company's region:

- **India (`IN`):** shows INR reseller packages.
- **Any other country, or no country set:** shows USD reseller packages.

If the dropdown shows an unexpected currency, open that setting, select the correct country for your company, save, and reload the package creation or editing page. The filter uses this saved company setting; it does not detect ownership or location from the server, IP address, or customer's country. Your retail prices and customer currencies do not control it and may differ from the reseller currency.

Only matching prices returned by OPSSHIELD for your reseller account are offered. If none are available, the form reports that no reseller packages are available in that currency; it does not convert prices or fall back to another currency. Check with OPSSHIELD that your reseller account has packages in the expected currency.

An existing package mapping in another currency remains visible as **Current mapping**, provided it is still returned by OPSSHIELD. Changing **Default Country** does not automatically remap existing packages or convert their prices.

## Welcome email

Use Blesta's package **Welcome Email** editor. Example body:

```text
Your cPGuard license is ready.

License key: {service.cpguard_license_key}
OPSSHIELD service ID: {service.cpguard_service_id}

Apply the license on an existing installation:
{service.cpguard_apply_command}

{% if service.cpguard_invite_link %}
Complete your OPSSHIELD account registration:
{service.cpguard_invite_link}
{% endif %}

Sign in to your cPGuard dashboard at https://app.opsshield.com/
Installation instructions are available in the OPSSHIELD License tab of your service.
```

Blesta sends the normal activation welcome email. The module does not send separate emails itself. Both documented `Invite_link` and actual WHMCS `invite_link` response spellings are handled. Staff can refresh an invitation from the service tab and resend the welcome email using Blesta's normal controls. An existing registered customer does not need an invitation.

## Manage licenses

The **OPSSHIELD License** tab is available to staff and clients. It groups license details, reissue controls and OPSSHIELD account access into separate sections. cPGuard installation/apply commands are in the expandable **cPGuard Setup Instructions** section and apply only to that product. The service label uses **OPSSHIELD #ID**. **Basic Options** keeps the existing reseller account assignment without an account selector or recovery field.

- **Suspend / Unsuspend:** use Blesta's normal service actions or automation. A false or mismatched API result prevents Blesta from treating the module action as successful.
- **Cancel:** cancels the upstream license; it does not delete its history. An already canceled license can be canceled again safely. Cancel unneeded licenses before upstream renewal.
- **Reissue License:** clears the server binding so the same key can bind to a different server. Available only on an active service with an active upstream license. The action asks for confirmation before clearing the binding. `reissue: true` means the license is already waiting to bind; the button remains visible but disabled with an explanation. Suspended licenses and unavailable connections also disable reissue.
- **Package changes:** use Blesta's service/package change workflow with the module enabled. OPSSHIELD cancels the old license and creates a replacement. The module saves the new remote service ID/key and displays a notice instructing the customer to apply the new key. Add that instruction to your service-change email or notify the customer through your normal workflow. Changes across reseller accounts are blocked.
- **Renewal:** Blesta handles retail invoicing. OPSSHIELD automatically renews active licenses using reseller credit. The module makes no remote purchase on Blesta renewal. Upstream and retail due dates are separate; check both, especially after imports or package changes. Prorated upstream credits/refunds are determined by OPSSHIELD.

No low-credit notification cron, bulk purchase workflow, remote deletion, or single sign-on is included in this version. The dashboard link opens the normal OPSSHIELD sign-in page.

## Link an existing license or recover an uncertain order

Staff can enter **Existing OPSSHIELD service ID** when adding a service to link a reseller-owned license without buying a replacement. The module fetches and checks its identity and pricing, and requires an active upstream license so that activation cannot leave the local service active while the remote license is suspended. Verify the remote license belongs to the customer: the documented license lookup does not expose the buyer email.

If a purchase request times out or returns an incomplete response, it may already have created a charge/license. The API does not document an idempotency token or a lookup by Blesta order ID. The HTTP client never retries automatically, but Blesta's own provisioning automation may retry pending services.

1. Hold further provisioning attempts for that pending service while investigating.
2. Check the reseller portal for the license and invoice created for this customer. Do not immediately repeat the purchase or package-change request.
3. If a new license already exists for an unprovisioned pending service, open **Manage Service → Basic Options**, enter its remote ID in **Existing OPSSHIELD service ID**, and activate with **Use module** enabled. Blesta 5.10 displays the import field directly on the pending activation form. This links the existing active license without making another purchase.
4. Recovery cannot replace an identity already attached to a provisioned service. If a package-change response was lost, reconcile the old/new upstream identities and local service fields before proceeding; do not repeat the destructive package change blindly.

Only signed-in staff can enter an existing license ID during service creation or pending activation. Automation can activate an already linked pending service using its saved identity. Client reissue actions use the service identity already stored by Blesta and ignore submitted service IDs. POST forms use Blesta's normal CSRF protection; the module does not disable it.

## Verification

Run the offline checks from the repository root:

```sh
php tests/run.php
```

These checks exercise API response validation, failure handling, lifecycle transitions, replacement license metadata, account scoping, independent retail billing periods, staff import restrictions, invitation handling, POST action controls, and escaped template output using fixtures. They do not prove a real order was provisioned.

Before selling licenses, verify in the installed Blesta instance: account connection, package save, welcome email tags, a paid test order, client access, reissue, suspend/unsuspend, package change/new key, and cancellation. A live order or package change can consume reseller credit; choose the customer, upstream pricing and spending limit before running those checks.

Verification: account connection, account details, upstream pricing, package save with independent retail billing periods were exercised on Blesta 5.10.1. The reviewed module also passes offline regression checks on PHP 8.2 and 8.5 and a compatibility smoke check against the Blesta 5.10.1 base class. Paid provisioning and customer lifecycle actions have not yet been tested live.

## Development and packaging

```sh
php tests/run.php
python3 scripts/build-release.py
```

The archive in `dist/` contains only the runtime module and installation documentation. Development tests, CI configuration and local files are excluded. Do not upload the entire repository into your web root.

Security reports: use this repository's GitHub private vulnerability reporting when enabled, or contact OPSSHIELD through your existing support channel. Do not include API keys, license keys or invitation links in public issues.

## Sources

- [OPSSHIELD WHMCS source](https://github.com/opsshieldllp/whmcs-module), inspected at commit `9b5b1d732bbdb89dc68ed13740d64011ddad4e9c`.
- [Reseller API documentation](https://github.com/opsshieldllp/whmcs-module/blob/9b5b1d732bbdb89dc68ed13740d64011ddad4e9c/API.md).
- [Blesta module methods](https://docs.blesta.com/developers/modules/module-methods/) and [ModuleFields](https://docs.blesta.com/developers/modules/modulefields/).

This is a new Blesta implementation of the documented API behavior, with no WHMCS runtime dependency.
