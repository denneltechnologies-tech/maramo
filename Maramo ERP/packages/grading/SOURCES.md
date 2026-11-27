# Sources for board rules

Every board-specific number in `src/Presets/India/IndiaPreset.php` carries an `[S#]` tag that points here.
"Checked" says how each source was checked on 14 Nov 2026. Official CBSE PDFs on cbse.gov.in could not be
downloaded (HTTP 403), so some rows were checked only against secondary reports. Those rows are
marked, and the matching rule is configurable and listed in VERIFICATION.md.

| Tag | Rule(s) encoded | Source | Checked |
|-----|-----------------|--------|---------|
| S1 | CBSE classes VI-VIII scholastic scale A1 91-100, A2 81-90, B1 71-80, B2 61-70, C1 51-60, C2 41-50, D 33-40, E 32 & below ("Needs improvement"). Co-scholastic and discipline on a 3-point scale A Outstanding, B Very Good, C Fair (VI-VIII). Class IX co-scholastic on a 5-point A-E scale. Per-term subject split: periodic test 10, notebook 5, subject enrichment 5, exam 80. | CBSE Circular Acad-14/2017 (21 Mar 2017), "Uniform system of assessment, examination and report card for classes VI-IX": https://cbseacademic.nic.in/web_material/Circulars/2017/14_Circular_2017.pdf | Secondary summaries of the circular; PDF not opened. A press report (https://www.deccanherald.com/amp/story/archives%2Fcbse-withdraws-uniform-assessment-policy-1919199) says CBSE later withdrew the VI-VIII scheme, so the status is uncertain (VERIFICATION.md). |
| S2 | Nine-point absolute scale A1 91-100 (GP 10), A2 81-90 (9), B1 71-80 (8), B2 61-70 (7), C1 51-60 (6), C2 41-50 (5), D 33-40 (4), E1 21-32, E2 0-20 (no grade point). | CBSE CCE scheme for classes IX-X (2009-2017), CBSE circulars on CCE, e.g. https://www.cbse.gov.in/circulars/cir59-2010.pdf. CBSE no longer uses these bands for board results (from 2018 it reports marks). | Official PDF returned 403. The bands are cross-checked against widely published secondary material (e.g. https://www.aees.gov.in/htmldocs/downloads/school_exams/grading_system.pdf). |
| S3 | Pass = 33% per subject. In subjects with practical work, 33% in theory and 33% in practical separately. | CBSE Examination Bye-Laws, "Scheme of examinations and pass criteria": https://www.cbse.gov.in/cbsenew/Exambylaws_archive/SCHEME%20OF%20EXAMINATIONS%20AND%20PASS%20CRITERIA.pdf | The 33% figure appears consistently in search results. The separate theory/practical rule is checked only against secondary reports. |
| S4 | CISCE pass marks lowered in Nov 2017: ICSE (class X, and IX) 35% -> 33%; ISC (class XII, and XI) 40% -> 35%. | Reported in Business Standard, 28 Nov 2017: https://business-standard.com/amp/article/current-affairs/like-cbse-icse-isc-students-now-need-only-33-35-to-pass-board-exams-117112800474_1.html, and DNA: https://www.dnaindia.com/education/report-cisce-lowers-class-10-12-pass-marks-2574840 | Several press reports agree. Effective year is 2018 or 2019 depending on the report (VERIFICATION.md). |
| S5 | CBSE board results: positional grades. Passed candidates are ranked, A1 = top 1/8th of passed candidates, then A2, B1, B2, C1, C2, D1, D2 by eighths, and E = failed. | CBSE grading note: https://www.cbse.gov.in/cbsenew/correction/grading.pdf | Secondary summaries quote "A-1 Top 1/8th of the passed candidates". Official PDF returned 403. Tie and small-cohort handling not confirmed (VERIFICATION.md). |
| S6 | Indicative percentage = 9.5 x CGPA (overall), and 9.5 x grade point (subject-wise). CGPA = average grade point of the five main subjects, excluding the additional subject. | CBSE CCE-era result documentation, reported by Deccan Herald: https://www.deccanherald.com/content/72524/formula-devised-calculate-marks-cbsc.html | Secondary source only. CBSE describes the figure as approximate. Applies to the CCE era (VERIFICATION.md). |
| S7 | Classes IX-X: internal assessment 20 (periodic tests 10, notebook 5, subject enrichment 5) + board/annual exam 80. Periodic tests use the average of the best two. | CBSE circulars on assessment for classes IX-X from 2017-18 onward (Acad-14/2017 for IX, plus later CBSE circulars for X). | The 80/20 split is consistent across sources. The best-two reduction is NOT confirmed against an official text, so it is a parameter (VERIFICATION.md). |

## Values that are not board rules

These presets are conventions or examples. They are not cited board rules:

- `state.generic` divisions (Distinction 75, First 60, Second 45, Third 33): a common pattern in Indian state boards, given as an example only.
- `Presets::neutralScheme()` A-F letter scale: an example with no jurisdiction behind it.
- `IndiaPreset::twoTermPlan()` 50/50: a school choice.
- `IndiaPreset::iscBestOfFour()` and `icseBestOfFive()`: aggregation conventions used by schools and admissions. CISCE does not compute them.
- Grace marks: no board's moderation formula is public, so the default is none.
