# Classivo ERP

Classivo ERP is a school management kit built with Laravel and Filament. It is split into small Composer packages, so you can use the whole kit or only the parts you need.

## What is included

| Package | What it does |
|---|---|
| [core](/packages/core) | School, branch, academic session, classes, sections, subjects and the tenancy contract |
| [students](/packages/students) | Students, guardians and attendance |
| [exams](/packages/exams) | Exams and marks |
| [report-cards](/packages/report-cards) | Marksheet / report card PDFs |
| [timetable](/packages/timetable) | Class timetable |
| [grading](/packages/grading) | Grading schemes (CBSE-style preset) |
| [fees](/packages/fees) | Fee structure and payments (no online gateway) |
| [hr](/packages/hr) | Staff records |

Defaults are country-neutral. An India preset (CBSE grading, Hindi and English, INR, April to March year) is on by default and can be switched off.

## Not included

The mobile apps, push notifications, the multi-school hosted layer and online payment gateways are not part of this open-source kit.

## Licence

MIT. Contributions need a DCO sign-off (`git commit -s`).

Next: [Install](/install) and [Architecture](/architecture).

## Demo, docs and services

- Live demo: https://demo.classivoerp.com
- Full documentation of all features: https://docs.classivoerp.com
- Website: https://classivoerp.com
- We offer free customisation and custom development services. Email us at info@classivoerp.com or contact us through our website: https://classivoerp.com/contact/
- Ready-made mobile apps are available as a paid add-on: Admin app, Teacher app and Student app. (They are not part of this repository.)
