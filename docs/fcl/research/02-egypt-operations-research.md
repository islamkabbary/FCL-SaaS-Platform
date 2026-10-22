دراسة معمّقة: التشغيل الفعلي لـ FCL Ocean Import في مصر وSOP مقترح لبناء النظام
هذه الدراسة موجهة تحديدًا لمشروعك: Multi-Tenant SaaS لشركات Freight Forwarding مصرية، يبدأ بـ FCL Ocean Import، مع Origin Agent وDirect Shipping Line وCustoms Clearance داخلي. البحث ركّز على ما يمكن إثباته من Egyptian Customs، NAFEZA، CargoX، Alexandria Container & Cargo Handling، Damietta Container Terminal، وإرشادات خطوط ملاحية عاملة في مصر مثل Hapag-Lloyd وCMA CGM وMaersk، مع فصل واضح بين الإجراء الرسمي وبين الـ carrier/terminal practice وبين الاستنتاج البرمجي الذي يحتاج تأكيدًا من Operations حقيقي.

تاريخ البحث: 22 أكتوبر 2026.

أهم نتيجة: البحث يؤكد اتجاه التقرير السابق، لكنه يضيف تعديلًا معماريًا مهمًا جدًا: لا يكفي أن يكون ACID مجرد field واحد على import_jobs إذا كنت ستدعم MBL/HBL أو consolidated shipments؛ في بعض حالات التجميع يكون لكل sub-B/L رقم ACID مستقل، ثم يوجد ACID مرتبط بالـ consolidated/master B/L أيضًا. NAFEZA تشرح هذا السيناريو صراحة. 

الخلاصة التشغيلية وما يجب أن تعتبره حقيقة الآن
الصورة الأقرب للواقع المصري ليست:

Create Shipment → Arrived → Customs → Delivered → Closed

بل:

text
Copy
Pre-Alert / Commercial Information
             │
             ▼
      Import Job Opened
             │
     ┌───────┼───────────┐
     ▼       ▼           ▼
   ACI      BLs      Documentation
  /ACID   MBL/HBL       Readiness
     │       │           │
     └───────┼───────────┘
             ▼
       Ocean Transit
             │
        ETA Changes
             │
             ▼
     Arrival / Discharge
             │
      ┌──────┴──────────┐
      ▼                 ▼
 Customs Process    Carrier Process
 Declaration        Charges / BL
 Inspection         Delivery Order
 Release            Carrier Release
      │                 │
      └────────┬────────┘
               ▼
        Terminal Ready
               │
               ▼
           Gate Out
               │
        ┌──────┴──────┐
        ▼             ▼
     Delivery      POD
        │
        ▼
    Empty Return
        │
        ▼
 Operational Closure
        │
        ▼
  Financial Closure
والأهم: المسارات داخل الرسم لا تتحول إلى Status واحدة. قد تكون السفينة Arrived بينما الـ Customs Declaration ما زالت تحت الفحص؛ وقد يحدث Customs Release بينما الـ Delivery Order عند الخط لم يُستخرج بعد؛ وقد يتم تسليم Container بينما Container ثانية من نفس Job ما زالت في الميناء. المصادر الرسمية نفسها تفصل ACI، Import Declaration، Customs Release، Delivery Order، وحركة الـ Containers إلى خدمات وعمليات مستقلة. 

مستويات الثقة المستخدمة في التقرير
التصنيف	معناه
رسمي — مصر	مثبت من Egyptian Customs أو NAFEZA
رسمي — Carrier/Terminal	مثبت من Shipping Line أو Terminal، لكنه ليس قاعدة عامة لكل الشركات
استنتاج تشغيلي	تصميم منطقي مستنتج من أكثر من مصدر، وليس نصًا قانونيًا
يحتاج Field Validation	لا يوجد دليل عام كافٍ؛ يجب تأكيده من موظف Operations/Customs فعلي

أول حقيقة يجب تثبيتها في النظام هي أن ACI عملية Pre-Shipment، وليست Arrival process. مصلحة الجمارك المصرية تقول إن نظام Maritime ACI أصبح إلزاميًا منذ 1 أكتوبر 2021، وإن بيانات ومستندات الشحنة الأساسية يجب إتاحتها قبل الشحن بـ48 ساعة على الأقل. وفي نفس الوقت توجد مهلة مختلفة خاصة ببيانات الـ Manifest مرتبطة بما لا يتجاوز 48 ساعة قبل وصول السفينة؛ لذلك يجب ألا تخلط في النظام بين ACI deadline وManifest timing. 

وثاني حقيقة مهمة: NAFEZA لا تتعامل مع ACID باعتباره مجرد رقم مرجعي يُضاف في النهاية. الخطوط الملاحية وFreight Forwarders لديهم خدمة للتحقق من ACID وإغلاق تعديلات بيانات ACI عندما يكتمل الـ Booking وتبدأ إجراءات الشحن، وتوضح المنصة أن ذلك يتم قبل الشحن. هذا يبرر وجود lifecycle خاص بـ ACI داخل تطبيقك بدل acid_number فقط. 

وثالث حقيقة: الـ Freight Forwarder له دور واضح في حالات MBL/HBL. NAFEZA تقول إن Foreign Freight Forwarder يحتاج CargoX verification، وأن Agent/Representative Office في مصر يُدخل MBL ACID، ثم عند وصول السفينة يتم التعامل مع الـ HBLs مقابل الـ MBL؛ كما أن exporter يرسل Copy HBL مع المستندات المطلوبة من خلال CargoX. 

ورابع حقيقة: Customs Release ≠ Carrier Release ≠ Terminal Ready ≠ Delivery. فـ NAFEZA لديها مسارات مستقلة للـ Import Declaration والإفراج والـ Delivery Order، بينما Hapag-Lloyd Egypt تضع شروطًا خاصة للحصول على Import Delivery Order/container release تتعلق بالمستحقات والمستندات، وMaersk أيضًا تربط container release باستيفاء الجمارك والمصاريف والمستندات. 

هذه الأربع نقاط وحدها كافية لتغيير تصميم FCL Module مقارنة بأي CRUD بسيط للشحنات.

سير العمل اليومي من Pre-Alert إلى Empty Return
Pre-Alert وفتح Import Job
الواقع الرسمي: لا يوجد نص في NAFEZA يقول إن شركة الـ Freight Forwarder يجب أن تفتح record داخليًا اسمه Import Job في لحظة محددة؛ هذه مسألة داخلية للشركة. لكن لأن ACI والـ BL والمستندات والمعلومات الخاصة بالمستورد والمصدر تبدأ قبل وصول السفينة، فمن غير المنطقي تصميم النظام بحيث تبدأ Job عند الـ Arrival فقط. 

الاستنتاج الأنسب لمشروعك: تفتح Import Job عندما يصل للفرع المصري Actionable Pre-Alert كافٍ لبدء المتابعة، حتى لو لم تكتمل كل البيانات.

في Origin Agent case غالبًا سيصل إلى الموظف خليط من:

text
Copy
Origin Agent reference
Customer / Importer
Shipper / Exporter
MBL / HBL references
Carrier
POL / POD
Vessel / Voyage
ETD / ETA
Container information if available
Commercial Invoice
Packing List
ACID information
Special instructions
هذه ليست قائمة رسمية موحدة للـ Pre-Alert؛ هي Software intake model مشتق من البيانات التي تصبح مطلوبة لاحقًا في ACI/BL/customs flow. البيانات الرسمية التي تؤكد أهميتها تشمل importer/exporter identification، BL، commercial invoice، ports، ACID والمستندات المرافقة. 

في Direct Shipping Line case قد لا يوجد Origin Agent في العلاقة التشغيلية أصلًا، ولذلك يجب ألا تجعل origin_agent_id إلزاميًا ولا تجعل Booking الذي أنشأته شركتك prerequisite لفتح Import Job. الخطوط نفسها توفر Arrival Notice، Delivery Order، tracking وimport documentation كعمليات منفصلة بعد أن تكون الشحنة قد حُجزت أصلًا في المصدر. CMA CGM Egypt، مثلًا، تضع Arrival Notice وDelivery Order وTracking كخدمات import واضحة منفصلة. 

لذلك أوصي بأن يكون:

text
Copy
source_type =
    origin_agent
    direct_shipping_line
    other
مع source_reference اختياري.

Software state المقترح:

text
Copy
Job lifecycle:
Draft → Open → On Hold → Operationally Complete → Closed / Cancelled
لكن Draft لا تعني أن ACI Draft، وOpen لا تعني أن السفينة أبحرت. دي مجرد lifecycle للـ operational file.

ACI وACID قبل الشحن
الـ Egyptian importer يبدأ ACI process في NAFEZA ويحصل على ACID، بينما المستندات القادمة من الخارج تنتقل عبر CargoX ضمن المنظومة؛ NAFEZA/CargoX ecosystem صُمم بحيث تكون البيانات متاحة للجهات المصرية قبل الشحن. مصلحة الجمارك تحدد 48 ساعة على الأقل قبل الشحن لمعلومات ومستندات ACI الأساسية. 

المستندات المعتادة التي تسردها NAFEZA لمعظم ACI filings هي:

Document	الوضع
Commercial Invoice	أساسي لمعظم الملفات
Packing List	أساسي لمعظم الملفات
Copy of Bill of Lading	المطلوب نسخة، وليس Original BL في ACI upload
Certificate of Origin	ضمن الحزمة المعتادة
Analysis / Inspection / Health / Phytosanitary / Veterinary / MSDS / Insurance وغيرها	حسب نوع البضاعة

كما تقول NAFEZA إن حجم كل مستند يتم رفعه عبر NAFEZA/CargoX في هذا السياق لا يتجاوز 5 MB. هذا يجب أن يكون outbound ACI rule في برنامجك، وليس بالضرورة حد تخزين كل مستندات برنامجك داخليًا. 

هناك بيانات تعريفية لا ينبغي التعامل معها كملاحظات free-text: الرقم التعريفي للمستورد المصري، تعريف المصدر الأجنبي، وACID لها متطلبات ظهور على المستندات؛ NAFEZA تقول إن تعريف importer/exporter يظهر على Commercial Invoice وB/L وإن ACID يظهر على مستندات الشحنة ذات الصلة. 

وهنا يظهر أول تعديل كبير في الـ domain model الذي أوصي به:

لا تفعل هذا فقط:

text
Copy
import_jobs.acid_number
الأفضل:

text
Copy
ImportJob
   │
   ├── BillsOfLading
   │       │
   │       └── ACI Records / ACIDs
   │
   └── Parties
أو Entity مستقلة مثل:

text
Copy
aci_records
-----------
id
import_job_id
bill_of_lading_id nullable
acid_number
aci_type
importer_party_id
exporter_party_id
status
issued_at
verified_at
locked_at
والسبب رسمي ومهم: في حالات consolidated LCL/FCL تشرح NAFEZA أن كل sub-bill يمكن أن يكون له ACID منفصل، ثم يجمع الـ Egyptian Freight Forwarder هذه الأرقام ويطلب ACID للـ consolidated B/L نفسه. وبالتالي Job واحدة قد تحتاج أكثر من ACID. 

هذه واحدة من أهم نتائج البحث الثاني؛ لأنها تصحح تبسيطًا كان موجودًا في التقرير الأول.

Booking وMBL/HBL
الـ Booking مهم كـ external shipping reference، لكنه ليس mandatory root للـ Egyptian import Job. في Destination Freight Forwarding قد تستلم الشركة تفاصيل الشحنة بعد أن يكون الحجز قد تم في بلد المصدر.

لكن الـ Bill of Lading ليست مجرد string على الـ Job.

لازم على الأقل تدعم:

text
Copy
Bill of Lading
├── Master BL
└── House BL
ومستقبليًا:

text
Copy
MBL
 ├── HBL 1
 ├── HBL 2
 └── HBL 3
NAFEZA نفسها تتحدث رسميًا عن MBL/HBL وعن clearance للـ HBLs against the MBL في Freight Forwarder scenario، وتوضح أن consolidated sub-bills قد ترتبط بـ ACIDs منفصلة. 

كذلك Vessel + Voyage لا يصلحان كـ unique shipment identity. NAFEZA توضح أن أكثر من shipment يمكن أن تأتي على نفس vessel، ولكل shipment ACID مستقل. 

Ocean Transit وPre-Arrival
بعد الإبحار، دور Operations يتحول من “هل البيانات جاهزة للشحن؟” إلى exception monitoring:

text
Copy
Original ETA
Current ETA
ETA change history
Vessel / Voyage
Transshipment if applicable
Arrival Notice received?
Documents complete?
Customs file ready?
Carrier charges known?
Container free-time information available?
الخطوط الملاحية المصرية نفسها توفر Arrival Notice وTracking. CMA CGM Egypt تعرض Arrival Notice وDelivery Order وShipment Tracking كوظائف Import، بينما Hapag-Lloyd Egypt تتيح Arrival Notice داخل Navigator وContainer tracking. 

لا أوصي بأن يتم:

text
Copy
UPDATE import_jobs SET eta = ...
وفقط.

الأفضل:

text
Copy
job_events

ETA_CHANGED
VESSEL_ARRIVED
CONTAINER_DISCHARGED
ARRIVAL_NOTICE_RECEIVED
...
والـ Job يحتفظ بـ current_eta للقراءة السريعة، لكن history لا يضيع.

Arrival وDischarge
لازم تفرق بين:

text
Copy
Vessel ETA
Vessel ATA
Container Discharged
Container Available
لأن وصول السفينة لا يعني تلقائيًا أن كل Containers في Job موجودة أو جاهزة للتعامل. وجود خدمة NAFEZA مخصصة لإخطار الجمارك بالـ non-arrived containers دليل مباشر على أن حالة “بعض الحاويات لم تصل” حالة تشغيلية يجب أن يتسع لها النظام. 

وده معناه إن:

Container State لازم تكون per-container، مش per-job.

مثلًا:

text
Copy
CONT-1 → discharged
CONT-2 → not_arrived
CONT-3 → customs_hold
في نفس الـ Job.

في Alexandria، شركة الإسكندرية لتداول الحاويات تستخدم CATOS لإدارة discharge/loading والyard والمخازن وحركات الحاويات والـ inspection/gate lists، ولديها OCR عند Gate In/Gate Out. هذا لا يعني أن برنامجك يحتاج integration معها الآن، لكنه يؤكد أن arrival/discharge/gate movements Events مستقلة فعلًا في الواقع التشغيلي. 

وفي Damietta، الموقع الرسمي لمحطة DCHC يعرض Vessel Schedule وContainer Tracking؛ لكنه لا ينشر في المصادر التي أمكن التحقق منها SOP تفصيليًا عامًا يحدد كل خطوة من customs clearance إلى gate-out، ولذلك يجب عدم hard-code “Damietta workflow” بناءً على افتراضات. 

Customs Clearance
بعد أو حول مرحلة الوصول، تبدأ أو تستكمل عملية Customs Declaration. NAFEZA لديها خدمة رسمية لتقديم Import Declaration، ولديها أيضًا real-time tracking لحالة البيان الجمركي. 

الـ NAFEZA overview يعرض workflow عام يشمل تقديم المستندات، تحديد موعد examination/inspection، دفع الرسوم إلكترونيًا، ثم الحصول على unified release permission الذي يبدأ إجراءات استلام البضاعة. كما تشير المنصة إلى تنسيق جهات الفحص داخل منظومة موحدة. 

لكن لا أنصح بتحويل هذه الخطوات إلى state machine صلبة من خمسة states فقط، لأن نوع البضاعة والجهات الرقابية والـ risk path والفحص والمستندات تختلف.

تصميم أفضل:

text
Copy
Customs Declaration
-------------------
reference
status
submitted_at
inspection_required
inspection_status
customs_hold_reason
duties_status
release_reference
released_at
مع:

text
Copy
Customs Events
--------------
DECLARATION_SUBMITTED
DOCUMENT_REQUESTED
INSPECTION_SCHEDULED
INSPECTION_COMPLETED
HOLD_PLACED
HOLD_CLEARED
DUTIES_READY
DUTIES_PAID
CUSTOMS_RELEASED
أسماء الأحداث أعلاه تصميم برمجي مقترح، وليست official NAFEZA status names.

يجب أن تسمح بأن تكون Customs Release مستقلة عن Carrier Release.

Shipping Line Delivery Order
دي مرحلة لازم تتبني كـ domain مستقلة.

Hapag-Lloyd Egypt، كمثال carrier-specific موثق، تقول إن Import Delivery Order/container release يتطلب تسوية المستحقات المطلوبة وتقديم documentation تشمل حسب الحالة Original B/L أو Sea Waybill/telex-release equivalent، delegation letter، customer ID، tax ID وcommercial registration. 

Maersk تقول بشكل أعم إن import container release يخضع للوائح الجمارك المحلية، وتسوية charges وتسليم المستندات المطلوبة قبل طلب release/delivery order. 

NAFEZA نفسها لديها خدمة Submit a Delivery Order for Shipment، وهو دليل إضافي أن الـ DO concept ليس مجرد field داخل Customs Declaration. 

لذلك أوصي بمفهوم:

text
Copy
Carrier Release / Delivery Order
--------------------------------
carrier
bl_id
do_number
requested_at
documents_ready
carrier_charges_status
released_at
valid_until
status
وليس:

text
Copy
shipment_status = DO_RECEIVED
Terminal Release وGate-Out
حتى لو Customs Release وCarrier DO جاهزان، يجب أن يكون هناك gate مستقل يعبر عن:

text
Copy
Terminal Ready / Pickup Eligible
لأن terminal operations لها inspection/gate/container handling realities خاصة بها.

في Alexandria، مركز Logistics Services Center الذي افتتح في يوليو 2025 صُمم كمركز one-stop لأعمال inspection، examination وrelease الخاصة بالـ containers، مع استثناء refrigerated وhazardous containers من هذا المسار المعلن، وهو مثال واضح على أن Cargo Type قد يغير الـ terminal SOP. 

إذًا في النظام لا أنصح بكتابة:

text
Copy
if customs_released = true:
    delivery_ready = true
الأفضل:

text
Copy
Delivery Readiness =
    customs_released
    AND carrier_released
    AND terminal_released
    AND transport_ready
بحيث يمكن تعديل الـ gates حسب العميل/الميناء/الـ carrier.

Trucking وDelivery
هنا تقل قوة المصادر الرسمية العامة بشكل واضح. لم أجد مصدرًا رسميًا عامًا للإسكندرية أو دمياط يحدد SOP موحدًا لكل Freight Forwarders من truck assignment إلى consignee POD؛ الـ terminal والcarrier والمستورد وطبيعة cargo تغير التفاصيل. مواقع المحطات تثبت tracking/gate/yard capabilities، لكنها لا تكفي لادعاء workflow موحد لكل شركة. 

لذلك هذه المنطقة يجب أن تكون Field Validation Priority.

لكن تصميم النظام يمكن أن يستوعب بأمان:

text
Copy
Delivery
--------
delivery_reference
delivery_address
trucker
vehicle
driver
pickup_planned_at
gate_out_at
delivered_at
pod_document
exception
ولا تجعل Delivery مرتبطة بـ Job فقط؛ خليها قادرة على ربط مجموعة Containers، لأن Job بـ 3 Containers ممكن تتسلم على رحلتين أو 3 رحلات.

Empty Return
دي ليست cosmetic checkbox.

بعد تفريغ FCL عند العميل، الـ empty container يجب إرجاعها وفق الـ carrier/depot/free-time arrangement. Carrier terms تختلف وتتغير؛ Hapag-Lloyd Egypt، على سبيل المثال، تحيل العميل إلى local tariff/free-time tool للحصول على local charges وfree time بدل نشر قاعدة واحدة ثابتة للجميع. 

إذن:

text
Copy
Container
---------
delivery_at
empty_return_depot
empty_return_due_at
empty_returned_at
empty_return_reference
والتنبيه:

text
Copy
Empty return due in 2 days
Empty return due tomorrow
Empty return overdue
يكون per container.

ولا تضع 7 days أو 14 days hard-coded.

Operational Closure وFinancial Closure
لا يوجد قانون مصري يقول إن برنامج الـ Freight Forwarder يجب أن يقفل Job بهذه الطريقة؛ هذه توصية تصميمية.

لكن بناءً على فصل العمليات السابقة، المنطقي أن:

text
Copy
Operationally Complete
تعني مثلًا:

كل Containers المطلوبة تم التعامل معها.
Delivery اكتمل.
Empty Return المطلوب اكتمل.
لا توجد blocking operational tasks.
لا يوجد unresolved customs/carrier exception.
بينما:

text
Copy
Financially Closed
تعني أن الـ operational revenue/cost process الخاص بالشركة انتهى حسب سياستها.

وبالتالي:

Delivered ≠ Closed.

وده قرار أنصح باعتماده من الآن.

سيناريوهات واقعية مركّبة وSwimlane المسؤوليات
السيناريوهات التالية ليست ادعاءً بأنها ملفات شحن عامة منشورة لشركات بعينها. هي Composite Scenarios مبنية على الإجراءات الرسمية والممارسات المنشورة، وهدفها تحويل البحث إلى workflow يمكنك مناقشته مع Operations.

Origin Agent — MBL/HBL وContainer واحدة
نفترض أن شركة مصرية اسمها ABC Freight Egypt تستلم Pre-Alert من Partner في Shanghai.

المرحلة	الطرف الأساسي	ما يحدث في السيستم
Pre-Alert	Origin Agent → Operations/Documentation	Create Draft Job
ACI	Egyptian Importer / Exporter / ACI parties	Add/verify ACID
Documents	Documentation	Check invoice, packing list, BL copy, COO
BL	Documentation	Create MBL/HBL structure
Transit	Operations	Monitor ETA / Arrival Notice
Arrival	Operations	Record ATA/discharge
Customs	Customs Team	Create Customs Declaration workflow
Carrier	Documentation/Operations	Obtain DO/carrier release
Terminal	Operations	Confirm pickup eligibility
Trucking	Operations	Assign truck
Delivery	Driver/Operations	Gate-out → Delivery → POD
Empty	Operations	Track empty return
Closure	Ops + Finance	Operational then financial close

القسم الخاص بـ ACI والـ MBL/HBL مدعوم مباشرة بإرشادات NAFEZA الخاصة بدور Freight Forwarder، بينما ما قبلها وبعدها هو تركيب تشغيلي منطقي حول تلك العمليات. 

ما يراه المستخدم عند فتح Job:

text
Copy
IMP-2026-00184

Customer: ABC Import Co.
Source: Origin Agent
Origin Agent: Shanghai Partner Ltd.
Carrier: ...
POL: Shanghai
POD: Alexandria
Vessel / Voyage: ...
Current ETA: ...

ACI
✓ Sub/HBL ACID verified
✓ Master ACID verified
✓ ACI locked before shipment

Documents
✓ Commercial Invoice
✓ Packing List
✓ BL Copy
! Certificate X pending

Transport
Vessel: In transit

Customs
Not started

Carrier Release
Not started

Containers
MSCU1234567 — In transit

Tasks
[ ] Review Arrival Notice
[ ] Prepare Customs File
ده أقرب إلى Freight workspace من شاشة CRUD.

Direct Shipping Line — بدون Origin Agent
في الحالة الثانية:

text
Copy
Shipper / Customer
       │
       ▼
Shipping Line
       │
       ▼
Egyptian Importer / Freight Forwarder
لا يوجد سبب معماري لإجبار Job على Origin Agent.

الـ carrier قد يوفّر Arrival Notice، invoices، tracking، وDelivery Order عبر بوابته. CMA CGM Egypt تسرد هذه الخدمات صراحة، وHapag-Lloyd لديها Arrival Notice وDO/container release workflows. 

الـ Job تكون مثلًا:

text
Copy
source_type = direct_shipping_line
origin_agent_id = null
carrier = CMA CGM
mbl = ...
hbl = null
وبقية دورة ACI، Customs، Carrier Release، Terminal وDelivery تستمر.

درس مهم: HBL لا يجب أن يكون mandatory.

MBL/HBL Consolidated Case
دي أهم حالة لتصميم Database حتى لو مش هتكون أول Use Case كل يوم.

NAFEZA تقول إنه في consolidated LCL/FCL يكون لكل sub-bill ACID خاص، ويبلغ كل importer وكيل الشحن المصري بالـ ACID الخاص به، ثم يطلب المصري ACID للـ consolidated/master B/L ويبلغه للـ foreign forwarder ليظهر على الـ master document. 

يعني ممكن يكون عندك:

text
Copy
MBL-001
ACID-MASTER

├── HBL-101
│   └── ACID-A
│
├── HBL-102
│   └── ACID-B
│
└── HBL-103
    └── ACID-C
وده وحده يثبت أن:

text
Copy
import_jobs.acid_number
غير كافٍ كـ long-term design.

كمان يثبت ضرورة وجود:

text
Copy
bill_of_ladings
bill_relationships
aci_records
بدل ما تكون كل معلومات الـ BL نصوصًا على Job.

Multiple Containers وPartial Operations
نفترض Job بها:

text
Copy
Container A
Container B
Container C
يوم الوصول:

text
Copy
A → discharged
B → discharged
C → non-arrived
وجود خدمة NAFEZA لإخطار Customs بالـ non-arrived containers يجعل السيناريو نفسه واقعيًا تنظيميًا، وليس edge case خياليًا. 

بعد ذلك قد يحدث:

text
Copy
A → customs released → delivered → empty returned
B → customs released → delivered → empty outstanding
C → arrived later → customs processing
وفي اللحظة نفسها Job كلها لا يمكن اختزالها إلى:

text
Copy
status = DELIVERED
ولا:

text
Copy
status = CUSTOMS_CLEARANCE
الـ Job يجب أن تعرض aggregated readiness:

text
Copy
Containers: 1/3 operationally completed
Customs: partial
Delivery: partial
Empty Return: 1 outstanding
Swimlane عملي للأدوار
التقسيم التالي توصية تشغيلية أولية، ويجب تعديل أسماء الأقسام بعد مقابلة الشركة المستهدفة:

مرحلة	Customer Service / Documentation	Operations	Customs	Finance	External
Pre-Alert	يستلم ويراجع البيانات	يفتح/يستلم Job	—	—	Origin Agent / Carrier
ACI	يتابع ACID/docs	يراقب readiness	يراجع regulatory readiness عند الحاجة	—	Importer / Exporter / CargoX / NAFEZA
BL	MBL/HBL review	references	—	—	Agent / Carrier
Transit	docs updates	ETA / exceptions	prepares file	estimates charges if company policy	Carrier
Arrival	Arrival Notice	arrival/discharge	customs progression	carrier/local invoices	Terminal/Carrier
Customs	document support	exception coordination	declaration/examination/release	duties/cost coordination حسب الشركة	NAFEZA/Customs
DO	documents	coordinates	—	line charges/payment according to company SOP	Shipping Line
Delivery	—	trucking/gate-out/POD	confirms release gate	transport costs	Terminal/Trucker
Empty Return	—	monitor return	—	D&D exposure	Carrier/Depot
Closure	file completeness	operational close	customs complete	financial close	—

أهم شيء للتحقق ميدانيًا هو هل شركتك المستهدفة تفصل Documentation عن Operations فعلًا، وهل Customs team يملك Job من بداية معينة أم مجرد customs sub-process، ومين مسؤول عن Shipping Line DO والمصاريف. المصادر الرسمية لا تفرض organizational chart على Freight Forwarder.

المستندات والـ Release Gates والاستثناءات
Document Checklist حقيقي كبداية
بناءً على NAFEZA، الـ core ACI documentation تبدأ عادة بـ Commercial Invoice، Packing List، B/L Copy وCertificate of Origin، مع شهادات أخرى حسب طبيعة البضاعة. كما توجد قواعد حول importer/exporter identifiers وACID على المستندات. 

لكن لا تبنِ:

text
Copy
job.has_invoice
job.has_packing_list
job.has_coo
كـ booleans.

الأفضل:

text
Copy
DocumentRequirement
Document
DocumentVersion
DocumentLink
ثم Profile:

text
Copy
Egypt ACI Readiness Profile

Commercial Invoice        Required
Packing List              Required
BL Copy                    Required
Certificate of Origin     Required/Conditional
MSDS                       Conditional
Health Certificate        Conditional
Phytosanitary             Conditional
Inspection Certificate    Conditional
...
هذا يسمح لاحقًا باختلاف cargo/customer/SOP.

الـ Release Gates التي يجب أن يراها Operations
أقترح أن تكون شاشة الـ Job فيها شيء قريب من:

Gate	مثال Status
ACI Readiness	Ready / Missing / Locked
Document Readiness	Complete / Incomplete
Vessel	In Transit / Arrived
Customs Declaration	Not Started / In Progress / Exception
Customs Release	Pending / Released
Carrier DO	Pending / Released
Terminal Release	Pending / Ready
Delivery	Not Planned / Planned / In Progress / Delivered
Empty Return	Pending / Returned
Operational Closure	Open / Complete
Financial Closure	Open / Complete

هذه status names اقتراح Product Design وليست official NAFEZA vocabulary.

سبب الفصل واضح من المصادر: NAFEZA تفصل Import Declaration عن release وعن Delivery Order، والـ carrier يضيف شروطه الخاصة للـ DO/container release. 

Exception Matrix
المشكلة	ما نعرفه	ما يجب أن يفعله النظام
Missing ACI document	ACI له document requirements قبل الشحن	Block readiness + Task + Alert
Incorrect ACI data	NAFEZA توفر خدمة amendment للبيانات المرتبطة بالـ B/L	Exception workflow؛ لا silently overwrite
ACI locked	Lock يمنع تعديل shipment data بعد بدء shipping procedures	Record locked_at; amendments تعامل كاستثناء
Exporter docs not ready	جزء أساسي من CargoX/ACI flow	Docs Pending مع owner واضح
Container لم تصل	NAFEZA لديها non-arrived-container service	Container-level exception
Customs examination	NAFEZA تتعامل مع inspection/examination	Customs task، وليس تغيير Job status كلها
Customs released لكن DO غير جاهز	عمليتان مستقلتان	Delivery remains blocked
Carrier charges/documents pending	carrier قد يمنع DO/release	Carrier Release exception
Lost Original B/L	carrier-specific procedure	SOP per carrier، لا global rule
Vessel ETA changed	carrier tracking/arrival sources تتغير	append ETA event + re-plan tasks
Container واحدة فقط خرجت	multi-container reality	partial delivery
Empty return متأخر	free-time/carrier terms vary	per-container overdue alert
Hazardous/Reefer	بعض terminal workflows تختلف	Cargo profile + SOP override

بالنسبة إلى Lost Original B/L تحديدًا، لا تعمم procedure واحدة. Hapag-Lloyd Egypt، مثلًا، تنشر إجراء يتضمن Police Report وLOI في حال lost original BL؛ هذا يثبت أن exception موجود وله formal carrier workflow، لكنه لا يثبت أن كل carrier يستخدم نفس المستندات. 

وبالنسبة إلى ACID amendments، NAFEZA لديها خدمة مستقلة لـ “Request to Amend ACI Data Associated with Bill of Lading”، ما يعني أن تطبيقك يجب أن يحتفظ بتاريخ التعديل وألا يفترض أن أي field يمكن editها بحرية بعد ACI processing. 

Free Time وDemurrage/Detention
دي منطقة خطرة جدًا لو عملنا فيها assumptions.

Carrier tariffs تتغير. Hapag-Lloyd Egypt تحيل المستخدم لأداة local charges/free time، وMaersk تنشر تعديلات مصرية على charges من وقت لآخر؛ وبالتالي لا توجد قيمة global مثل:

text
Copy
free_days = 7
صالحة لكل Job. 

MVP الصحيح:

text
Copy
free_time_source = manual/carrier
free_time_days
free_time_basis
free_time_expires_at
verified_at
verified_by
ثم في مرحلة Integration لاحقة يمكن الأتمتة carrier-by-carrier.

Alexandria مقابل Damietta
في الإسكندرية، لدينا دليل رسمي أوضح على الـ terminal digitization: CATOS يدير vessel/yard/container/gate information، OCR يسجل Gate In/Gate Out، ومركز Logistics Services Center الجديد يعمل على inspections/examinations/release مع استثناءات مثل refrigerated/hazardous containers. 

في دمياط، المصدر الرسمي الذي أمكن التحقق منه يوفر vessel schedules، container tracking ومعلومات terminal services، لكنه لا يقدم public end-to-end SOP كافيًا لكي نبني logic مختلفًا صلبًا باسم if port == Damietta. 

لذلك القرار البرمجي الصحيح:

text
Copy
National Egyptian Rules → Code / configurable business rules

Carrier-specific SOP     → Carrier configuration/profile

Terminal-specific SOP    → Terminal configuration/profile

Company internal SOP     → Tenant workflow configuration later
لا تعمل AlexandriaWorkflowService وDamiettaWorkflowService الآن.

ترجمة البحث إلى شاشات وقواعد FCL M1
أهم نصيحة هنا: M1 يجب أن يثبت الـ operational spine، وليس أن يحاول إنهاء الشحن البحري المصري كله.

الشاشة الرئيسية التي أوصي بها
Jobs Inbox
مش مجرد list.

text
Copy
Job No.
Customer
Source
MBL/HBL
Containers
POD
ETA
Assigned Operator
ACI readiness
Docs readiness
Customs status
Release status
Next task
Exception flag
Filters مهمة:

text
Copy
My Jobs
My Branch
Arriving this week
Missing ACI/Documents
Customs Exception
Pending DO
Ready for Delivery
Empty Return Due
Overdue Tasks
Job Workspace
أرشح Tab structure:

text
Copy
Overview
Parties
ACI
BL / Shipment
Containers
Documents
Timeline
Tasks
Customs          [future M3]
Release          [future M3]
Delivery         [future M4]
Charges / P&L    [future M5]
Audit
لكن مش لازم كل Tabs تكون implemented بالكامل في M1.

الـ Overview يجب أن يجاوب خلال ثوانٍ على سؤال:
“إيه وضع الشحنة وإيه الحاجة اللي موقفاها دلوقتي؟”

مثلًا:

text
Copy
IMP-2026-00184
Alexandria | MAIN Branch
Operator: Ahmed

ETA                  02 Nov 2026
Containers           2
ACI                   READY
Documents             1 MISSING
Customs               NOT STARTED
Carrier Release       NOT READY
Delivery              BLOCKED

NEXT ACTION
Obtain missing Certificate of Origin

RISK
Arrival in 3 days
Data Model الذي أراه أنسب بعد البحث المصري
أنا أوصي الآن بهذا الـ M1 domain:

text
Copy
parties
import_jobs
job_parties

bookings

bills_of_lading
bill_relationships

aci_records

containers
bill_container

job_events

tasks

document_requirements
documents
document_links

external_references
وأؤجل:

text
Copy
customs_declarations
release_gates
deliveries
operational_charges
customer_invoices
vendor_bills
إلى milestones التالية، لكن حدودهم لازم تكون معروفة من M1.

العلاقات المهمة
text
Copy
Import Job
│
├── Parties
│   ├── Customer
│   ├── Importer
│   ├── Shipper / Exporter
│   ├── Consignee
│   ├── Notify Party
│   ├── Origin Agent
│   └── Carrier
│
├── Booking(s)
│
├── Bills of Lading
│   ├── MBL
│   │   ├── HBL
│   │   └── HBL
│   └── ...
│
├── ACI Records
│
├── Containers
│
├── Documents
│
├── Events
│
└── Tasks
أهم Business Rules لـ M1
فتح Job: يمكن فتح Draft Job بمجرد وصول معلومات تشغيلية قابلة للعمل؛ لا يجب انتظار وصول السفينة. هذا استنتاج product design مدعوم بكون ACI وshipping data موجودة pre-shipment. 

Booking: optional بالنسبة إلى Destination Import Job.

Origin Agent: optional.

MBL/HBL: Entities وليست strings فقط.

ACID: لا تفترض One ACID Per Job. consolidated cases قد تحتاج ACIDs متعددة. 

Container: كيان مستقل، وحالته مستقلة.

Container ↔ BL: لا تفترض one-to-one.

Vessel/Voyage: ليست unique key للشحنة؛ أكثر من shipment قد تكون على vessel نفسها. 

ETA: current value + immutable history.

Arrival: Event وليس Job lifecycle state أساسيًا.

Customs: Domain مستقل.

Carrier Release: Domain مستقل.

Delivery: لا تبدأ آليًا بمجرد Customs Release.

Empty Return: per-container.

Operational Close ≠ Financial Close.

Audit: كل write مهم يجب أن يحفظ actor/time/change context.

Branch: Job لها owning branch، والـ authorization يجب أن يراعي branch scope.

Tenant: لا يوجد tenant_id داخل طلب client يحدد Tenant؛ tenant context يظل من الـ hostname/session architecture الموجودة بالفعل.

Permissions التي أنصح بتجهيزها من البداية
حتى لو M1 لا ينفذ Customs/Finance بالكامل، permission vocabulary يجب ألا تكون:

text
Copy
manage_shipments
فقط.

الأفضل على الأقل:

text
Copy
jobs.view
jobs.create
jobs.update
jobs.assign
jobs.cancel

parties.manage

bills.manage
containers.manage

documents.view
documents.manage

tasks.manage

customs.view
customs.manage
customs.release

delivery.view
delivery.manage

finance.view
finance.manage
finance.approve
وبعدين تربطها بالـ Branch Scope.

وده مهم خصوصًا لأن مشروعك قضى Milestone 5.1 في تقوية tenant identification، session invalidation، authorization sync، impersonation، concurrency وlong-running authorization. التقرير نفسه يقول إن الـ automated verification نجح، لكنه يذكر صراحة أن الـ browser/real-server verification لم تُغلق بعد، كما توجد low-severity review notes مرتبطة ببعض Livewire/authorization behavior. لذلك لا أنصح بعمل SaaS Milestone جديدة، لكن أنصح بإغلاق browser verification كـ gate بالتوازي مع FCL M1، وأن تُعامل Customs Release/financial approval/document deletion مستقبلًا كأفعال sensitive أثناء impersonation. 

Acceptance Gate حقيقي لـ FCL M1
أنا لن أعتبر M1 ناجحة لأن CRUD اشتغل.

أعتبرها ناجحة عندما تقدر تعمل هذا السيناريو:

text
Copy
1. Employee في Tenant A يستلم Origin-Agent Pre-Alert.

2. ينشئ Draft Import Job.

3. يحدد:
   Customer
   Importer
   Exporter
   Origin Agent
   Carrier
   POL / POD
   Vessel / Voyage
   ETA

4. يضيف MBL.

5. يضيف HBL عند وجوده.

6. يسجل ACID الصحيح على المستوى المناسب.

7. يضيف Container أو أكثر.

8. النظام يعرض ACI / Documents readiness.

9. يسند Job لموظف وBranch.

10. يسجل كل تعديل في Timeline/Audit.

11. يغيّر ETA بدون فقد التاريخ.

12. Container واحدة يمكن أن تختلف حالتها عن الأخرى.

13. Employee آخر مصرح له يكمل نفس Job.

14. Employee غير مصرح له في Branch أخرى لا يراها وفق السياسة التي ستعتمدها.

15. Tenant B لا يستطيع اكتشاف أي record أو document يخص Tenant A.

16. الـ Central DB لا تحمل transaction records الخاصة بالشحنة.
لو ده اشتغل على شحنة مصرية حقيقية بعد anonymization، ساعتها M1 عندها أساس قوي.

ما يزال يحتاج موظفين مصريين حقيقيين قبل تثبيت الـ SOP
الـ Deep Research الثاني غطى جزءًا مهمًا جدًا من الفجوة التي كانت موجودة في البحث الأول، لكنه كشف بوضوح أن الإنترنت لن يعطيك SOP الداخلي الكامل لشركة Freight Forwarding مصرية.

الجزء الوطني الرسمي واضح نسبيًا: ACI قبل الشحن، ACID، NAFEZA، CargoX، المستندات، Import Declaration، inspection/release ecosystem وبعض Freight Forwarder MBL/HBL rules. 

لكن هذه الأسئلة لا تزال تحتاج walkthrough من Operations:

السؤال	ليه مهم للسيستم؟
مين داخل الشركة يستلم أول Pre-Alert؟	Ownership وJob creation
إمتى تعتبر الشركة Pre-Alert كافية لفتح Job؟	Draft/Open rule
مين يتابع ACID؟ Documentation أم Customs أم Customer Service؟	Role/Task routing
هل الشركة تنشئ Master ACID بنفسها في consolidated cases؟	ACI workflow
مين يراجع MBL/HBL draft؟	Permissions
مين يتابع Arrival Notice وLine invoices؟	Task ownership
مين يفتح Customs Declaration داخليًا؟	Customs workflow
ما أسماء الـ Customs statuses التي يستخدمها الفريق فعليًا؟	UI/domain mapping
مين يستخرج Delivery Order؟	Release responsibility
هل Finance تدفع line charges أم Operations؟	Handoff
إزاي يتأكدوا إن terminal أصبحت ready؟	Delivery gate
هل عندهم truck fleet أم subcontractors؟	Delivery model
مين يختار empty-return depot ويتابع التاريخ؟	Container workflow
إمتى Operations تعتبر Job مقفولة؟	Operational closure
إمتى Finance تعتبرها مقفولة؟	Financial closure
إزاي يحسبوا Demurrage/Detention اليوم؟	Future M4/M5
إيه Excel sheets اللي لسه هي source of truth؟	Hidden business rules

نوع الملفات التي أطلبها من الشركة
مش محتاج 100 Shipment.

3–5 Jobs مختارة كويس أهم من 500 row في Excel.

أفضل مجموعة:

Case	السبب
Origin Agent + 1 container	Base case
Direct Shipping Line	يكشف اختلاف source workflow
2–4 Containers	يكشف partial operations
MBL + HBL	يكشف document hierarchy
Delayed / Missing Document case	يكشف exception flow
Customs Examination/Hold	يكشف customs reality
Late Empty Return	يكشف container closure
Consolidated case إن كانت الشركة تعملها	يختبر MBL/HBL/ACID model

لكل واحدة اطلب بعد إخفاء البيانات الحساسة:

text
Copy
Initial email / Pre-Alert
Commercial Invoice
Packing List
MBL / HBL
Certificate of Origin
ACID information
Arrival Notice
Customs reference / screenshots
Carrier DO
Release evidence
Container movement information
Trucking instruction
POD
Empty-return evidence
Customer invoice
Supplier/line/trucker costs
Internal Excel tracker row
ثم لا تسأل الموظف:

"إيه الـ fields اللي محتاجها؟"

الأفضل تمشي معه:

"وصلتك الرسالة دي. عملت إيه بعدها؟"

ثم:

"إزاي عرفت إن الخطوة دي خلصت؟"

ثم:

"إيه اللي ممكن يمنعك تكمل؟"

ثم:

"مين الشخص اللي استلم منك بعدها؟"

ثم:

"إيه المستند أو الموقع اللي أثبتلك إن الخطوة تمت؟"

ده اللي هيطلع الـ SOP الحقيقي.

قراري النهائي بعد البحثين
أنت الآن جاهز تبدأ FCL Operations Discovery/M1 Specification، وليس جاهزًا أن تقول لـ Claude: “ابنِ كل FCL Import Operations”.

الفرق مهم.

البحث الأول أعطانا:

كيف يجب أن تُبنى Freight Software architecture.

والبحث الحالي أعطانا:

كيف تبدو الدورة المصرية الرسمية والتشغيلية، وأين تقع NAFEZA/ACI/MBL/HBL/Customs/DO/Terminal/Empty Return.

وما ينقصنا الآن فقط:

كيف الشركة المصرية التي ستستخدم منتجك توزع هذه الخطوات يوميًا بين موظفيها.

لذلك أنا لا أنصح بعمل Deep Research واسع ثالث قبل التنفيذ. العائد الآن سيكون أقل من العائد من جلسة واحدة مع Operations + ملفات حقيقية.

الخطوة المثالية أصبحت:

text
Copy
SaaS Foundation
       ✅

Global FCL Architecture Research
       ✅

Egypt FCL Operations Research
       ✅

Real Company Walkthrough
       ⏳

FCL M1 Business Specification
       ↓

Claude Code Implementation
       ↓

Browser Validation With Real Shipment
وبالتحديد: يمكن بدء تصميم FCL M1 الآن، لكن لا أثبت كل Business Rules الخاصة بالجمارك، DO، trucking وfinancial close قبل الـ walkthrough الميداني.

المصادر الرسمية والرئيسية المستخدمة
جميع الروابط التالية تم الوصول إليها أو التحقق منها في 22 أكتوبر 2026 ما لم يُذكر تاريخ تحديث الصفحة نفسها.

المصدر	ما تم استخدامه فيه
Egyptian Customs — Advance Cargo Information	إلزام Maritime ACI وتوقيت الـ 48 ساعة قبل الشحن. 
NAFEZA — Documents required to create an ACI file	Commercial Invoice، Packing List، B/L Copy، COO والمستندات المشروطة وحد 5 MB. 
NAFEZA — ACI Lock & Request Validator	Verify/Lock ACID قبل shipping ومنع التعديل بعد lock. 
NAFEZA — Freight Forwarder Role in ACI	Foreign FF، Egyptian agent، MBL ACID وHBL handling. 
NAFEZA — Consolidated LCL/FCL ACID	Multiple sub-B/L ACIDs + consolidated/master ACID. 
NAFEZA — Import Declaration	إثبات وجود Import Declaration كعملية مستقلة. 
NAFEZA — Customs Declaration Tracking	real-time declaration-status follow-up. 
NAFEZA — Import Release	وجود process مستقل للإفراج. 
NAFEZA — Submit a Delivery Order	إثبات DO كعملية منفصلة. 
NAFEZA — Non-Arrived Containers	إثبات partial/non-arrived container scenario. 
NAFEZA — Overview	Documentation → inspection → payment → consolidated release flow. 
CargoX — Egypt ACI / NAFEZA	دور CargoX في نقل المستندات ضمن ACI ecosystem؛ مصدر vendor corroboration وليس بديلًا عن الجمارك. 
Alexandria Container & Cargo Handling — Terminal Operating Systems	CATOS، EDI، container movements، gates وOCR. 
Alexandria Container & Cargo Handling — Logistics Services Center	مركز inspection/examination/release الجديد، بدأ يوليو 2025. 
Damietta Container & Cargo Handling	Vessel schedule، container tracking وterminal services. 
Hapag-Lloyd Egypt — Local FAQ	Arrival Notice، Import DO/container release requirements وfree-time/local charges. 
CMA CGM Egypt — Import Information Guide	Arrival Notice، Delivery Order وShipment Tracking كعمليات import منفصلة. 
Maersk — Container Release	Customs + charges + documents كمتطلبات قبل container release. 

الخلاصة الحاسمة للمشروع: البحث الحالي أعطاك معلومات كافية لكي تتوقف عن البحث النظري الواسع وتبدأ تحويل النتائج إلى FCL M1 Business Workflow & Domain Specification. لكن قبل تثبيت تفاصيل الـ Operations اليومية، خذ شحنة حقيقية واحدة مع موظف Operations وشحنة مع Customs وامشِ بهما من أول Pre-Alert حتى Empty Return. عندها لن يكون M1 مبنيًا على تخمينات أو على تقليد CargoWise؛ سيكون مبنيًا على القواعد المصرية الرسمية + بنية Software سليمة + طريقة شغل العميل المصري الفعلية.