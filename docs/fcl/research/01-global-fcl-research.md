FCL Import Operations Research and MVP Architecture Report
Executive summary
As of 22 October 2026, the strongest design direction for your platform is to make the FCL Import Job the operational aggregate—the record that users open to understand and operate a shipment—but not to turn it into one giant database row or one giant status machine.

Public product material from CargoWise, Magaya, GoFreight, and Descartes shows a consistent industry pattern: successful freight-forwarding systems bring shipment operations into one operational workspace while keeping bookings, transport documents, containers, events, customs, delivery, documents, tasks, and finance as connected but distinct business concepts. CargoWise describes an integrated forwarding workflow spanning booking, documentation, carrier connectivity and internal workflows; Magaya exposes bills, bookings, shipments, events, invoices and document attachments as distinct integration objects; GoFreight exposes operational and financial objects through APIs and webhooks; Descartes describes a SaaS forwarding TMS covering multimodal operations and accounting. 

That pattern also aligns closely with current DCSA standards. DCSA has separate standards for Booking, Bill of Lading, Arrival Notice, and Track & Trace, rather than a universal “shipment status.” Booking 2.0 and Bill of Lading 3.0 were finalized in 2025; Arrival Notice 1.0 was published in November 2025; and Track & Trace defines shipment, transport, equipment and, in 3.0, IoT/reefer events. DCSA’s 2026 roadmap says shipment-release standardization is only now being developed, which means an import application should model release as its own domain rather than pretend a mature universal release API already exists. 

For your existing SaaS, the Database-per-Tenant architecture should remain. FCL operational records should live entirely inside the corresponding tenant database; fcl_central should continue to function as the SaaS/control plane for tenant provisioning, subscriptions, platform administration and cross-platform support metadata. Stancl’s own documentation describes the trade-off: single-database tenancy lowers infrastructure complexity but increases application-level scoping complexity, while its multi-database tooling switches the default database into the tenant context. Its queue and filesystem bootstrappers can likewise carry tenant context into jobs and tenant-prefix storage. 

This recommendation is particularly appropriate for your codebase because Milestone 5.1 already hardened host identification, tenant authentication/session behavior, long-running Livewire authorization, authorization synchronization, concurrency, provisioning and impersonation-related session behavior. It also records that M5.1 was automated-test verified but had not yet received its browser/real-server verification, and it retains a small number of low-severity Livewire/authorization review notes. 

For Egypt, the most important fact is that ACI/ACID is upstream of port arrival. Maritime ACI has been mandatory since 1 October 2021, and Egyptian Customs states that shipment data and documents must be available at least 48 hours before shipping, not 48 hours before arrival. NAFEZA’s workflow captures the Egyptian importer, foreign exporter, shipment, initial BL, ports, carrier/means of transport, gross/net weight, package count and invoice/commodity information before issuing the ACID identifier. 

NAFEZA lists the usual ACI package as commercial invoice, packing list, a copy of the bill of lading rather than the original, and certificate of origin, with additional certificates depending on the cargo. It also requires ACID on shipment documentation, and official guidance says the importer/exporter identification data must appear on the commercial invoice and BL. 

That changes how the MVP should be thought about:

The FCL Import Job is not merely “created when the vessel arrives.” It represents the destination forwarder’s operational file, which can begin as soon as actionable pre-alert/ACI/BL information becomes available.

The destination office may not itself have created the ocean booking. Therefore, Booking should be optional/reference data for an import job, not a mandatory prerequisite for opening one. This is especially important for your two expected business channels—an overseas Origin Agent sending a pre-alert versus a Direct Shipping Line relationship.

The recommended sequence is therefore:

Pre-Alert / Import Job → Documentation & ACI readiness → BL/Booking references → Containers → ETA/Arrival Events → Customs File → Customs Release → Carrier/Terminal Release → Delivery → Empty Return → Operational Closure → Financial Closure

The most important architectural decision is to separate those states. A shipment can be physically arrived while its customs file is incomplete; customs can be released while carrier release is still pending; a container can be delivered while empty return is outstanding; operations can be complete while AP/AR is still open. DCSA’s standards separation strongly supports that model. 

My recommended development roadmap is:

Milestone	Primary objective	Relative effort
FCL M1	Core Import Jobs, parties, BL/booking references, branch ownership, basic timeline	Large
FCL M2	Containers, secure documents, operational events and task automation	Large
FCL M3	Egypt ACI/customs readiness, declaration tracking and release	Large
FCL M4	Delivery, trucking, gate-out, POD, empty return and visibility	Medium–Large
FCL M5	Operational charges, costs, AR/AP concepts and Job P&L	Large
FCL M6	Reports, carrier/API integrations, customer visibility and production hardening	Large

The biggest recommendation from this research is not to start M1 by building every possible logistics table. Start with one real Egyptian FCL import job, build a correct operational spine, and leave customs integration, automatic carrier integration, sophisticated detention/demurrage calculation and accounting depth to subsequent milestones.

Commercial systems and design lessons
The purpose of this comparison is pattern mining, not vendor ranking. Public documentation does not expose every vendor’s internal database topology, tenant-isolation implementation or complete API contracts. Where that information is not publicly verifiable, it is marked unspecified rather than inferred.

Area	CargoWise	Magaya	GoFreight	Descartes
Deployment architecture	CargoWise markets a global integrated platform and explicitly references CargoWise Cloud hosting. Current Value Packs include standard cloud hosting for many customers. 
Digital Freight Platform is cloud-based; Magaya Connect also integrates cloud and on-premise applications. 
Public product material describes a fully cloud-based freight platform; its API is web-accessible and exposes programmatic platform data. 
Freight Forwarder TMS is explicitly described as SaaS-based, with Forwarder Enterprise QW also described as web/cloud-based. 
Vendor-internal tenancy model	Unspecified publicly. “Single global database/platform” describes the user operating model but does not prove a particular SaaS tenant-isolation implementation. 
Unspecified publicly.	Unspecified publicly.	Unspecified publicly.
Public operational data model	Shipment/jobs, booking/documentation, carrier connectivity, customs entries, transport documents, containers, financial context. 
Bills, rates, quotes, bookings, booking responses, inventory, shipments, invoices, shipping events, documents, organizations and warehousing are explicit API/integration concepts. 
API exposes operational and financial resources, including containers and invoice/payment events. Public use cases also expose shipment/document workflows. 
Public TMS model is described primarily by shipment/operations, accounting, financial analysis, office communication and integrations; low-level persistence is unspecified. 
Key modules	Forwarding, customs/compliance, warehouse, land transport, documentation, automation and finance-related workflow. 
Freight forwarding, shipping, WMS, PO management, customs/compliance, rate management, accounting, customer portal and integrations. 
Ocean freight lifecycle, containers, invoicing/accounting, CRM, workflows/tasks, customer portal, integrations and region-specific customs capabilities. 
Multimodal forwarding operations, accounting/financial analysis, customs-related products, integration and visibility. 
API / events	Built-in integrations are a key selling point. Public comprehensive endpoint/webhook documentation comparable to GoFreight's is not openly exposed in the material reviewed; full public contract therefore unspecified. 
Open API consists of web services; Connect adds an event-based integration model, API endpoints and webhook mechanisms. 
Strongest publicly documented developer interface of the four reviewed: API-key authenticated API plus webhook subscriptions with events such as invoices, payments and containers. 
Forwarder TMS markets integrative capability, but complete public endpoint/webhook contracts are unspecified. Descartes Customs separately publishes an integration/message interface. 
Documents	Documentation is embedded into forwarding workflows, and document data is also used by customs/compliance processes. 
Hundreds of document templates; document attachments are part of Open API; Magaya’s network/platform exchanges operational documentation. 
Shipment/document workflows and downloadable customer documents are product concepts; API/webhook ecosystem supports operational integration. 
Document and shipment processing are part of the wider forwarding/customs suite, but detailed public document-storage internals are unspecified. 
Pricing model	Current Value Pack model charges per eligible logistics transaction; forwarding is presented as one all-inclusive fee per shipment, with user-seat and standard cloud-hosting fees removed for many customers, while optional/premium items can remain separate. 
Public pages reviewed do not disclose a sufficiently specific current formula to characterize seat/module/transaction pricing safely: unspecified.	Current pricing page is quote-based and asks number of employees and offices; exact calculation is unspecified publicly. 
Forwarder TMS pricing is unspecified publicly. Descartes Customs, a separate product, publicly demonstrates a SaaS pattern combining base subscription, users/modules and volume charges, which should not be assumed to be the Forwarder TMS pricing model. 
Best lesson for your MVP	One operational platform, high reuse of shipment data, integrated compliance and automation.	Keep freight, documents, finance, events and integrations connected but modular.	Make API/webhook readiness a first-class architecture concern and maintain event-driven container visibility.	Keep operations and financial performance connected while supporting multiple offices.
Public-information limitation	Internal tenancy/schema and full public integration contract are not disclosed.	Internal tenancy and exact commercial pricing are not disclosed.	Internal tenancy/schema and Egyptian customs integration are not publicly established by the material reviewed.	Forwarder TMS low-level data model, complete API contract and exact pricing are not public.

Several important design lessons emerge.

First, copy the operational shape, not the feature count. CargoWise’s current Value Pack contains hundreds of forwarding/customs/warehouse/transport capabilities; reproducing that scope would be a mistake for an MVP. What matters is the underlying principle: data entered once should flow through subsequent stages instead of being retyped. CargoWise explicitly positions booking, documentation, carrier communication and workflow on one platform, and its customs material describes turning shipment/document context into declaration-ready data. 

Second, make the Job the workspace but not the only entity. Magaya’s Open API separately exposes Bills, Bookings, Shipments, Shipping Events, Document Attachments, Organizations and Invoices. That is a useful public indication that these concepts deserve distinct identities even if users experience them through one shipment screen. 

Third, event-driven integration should be designed in before you actually integrate carriers. GoFreight exposes webhooks for operational and financial changes, and DCSA’s Booking/BL standards use notification patterns rather than requiring endless polling. Your internal model should therefore be able to process an external event idempotently even while M1 starts with manual data entry. 

Fourth, finance belongs to the operational job, but SaaS billing absolutely does not. Freight systems commonly connect shipment operations to AP/AR and profitability: GoFreight has shipment-level financial functions, Magaya includes accounting, and Descartes connects forwarding with financial analytics. 
 Your tenant’s shipment charges should therefore live in its Tenant DB, while your own SaaS subscription billing remains in fcl_central.

Fifth, do not infer their SaaS tenancy architecture. A vendor saying “one global database” or “cloud based” does not establish whether it uses one physical DB per customer, schemas, row-level tenancy, shards or another internal architecture. Your existing Database-per-Tenant choice should be evaluated on your threat model and operational needs, not copied from unverifiable vendor internals.

Standards, workflow, and Egypt-specific operations
The most relevant standards for an ocean FCL import product are not all equally important.

Standard / protocol	Relevance to your FCL MVP	Recommended use
DCSA Booking 2.x	High for carrier booking integration. Booking 2.0 and BL 3.0 final versions were released in February 2025. 
Design Booking and booking-status concepts to map cleanly to DCSA, even if M1 only stores external booking references.
DCSA Bill of Lading 3.x / eBL	Very high. DCSA treats Shipping Instructions and Transport Documents as explicit processes and provides API specifications/business models. 
Separate BL identity, type, lifecycle and parties from the Import Job. Do not store “BL number” as one incidental string only.
DCSA Arrival Notice 1.0	Very high for import. It standardizes arrival notices as structured machine-readable data and supports versions/updates. 
M2 should have a structured Arrival Notice/event ingestion model rather than just PDF attachment storage.
DCSA Track & Trace	Very high for container/event visibility. It defines shipment, transport, equipment and related events. 
Build a normalized job_events / container_events model.
DCSA Track & Trace 3.0	Architecturally relevant but not yet generally public for implementation: DCSA published it on 30 Sep 2026 for members/partners first, with public release planned for H2 2027. 
Keep the event schema extensible; do not make private/non-public 3.0 artifacts an MVP dependency.
DCSA Shipment Release	Future relevance. As of the 2026 roadmap, shipment release standardization is still being developed. 
Implement a clean local Release domain now; add a standard adapter later.
UN/EDIFACT	Important for legacy ocean/terminal/carrier interoperability. UNECE directories show transport/equipment messages such as IFTMIN, IFTSTA, COPARN and CODECO sharing common goods/equipment structures. 
Treat EDIFACT as an adapter format, not as your internal database schema.
ISO 6346	Essential for container identification/size/type. ISO 6346:2022 remains the published edition as of this report, with a new edition in publication. 
Validate container owner/serial/check digit and store ISO size/type codes.
UN/LOCODE	Essential for ports and transport locations. UNECE describes it as a global trade/transport location standard widely used by carriers and forwarders. 
Store POL/POD/location references by UN/LOCODE instead of arbitrary strings where possible.
SOLAS VGM	Relevant container data even though the VGM responsibility occurs primarily on the export/loading side.	Receive/store VGM metadata when available; it does not need to become the center of an Egyptian import workflow.
IATA Cargo-XML / ONE Record	Primarily air cargo, not the baseline for ocean FCL. IATA describes ONE Record as an air-cargo data-sharing standard and Cargo-XML as an air-cargo messaging standard supporting ACI/customs requirements. 
Keep out of FCL M1–M6 unless the product expands into Air Freight.

A particularly useful standards lesson is that interoperability should happen at the boundary of your system. Your own domain model should express freight concepts clearly; DCSA APIs and EDIFACT messages should be mapped into or out of that model by provider adapters. That protects you from coupling business logic to a carrier’s message version.

The following is the recommended end-to-end operational workflow.

Stage	Data that should exist	Main actors and documents	Timing	Validation / edge cases
Pre-Alert / Job Opening	Internal job no.; branch; customer/importer; foreign exporter/shipper; origin agent if applicable; carrier; source channel; POL/POD; vessel/voyage if known; ETA; MBL/HBL refs if known; ACID if available; commodity summary; packages; gross/net weight; assigned operator	Destination forwarder, importer, overseas agent/exporter. Pre-alert email/data, invoice, packing list, BL copy, certificate(s)	Open the job when actionable information arrives. Egyptian ACI data must exist upstream before shipment; Customs says data/docs must be available at least 48h before shipping. 
Do not require every field to be complete. Support Draft → Open. Detect likely duplicate by MBL/HBL/ACID/container/source reference but allow authorized override for legitimate cases.
ACI / Documentation Readiness	Importer tax ID; foreign exporter identifier; ACID; invoice no/date/value/currency; HS/item info; country of origin; BL ref; ports; shipping company; vessel; weights and packages	Egyptian importer, foreign exporter, freight forwarder/agent, NAFEZA/CargoX. Commercial invoice, packing list, BL copy, COO, cargo-specific certificates	Before shipment. NAFEZA issues the ACID after the importer submits required pre-shipment information. 
ACID should be represented explicitly. NAFEZA requires ACID on shipment documents and importer/exporter identifiers on invoice/BL. 
Booking / BL	Carrier booking ref if known; MBL; zero or more HBLs; BL type; shipper/consignee/notify; freight terms; issue date/place; surrender/release state; vessel/voyage; POL/POD	Origin agent, carrier, exporter, forwarder, consignee. Booking confirmation, shipping instructions, draft/final BL/eBL	Booking ordinarily occurs upstream before sailing; a destination-import office may receive it only as reference information. DCSA deliberately models booking and BL as separate processes. 
Booking must therefore be optional on an import job. Support MBL only, MBL+HBL, multiple HBL and amendments. Do not assume one BL equals one container.
Containers & Transit	Container no.; ISO type/size; seal; weights/VGM when received; carrier; current milestone; ETA updates; load/discharge/transshipment events	Carrier, terminal, origin/destination agents. Carrier tracking/events	From booking/pre-alert through discharge	ISO 6346 validation; do not derive truth from one mutable container_status. Maintain event history and computed current state. ISO 6346 defines container identification and size/type coding. 
Arrival	ETA history; ATA; discharge time; terminal; Arrival Notice received/version; free-time data if provided; charges/due notices	Carrier/agent, forwarder, consignee, terminal. Arrival Notice, manifest-derived data	Arrival Notice is provided in advance of arrival and may be updated. DCSA 1.0 supports structured arrival notices and updates. 
Keep ETA and ATA distinct. Never overwrite historic ETA without preserving change history. Duplicate arrival events must be idempotent.
Customs Clearance	ACID; customs declaration ref; declaration timestamps/status; HS/classification references if required; document checklist; examination/hold notes; customs release timestamp/reference	Importer, customs broker/clearance staff, Egyptian Customs, NAFEZA and other authorities depending on cargo	Exact post-arrival duration is variable. The official sources reviewed do not provide a reliable Alexandria/Damietta median suitable for a hard-coded SLA.	Model customs as a separate workflow. Do not invent a NAFEZA API integration until an officially supported integration contract/authorization is verified. Support missing-document, amendment, examination/hold and authority-certificate exceptions.
Release	Customs release; carrier/document release; terminal eligibility; release references; outstanding holds; DO information where applicable	Customs, shipping line/agent, terminal, forwarder/importer	After applicable prerequisites	Customs released ≠ cargo ready for delivery. Store independent release gates. DCSA itself notes that global import-release processes remain fragmented and is only standardizing them now. 
Delivery & Empty Return	Transporter; driver/vehicle where required; pickup appointment; gate-out; delivery address; delivered time; POD; empty-return depot and timestamp; container condition/exception	Operations, trucker, terminal/depot, consignee	After operational release; target dates depend on carrier/terminal free time	Delivery and empty return are separate milestones. Support failed pickup, no-show, changed destination, damaged container, detention risk and split deliveries across containers.
Job Closure	Operational-complete timestamp; unresolved task count; missing docs; containers returned; outstanding charges/AP/AR; financial-close timestamp	Operations supervisor, finance	Organization-specific	Keep operational closure and financial closure separate. Do not let “Delivered” automatically mean fully closed.

Egypt-specific ACI data requirements should materially shape M1. NAFEZA’s official ACI workflow asks for shipment data including initial bill number, ports of loading and arrival, shipping company, freight number, optional shipping agency, means of transport, gross/net weight, packages, invoice/PO number and date, contract type, value/currency and commodity-level information. Those fields should influence your domain model even if M1 does not automate NAFEZA submission. 

The document model should also understand an Egypt ACI readiness profile. NAFEZA says most ACI files include a commercial invoice, packing list, BL copy and certificate of origin; it lists further cargo-specific certificates such as analysis, fumigation, inspection, insurance, health, MSDS, phytosanitary and veterinary certificates. NAFEZA currently says individual documents uploaded through NAFEZA/CargoX should not exceed 5 MB. 

That 5 MB requirement should not automatically become the storage limit of your entire application. It is better modeled as an outbound validation rule for an “Egypt ACI submission package.” Your system may have good reasons to retain higher-resolution or additional internal documents.

For consolidated cases, NAFEZA explicitly documents a special rule: in consolidated LCL/FCL scenarios, sub-bills have their own ACID identifiers and those identifiers are linked into the consolidated/master process. 
 This is another reason why BillOfLading cannot simply be four text columns on import_jobs.

Likewise, NAFEZA states that when multiple shipments travel on the same vessel, separate shipments can still have distinct ACID handling. 
 Thus, vessel + voyage can never be a shipment uniqueness key.

For Alexandria and Damietta specifically, the official sources reviewed did not expose a sufficiently complete, stable end-to-end terminal/customs SOP to justify encoding two separate hard-coded workflows. The national ACI/NAFEZA requirements are authoritative and should be modeled; port-, terminal-, shipping-line- and contract-specific details should live in configurable Port, Terminal, Carrier or SOP profiles. Locations themselves should use UN/LOCODE as the canonical transport-location identifier. 

That distinction matters: regulatory truth belongs in rules; local operating practice belongs in configuration.

Target multi-tenant architecture and data model
The recommended architecture for your application is:

text
Copy
                         CENTRAL / CONTROL PLANE
┌────────────────────────────────────────────────────────────────┐
│ fcl_central                                                    │
│                                                                │
│ Tenant provisioning     SaaS plans/subscriptions/seats         │
│ Platform admins         SaaS billing/payments                  │
│ Support access          Central platform audit                 │
│ Domain/subdomain        Tenant health/control metadata         │
└───────────────────────────────┬────────────────────────────────┘
                                │ tenant identity
              ┌─────────────────┴─────────────────┐
              │                                   │
              ▼                                   ▼
┌───────────────────────────┐       ┌───────────────────────────┐
│ Tenant DB: acme           │       │ Tenant DB: globex         │
│                           │       │                           │
│ Users / Roles / Branches  │       │ Users / Roles / Branches  │
│ Import Jobs               │       │ Import Jobs               │
│ BL / Containers           │       │ BL / Containers           │
│ Documents / Tasks         │       │ Documents / Tasks         │
│ Customs / Delivery        │       │ Customs / Delivery        │
│ Charges / P&L             │       │ Charges / P&L             │
│ Operational Audit         │       │ Operational Audit         │
└───────────────────────────┘       └───────────────────────────┘

             PRIVATE TENANT-PREFIXED OBJECT STORAGE
   tenants/{tenant-uuid}/jobs/{job-id}/documents/...
This follows your existing security boundary rather than introducing a second tenancy design for FCL. Stancl’s DatabaseTenancyBootstrapper changes the default connection when tenant context initializes; its documentation also cautions that explicit connections or models with an explicit connection can bypass that default switch. 
 Therefore, FCL domain models should use the tenant connection by default and must not explicitly request central.

The central database may reasonably contain global reference data such as SaaS configuration or, if you intentionally choose it, a read-only copy/cache of global standards such as UN/LOCODE. It should not hold customer shipment transactions, BLs, commercial invoices, containers or job charges. Where tenant code reads global reference information, use a narrow read-only repository rather than giving arbitrary domain code access to the central connection.

The stancl documentation explicitly says single-database tenancy brings lower DevOps complexity but requires more application scoping and may complicate package integration. Since your system has already paid the operational cost of Database-per-Tenant and hardened several tenant-isolation problems, changing now would add migration risk without a demonstrated FCL benefit. 

Queues need special treatment. Stancl’s QueueTenancyBootstrapper embeds the tenant ID in a job payload and initializes tenancy before the job executes. For database queues, the docs recommend keeping queue storage on the central connection, and they warn against casually mixing central and tenant queue connections because persistent global state can leave a later job in the wrong tenant context. 

For FCL, that means every asynchronous process—document scanning, carrier event ingestion, notifications, document parsing, report generation—should be classified explicitly as either:

Tenant Job → tenant context required

or:

Central Job → no tenant operational access

A queue job that cannot identify its intended tenant should fail closed, not fall back to whichever tenant was previously active.

For file storage, stancl can suffix tenant paths and even prefixes S3 paths by tenant context; its documentation explicitly describes tenant-suffixed filesystem roots. 
 I recommend being even more explicit for FCL documents:

text
Copy
private/
└── tenants/
    └── {tenant_uuid}/
        ├── quarantine/
        │   └── {document_uuid}
        └── jobs/
            └── {job_uuid}/
                ├── commercial-invoice/
                ├── packing-list/
                ├── bill-of-lading/
                ├── certificate-of-origin/
                ├── customs/
                └── delivery/
Never derive the object-storage key directly from the browser-supplied filename.

Your current security baseline makes that especially important. M5.1 already contains tenant-identification, session, authorization, concurrency and long-running request fixes and documents remaining Livewire-related review notes. FCL should build on those controls rather than bypassing them with a generic upload endpoint. 

The recommended entity shape is below. The two entities marked as additions—PARTY and CUSTOMS_DECLARATION—are not in your original requested list, but I consider both necessary to avoid poor design.

logically owns

logically owns

logically owns

primary branch

owns job

assigned operator

customer / shipper / consignee / agent / carrier

references

contains

moves

has

drives

accounts

fulfils

clears

covers

covered by

moves

delivered on

TENANT

BRANCH

USER

IMPORT_JOB

bigint

id

string

job_number

bigint

branch_id

bigint

assigned_user_id

string

source_type

string

lifecycle_status

string

pol_unlocode

string

pod_unlocode

string

vessel_name

string

voyage_number

datetime

eta

datetime

ata

string

acid_number

datetime

opened_at

datetime

operational_closed_at

datetime

financial_closed_at

integer

lock_version

PARTY

bigint

id

string

type

string

legal_name

string

tax_or_registration_no

string

country_code

BOOKING

bigint

id

bigint

import_job_id

string

carrier_booking_reference

string

status

datetime

booked_at

datetime

etd

datetime

eta

BILL_OF_LADING

bigint

id

bigint

import_job_id

string

type

string

number

string

transport_document_type

string

freight_terms

date

issue_date

datetime

surrendered_at

CONTAINER

bigint

id

bigint

import_job_id

string

number

string

iso_size_type

string

seal_number

decimal

gross_weight

decimal

tare_weight

decimal

vgm

DOCUMENT

bigint

id

bigint

import_job_id

string

document_type

string

storage_key

string

original_name

string

detected_mime

bigint

size_bytes

string

sha256

string

scan_status

integer

version

TASK

bigint

id

bigint

import_job_id

bigint

assigned_user_id

string

type

string

status

datetime

due_at

CHARGE

bigint

id

bigint

import_job_id

string

side

string

charge_code

string

currency

decimal

quantity

decimal

unit_rate

decimal

amount

string

status

DELIVERY

bigint

id

bigint

import_job_id

string

status

datetime

pickup_at

datetime

delivered_at

datetime

empty_returned_at

CUSTOMS_DECLARATION

bigint

id

bigint

import_job_id

string

reference

string

status

datetime

submitted_at

datetime

released_at

BL_CONTAINER

bigint

bill_of_lading_id

bigint

container_id

DELIVERY_CONTAINER

bigint

delivery_id

bigint

container_id



Show code
The lines from Tenant to tenant entities in this diagram are logical ownership only. With Database-per-Tenant, they should not become cross-database foreign keys.

There are several key data-model rules behind the diagram.

Do not use one shipment_status enum with twenty values. Keep a coarse ImportJob.lifecycle_status, for example:

text
Copy
draft
open
on_hold
operationally_complete
closed
cancelled
Then let each domain own its real state:

text
Copy
Booking.status
BillOfLading.lifecycle/status
CustomsDeclaration.status
Release status
Delivery.status
Document.scan_status
Task.status
Charge.status
Arrival should primarily be represented by events/timestamps, not by a state named ARRIVED.

DCSA’s separation of Booking, Transport Document, Arrival Notice and Track & Trace is strong external evidence for that decomposition. 

Do not model one BL to one container. A BL may cover multiple containers, and consolidated structures can create relationships where multiple transport documents concern cargo in common equipment. Egypt’s ACI guidance for consolidated LCL/FCL is further evidence that master/sub-document structures matter. 

Do not use strings for every business partner. A reusable Party or Organization record should represent customer/importer, foreign exporter/shipper, consignee, notify party, origin agent, shipping line, customs broker and trucking supplier, with a role/link table where necessary. Magaya’s public Open API explicitly exposes Organization Master Data alongside bookings, bills and shipments, which supports this separation. 

A practical M1 schema should therefore create or establish:

Table/domain	M1 purpose
parties	Reusable business entities
import_jobs	Operational aggregate/root
job_parties	Party roles per job
bookings	Optional carrier/origin booking reference
bills_of_lading	MBL/HBL and document lifecycle metadata
containers	FCL equipment
bill_of_lading_containers	Many-to-many BL/container relationship
job_events	Timeline and integration-ready event history
documents	Metadata/security lifecycle, even if advanced UI lands in M2
document_links	Attach document to Job/BL/Container/etc. without nullable-column sprawl
tasks	Basic operational work
charges	Basic operational revenue/cost records, not full accounting
Existing users, branches, roles	Ownership and authorization

CustomsDeclaration, formal release and advanced delivery entities can be migrated in M3/M4 if you want M1 smaller, but the M1 design must reserve clean boundaries for them.

For API-facing identifiers, preserve whatever primary-key convention the existing project uses. Where external integrations are exposed, add a stable opaque public_id such as a ULID rather than leaking sequential internal IDs.

APIs, sequence flows, and security controls
The Livewire application and REST API should share the same domain actions/services.

For example:

text
Copy
Livewire action ─┐
                 ├──> CreateImportJob action ──> policies/domain rules/transaction
REST controller ─┘
Never implement the rules once inside a Livewire component and separately inside an API controller.

The recommended initial API surface is:

Method	Endpoint	Purpose
POST	/api/v1/import-jobs	Open a job/pre-alert
GET	/api/v1/import-jobs	Search/filter jobs
GET	/api/v1/import-jobs/{job}	Operational workspace data
PATCH	/api/v1/import-jobs/{job}	Update allowed core data
POST	/api/v1/import-jobs/{job}/bills-of-lading	Add MBL/HBL
POST	/api/v1/import-jobs/{job}/containers	Add equipment
PATCH	/api/v1/containers/{container}	Correct allowed container data
POST	/api/v1/import-jobs/{job}/documents/uploads	Initiate secure upload
POST	/api/v1/documents/{document}/complete	Confirm upload and queue scan
POST	/api/v1/documents/{document}/download-url	Generate authorized temporary download
POST	/api/v1/import-jobs/{job}/charges	Add draft revenue/cost
POST	/api/v1/import-jobs/{job}/events/arrival	Record/ingest arrival
POST	/api/v1/import-jobs/{job}/customs-declarations	M3 customs record
POST	/api/v1/import-jobs/{job}/releases	M3 release
POST	/api/v1/import-jobs/{job}/deliveries	M4 delivery

Do not accept tenant_id from tenant-facing API requests. The authenticated hostname/integration identity determines the tenant.

Similarly, a caller may submit a branch_id, but the server must independently verify that the actor can create/access jobs in that branch.

Mutating endpoints intended for external integrations should support an idempotency key. Carrier events additionally need a provider event reference or deterministic content key so that retries do not duplicate events.

A proposed Create Job request:

json
Copy
{
  "source_type": "origin_agent",
  "source_reference": "AGT-SHA-2026-00981",
  "branch_id": 1,
  "assigned_user_id": 17,
  "customer_party_id": 82,
  "origin_agent_party_id": 144,
  "carrier_party_id": 211,
  "pol_unlocode": "CNSHA",
  "pod_unlocode": "EG...",
  "vessel_name": "EXAMPLE VESSEL",
  "voyage_number": "026W",
  "eta": "2026-11-04T08:00:00+02:00",
  "acid_number": "EXAMPLE-ACID",
  "bill_of_ladings": [
    {
      "type": "master",
      "number": "EXAMPLE-MBL-001"
    }
  ]
}
A representative response:

json
Copy
{
  "data": {
    "id": "01K...",
    "job_number": "IMP-2026-000184",
    "lifecycle_status": "open",
    "branch": {
      "id": 1,
      "code": "MAIN"
    },
    "source_type": "origin_agent",
    "eta": "2026-11-04T08:00:00+02:00",
    "ata": null,
    "acid_number": "EXAMPLE-ACID",
    "readiness": {
      "documents": "incomplete",
      "customs": "not_started",
      "delivery": "not_ready"
    },
    "version": 1
  }
}
The important detail is that the response does not claim the shipment is one monolithic status = PRE_ALERT. It exposes separate readiness domains.

A draft charge request should look more like:

json
Copy
{
  "side": "cost",
  "charge_code": "CUSTOMS_CLEARANCE",
  "party_id": 391,
  "currency": "EGP",
  "quantity": "1",
  "unit_rate": "4500.00"
}
and the server should calculate:

json
Copy
{
  "data": {
    "currency": "EGP",
    "quantity": "1",
    "unit_rate": "4500.00",
    "amount": "4500.00",
    "status": "draft"
  }
}
Amounts should never move through PHP floating point. That is consistent with the exact-decimal approach already used in your SaaS billing.

Create Job from Pre-Alert

Tenant Audit
Tenant DB
CreateImportJob
Tenant + Branch Policy
Livewire / API
Tenant Audit
Tenant DB
CreateImportJob
Tenant + Branch Policy
Livewire / API
Operator
Submit pre-alert/job data
Authorize tenant, branch, permission
Allowed
create(validated data)
Check idempotency / likely duplicates
BEGIN
Create ImportJob
Create parties/BL references
Create initial timeline event
Record actor + source
COMMIT
Job + readiness summary
Open job workspace
Operator


Show code
Add Container

Tenant DB
ContainerService
Policy
UI
Tenant DB
ContainerService
Policy
UI
Operator
Add container number/type/seal
Can update this job?
Allowed
Add container
Normalize + ISO 6346 validation
Check duplicate/association
Create container
Create "container added" event
Container created
Operator


Show code
ISO 6346 provides the international container identification and size/type coding framework, so container validation should be based on that standard rather than merely a regex for four letters and seven digits. 

Mark Arrival

Tasks
Tenant DB
EventService
Integration Adapter
Carrier / Manual Operator
Tasks
Tenant DB
EventService
Integration Adapter
Carrier / Manual Operator
alt
[Already processed]
[New event]
Arrival / discharge event
Normalized event + provider event ID
Check idempotency
Existing result
Store immutable event
Set ATA / derived milestone
Create/update arrival tasks
Accepted


Show code
DCSA Track & Trace exists specifically to give shipment/equipment events a common structure, which makes an event-centric internal design much more future-proof than writing directly into a mutable shipment_status. 

Create Customs Declaration

Future NAFEZA Adapter
Tenant DB
CustomsService
Egypt ACI Readiness
UI
Future NAFEZA Adapter
Tenant DB
CustomsService
Egypt ACI Readiness
UI
Automated NAFEZA filing is deferred
until an authorized public integration contract is verified.
ClearanceUser
Create declaration record
Check ACID + required document metadata
Complete / warnings
Create declaration
Store declaration + status
Audit action
ClearanceUser


Show code
This is intentionally a local declaration-tracking model, not a claim that NAFEZA exposes an unrestricted API your SaaS can call. The public official sources reviewed document the ACI/ACID process and portal/CargoX document flow but do not provide enough public material to design a production filing API contract safely. 

Release and Delivery

Audit
Trucker
Delivery
Tenant DB
Release
Audit
Trucker
Delivery
Tenant DB
Release
alt
[Carrier/terminal release not complete]
[All delivery gates complete]
Operator
Record customs release
Update customs release gate
Evaluate other holds
Not ready for pickup
Ready for delivery
Create delivery
Dispatch / pickup instruction
Gate-out
Delivered + POD
Empty container returned
Record milestones
Operator


Show code
The release-gate separation is deliberate. DCSA’s current roadmap says standardization of Shipment Release started only in 2026, while Arrival Notice is already standardized, reflecting the present fragmentation of import release processes. 

Document security architecture should be treated as part of the product design, not added after M2.

OWASP recommends an allowlist of necessary extensions, validation beyond the browser-supplied Content-Type, generated storage names, file-size limits, authorized upload access, storage outside the public webroot or on a separate host, antivirus/sandbox scanning where possible, and CDR for appropriate formats such as PDF/DOCX. 

I recommend this lifecycle:

text
Copy
Browser
   │
   ▼
Authorized Upload Initiation
   │
   ├── validate intended job/document type
   ├── generate UUID/object key
   └── issue short-lived upload permission
   │
   ▼
Tenant Quarantine Prefix
   │
   ▼
Verify size + MIME + signature + SHA-256
   │
   ▼
Malware Scan
   │
   ├── infected  → quarantine/reject/audit
   ├── error     → remain unavailable
   └── clean
        │
        ▼
Promote to Private Tenant Job Storage
        │
        ▼
Authorized Short-Lived Download URL
ClamAV, for example, offers the persistent clamd scanning daemon and streamed scan capability for integration scenarios. 
 It is one implementation option rather than a required architectural dependency.

Laravel itself can generate temporary expiring URLs for private files on local/S3 disks. 
 The key security requirement is that authorization is checked before a temporary URL is generated. The fact that a person possesses a guessed document ID must not be enough to obtain a signed object-store URL.

For Livewire, do not restore a generic upload mechanism merely because it is convenient. File upload is a multi-request process, so every upload-stage request has to initialize the correct tenant and enforce authorization. Your existing project has already uncovered classes of long-running/Livewire security bugs, and M5.1 retains a low-severity note about some Livewire hook ordering during impersonation. 

Impersonation must remain deny-by-default for new FCL writes. Platform support staff should not automatically gain the right to release cargo, alter customs data, edit charges or perform delivery actions just because those screens are introduced. The FCL permission matrix should classify actions such as customs release, financial approval and document deletion as sensitive, and the impersonation middleware should explicitly deny them until reviewed.

Every audit record generated during impersonation should preserve at least:

text
Copy
tenant
effective user
original actor / impersonator
action
resource type + id
timestamp
request/correlation id
before/after metadata where appropriate
reason/support-session reference
Passwords, session IDs, handoff tokens and object-store signatures should never enter audit payloads.

For payment-card scope, shipment charges are not the same thing as payment-card processing. Keep cardholder data entirely outside the FCL operational product. PCI SSC states that PCI DSS applies to entities storing, processing or transmitting cardholder data; even where processing is outsourced, merchants retain responsibilities around service-provider compliance. 
 If you later integrate a payment gateway, use hosted/tokenized provider flows rather than accepting card numbers into documents or ordinary FCL forms. PCI SSC specifically notes that even images containing cardholder data can fall into PCI scope. 

For ordinary PII and commercial documents, apply minimization, role/branch-based access, encryption in transit and at rest, retention rules, access logs, and secure deletion policies; specific Egyptian privacy/legal retention periods should be approved by qualified local legal/compliance advice rather than invented in software requirements.

Backups need to respect Database-per-Tenant semantics. A useful backup is not merely “we have one nightly SQL dump”; the platform must be able to restore Tenant A without overwriting Tenant B, and restore a database and its referenced object-store documents to a mutually consistent recovery point. Production readiness should therefore include scheduled restore drills, not backup creation alone.

Testing strategy and M1–M6 delivery roadmap
Your FCL test strategy should continue the philosophy that worked well in SaaS M5.1: business bugs become permanent regression tests. M5.1 reports 427 tests, 426 passing and one skipped, with every changed/new fix test deliberately run once without its fix to confirm the test actually detected the defect. 
 That is worth preserving.

The new module needs additional classes of verification because FCL introduces files, asynchronous jobs, external events and much higher operational concurrency.

Layer	Required coverage
Unit	Container ISO validation; lifecycle transition rules; document checklist calculation; event normalization; charge arithmetic; deadline/SLA calculations
Domain/service integration	Create/update/close job; add BL/container; duplicate detection; arrival events; customs/release gates; delivery transitions; immutable audit attribution
Tenant isolation	Same numeric/public IDs in Tenant A/B; wrong-subdomain requests; explicit central connection misuse; queue jobs; cache; documents; signed downloads
Authorization	Branch scope; role permissions; direct route/API attempts; Livewire actions; deactivated user; suspended tenant; expired subscription
Impersonation	Platform support and Tenant Admin employee-view restrictions against every newly sensitive FCL write action
Document security	Wrong MIME/extension; oversized file; malicious-test signature; scan failure; quarantine inaccessible; cross-tenant object path; expired signed URL
Concurrency	Same job/reference created twice; same container linked simultaneously; duplicate provider event; two users releasing/closing job; duplicate financial charge
External integration	Webhook signature/auth; idempotency; out-of-order events; duplicate events; provider timeout; bad mapping; dead-letter/retry behavior
Livewire	Action-level authorization after initial render; tenant context retained across updates; impersonation checked on every action; no stale long-running-process state
Real server	acme.localhost vs second tenant with real cookies, CSRF, Livewire updates, uploads, queues and object downloads
Regression	All SaaS M1–M5.1 suites continue to pass; central subscription/billing arithmetic remains untouched by operational FCL finance

Concurrency tests should test the invariant, not only SQL syntax. For example:

Two simultaneous requests attempting to create the same externally identified container/event must leave one logical result.

That lesson is already strongly supported by your M5.1 experience, where a query that superficially looked locked still proved unsafe under MySQL snapshot behavior until tested with a second database session. 

For file isolation, a mandatory test should look conceptually like:

text
Copy
Tenant A uploads document DA
Tenant B has user with same local user ID
Tenant B knows/guesses DA's public ID
Tenant B requests:
    metadata    -> 404/403
    download    -> 404/403
    signed URL  -> never issued
    object path -> not directly public
The same test must be repeated through Livewire and the REST endpoint.

For queues:

text
Copy
Dispatch tenant A scan job
Dispatch tenant B scan job
Dispatch central job
Run all on same persistent worker

Assert:
A only reads A
B only reads B
central does not inherit B
That directly targets the stale-context concern in stancl’s queue documentation. 

For operational finance, distinguish two unrelated proration concepts. Your SaaS’s annual subscription-seat proration is a central billing concern and should remain a regression suite. FCL operational charges—storage, detention, demurrage, per-container, per-day or other rules—must not reuse SaaS proration code merely because both involve money. Implement those calculations only after the relevant line/terminal/customer rules are confirmed.

The recommended roadmap is:

Milestone	Scope	Developer-ready acceptance gate	Effort	Main blocker/risk
M1 — Core FCL Import Jobs	Job workspace, parties, branch ownership, pre-alert data, optional Booking, MBL/HBL, basic containers, ACID field, basic tasks/timeline	A tenant operator can open and manage an FCL Import Job from a pre-alert; Job number is tenant-unique; MBL/HBL and container relationships are represented correctly; job is branch-scoped; same external request is idempotent; Tenant B cannot see any record; HTTP/API/Livewire use the same policies/actions; every write is audited; no FCL transaction is stored in fcl_central; M1 does not claim NAFEZA integration	Large	Real shipment samples and party/BL relationship rules
M2 — Containers, Documents & Events	Full equipment lifecycle, private documents, quarantine/scan, document versions, arrival notices, normalized timeline/events, operational task triggers	ISO container validation; secure tenant-prefixed storage; infected/unscanned docs cannot be downloaded; signed URLs expire; arrival events are immutable/idempotent; ETA history preserved; job workspace shows document completeness and container timeline; queue-worker cross-tenant tests pass	Large	Secure upload implementation and carrier-event mapping
M3 — Egypt Customs & Release	ACI readiness, customs declaration records, customs documents, holds/examinations, customs release, carrier/terminal release gates	ACID and required Egypt-document checklist represented; NAFEZA-specific rules validated without pretending to submit automatically; consolidated-BL/ACID scenario supported; customs release independently recorded; delivery cannot begin while mandatory release gates remain; action is permission/impersonation protected; exceptions are auditable	Large	Customs-broker walkthrough; official integration contract if automated filing is desired
M4 — Delivery & Visibility	Trucking assignment, pickup, gate-out, delivery, POD, empty return, free-time risk, customer-facing operational visibility	Delivery can involve one/multiple containers; no pickup before required releases; POD stored securely; empty return tracked independently; exception and failed-delivery flows exist; actual milestones feed timeline; customer/limited visibility never leaks internal costs or other tenants	Medium–Large	Actual trucking/terminal workflow and free-time sources
M5 — Operational Finance & Job P&L	Revenue charges, cost charges, vendors, AR/AP handoff, currencies, approvals, shipment P&L	Revenue/cost clearly separated; exact decimal arithmetic; no floats; currency explicit; unauthorized staff/support impersonation cannot approve sensitive finance actions; duplicate charge submissions idempotent; profitability reconciles from immutable/approved amounts; SaaS billing remains isolated in central DB	Large	Accounting rules, taxes, invoice requirements, FX and customer/vendor workflows
M6 — Reporting, Integrations & Hardening	Operational dashboards, exception reporting, carrier adapters/webhooks, DCSA mapping, customer visibility, performance, backup/restore and production hardening	KPI definitions agreed; integrations are adapter-based and replay-safe; reporting cannot cross tenant/branch boundaries; restore drill succeeds per tenant; queue/storage/session isolation tested under persistent workers; load/security tests completed; remaining production blockers documented; no generic “production ready” claim without evidence	Large	External provider access, production infrastructure and agreed KPIs

The M1 acceptance criteria should be especially strict about scope. A successful M1 does not need automated carrier tracking, OCR, AI document extraction, NAFEZA automation, detention engines, tax invoicing or customer portals.

It needs to prove the fundamental operational object is correct.

A good M1 user journey is:

text
Copy
1. Operator receives an Origin Agent pre-alert.
2. Operator creates Import Job IMP-2026-xxxxx.
3. Customer / shipper / consignee / agent / carrier are selected.
4. POL/POD, vessel/voyage, ETA and ACID are entered when known.
5. MBL is added.
6. HBL(s), if applicable, are attached to the structure.
7. Container(s) are recorded.
8. Basic documents/checklist can be referenced.
9. Operator/branch ownership is assigned.
10. Job timeline shows who created/changed what.
11. Another authorized employee can continue the same job.
12. Another tenant cannot discover any of it.
If that flow is clean, M2–M6 have a stable base.

If that flow is wrong, adding customs, accounting and tracking will merely make the wrong model harder to change.

There is also one important project gate before broad FCL development: M5.1 itself reports that its fixes had automated verification but no real-server/browser run in that milestone. 
 I would not create another SaaS feature milestone, but I would complete that existing manual verification as an engineering gate while FCL requirements discovery starts.

For production, the FCL module should ultimately satisfy a checklist like this:

Production control	Required outcome
Tenant DB isolation	Proven by automated and real-server cross-tenant tests
Tenant sessions	Tenant-scoped; no cookie/domain crossover
Queue context	Tenant initialized and reverted for every job
File isolation	Private, tenant-prefixed, authorization checked before URL issuance
Malware controls	Quarantine → scan → clean promotion
Authorization	Policies on HTTP, API and Livewire actions
Impersonation	Sensitive FCL writes denied unless explicitly approved
Audit	Original/effective actors preserved
Secrets	No carrier/customs credentials in source/database logs
Backups	Central + every tenant DB + document storage, with restore drills
Integration replay	Webhooks/events idempotent
Financial arithmetic	Exact decimal; explicit currency
Monitoring	Queue failures, scan failures, external-integration failures, DB/storage health
Deployment	Repeatable migrations for all tenant DBs plus rollback/recovery plan
Documents	Retention, deletion and access policy defined
External regulatory integration	Only supported against verified official contracts
Recovery	Ability to restore one tenant without affecting others

Evidence boundaries and prioritized next steps
There are several areas where this research deliberately does not pretend the public evidence is stronger than it is.

Vendor internal tenancy. None of the reviewed public product material for CargoWise, Magaya, GoFreight or Descartes provides enough detail to state exactly how each vendor physically isolates customers at database level. Those fields are therefore marked unspecified rather than guessing.

Vendor pricing. CargoWise currently provides unusually explicit public Value Pack pricing architecture, and GoFreight publicly exposes a custom-quote flow based partly on company size/office count. Magaya’s exact commercial formula and Descartes Forwarder TMS’s exact pricing were not sufficiently publicly specified in the reviewed sources. Descartes Customs has public SaaS pricing, but it is a distinct product and was not used as a substitute for Forwarder TMS pricing. 

Alexandria and Damietta operational timings. I did not find an authoritative public source providing sufficiently stable port-specific end-to-end median import-release timing or a single official terminal-to-delivery SOP appropriate to encode into application logic. Therefore the MVP should record real timestamps and calculate tenant-specific performance rather than hard-code an invented “normal customs clearance = X days” rule. The authoritative timing we can establish is Egypt’s ACI requirement for information/documents at least 48 hours before shipping. 

NAFEZA automation. The official material confirms the portal, ACI/ACID process, document requirements and CargoX-related workflow, but this research did not locate a public production API contract sufficient for implementing direct declaration filing from your SaaS. The correct MVP is therefore NAFEZA-aware, not fake-NAFEZA-integrated. 

DCSA release. Do not wait for it, but do not invent compatibility claims either. The 2026 DCSA roadmap explicitly says Shipment Release entered development/discovery in 2026. Build your own clean release domain and later map it to the eventual standard. 

Track & Trace 3.0. DCSA announced 3.0 in September 2026, but it is initially available to DCSA members/partners and is planned for wider public release in the second half of 2027. Your data model can prepare for it without using unavailable/private artifacts as an MVP dependency. 

ISO container standard. ISO 6346:2022 remains the published edition at the report date; ISO shows the next edition in the final publication process. Implement the current published rules through a replaceable validator/reference layer rather than scattering assumptions throughout the code. 

The research therefore supports a clear product strategy:

Build a freight operating system, not a digital spreadsheet.

The Job is the workspace.
The BL is a transport document.
The Container is equipment.
The Event is a historical fact.
The Customs Declaration is a regulatory workflow.
The Release is a gate.
The Delivery is a fulfillment workflow.
The Charge is a financial fact.
The Document is a secured object with its own lifecycle.
The Task is work that needs to happen.

None of those should be reduced to one shipments table with sixty nullable columns and one giant status enum.

The strongest primary evidence behind that conclusion comes from DCSA’s intentionally separate Booking, BL, Arrival Notice and Track & Trace standards; from the modular object models exposed by Magaya and GoFreight; and from the integrated-but-domain-rich operating models marketed by CargoWise and Descartes. 

For Egypt, build ACI awareness into the core model from day one rather than bolting it on during M3. That means M1 should already understand ACID, importer/exporter identifiers, BL structure, invoice/commodity metadata capability, origin/destination locations and the documents required to determine readiness. Actual customs execution can then arrive in M3 without redesigning M1. NAFEZA’s published ACI process and document requirements justify that approach. 

Finally, do not let this research become a substitute for real operational discovery. Commercial systems and standards tell us how to structure software; they cannot tell us exactly how your target Egyptian freight forwarder divides responsibility between Operations, Documentation, Customs, Customer Service, Finance and Trucking, or which Excel sheets and informal workarounds currently contain critical business rules. Those details need to come from real shipments.

The prioritized next five steps are:

Complete the remaining M5/M5.1 browser verification without starting another SaaS feature milestone. Your own M5.1 report says the hardening was automated-test verified but not browser/real-server verified. Close that gate while FCL analysis proceeds. 

Collect three to five real Egyptian FCL Import files, ideally including an Origin-Agent shipment, a Direct-Shipping-Line case, a shipment with multiple containers, a problematic customs/document case, and—if the business handles it—a consolidated MBL/HBL case. For each, collect the actual pre-alert, BL, invoice, packing list, COO, Arrival Notice, customs references, release evidence, delivery/POD and charges after redacting sensitive data. The ACI document set should be checked against NAFEZA’s official list. 

Run one whiteboard walkthrough with Operations and one with Customs/Finance, reconstructing a shipment from the first message received through empty-return and financial closure. Record who creates each fact, who approves it, which document proves it, what causes delays, what can change, and which event cannot be reversed. Do not ask only “what fields do you need?”; ask “what do you do next, what blocks you, and how do you know it is done?”

Freeze an FCL M1 domain specification before Claude writes migrations. It should explicitly define ImportJob, Party, Booking, BillOfLading, Container, branch visibility, job numbering, source type, ACID, event history, duplicate rules, permissions, audit rules and lifecycle/closure semantics. DCSA/ISO/UNLOCODE should influence field meanings and interoperability, but the internal domain model should remain yours. 

Then implement FCL M1 only. Do not bundle secure document storage, NAFEZA automation, full customs, trucking, operational accounting, carrier APIs and reporting into the first coding milestone. M1 should prove that a real Egyptian FCL Import shipment can be represented correctly, securely and tenant-isolated from pre-alert through a coherent operational job workspace. Once that foundation survives a real-shipment walkthrough, proceed to M2 Containers/Documents/Events and then M3 Customs/Release.