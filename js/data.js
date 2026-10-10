/* ==========================================================================
   data.js — Mock Data Seed
   Comprehensive, interconnected sample data for a fictional city council.
   Seeded into Local Storage on first run by store.initStore().
   ========================================================================== */

const TODAY = new Date();
const iso = (d) => d.toISOString();
const dayOffset = (n) => { const d = new Date(TODAY); d.setDate(d.getDate() + n); return iso(d); };
const dateOnly = (n) => { const d = new Date(TODAY); d.setDate(d.getDate() + n); return d.toISOString().slice(0,10); };

export function seedData(writeKey) {

  /* ----------------------- Council Members ----------------------- */
  const councilMembers = [
    { id:'M-001', name:'Hon. Ricardo Almazan', title:'City Secretary', role:'Presiding', ward:'City-Wide', party:'Independent', email:'almazan@council.gov', phone:'+63 917 100 0001', avatar:'RA', status:'active' },
    { id:'M-002', name:'Hon. Maria Santos', title:'Councilor', role:'Member', ward:'District 1', party:'Progressive', email:'santos@council.gov', phone:'+63 917 100 0002', avatar:'MS', status:'active' },
    { id:'M-003', name:'Hon. Juan Dela Cruz', title:'Councilor', role:'Member', ward:'District 2', party:'Unity', email:'delacruz@council.gov', phone:'+63 917 100 0003', avatar:'JD', status:'active' },
    { id:'M-004', name:'Hon. Ana Reyes', title:'Councilor', role:'Member', ward:'District 3', party:'Progressive', email:'reyes@council.gov', phone:'+63 917 100 0004', avatar:'AR', status:'active' },
    { id:'M-005', name:'Hon. Carlos Mendoza', title:'Councilor', role:'Member', ward:'District 4', party:'Unity', email:'mendoza@council.gov', phone:'+63 917 100 0005', avatar:'CM', status:'active' },
    { id:'M-006', name:'Hon. Lourdes Tan', title:'Councilor', role:'Member', ward:'District 5', party:'Independent', email:'tan@council.gov', phone:'+63 917 100 0006', avatar:'LT', status:'active' },
    { id:'M-007', name:'Hon. Pedro Bautista', title:'Councilor', role:'Member', ward:'District 6', party:'Unity', email:'bautista@council.gov', phone:'+63 917 100 0007', avatar:'PB', status:'active' },
    { id:'M-008', name:'Hon. Cristina Lim', title:'Councilor', role:'Member', ward:'District 7', party:'Progressive', email:'lim@council.gov', phone:'+63 917 100 0008', avatar:'CL', status:'active' },
    { id:'M-009', name:'Hon. Felix Garcia', title:'Councilor', role:'Member', ward:'District 8', party:'Independent', email:'garcia@council.gov', phone:'+63 917 100 0009', avatar:'FG', status:'inactive' },
    { id:'M-010', name:'Hon. Grace Villanueva', title:'Councilor', role:'Member', ward:'District 9', party:'Progressive', email:'villanueva@council.gov', phone:'+63 917 100 0010', avatar:'GV', status:'active' },
    { id:'M-011', name:'Hon. Roberto Aguilar', title:'Vice Mayor', role:'Member', ward:'City-Wide', party:'Unity', email:'aguilar@council.gov', phone:'+63 917 100 0011', avatar:'RA', status:'active' },
    { id:'M-012', name:'Hon. Patricia Ong', title:'Councilor', role:'Member', ward:'District 10', party:'Unity', email:'ong@council.gov', phone:'+63 917 100 0012', avatar:'PO', status:'active' },
  ];

  /* ----------------------- Committees ----------------------- */
  const committees = [
    { id:'C-001', name:'Finance & Appropriations', chair:'M-011', jurisdiction:'City budget, appropriations, revenue', scope:'Financial legislation', status:'active', established:dateOnly(-400), workload:85 },
    { id:'C-002', name:'Laws & Ordinances', chair:'M-002', jurisdiction:'Drafting and reviewing city ordinances', scope:'Legal framework', status:'active', established:dateOnly(-500), workload:92 },
    { id:'C-003', name:'Public Works & Infrastructure', chair:'M-003', jurisdiction:'Infrastructure projects, public works', scope:'Physical development', status:'active', established:dateOnly(-380), workload:78 },
    { id:'C-004', name:'Health & Sanitation', chair:'M-004', jurisdiction:'Public health programs, sanitation', scope:'Health services', status:'active', established:dateOnly(-360), workload:65 },
    { id:'C-005', name:'Education & Culture', chair:'M-010', jurisdiction:'Educational programs, cultural preservation', scope:'Education', status:'active', established:dateOnly(-350), workload:58 },
    { id:'C-006', name:'Peace & Order', chair:'M-005', jurisdiction:'Public safety, law enforcement oversight', scope:'Safety', status:'active', established:dateOnly(-340), workload:71 },
  ];

  const committeeMembers = [
    { id:'CM-001', committeeId:'C-001', memberId:'M-011', role:'Chair' },
    { id:'CM-002', committeeId:'C-001', memberId:'M-002', role:'Vice Chair' },
    { id:'CM-003', committeeId:'C-001', memberId:'M-006', role:'Member' },
    { id:'CM-004', committeeId:'C-002', memberId:'M-002', role:'Chair' },
    { id:'CM-005', committeeId:'C-002', memberId:'M-004', role:'Vice Chair' },
    { id:'CM-006', committeeId:'C-002', memberId:'M-008', role:'Member' },
    { id:'CM-007', committeeId:'C-003', memberId:'M-003', role:'Chair' },
    { id:'CM-008', committeeId:'C-003', memberId:'M-005', role:'Member' },
    { id:'CM-009', committeeId:'C-003', memberId:'M-007', role:'Member' },
    { id:'CM-010', committeeId:'C-004', memberId:'M-004', role:'Chair' },
    { id:'CM-011', committeeId:'C-004', memberId:'M-010', role:'Member' },
    { id:'CM-012', committeeId:'C-005', memberId:'M-010', role:'Chair' },
    { id:'CM-013', committeeId:'C-005', memberId:'M-006', role:'Member' },
    { id:'CM-014', committeeId:'C-006', memberId:'M-005', role:'Chair' },
    { id:'CM-015', committeeId:'C-006', memberId:'M-007', role:'Vice Chair' },
    { id:'CM-016', committeeId:'C-006', memberId:'M-012', role:'Member' },
  ];

  /* ----------------------- Ordinances ----------------------- */
  const ordinances = [
    { id:'ORD-2024-001', number:'Ordinance No. 2024-001', title:'An Ordinance Regulating Single-Use Plastics in Commercial Establishments', author:'M-002', category:'Environment', committeeId:'C-002', status:'Enacted', stage:'Published', dateIntroduced:dateOnly(-120), dateApproved:dateOnly(-60), datePublished:dateOnly(-55), summary:'Prohibits single-use plastic bags and utensils in retail, with phased penalties and a green-incentive program for compliant businesses.', versions:3, aiSummary:'This ordinance bans single-use plastics in commercial establishments, introduces a phased penalty schedule, and creates a green-business incentive program. Key stakeholders include retailers and environmental groups. Estimated enforcement cost is low with high environmental impact.' },
    { id:'ORD-2024-002', number:'Ordinance No. 2024-002', title:'An Ordinance Establishing the City Scholarship Program for Underprivileged Students', author:'M-010', category:'Education', committeeId:'C-005', status:'Approved', stage:'Approved', dateIntroduced:dateOnly(-90), dateApproved:dateOnly(-15), summary:'Creates a scholarship fund for top graduates from low-income households, funded by 1% of the special education trust.', versions:2, aiSummary:'Establishes a need-and-merit scholarship funded by a 1% education trust allocation. Targets 200 scholars annually with a projected 3-year budget of ₱18M.' },
    { id:'ORD-2024-003', number:'Ordinance No. 2024-003', title:'An Ordinance on the Comprehensive Traffic Management Code of the City', author:'M-003', category:'Transportation', committeeId:'C-003', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-30), summary:'Consolidates all traffic rules, introduces a demerit-point system, and designates bike lanes on all major thoroughfares.', versions:1, aiSummary:'A consolidated traffic code introducing a demerit-point system and mandatory bike lanes. High implementation complexity; requires inter-agency coordination with the transport office.' },
    { id:'ORD-2024-004', number:'Ordinance No. 2024-004', title:'An Ordinance Requiring Smoke-Free Zones in All Public Places', author:'M-004', category:'Health', committeeId:'C-004', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-20), summary:'Declares all public parks, terminals, and government premises as smoke-free zones with signage and fines.', versions:1, aiSummary:'Designates smoke-free public zones with mandatory signage and graduated fines. Public health impact is high; enforcement depends on barangay participation.' },
    { id:'ORD-2024-005', number:'Ordinance No. 2024-005', title:'An Ordinance Amending the City Revenue Code (Surcharges & Penalties)', author:'M-011', category:'Finance', committeeId:'C-001', status:'Drafting', stage:'Drafting', dateIntroduced:dateOnly(-5), summary:'Amends surcharge schedules and introduces an early-payment discount of 5% for business permits.', versions:1, aiSummary:'Revenue code amendment introducing an early-payment discount and revised surcharge tiers. Fiscal model projects a 2% increase in timely collections.' },
    { id:'ORD-2023-018', number:'Ordinance No. 2023-018', title:'An Ordinance Approving the Annual City Budget for FY 2024', author:'M-011', category:'Finance', committeeId:'C-001', status:'Enacted', stage:'Published', dateIntroduced:dateOnly(-300), dateApproved:dateOnly(-260), datePublished:dateOnly(-255), summary:'Appropriates ₱2.4 billion for general operations, infrastructure, and social services for FY 2024.', versions:4, aiSummary:'FY2024 annual budget of ₱2.4B allocated across operations (40%), infrastructure (35%), and social services (25%). Largest line item is road networks.' },
  ];

  /* ----------------------- Resolutions ----------------------- */
  const resolutions = [
    { id:'RES-2024-001', number:'Resolution No. 2024-001', title:'A Resolution Expressing Sympathy and Condolences to the Family of the Late Hon. Eduardo Perez', author:'M-002', category:'Ceremonial', status:'Adopted', stage:'Adopted', dateIntroduced:dateOnly(-80), summary:'Expresses condolences on behalf of the Sanggunian to the Perez family.', aiSummary:'Ceremonial resolution of condolence. No fiscal or policy impact; procedural adoption.' },
    { id:'RES-2024-002', number:'Resolution No. 2024-002', title:'A Resolution Endorsing the City to the National Housing Authority for a Socialized Housing Project', author:'M-003', category:'Housing', committeeId:'C-003', status:'Adopted', stage:'Adopted', dateIntroduced:dateOnly(-70), summary:'Endorses the city as a priority site for an NHA socialized housing development.', aiSummary:'Endorsement resolution enabling a national housing project. Potential benefit: 1,200 housing units for informal-settler families.' },
    { id:'RES-2024-003', number:'Resolution No. 2024-003', title:'A Resolution Authorizing the Mayor to Enter into a Memorandum of Agreement with the Department of Health', author:'M-004', category:'Health', committeeId:'C-004', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-25), summary:'Authorizes an MOA with DOH for the expanded immunization program.', aiSummary:'MOA authorization with DOH for expanded immunization. Zero local cost; program funded nationally. Expected coverage: 95% of children under 5.' },
    { id:'RES-2024-004', number:'Resolution No. 2024-004', title:'A Resolution Declaring the Last Friday of Every Month as Clean and Green Day', author:'M-006', category:'Environment', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-12), summary:'Designates a monthly citywide clean-up and tree-planting day with barangay participation.', aiSummary:'Declares a monthly clean-and-green day. Low-cost, high-participation initiative; supports waste-reduction targets.' },
    { id:'RES-2024-005', number:'Resolution No. 2024-005', title:'A Resolution Urging the National Government to Establish a Satellite Office in the City', author:'M-011', category:'Governance', status:'Drafting', stage:'Drafting', dateIntroduced:dateOnly(-3), summary:'Urges national agencies to establish a satellite office to improve citizen access to services.', aiSummary:'Advocacy resolution requesting a national government satellite office. Improves citizen access to frontline services; no local fiscal impact.' },
  ];

  /* ----------------------- Sessions ----------------------- */
  const sessions = [
    { id:'S-001', title:'Regular Session — 42nd Regular Session', type:'Regular', date:dateOnly(0), time:'09:00', venue:'Session Hall, 3rd Floor', status:'In Progress', agendaCount:5, attendance:[ {memberId:'M-001',status:'present'},{memberId:'M-002',status:'present'},{memberId:'M-003',status:'present'},{memberId:'M-004',status:'late'},{memberId:'M-005',status:'present'},{memberId:'M-006',status:'absent'},{memberId:'M-007',status:'present'},{memberId:'M-008',status:'present'},{memberId:'M-011',status:'present'},{memberId:'M-012',status:'present'} ], duration:0 },
    { id:'S-002', title:'Special Session — Budget Deliberations', type:'Special', date:dateOnly(3), time:'14:00', venue:'Session Hall, 3rd Floor', status:'Scheduled', agendaCount:3, attendance:[], duration:0 },
    { id:'S-003', title:'Joint Session — with Barangay Councils', type:'Joint', date:dateOnly(7), time:'09:00', venue:'City Gymnasium', status:'Scheduled', agendaCount:4, attendance:[], duration:0 },
    { id:'S-004', title:'Regular Session — 41st Regular Session', type:'Regular', date:dateOnly(-14), time:'09:00', venue:'Session Hall, 3rd Floor', status:'Concluded', agendaCount:6, attendance:[ {memberId:'M-001',status:'present'},{memberId:'M-002',status:'present'},{memberId:'M-003',status:'absent'},{memberId:'M-004',status:'present'},{memberId:'M-005',status:'present'},{memberId:'M-006',status:'present'},{memberId:'M-007',status:'present'},{memberId:'M-008',status:'late'},{memberId:'M-011',status:'present'},{memberId:'M-012',status:'present'} ], duration:245 },
    { id:'S-005', title:'Regular Session — 40th Regular Session', type:'Regular', date:dateOnly(-28), time:'09:00', venue:'Session Hall, 3rd Floor', status:'Concluded', agendaCount:7, attendance:[ {memberId:'M-001',status:'present'},{memberId:'M-002',status:'present'},{memberId:'M-003',status:'present'},{memberId:'M-004',status:'present'},{memberId:'M-005',status:'present'},{memberId:'M-006',status:'absent'},{memberId:'M-007',status:'present'},{memberId:'M-008',status:'present'},{memberId:'M-011',status:'present'},{memberId:'M-012',status:'present'} ], duration:210 },
  ];

  /* ----------------------- Agenda ----------------------- */
  const agenda = [
    { id:'A-001', title:'Second Reading of Ordinance No. 2024-003', priority:'High', sessionId:'S-001', deadline:dateOnly(0), status:'In Progress', category:'Legislation', responsible:'Committee on Public Works' },
    { id:'A-002', title:'Public Hearing Report — Smoke-Free Zones', priority:'High', sessionId:'S-001', deadline:dateOnly(0), status:'Pending', category:'Public Hearing', responsible:'Committee on Health' },
    { id:'A-003', title:'Approval of Minutes — 41st Regular Session', priority:'Medium', sessionId:'S-001', deadline:dateOnly(0), status:'Pending', category:'Administrative', responsible:'Office of the Secretary' },
    { id:'A-004', title:'Budget Hearing for FY 2025', priority:'Critical', sessionId:'S-002', deadline:dateOnly(3), status:'Scheduled', category:'Finance', responsible:'Committee on Finance' },
    { id:'A-005', title:'Barangay Concerns Forum', priority:'Medium', sessionId:'S-003', deadline:dateOnly(7), status:'Scheduled', category:'Community', responsible:'Office of the Vice Mayor' },
    { id:'A-006', title:'Third Reading — Scholarship Ordinance', priority:'High', sessionId:'S-004', deadline:dateOnly(-14), status:'Completed', category:'Legislation', responsible:'Committee on Education' },
  ];

  /* ----------------------- Votes ----------------------- */
  const votes = [
    { id:'V-001', subject:'Approval of Ordinance No. 2024-001 (Single-Use Plastics)', sessionId:'S-004', type:'Roll Call', date:dateOnly(-14), total:10, yes:8, no:1, abstain:1, result:'Passed', tallies:[ {memberId:'M-001',vote:'yes'},{memberId:'M-002',vote:'yes'},{memberId:'M-004',vote:'yes'},{memberId:'M-005',vote:'no'},{memberId:'M-006',vote:'yes'},{memberId:'M-007',vote:'yes'},{memberId:'M-008',vote:'abstain'},{memberId:'M-011',vote:'yes'},{memberId:'M-012',vote:'yes'},{memberId:'M-010',vote:'yes'} ] },
    { id:'V-002', subject:'Approval of Resolution No. 2024-001 (Condolences)', sessionId:'S-005', type:'Viva Voce', date:dateOnly(-28), total:9, yes:9, no:0, abstain:0, result:'Unanimous', tallies:[] },
    { id:'V-003', subject:'Approval of Annual Budget FY 2024 (Ord. 2023-018)', sessionId:'S-005', type:'Roll Call', date:dateOnly(-28), total:9, yes:7, no:2, abstain:0, result:'Passed', tallies:[ {memberId:'M-001',vote:'yes'},{memberId:'M-002',vote:'yes'},{memberId:'M-004',vote:'yes'},{memberId:'M-005',vote:'no'},{memberId:'M-007',vote:'yes'},{memberId:'M-008',vote:'yes'},{memberId:'M-011',vote:'yes'},{memberId:'M-012',vote:'no'},{memberId:'M-010',vote:'yes'} ] },
  ];

  /* ----------------------- Records ----------------------- */
  const records = [
    { id:'D-001', title:'FY 2024 Approved Budget Document', category:'Budget', type:'PDF', size:'4.2 MB', uploadedBy:'M-011', dateUploaded:dateOnly(-255), version:'v3', status:'Final', tags:['budget','finance','2024'], audit:[ {action:'uploaded',by:'M-011',time:dateOnly(-260)},{action:'versioned',by:'M-002',time:dateOnly(-258)},{action:'approved',by:'M-001',time:dateOnly(-255)} ] },
    { id:'D-002', title:'Committee Report — Public Works Q1', category:'Committee Report', type:'PDF', size:'1.8 MB', uploadedBy:'M-003', dateUploaded:dateOnly(-40), version:'v1', status:'Active', tags:['committee','infrastructure'], audit:[ {action:'uploaded',by:'M-003',time:dateOnly(-40)} ] },
    { id:'D-003', title:'Public Hearing Transcript — Smoke-Free Zones', category:'Transcript', type:'DOCX', size:'780 KB', uploadedBy:'M-004', dateUploaded:dateOnly(-18), version:'v2', status:'Active', tags:['hearing','health'], audit:[ {action:'uploaded',by:'M-004',time:dateOnly(-20)},{action:'revised',by:'M-004',time:dateOnly(-18)} ] },
    { id:'D-004', title:'City Development Plan 2024–2027', category:'Plan', type:'PDF', size:'12.5 MB', uploadedBy:'M-001', dateUploaded:dateOnly(-100), version:'v1', status:'Final', tags:['plan','development','strategy'], audit:[ {action:'uploaded',by:'M-001',time:dateOnly(-100)} ] },
    { id:'D-005', title:'Ordinance Draft — Traffic Management Code', category:'Legislation Draft', type:'DOCX', size:'1.1 MB', uploadedBy:'M-003', dateUploaded:dateOnly(-30), version:'v1', status:'Draft', tags:['draft','transportation'], audit:[ {action:'uploaded',by:'M-003',time:dateOnly(-30)} ] },
  ];

  /* ----------------------- Public Hearings ----------------------- */
  const hearings = [
    { id:'H-001', title:'Public Hearing — Smoke-Free Zones Ordinance', ordinanceRef:'ORD-2024-004', date:dateOnly(-18), time:'09:00', venue:'City Gymnasium', status:'Concluded', registered:142, attended:118, issues:6, feedbacks:34 },
    { id:'H-002', title:'Public Hearing — Traffic Management Code', ordinanceRef:'ORD-2024-003', date:dateOnly(5), time:'14:00', venue:'Session Hall', status:'Scheduled', registered:67, attended:0, issues:0, feedbacks:0 },
    { id:'H-003', title:'Public Hearing — Socialized Housing Endorsement', ordinanceRef:'RES-2024-002', date:dateOnly(-45), time:'09:00', venue:'Barangay Hall 4', status:'Concluded', registered:89, attended:76, issues:3, feedbacks:21 },
    { id:'H-004', title:'Public Hearing — FY 2025 Budget Proposal', ordinanceRef:null, date:dateOnly(10), time:'13:00', venue:'City Gymnasium', status:'Scheduled', registered:45, attended:0, issues:0, feedbacks:0 },
  ];

  /* ----------------------- Archives ----------------------- */
  const archives = [
    { id:'AR-001', title:'Ordinance No. 2023-018 — FY 2024 Annual Budget', category:'Ordinance', year:2023, dateArchived:dateOnly(-255), retention:'Permanent', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-002', title:'Session Minutes — 1st to 39th Regular Sessions (2023)', category:'Minutes', year:2023, dateArchived:dateOnly(-200), retention:'10 years', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-003', title:'Resolution No. 2023-045 — City Anniversary Proclamation', category:'Resolution', year:2023, dateArchived:dateOnly(-150), retention:'Permanent', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-004', title:'Historical Map Collection — 1985 City Survey', category:'Historical', year:1985, dateArchived:dateOnly(-1200), retention:'Permanent', format:'Digitized', status:'Restored', searchable:true },
    { id:'AR-005', title:'Ordinance No. 2022-009 — Zoning Code Amendment', category:'Ordinance', year:2022, dateArchived:dateOnly(-700), retention:'Permanent', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-006', title:'Council Proceedings 1998 — Centennial Session', category:'Minutes', year:1998, dateArchived:dateOnly(-2500), retention:'Permanent', format:'Digitized', status:'Restored', searchable:true },
  ];

  /* ----------------------- Research ----------------------- */
  const research = [
    { id:'R-001', title:'Impact Assessment: Single-Use Plastic Ban', policy:'Plastic Regulation', type:'Impact Assessment', status:'Completed', date:dateOnly(-70), impactScore:8.6, scope:'Environment', recommendation:'Adopt with phased enforcement and green-business incentives.', benchmark:'Modeled on 3 peer cities; projected 40% plastic-waste reduction in 18 months.', metrics:{ environmental:90, economic:65, social:78, implementability:72 } },
    { id:'R-002', title:'Comparative Analysis: Traffic Management Codes (5 Cities)', policy:'Traffic Management', type:'Comparative Analysis', status:'Completed', date:dateOnly(-40), impactScore:7.4, scope:'Transportation', recommendation:'Adopt demerit-point system; prioritize bike-lane rollout in business districts.', benchmark:'5 peer cities benchmarked; best performer reduced congestion 22% in 2 years.', metrics:{ environmental:55, economic:80, social:85, implementability:60 } },
    { id:'R-003', title:'Policy Research: Socialized Housing Endorsement', policy:'Housing', type:'Policy Research', status:'In Progress', date:dateOnly(-20), impactScore:8.1, scope:'Housing', recommendation:'Proceed with NHA endorsement; pre-identify 3 candidate sites.', benchmark:'National housing data; 1,200-unit potential yield for the city.', metrics:{ environmental:40, economic:70, social:92, implementability:68 } },
    { id:'R-004', title:'Benchmarking: City Scholarship Programs', policy:'Education', type:'Benchmarking', status:'Completed', date:dateOnly(-15), impactScore:7.9, scope:'Education', recommendation:'Cap scholarships at 200/year; tie retention to GPA 2.5 minimum.', benchmark:'4 peer LGU scholarship models compared.', metrics:{ environmental:20, economic:75, social:95, implementability:82 } },
  ];

  /* ----------------------- Citizen Feedback ----------------------- */
  const feedback = [
    { id:'F-001', type:'Complaint', subject:'Potholes on Rizal Street need urgent repair', category:'Infrastructure', citizen:'Anonymous', date:dateOnly(-2), status:'Pending Validation', ward:'District 2', priority:'High', response:'' },
    { id:'F-002', type:'Suggestion', subject:'Add more streetlights along the riverwalk', category:'Public Safety', citizen:'Jose Ramos', date:dateOnly(-5), status:'Validated', ward:'District 3', priority:'Medium', response:'Forwarded to the Committee on Public Works for inclusion in the next infrastructure plan.' },
    { id:'F-003', type:'Compliment', subject:'Thank you for the new scholarship ordinance', category:'Education', citizen:'Maria Cruz', date:dateOnly(-8), status:'Acknowledged', ward:'District 1', priority:'Low', response:'Thank you for your kind words. The scholarship program will begin accepting applications next quarter.' },
    { id:'F-004', type:'Complaint', subject:'Garbage collection schedule in Brgy. 5 is inconsistent', category:'Sanitation', citizen:'Anonymous', date:dateOnly(-1), status:'Pending Validation', ward:'District 5', priority:'High', response:'' },
    { id:'F-005', type:'Suggestion', subject:'Establish a weekly night market to support vendors', category:'Economy', citizen:'Pedro Santos', date:dateOnly(-12), status:'Under Review', ward:'District 4', priority:'Medium', response:'' },
    { id:'F-006', type:'Complaint', subject:'Flooding at the intersection of Mabini and Bonifacio', category:'Drainage', citizen:'Ana Reyes', date:dateOnly(-3), status:'Validated', ward:'District 6', priority:'Critical', response:'Drainage clearing scheduled; engineering assessment requested from the City Engineer\'s Office.' },
  ];

  /* ----------------------- Notifications ----------------------- */
  const notifications = [
    { id:'N-001', title:'Session starts in 30 minutes', body:'The 42nd Regular Session begins at 09:00 today.', icon:'clock', color:'blue', read:false, time:dayOffset(0) },
    { id:'N-002', title:'New citizen feedback submitted', body:'A high-priority complaint about drainage was received.', icon:'message-square', color:'amber', read:false, time:dayOffset(-1) },
    { id:'N-003', title:'Ordinance awaiting your review', body:'Ordinance No. 2024-003 (Traffic Management) is in committee review.', icon:'file-text', color:'primary', read:false, time:dayOffset(-2) },
    { id:'N-004', title:'Public hearing scheduled', body:'Public Hearing on the Traffic Management Code is set for this week.', icon:'mic', color:'emerald', read:true, time:dayOffset(-3) },
    { id:'N-005', title:'Research report completed', body:'Impact Assessment for the plastic ban is now available.', icon:'flask-conical', color:'primary', read:true, time:dayOffset(-5) },
  ];

  /* ----------------------- Activities ----------------------- */
  const activities = [
    { id:'ACT-001', action:'create', collection:'feedback', label:'Drainage complaint at Mabini-Bonifacio', time:dayOffset(-1), user:'Citizen Portal' },
    { id:'ACT-002', action:'update', collection:'ordinances', label:'Ordinance No. 2024-003 moved to Committee Review', time:dayOffset(-2), user:'Hon. R. Almazan' },
    { id:'ACT-003', action:'create', collection:'sessions', label:'42nd Regular Session scheduled for today', time:dayOffset(-3), user:'Office of the Secretary' },
    { id:'ACT-004', action:'create', collection:'research', label:'Benchmarking: City Scholarship Programs completed', time:dayOffset(-5), user:'Research Division' },
    { id:'ACT-005', action:'create', collection:'hearings', label:'Public Hearing — Traffic Management Code scheduled', time:dayOffset(-6), user:'Office of the Secretary' },
  ];

  /* ----------------------- Settings ----------------------- */
  const settings = [
    { id:'SET-001', darkMode:false, density:'comfortable', notifications:true, theme:'blue', language:'English', fiscalYear:2024, orgName:'City Legislative Council' }
  ];

  writeKey('councilMembers', councilMembers);
  writeKey('committees', committees);
  writeKey('committeeMembers', committeeMembers);
  writeKey('ordinances', ordinances);
  writeKey('resolutions', resolutions);
  writeKey('sessions', sessions);
  writeKey('agenda', agenda);
  writeKey('votes', votes);
  writeKey('records', records);
  writeKey('hearings', hearings);
  writeKey('archives', archives);
  writeKey('research', research);
  writeKey('feedback', feedback);
  writeKey('notifications', notifications);
  writeKey('activities', activities);
  writeKey('settings', settings);
}
