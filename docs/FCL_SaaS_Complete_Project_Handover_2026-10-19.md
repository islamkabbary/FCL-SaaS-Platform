# FCL SaaS Platform — Complete Project Handover & Decision Register

> **تاريخ إعداد الملف:** 19 أكتوبر 2026  
> **الغرض:** نقل السياق الكامل لمشروع FCL SaaS Platform إلى جهاز آخر، أو حساب ChatGPT/Claude مختلف، أو مطور جديد، من دون إعادة شرح ما حدث.  
> **اللغة:** شرح عربي مصري مع إبقاء أسماء الأدوات والمفاهيم التقنية بالإنجليزية.  
> **حالة المعلومة:** هذا الملف مبني على القرارات المؤكدة في النقاش، وتقارير Claude Code التي نقلها صاحب المشروع، ونجاح اختبارات المتصفح اليدوية التي أكدها صاحب المشروع. **لم أقم بفحص نسخة الـ Git المحلية أو ملفات الكود بنفسي عند إعداد هذا المستند.**  
> **مهم:** يجب مراجعة الكود والمستندات الموجودة في الـ Repository عند الانتقال لجهاز جديد؛ فهي مصدر الحقيقة للحالة الحية. هذا الملف هو مرجع للسياق والقرارات والتقدم حتى تاريخ إعداده.

> **تحديث 20 أكتوبر 2026 (بعد التحقق من الـ Repository نفسه):**
> - **Milestone 5 خلصت** وليها Commits وتوثيق: `docs/MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md` و`docs/SAAS_MVP_READINESS.md`. لسه محتاجة Manual Browser Smoke Test من صاحب المشروع.
> - اتعملت **مراجعة عميقة** لـ Milestones 1–5 (`docs/FCL_SaaS_MVP_Review_Report_2026-10-19.md`) ولقت 7 مشاكل مثبتة بالتشغيل، وبعدها **Milestone 5.1 — Security & Billing Hardening** صلحتهم كلهم ومعاهم الملاحظات المتوسطة. التفاصيل في `docs/MILESTONE_05_1_SECURITY_BILLING_HARDENING.md`.
> - تعديل الـ Password Minimum من 12 لـ 6 **مبقاش موجود**: الكود فيه `Password::min(12)` والـ Working Tree نضيف.
> - **فيه Remote دلوقتي:** `github.com/islamkabbary/FCL-SaaS-Platform`. Commits الـ Handover والمراجعة اتعملها Push بإذن صاحب المشروع؛ Commits الـ Milestone 5.1 **محلية ومتعملهاش Push**.
> - الاختبارات بعد 5.1: **427 test، 426 passed، 1 skipped**. و`composer test` بقى بيكمّل لآخره.
> - الخطوة الجاية: Browser Smoke Test لـ M5 و M5.1، وبعدها قرارات Production (2FA إلزامي للـ Platform Admin، والسياسات التجارية)، وبعدين تحديد أول FCL Operations Milestone.

---

## 0) اقرأ هذا أولًا — الحالة الحالية في دقيقة

**المشروع:** منصة SaaS متعددة الشركات لشركات Freight Forwarding، تبدأ مستقبلًا بتشغيل **FCL Import فقط**.  
**القرار الاستراتيجي:** إنهاء **SaaS Management MVP أولًا** ثم الانتقال إلى **FCL Operations**، وليس بناء الاثنين بالتبادل.

| Milestone | المجال | الحالة حتى آخر محادثة | تحقق صاحب المشروع على المتصفح |
|---|---|---|---|
| 1 | SaaS Foundation | مكتمل، موثق، committed على `main` | نجح |
| 2 | Tenant Users & Permissions | مكتمل، موثق، committed على `main` | نجح |
| 3 | Central SaaS Platform Management | مكتمل، موثق، committed على `main` | نجح |
| 4 | Annual Subscriptions, Seats & Billing | مكتمل، موثق، committed على `main` | نجح |
| 5 | Support, Impersonation & Security Finalization | ~~تم إعداد Prompt تفصيلي لتنفيذه؛ لا يوجد تأكيد صريح في المحادثة أنه أُرسل بالفعل أو بدأ/اكتمل تنفيذه~~ **تحديث 20 أكتوبر:** مكتمل، موثق، committed على `main` | لم يؤكد بعد |
| 5.1 | Security & Billing Hardening (إصلاحات المراجعة العميقة) | مكتمل، موثق، committed على `main` (20 أكتوبر 2026، بدون Push) | لم يؤكد بعد |
| المرحلة التالية | FCL Import Operations | **لم يبدأ تنفيذها**؛ المتطلبات التشغيلية التفصيلية لم تعتمد بعد | — |

**آخر أرقام اختبار مُبلّغ عنها من Milestone 4:** `280` اختبارًا، `279 passed`، `1 skipped` (2FA غير مفعّل في ذلك الوقت)، و`PHPStan 0 errors`، وPint و`npm run build` ناجحان، و`npm audit: 0 vulnerabilities`.

**Git عند آخر تقرير Milestone 4:** فرع `main`، بدون Remote، لا Push، Commits باسم **Islam Kabbary**. **كان هناك تعديل واحد غير committed خارج النطاق في `app/Console/Commands/CreatePlatformAdmin.php` يخفض الحد الأدنى لكلمة مرور Platform Admin من 12 إلى 6.** لا تفترض أن Working Tree نظيف اليوم؛ راجع تقرير Milestone 5 و`git status` قبل أي إجراء.

**وثائق موجودة بالفعل بحسب التقارير:**
- `docs/MILESTONE_01_SAAS_FOUNDATION.md`
- `docs/MILESTONE_02_TENANT_USERS_PERMISSIONS.md`
- `docs/MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md`
- `docs/MILESTONE_04_SUBSCRIPTIONS_BILLING.md`
- `CLAUDE.md` — قواعد العمل المستمرة مع Claude Code.

**وثائق مطلوبة من Milestone 5 وليست مؤكدة التنفيذ بعد:**
- `docs/MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md`
- `docs/SAAS_MVP_READINESS.md`

**طلب جانبي معلق:** تكليف Session ثانية من Claude Code بتحديث **`README.md` فقط** لشرح تثبيت وتشغيل المشروع من الصفر على جهاز مطور آخر. لا توجد رسالة تؤكد أنها أنهت التعديل، ولا تعتبره منفذًا أو committed دون التحقق.

---

## 1) فكرة المشروع ودوافعه

### 1.1 الهدف التجاري

بناء منتج **Multi-Tenant SaaS** تستطيع من خلاله عدة شركات Freight Forwarding مستقلة استخدام نفس المنصة، مع قاعدة بيانات منفصلة لكل شركة. أول مجموعة عمليات سيتم بناؤها فوق المنصة هي **FCL Import** لشركات الشحن والتخليص، انطلاقًا من احتياجات شركة حقيقية في مصر تستخدم Excel ومستندات متفرقة.

المنتج **ليس** مشروع LCL Import آخر، وليس ERP شاملًا الآن، ولا نظام Export أو Air Freight. لا تخلط المشروع بمشاريع المستخدم الأخرى.

### 1.2 الواقع التشغيلي المعروف لشركة الشحن

- نوع الشحن الأول المستهدف: **Full Container Load Import — FCL Import**.
- الشحنة قد تصل عن طريق **Origin Agent** بالخارج أو عن طريق **Direct Shipping Line**.
- لدى الشركة **فريق تخليص جمركي داخلي**.
- معلومات التشغيل الحالية موزعة على Excel ومستندات منفصلة.
- طريقة تحصيل الإيراد وحساب التكلفة **قد تختلف من شحنة إلى أخرى**.
- الهدف التشغيلي النهائي: متابعة الملف من الـ Pre-alert حتى الإفراج والتسليم والإغلاق التشغيلي والمالي.
- تفاصيل العمل الواقعي تحتاج التحقق من موظفي Operations/Documentation/Customs/Finance وملفات الشحن الحقيقية عندما تتاح.

### 1.3 لماذا بدأنا بالـ SaaS قبل الـ FCL؟

المستخدم قرر التركيز على أساس منتج يمكن بيعه وإدارته كمنصة متعددة العملاء أولًا: إنشاء الشركات وعزلها، إدارة الموظفين والصلاحيات، إدارة الاشتراكات والمقاعد، ثم الدعم الفني والأمان. بعد اكتمال **SaaS MVP** نبدأ Modules الـ FCL تدريجيًا. **لا تقترح القفز إلى Shipments قبل إغلاق Milestone 5 وتقييم الجاهزية** إلا بتوجيه صريح جديد من صاحب المشروع.

---

## 2) السجل الأساسي للقرارات المعتمدة — Business & Architecture

### 2.1 نموذج الـ Tenant والفروع

| الموضوع | القرار المتفق عليه |
|---|---|
| Tenant | شركة Freight Forwarding واحدة مستقلة |
| Tenant Database | **Database Per Tenant**؛ Central/Landlord DB منفصلة |
| الدخول | Subdomain مستقل لكل شركة (`acme.localhost` محليًا) |
| Branches | داخل الشركة نفسها، وليست Tenants منفصلة |
| بدء الشركة | إنشاء **Default Branch** تلقائيًا مع الشركة (`MAIN`) |
| إنشاء فروع إضافية | **ممنوع حاليًا من Tenant Admin**؛ مؤجل |
| الموظف | له **Primary Branch واحد** |
| وصول الموظفين لفروع مختلفة | مستقبلًا: Own Branch / Selected Branches / All Branches وفق Permissions |
| موظف متعدد الفروع | Primary Branch واحد، ويمكن السماح له بالعمل في فروع أخرى بصلاحيات لاحقًا |
| إنشاء الشركة | **Manual Onboarding** بواسطة Platform Admin فقط، لا Public Tenant Self-Registration |
| من يدير الحسابات الداخلية | Tenant Admin |
| رؤية Platform Admin لبيانات الشركات | ليست تلقائية؛ وصول دعم استثنائي مؤقت ومنضبط لاحقًا |

### 2.2 قواعد الـ Shipment المستقبلية (لم تُنفذ بعد)

- كل Shipment له **Primary Branch واحد**.
- يمكن لموظفي فروع أخرى المشاركة وفق الصلاحيات.
- تغيير الـ Primary Branch يتطلب **صلاحية خاصة وسببًا وتسجيلًا للفرع القديم والجديد**.
- قبل نقل Shipment لفرع جديد يجب مراجعة المسؤولين المعيّنين والـ Open Tasks وتأكيد إعادة التوزيع أو استمرارها.
- يجب فصل صلاحيات الفروع عن مفهوم عزل الشركات بالـ Database.
- لا تنفذ هذه المتطلبات داخل SaaS Management Milestones؛ ستظهر عند بناء FCL Operations.

### 2.3 هيكل الإدارة والمصادقة

- **Platform Admin:** مسؤول المنصة المركزي، ينشئ الشركات ويديرها ويدير اشتراكاتها ومدفوعاتها.
- **Tenant Admin:** مسؤول داخل شركة واحدة يدير موظفيها وأدوارها وصلاحياتها، ضمن حدود الـ Seats المشتراة بعد Milestone 4.
- **Employee:** مستخدم داخل Tenant بصلاحيات محدودة.
- Central Platform Admin Accounts منفصلة عن Tenant User Accounts والـ Authentication Guards.
- التسجيل العام للـ Tenant والعملاء **معطل**؛ التهيئة والتفعيل يدويان.
- حماية بيانات شركات مختلفة **Backend-enforced**؛ لا يكفي إخفاء الأزرار.

### 2.4 قرارات الاشتراكات المؤكدة

| الموضوع | القرار |
|---|---|
| نوع الخطط | Fixed Plans أولًا |
| مدة الاشتراك | **سنوية فقط** |
| التسعير | **Annual Base Fee + Annual Price Per Seat × Purchased Seats** |
| عدد الشحنات | لا حدود على عدد Shipments مبدئيًا |
| المميزات | Core Features مفتوحة في البداية؛ Add-ons مؤجلة |
| مقاعد المستخدمين | كل مستخدم نشط يستهلك Seat؛ **Tenant Admin محسوب** |
| إعادة استخدام Seat | يجوز بعد تعطيل موظف، بدون دفع إضافي |
| زيادة المقاعد أثناء السنة | طلب من Tenant Admin ثم اعتماد Platform Admin |
| قيمة الزيادة | **Proration** حتى تاريخ تجديد نفس الاشتراك |
| تقليل المقاعد | يبدأ عند **التجديد السنوي التالي**، وليس منتصف السنة |
| Refund عند تقليل المقاعد | لا رد تلقائي أثناء السنة |
| تنشيط الاشتراك | يدويًا بواسطة Platform Admin |
| الدفع | تسجيل مالي/تحصيل يدوي في MVP، بلا Payment Gateway |
| السعر التوضيحي القديم | `$10/user` **مثال فقط وليس سعرًا معتمدًا** |

### 2.5 Support Impersonation — قرارات سبق اعتمادها (Milestone 5)

- **Direct Support Access:** Platform Admin يستطيع بدء دخول مؤقت لشركة معينة كـ Tenant Admin **بدون موافقة مسبقة في كل مرة من Tenant Admin**؛ مع تحقق أمني وإشعار للشركة وتدقيق كامل وسبب للدخول.
- هذا ليس حق دخول دائمًا لكل بيانات كل شركة؛ هو Session استثنائية محدودة زمنًا ومقيدة الصلاحيات.
- **Tenant Admin Login As Employee** مسموح مبدئيًا ضمن الشركة نفسها، بنفس مبادئ التقييد والتدقيق.
- نمط التنفيذ المطلوب: **Restricted Interactive Impersonation**؛ يمكن تصفح بعض الوظائف والتفاعل الآمن، لكن العمليات الحساسة مثل تغيير كلمات المرور/الصلاحيات/الاشتراكات/المدفوعات ممنوعة أثناء انتحال الهوية.
- لا Nested Impersonation ولا رفع صلاحيات، وإظهار Banner واضح، وإمكانية إنهاء الجلسة وتسجيل الممثل الحقيقي.
- **مدة 15 دقيقة** و**نقل مؤقت جدًا للهوية** ظهرت كقيم تقنية مقترحة في Prompt Milestone 5، لا تعتبر من الحقائق التي تم التحقق من تنفيذها حتى وصول التقرير.
- كانت هناك إشارة سابقة إلى **Tenant Admin restricted Login As**، وفكرة Platform Admin reauthentication/audit/notification جزء من المتطلبات المؤكدة.

### 2.6 قاعدة مهمة: 3 حالات مستقلة

لا تخلط بين:
1. **Provisioning Status**: `pending` → `provisioning` → `ready` أو `failed`.
2. **Manual Tenant Access Suspension**: نشط أو موقوف إداريًا بواسطة Platform Admin.
3. **Subscription Status / Validity**: وجود اشتراك سنوي ساري أو منتهي/غير مُفعّل.

- تفعيل الاشتراك **لا يلغي Suspension يدويًا**.
- الإيقاف الإداري **لا يحذف** قاعدة البيانات أو الموظفين أو الاشتراك.
- Tenant غير جاهز من ناحية Provisioning لا يعتبر جاهزًا بمجرد دفع اشتراك.

---

## 3) التكنولوجيا والبيئة

### 3.1 التقنيات عند بداية المشروع

| المكون | الإصدار/الاختيار حسب تقرير فحص البداية |
|---|---|
| PHP | 8.4.21 CLI (NTS, x64)، `pdo_mysql` و`mysqli` و`pdo_sqlite` |
| Laravel | 13.37.0 |
| Livewire | 4.4.7 |
| Flux | 2.20.1 |
| Fortify | 1.41.0 |
| Pest | 5.3.3 / pest-plugin-laravel 5.0.1 |
| Laravel Boost | 2.10.3 |
| MySQL | 8.4.3 — Laragon محليًا |
| stancl/tenancy | v3.10.1 تم تثبيتها في Milestone 1 |
| spatie/laravel-permission | v8.3.0 بحسب Milestone 2 |
| brick/math | مستخدمة في Milestone 4 للحسابات العشرية الدقيقة؛ صُرّح بها في Composer |
| Frontend | Laravel Vite/Node/npm، Livewire/Flux |
| DB architecture | MySQL Central + MySQL DB لكل Tenant |
| Runtime pattern | Laravel Modular Monolith وليس Microservices |

**مهم:** هذه أرقام رُصدت وقت التقارير، وليست ضمانًا أن الجهاز الثاني لديه نفس الإصدارات. اقرأ `composer.lock`, `package-lock.json`, `composer.json`, `package.json` على النسخة الحالية.

### 3.2 المشروع المحلي المعروف

- إنشاء المشروع لأول مرة على Windows من Laravel installer داخل مسار يشبه: `C:\Users\islam.k\FCL SaaS Platform` (اسم المجلد يحتوي مسافات).
- الاختيار الأول: Livewire Starter Kit، Fortify Built-in Auth، Single-file Livewire Components، Pest، Boost لClaude Code.
- بدأ المشروع بـ SQLite تجريبيًا، ثم تحول إلى **Central MySQL + Tenant MySQL** في Milestone 1.
- لم تكن توجد إعدادات Teams داخل Starter Kit؛ دور الـ Tenant والشركات مستقل عن ذلك.
- عند فحص أولي، Laragon/MySQL كان `root` بدون Password ويحتوي قواعد بيانات **مشاريع كثيرة أخرى**؛ لهذا اختير مستخدم MySQL مخصص لـ FCL.

### 3.3 قواعد البيانات والمستخدم

- Central DB: `fcl_central`.
- Test Central DB (آخر تقرير): `fcl_central_testing`.
- Tenant DB convention: `fcl_tenant_<uuid>`.
- Disposable tenant tests convention: `fcl_test_tenant_*`.
- MySQL Application User: `fcl_app`، بصلاحيات مقصورة على أسماء قواعد تبدأ `fcl_`، بعد فحص الـ Grants والتأكد من عدم الوصول إلى قواعد المشاريع الأخرى.
- قواعد Grants المعتمدة على Wildcard في MySQL 8.4 **Deprecated**؛ ملائمة لبيئة التطوير الحالية بعد تحقق العزل، لكن يلزم مراجعة سياسة الصلاحيات قبل Production.
- لا تستخدم Root في اتصال التطبيق المعتاد، ولا تشغّل أوامر حذف قواعد بيانات عامة.
- **لا تنقل `.env` أو أي Password إلى هذا الملف**؛ الإعدادات السرية تحفظ خارج Git.

### 3.4 المسارات والروابط المحلية المعروفة

| الخدمة | مثال الرابط |
|---|---|
| Central Platform Login | `http://localhost:8000/admin/login` |
| Central Platform Dashboard | `http://localhost:8000/admin` |
| Tenant Login | `http://acme.localhost:8000/login` |
| Tenant Employees | `http://acme.localhost:8000/employees` |
| Tenant Roles | `http://acme.localhost:8000/roles` |

- `acme` مجرد Subdomain مثالي؛ Tenant الحقيقي لا يظهر إلا لو تم إنشاؤه وتجهيزه.
- اختبارات Laravel تستطيع إرسال Host Headers بدون DNS.
- `*.localhost` عمل مع Chrome/Edge/Firefox بحسب التجارب السابقة؛ على الجهاز الجديد راجع المتصفح، Windows، Firewall، إعدادات الشبكة.
- في التطوير كانت Sessions Cookies من نوع Host-only لتفادي مشاركتها بين المركز والشركات.

### 3.5 أمر تشغيل عام (إرشادي ويجب مطابقته مع README والكود)

```powershell
# بعد تجهيز البيئة وملف .env وقاعدة البيانات والتبعيات
php artisan serve --host=127.0.0.1 --port=8000

# Terminal ثانٍ أثناء تطوير الواجهة
npm run dev
```

قد يستخدم المشروع `npm run build` بدل Vite Dev Server في بعض سيناريوهات التحقق. **هذه ليست تعليمات تأسيس كاملة لجهاز نظيف**؛ راجع ملف `README.md` الحقيقي والتوثيق الخاص بكل Milestone قبل أي Migrations أو Provisioning.

### 3.6 أوامر Artisan تم الإبلاغ عنها

| الأمر | الوظيفة | ملاحظة |
|---|---|---|
| `php artisan platform:create-admin` | إنشاء Platform Admin بكلمة مرور تدخل تفاعليًا | سياسة قوة كلمة المرور قيد ملاحظة أمنية |
| `php artisan tenants:provision <subdomain>` | محاولة استكمال/إعادة Provisioning لـ Tenant فشل | افحص حالة الـ Tenant قبل التنفيذ |
| `php artisan tenants:migrate` | تطبيق Migrations على Tenants الحالية | **يغير قواعد بيانات**؛ راجع الهدف |
| `php artisan tenants:sync-authorization` | Sync Roles/Permissions للـ Tenants القديمة | مطلوب بعد إضافة صلاحيات Billing للـ Tenants القديمة |
| `php artisan test` | تشغيل الاختبارات | قد تنشئ/تحذف قواعد اختبار مؤقتة |
| `composer test` | Pint + PHPStan + Suite بحسب إعداد المشروع | اقرأ script الأصلي |
| `npm run build`, `npm audit` | Build وSecurity Audit | غير مدمر عادةً |

**لا تفترض وجود أمر آخر حتى تراه في `php artisan list` أو الكود.** لا تنفذ Migrations أو اختبارات DB على الجهاز الثاني قبل ضبط بيئة Test معزولة.

---

## 4) رحلة المشروع زمنيًا — كيف وصلنا إلى هنا؟

1. **اكتشاف فكرة FCL Import**: مناقشة احتياجات شركة شحن حقيقية في مصر، workflow أولي، سبعة Modules، وأسئلة عملياتية معلقة بسبب غياب ملفات Excel وStaff Interviews.
2. **التحول إلى SaaS First**: قرار أن يكون لكل Freight Forwarding Company Tenant مستقل وقاعدة بيانات منفصلة، مع فروع داخل Tenant.
3. **حسم الأساس المعماري**: Laravel 13 + Livewire 4 + MySQL + stancl/tenancy + Subdomains + Central Platform منفصلة.
4. **اعتماد Manual Onboarding**: Platform Admin فقط ينشئ الشركة والـ Default Branch وأول Tenant Admin.
5. **اعتماد نموذج الاشتراك**: Fixed Annual Plans، Base Fee + Seats، Proration للزيادة، تخفيض المقاعد في التجديد القادم.
6. **وضع خطة 5 Milestones** لإكمال SaaS Management قبل FCL Operations.
7. **تأسيس مشروع Laravel Starter Kit** على Windows/Laragon وفحص الـ Baseline مع Claude Code.
8. **تنفيذ Milestones 1–4** بتقارير مفصلة، اختبارات آلية، Real-server Checks، Docs، Commits على `main`، ثم Manual Browser Smoke Tests ناجحة من صاحب المشروع.
9. **إعداد Prompt Milestone 5** للدعم الفني/انتحال الهوية والتأمين النهائي، مع ملاحظة أن نتيجة تنفيذها لم تصل بعد لهذه المحادثة.
10. **تكليف جلسة جانبية** بتوثيق التشغيل داخل `README.md` فقط لمطور جديد؛ النتيجة غير مؤكدة بعد.

---

## 5) Milestone 1 — SaaS Foundation

### 5.1 الحالة

**تم التنفيذ والتوثيق والـ Commits، ونجح اختبار المتصفح اليدوي.**

### 5.2 ما تم تنفيذه

- Central Platform على `localhost:8000` بقاعدة `fcl_central`.
- حسابات `PlatformAdmin` منفصلة بGuard/Table/Login، مع Rate-limited Login.
- شاشة قائمة Tenants وإنشاء Tenant.
- تثبيت `stancl/tenancy` v3.10.1، قاعدة MySQL لكل Tenant.
- Tenant Identification بواسطة Subdomain.
- فصل Central Routes عن Tenant Routes (ومنها `routes/tenant.php`).
- Provisioning: إنشاء سجل الشركة والدومين → قاعدة Tenant → Tenant Migrations → Default Branch (`MAIN`) → أول Tenant Admin → `ready`، أو `failed` مع سبب مناسب.
- أمر Retry آمن لا يحذف تلقائيًا أي Database.
- كلمة مرور أول Tenant Admin قوية وعشوائية، تُعرض مرة واحدة فقط، ويجب تغييرها في أول Login.
- Fortify Tenant Login من Starter Kit مع تعطيل Public Registration.
- Tenant Admin لا يستطيع إضافة فروع جديدة (`BranchPolicy` تمنع، ولا توجد Route للإضافة).
- مستخدم FCL منفصل لقواعد بيانات المشروع.

### 5.3 مشاكل اكتشفها Claude وأصلحها

1. **Tenant Sessions كانت تُحفظ في Central Database** بسبب وقت تهيئة Session مع Fortify؛ جرى إصلاح التعامل مع اتصال التخزين.
2. **Session من Tenant A كانت تؤدي للبحث عن مستخدم في Tenant B قبل الرفض**؛ تم تقديم فحص Tenant Session قبل User Lookup.
3. **Regression أدى إلى 404 لكل صفحات Tenant على Real Server** أثناء إصلاح Static Analysis؛ تم الإصلاح وإضافة Regression Test.
4. كانت هناك npm Critical Vulnerabilities في Build-time Tool Chain: `vite-plus` → `oxfmt` → `tinypool`. الترقية المباشرة لـ `vite-plus 0.3.3` كسرت الـ Build؛ جرى استعمال npm override على `tinypool 2.1.2` بعد تحقق التوافق، وأصبح Audit = 0. هذا حل مؤقت لحين ترقية مدعومة للـ Tooling.
5. مشكلة Factory `withTwoFactor()` والـ PHPStan scoped exception تمت معالجتهما بشكل يتيح `composer test` ناجحًا.

### 5.4 التحقق

- `74 tests`: **73 passed** و**1 skipped** (2FA Disabled)، 218 assertions.
- `composer test` / Pint / PHPStan ناجحة بحسب التقرير النهائي.
- npm Audit = 0 وFrontend Build ناجح.
- Real-server Checks على قواعد اختبار فعلية وجلسات وكوكيز؛ تم رفض Cross-tenant Credentials/Sessions.
- صاحب المشروع أكد نجاح Manual Smoke Test.

### 5.5 Commits — Milestone 1

| Hash | Commit |
|---|---|
| `8ef5303` | `fix(deps): patch critical tinypool advisories with an npm override` |
| `b4581ce` | `fix(starter-kit): restore UserFactory::withTwoFactor and scope PHPStan trait check` |
| `6eefd78` | `feat(tenancy): install stancl/tenancy with MySQL database-per-tenant isolation` |
| `58a066a` | `feat(tenancy): add tenant database schema with branches and primary branch` |
| `d33f8fa` | `feat(provisioning): provision tenant database, default branch and tenant admin` |
| `8ad71f0` | `feat(auth): add platform admin authentication and tenant management UI` |
| `71d5c54` | `feat(tenancy): add subdomain routing and isolated tenant authentication` |
| `ee697eb` | `test(tenancy): verify provisioning, tenant isolation and platform authentication` |
| `9b81fb3` | `docs(saas): document Milestone 1 implementation and verification` |

**Documentation:** `docs/MILESTONE_01_SAAS_FOUNDATION.md`.

---

## 6) Milestone 2 — Tenant Users & Permissions

### 6.1 الحالة

**تم التنفيذ والـ Commits، ونجح Manual Browser Smoke Test.**

### 6.2 الوظائف المنفذة

- صفحة `/employees`: List، Search، Role/Status Filters، Create، Edit، Activate/Deactivate، Role Assignment، Primary Branch.
- الموظف ينضم حاليًا إلى **Main Branch** فقط.
- Temporary Password تعرض مرة واحدة مع Forced Change عند أول دخول.
- تعطيل Employee يلغي Sessions المخزنة وRemember Token، ويرفض Login جديدًا؛ الحظر يطبق أيضًا على Livewire.
- صفحة `/roles`: CRUD للـ Custom Roles مع إدارة Permissions.
- سبع صلاحيات أولية تخص الموظفين والأدوار، مثل `employees.view`, `employees.create`, `employees.update`, `employees.deactivate`, `roles.view`, `roles.manage`, `roles.assign`.
- Role `Tenant Admin` محمي وله الصلاحيات اللازمة، وRole `Employee` مبدئيًا بدون Admin Privileges.
- لا يسمح للموظف بمنح نفسه صلاحيات أعلى، ولا بتعديل صلاحيات مستخدم أعلى، ولا بإلغاء آخر Tenant Admin نشط، ولا تغيير Roles الخاصة بنفسه حيث يمنع التصعيد.
- ترقية Tenants القديمة عبر `tenants:migrate` ثم `tenants:sync-authorization`؛ New Tenants تحصل على Roles خلال Provisioning.

### 6.3 مشكلة أمنية مهمة جدًا اكتُشفت

التكامل التقليدي بين Stancl وSpatie الذي يغير Permission Cache Key لكل Tenant **لم يكن كافيًا**: بيانات الصلاحيات المحملة بالفعل في **ذاكرة PHP Process** قد تبقى عند الانتقال من Tenant A إلى Tenant B، خاصة في CLI/Queues/Long-running Contexts، وحيث Role IDs تتكرر بين قواعد Tenant. طبق Claude **Bootstrapper يعيد ضبط Permission State عند كل تبديل Tenant**، واختبر Regression يثبت المشكلة بدون هذا الإصلاح.

### 6.4 التحقق

- `108` Tests إجمالًا: **107 passed** و**1 skipped**؛ 34 اختبارًا جديدًا مقارنة بـ Milestone 1.
- PHPStan 0، Pint ناجح، npm Build ناجح، npm Audit 0.
- Real-server check لجلسات تعطيل الموظف والـ Navigation و403.
- صاحب المشروع نفّذ اختبار المتصفح اليدوي وأكده ناجحًا.

### 6.5 Commits — Milestone 2

| Hash | Commit |
|---|---|
| `0af91d3` | `docs(project): define the permanent milestone workflow in CLAUDE.md` |
| `f09ad2e` | `feat(permissions): add tenant-scoped roles and permissions with spatie/laravel-permission` |
| `d895765` | `feat(employees): enforce employee lifecycle, deactivation and privilege rules` |
| `c1a15ed` | `feat(admin): add employee and role management pages for tenant admins` |
| `299c438` | `test(permissions): verify employee management, privilege rules and tenant isolation` |
| `35349c5` | `docs(permissions): document Milestone 2 tenant users and permissions` |

**Documentation:** `docs/MILESTONE_02_TENANT_USERS_PERMISSIONS.md`.

### 6.6 القيود المعروفة

- لا توجد واجهات لإنشاء فروع إضافية أو نقل موظف لفرع آخر.
- لا يوجد زر خاص لإعادة تعيين Temporary Password مفقودة؛ Forgot Password هو المسار المتاح.
- العمود القديم `is_tenant_admin` تُرك لأجل الترقية؛ الصلاحيات الفعلية تعتمد على Tenant Admin Role، وليس هذا العمود.

---

## 7) Milestone 3 — Central SaaS Platform Management

### 7.1 الحالة

**تم التنفيذ والـ Commits، ونجح Manual Browser Smoke Test.**

### 7.2 ما تم تنفيذه

- Admin Dashboard تحت `/admin` يعرض إحصائيات فعلية: Total، Ready & Active، Suspended، Pending، Provisioning، Failed؛ مع Recent Admin Activity.
- Tenants List: Search بالاسم أو Subdomain، Filters حسب Provisioning/Access، Pagination 15.
- Tenant Details: ID، Subdomain، Statuses، Dates، DB Name بدون Credentials، Sanitized Failure Summary وأمر Recovery.
- Edit: **اسم الشركة فقط**؛ لا Subdomain Rename ولا Database Rename.
- Suspend مع سبب إلزامي، Reactivate مع Note اختيارية.
- Central Read-only Audit Trail لعمليات الإنشاء والتعديل والإيقاف وإعادة التفعيل.
- Suspended Tenant يتلقى `403` على كل الطلبات بما فيها Login/Reset Password/Livewire؛ حتى لو لديه Session قائمة.
- Reactivation تعيد الوصول ولا تمس قواعد البيانات أو الموظفين أو الـ Subscription. Session التي ما زالت صالحة يمكن أن تعمل بعد إعادة التفعيل.
- Unknown/Pending/Provisioning/Failed Tenants تظل `404` وفق سلوك الأساس.
- حالة Suspension منفصلة عن Provisioning.

### 7.3 مشكلة مهمة اكتُشفت

في Long-running Processes، `stancl/tenancy` كان قد يحتفظ بـ Tenant Model Cached ويحجب تغيّر Suspension بعد أول Request. أصلح Claude الـ Access Check ليقرأ حالة الـ Tenant من Central DB لكل Request، وأثبت ذلك باختبارات Existing Session وLivewire.

### 7.4 التحقق

- `166` Tests: **165 passed** و**1 skipped**، 628 Assertions.
- 58 Test جديدة، Tests Milestones 1–2 ناجحة.
- Real-server: 24 Check بما فيها التوقف/العودة وإبقاء Tenant B مستقلة.
- `npm audit 0`, Build Pass, Pint/PHPStan Pass.
- صاحب المشروع أكد نجاح تجربة المتصفح.

### 7.5 Commits — Milestone 3

| Hash | Commit |
|---|---|
| `40f61e2` | `feat(platform): add tenant access lifecycle and central audit trail` |
| `c93155e` | `feat(tenancy): refuse every request to a suspended tenant` |
| `2928e14` | `feat(platform): add dashboard and tenant management pages` |
| `7446a98` | `test(platform): verify tenant management, suspension and audit trail` |
| `d405538` | `docs(platform): document Milestone 3 central platform management` |

**Documentation:** `docs/MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md`.

### 7.6 القيود المعروفة

- كل Platform Admin لديه صلاحيات إدارة جميع الشركات؛ لم تُبن Platform-specific Roles.
- Retry Provisioning من CLI فقط.
- Tenant Maintenance Commands قد تعمل على Suspended Tenants عمدًا (للتحديث).
- شاشة 403 بسيطة.
- **Livewire Temporary File Upload Routes** لم تكن Tenant-aware، ولا توجد وظيفة Upload فعلية في هذه المرحلة. أُدرجت كمطلوب أمني واضح في Milestone 5 قبل أي استخدام لرفع ملفات FCL.

---

## 8) Milestone 4 — SaaS Subscriptions & Billing

### 8.1 الحالة

**تم التنفيذ والـ Commits، ونجح صاحب المشروع في اختبار المتصفح اليدوي لكل سيناريوهات Milestone 4.**

### 8.2 Plans & Pricing

- Fixed Annual Plans مع `name`, `currency` يحدد عند الإنشاء، `annual_base_fee`, `annual_price_per_seat`.
- يمكن Archive للـ Plan.
- تغير سعر الخطة لا يغير Snapshot الأسعار للسنوات المفعلة بالفعل.
- لا توجد أسعار أو عملة مفروضة على صاحب المشروع؛ مثال `$10/user` كان إيضاحًا.
- استُخدم `brick/math` للدقة العشرية، بدل Float في المبالغ.

### 8.3 Activation & Entitlements

- Platform Admin يختار Plan، عدد Seats، Start Date اليوم أو سابقًا، يرى Preview، ثم يُنشئ Subscription Term وInitial Charge.
- كل Active User يشغل Seat واحدة، بما فيه Tenant Admin.
- Create/Reactivate Employee مرفوض إذا لا توجد Seat متاحة.
- Deactivate Employee يحرر مقعدًا متاحًا لإعادة استخدامه، بدون Charge إضافي.
- اختبر Claude منع Race Conditions: **6 عمليات متزامنة** على **2 مقعد شاغر**، نتيجتها 2 إنشاء ناجح و4 رفض.

### 8.4 Extra Seats, Proration & Reductions

- Tenant Admin يقدم Request زيادة Seats وتظهر Estimate.
- Platform Admin يراجع المبلغ المحسوب وقت الموافقة ويوافق أو يرفض.
- الزيادة المقبولة تنشط فورًا مع حفظ **نفس Renewal Date**.
- Proration = Additional Seats × Annual Price Per Seat × Remaining Term Days / Total Term Days؛ حساب عدد الأيام الفعلي 365/366 وفق السنة؛ Rounding **Half Up** مرة واحدة لأصغر وحدة نقدية.
- Seat Reduction بعد الموافقة تُجدول للتجديد القادم، بلا إلغاء مستخدمين آليًا.
- Renewal لا يقبل Seat Count أقل من Active User Count وقت التنفيذ.

### 8.5 Annual Renewals

- يفتح Renewal قبل انتهاء السنة بـ **30 يومًا** (تصميم افتراضي اختاره Claude).
- التجديد في الموعد يواصل من تاريخ انتهاء المدة القديمة.
- إذا تغير سعر الخطة يظهر فرق السعر ويتطلب تأكيدًا.
- Late Renewal: بحسب التنفيذ الحالي، يبدأ من يوم التجديد الفعلي ولا تُفوتر فجوة الانقطاع؛ **سياسة تجارية قابلة للمراجعة قبل العملاء الحقيقيين**.
- No Automatic Grace Period بعد انتهاء الاشتراك؛ **سياسة حالية لم تعتمد كتجارة نهائية بشكل منفصل**.
- منع Duplicate Charges عند تكرار طلب التجديد.

### 8.6 Billing & Manual Payments

- إنشاء Charge للـ Initial Annual Term / Prorated Seat Increase / Renewal، مع Line Items.
- يدعم Partial Payments، ويمنع Overpayment.
- حماية Double-submit بحيث لا تتكرر الدفعة.
- Charges وPayments تاريخية وغير قابلة للتعديل/الحذف مباشرة من الواجهة.
- سجلات مالية داخلية للمنصة، **ليست نظام فواتير ضريبية قانوني أو ZATCA/ETA e-Invoice**.
- لا Payment Gateway حاليًا.

### 8.7 Subscription Access

- Tenant بلا اشتراك نشط يمكنه الوصول إلى Login وAccount Settings وصفحات الاشتراك المسموحة، لكن باقي وظائف Tenant محجوبة، بما فيها Livewire Requests والصفحات القائمة.
- Manual Suspension ما زال يحجب كل شيء، ولو الاشتراك Active.
- Tenants قديمة سبقت Milestone 4 تحتفظ مؤقتًا بالوصول بدون Seat Limit حتى تفعيل اشتراك لها؛ **يجب تقييم انتقالها قبل Production**.
- Existing Tenants تحتاج `php artisan tenants:sync-authorization` لإضافة صلاحيات Billing؛ New Tenants تحصل عليها تلقائيًا.

### 8.8 Currency Defaults — اختيارات تنفيذ لم تصبح تسعيرًا معتمدًا

- قائمة خيارات العملات في `config/billing.php`: `AED`, `BHD`, `EGP`, `EUR`, `GBP`, `JOD`, `KWD`, `OMR`, `QAR`, `SAR`, `USD`.
- Currency تُختار مرة واحدة للخطة ولا تتغير عند Edit.
- لا تحويل عملات، ولا Tax Engine، ولا تحديد سعر تجاري نهائي.

### 8.9 Bug أمني أصلحه Claude

Livewire كانت قد تحتفظ بحالة «Security Middleware Already Checked» داخل Long-running PHP Process، مما يتيح Action لاحقة بدون إعادة بعض فحوص Login/Permission/Subscription. جرى Reset لهذه الحالة بعد كل Request مع Regression Test. PHP-FPM المعتاد لم يتأثر بحسب التقرير، لكن الإصلاح مهم لاختبارات/Octane/Long-running Contexts.

### 8.10 التحقق

- `280` Tests: **279 passed** و**1 skipped** (2FA Disabled وقتها).
- 114 Tests جديدة تغطي الـ 35 منطقة المتفق عليها.
- PHPStan 0 وPint و`composer test` ناجحة.
- `npm run build` ناجح و`npm audit` = 0.
- Real-server: 31 Check ناجحة على Test Databases.
- صاحب المشروع **أكّد نجاح جميع خطوات Manual Browser Smoke Test**: Plan، Tenant Required Subscription، Activation، Seat Limit/Reuse، Increase/Proration، Partial Payment، Renewal/Reduction، Manual Suspension/Reactivate.

### 8.11 Commits — Milestone 4

| Hash | Commit |
|---|---|
| `628db15` | `feat(billing): add annual plans, subscription terms and billing records` |
| `3f85c31` | `feat(billing): enforce purchased seats on employee management` |
| `9d35d1a` | `feat(platform): manage plans, subscriptions, renewals, seat requests and payments` |
| `4dfde7c` | `feat(tenancy): restrict tenant access by subscription and add the billing page` |
| `322cc00` | `test(billing): verify plans, seats, proration, renewals and payments` |
| `0057eb4` | `docs(billing): document Milestone 4 subscriptions, seats and billing` |

**Documentation:** `docs/MILESTONE_04_SUBSCRIPTIONS_BILLING.md`.

### 8.12 ملاحظات مفتوحة من Milestone 4

1. **تعديل أمني غير committed ومن خارج نطاق Milestone 4:** `app/Console/Commands/CreatePlatformAdmin.php` خفّض Password Minimum من 12 إلى 6. Claude تركه بدون Commit للحفاظ على تعديل لم ينشئه. صاحب المشروع لم يمنح إذنًا لإزالة هذا التعديل؛ لا تعدله أو تدمجه بصمت. تم تكليف Milestone 5 بفحصه والإبلاغ عنه قبل تقرير Production Readiness.
2. **Dev Database**: `fcl_central` كان يحتوي Migrations الجديدة already applied، مع 0 Tenants و0 Plans وقت التقرير؛ Claude لم يكن صاحب الأوامر التي نفذتها وفق تقريره. راجع قاعدة الجهاز الجديد وتاريخ Migrations، ولا تفترض أنها خالية أو أن صاحب آخر Session هو من نفذها.
3. **Transient Billing Test Failure**: فشل واحد لم يُعاد إنتاجه؛ تزامن مع إعادة Formatting لـ Blade، لكن السبب غير مؤكد.
4. **Per-commit dependency**: Commit `9d35d1a` يشير إلى Permission تمت إضافتها في Commit لاحق. الاختبار كان على Final State فقط، لا يُفترض أن كل Commit مستقل وقابل للاختبار وحده.
5. **لا Git Push** و**لا Remote** حتى التقرير الأخير.

---

## 9) Milestone 5 — Support, Impersonation & SaaS Finalization

### 9.1 الحالة الدقيقة

**التخطيط والـ Prompt التفصيلي جاهزان. لم يؤكد المستخدم في المحادثة أن Prompt Milestone 5 أُرسل بالفعل، ولم تصل رسالة تنفيذ نهائية أو Commits أو نتائج اختبارات لهذه المرحلة.**

**ممنوع كتابة Milestone 5 «Completed» حتى وصول تقرير رسمي وفحص Git/Tests/Browser.**

### 9.2 النطاق المطلوب من Prompt الأخير

1. **Platform Admin Direct Support Access → Tenant Admin**
   - من Central Platform إلى Tenant Subdomain، بسبب دعم إلزامي، إعادة تحقق من الهوية، Token/Handoff قصير جدًا Single-use، Session مؤقتة، دون تجاوز Suspension/Subscription.
   - No permanent admin access؛ تتبع Actor الحقيقي.
   - إشعار Tenant Admin داخل النظام وسجل الدعم.
2. **Tenant Admin → Employee Login As**
   - فقط داخل نفس Tenant؛ لا انتحال Tenant Admin آخر أو من يملك صلاحيات أعلى.
3. **Restricted Interactive Authorization**
   - Allowlist لما يجوز فعله، Block للعمليات الحساسة: Password/Email/MFA/Roles/Employees/Subscriptions/Payments/Approval/Destructive Actions، مع حماية Backend/Livewire.
4. **Session Lifecycle**
   - Start/Expiry/End/Revoke مع Banner واضح، لا Nested Sessions، إنهاء عند انتهاء الصلاحيات/تعطيل الهدف/إيقاف الشركة/انتهاء الاشتراك.
5. **Audit & Notification**
   - Actor الحقيقي + Target + Tenant + Reason + Timestamps + Denied Sensitive Attempts.
6. **Platform Admin Security Hardening**
   - إعادة فحص Password Policy 12→6 غير المعتمد، Rate Limiting، Recent Reauthentication، CSRF، Cookies، Sessions، ومراجعة MFA/2FA (إن لم تنفذ تُسجل كفجوة Production).
7. **Livewire Tenant Uploads**
   - تصحيح أو تعطيل آمن لمسارات Temporary Upload غير Tenant-aware حتى لا تصبح ثغرة قبل Modules الملفات.
8. **Full SaaS Security Regression & Readiness**
   - إعادة اختبار Tenant Sessions، Permission Cache، Stale Tenant Model، Livewire Middleware، Subscription Access، Billing، Seat Concurrency، Cross-domain Token Replay.
   - تقرير **Local/Staging/Production Readiness** بمؤشرات PASS/PASS WITH LIMITATIONS/BLOCKED/NOT VERIFIED.

### 9.3 ملفات متوقعة عند إنهاء Milestone 5

- `docs/MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md`
- `docs/SAAS_MVP_READINESS.md`
- Commits جديدة على `main` باسم Islam Kabbary، بدون Push.

### 9.4 كيف نستأنف على حساب/جهاز جديد؟

1. افحص أولًا هل Session Claude القديمة انتهت أصلًا، وهل ملفات Milestone 5 موجودة.
2. افحص `git status`, `git log` ونتائج الاختبارات قبل أي Prompt جديد.
3. لو Milestone 5 قيد التنفيذ، **لا تفتح Session ثانية تعدل نفس الكود**.
4. لو اكتملت، اجمع التقرير والـ Commits واختبار المتصفح؛ حدث هذا الملف.
5. لو توقفت، ابدأ من **حالة Working Tree الحقيقية** وباقي الأعمال الفعلية، لا تعيد كتابة ما تم.

---

## 10) README.md — طلب توثيق التسليم لمطور على جهاز آخر

المستخدم طلب جلسة Claude جانبية هدفها **تعديل ملف `README.md` في Root المشروع فقط** لتوثيق:

- Project Overview وTech Stack وRequirements.
- Fresh Setup من Clone أو نسخة مجلد مع `composer install` و`npm ci` وملف `.env` بالمُتغيرات الصحيحة.
- MySQL Central + Tenant DB Provisioning وأقل صلاحيات مستخدم DB.
- Central Migrations وإنشاء أول Platform Admin.
- Laravel Serve + Vite/Build، URLs Central/Tenant.
- إنشاء Tenant والـ Default Branch، إضافة أول Tenant Admin وتفعيل Subscription.
- Employee/Roles/Seat Constraints.
- Tests وTroubleshooting وحالات 403/404/Subscription Required.
- Quick Start يومي مختصر لمطور أعد البيئة مسبقًا.

**تعليمات الجلسة الجانبية:** تعدل `README.md` فقط، لا تغير Code/Env/Dependencies/Databases، لا Commit ولا Push. الجلسة الرئيسية فقط تدمج تعديل README وتعمل Commit منفصل بعد المراجعة. **لم يصل تأكيد أن هذا التعديل انتهى أو اندمج حتى تاريخ المستند.**

---

## 11) قواعد العمل الثابتة مع Claude Code وGit

هذه القرارات مهمة جدًا، لأنها جزء من أسلوب إدارة المشروع وتم حفظها في `CLAUDE.md`:

1. قبل كل Milestone: مراجعة الـ Code الموجود والـ Docs ذات الصلة، وعدم كسر ما تم بناؤه.
2. تنفيذ **Milestone واحدة فقط**؛ لا تبدأ مرحلة ثانية تلقائيًا.
3. إجراء اختبارات Pest/Pint/PHPStan وBuild/Security Audit، وReal-server Checks عند الإمكان، ثم صاحب المشروع يجري Manual Browser Smoke Test.
4. بعد كل Milestone يتم إنشاء **Markdown Documentation مفصل** تحت `docs/`، وليس مجرد ملخص.
5. تقسيم التغييرات إلى **Commits منطقية** لكل وحدة عمل مكتملة، لا Commit كبير لكل المرحلة.
6. الـ Commits تُكتب على **`main` مباشرة**؛ لا إنشاء Branch جديد.
7. Git **Author وCommitter = Islam Kabbary** مع البريد الحالي الصحيح للـ Repository؛ لا تختلق Email.
8. Commit Subjects/Body بالإنجليزية تصف بدقة ما تم إنجازه. لا رسائل عامة من نوع `updates` أو `fixes`.
9. لا AI/Claude/Anthropic Attribution أو Co-author Trailers.
10. الـ Documentation Commit يكون آخر Commit منطقي عادةً بعد التنفيذ والاختبارات.
11. **لا Git Push** إلا بإذن صريح جديد من صاحب المشروع؛ الـ Commit المحلي لا يساوي رفعًا على GitHub.
12. لا Commit لـ `.env` أو Credentials أو Secrets أو Database Dumps أو Logs.
13. لا `migrate:fresh`, `db:wipe`, إسقاط قواعد بيانات موجودة، أو عمليات مدمرة بدون تصريح محدد.
14. لو توجد تغييرات خارجية/غير متوقعة في Working Tree، لا تحذفها أو تعيد ضبطها بصمت؛ اعزلها وأبلغ بها.
15. لا تزعم أن كل Commit اختُبر منفصلًا إذا جرى اختبار الحالة النهائية فقط.
16. يجب إنهاء كل Milestone بتقرير: Implemented Features، Tests Results، Documentation Path، Commit Hashes، Branch، Git Status، Manual Browser Steps، Known Limitations.

**تاريخ مهم للـ Git:** أصل Repository كان `master` في تقرير سابق، ثم أصبح `main`؛ إحدى جلسات Claude حاولت إعادة تسميته بعد أن كان قد تغير مسبقًا، وظهر «no branch named master»، دون تأثير على التاريخ. لا تعد لفتح موضوع Rename طالما الفرع الحالي `main`.

**Remote:** لم يكن هناك Git Remote بحسب آخر التقارير. ليس معنى وجود Commits أنها موجودة أونلاين أو قابلة للـ Clone من الجهاز الجديد.

---

## 12) القرارات التجارية/التقنية المفتوحة — لا تخترع إجاباتها

| الموضوع | ما نعرفه | ما لم يُعتمد نهائيًا / المطلوب |
|---|---|---|
| الأسعار الفعلية | Formula معتمدة | Base Fee وقيمة Seat واسم Plan التجاري النهائي |
| العملة التجارية الأساسية | قائمة عملات مدعومة | اختيار Currency لكل خطة عند استخدامها؛ لا عملة عامة مفروضة |
| Taxes/VAT | لا Tax Engine | دولة الفوترة، الضرائب، قواعد التطبيق، المستندات القانونية |
| Invoicing Compliance | Internal Charges/Payments فقط | هل نحتاج Electronic Invoices/Tax Invoices؟ ومتى؟ |
| Payment Terms | Manual Payments | شروط الدفع والائتمان والتحصيل والتأخر |
| Payment Gateway | مؤجل عمدًا | المزود والتوقيت إن احتُيج له |
| Grace Period | الكود الحالي بدون Grace بعد Expiry | هل نُبقي هذه السياسة عند إطلاق الخدمة؟ |
| Late Renewal | الكود الحالي يبدأ فترة جديدة من يوم التجديد المتأخر دون تحصيل Gap | هل هذه سياسة تجارية معتمدة نهائيًا؟ |
| Legacy Tenants | إعفاء مؤقت من قيد الـ Seats حتى تفعيل Subscription | خطة انتقال قبل العملاء الحقيقيين |
| Tenant Extra Branches | Default Branch فقط حاليًا | متى نفتح Branch Creation؟ نموذج اشتراك/حدود فروع؟ غير معتمد |
| Storage Quota | لا حد نهائي محدد | خطة تخزين/حدود مستقبلية |
| Platform Admin Roles | كل Platform Admin له صلاحيات إدارة الشركات | هل نحتاج فصل أدوار Platform Operators عند زيادة الفريق؟ |
| Support Access | نمط Restricted Direct Access معتمد | التحقق من التنفيذ الفعلي لـ Milestone 5 والجاهزية الأمنية |
| MFA for Platform Admin | كان 2FA Disabled في تقارير 1–4 | تحقق من نتيجة Milestone 5؛ عدم وجودها قد يكون Production Blocker |
| DB privilege patterns | Local `fcl_app` ضمن `fcl_` | Hardening أفضل على Production ومراجعة Wildcard Deprecation |
| Redis / Shared Cache | غير مُستخدم لعزل بيانات Tenants في مراحل البداية | Tenant-aware Cache قبل تفعيل Shared Redis أو أي Tenant Data Caching |
| Provisioning | Synch داخل Web Request | تقييم Async/Queue/Timeouts عند Deployment وتزايد الشركات |
| Tenant Lifecycle | Suspend/Reactivate متاح | Delete/Domain Rename/Deep Lifecycle مؤجل |
| Temporary Uploads | ثغرة نطاق محتملة سُجلت في Milestone 3 | نتيجة إصلاح/تعطيل Milestone 5 قبل ملفات FCL |
| Source of Truth | Claude reports + User browser tests | مراجعة كود Repository الجديد وتقارير Documentation الفعلية |

**لا تحول أي Default اختاره Claude في Milestone 4 إلى قرار تجاري «وافق عليه العميل» لمجرد أنه نجح تقنيًا.**

---

## 13) FCL Import Operations — المطلوب لاحقًا وليس منفذًا

### 13.1 رحلة الشحنة الافتراضية التي تحتاج Validation

1. وصول بيانات شحنة / **Pre-alert** من Origin Agent أو Direct Shipping Line.
2. إنشاء **Import Job** مركزي مرتبط بالأطراف والمعرفات والتواريخ.
3. مراجعة **Shipping Documents** (Received / Missing / Under Review / Needs Correction حسب الحقيقي).
4. متابعة **Vessel / Voyage / Containers** والتواريخ والمراحل المهمة.
5. Arrival وCharges حسب طبيعة كل شحنة.
6. **Customs Clearance** داخل فريق التخليص، بحالة منفصلة عن حالة الشحنة الإجمالية.
7. Delivery Order (DO) / Release / Delivery Arrangement.
8. Cargo Delivery وتسجيل التواريخ أو الدليل المطلوب.
9. إغلاق Operational وFinancial كل منهما حسب شروطه؛ قد لا يحدثان في الوقت نفسه.

**هذه رحلة مرجعية أولية وليست عملية تشغيل تم اعتمادها من الشركة.**

### 13.2 وحدات الـ FCL MVP المقترحة

| Module | الهدف الأولي |
|---|---|
| Shipments / Import Jobs | ملف الشحنة المركزي، الأطراف، المصدر، المراجع والحالة |
| Containers | ربط الحاويات بالشحنة ومراحلها وتواريخها |
| Documents | Checklist، حالة الوثائق، ملفاتها وصلاحيات الوصول |
| Customs Clearance | حالة التخليص والمسؤول والمتطلبات الناقصة والمتابعة |
| Operations & Tasks | تكليف المتابعة والمهام والمواعيد والتأخر |
| Charges & Basic Profitability | إيرادات وتكاليف الشحنة بشكل مرن، بدون General Ledger شامل |
| Reports & Dashboard | متابعة الشحنات والمستندات الناقصة والمهام المتأخرة والربحية الأولية |

### 13.3 مبادئ لا تُنسى في العمليات

- `Origin Agent` و`Direct Shipping Line` مساران مختلفان محتملًا؛ لا تفرض نفس قواعد الطرف التجاري إلا بعد دراسة حالة فعلية.
- الأطراف قد تؤدي أدوارًا مختلفة حسب الشحنة؛ لا تنشئ Classification جامدة دون تحقق.
- دخل وتكلفة كل شحنة يمكن أن يختلفا؛ لا تفترض معادلة موحدة لكل الشحنات.
- Tracking يجب أن يفصل Overall Shipment/Documentation/Customs/Delivery/Financial Progress.
- كل Shipment Primary Branch واحد، مع Cross-branch Permissions وتحكم في Transfer حسب ما سبق.
- لا تفترض دعم تكامل Nafeza/ACID أو Shipping Lines APIs دون تأكيد الصلاحيات والـ API والمتطلبات.
- Uploads والملفات تتطلب Tenant Isolation حقيقيًا قبل أي Feature وثائق.

### 13.4 معلومات تشغيلية ما زالت ناقصة

لم يتم في السياق القديم:
- تحليل Excel الحقيقي للشركة.
- إجراء Walkthrough لشحنة FCL فعلية كاملة مع فريق Operations.
- توثيق قائمة المستندات الفعلية والـ Validation/Clearance Milestones.
- اعتماد حالات الـ Shipment أو Customs أو Approvals.
- الاتفاق على تفاصيل Charges/Currencies/Taxes/Invoice Flow لكل شحنة.
- تحديد كل Permissions التشغيلية وBranch Access بالتفصيل.
- تصميم نهائي للـ FCL Database Schema أو API أو UI.

**عندما نبدأ FCL:** ابدأ بمتطلبات واقعية لشحنة واحدة ولا تحوّل مسودة Workflow إلى Business Rules ملزمة. وفي الوقت ذاته احترم رغبة المستخدم في تجنب جلسات أسئلة لا تنتهي؛ اجمع الحد الأدنى من المعلومات التي تمنع الافتراض الخطر ثم نفّذ MVP تدريجيًا.

### 13.5 مؤجلات FCL المعروفة

- LCL / Export / Air Freight.
- Full ERP/General Ledger.
- كل تكاملات شركات الملاحة/Tracking الخارجية.
- Portals للعميل/Agent.
- Mobile Application.
- AI Document Extraction.
- تكامل Nafeza/ACID غير مؤكّد.
- Advanced Automation/Full Accounting قبل احتياج واقعي.

---

## 14) مخاطر النقل إلى جهاز آخر وحساب AI مختلف

### 14.1 الحساب الجديد لا يعرف المشروع تلقائيًا

حساب ChatGPT آخر **لا يمتلك ذاكرة هذه المحادثة تلقائيًا**. ارفع هذا الملف للحساب الجديد، ثم (اختياريًا لكن يفضل) أرفق `README.md`, `CLAUDE.md`, `docs/MILESTONE_*.md`, `docs/SAAS_MVP_READINESS.md` إذا كانت موجودة، أو امنح Claude Code وصولًا لكود Repository الذي نقلته.

### 14.2 Git لا يوجد له Remote حسب آخر تقرير

- `git log` والـ Commits محفوظة محليًا فقط طالما لا Remote ولا Push.
- نقل مجلد الملفات **بدون `.git`** يعني فقدان تاريخ Commits داخل النسخة الجديدة.
- لتكملة نفس المشروع مع التاريخ: استخدم وسيلة نقل موثوقة تشمل Repo وملفاته المخفية `.git` أو هيئ **Private Remote** بإذن صريح ثم Push مدروس؛ لم يُعطَ إذن تلقائي للـ Push.
- لا تنشر المشروع على Public Repository بدون مراجعة الوصول والأسرار.
- لا ترفع `.env` أو DB Passwords أو بيانات عملاء أو Backups غير مشفرة إلى المحادثة/المستودع.
- يختلف مكان `.env` وDatabase Local من جهاز لآخر؛ جهز بيئة الجهاز الجديد من README بعد التحقق.

### 14.3 الفرق بين «نقل الكود» و«نقل البيانات»

- Git ينقل الكود والوثائق والتاريخ إذا احتوى `.git`، لكنه لا ينقل قاعدة البيانات ولا Accounts الموجودة داخلها.
- يحتاج الجهاز الثاني إلى Central Database جديدة وTenant DBs خاصة به أو نقلًا آمنًا للبيانات المسموح بها، مع الانتباه للأسرار والخصوصية.
- لا تفترض أن `fcl_central` و`fcl_central_testing` على الجهاز الجديد يحويان نفس المحتوى.
- لا تجرب Tenant Migrations أو CLI Commands قبل ضبط MySQL User واسم DB وTest Connections بوضوح.

### 14.4 اعمل تدقيق استقبال على الجهاز الجديد

أوامر **قراءة فقط غالبًا** داخل مجلد المشروع، لا تغير Git:

```powershell
git status --short
git branch --show-current
git log --oneline -20
git remote -v
php -v
composer --version
node --version
npm --version
```

فحص الاتصال والإعدادات يكون بدون طباعة كلمات المرور أو `.env` بالكامل. تشغيل Tests/Migrations/Install Commands لا يُصنف Read-only؛ افهم تأثيرها أولًا.

### 14.5 التعارض بين الجلسات

وقت إعداد الوثيقة كان هناك:
- Claude Code **Main Session** كانت الجهة المقصودة لتنفيذ Milestone 5؛ لم يؤكد المستخدم نتيجة تنفيذ المرحلة.
- Claude Code **Side Session** مطلوبة لتحديث `README.md` فقط.

أي Session جديدة على نفس Working Tree قد ترى تغييرات غير مكتملة. قبل تعديل الملفات: تحقق من وجود جلسات أخرى، واتفق على ملكية الملفات، ولا تنفذ Git Reset/Checkout/Commit عشوائيًا.

---

## 15) الخطوة التالية الدقيقة — من أين نكمل؟

### أولوية 1 — التحقق من نتيجة Milestone 5

اطلب من Claude Code/المطور على النسخة **الفعلية الحالية**:

1. اقرأ `CLAUDE.md` وDocs 1–4، وتحقق من وجود Docs 5.
2. راجع `git status` و`git log --oneline -20` دون تعديل.
3. تحقق إن كان Milestone 5 مكتملًا أم جزئيًا، وما Commits الخاصة به، وهل Audit/MFA/Impersonation Tests نجحت.
4. راجع التعديل غير المعتمد في Password Minimum من 12 إلى 6 وتأثيره الحقيقي، دون overwrite صامت.
5. تحقق من `README.md` وهل انتهى توثيق تشغيل المشروع وهل تم Commit له على `main` أم لا.
6. لو Milestone 5 انتهى، نفذ **Manual Browser Smoke Test** لجلسات Support Access وTenant Admin Login As والمخاطر الحساسة.
7. اقرأ `docs/SAAS_MVP_READINESS.md` لو وُجد، واحسم Blockers المحلية وStaging وProduction.
8. **بعدها فقط** اختر Scope أول FCL Operations Milestone اعتمادًا على متطلبات واقعية.

### أولوية 2 — ليست Production Ready لمجرد نجاح الاختبارات

قبل نشر المنصة لعملاء حقيقيين، راجع على الأقل:
- HTTPS وSecure Cookies وSession Domain.
- Platform Admin MFA/Password Policy وReauthentication.
- DB grants بأقل صلاحيات، Secret Management.
- Backup/Restore وMigrations وRollback Procedure.
- Logs، Monitoring، Error Reporting، Scheduler/Queues إن لزم.
- Tenant Cache + Files + Temporary Upload Isolation.
- Subscription Policies والـ Grace/Late Renewal/Legacy Access.
- Tax/Invoice/Payment Terms حسب السوق المستهدف.
- End-to-end Security Check (لا تدّعِ Pen Test احترافيًا بدون تنفيذه).

### أولوية 3 — عدم فتح Features مشتتة

- لا تبدأ FCL Operations قبل حسم جاهزية SaaS MVP المطلوبة.
- بعد الانتقال لـ FCL، اعمل Milestones صغيرة وواقعية ومختبرة وفق نفس نظام Docs/Commits.
- لا تبدأ Full Accounting أو Integrations أو منصات أخرى دون ضرورة.

---

## 16) رسالة جاهزة للحساب الجديد (انسخها بعد رفع الملف)

> اقرأ ملف `FCL_SaaS_Complete_Project_Handover_2026-10-19.md` بالكامل، واعتبره مرجعًا أساسيًا للتاريخ والقرارات التي اتخذناها في مشروع FCL SaaS Platform.  
> أنا أكمل نفس المشروع على جهاز/حساب مختلف؛ لا تخلطه بمشاريع أخرى ولا تفترض أن المتطلبات التشغيلية لـ FCL تم اعتمادها.  
> تم تنفيذ Milestones 1–4 واختبارها يدويًا، بينما Milestone 5 أُعد لها Prompt ولم نتحقق بعد إن كانت بدأت أو انتهت.  
> تعامل مع Repository والـ Documentation الحالية كمصدر الحقيقة للحالة الفعلية، ثم أخبرني باختصار: ما الذي اكتمل، ما الذي لا يزال مفتوحًا، وما الخطوة العملية التالية.  
> احترم قواعد المشروع في `CLAUDE.md`: Documentation بعد كل Milestone، Commits منطقية على `main` باسم Islam Kabbary، ولا Push بدون إذني.  
> تكلم معي بالعربية المصرية بشكل عملي، وتجنب العودة إلى أسئلة وقرارات سبق حسمها. لا تكتب كودًا أو تغير قواعد بيانات أو Git قبل فهم حالة المشروع الحالية والمهمة التي أطلبها.

---

## 17) مفاتيح للمساعد الجديد — حدود الثقة والتحقق

**مؤكد من صاحب المشروع مباشرة:**
- قرارات SaaS الأساسية التي نوقشت واعتمدت.
- نجاح Manual Smoke Test لمراحل 1 و2 و3 و4.
- رغبته في توثيق كل Milestone وCommits باسم Islam Kabbary على `main` وعدم Push التلقائي.
- رغبته في التركيز على SaaS MVP أولًا ثم FCL.

**مُبلَّغ به من Claude Code، ولم أتحقق منه مباشرة:**
- تفاصيل الكود، Migrations، اختبار Real-server، Commits وأرقام الاختبارات.
- مستويات الأمان التي تقول تقارير Claude إنها اجتازت الاختبارات.
- أسماء بعض الملفات/الأوامر.

**غير مؤكد بعد:**
- نتيجة تنفيذ Milestone 5.
- إتمام تحديث `README.md` في الجلسة الجانبية وCommit له.
- هل تغيرت حالة Working Tree / Password Policy بعد آخر تقرير Milestone 4.
- جاهزية Staging/Production.
- Business Rules التفصيلية لوحدات FCL Import التشغيلية.

**إذا تعارض هذا المستند مع الكود الحالي أو التقرير النهائي لـ Milestone 5، حدّث حالة المستند بناءً على الدليل؛ لا تتجاهل القرارات التجارية الصريحة لصاحب المشروع.**

---

## 18) فهرس المراجع داخل المشروع

| المرجع | لماذا نقرأه |
|---|---|
| `CLAUDE.md` | قواعد سير العمل والتوثيق وGit المُلزمة للجلسات |
| `AGENTS.md` | Laravel Boost Guidelines عند توفره؛ قد يكون Generated/Ignored |
| `README.md` | تشغيل المشروع من الصفر للمطور الجديد؛ تحقق من اكتماله |
| `docs/MILESTONE_01_SAAS_FOUNDATION.md` | أساس Tenancy وCentral/Tenant Auth وقواعد البيانات |
| `docs/MILESTONE_02_TENANT_USERS_PERMISSIONS.md` | Employee/Roles/Permissions والعزل |
| `docs/MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md` | Central Admin، Suspension، Audit |
| `docs/MILESTONE_04_SUBSCRIPTIONS_BILLING.md` | Plans، Annual Terms، Seats، Proration، Payments |
| `docs/MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md` | مستند متوقع، وجوده يتوقف على حالة Milestone 5 |
| `docs/SAAS_MVP_READINESS.md` | مستند متوقع عن Local/Staging/Production Readiness |
| Historical `FCL_Import_Project_Context.md` | سياق FCL التشغيلي الأصلي؛ **جزء “لم يبدأ أي تنفيذ” فيه أصبح قديمًا لأنه سبق Milestones SaaS** |

---

## 19) قائمة القرارات التي لا ينبغي إعادة فتحها من الصفر

- SaaS First، ثم FCL Operations.
- Company Per Tenant، Branches داخل Tenant.
- Database Per Tenant + Central DB.
- Subdomain Per Tenant.
- Manual Onboarding فقط.
- Default Branch `MAIN` تلقائيًا، Additional Branches مؤجلة.
- Primary Branch واحد لكل Employee، Cross-branch Permissions لاحقًا.
- Separate Platform/Tenant Admin Accounts.
- Fixed Annual Plans فقط، Base Fee + Per-seat Fee.
- Active Users يستهلكون Seats، والـ Tenant Admin ضمن العدد.
- Mid-term Seats Prorated إلى Renewal Date، وزيادة بموافقة Platform Admin.
- Seat Reduction عند Renewal القادم، لا Refund تلقائي.
- No Shipment Limit الآن، وCore Features بدون Paid Add-ons أولًا.
- Manual Billing/Payments في MVP؛ Online Payment Gateways لاحقًا.
- Restricted Direct Support Impersonation مع Audit/Reauth/Notification، وليس unrestricted access.
- Laravel 13 + Livewire 4 + MySQL + stancl/tenancy + Spatie Permission كأساس قائم بالفعل.
- Direct Commits on `main`, Author/Committer Islam Kabbary, Markdown Docs per milestone, No Push دون إذن.

---

## 20) خاتمة — الجملة التي تلخص موقف المشروع

**حتى 19 أكتوبر 2026:** لدينا SaaS Management MVP منفذ ومختبر يدويًا في المجالات الأربع الأولى (Foundation, Tenant Users/Permissions, Platform Management, Annual Subscriptions/Billing). المرحلة الخامسة الخاصة بـ Secure Support Impersonation والأمان النهائي هي **المهمة التالية التي جرى تجهيز Prompt لها** ولم يصل تأكيد بدء تنفيذها أو إنجازها بعد. الـ FCL Import Operations **لم يبدأ تنفيذها**؛ الموجود لها تصور أولي يحتاج التحقق من العمل الحقيقي داخل شركة Freight Forwarding قبل تصميم التفاصيل. يجب استلام حالة Repository الصحيحة على الجهاز الثاني، وفحص Git وMilestone 5 وREADME، ثم إغلاق SaaS MVP بأمان قبل بدء الـ FCL.
