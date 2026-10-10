-- ADMI machine fleet seed -- generated from ADMI_Maintenance_Plan.xlsx +
-- ADMI_Machine_Capability_Portfolio_Formatted_RevB.xlsx
-- Re-runnable: assets keyed on asset_tag, requirements keyed on (asset, template_key).
SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- Brother TC-S2A  (EQ-0001)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0001','Brother TC-S2A','TC-S2A',c.id,l.id,'Brother','TC-S2A','Drill/Tap Center','FANUC 0i-MC',2008,'active','2008 · Drill/Tap Center · Brother CNC-A00 · GREASE-lubed (no way oil, no hydraulics)'
FROM asset_categories c, locations l WHERE c.name='Small 3-Axis' AND l.name='Machine Shop'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0001');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Drill/Tap Center',10),
 (@aid,'Travels','X Travel (in)','18.9',20),
 (@aid,'Travels','Y Travel (in)','14.2',30),
 (@aid,'Travels','Z Travel (in)','10.6',40),
 (@aid,'Table','Table Size','21.7 × 16.5 in',50),
 (@aid,'Table','Table Load (lb)','441',60),
 (@aid,'Spindle','Max RPM','20000',70),
 (@aid,'Spindle','Spindle HP','7.5',80),
 (@aid,'Spindle','Taper','CAT40',90),
 (@aid,'Tooling','ATC Capacity','21',100),
 (@aid,'Machine','Control','FANUC 0i-MC',110),
 (@aid,'Machine','Best For','High-speed drilling & tapping',120),
 (@aid,'Lubrication','Way / Guide Lube','GREASE — no way oil. Auto/manual grease lubricator; LUBE LHL-X100-7 (700 cc cartridge), Brother standard',130),
 (@aid,'Lubrication','Hydraulic System','None — electric + pneumatic only (spring drawbar, air 0.4–0.6 MPa)',140),
 (@aid,'Lubrication','Lube Notes','Grease architecture confirmed; exact 2008 OEM grease callout not public — LHL-X100-7 is the Brother-standard cartridge. Never mix grease types in lines.',150);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; chip cleanup (brush/vacuum — never blow air at covers/ballscrews)','Daily Log tab','daily',1,10,0,0),
 (@aid,'Verify auto-grease pump operation & LHL-X100-7 cartridge level','Pump only alarms when nearly empty — check visually','daily',1,20,0,0),
 (@aid,'Check air pressure 0.4–0.6 MPa; drain FRL separator','Dry air critical — drawbar/ATC are air-assisted','daily',1,30,0,0),
 (@aid,'Wipe BT30 spindle taper, apply light oil film; inspect holder tapers for fretting',NULL,'daily',1,40,0,0),
 (@aid,'Check coolant level',NULL,'daily',1,50,0,0),
 (@aid,'Warm-up after idle; formal run-in if idle >720 hr',NULL,'daily',1,60,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,70,0,0),
 (@aid,'Confirm grease cartridge reserve; keep spare P/N 249137 on shelf','Long lead times','weekly',7,80,0,0),
 (@aid,'Clean chip pan & screens; skim tramp oil',NULL,'weekly',7,90,0,0),
 (@aid,'Pull way covers: confirm grease at ALL linear-guide trucks & ballscrews; inspect grease lines & metering valves','Ballscrew meter feeds 2× truck meters; a dry truck = clogged meter','monthly',30,100,0,0),
 (@aid,'Clean ATC turret & magazine pots; check pot condition',NULL,'monthly',30,110,0,0),
 (@aid,'Clean electrical cabinet filters & fans',NULL,'monthly',30,120,0,0),
 (@aid,'Replace 700 cc LHL-X100-7 grease cartridge (on alarm or empty, typ. 3–6 mo)','Change with power ON; bleed pump per LUBE manual. Never mix grease types','quarterly',91,130,0,0),
 (@aid,'Grease ATC cam followers, turret cam & magazine slides; verify ATC origin',NULL,'quarterly',91,140,0,0),
 (@aid,'Full coolant change & tank cleanout',NULL,'quarterly',91,150,0,0),
 (@aid,'End-to-end grease-line inspection & pump pressure test',NULL,'annual',365,160,0,0),
 (@aid,'Replace control memory backup battery','Power ON; back up programs/parameters first','annual',365,170,0,0),
 (@aid,'Measure drawbar clamp force; inspect pull-stud grippers','Coil-spring drawbar — slow force loss','annual',365,180,0,0),
 (@aid,'Level check, backlash check, spindle runout',NULL,'annual',365,190,0,0);

-- ---------------------------------------------------------------
-- Takumi V10  (EQ-0002)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0002','Takumi V10','V10',c.id,l.id,'Takumi','V10','Box-Way VMC','FANUC 0i-MF Plus',2024,'active','2024 · Box-Way VMC · FANUC 0i-MF Plus · Way oil VG 68 · No hydraulics'
FROM asset_categories c, locations l WHERE c.name='Medium 3-Axis' AND l.name='Machine Shop'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0002');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Box-Way VMC',10),
 (@aid,'Travels','X Travel (in)','39.5',20),
 (@aid,'Travels','Y Travel (in)','26',30),
 (@aid,'Travels','Z Travel (in)','24',40),
 (@aid,'Table','Table Size','41.73 × 25.59 in',50),
 (@aid,'Table','Table Load (lb)','2205',60),
 (@aid,'Spindle','Max RPM','12000',70),
 (@aid,'Spindle','Spindle HP','20',80),
 (@aid,'Spindle','Taper','CAT40',90),
 (@aid,'Tooling','ATC Capacity','24',100),
 (@aid,'Machine','Control','FANUC 0i-MF Plus',110),
 (@aid,'Machine','Best For','Heavy cutting in aluminum, steel & stainless',120),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil w/ rust preventative (shop std: FUCHS RENEP KN 68) — centralized autolube to box ways & ballscrews',130),
 (@aid,'Lubrication','Hydraulic System','None documented — pneumatic only; guided-counterweight Z head',140),
 (@aid,'Lubrication','Lube Notes','Grade per parent-company Hurco factory manual; verify brand on autolube reservoir plate.',150);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; chip removal (brush/vacuum)','Daily Log tab','daily',1,10,0,0),
 (@aid,'Check way-lube pump oil level','Shop std: FUCHS RENEP KN 68 (ISO VG 68)','daily',1,20,0,0),
 (@aid,'Air 85–115 psi; drain separator',NULL,'daily',1,30,0,0),
 (@aid,'Check coolant level; oil-chiller running',NULL,'daily',1,40,0,0),
 (@aid,'Clean spindle taper; apply light oil film; inspect holders',NULL,'daily',1,50,0,0),
 (@aid,'Spindle warm-up: 10 min each @ 25/50/75% speed',NULL,'daily',1,60,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,70,0,0),
 (@aid,'Clean ATC interior; check way-lube line filter; CTS filter',NULL,'weekly',7,80,0,0),
 (@aid,'Clean electrical-cabinet filters; pneumatic lubricator oil',NULL,'weekly',7,90,0,0),
 (@aid,'Pull a cover section: verify oil film distribution on box ways',NULL,'monthly',30,100,0,0),
 (@aid,'Clean chip auger/conveyor; check oil-chiller fluid level',NULL,'monthly',30,110,0,0),
 (@aid,'Machine level check (0.0005 in/12 in); axis backlash check',NULL,'quarterly',91,120,0,0),
 (@aid,'Inspect way wipers; clean waycover undersides; ballscrew endplay',NULL,'quarterly',91,130,0,0),
 (@aid,'Spindle belt inspection & tension','Belted CAT50 spindle','quarterly',91,140,0,0),
 (@aid,'Grease ATC cam pockets; coolant change',NULL,'quarterly',91,150,0,0),
 (@aid,'Replace FANUC memory battery — power ON, yearly even without BAT alarm',NULL,'annual',365,160,0,0),
 (@aid,'Replace absolute pulse-coder batteries — yearly or at APC 307/308 alarm','Within 1 week of alarm or reference lost','annual',365,170,0,0),
 (@aid,'ATC gearbox oil change','Mobilgear 600 XP 150 class','annual',365,180,0,0),
 (@aid,'Spindle chiller fluid change',NULL,'annual',365,190,0,0),
 (@aid,'Level / tram / square + ballbar or laser calibration',NULL,'annual',365,200,0,0);

-- ---------------------------------------------------------------
-- Hurco VM20i  (EQ-0003)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0003','Hurco VM20i','VM20i',c.id,l.id,'Hurco','VM20i','VMC','Hurco WinMax',2023,'active','2023 · VMC · WinMax · Way oil VG 68 · Pneumatic only'
FROM asset_categories c, locations l WHERE c.name='Medium 3-Axis' AND l.name='Machine Shop'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0003');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','VMC',10),
 (@aid,'Travels','X Travel (in)','40',20),
 (@aid,'Travels','Y Travel (in)','20',30),
 (@aid,'Travels','Z Travel (in)','25',40),
 (@aid,'Table','Table Size','46.5 × 19.7 in',50),
 (@aid,'Table','Table Load (lb)','1764',60),
 (@aid,'Spindle','Max RPM','8000',70),
 (@aid,'Spindle','Spindle HP','25',80),
 (@aid,'Spindle','Taper','CAT40',90),
 (@aid,'Tooling','ATC Capacity','24',100),
 (@aid,'Machine','Control','Hurco WinMax',110),
 (@aid,'Machine','Best For','General precision milling',120),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil w/ rust preventative (shop std: FUCHS RENEP KN 68)',130),
 (@aid,'Lubrication','Hydraulic System','None — pneumatic; air-over-oil tool-unclamp booster (cylinder oil VG 68 per VM manual; VG 32 in older docs)',140),
 (@aid,'Lubrication','Lube Notes','FRL air-line lubricator: ISO VG 32 (Mobil DTE Oil Light).',150);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; clear chips from way covers, enclosure & conveyor; clean chip screens','Also initial on Daily Log tab','daily',1,10,0,0),
 (@aid,'Daily ops check: jog each axis full travel, smooth motion, limit switches OK, inspect way covers & wipers, confirm lube film on ways','Factory daily checklist','daily',1,20,0,0),
 (@aid,'Check autolube tank level','Shop std: FUCHS RENEP KN 68 (VG 68 way oil w/ rust preventative), between H/L marks','daily',1,30,0,0),
 (@aid,'Check FRL air pressure (80–100 psi); verify auto moisture drain','Drain water','daily',1,40,0,0),
 (@aid,'Check coolant level; clean conveyor chip screens','Every 8 hr if multi-shift','daily',1,50,0,0),
 (@aid,'Clean spindle taper (lightly oiled cloth); inspect tools; check spindle for leakage',NULL,'daily',1,60,0,0),
 (@aid,'Warm-up cycle if idle ≥8 hr','Full spindle run-in if idle >30 days','daily',1,70,0,0),
 (@aid,'Spindle chiller: level, leaks, ventilation (if equipped)',NULL,'daily',1,80,0,0),
 (@aid,'Coolant concentration & pH — record on Coolant Log tab','Refractometer × Brix factor; pH floor 8.5','weekly',7,90,0,0),
 (@aid,'Clean electrical-cabinet heat-exchanger filter',NULL,'weekly',7,100,0,0),
 (@aid,'Clean & inspect each tool holder','Lightly oiled lint-free cloth','weekly',7,110,0,0),
 (@aid,'Clean coolant filters; CTS filter if equipped',NULL,'weekly',7,120,0,0),
 (@aid,'Check unclamp-cylinder oil cup level','1/3 full, max 1/2 — ISO VG 68','weekly',7,130,0,0),
 (@aid,'Chiller: solution level, tank air & water filters; bleed circulating system',NULL,'weekly',7,140,0,0),
 (@aid,'Inspect conduit, connectors, cabling for fraying/looseness; check limit-switch fasteners',NULL,'monthly',30,150,0,0),
 (@aid,'Autolube: add rust preventative, check filler screen, manually trigger a lube cycle','3.0 cc/cycle @ 42 psi, 30 s on / 15 min off','monthly',30,160,0,0),
 (@aid,'Perform spindle run-in',NULL,'monthly',30,170,0,0),
 (@aid,'Brush NLGI 1 grease onto tool holders; check tool clamp alignment & sequence',NULL,'monthly',30,180,0,0),
 (@aid,'Check ATC oil level','Sight glass mid-level — ISO VG 32','quarterly',91,190,0,0),
 (@aid,'Replace FRL air-filter element; drain lines',NULL,'quarterly',91,200,0,0),
 (@aid,'Replace coolant & filters; clean chip-conveyor tank','Full clean-dump-rinse-recharge; see Coolant Log notes','quarterly',91,210,0,0),
 (@aid,'Measure ground impedance','Licensed electrician','quarterly',91,220,0,0),
 (@aid,'Verify machine level','0.02 mm/m precision level, 9-position grid; monthly for first 6 months after install','semiannual',182,230,0,0),
 (@aid,'Grease ATC: arm zerks, magazine chain, drum','NLGI 1','semiannual',182,240,0,0),
 (@aid,'Clean autolube reservoir tank & suction filter',NULL,'annual',365,250,0,0),
 (@aid,'Replace ATC oil','ISO VG 32 — sooner if darkening','annual',365,260,0,0),
 (@aid,'Flush, clean & refill spindle chiller',NULL,'annual',365,270,0,0),
 (@aid,'Replace control CMOS/RTC battery (check annually, replace ≤3 yr)','Not in factory list — field practice; back up parameters first','annual',365,280,0,0),
 (@aid,'Inspect spindle belt tension & wear','Belt-driven spindles; distributor PM add-on','annual',365,290,0,0);

-- ---------------------------------------------------------------
-- Fadal 4020  (EQ-0004)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0004','Fadal 4020','Fadal 4020',c.id,l.id,'Fadal','4020','VMC','Fadal CNC88HS',1997,'active','1997 · VMC 4020 · CNC88HS · Way lube VG 68 (3 approved oils ONLY) · Mechanical counterweight'
FROM asset_categories c, locations l WHERE c.name='Medium 3-Axis' AND l.name='Machine Shop'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0004');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','VMC',10),
 (@aid,'Travels','X Travel (in)','40',20),
 (@aid,'Travels','Y Travel (in)','20',30),
 (@aid,'Travels','Z Travel (in)','20',40),
 (@aid,'Table','Table Size','50 × 18 in',50),
 (@aid,'Table','Table Load (lb)','1500',60),
 (@aid,'Spindle','Max RPM','7500',70),
 (@aid,'Spindle','Spindle HP','20',80),
 (@aid,'Spindle','Taper','CAT40',90),
 (@aid,'Tooling','ATC Capacity','21',100),
 (@aid,'Machine','Control','Fadal CNC88HS',110),
 (@aid,'Machine','Best For','General machining',120),
 (@aid,'Lubrication','Way / Guide Lube','FUCHS RENEP KN 68 (shop std, ISO VG 68) — Fadal factory list: Magna BD68 / Tonna 68V / Vactra 2; monitor Bijur filter (others clog Bijur metering valves)',130),
 (@aid,'Lubrication','Hydraulic System','Small Hi/Lo 2-speed belt-idler actuator reservoir only; mechanical chain counterweight (no counterbalance oil)',140),
 (@aid,'Lubrication','Hydraulic Oil','Mobil DTE Heavy Medium (VG 68); 4th-axis brake: ISO VG 32',150),
 (@aid,'Lubrication','Lube Notes','Spindle chiller: Dowfrost Clear 50/50 w/ distilled water — no substitutes. Rotary gearbox: Mobil Gear 626.',160);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Wash ATC & machine work area; remove chip buildup from guards & way covers','Daily Log tab','daily',1,10,0,0),
 (@aid,'Check air: left regulator 80–90 psi (right 120 max, Tool-Out only)',NULL,'daily',1,20,0,0),
 (@aid,'Check way-lube level & flow to all way surfaces','Shop std: FUCHS RENEP KN 68 — monitor Bijur filter; Fadal factory list: Vactra 2 / Magna BD68 / Tonna 68V','daily',1,30,0,0),
 (@aid,'With a tool in spindle, blow chips from ATC & slide','Never during tool change or with empty spindle','daily',1,40,0,0),
 (@aid,'Check coolant level; clean coolant screen',NULL,'daily',1,50,0,0),
 (@aid,'Clean spindle taper; apply light oil film; inspect holders',NULL,'daily',1,60,0,0),
 (@aid,'Spindle chiller: pump & refrigeration op; Dowfrost level 1/2–3/4','Dowfrost Clear 50/50 w/ DI water — no substitutes','weekly',7,70,0,0),
 (@aid,'Clean ATC slide; check air regulator; drain water separator; fill oiler',NULL,'weekly',7,80,0,0),
 (@aid,'Lube Y-axis telescoping ballscrew cover with way lube',NULL,'weekly',7,90,0,0),
 (@aid,'Molygraph: axis way covers & retention rings · Kopr-Kote: Geneva wheel & gear',NULL,'weekly',7,100,0,0),
 (@aid,'Check Hi/Lo hydraulic actuator reservoir','Mobil DTE Heavy Medium','weekly',7,110,0,0),
 (@aid,'Inspect all cooling fans; clean screens','DC-era amps overheat when fans die','weekly',7,120,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,130,0,0),
 (@aid,'Spindle-motor top filter; chiller pump filter (3–5 psi normal) & condenser louvers',NULL,'monthly',30,140,0,0),
 (@aid,'Cabinet fan screen; door rollers & interlock check',NULL,'monthly',30,150,0,0),
 (@aid,'Head cover off: inspect spindle & Hi/Lo belts; check CTS leaks',NULL,'monthly',30,160,0,0),
 (@aid,'Inspect cables, hoses, tubing',NULL,'monthly',30,170,0,0),
 (@aid,'Service way-lube filter — NEVER exceed 4 months','Pre-fill new external element (LUB-0049) with way lube. Clogging = wrong oil in tank','quarterly',91,180,0,0),
 (@aid,'Annual PM (dealer checklist): level, square, tram; spindle taper runout ≤ .0001 in; adjust gibs & straps','CNCPros Fadal annual PM form','annual',365,190,0,0),
 (@aid,'Tune axes: lag, balance, voltages; adjust & RECORD X/Y/Z backlash',NULL,'annual',365,200,0,0),
 (@aid,'Push/pull test X & Y — max .0003 in play; ballscrews by hand',NULL,'annual',365,210,0,0),
 (@aid,'Check way-cover wipers; ATC clips & tool-locator tabs; spindle belts/pulleys; meg motors',NULL,'annual',365,220,0,0),
 (@aid,'Inspect Z counterweight chains & sprockets',NULL,'annual',365,230,1,0),
 (@aid,'Back up parameters/programs; replace CNC88HS memory battery proactively','Record SETP/backlash values BEFORE power-down work','annual',365,240,0,0);

-- ---------------------------------------------------------------
-- Hurco VM30i  (EQ-0005)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0005','Hurco VM30i','VM30i',c.id,l.id,'Hurco','VM30i','VMC','Hurco WinMax',2017,'active','2017 · VMC · WinMax · Way oil VG 68 · Pneumatic only'
FROM asset_categories c, locations l WHERE c.name='Large 3-Axis' AND l.name='Large Machining Bay'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0005');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','VMC',10),
 (@aid,'Travels','X Travel (in)','50',20),
 (@aid,'Travels','Y Travel (in)','20',30),
 (@aid,'Travels','Z Travel (in)','20',40),
 (@aid,'Table','Table Size','52 × 20 in',50),
 (@aid,'Table','Table Load (lb)','3970',60),
 (@aid,'Spindle','Max RPM','12000',70),
 (@aid,'Spindle','Spindle HP','20',80),
 (@aid,'Spindle','Taper','CAT40 BIG-PLUS',90),
 (@aid,'Tooling','ATC Capacity','24',100),
 (@aid,'Machine','Control','Hurco WinMax',110),
 (@aid,'Machine','Best For','Medium-size aluminum, steel & stainless parts',120),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil w/ rust preventative (shop std: FUCHS RENEP KN 68) — autolube',130),
 (@aid,'Lubrication','Hydraulic System','None — pneumatic only',140),
 (@aid,'Lubrication','Lube Notes','FRL: ISO VG 32.',150);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; clear chips from way covers, enclosure & conveyor; clean chip screens','Also initial on Daily Log tab','daily',1,10,0,0),
 (@aid,'Daily ops check: jog each axis full travel, smooth motion, limit switches OK, inspect way covers & wipers, confirm lube film on ways','Factory daily checklist','daily',1,20,0,0),
 (@aid,'Check autolube tank level','Shop std: FUCHS RENEP KN 68 (VG 68 way oil w/ rust preventative), between H/L marks','daily',1,30,0,0),
 (@aid,'Check FRL air pressure (80–100 psi); verify auto moisture drain','Drain water','daily',1,40,0,0),
 (@aid,'Check coolant level; clean conveyor chip screens','Every 8 hr if multi-shift','daily',1,50,0,0),
 (@aid,'Clean spindle taper (lightly oiled cloth); inspect tools; check spindle for leakage',NULL,'daily',1,60,0,0),
 (@aid,'Warm-up cycle if idle ≥8 hr','Full spindle run-in if idle >30 days','daily',1,70,0,0),
 (@aid,'Spindle chiller: level, leaks, ventilation (if equipped)',NULL,'daily',1,80,0,0),
 (@aid,'Coolant concentration & pH — record on Coolant Log tab','Refractometer × Brix factor; pH floor 8.5','weekly',7,90,0,0),
 (@aid,'Clean electrical-cabinet heat-exchanger filter',NULL,'weekly',7,100,0,0),
 (@aid,'Clean & inspect each tool holder','Lightly oiled lint-free cloth','weekly',7,110,0,0),
 (@aid,'Clean coolant filters; CTS filter if equipped',NULL,'weekly',7,120,0,0),
 (@aid,'Check unclamp-cylinder oil cup level','1/3 full, max 1/2 — ISO VG 68','weekly',7,130,0,0),
 (@aid,'Chiller: solution level, tank air & water filters; bleed circulating system',NULL,'weekly',7,140,0,0),
 (@aid,'Inspect conduit, connectors, cabling for fraying/looseness; check limit-switch fasteners',NULL,'monthly',30,150,0,0),
 (@aid,'Autolube: add rust preventative, check filler screen, manually trigger a lube cycle','3.0 cc/cycle @ 42 psi, 30 s on / 15 min off','monthly',30,160,0,0),
 (@aid,'Perform spindle run-in',NULL,'monthly',30,170,0,0),
 (@aid,'Brush NLGI 1 grease onto tool holders; check tool clamp alignment & sequence',NULL,'monthly',30,180,0,0),
 (@aid,'Check ATC oil level','Sight glass mid-level — ISO VG 32','quarterly',91,190,0,0),
 (@aid,'Replace FRL air-filter element; drain lines',NULL,'quarterly',91,200,0,0),
 (@aid,'Replace coolant & filters; clean chip-conveyor tank','Full clean-dump-rinse-recharge; see Coolant Log notes','quarterly',91,210,0,0),
 (@aid,'Measure ground impedance','Licensed electrician','quarterly',91,220,0,0),
 (@aid,'Verify machine level','0.02 mm/m precision level, 9-position grid; monthly for first 6 months after install','semiannual',182,230,0,0),
 (@aid,'Grease ATC: arm zerks, magazine chain, drum','NLGI 1','semiannual',182,240,0,0),
 (@aid,'Clean autolube reservoir tank & suction filter',NULL,'annual',365,250,0,0),
 (@aid,'Replace ATC oil','ISO VG 32 — sooner if darkening','annual',365,260,0,0),
 (@aid,'Flush, clean & refill spindle chiller',NULL,'annual',365,270,0,0),
 (@aid,'Replace control CMOS/RTC battery (check annually, replace ≤3 yr)','Not in factory list — field practice; back up parameters first','annual',365,280,0,0),
 (@aid,'Inspect spindle belt tension & wear','Belt-driven spindles; distributor PM add-on','annual',365,290,0,0);

-- ---------------------------------------------------------------
-- Takumi H16  (EQ-0006)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0006','Takumi H16','H16',c.id,l.id,'Takumi','H16','Bridge Mill','FANUC 0i-MF Plus',2024,'active','2024 · Bridge Mill · FANUC 0i-MF Plus · Linear roller guides + scales · No hydraulics'
FROM asset_categories c, locations l WHERE c.name='Large 3-Axis' AND l.name='Large Machining Bay'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0006');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Bridge Mill',10),
 (@aid,'Travels','X Travel (in)','62.9',20),
 (@aid,'Travels','Y Travel (in)','51.1',30),
 (@aid,'Travels','Z Travel (in)','27.5',40),
 (@aid,'Table','Table Size','74.8 × 51.2 in',50),
 (@aid,'Table','Table Load (lb)','13200',60),
 (@aid,'Spindle','Max RPM','15000',70),
 (@aid,'Spindle','Spindle HP','25',80),
 (@aid,'Spindle','Taper','BIG-PLUS CAT40',90),
 (@aid,'Tooling','ATC Capacity','30',100),
 (@aid,'Machine','Control','FANUC 0i-MF Plus',110),
 (@aid,'Machine','Best For','High-speed bridge milling, dies & molds',120),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil — centralized autolube to roller linear guides & ballscrews',130),
 (@aid,'Lubrication','Hydraulic System','None documented — pneumatic only',140),
 (@aid,'Lubrication','Lube Notes','Roller linear guides (not box ways); VG 32–68 acceptable, VG 68 per Hurco parent spec.',150);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; chip removal','Daily Log tab','daily',1,10,0,0),
 (@aid,'Check auto-lube reservoir (guides & ballscrews)','ISO VG 68','daily',1,20,0,0),
 (@aid,'Both chillers (spindle oil + coolant) running & at level',NULL,'daily',1,30,0,0),
 (@aid,'Air pressure; drain separator; scale air-purge supply if fitted',NULL,'daily',1,40,0,0),
 (@aid,'Clean spindle taper; apply light oil film; inspect holders',NULL,'daily',1,50,0,0),
 (@aid,'Spindle warm-up cycle',NULL,'daily',1,60,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,70,0,0),
 (@aid,'Clean cabinet filters; clean around linear-scale covers',NULL,'weekly',7,80,0,0),
 (@aid,'Verify lube delivery at roller-guide trucks',NULL,'monthly',30,90,0,0),
 (@aid,'Clean chip conveyor',NULL,'monthly',30,100,0,0),
 (@aid,'Level check — critical on double-column bridge','Hand-scraped & leveled to exploit scales','quarterly',91,110,0,0),
 (@aid,'Backlash, ballscrew endplay, way wipers, waycover undersides',NULL,'quarterly',91,120,0,0),
 (@aid,'Grease any manual linear-rail points NLGI 2',NULL,'semiannual',182,130,0,0),
 (@aid,'Replace FANUC memory + pulse-coder batteries — power ON','APC 307/308 = replace within 1 week','annual',365,140,0,0),
 (@aid,'Both chiller fluid changes',NULL,'annual',365,150,0,0),
 (@aid,'Spindle runout & vibration check','Direct-drive — no belt','annual',365,160,0,0),
 (@aid,'Ballbar / laser calibration','Annual minimum on the accuracy machine','annual',365,170,0,0);

-- ---------------------------------------------------------------
-- Fadal 8030  (EQ-0007)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0007','Fadal 8030','Fadal 8030',c.id,l.id,'Fadal','8030','VMC','Fadal CNC88HS',2002,'active','2002 · VMC 8030 · CNC88HS · Way lube VG 68 (3 approved oils ONLY) · Mechanical counterweight'
FROM asset_categories c, locations l WHERE c.name='Large 3-Axis' AND l.name='Large Machining Bay'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0007');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','VMC',10),
 (@aid,'Travels','X Travel (in)','80',20),
 (@aid,'Travels','Y Travel (in)','30',30),
 (@aid,'Travels','Z Travel (in)','30',40),
 (@aid,'Table','Table Size','86 × 28 in',50),
 (@aid,'Table','Table Load (lb)','3500',60),
 (@aid,'Spindle','Max RPM','6000',70),
 (@aid,'Spindle','Spindle HP','30',80),
 (@aid,'Spindle','Taper','CAT40',90),
 (@aid,'Tooling','ATC Capacity','30',100),
 (@aid,'Machine','Control','Fadal CNC88HS',110),
 (@aid,'Machine','Best For','Large plates and long parts',120),
 (@aid,'Lubrication','Way / Guide Lube','Same as 4020 — ISO VG 68 (3 approved products only)',130),
 (@aid,'Lubrication','Hydraulic System','Same — Hi/Lo idler reservoir; mechanical counterweight',140),
 (@aid,'Lubrication','Hydraulic Oil','Mobil DTE Heavy Medium (VG 68); 4th-axis brake: ISO VG 32',150),
 (@aid,'Lubrication','Lube Notes','Same chiller/gearbox specs as 4020.',160);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Wash ATC & machine work area; remove chip buildup from guards & way covers','Daily Log tab','daily',1,10,0,0),
 (@aid,'Check air: left regulator 80–90 psi (right 120 max, Tool-Out only)',NULL,'daily',1,20,0,0),
 (@aid,'Check way-lube level & flow to all way surfaces','Shop std: FUCHS RENEP KN 68 — monitor Bijur filter; Fadal factory list: Vactra 2 / Magna BD68 / Tonna 68V','daily',1,30,0,0),
 (@aid,'With a tool in spindle, blow chips from ATC & slide','Never during tool change or with empty spindle','daily',1,40,0,0),
 (@aid,'Check coolant level; clean coolant screen',NULL,'daily',1,50,0,0),
 (@aid,'Clean spindle taper; apply light oil film; inspect holders',NULL,'daily',1,60,0,0),
 (@aid,'Spindle chiller: pump & refrigeration op; Dowfrost level 1/2–3/4','Dowfrost Clear 50/50 w/ DI water — no substitutes','weekly',7,70,0,0),
 (@aid,'Clean ATC slide; check air regulator; drain water separator; fill oiler',NULL,'weekly',7,80,0,0),
 (@aid,'Lube Y-axis telescoping ballscrew cover with way lube',NULL,'weekly',7,90,0,0),
 (@aid,'Molygraph: axis way covers & retention rings · Kopr-Kote: Geneva wheel & gear',NULL,'weekly',7,100,0,0),
 (@aid,'Check Hi/Lo hydraulic actuator reservoir','Mobil DTE Heavy Medium','weekly',7,110,0,0),
 (@aid,'Inspect all cooling fans; clean screens','DC-era amps overheat when fans die','weekly',7,120,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,130,0,0),
 (@aid,'Spindle-motor top filter; chiller pump filter (3–5 psi normal) & condenser louvers',NULL,'monthly',30,140,0,0),
 (@aid,'Cabinet fan screen; door rollers & interlock check',NULL,'monthly',30,150,0,0),
 (@aid,'Head cover off: inspect spindle & Hi/Lo belts; check CTS leaks',NULL,'monthly',30,160,0,0),
 (@aid,'Inspect cables, hoses, tubing',NULL,'monthly',30,170,0,0),
 (@aid,'Service way-lube filter — NEVER exceed 4 months','Pre-fill new external element (LUB-0049) with way lube. Clogging = wrong oil in tank','quarterly',91,180,0,0),
 (@aid,'Annual PM (dealer checklist): level, square, tram; spindle taper runout ≤ .0001 in; adjust gibs & straps','CNCPros Fadal annual PM form','annual',365,190,0,0),
 (@aid,'Tune axes: lag, balance, voltages; adjust & RECORD X/Y/Z backlash',NULL,'annual',365,200,0,0),
 (@aid,'Push/pull test X & Y — max .0003 in play; ballscrews by hand',NULL,'annual',365,210,0,0),
 (@aid,'Check way-cover wipers; ATC clips & tool-locator tabs; spindle belts/pulleys; meg motors',NULL,'annual',365,220,0,0),
 (@aid,'Inspect Z counterweight chains & sprockets',NULL,'annual',365,230,1,0),
 (@aid,'Back up parameters/programs; replace CNC88HS memory battery proactively','Record SETP/backlash values BEFORE power-down work','annual',365,240,0,0);

-- ---------------------------------------------------------------
-- Niigata N600  (EQ-0008)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0008','Niigata N600','N600',c.id,l.id,'Niigata','N600','Horizontal Machining Center','FANUC F31i-B Plus',2026,'active','2026 · 4-Axis HMC · FANUC F31i-B Plus · Hydraulic FUCHS RENOLIN B10 VG 32 · FUCHS RENEP KN 68 + Mobilux EP 2'
FROM asset_categories c, locations l WHERE c.name='4-Axis HMC' AND l.name='Machine Shop'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0008');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Horizontal Machining Center',10),
 (@aid,'Travels','X Travel (in)','31.5',20),
 (@aid,'Travels','Y Travel (in)','29.53',30),
 (@aid,'Travels','Z Travel (in)','33.46',40),
 (@aid,'Travels','B Axis','360° (full 4th)',50),
 (@aid,'Table','Table Size','19.7 × 19.7 in pallet',60),
 (@aid,'Table','Table Load (lb)','1764',70),
 (@aid,'Spindle','Max RPM','12000',80),
 (@aid,'Spindle','Spindle HP','60',90),
 (@aid,'Spindle','Taper','CT50 BIG PLUS',100),
 (@aid,'Tooling','ATC Capacity','60',110),
 (@aid,'Machine','Control','FANUC F31i-B Plus',120),
 (@aid,'Machine','Best For','High-production 4-axis work, unattended pallet-change machining',130),
 (@aid,'Lubrication','Way / Guide Lube','FUCHS RENEP KN 68 (VG 68; shop-std sub. for Vactra No. 2) for way-lube points; Mobilux EP 2 grease for linear roller guides',140),
 (@aid,'Lubrication','Hydraulic System','Yes — pallet clamp / APC / fixture hydraulics',150),
 (@aid,'Lubrication','Hydraulic Oil','FUCHS RENOLIN B10 VG 32 (shop-std sub. for Mobil DTE 24)',160),
 (@aid,'Lubrication','Lube Notes','Per Mobil OEM chart for Niigata HMCs (not N600-specific); confirm grease vs. auto-lube trucks at runoff.',170);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Washdown; clean chips from pallet seating surfaces, locating cones & APC transfer area','Chips on seats = clamp faults & position error. Daily Log tab','daily',1,10,0,0),
 (@aid,'Hydraulic power unit: oil level & pressure vs. nameplate','Pallet clamp / APC / tool unclamp','daily',1,20,0,0),
 (@aid,'Way/grease-lube tank level; confirm lube pump cycles without alarm',NULL,'daily',1,30,0,0),
 (@aid,'Drain FRL; verify air pressure; confirm seating-confirmation air-blast ports clear',NULL,'daily',1,40,0,0),
 (@aid,'Wipe BIG PLUS taper AND flange face, apply light oil film; inspect holders','Dual-contact needs both surfaces spotless','daily',1,50,0,0),
 (@aid,'Coolant level; chip conveyor operation',NULL,'daily',1,60,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,70,0,0),
 (@aid,'Solvent-clean pallet cones/curvic seats on BOTH pallets & receiver; inspect for fretting',NULL,'weekly',7,80,0,0),
 (@aid,'Clean APC rails & shuttle; cycle APC & listen',NULL,'weekly',7,90,0,0),
 (@aid,'Chip conveyor internals; cabinet fan & chiller filters',NULL,'weekly',7,100,0,0),
 (@aid,'Hydraulic suction strainer & line filter; check filter ΔP indicator',NULL,'monthly',30,110,0,0),
 (@aid,'ATC chain tension & lube; tool-pot grippers (60-tool magazine); tool-change alignment',NULL,'monthly',30,120,0,0),
 (@aid,'Deep-clean spindle taper; drawbar retention-force check — trend it','CT50 gripper wear shows here first','monthly',30,130,0,0),
 (@aid,'B-axis positioning & repeatability check; verify pallet clamp pressure & seat-confirmation switch',NULL,'monthly',30,140,0,0),
 (@aid,'Grease APC lift/shuttle points per lube chart',NULL,'monthly',30,150,0,0),
 (@aid,'Hydraulic oil sample analysis; replace line filter element',NULL,'quarterly',91,160,0,0),
 (@aid,'B-axis index gearbox oil level',NULL,'quarterly',91,170,0,0),
 (@aid,'Backlash / lost-motion all axes; review FANUC servo diagnostics',NULL,'quarterly',91,180,0,0),
 (@aid,'Coolant tank clean & change',NULL,'quarterly',91,190,0,0),
 (@aid,'Hydraulic oil change + tank flush + filters','2,000 hr / 1 yr norm','annual',365,200,0,0),
 (@aid,'FANUC memory + pulse-coder batteries — power ON','APC 307/308 = 1 week','annual',365,210,0,0),
 (@aid,'Spindle runout (test bar ≤ .0002 in TIR); ballbar + laser; re-comp pitch error',NULL,'annual',365,220,0,0),
 (@aid,'Verify 3-point leveling; APC full inspection (lift cylinder seals, shot pins, rail wear, cone repeatability)',NULL,'annual',365,230,0,0),
 (@aid,'Consider Niigata factory PM visit','OEM program via Niigata USA, Wood Dale IL','annual',365,240,0,0);

-- ---------------------------------------------------------------
-- Hurco VM10Ui  (EQ-0009)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0009','Hurco VM10Ui','VM10Ui',c.id,l.id,'Hurco','VM10Ui','Trunnion VMC','Hurco WinMax',2025,'active','2025 · 5-Axis Trunnion · WinMax · Hydraulic clamp VG 46 · Trunnion oil VG 150'
FROM asset_categories c, locations l WHERE c.name='Small 5-Axis' AND l.name='5-Axis Cell'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0009');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Trunnion VMC',10),
 (@aid,'Travels','X Travel (in)','24',20),
 (@aid,'Travels','Y Travel (in)','20',30),
 (@aid,'Travels','Z Travel (in)','20',40),
 (@aid,'Travels','A Axis','+30° / −110°',50),
 (@aid,'Travels','C Axis','360°',60),
 (@aid,'Table','Table Size','14 in dia',70),
 (@aid,'Table','Table Load (lb)','330',80),
 (@aid,'Spindle','Max RPM','12000',90),
 (@aid,'Spindle','Spindle HP','25',100),
 (@aid,'Spindle','Taper','CAT40',110),
 (@aid,'Tooling','ATC Capacity','24',120),
 (@aid,'Machine','Control','Hurco WinMax',130),
 (@aid,'Machine','Best For','Precision medical & aerospace 5-axis work',140),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil — autolube',150),
 (@aid,'Lubrication','Hydraulic System','Yes — trunnion clamp HPU (35 bar)',160),
 (@aid,'Lubrication','Hydraulic Oil','ISO VG 46 per Hurco VM10U manual; Mobil lists VG 32 (if placard says 32, use shop-std FUCHS RENOLIN B10) — check tank placard',170),
 (@aid,'Lubrication','Lube Notes','A/C-axis gearboxes on trunnion models: ISO VG 220.',180);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; clear chips from way covers, enclosure & conveyor; clean chip screens','Also initial on Daily Log tab','daily',1,10,0,0),
 (@aid,'Daily ops check: jog each axis full travel, smooth motion, limit switches OK, inspect way covers & wipers, confirm lube film on ways','Factory daily checklist','daily',1,20,0,0),
 (@aid,'Check autolube tank level','Shop std: FUCHS RENEP KN 68 (VG 68 way oil w/ rust preventative), between H/L marks','daily',1,30,0,0),
 (@aid,'Check FRL air pressure (80–100 psi); verify auto moisture drain','Drain water','daily',1,40,0,0),
 (@aid,'Check coolant level; clean conveyor chip screens','Every 8 hr if multi-shift','daily',1,50,0,0),
 (@aid,'Clean spindle taper (lightly oiled cloth); inspect tools; check spindle for leakage',NULL,'daily',1,60,0,0),
 (@aid,'Warm-up cycle if idle ≥8 hr','Full spindle run-in if idle >30 days','daily',1,70,0,0),
 (@aid,'Spindle chiller: level, leaks, ventilation (if equipped)',NULL,'daily',1,80,0,0),
 (@aid,'Check hydraulic clamp-system pressure','VM10Ui factory-set 35 bar — do not adjust','daily',1,90,0,0),
 (@aid,'Clean trunnion table; check A-axis (tilt) & C-axis (rotary) oil levels',NULL,'daily',1,100,0,0),
 (@aid,'Coolant concentration & pH — record on Coolant Log tab','Refractometer × Brix factor; pH floor 8.5','weekly',7,110,0,0),
 (@aid,'Clean electrical-cabinet heat-exchanger filter',NULL,'weekly',7,120,0,0),
 (@aid,'Clean & inspect each tool holder','Lightly oiled lint-free cloth','weekly',7,130,0,0),
 (@aid,'Clean coolant filters; CTS filter if equipped',NULL,'weekly',7,140,0,0),
 (@aid,'Check unclamp-cylinder oil cup level','1/3 full, max 1/2 — ISO VG 68','weekly',7,150,0,0),
 (@aid,'Chiller: solution level, tank air & water filters; bleed circulating system',NULL,'weekly',7,160,0,0),
 (@aid,'Check trunnion clamp hydraulic tank level','Sight glass half full','weekly',7,170,0,0),
 (@aid,'Inspect conduit, connectors, cabling for fraying/looseness; check limit-switch fasteners',NULL,'monthly',30,180,0,0),
 (@aid,'Autolube: add rust preventative, check filler screen, manually trigger a lube cycle','3.0 cc/cycle @ 42 psi, 30 s on / 15 min off','monthly',30,190,0,0),
 (@aid,'Perform spindle run-in',NULL,'monthly',30,200,0,0),
 (@aid,'Brush NLGI 1 grease onto tool holders; check tool clamp alignment & sequence',NULL,'monthly',30,210,0,0),
 (@aid,'Check ATC oil level','Sight glass mid-level — ISO VG 32','quarterly',91,220,0,0),
 (@aid,'Replace FRL air-filter element; drain lines',NULL,'quarterly',91,230,0,0),
 (@aid,'Replace coolant & filters; clean chip-conveyor tank','Full clean-dump-rinse-recharge; see Coolant Log notes','quarterly',91,240,0,0),
 (@aid,'Measure ground impedance','Licensed electrician','quarterly',91,250,0,0),
 (@aid,'Check hydraulic system oil level','VM10Ui: ISO VG 46 per manual (if placard says VG 32, use shop-std FUCHS RENOLIN B10)','quarterly',91,260,0,0),
 (@aid,'Verify machine level','0.02 mm/m precision level, 9-position grid; monthly for first 6 months after install','semiannual',182,270,0,0),
 (@aid,'Grease ATC: arm zerks, magazine chain, drum','NLGI 1','semiannual',182,280,0,0),
 (@aid,'Clean autolube reservoir tank & suction filter',NULL,'annual',365,290,0,0),
 (@aid,'Replace ATC oil','ISO VG 32 — sooner if darkening','annual',365,300,0,0),
 (@aid,'Flush, clean & refill spindle chiller',NULL,'annual',365,310,0,0),
 (@aid,'Replace control CMOS/RTC battery (check annually, replace ≤3 yr)','Not in factory list — field practice; back up parameters first','annual',365,320,0,0),
 (@aid,'Inspect spindle belt tension & wear','Belt-driven spindles; distributor PM add-on','annual',365,330,0,0),
 (@aid,'Replace trunnion A & C axis oil','VM10Ui: 2.0 L ISO VG 150 each (VTXU: VG 220)','annual',365,340,0,0),
 (@aid,'Replace hydraulic oil & filter (clamp system)',NULL,'annual',365,350,0,0);

-- ---------------------------------------------------------------
-- OKK VP600-5AX  (EQ-0010)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0010','OKK VP600-5AX','VP600-5AX',c.id,l.id,'OKK','VP600-5AX','Trunnion VMC','FANUC 160iS-MB',2004,'active','2004 · 5-Axis Trunnion · FANUC 160iS-MB · Hydraulic VG 32 (verify placard) · Way oil VG 68'
FROM asset_categories c, locations l WHERE c.name='Medium 5-Axis' AND l.name='5-Axis Cell'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0010');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Trunnion VMC',10),
 (@aid,'Travels','X Travel (in)','44.1',20),
 (@aid,'Travels','Y Travel (in)','24',30),
 (@aid,'Travels','Z Travel (in)','18.1',40),
 (@aid,'Travels','A Axis','+15° / −105°',50),
 (@aid,'Travels','B Axis','360°',60),
 (@aid,'Table','Table Size','20 × 20 in pallet',70),
 (@aid,'Table','Table Load (lb)','2640',80),
 (@aid,'Spindle','Max RPM','12000',90),
 (@aid,'Spindle','Spindle HP','30',100),
 (@aid,'Spindle','Taper','CAT40',110),
 (@aid,'Tooling','ATC Capacity','40',120),
 (@aid,'Machine','Control','FANUC 160iS-MB',130),
 (@aid,'Machine','Best For','Complex precision 5-axis components',140),
 (@aid,'Lubrication','Way / Guide Lube','FUCHS RENEP KN 68 (shop std, VG 68) — Mobil OKK chart lists Vactra No. 2 / Vacuoline 1409',150),
 (@aid,'Lubrication','Hydraulic System','Yes — trunnion clamp / ATC hydraulics',160),
 (@aid,'Lubrication','Hydraulic Oil','FUCHS RENOLIN B10 VG 32 (shop std) — ISO VG 32 AW per OKK-era practice; confirm on HPU placard',170),
 (@aid,'Lubrication','Lube Notes','Spindle cooler: Velocite No. 6 / DTE 21 (Mobil OKK chart).',180);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Washdown; clean trunnion table, T-slots, cradle gaps & A-axis pivot areas','Chips pack in the cradle. Daily Log tab','daily',1,10,0,0),
 (@aid,'Hydraulic unit level & pressure','Low pressure = weak trunnion clamp = chatter/drift','daily',1,20,0,0),
 (@aid,'Way-lube tank level; pump pressure cycle completes',NULL,'daily',1,30,0,0),
 (@aid,'Drain FRL; air pressure; clean spindle taper + light oil film',NULL,'daily',1,40,0,0),
 (@aid,'Coolant level; TSC filter pressure differential',NULL,'daily',1,50,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab','Way-lube carryover = heavy tramp load; skim daily','weekly',7,60,0,0),
 (@aid,'Chip conveyor & under-trunnion cleanout',NULL,'weekly',7,70,0,0),
 (@aid,'Cabinet & chiller filters',NULL,'weekly',7,80,0,0),
 (@aid,'Cycle A −105°/+15° & C 360° no-load; record servo load % baseline','Cheap early warning on 20-yr-old rotaries','weekly',7,90,0,0),
 (@aid,'Hydraulic filter/strainer; verify clamp pressure at reducing valve & confirmation switch',NULL,'monthly',30,100,0,0),
 (@aid,'ATC magazine grease, arm timing, pot grippers',NULL,'monthly',30,110,0,0),
 (@aid,'A/C repeatability spot-check (indicate reference, index pattern); clamp holding check',NULL,'monthly',30,120,0,0),
 (@aid,'Hydraulic oil sample','20+ yr old unit — seals & oil degrade faster','quarterly',91,130,0,0),
 (@aid,'Trunnion worm-gearbox oil level/change per manual','Typ. 6–12 mo','quarterly',91,140,0,0),
 (@aid,'Backlash A/C + linear axes; update FANUC comp if drifting',NULL,'quarterly',91,150,0,0),
 (@aid,'Kinematic check: master-ball A/C centerline; recompute rotary offsets if drifting',NULL,'quarterly',91,160,0,0),
 (@aid,'Coolant change + TSC element',NULL,'quarterly',91,170,0,0),
 (@aid,'Hydraulic full change + filters',NULL,'annual',365,180,0,0),
 (@aid,'FANUC 160iS batteries: memory + pulse-coders (X/Y/Z/A/C = 5) — DO NOT stretch','Losing A/C absolute reference = trunnion re-master. Power ON','annual',365,190,0,0),
 (@aid,'Spindle runout + drawbar retention force','2004 motorized spindle — known wear population','annual',365,200,0,0),
 (@aid,'Ballbar (3 planes) + rotary-axis test; level & tram',NULL,'annual',365,210,0,0);

-- ---------------------------------------------------------------
-- Hurco VTXU  (EQ-0011)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0011','Hurco VTXU','VTXU',c.id,l.id,'Hurco','VTXU','Trunnion VMC','Hurco Ultimax',2012,'active','2012 · 5-Axis Trunnion · Ultimax · Trunnion gearboxes VG 220 (2.0 L each) · No hydraulic unit'
FROM asset_categories c, locations l WHERE c.name='Medium 5-Axis' AND l.name='5-Axis Cell'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0011');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Trunnion VMC',10),
 (@aid,'Travels','X Travel (in)','30',20),
 (@aid,'Travels','Y Travel (in)','24',30),
 (@aid,'Travels','Z Travel (in)','24',40),
 (@aid,'Travels','A Axis','+110° / −30°',50),
 (@aid,'Travels','C Axis','360°',60),
 (@aid,'Table','Table Size','18 in dia',70),
 (@aid,'Table','Table Load (lb)','551',80),
 (@aid,'Spindle','Max RPM','8100',90),
 (@aid,'Spindle','Spindle HP','30',100),
 (@aid,'Spindle','Taper','CAT40',110),
 (@aid,'Tooling','ATC Capacity','40',120),
 (@aid,'Machine','Control','Hurco Ultimax',130),
 (@aid,'Machine','Best For','Complex titanium, stainless & steel parts',140),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil w/ rust preventative — autolube',150),
 (@aid,'Lubrication','Hydraulic System','None — no hydraulic section in manual; tool-release cylinder ISO VG 32',160),
 (@aid,'Lubrication','Lube Notes','Trunnion A/C gearboxes: ISO VG 220 (2.0 L each); FRL: VG 32.',170);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; clear chips from way covers, enclosure & conveyor; clean chip screens','Also initial on Daily Log tab','daily',1,10,0,0),
 (@aid,'Daily ops check: jog each axis full travel, smooth motion, limit switches OK, inspect way covers & wipers, confirm lube film on ways','Factory daily checklist','daily',1,20,0,0),
 (@aid,'Check autolube tank level','Shop std: FUCHS RENEP KN 68 (VG 68 way oil w/ rust preventative), between H/L marks','daily',1,30,0,0),
 (@aid,'Check FRL air pressure (80–100 psi); verify auto moisture drain','Drain water','daily',1,40,0,0),
 (@aid,'Check coolant level; clean conveyor chip screens','Every 8 hr if multi-shift','daily',1,50,0,0),
 (@aid,'Clean spindle taper (lightly oiled cloth); inspect tools; check spindle for leakage',NULL,'daily',1,60,0,0),
 (@aid,'Warm-up cycle if idle ≥8 hr','Full spindle run-in if idle >30 days','daily',1,70,0,0),
 (@aid,'Spindle chiller: level, leaks, ventilation (if equipped)',NULL,'daily',1,80,0,0),
 (@aid,'Check hydraulic clamp-system pressure','VM10Ui factory-set 35 bar — do not adjust','daily',1,90,0,0),
 (@aid,'Clean trunnion table; check A-axis (tilt) & C-axis (rotary) oil levels',NULL,'daily',1,100,0,0),
 (@aid,'Coolant concentration & pH — record on Coolant Log tab','Refractometer × Brix factor; pH floor 8.5','weekly',7,110,0,0),
 (@aid,'Clean electrical-cabinet heat-exchanger filter',NULL,'weekly',7,120,0,0),
 (@aid,'Clean & inspect each tool holder','Lightly oiled lint-free cloth','weekly',7,130,0,0),
 (@aid,'Clean coolant filters; CTS filter if equipped',NULL,'weekly',7,140,0,0),
 (@aid,'Check unclamp-cylinder oil cup level','1/3 full, max 1/2 — ISO VG 68','weekly',7,150,0,0),
 (@aid,'Chiller: solution level, tank air & water filters; bleed circulating system',NULL,'weekly',7,160,0,0),
 (@aid,'Check trunnion clamp hydraulic tank level','Sight glass half full','weekly',7,170,0,0),
 (@aid,'Refill autolube reservoir; clean oil-chiller filter','VTX factory weekly','weekly',7,180,0,0),
 (@aid,'Inspect conduit, connectors, cabling for fraying/looseness; check limit-switch fasteners',NULL,'monthly',30,190,0,0),
 (@aid,'Autolube: add rust preventative, check filler screen, manually trigger a lube cycle','3.0 cc/cycle @ 42 psi, 30 s on / 15 min off','monthly',30,200,0,0),
 (@aid,'Perform spindle run-in',NULL,'monthly',30,210,0,0),
 (@aid,'Brush NLGI 1 grease onto tool holders; check tool clamp alignment & sequence',NULL,'monthly',30,220,0,0),
 (@aid,'Check ATC oil level','Sight glass mid-level — ISO VG 32','quarterly',91,230,0,0),
 (@aid,'Replace FRL air-filter element; drain lines',NULL,'quarterly',91,240,0,0),
 (@aid,'Replace coolant & filters; clean chip-conveyor tank','Full clean-dump-rinse-recharge; see Coolant Log notes','quarterly',91,250,0,0),
 (@aid,'Measure ground impedance','Licensed electrician','quarterly',91,260,0,0),
 (@aid,'Check hydraulic system oil level','VM10Ui: ISO VG 46 per manual (if placard says VG 32, use shop-std FUCHS RENOLIN B10)','quarterly',91,270,0,0),
 (@aid,'Verify machine level','0.02 mm/m precision level, 9-position grid; monthly for first 6 months after install','semiannual',182,280,0,0),
 (@aid,'Grease ATC: arm zerks, magazine chain, drum','NLGI 1','semiannual',182,290,0,0),
 (@aid,'Grease tilt-axis incline shaft & incline-shaft gearcase; grease rotary-union bearing','VTX/HTX checklist — VTXU-specific','semiannual',182,300,0,0),
 (@aid,'Clean autolube reservoir tank & suction filter',NULL,'annual',365,310,0,0),
 (@aid,'Replace ATC oil','ISO VG 32 — sooner if darkening','annual',365,320,0,0),
 (@aid,'Flush, clean & refill spindle chiller',NULL,'annual',365,330,0,0),
 (@aid,'Replace control CMOS/RTC battery (check annually, replace ≤3 yr)','Not in factory list — field practice; back up parameters first','annual',365,340,0,0),
 (@aid,'Inspect spindle belt tension & wear','Belt-driven spindles; distributor PM add-on','annual',365,350,0,0),
 (@aid,'Replace trunnion A & C axis oil','VM10Ui: 2.0 L ISO VG 150 each (VTXU: VG 220)','annual',365,360,0,0),
 (@aid,'Replace hydraulic oil & filter (clamp system)',NULL,'annual',365,370,0,0),
 (@aid,'Replace ATC cam oil','VTX manual','annual',365,380,0,0);

-- ---------------------------------------------------------------
-- Hurco VMX42Ui  (EQ-0012)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0012','Hurco VMX42Ui','VMX42Ui',c.id,l.id,'Hurco','VMX42Ui','Trunnion VMC','Hurco WinMax',2024,'active','2024 · 5-Axis Trunnion · WinMax · Hydraulic clamp FUCHS RENOLIN B10 VG 32 (shop std)'
FROM asset_categories c, locations l WHERE c.name='Large 5-Axis' AND l.name='Large Machining Bay'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0012');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Trunnion VMC',10),
 (@aid,'Travels','X Travel (in)','42',20),
 (@aid,'Travels','Y Travel (in)','30',30),
 (@aid,'Travels','Z Travel (in)','25',40),
 (@aid,'Travels','A Axis','+30° / −110°',50),
 (@aid,'Travels','C Axis','360°',60),
 (@aid,'Table','Table Size','20 in dia',70),
 (@aid,'Table','Table Load (lb)','661',80),
 (@aid,'Spindle','Max RPM','12000',90),
 (@aid,'Spindle','Spindle HP','30',100),
 (@aid,'Spindle','Taper','CAT40',110),
 (@aid,'Tooling','ATC Capacity','40',120),
 (@aid,'Machine','Control','Hurco WinMax',130),
 (@aid,'Machine','Best For','Complex geometry',140),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil — autolube',150),
 (@aid,'Lubrication','Hydraulic System','Yes — rotary-axis clamping',160),
 (@aid,'Lubrication','Hydraulic Oil','FUCHS RENOLIN B10 VG 32 (shop std; Mobil OEM page lists DTE 24 Ultra)',170);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; clear chips from way covers, enclosure & conveyor; clean chip screens','Also initial on Daily Log tab','daily',1,10,0,0),
 (@aid,'Daily ops check: jog each axis full travel, smooth motion, limit switches OK, inspect way covers & wipers, confirm lube film on ways','Factory daily checklist','daily',1,20,0,0),
 (@aid,'Check autolube tank level','Shop std: FUCHS RENEP KN 68 (VG 68 way oil w/ rust preventative), between H/L marks','daily',1,30,0,0),
 (@aid,'Check FRL air pressure (80–100 psi); verify auto moisture drain','Drain water','daily',1,40,0,0),
 (@aid,'Check coolant level; clean conveyor chip screens','Every 8 hr if multi-shift','daily',1,50,0,0),
 (@aid,'Clean spindle taper (lightly oiled cloth); inspect tools; check spindle for leakage',NULL,'daily',1,60,0,0),
 (@aid,'Warm-up cycle if idle ≥8 hr','Full spindle run-in if idle >30 days','daily',1,70,0,0),
 (@aid,'Spindle chiller: level, leaks, ventilation (if equipped)',NULL,'daily',1,80,0,0),
 (@aid,'Check hydraulic clamp-system pressure','VM10Ui factory-set 35 bar — do not adjust','daily',1,90,0,0),
 (@aid,'Clean trunnion table; check A-axis (tilt) & C-axis (rotary) oil levels',NULL,'daily',1,100,0,0),
 (@aid,'Coolant concentration & pH — record on Coolant Log tab','Refractometer × Brix factor; pH floor 8.5','weekly',7,110,0,0),
 (@aid,'Clean electrical-cabinet heat-exchanger filter',NULL,'weekly',7,120,0,0),
 (@aid,'Clean & inspect each tool holder','Lightly oiled lint-free cloth','weekly',7,130,0,0),
 (@aid,'Clean coolant filters; CTS filter if equipped',NULL,'weekly',7,140,0,0),
 (@aid,'Check unclamp-cylinder oil cup level','1/3 full, max 1/2 — ISO VG 68','weekly',7,150,0,0),
 (@aid,'Chiller: solution level, tank air & water filters; bleed circulating system',NULL,'weekly',7,160,0,0),
 (@aid,'Check trunnion clamp hydraulic tank level','Sight glass half full','weekly',7,170,0,0),
 (@aid,'Inspect conduit, connectors, cabling for fraying/looseness; check limit-switch fasteners',NULL,'monthly',30,180,0,0),
 (@aid,'Autolube: add rust preventative, check filler screen, manually trigger a lube cycle','3.0 cc/cycle @ 42 psi, 30 s on / 15 min off','monthly',30,190,0,0),
 (@aid,'Perform spindle run-in',NULL,'monthly',30,200,0,0),
 (@aid,'Brush NLGI 1 grease onto tool holders; check tool clamp alignment & sequence',NULL,'monthly',30,210,0,0),
 (@aid,'Check ATC oil level','Sight glass mid-level — ISO VG 32','quarterly',91,220,0,0),
 (@aid,'Replace FRL air-filter element; drain lines',NULL,'quarterly',91,230,0,0),
 (@aid,'Replace coolant & filters; clean chip-conveyor tank','Full clean-dump-rinse-recharge; see Coolant Log notes','quarterly',91,240,0,0),
 (@aid,'Measure ground impedance','Licensed electrician','quarterly',91,250,0,0),
 (@aid,'Check hydraulic system oil level','VM10Ui: ISO VG 46 per manual (if placard says VG 32, use shop-std FUCHS RENOLIN B10)','quarterly',91,260,0,0),
 (@aid,'Verify machine level','0.02 mm/m precision level, 9-position grid; monthly for first 6 months after install','semiannual',182,270,0,0),
 (@aid,'Grease ATC: arm zerks, magazine chain, drum','NLGI 1','semiannual',182,280,0,0),
 (@aid,'Clean autolube reservoir tank & suction filter',NULL,'annual',365,290,0,0),
 (@aid,'Replace ATC oil','ISO VG 32 — sooner if darkening','annual',365,300,0,0),
 (@aid,'Flush, clean & refill spindle chiller',NULL,'annual',365,310,0,0),
 (@aid,'Replace control CMOS/RTC battery (check annually, replace ≤3 yr)','Not in factory list — field practice; back up parameters first','annual',365,320,0,0),
 (@aid,'Inspect spindle belt tension & wear','Belt-driven spindles; distributor PM add-on','annual',365,330,0,0),
 (@aid,'Replace trunnion A & C axis oil','VM10Ui: 2.0 L ISO VG 150 each (VTXU: VG 220)','annual',365,340,0,0),
 (@aid,'Replace hydraulic oil & filter (clamp system)',NULL,'annual',365,350,0,0);

-- ---------------------------------------------------------------
-- Hurco VMX42SRTi  (EQ-0013)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0013','Hurco VMX42SRTi','VMX42SRTi',c.id,l.id,'Hurco','VMX42SRTi','Swivel-Head VMC','Hurco WinMax',2025,'active','2025 · 5-Axis Swivel-Head + Rotary Table · WinMax · Hydraulic FUCHS RENOLIN B10 VG 32 (shop std)'
FROM asset_categories c, locations l WHERE c.name='Large 5-Axis' AND l.name='Large Machining Bay'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0013');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Swivel-Head VMC',10),
 (@aid,'Travels','X Travel (in)','42',20),
 (@aid,'Travels','Y Travel (in)','30',30),
 (@aid,'Travels','Z Travel (in)','25',40),
 (@aid,'Travels','B Axis','±92.5° (head)',50),
 (@aid,'Travels','C Axis','360°',60),
 (@aid,'Table','Table Size','49.2 × 29.5 in',70),
 (@aid,'Table','Table Load (lb)','1764',80),
 (@aid,'Spindle','Max RPM','12000',90),
 (@aid,'Spindle','Spindle HP','30',100),
 (@aid,'Spindle','Taper','CAT40',110),
 (@aid,'Tooling','ATC Capacity','40',120),
 (@aid,'Machine','Control','Hurco WinMax',130),
 (@aid,'Machine','Best For','Large aerospace & medical components',140),
 (@aid,'Lubrication','Way / Guide Lube','ISO VG 68 way oil — autolube',150),
 (@aid,'Lubrication','Hydraulic System','Yes — swivel-head/rotary clamping',160),
 (@aid,'Lubrication','Hydraulic Oil','FUCHS RENOLIN B10 VG 32 (shop std; Mobil OEM page lists DTE 24 Ultra)',170);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Machine washdown; clear chips from way covers, enclosure & conveyor; clean chip screens','Also initial on Daily Log tab','daily',1,10,0,0),
 (@aid,'Daily ops check: jog each axis full travel, smooth motion, limit switches OK, inspect way covers & wipers, confirm lube film on ways','Factory daily checklist','daily',1,20,0,0),
 (@aid,'Check autolube tank level','Shop std: FUCHS RENEP KN 68 (VG 68 way oil w/ rust preventative), between H/L marks','daily',1,30,0,0),
 (@aid,'Check FRL air pressure (80–100 psi); verify auto moisture drain','Drain water','daily',1,40,0,0),
 (@aid,'Check coolant level; clean conveyor chip screens','Every 8 hr if multi-shift','daily',1,50,0,0),
 (@aid,'Clean spindle taper (lightly oiled cloth); inspect tools; check spindle for leakage',NULL,'daily',1,60,0,0),
 (@aid,'Warm-up cycle if idle ≥8 hr','Full spindle run-in if idle >30 days','daily',1,70,0,0),
 (@aid,'Spindle chiller: level, leaks, ventilation (if equipped)',NULL,'daily',1,80,0,0),
 (@aid,'Clean C-axis rotary table',NULL,'daily',1,90,0,0),
 (@aid,'Check hydraulic clamp-system pressure (B head / C table)',NULL,'daily',1,100,0,0),
 (@aid,'Coolant concentration & pH — record on Coolant Log tab','Refractometer × Brix factor; pH floor 8.5','weekly',7,110,0,0),
 (@aid,'Clean electrical-cabinet heat-exchanger filter',NULL,'weekly',7,120,0,0),
 (@aid,'Clean & inspect each tool holder','Lightly oiled lint-free cloth','weekly',7,130,0,0),
 (@aid,'Clean coolant filters; CTS filter if equipped',NULL,'weekly',7,140,0,0),
 (@aid,'Check unclamp-cylinder oil cup level','1/3 full, max 1/2 — ISO VG 68','weekly',7,150,0,0),
 (@aid,'Chiller: solution level, tank air & water filters; bleed circulating system',NULL,'weekly',7,160,0,0),
 (@aid,'Check B-axis tilt-mechanism oil level; C-axis rotary table oil level',NULL,'weekly',7,170,0,0),
 (@aid,'Inspect conduit, connectors, cabling for fraying/looseness; check limit-switch fasteners',NULL,'monthly',30,180,0,0),
 (@aid,'Autolube: add rust preventative, check filler screen, manually trigger a lube cycle','3.0 cc/cycle @ 42 psi, 30 s on / 15 min off','monthly',30,190,0,0),
 (@aid,'Perform spindle run-in',NULL,'monthly',30,200,0,0),
 (@aid,'Brush NLGI 1 grease onto tool holders; check tool clamp alignment & sequence',NULL,'monthly',30,210,0,0),
 (@aid,'Check ATC oil level','Sight glass mid-level — ISO VG 32','quarterly',91,220,0,0),
 (@aid,'Replace FRL air-filter element; drain lines',NULL,'quarterly',91,230,0,0),
 (@aid,'Replace coolant & filters; clean chip-conveyor tank','Full clean-dump-rinse-recharge; see Coolant Log notes','quarterly',91,240,0,0),
 (@aid,'Measure ground impedance','Licensed electrician','quarterly',91,250,0,0),
 (@aid,'Check hydraulic system oil level',NULL,'quarterly',91,260,0,0),
 (@aid,'Verify machine level','0.02 mm/m precision level, 9-position grid; monthly for first 6 months after install','semiannual',182,270,0,0),
 (@aid,'Grease ATC: arm zerks, magazine chain, drum','NLGI 1','semiannual',182,280,0,0),
 (@aid,'Clean autolube reservoir tank & suction filter',NULL,'annual',365,290,0,0),
 (@aid,'Replace ATC oil','ISO VG 32 — sooner if darkening','annual',365,300,0,0),
 (@aid,'Flush, clean & refill spindle chiller',NULL,'annual',365,310,0,0),
 (@aid,'Replace control CMOS/RTC battery (check annually, replace ≤3 yr)','Not in factory list — field practice; back up parameters first','annual',365,320,0,0),
 (@aid,'Inspect spindle belt tension & wear','Belt-driven spindles; distributor PM add-on','annual',365,330,0,0),
 (@aid,'Replace B-axis tilt-mechanism oil',NULL,'annual',365,340,0,0),
 (@aid,'Replace hydraulic oil & filter','FUCHS RENOLIN B10 VG 32 (shop std; Mobil OEM page lists DTE 24 Ultra)','annual',365,350,0,0);

-- ---------------------------------------------------------------
-- Nicolas Correa FP4040  (EQ-0014)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0014','Nicolas Correa FP4040','FP4040',c.id,l.id,'Nicolas Correa','FP4040','Bridge/Gantry','Heidenhain TNC-426',1998,'active','1998 · Bridge Mill · Heidenhain TNC-426 · Box ways · Hydraulic head/tool clamp · HLP 46'
FROM asset_categories c, locations l WHERE c.name='Large 5-Axis' AND l.name='Large Machining Bay'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0014');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Bridge/Gantry',10),
 (@aid,'Travels','X Travel (in)','137.8',20),
 (@aid,'Travels','Y Travel (in)','118.1',30),
 (@aid,'Travels','Z Travel (in)','39.4',40),
 (@aid,'Travels','A Axis','2.5° index (univ. head)',50),
 (@aid,'Travels','B Axis','2.5° index (univ. head)',60),
 (@aid,'Table','Table Size','157.5 × 49.2 in',70),
 (@aid,'Table','Table Load (lb)','22046',80),
 (@aid,'Spindle','Max RPM','4000',90),
 (@aid,'Spindle','Spindle HP','50',100),
 (@aid,'Spindle','Taper','ISO50',110),
 (@aid,'Machine','Control','Heidenhain TNC-426',120),
 (@aid,'Machine','Best For','Massive dies, molds & aerospace',130),
 (@aid,'Lubrication','Way / Guide Lube','FUCHS RENEP KN 68 (shop std, VG 68) — class standard, NOT machine-documented',140),
 (@aid,'Lubrication','Hydraulic System','Yes — head clamp/index, hydraulic tool clamp (confirmed), counterbalance',150),
 (@aid,'Lubrication','Hydraulic Oil','HLP 46 (Mobil DTE 25) European practice; some units filled VG 32 — check tank plate',160),
 (@aid,'Lubrication','Lube Notes','No factory lube chart online; NC Service (Burgos, +34 947 47 36 19) holds factory manuals.',170);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Washdown; clear chips from ways, covers & telescopic joints','Daily Log tab','daily',1,10,0,0),
 (@aid,'Hydraulic unit: level & pressure — LOG both','Aged clamp circuits leak & drift','daily',1,20,0,0),
 (@aid,'Way-lube reservoir & pump prime/cycle check','Starved box way = regrind','daily',1,30,0,0),
 (@aid,'Air: drain separator, line pressure, FRL auto-drain',NULL,'daily',1,40,0,0),
 (@aid,'Listen/feel: spindle gearbox, hydraulic pump whine, servo hum',NULL,'daily',1,50,0,0),
 (@aid,'Head-clamp & tool-clamp engage/release cleanly on first index',NULL,'daily',1,60,0,0),
 (@aid,'Clean spindle taper; apply light oil film',NULL,'daily',1,70,0,0),
 (@aid,'GREASE UNIVERSAL HEAD — 2 grease points on the head','Every week, both fittings','weekly',7,80,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,90,0,0),
 (@aid,'Way wipers all axes — replace torn/hardened immediately','$40 wipers vs $6,000 ballscrew','weekly',7,100,0,0),
 (@aid,'Hydraulic oil temperature at operating state','Heat = oxidation/varnish accelerant','weekly',7,110,0,0),
 (@aid,'Cabinet filters & fans; wipe Heidenhain scale housings','1998 electronics die from heat','weekly',7,120,0,0),
 (@aid,'Hydraulic filter bypass indicator; replace on indication',NULL,'monthly',30,130,0,0),
 (@aid,'Head-clamp full-pressure test: index pattern, verify pressure & zero drift under load','Clamp seals = known weak point','monthly',30,140,0,0),
 (@aid,'Backlash spot-check X/Y/Z (indicator reversal); TREND in log',NULL,'monthly',30,150,0,0),
 (@aid,'Walk lube lines: crushed/chafed/weeping; verify wet film at each way; consumption vs. baseline',NULL,'monthly',30,160,0,0),
 (@aid,'Hydraulic oil lab analysis: viscosity, water, ISO 4406 particles, wear metals, TAN','Catches pump wear & varnish before valve stiction','quarterly',91,170,0,0),
 (@aid,'Gib adjustment check all axes',NULL,'quarterly',91,180,0,0),
 (@aid,'Universal-head 2.5° index repeatability — both directions on master bar; TREND','Degradation = coupling wear or low clamp pressure','quarterly',91,190,0,0),
 (@aid,'Drawbar force test','1998 spring packs are fatigued','quarterly',91,200,0,0),
 (@aid,'Thermal scan of drives/transformers; DC-bus connection torque',NULL,'quarterly',91,210,0,0),
 (@aid,'Hydraulic change + reservoir cleanout + suction strainer','Or condition-based per analysis trend','annual',365,220,0,0),
 (@aid,'Re-level per factory points; bridge squareness & perpendicularity','Investigate > .0003 in/12 in. Foundations settle','annual',365,230,0,0),
 (@aid,'Ballbar X-Y / X-Z / Y-Z vs. baseline',NULL,'annual',365,240,0,0),
 (@aid,'TNC 426 buffer battery — replace BEFORE ''CHANGE BUFFER BATTERY'' message','~1 week to RAM loss after message. 3× AA or CR 2450N','annual',365,250,0,0),
 (@aid,'Full parameter/PLC backup ×2 offline copies (TNCBACK.EXE) before & after battery work','1998 OEM parameter set may be irreplaceable','annual',365,260,0,0),
 (@aid,'Heidenhain scale service: sealing lips, reader head',NULL,'annual',365,270,0,0),
 (@aid,'Spindle gearbox oil change; servo fans/blowers; hose age survey; way surface inspection with covers pulled','28-yr-old clamp-circuit hose is when-not-if','annual',365,280,0,0);

-- ---------------------------------------------------------------
-- Cincinnati Milacron 30V  (EQ-0015)
-- ---------------------------------------------------------------
INSERT INTO assets (asset_tag,name,short_name,category_id,location_id,manufacturer,model,machine_type,control_system,year_built,status,notes)
SELECT 'EQ-0015','Cincinnati Milacron 30V','Cinci 30V',c.id,l.id,'Cincinnati Milacron','30V','Bridge/Profiler','Acramatic 950',1989,'active','1989 · Bridge Profiler · Acramatic 950 · DC drives · Hydraulic A/B clamps + counterweight'
FROM asset_categories c, locations l WHERE c.name='Large 5-Axis' AND l.name='Large Machining Bay'
ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), location_id=VALUES(location_id),
  manufacturer=VALUES(manufacturer), model=VALUES(model), machine_type=VALUES(machine_type),
  control_system=VALUES(control_system), year_built=VALUES(year_built);
SET @aid := (SELECT id FROM assets WHERE asset_tag='EQ-0015');
DELETE FROM asset_specs WHERE asset_id=@aid;
INSERT INTO asset_specs (asset_id,spec_group,spec_key,spec_value,sort_order) VALUES
 (@aid,'Machine','Type','Bridge/Profiler',10),
 (@aid,'Travels','X Travel (in)','130',20),
 (@aid,'Travels','Y Travel (in)','60',30),
 (@aid,'Travels','Z Travel (in)','24',40),
 (@aid,'Travels','A Axis','±25° (head)',50),
 (@aid,'Travels','B Axis','±25° (head)',60),
 (@aid,'Table','Table Size','130 × 60 in',70),
 (@aid,'Spindle','Max RPM','5000',80),
 (@aid,'Spindle','Spindle HP','100',90),
 (@aid,'Spindle','Taper','#50 Taper',100),
 (@aid,'Machine','Control','Acramatic 950',110),
 (@aid,'Machine','Best For','Large aerospace components',120),
 (@aid,'Lubrication','Way / Guide Lube','Cincinnati P-47 (VG 68) = shop std FUCHS RENEP KN 68; P-50 = Vactra No. 4 (VG 220) if called out for vertical ways',130),
 (@aid,'Lubrication','Hydraulic System','Yes — A/B tilt clamps, counterweight, tool clamp',140),
 (@aid,'Lubrication','Hydraulic Oil','Cincinnati P-38 = VG 32 (shop std: FUCHS RENOLIN B10) or P-55 = VG 46 (DTE 25) — which one requires the 30V manual / tank plate',150),
 (@aid,'Lubrication','Lube Notes','Fives Group (successor) supports these; verify P-number off machine placard. Never put tackified way oil in hydraulic tank.',160);
INSERT IGNORE INTO maintenance_requirements (asset_id,task_name,spec_note,frequency,interval_days,sort_order,is_safety_critical,grace_days) VALUES
 (@aid,'Washdown; clear chips from ways & covers','Daily Log tab','daily',1,10,0,0),
 (@aid,'Hydraulic level & pressure + COUNTERWEIGHT check: no Z/head drift power-off, no noise on reversal','SAFETY ITEM — dropped head is catastrophic','daily',1,20,1,0),
 (@aid,'Way-lube pump operation & reservoir',NULL,'daily',1,30,0,0),
 (@aid,'A/B tilt-clamp function on first contour move — no chatter marks',NULL,'daily',1,40,0,0),
 (@aid,'100 HP spindle: monitor start-up current & sound','DC spindles telegraph brush/bearing problems audibly','daily',1,50,0,0),
 (@aid,'Air: drain separator, pressure',NULL,'daily',1,60,0,0),
 (@aid,'Clean spindle taper; apply light oil film',NULL,'daily',1,70,0,0),
 (@aid,'Coolant concentration & pH — Coolant Log tab',NULL,'weekly',7,80,0,0),
 (@aid,'Acramatic 950 cabinet cooling: filters & fans','1989 boards are heat-fragile & repair-only','weekly',7,90,0,0),
 (@aid,'Way wipers; hydraulic temp check',NULL,'weekly',7,100,0,0),
 (@aid,'DC brush inspection — spindle motor: satin-gray face OK; replace at 1/4 length; blow out dust; clean commutator','THE signature PM item for 1989 DC drives','monthly',30,110,0,0),
 (@aid,'Spindle-motor blower & filter service','Brush dust + blocked blower = flashover','monthly',30,120,0,0),
 (@aid,'Counterweight mechanical: cable/chain wear, sheaves, anchors','SAFETY','monthly',30,130,1,0),
 (@aid,'Hydraulic filter indicator; lube-line walk',NULL,'monthly',30,140,0,0),
 (@aid,'Hydraulic oil lab analysis','Clamps AND counterweight ride on this circuit','quarterly',91,150,1,0),
 (@aid,'A/B clamp holding-torque verify (known moment, indicator)',NULL,'quarterly',91,160,0,0),
 (@aid,'Axis-servo brush inspection; commutator survey (grooving, threading, out-of-round)',NULL,'quarterly',91,170,0,0),
 (@aid,'Gibs, backlash trend, drawbar force',NULL,'quarterly',91,180,0,0),
 (@aid,'Acramatic 950 memory batteries + FULL control backup BEFORE any power-down','Never hard-reset casually — can drop files. Manual: mcspt.com','annual',365,190,0,0),
 (@aid,'DC drive service: bus capacitors, connection torque, thermal scan under load, tach couplings','1989 electrolytics far past design life','annual',365,200,0,0),
 (@aid,'Commutator resurfacing as indicated; motor-shop rotation for 100 HP spindle motor',NULL,'annual',365,210,0,0),
 (@aid,'Hydraulic change + filters; hose age survey (clamp & counterweight circuits first)',NULL,'annual',365,220,1,0),
 (@aid,'Geometry & level re-check; ballbar','37 years of foundation settling','annual',365,230,0,0),
 (@aid,'Counterweight teardown-level inspection','Certified rigger/millwright. SAFETY','annual',365,240,1,0),
 (@aid,'Spares posture review: 950 boards, DC modules, brush P/Ns; repair-vendor list current','Orphaned control — weeks of downtime if unprepared','annual',365,250,0,0);

-- Measured steps for the weekly coolant check (Brix concentration + pH targets
-- carried over from the Coolant Log tab: 8-12% concentration, pH floor 8.7).
INSERT INTO requirement_steps (requirement_id, step_no, step_text, response_type, value_unit, value_min, value_max)
SELECT r.id, 1, 'Coolant concentration (refractometer Brix x factor)', 'value', '%', 8.000, 12.000
FROM maintenance_requirements r
WHERE r.frequency='weekly' AND r.task_name LIKE 'Coolant concentration%'
  AND NOT EXISTS (SELECT 1 FROM requirement_steps s WHERE s.requirement_id=r.id AND s.step_no=1);
INSERT INTO requirement_steps (requirement_id, step_no, step_text, response_type, value_unit, value_min, value_max)
SELECT r.id, 2, 'Coolant pH', 'value', 'pH', 8.700, 9.500
FROM maintenance_requirements r
WHERE r.frequency='weekly' AND r.task_name LIKE 'Coolant concentration%'
  AND NOT EXISTS (SELECT 1 FROM requirement_steps s WHERE s.requirement_id=r.id AND s.step_no=2);
INSERT INTO requirement_steps (requirement_id, step_no, step_text, response_type)
SELECT r.id, 3, 'Tramp oil skimmed', 'passfail'
FROM maintenance_requirements r
WHERE r.frequency='weekly' AND r.task_name LIKE 'Coolant concentration%'
  AND NOT EXISTS (SELECT 1 FROM requirement_steps s WHERE s.requirement_id=r.id AND s.step_no=3);