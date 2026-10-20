# FCL SaaS Platform — Deep Review & Test Report (SaaS MVP, Milestones 1–5)

> **التاريخ:** 19 أكتوبر 2026
> **الغرض:** نقل نتيجة المراجعة العميقة والاختبارات لجلسة Claude Code على جهاز تاني، علشان تكمل منها مباشرة من غير ما تعيد المراجعة.
> **المرجع الأساسي للمشروع:** `docs/FCL_SaaS_Complete_Project_Handover_2026-10-19.md` و `CLAUDE.md` و باقي `docs/`.
> **مهم للجلسة المستقبِلة:** تعامل مع الكود الحالي كمصدر الحقيقة. تحقّق من كل Finding بنفسك قبل الإصلاح (أرقام السطور ممكن تتحرك). اتبع قواعد `CLAUDE.md` بالكامل: Commits على `main` باسم Islam Kabbary، بدون AI Attribution، بدون Push إلا بإذن صريح.

> **تحديث 20 أكتوبر 2026:** كل الـ Findings السبعة والملاحظات المتوسطة اتصلحت في **Milestone 5.1** (`docs/MILESTONE_05_1_SECURITY_BILLING_HARDENING.md`). الـ 8 Review Proofs اتنقلوا لملفاتهم الدائمة كـ Regression Tests بتنجح، وفولدر `tests/Feature/Review/` **اتمسح**، فأوامر `RUN_REVIEW_PROOFS` في Appendix A مبقتش بتشغّل حاجة. التقرير ده فاضل زي ما هو كسجل للمراجعة.

---

## 0) الخلاصة في دقيقة

- الحالة وقت المراجعة: Milestones 1–5 منفذة ومعمول لها Commits. آخر Commit على `main`: `822e937`، و `main` متزامن مع `origin/main`.
- الاختبارات الموجودة كلها سليمة: **387 passed + 1 skipped** من 388.
- المراجعة لقت **7 مشاكل حقيقية**، وكل واحدة ليها Test فشل فعلًا وقت التشغيل (8 حالات، لأن T1 فيه حالتين).
- فيه حوالي 15 ملاحظة إضافية أقل خطورة، متأكد منها من قراءة الكود بس.
- **مفيش أي سطر في كود التطبيق اتعدّل، ومفيش أي Commit جديد.**
- الخطوة المقترحة: **Milestone 5.1 — Security & Billing Hardening** قبل بدء FCL Operations. **لسه مستني موافقة صاحب المشروع.**

---

## 1) طريقة المراجعة

- 4 مراجعات متوازية Read-only حسب المجال:
  - Tenancy & Isolation (M1/M3)
  - Users & Permissions (M2)
  - Billing & Seats (M4)
  - Impersonation & 2FA (M5)
- كل Finding مهم اتأكد بقراءة الكود مباشرة، وبعدها اتكتبله Test يأكد **السلوك الصحيح**، فبيفشل طول ما المشكلة موجودة.
- ملف الإثبات: `tests/Feature/Review/ReviewFindingsTest.php`، وهو Committed على `main`. الاختبارات دي **Opt-in**: بتتعمل Skip في التشغيل العادي علشان الـ Suite تفضل خضرا، وبتشتغل بـ `RUN_REVIEW_PROOFS=1`. نسخته الكاملة موجودة كمان في Appendix A تحت.

---

## 2) نتيجة الاختبارات

| الفحص | النتيجة |
|---|---|
| Pint | ✅ passed |
| PHPStan | ✅ 0 errors |
| Pest — الاختبارات الأصلية (388) | ✅ 387 passed, 1 skipped (Starter Kit tenant 2FA — الميزة مقفولة عمدًا) |
| Pest — Review proofs (8) | ❌ 8 failed (متوقع: كل واحد بيثبت Bug) |
| إجمالي | 396 tests, 1744 assertions, ~6 دقايق |
| قواعد الاختبار المؤقتة | ✅ اتمسحت كلها؛ متبقي `fcl_central` و `fcl_central_testing` بس |

**ملاحظة تشغيل:** `composer test` بيقف بسبب Composer process timeout (300 ثانية) قبل ما Pest يخلص. للتشغيل الكامل استخدم:

```bash
php -d memory_limit=1536M vendor/bin/pest
```

أو زوّد `process-timeout` في Composer.

---

## 3) المشاكل المثبتة وقت التشغيل (Runtime-proven)

### T1 — 🔴 High — تعرّف الـ Tenant من Host مزيف (Host Header / Password Reset Poisoning)

- **المكان:**
  - `vendor/stancl/tenancy/src/Middleware/InitializeTenancyBySubdomain.php` → `makeSubdomain()` بيستخدم `Str::endsWith($hostname, central_domains)` من غير فاصل نقطة، وبياخد أول Label بس.
  - مستخدم من `bootstrap/app.php` (حوالي سطر 39).
  - مفيش `trustHosts()` في المشروع.
- **السيناريو:**
  - `acme.evillocalhost` و `acme.globex.localhost` بيتعرّفوا كـ Tenant `acme`.
  - على Production (`CENTRAL_DOMAIN=fcl.app`) المهاجم يسجل `evilfcl.app`، ويبعت `POST /forgot-password` بـ `Host: acme.evilfcl.app`.
  - الضحية يوصلها إيميل Reset حقيقي فيه لينك على دومين المهاجم، فالـ Token يتسرق والحساب يتاخد.
  - لو اتعمل `trustProxies(at: '*')` زي ما ملف الـ Readiness مقترح، `X-Forwarded-Host` يخلي ده يشتغل حتى ورا Load Balancer.
- **دليل الـ Test:** `[T1]` رجع 200 بدل 404 للحالتين.
- **اتجاه الإصلاح:**
  - تثبيت الـ Hosts المسموحة: `$middleware->trustHosts(...)` بـ Pattern يسمح بالـ Central Domain بالظبط، أو `^[a-z0-9-]+\.` + Central Domain بـ Label واحد بس.
  - أو Subclass لـ `InitializeTenancyBySubdomain` يشترط إن `$host === "{$sub}.{$central}"`.
  - مع `trustProxies` لازم Proxies محددة، مش `*`.

### U1 — 🔴 High — `tenants:sync-authorization` بيرجّع Tenant Admin للمؤسس بعد سحبه منه

- **المكان:**
  - `app/Actions/Tenancy/SyncTenantAuthorization.php` (حوالي السطور 46–49): بيدي Role Tenant Admin لأي User عنده `is_tenant_admin = true` ومعندوش الـ Role، من غير شرط `is_active` ومن غير Marker إنه اتعمل قبل كده.
  - `is_tenant_admin` بيتعمله Set في `ProvisionTenant.php:144`، **ومفيش أي حاجة بتمسحه**.
- **السيناريو:**
  - Admin تاني بيشيل صلاحية المؤسس (مثلًا لأنه ساب الشركة أو حسابه اتخترق).
  - في أول Deploy، والأمر ده خطوة إجبارية فيه، المؤسس يرجع Tenant Admin بصمت.
- **دليل الـ Test:** `[U1]` المؤسس رجع Admin.
- **اتجاه الإصلاح:** إما مسح `is_tenant_admin` لما الـ Role تتشال، أو الـ Backfill يحصل مرة واحدة بس (مثلًا لو مفيش أي User عنده الـ Role)، والأحسن الاتنين مع بعض.

### B1 — 🔴 High — إضافة موظفين أكتر من Seats المدة الجاية المؤكدة

- **المكان:**
  - `app/Billing/SeatGuard.php` (`claim()` / `usage()`) و `app/Models/Tenant.php::seatLimit()` (حوالي 138–146): بيقروا Seats المدة الحالية بس.
  - `app/Actions/Billing/RenewSubscription.php` بيقارن الـ Seats بالـ Active Users لحظة التأكيد بس.
- **السيناريو:**
  - المدة 1 فيها 5 Seats، وفيه Active User واحد بس.
  - Reduction لـ 2 اتوافق عليها، وبعدها Renewal بدري لـ 2 Seats.
  - لحد ما المدة القديمة تخلص، الشركة تقدر تزود موظفين لحد 5، وتدخل المدة الجديدة بـ 5 Active Users على 2 Seats مدفوعين، طول السنة.
- **دليل الـ Test:** `[B1]` الموظف الزيادة اتضاف، و `NoSeatAvailable` مترماش.
- **اتجاه الإصلاح:** `SeatGuard` يطبّق `min(seats المدة الحالية, seats أي مدة مستقبلية مؤكدة)`.

### S1 — 🟠 Medium — التحايل على Lockout الـ 2FA بـ Login جديد

- **المكان:** `app/Http/Controllers/Platform/TwoFactorChallengeController.php::store()` (حوالي 51–58). الكود بيتحقق من الكود الأول، وبعدها بس بيشيك `tooManyAttempts`. والـ Login الناجح بالـ Password مش بيتحسب على أي Limiter.
- **السيناريو:** مهاجم معاه Password مسروق يعيد الدورة: Login، بعدين محاولة كود، وهكذا. الحد الوحيد اللي فاضل هو `throttle:10,1` لكل IP، وده بيتكسر بتغيير الـ IPs.
- **دليل الـ Test:** `[S1]` المهاجم بقى Authenticated بعد Lockout.
- **اتجاه الإصلاح:** شيك `RateLimiter::tooManyAttempts($key, 5)` **قبل** `verifyCodeOrRecoveryCode`، زي ما `SupportSessionController::reauthenticate` بيعمل بالظبط.

### U4 — 🟠 Medium — تغيير الـ Password مش بيقفل الجلسات التانية

- **المكان:**
  - `resources/views/pages/settings/⚡security.blade.php` (حوالي 46–49): بيحدّث الـ Password و `must_change_password` بس.
  - مفيش `logoutOtherDevices` ولا `AuthenticateSession` ولا مسح لصفوف `sessions` في المشروع كله.
  - ونفس المشكلة في `ResetUserPassword`.
- **السيناريو:**
  - حد شاف الـ Temporary Password، سواء صاحب صلاحية `employees.create` أو الوسيط اللي نقله، يعمل Login قبل الموظف.
  - الموظف يغيّر الـ Password، وجلسة المهاجم تفضل شغالة كأنها الموظف.
- **دليل الـ Test:** `[U4]` صف الجلسة التانية فضل موجود.
- **اتجاه الإصلاح:** بعد أي تغيير أو Reset للـ Password:
  - امسح صفوف `sessions` بتاعة المستخدم ما عدا الحالية، وهي في الـ Tenant DB.
  - غيّر `remember_token`.

### B2 — 🟠 Medium — زيادة الـ Seats مش بتلغي Reduction متجدولة

- **المكان:**
  - `app/Actions/Billing/ReviewSeatChangeRequest.php::approve()`: فرع الـ Increase (حوالي 109–133) مش بيعمل Supersede للـ Reductions، وفرع الـ Reduction بس اللي بيعمل كده (حوالي 136–138).
  - `RenewSubscription::quote()` (حوالي 56–63) بيستخدم `$seats ??= $reduction->seats`.
- **السيناريو:** المدة فيها 2 Seats → Reduction لـ 1 اتوافق عليها → Increase بـ +3 اتوافق عليه واتدفع تمنه (بقوا 5) → في الـ Renewal العرض الافتراضي بقى 1 Seat.
- **دليل الـ Test:** `[B2]` الـ `scheduledReduction` فضل موجود.
- **اتجاه الإصلاح:** الموافقة على Increase تعمل Supersede لأي Reduction متجدولة، أو على الأقل تتجاهل أي Reduction أقل من الـ Seats الحالية.

### S2 — 🟡 Low — نهاية الـ Impersonation بتغيّر Remember Token بتاع صاحب الحساب

- **المكان:**
  - `app/Impersonation/Impersonator.php`: `Auth::guard('web')->logout()` في `beginSupport()` (حوالي سطر 41) وفي `terminate()` (حوالي سطر 199).
  - `app/Http/Middleware/EnforceImpersonation.php` (حوالي 59–65): بيسلّم لـ Fortify `destroy()`، وده كمان بيعمل `logout()`.
- **السيناريو:** `SessionGuard::logout()` بيولّد `remember_token` جديد للمستخدم الحالي، وده الهدف. فكل جلسة دعم تخلص بتلغي "Remember me" عند الـ Tenant Admin الحقيقي على كل أجهزته. وده بيعارض توثيق M5 §5.3.
- **دليل الـ Test:** `[S2]` التوكن اتغير من `keep-me`.
- **اتجاه الإصلاح:**
  - استخدم `logoutCurrentDevice()` في `terminate()` و `beginSupport()`.
  - في فرع `/logout` جوه `EnforceImpersonation`، نادي `terminate()` واعمل Redirect بدل ما تسلّم لـ Fortify.

---

## 4) ملاحظات متأكد منها من الكود بس (أولوية أقل، من غير Runtime proof)

### Users & Permissions (M2)

- **Medium:** Demote أو Deactivate متزامن بين اتنين Admins ممكن يسيب الشركة من غير ولا Admin نشط.
  - المكان: `UpdateEmployee.php`. أول قراءة بتحصل قبل `lockForUpdate`، فتحت REPEATABLE READ العد بيبقى على Snapshot قديم.
  - الإصلاح: خد الـ Lock في أول الـ Transaction، أو اعمل العد بـ `lockForUpdate`.
- **Medium:** Self-deletion في `⚡delete-user-modal.blade.php` بيشيك `isLastActiveTenantAdmin()` من غير Transaction ولا Lock، فحذف متزامن من Admin-ين يعدّي.
- **Low–Medium:** `POST /forgot-password` بيكشف وجود الإيميل ("We can't find a user…")، ومن غير Throttle بالـ IP.
- **Low:** `RolePolicy::update/delete` مش بتراعي مين شايل الـ Role، فممكن تنقص صلاحيات User أعلى من الفاعل عن طريق Role مشتركة.
- **Low:** `EnsurePasswordIsChanged` مش متسجل كـ Livewire Persistent Middleware.
- **Low:** تخصيص Role `Employee` بصلاحية مش موجودة عند صاحب `employees.create` بيكسر إضافة الموظفين من غير رسالة واضحة.
- **Low:** الإيميل مش بيتحول Lowercase في Profile Update. ده آمن حاليًا بسبب الـ Collation `_ci` بس.

### Billing (M4)

- **Medium:** التأكيد مش مربوط بالأسعار اللي ظهرت في المعاينة. ده في `RenewSubscription` و `ActivateSubscription` والموافقة على الـ Seats. لو حد عدّل الـ Plan بين المعاينة والتأكيد، يتعمل Charge بمبلغ محدش شافه، والـ Charges Append-only.
  - الإصلاح: ابعت الأسعار أو الـ Total اللي اتعاينوا وارفض لو فيه اختلاف.
- **Low–Medium:** بعد Renewal بدري، `RequestSeatChange` بيتحقق بالمدة القديمة (`termOn(today)`)، فالـ Reduction ممكن توصل متأخر سنة، أو "Reduction" تبقى زيادة فعليًا.
- **Low:** Deadlock محتمل بين `approve` (اللي بيقفل الـ Request وبعدين الـ Subscription) و `renew` (اللي بيقفل الـ Subscription وبعدين بيعمل Update على الـ Requests)، والنتيجة 500.
  - الإصلاح: اقفل الـ Subscription الأول في `approve`.
- **Low:** إعادة استخدام Submission Token على Charge تانية بترجّع Payment الـ Charge التانية على إنها نجاح.

### Tenancy (M1/M3)

- **Medium:** Fatal أو Timeout أثناء الـ Provisioning بيسيب الـ Tenant `provisioning` للأبد، من غير Audit Entry. والـ Retries مش Serialized: اتنين `tenants:provision` في نفس الوقت يعني Migrate/Seed مرتين، وواحد من الأدمنز يشوف Temporary Password مش شغال.
  - الإصلاح: سجل الـ Audit قبل البدء + Atomic Claim (`UPDATE … WHERE status IN (pending, failed)`) + معالجة صريحة لـ `provisioning` القديمة.
- **Low:** إنشاء نفس الـ Subdomain مرتين في نفس اللحظة بيرجّع 500 بدل Validation Error.
  - الإصلاح: Catch لـ `UniqueConstraintViolationException`.
- **Low:** الـ Audit Append-only على مستوى الـ Model Events بس. `query()->update()` و `DB::table` بيعدّوا عليه.

### Impersonation (M5) — مفيش ثغرة حالية، لكن محتاج Tests حماية

- ترتيب CSRF قبل `EnforceImpersonation` صحيح دلوقتي بالصدفة (ترتيب الـ Middleware). ومن غيره، `/logout` مزوّر ممكن يشيل الـ Marker ويسيب Tenant Admin غير مقيد.
- Livewire `__dispatch` و `updating*` hooks بيشتغلوا قبل Listener الـ Impersonation. مفيش استخدام حالي، لكن أول `#[On]` على صفحة مش في الـ Allowlist هيبقى ثغرة.
- الـ Handoff مش مربوط بالـ IP اللي بدأ الجلسة (`handoff_ip` بيتسجل بس مش بيتقارن).
- `regenerateRecoveryCodes` بيقبل كود من Secret لسه متأكدش.

### اتراجع ولقيناه سليم

- عزل Central/Tenant DB.
- الـ Sessions بتتخزن في Tenant DB، والـ Cookies Host-only.
- إعادة ضبط الـ Permission Cache مع كل Tenant Switch.
- الـ Suspension بيتقري من الـ Central DB في كل Request.
- الـ Handoff Token: Hash فقط، Single-use بـ Conditional UPDATE، مربوط بالـ Tenant.
- الـ TOTP Replay ممنوع، والـ Recovery Codes Single-use.
- الـ Livewire Uploads مقفولة.
- حسابات الفلوس: Integer Minor Units، و HALF_UP مرة واحدة، والسنين الكبيسة صح.
- منع الـ Overpayment تحت Lock.
- منع الـ Livewire Tampering في Employees/Roles.

---

## 5) خطة الإصلاح المقترحة — Milestone 5.1: Security & Billing Hardening

> **لسه متعتمدتش من صاحب المشروع.** اسأل قبل التنفيذ.

1. **T1:** تثبيت الـ Hosts + Tests للـ Hosts المزيفة ولـ Host لينك الـ Reset.
2. **S1, U1, U4, S2:** إصلاحات صغيرة ومحددة.
3. **B1, B2** + ملاحظات الـ Billing المتوسطة: ربط التأكيد بالأسعار، و RequestSeatChange بعد الـ Renewal البدري، وترتيب الـ Locks.
4. Race conditions: آخر Admin، و Self-deletion، والـ Provisioning Claim، والـ Subdomain Race.
5. نقل الـ 8 Review Proofs لملفاتها الدائمة كـ Regression Tests، وحذف `tests/Feature/Review/`.
6. تحديث `docs/SAAS_MVP_READINESS.md`:
   - مشكلة الـ Password=6 اتحلت. الكود فيه `Password::min(12)` والـ Working Tree كان نضيف.
   - إضافة الـ Findings دي ونتيجتها.
7. Documentation جديد `docs/MILESTONE_05_1_SECURITY_BILLING_HARDENING.md` كآخر Commit، حسب `CLAUDE.md`.

---

## 6) ملاحظات بيئة الجهاز اللي اتعملت عليه المراجعة (مش مشاكل في الكود)

> دي خاصة بجهاز المراجعة. على الجهاز الجديد افحص بيئتك بنفسك.

- MySQL هنا 8.0.30، والـ Handover كان بيقول 8.4.3.
- **`partial_revokes = ON`** على الـ MySQL ده، بسبب مستخدم من مشروع تاني (`pos_provisioner`). ده بيخلي Grant الـ Wildcard `fcl\_%` يتعامل كاسم حرفي، فـ `fcl_app` ميقدرش يعمل `fcl_test_tenant_*` ولا `fcl_tenant_*`.
  - الحل المؤقت اللي صاحب المشروع وافق عليه: صلاحيات Global لـ `fcl_app`.
  - **أوامر الـ REVOKE الحامية للقواعد التانية لسه متنفذتش.** متكررش الإعداد ده على Staging/Production.
  - على جهاز MySQL فيه `partial_revokes = OFF`، الـ Grant الأصلي من README §F كافي.
- `APP_KEY` كان فاضي في `.env` على الجهاز ده. الاختبارات اشتغلت بمفتاح مؤقت عن طريق Environment Variable. التطبيق محتاج `php artisan key:generate` قبل التشغيل في المتصفح.
- `package-lock.json` اتعدل خارج المراجعة، والغالب إنه من `npm install`: اسم المشروع اتغير لـ `FCL-SaaS-Platform` واتشالت حقول `libc`. **متعملوش Commit من غير مراجعة.**

---

## 7) حالة Git وقت كتابة التقرير

- Branch: `main`، متزامن مع `origin/main` (`822e937`)، وفيه Remote: `github.com/islamkabbary/FCL-SaaS-Platform`.
- بعد المراجعة اتعمل Commit واتعمل Push على `main` للحاجات دي:
  - `docs/FCL_SaaS_Complete_Project_Handover_2026-10-19.md`
  - `tests/Feature/Review/ReviewFindingsTest.php` (Opt-in)
  - `docs/FCL_SaaS_MVP_Review_Report_2026-10-19.md` (التقرير ده)
- **متعملوش Commit عمدًا:** `package-lock.json` (اتعدل على جهاز المراجعة من غير مراجعة. شوف §6).
- مفيش أي تعديل على كود التطبيق.

---

## Appendix A — `tests/Feature/Review/ReviewFindingsTest.php` (النسخة الكاملة)

الملف موجود في الـ Repository. التشغيل:

```bash
# التشغيل العادي: الـ 8 بيتعملهم Skip
php -d memory_limit=1536M vendor/bin/pest tests/Feature/Review

# تشغيل الإثباتات: المتوقع قبل الإصلاح إن الـ 8 يفشلوا
RUN_REVIEW_PROOFS=1 php -d memory_limit=1536M vendor/bin/pest tests/Feature/Review
```

```powershell
# PowerShell
$env:RUN_REVIEW_PROOFS = '1'; php -d memory_limit=1536M vendor/bin/pest tests/Feature/Review
```

بعد كل إصلاح، الـ Test الخاص بيه لازم ينجح. وبعدها انقله لملفه الدائم من غير شرط الـ Opt-in.

```php
<?php

/*
 * Review proofs (2026-10-19), see docs/FCL_SaaS_MVP_Review_Report_2026-10-19.md.
 * Each test asserts the CORRECT behaviour, so it fails while the defect it documents
 * exists. They are opt-in so the regular suite stays green:
 *
 *   RUN_REVIEW_PROOFS=1 php vendor/bin/pest tests/Feature/Review
 *
 * When a finding is fixed, move its test to the permanent suite.
 */

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RenewSubscription;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\SyncEmployeeRoles;
use App\Actions\Tenancy\SyncTenantAuthorization;
use App\Enums\SeatRequestType;
use App\Enums\TenantRole;
use App\Exceptions\NoSeatAvailable;
use App\Models\PlatformAdmin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    if (! getenv('RUN_REVIEW_PROOFS')) {
        $this->markTestSkipped('Review proof: run with RUN_REVIEW_PROOFS=1.');
    }
});

test('[T1] hosts that merely end with the central domain string do not resolve a tenant', function (string $host) {
    provisionTenant('acme');

    $this->get("http://{$host}/login")->assertNotFound();
})->with(['acme.evillocalhost', 'acme.globex.localhost']);

test('[U1] syncing authorization does not re-promote a demoted founder', function () {
    actingInTenant();
    $founder = tenantAdminUser();
    $second = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);
    $employeeRole = Role::findByName(TenantRole::Employee->value, 'web');

    app(SyncEmployeeRoles::class)->handle($second, $founder, [$employeeRole->id]);
    expect($founder->refresh()->isTenantAdmin())->toBeFalse();

    app(SyncTenantAuthorization::class)->handle();

    expect($founder->refresh()->isTenantAdmin())->toBeFalse();
});

test('[U4] changing the password ends the user\'s other sessions', function () {
    actingInTenant();
    $user = User::factory()->create(['password' => Hash::make('password')]);

    DB::connection('tenant')->table('sessions')->insert([
        'id' => str_repeat('x', 40), 'user_id' => $user->id, 'ip_address' => '10.0.0.9',
        'user_agent' => 'other device', 'payload' => base64_encode(serialize([])), 'last_activity' => time(),
    ]);

    $this->actingAs($user);
    Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(DB::connection('tenant')->table('sessions')->where('id', str_repeat('x', 40))->exists())->toBeFalse();
});

test('[B1] users cannot be added beyond the seats of an already confirmed next term', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 09:00:00'));
    $platformAdmin = PlatformAdmin::factory()->create();
    $plan = testPlan();
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($platformAdmin, $tenant, $plan, 5, CarbonImmutable::today());

    // Only the Tenant Admin is active. A reduction to 2 seats is approved.
    tenancy()->initialize($tenant);
    $admin = tenantAdminUser();
    $reduction = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Reduction, 2);
    tenancy()->end();
    app(ReviewSeatChangeRequest::class)->approve($platformAdmin, $reduction);

    // Early renewal for 2 seats (term 2 starts 2027-01-15).
    $this->travelTo(CarbonImmutable::parse('2026-12-20'));
    app(RenewSubscription::class)->handle($platformAdmin, $tenant, $plan, 2, false);

    tenancy()->initialize($tenant);
    app(CreateEmployee::class)->handle($admin, ['name' => 'One', 'email' => 'one@acme.test']);

    // A third active user would exceed the 2 seats already confirmed for term 2.
    expect(fn () => app(CreateEmployee::class)->handle($admin, ['name' => 'Two', 'email' => 'two@acme.test']))
        ->toThrow(NoSeatAvailable::class);
});

test('[B2] an approved increase cancels an earlier scheduled reduction', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00'));
    $platformAdmin = PlatformAdmin::factory()->create();
    $tenant = actingInTenant(subscribed: false);
    subscribeTenant($tenant, seats: 2, daysAgo: 184); // term 2026-03-01 → 2027-03-01
    $admin = tenantAdminUser();

    $reduction = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Reduction, 1);
    app(ReviewSeatChangeRequest::class)->approve($platformAdmin, $reduction);
    $increase = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Increase, 3);
    app(ReviewSeatChangeRequest::class)->approve($platformAdmin, $increase); // term now has 5 seats

    $this->travelTo(CarbonImmutable::parse('2027-02-15'));
    $quote = app(RenewSubscription::class)->quote($tenant);

    expect($quote->scheduledReduction)->toBeNull()
        ->and($quote->seats)->toBe(5);
});

test('[S1] a fresh password login stays locked out after too many wrong 2FA codes', function () {
    $admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    $secret = enableTwoFactorFor($admin);
    $login = fn () => $this->post(route('platform.login.store'), ['email' => 'ops@platform.test', 'password' => 'password']);

    $login();
    foreach (range(1, 5) as $attempt) {
        $this->post(route('platform.two-factor.challenge.store'), ['code' => '000000']);
    }

    $login(); // the stolen password is still correct
    $engine = app(Google2FA::class);
    $this->post(route('platform.two-factor.challenge.store'), ['code' => $engine->oathTotp($secret, $engine->getTimestamp())]);

    $this->assertGuest('platform');
});

test('[S2] ending support access does not rotate the target\'s remember-me token', function () {
    enableSupportAccess();
    $platformAdmin = PlatformAdmin::factory()->create();
    $tenant = provisionTenant('acme')->tenant;
    $targetId = $tenant->run(fn (): int => User::role(TenantRole::TenantAdmin->value)->firstOrFail()->id);
    $tenant->run(fn () => User::find($targetId)->forceFill(['remember_token' => 'keep-me'])->save());

    [, $token] = startSupportSession($platformAdmin, $tenant);
    postHandoff($tenant, $token);
    $this->post($tenant->url('impersonation/end'));

    expect($tenant->run(fn () => User::find($targetId)->remember_token))->toBe('keep-me');
});
```
